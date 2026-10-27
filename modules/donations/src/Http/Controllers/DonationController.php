<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Wallet\WalletAccountReadService;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Models\DonationOrder;
use KaevCMS\Modules\Donations\Services\DonationMoney;
use KaevCMS\Modules\Donations\Services\DonationOrders;
use KaevCMS\Modules\Donations\Services\DonationPaymentPreparations;
use KaevCMS\Modules\Donations\Services\DonationRates;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\DonationPaymentCheckout;
use LogicException;

final class DonationController extends Controller
{
    public function __invoke(
        Request $request,
        DonationSettings $settings,
        WalletAccountReadService $wallets,
        DonationRates $rates,
        DonationMoney $money,
        DonationPaymentPreparations $payments,
        WalletAppearanceSettings $appearance,
    ): View {
        // Module enabled is not enough: owner must opt in to showing the player page.
        abort_unless($settings->isVisibleToPlayers(), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $limits = $rates->limits();
        $currencies = $rates->availableForPlayers(app()->getLocale());
        $canStartCheckout = DonationPaymentCheckout::enabled() && DonationPaymentCheckout::allowedForUser($user);
        $paymentProviders = [];
        if ($canStartCheckout) {
            $currencies = array_values(array_filter($currencies, static function (array $currency) use ($payments, &$paymentProviders): bool {
                $available = false;
                foreach ($payments->available($currency['code']) as $provider) {
                    if ($provider['mode'] === 'live') {
                        $paymentProviders[$provider['code']] ??= ['code' => $provider['code'], 'name' => $provider['name'], 'currencies' => []];
                        $paymentProviders[$provider['code']]['currencies'][] = $currency['code'];
                        $available = true;
                    }
                }

                return $available;
            }));
        }
        $firstCurrency = $currencies[0]['code'] ?? '';
        $defaultCoins = min($limits['max_coins'], max($limits['min_coins'], 100));

        $hasInput = $request->query->has('coins') || $request->query->has('currency');
        $requestedCoins = $hasInput ? $request->query('coins') : (string) $defaultCoins;
        $requestedCode = $hasInput ? $request->query('currency') : $firstCurrency;
        $coins = is_string($requestedCoins) && preg_match('/\A[1-9][0-9]{0,6}\z/D', $requestedCoins)
            ? (int) $requestedCoins : null;
        $code = is_string($requestedCode) && preg_match('/\A[A-Z]{3}\z/D', $requestedCode)
            ? $requestedCode : '';
        $availableCodes = array_column($currencies, 'code');
        $error = null;
        $quote = null;

        if ($currencies !== []) {
            if ($coins === null || $coins < $limits['min_coins'] || $coins > $limits['max_coins']) {
                $error = __('module-donations::messages.player_invalid_coins');
            } elseif (! in_array($code, $availableCodes, true)) {
                $error = __('module-donations::messages.player_invalid_currency');
            } else {
                try {
                    // Always re-read the server-side price; never trust an amount submitted by a browser.
                    $quote = $rates->quote($code, $coins);
                } catch (InvalidArgumentException) {
                    $error = __('module-donations::messages.player_quote_unavailable');
                }
            }
        }

        /** @var view-string $view */
        $view = $request->boolean('dialog') ? 'module-donations::account.calculator' : 'module-donations::account.index';

        return view($view, [
            'user' => $user,
            'walletView' => $wallets->forUser($user),
            'currencies' => $currencies,
            'limits' => $limits,
            'selectedCurrency' => $code,
            'coinsInput' => $coins === null ? '' : (string) $coins,
            'quote' => $quote,
            'quoteError' => $error,
            'money' => $money,
            'draftRequestKey' => (string) Str::uuid(),
            'liveCheckoutEnabled' => $canStartCheckout && $currencies !== [],
            'paymentProviders' => array_values($paymentProviders),
            'isDialog' => $request->boolean('dialog'),
            'coinForms' => $appearance->forms(),
            'coinsLabel' => $quote !== null ? $appearance->label($quote['coins']) : '—',
        ]);
    }

    /** A deliberate top-up action creates immutable terms, never a transfer or Wallet credit. */
    public function topUp(Request $request, DonationOrders $orders, DonationPaymentPreparations $payments, DonationPaymentCheckout $checkout, DonationRates $rates): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'coins' => ['required', 'integer', 'min:1', 'max:1000000'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'provider' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_-]{1,39}\z/D'],
            'request_key' => ['required', 'uuid'],
            'quoted_total_minor' => ['required', 'string', 'regex:/\A[0-9]{1,16}\z/D'],
        ]);

        try {
            return DB::transaction(function () use ($user, $data, $orders, $payments, $checkout, $rates): JsonResponse {
                // Match the existing user -> settings locking order. Price updates take this settings lock too.
                User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                DB::table('module_donation_settings')->where('id', 1)->lockForUpdate()->first();
                if (! DonationPaymentCheckout::enabled() || ! DonationPaymentCheckout::allowedForUser($user)
                    || ! app(DonationSettings::class)->isVisibleToPlayers()
                    || ! collect($payments->available($data['currency']))->contains(fn (array $provider): bool => $provider['code'] === $data['provider'] && $provider['mode'] === 'live')) {
                    throw new LogicException('Payment provider unavailable.');
                }
                $existing = DonationOrder::query()->where('user_id', $user->getKey())->where('idempotency_key', strtolower($data['request_key']))->first();
                $quote = $existing === null ? $rates->quote($data['currency'], (int) $data['coins']) : [
                    'coins' => (int) $existing->coins, 'currency_code' => $existing->currency_code,
                    'unit_price_minor' => (int) $existing->unit_price_minor, 'total_minor' => (int) $existing->total_minor,
                    'exponent' => (int) $existing->exponent,
                ];
                if ((string) $quote['total_minor'] !== $data['quoted_total_minor']) {
                    return response()->json(['message' => __('module-donations::messages.topup_price_changed'), 'quote' => $quote], 409)
                        ->header('Cache-Control', 'private, no-store');
                }
                $order = $orders->createDraft($user, $data['currency'], (int) $data['coins'], $data['request_key']);
                $payments->prepare($user, $order->order_uuid, $data['provider']);
                $form = $checkout->build($user, $order);

                return response()->json([
                    'action' => $form->redirectUrl, 'method' => $form->method, 'fields' => $form->fields,
                    'order_url' => route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]),
                ])->header('Cache-Control', 'private, no-store');
            });
        } catch (InvalidArgumentException|LogicException|ModelNotFoundException) {
            throw ValidationException::withMessages(['order' => __('module-donations::messages.order_unavailable')]);
        }
    }

    /** Order history is deliberately independent of new-order visibility. */
    public function orders(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return redirect()->to(public_route('wallet.index', $request->only(['inactive', 'page'])));
    }

    public function show(Request $request, string $orderUuid, DonationOrders $orders, DonationMoney $money, DonationSettings $settings, DonationPaymentPreparations $payments, DonationPaymentCheckout $checkout): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $order = $orders->forUser($user, $orderUuid);
        abort_unless($order instanceof DonationOrder, 404);

        /** @var view-string $view */
        $view = 'module-donations::account.order';

        return view($view, [
            'user' => $user, 'order' => $order, 'money' => $money,
            'canChooseCoins' => $settings->isVisibleToPlayers(),
            'paymentPreparation' => $payments->forOrder($order),
            'canCheckout' => $checkout->available($user, $order),
            'availableProviders' => $settings->isVisibleToPlayers()
                && DonationPaymentCheckout::enabled() && DonationPaymentCheckout::allowedForUser($user)
                ? array_values(array_filter($payments->available($order->currency_code), static fn (array $provider): bool => $provider['mode'] === 'live')) : [],
        ]);
    }

    /** Read-only render of a server-built POST checkout. Browser return is NOT payment proof. */
    public function checkout(Request $request, string $orderUuid, DonationOrders $orders, DonationPaymentCheckout $checkout, DonationMoney $money): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $order = $orders->forUser($user, $orderUuid);
        abort_unless($order instanceof DonationOrder, 404);
        abort_unless($checkout->available($user, $order), 404);
        $form = $checkout->build($user, $order);

        return response()->view('module-donations::account.checkout', [
            'user' => $user, 'order' => $order, 'money' => $money, 'form' => $form,
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** This creates only a provider-free draft; there is no checkout or wallet credit. */
    public function store(Request $request, DonationOrders $orders, DonationPaymentPreparations $payments, DonationPaymentCheckout $checkout): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'coins' => ['required', 'integer', 'min:1', 'max:1000000'],
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/D'],
            'request_key' => ['required', 'uuid'],
        ]);
        try {
            $order = $orders->createDraft($user, $data['currency'], (int) $data['coins'], $data['request_key']);
        } catch (InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['order' => __('module-donations::messages.order_unavailable')]);
        }

        // Exactly one available payment provider needs no separate selection page.
        // A draft remains recoverable in order history if preparation is unavailable.
        if (DonationPaymentCheckout::enabled() && DonationPaymentCheckout::allowedForUser($user)) {
            $available = array_values(array_filter($payments->available($order->currency_code),
                static fn (array $provider): bool => $provider['mode'] === 'live'));
            if (count($available) === 1) {
                try {
                    $payments->prepare($user, $order->order_uuid, $available[0]['code']);
                    if ($checkout->available($user, $order)) {
                        return redirect()->route('modules.donations.orders.checkout', ['orderUuid' => $order->order_uuid]);
                    }
                } catch (InvalidArgumentException|LogicException|ModelNotFoundException) {
                    // Payment creation must never pretend that an unfinished order is payable.
                }
            }
        }

        return redirect()->route('modules.donations.orders.show', ['orderUuid' => $order->order_uuid]);
    }

    /** Prepare and audit immutable payment terms; does not initiate an external payment. */
    public function prepare(Request $request, string $orderUuid, DonationPaymentPreparations $payments): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate(['provider' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_-]{1,39}\z/D']]);
        try {
            $payments->prepare($user, $orderUuid, $data['provider']);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (InvalidArgumentException|LogicException) {
            return back()->withErrors(['order' => __('module-donations::messages.preparation_unavailable')]);
        }

        if (app(DonationPaymentCheckout::class)->available($user, app(DonationOrders::class)->forUser($user, $orderUuid))) {
            return redirect()->route('modules.donations.orders.checkout', ['orderUuid' => $orderUuid]);
        }

        return redirect()->route('modules.donations.orders.show', ['orderUuid' => $orderUuid]);
    }

    public function cancel(Request $request, string $orderUuid, DonationOrders $orders): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        try {
            $orders->cancelDraft($user, $orderUuid);
        } catch (ModelNotFoundException $exception) {
            abort(404);
        } catch (InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['order' => __('module-donations::messages.order_unavailable')]);
        }

        if ($request->input('return_to') === 'history') {
            return redirect()->to(public_route('wallet.index'))->with('status', __('Top-up cancelled'));
        }

        return redirect()->route('modules.donations.orders.show', ['orderUuid' => $orderUuid])
            ->with('status', __('module-donations::messages.draft_closed'));
    }
}
