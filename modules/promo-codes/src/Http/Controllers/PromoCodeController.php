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
            if ($activation->reward_inventory_grant_id === null) {
                continue;
            }

            foreach ($activation->rewardGrant->items as $reward) {
                $iconUrls[$activation->id][$reward->item_id] = $this->assets->itemIcon(
                    $activation->gameServer,
                    $reward->item_id,
                );
            }
        }

        /** @var view-string $view */
        $view = 'module-promo-codes::account.index';

        return view($view, [
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
            $message = __($exception->translationKey());

            return back()
                ->withInput($request->safe()->only(['code', 'request_token']))
                ->withErrors(['code' => $message])
                ->with('account_operation', [
                    'type' => 'error',
                    'eyebrow' => __('module-promo-codes::messages.account_title'),
                    'title' => __('module-promo-codes::messages.activation_failed_title'),
                    'message' => $message,
                ]);
        }

        $items = [];
        foreach ($activation->rewardGrant->items as $reward) {
            $items[] = [
                'item_id' => $reward->item_id,
                'name' => $reward->displayName($activation->game_server_id),
                'amount' => $reward->amount,
                'icon_url' => $this->assets->itemIcon($activation->gameServer, $reward->item_id),
            ];
        }

        $message = __('module-promo-codes::messages.activation_success', [
            'server' => $activation->gameServer->nameFor(),
        ]);

        return redirect()->route('modules.promo-codes.index')
            ->with('status', $message)
            ->with('account_operation', [
                'type' => 'success',
                'eyebrow' => __('module-promo-codes::messages.account_title'),
                'title' => __('module-promo-codes::messages.activation_success_title'),
                'message' => $message,
                'items' => $items,
                'action_url' => public_route('web-inventory.index', ['server' => $activation->game_server_id]),
                'action_label' => __('module-promo-codes::messages.open_inventory'),
            ]);
    }
}
