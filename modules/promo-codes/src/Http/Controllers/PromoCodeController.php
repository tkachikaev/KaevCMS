<?php

namespace KaevCMS\Modules\PromoCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GameAssets\GameAssetUrlResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use KaevCMS\Modules\PromoCodes\Exceptions\PromoCodeActivationException;
use KaevCMS\Modules\PromoCodes\Http\Requests\ActivatePromoCodeRequest;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;
use KaevCMS\Modules\PromoCodes\Services\PromoCodeActivationService;

final class PromoCodeController extends Controller
{
    public function __construct(private readonly GameAssetUrlResolver $assets) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $activations = PromoCodeActivation::query()
            ->with(['promoCode.rewards', 'gameServer.translations', 'rewardGrant.items'])
            ->where('user_id', $user->id)
            ->latest('activated_at')
            ->limit(10)
            ->get();

        $iconUrls = [];
        foreach ($activations as $activation) {
            foreach ($activation->rewardGrant?->items ?? [] as $reward) {
                $iconUrls[$activation->id][$reward->item_id] = $this->assets->itemIcon(
                    $activation->gameServer,
                    $reward->item_id,
                );
            }
        }

        return view('module-promo-codes::account.index', [
            'user' => $user,
            'activations' => $activations,
            'iconUrls' => $iconUrls,
            'requestToken' => (string) Str::uuid(),
        ]);
    }

    public function activate(
        ActivatePromoCodeRequest $request,
        PromoCodeActivationService $activationService,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $activation = $activationService->activate(
                user: $user,
                code: (string) $request->validated('code'),
                requestToken: (string) $request->validated('request_token'),
            );
        } catch (PromoCodeActivationException $exception) {
            return back()
                ->withInput($request->safe()->only(['code', 'request_token']))
                ->withErrors(['code' => $this->errorMessage($exception->reasonCode)]);
        }

        return redirect()->route('modules.promo-codes.index')->with(
            'status',
            __('module-promo-codes::messages.activation_success', [
                'server' => $activation->gameServer->nameFor(),
            ]),
        );
    }

    private function errorMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'disabled' => __('module-promo-codes::messages.activation_disabled'),
            'not_started' => __('module-promo-codes::messages.activation_not_started'),
            'expired' => __('module-promo-codes::messages.activation_expired'),
            'total_limit' => __('module-promo-codes::messages.activation_total_limit'),
            'user_limit' => __('module-promo-codes::messages.activation_user_limit'),
            'no_rewards' => __('module-promo-codes::messages.activation_no_rewards'),
            default => __('module-promo-codes::messages.activation_invalid'),
        };
    }
}
