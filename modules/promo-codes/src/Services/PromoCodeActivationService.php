<?php

namespace KaevCMS\Modules\PromoCodes\Services;

use App\Models\User;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\PromoCodes\Exceptions\PromoCodeActivationException;
use KaevCMS\Modules\PromoCodes\Models\PromoCode;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeReward;

final class PromoCodeActivationService
{
    public function __construct(private readonly RewardInventoryService $inventory) {}

    public function activate(User $user, string $code, string $requestToken): PromoCodeActivation
    {
        $code = PromoCode::normalizeCode($code);

        $existing = $this->existingActivation($requestToken, $user);
        if ($existing instanceof PromoCodeActivation) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $code, $requestToken): PromoCodeActivation {
                $existing = PromoCodeActivation::query()
                    ->where('request_token', $requestToken)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof PromoCodeActivation) {
                    return $this->assertActivationOwner($existing, $user);
                }

                $promoCode = PromoCode::query()
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->first();

                if (! $promoCode instanceof PromoCode) {
                    throw new PromoCodeActivationException('invalid');
                }

                $promoCode->load(['rewards', 'gameServer.translations']);
                $this->assertAvailable($promoCode, $user);

                if ($promoCode->rewards->isEmpty()) {
                    throw new PromoCodeActivationException('no_rewards');
                }

                $activation = PromoCodeActivation::query()->create([
                    'request_token' => $requestToken,
                    'promo_code_id' => $promoCode->id,
                    'user_id' => $user->id,
                    'game_server_id' => $promoCode->game_server_id,
                    'reward_inventory_grant_id' => null,
                    'code_snapshot' => $promoCode->code,
                    'user_email' => $user->email,
                    'activated_at' => now(),
                ]);

                $items = $promoCode->rewards
                    ->map(static fn (PromoCodeReward $reward): RewardGrantItem => new RewardGrantItem(
                        itemId: (int) $reward->item_id,
                        amount: (int) $reward->amount,
                    ))
                    ->all();

                $grant = $this->inventory->grant(
                    user: $user,
                    server: $promoCode->gameServer,
                    grantKey: 'promo-code.activation.'.$activation->id,
                    sourceType: 'promo-code',
                    items: $items,
                    sourceReference: (string) $promoCode->id,
                    sourceLabel: $promoCode->code,
                    metadata: [
                        'module' => 'promo-codes',
                        'activation_id' => $activation->id,
                    ],
                    actor: $user,
                );

                $activation->update(['reward_inventory_grant_id' => $grant->id]);
                $promoCode->increment('activations_count');

                return $activation->fresh(['promoCode.rewards', 'gameServer.translations', 'rewardGrant.items'])
                    ?? $activation;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingActivation($requestToken, $user);
            if ($existing instanceof PromoCodeActivation) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assertAvailable(PromoCode $promoCode, User $user): void
    {
        $now = now();

        if (! $promoCode->enabled) {
            throw new PromoCodeActivationException('disabled');
        }

        if ($promoCode->starts_at !== null && $promoCode->starts_at->isAfter($now)) {
            throw new PromoCodeActivationException('not_started');
        }

        if ($promoCode->ends_at !== null && $promoCode->ends_at->isBefore($now)) {
            throw new PromoCodeActivationException('expired');
        }

        if ($promoCode->total_limit > 0 && $promoCode->activations_count >= $promoCode->total_limit) {
            throw new PromoCodeActivationException('total_limit');
        }

        $userActivations = PromoCodeActivation::query()
            ->where('promo_code_id', $promoCode->id)
            ->where('user_id', $user->id)
            ->count();

        if ($userActivations >= $promoCode->per_user_limit) {
            throw new PromoCodeActivationException('user_limit');
        }
    }

    private function existingActivation(string $requestToken, User $user): ?PromoCodeActivation
    {
        $activation = PromoCodeActivation::query()
            ->where('request_token', $requestToken)
            ->first();

        return $activation instanceof PromoCodeActivation
            ? $this->assertActivationOwner($activation, $user)
            : null;
    }

    private function assertActivationOwner(PromoCodeActivation $activation, User $user): PromoCodeActivation
    {
        if ($activation->user_id !== $user->id) {
            throw new PromoCodeActivationException('invalid');
        }

        return $activation->loadMissing(['promoCode.rewards', 'gameServer.translations', 'rewardGrant.items']);
    }
}
