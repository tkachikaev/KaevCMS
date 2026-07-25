<?php

namespace KaevCMS\Modules\PromoCodes\Http\Controllers;

use App\Auth\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Services\GameAssets\GameAssetUrlResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\PromoCodes\Http\Requests\SavePromoCodeRequest;
use KaevCMS\Modules\PromoCodes\Models\PromoCode;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeReward;

final class AdminPromoCodeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GameAssetUrlResolver $assets,
    ) {}

    public function index(): View
    {
        $promoCodes = PromoCode::query()
            ->with(['gameServer.translations', 'rewards'])
            ->latest('id')
            ->paginate(20);

        $iconUrls = [];
        foreach ($promoCodes as $promoCode) {
            foreach ($promoCode->rewards as $reward) {
                $iconUrls[$promoCode->id][$reward->item_id] = $this->assets->itemIcon(
                    $promoCode->gameServer,
                    $reward->item_id,
                );
            }
        }

        return view('module-promo-codes::admin.index', [
            'promoCodes' => $promoCodes,
            'iconUrls' => $iconUrls,
            'totalCount' => PromoCode::query()->count(),
            'enabledCount' => PromoCode::query()->where('enabled', true)->count(),
            'disabledCount' => PromoCode::query()->where('enabled', false)->count(),
            'activationsCount' => PromoCodeActivation::query()->count(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function create(): View
    {
        abort_unless($this->canManage(), 403);

        return view('module-promo-codes::admin.create', [
            'promoCode' => new PromoCode([
                'enabled' => true,
                'total_limit' => 0,
                'per_user_limit' => 1,
            ]),
            'gameServers' => $this->gameServers(),
            'rewardRows' => $this->emptyRewardRows(),
            'canManage' => true,
        ]);
    }

    public function store(SavePromoCodeRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $admin = $request->user('admin');

        $promoCode = DB::transaction(function () use ($payload, $admin): PromoCode {
            $promoCode = PromoCode::query()->create([
                'game_server_id' => (int) $payload['game_server_id'],
                'code' => (string) $payload['code'],
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'total_limit' => (int) $payload['total_limit'],
                'per_user_limit' => (int) $payload['per_user_limit'],
                'enabled' => (bool) $payload['enabled'],
                'created_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);

            $this->syncRewards($promoCode, (array) $payload['rewards']);

            return $promoCode->load(['gameServer.translations', 'rewards']);
        }, 3);

        $this->auditLogger->success(
            category: 'module',
            action: 'promo_code.created',
            target: $promoCode,
            details: $this->auditDetails($promoCode),
        );

        return $this->redirectTo('index')
            ->with('status', __('module-promo-codes::messages.created', ['code' => $promoCode->code]));
    }

    public function edit(PromoCode $promoCode): View
    {
        $promoCode->load(['gameServer.translations', 'rewards']);

        $rows = $promoCode->rewards
            ->map(static fn (PromoCodeReward $reward): array => [
                'item_id' => (string) $reward->item_id,
                'amount' => (string) $reward->amount,
            ])
            ->all();

        return view('module-promo-codes::admin.edit', [
            'promoCode' => $promoCode,
            'gameServers' => $this->gameServers(),
            'rewardRows' => $rows !== [] ? $rows : $this->emptyRewardRows(),
            'canManage' => $this->canManage(),
        ]);
    }

    public function update(SavePromoCodeRequest $request, PromoCode $promoCode): RedirectResponse
    {
        $payload = $request->validated();
        $admin = $request->user('admin');
        $before = $this->auditDetails($promoCode->load('rewards'));

        DB::transaction(function () use ($promoCode, $payload, $admin): void {
            $locked = PromoCode::query()->lockForUpdate()->findOrFail($promoCode->id);
            $totalLimit = (int) $payload['total_limit'];
            if ($totalLimit > 0 && $totalLimit < $locked->activations_count) {
                throw ValidationException::withMessages([
                    'total_limit' => __('module-promo-codes::messages.total_limit_below_activations', [
                        'count' => $locked->activations_count,
                    ]),
                ]);
            }

            $gameServerId = (int) $payload['game_server_id'];
            if ($locked->activations_count > 0 && $gameServerId !== $locked->game_server_id) {
                throw ValidationException::withMessages([
                    'game_server_id' => __('module-promo-codes::messages.server_locked_after_activation'),
                ]);
            }

            $locked->update([
                'game_server_id' => (int) $payload['game_server_id'],
                'code' => (string) $payload['code'],
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'total_limit' => (int) $payload['total_limit'],
                'per_user_limit' => (int) $payload['per_user_limit'],
                'enabled' => (bool) $payload['enabled'],
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);

            $this->syncRewards($locked, (array) $payload['rewards']);
        }, 3);

        $promoCode->refresh()->load(['gameServer.translations', 'rewards']);
        $this->auditLogger->success(
            category: 'module',
            action: 'promo_code.updated',
            target: $promoCode,
            details: [
                'before' => $before,
                'after' => $this->auditDetails($promoCode),
            ],
        );

        return $this->redirectTo('index')
            ->with('status', __('module-promo-codes::messages.updated', ['code' => $promoCode->code]));
    }

    public function toggle(Request $request, PromoCode $promoCode): RedirectResponse
    {
        $admin = $request->user('admin');

        $promoCode->update([
            'enabled' => ! $promoCode->enabled,
            'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
        ]);

        $this->auditLogger->success(
            category: 'module',
            action: $promoCode->enabled ? 'promo_code.enabled' : 'promo_code.disabled',
            target: $promoCode,
            details: [
                'game_server_id' => $promoCode->game_server_id,
                'enabled' => $promoCode->enabled,
            ],
        );

        return $this->redirectTo('index')->with(
            'status',
            $promoCode->enabled
                ? __('module-promo-codes::messages.enabled', ['code' => $promoCode->code])
                : __('module-promo-codes::messages.disabled', ['code' => $promoCode->code]),
        );
    }

    public function destroy(PromoCode $promoCode): RedirectResponse
    {
        $details = $this->auditDetails($promoCode->load(['gameServer.translations', 'rewards']));

        $hasActivations = $promoCode->activations()->exists();
        if ($hasActivations) {
            $promoCode->delete();
        } else {
            $promoCode->forceDelete();
        }

        $this->auditLogger->success(
            category: 'module',
            action: 'promo_code.deleted',
            target: $promoCode,
            details: array_merge($details, [
                'history_preserved' => $hasActivations,
            ]),
        );

        return $this->redirectTo('index')
            ->with('status', __('module-promo-codes::messages.deleted', ['code' => $promoCode->code]));
    }

    /** @return Collection<int, GameServer> */
    private function gameServers(): Collection
    {
        return GameServer::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return list<array{item_id:string,amount:string}> */
    private function emptyRewardRows(): array
    {
        return [['item_id' => '', 'amount' => '']];
    }

    /** @param array<int, mixed> $rewards */
    private function syncRewards(PromoCode $promoCode, array $rewards): void
    {
        $promoCode->rewards()->delete();

        foreach (array_values($rewards) as $index => $reward) {
            if (! is_array($reward)) {
                continue;
            }

            $promoCode->rewards()->create([
                'item_id' => (int) ($reward['item_id'] ?? 0),
                'amount' => (int) ($reward['amount'] ?? 0),
                'sort_order' => $index,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function auditDetails(PromoCode $promoCode): array
    {
        return [
            'game_server_id' => $promoCode->game_server_id,
            'starts_at' => $promoCode->starts_at?->toIso8601String(),
            'ends_at' => $promoCode->ends_at?->toIso8601String(),
            'total_limit' => $promoCode->total_limit,
            'per_user_limit' => $promoCode->per_user_limit,
            'enabled' => $promoCode->enabled,
            'rewards' => $promoCode->rewards
                ->map(static fn (PromoCodeReward $reward): array => [
                    'item_id' => $reward->item_id,
                    'amount' => $reward->amount,
                ])
                ->all(),
        ];
    }

    private function canManage(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->hasPermission(AdminPermission::ModulesManage);
    }

    /** @param array<string, mixed> $parameters */
    private function redirectTo(string $route, array $parameters = []): RedirectResponse
    {
        return redirect()->route(
            'admin.module-pages.promo-codes.'.$route,
            array_merge(['adminPath' => request()->route('adminPath')], $parameters),
        );
    }
}
