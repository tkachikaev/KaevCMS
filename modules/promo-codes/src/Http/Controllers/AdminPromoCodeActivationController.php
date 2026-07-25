<?php

namespace KaevCMS\Modules\PromoCodes\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;

final class AdminPromoCodeActivationController extends Controller
{
    public function __invoke(): View
    {
        return view('module-promo-codes::admin.activations', [
            'activations' => PromoCodeActivation::query()
                ->with(['promoCode.rewards', 'user', 'gameServer.translations', 'rewardGrant.items'])
                ->latest('activated_at')
                ->latest('id')
                ->paginate(30),
        ]);
    }
}
