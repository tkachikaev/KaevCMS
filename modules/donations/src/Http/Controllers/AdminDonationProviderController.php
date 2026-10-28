<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Services\Admin\AdminPathSettings;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\RoutablePaymentProvider;
use KaevCMS\Modules\Donations\Services\DonationLiveActivation;
use KaevCMS\Modules\Donations\Services\DonationMoney;
use KaevCMS\Modules\Donations\Services\DonationProviderRegistry;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use KaevCMS\Modules\Donations\Services\DonationYooMoneyNotificationTest;
use KaevCMS\Modules\Donations\Services\FreeKassaAdminTestPayments;
use KaevCMS\Modules\Donations\Services\FreeKassaCallbackSecurity;
use Throwable;

final class AdminDonationProviderController extends Controller
{
    public function index(AdminPathSettings $paths, DonationProviderSettings $settings): View
    {
        /** @var view-string $view */
        $view = 'module-donations::admin.providers';

        return view($view, [
            'adminPath' => $paths->path(),
            'providers' => $settings->overview(),
            'yoomoneyAccepting' => app(DonationLiveActivation::class)->state()['mode'] !== 'off',
        ]);
    }

    public function show(string $providerCode, AdminPathSettings $paths, DonationProviderSettings $settings,
        DonationProviderRegistry $registry, Request $request, DonationLiveActivation $activation): View|JsonResponse
    {
        abort_if($providerCode === 'paypal', 404); // PayPal has a separate verified settings contract.
        try {
            $driver = $registry->get($providerCode);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        $state = collect($settings->overview())->firstWhere('code', $providerCode);
        abort_unless($state !== null, 404);

        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $testStatus = $providerCode === 'freekassa' ? $this->freeKassaTestStatus($admin) : null;
        if ($providerCode === 'freekassa' && $request->boolean('test_status') && $request->expectsJson()) {
            return response()->json($testStatus)->header('Cache-Control', 'private, no-store');
        }

        $wallet = '';
        $publicMerchantId = '';
        $hasSecret = false;
        if ($providerCode === 'freekassa') {
            try {
                $publicMerchantId = $settings->credentialsForDriver('freekassa')['merchant_id'] ?? '';
            } catch (Throwable) {
                // Public merchant id is optional; never expose decryption errors.
            }
        }
        if ($providerCode === 'yoomoney') {
            try {
                $stored = $settings->credentialsForDriver('yoomoney');
                $wallet = $stored['receiver'] ?? '';
                $hasSecret = ($stored['notification_secret'] ?? '') !== '';
            } catch (Throwable) {
                // Never reveal errors containing credentials in the admin UI.
            }
        }
        $webhookUrl = $driver instanceof RoutablePaymentProvider
            ? route($driver->callbackRouteName()) : '';
        $webhookHost = strtolower((string) parse_url($webhookUrl, PHP_URL_HOST));
        $webhookIsLocal = ! str_starts_with($webhookUrl, 'https://')
            || in_array($webhookHost, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($webhookHost, '.localhost');

        $freeKassaPolicy = null;
        $freeKassaPolicyError = false;
        if ($providerCode === 'freekassa') {
            try {
                $freeKassaPolicy = app(FreeKassaCallbackSecurity::class)->current();
            } catch (Throwable) {
                // Damaged policy blocks callbacks but must not lock the admin out
                // of editing the sender list. Never display the stored ciphertext.
                $freeKassaPolicy = ['senders' => [], 'proxies' => [], 'customized' => true];
                $freeKassaPolicyError = true;
            }
        }

        /** @var view-string $view */
        $view = 'module-donations::admin.provider';

        return view($view, [
            'adminPath' => $paths->path(), 'driver' => $driver, 'state' => $state,
            'fields' => $driver->credentialFields(),
            'availableCurrencies' => $settings->configuredCurrencies($driver),
            'diagnostic' => $settings->localDiagnostic($providerCode),
            'publicWalletNumber' => $wallet, 'publicMerchantId' => $publicMerchantId,
            'hasNotificationSecret' => $hasSecret,
            'webhookUrl' => $webhookUrl, 'webhookIsLocal' => $webhookIsLocal,
            'freeKassaPolicy' => $freeKassaPolicy,
            'freeKassaTests' => $providerCode === 'freekassa' ? app(FreeKassaAdminTestPayments::class)->recent($admin) : [],
            'freeKassaPolicyError' => $freeKassaPolicyError,
            'freeKassaTestStatus' => $testStatus,
            'activation' => $activation->state(),
            'readinessIssues' => $activation->blockers(),
            'pilotPlayer' => $activation->state()['pilot_user_id']
                ? User::query()->find($activation->state()['pilot_user_id']) : null,
            'playerSearch' => is_string($request->query('player_search'))
                ? mb_substr(trim($request->query('player_search')), 0, 64) : '',
            'players' => $providerCode === 'yoomoney'
                ? User::query()->where('is_active', true)->when(is_string($request->query('player_search'))
                    && trim($request->query('player_search')) !== '', function ($query) use ($request): void {
                        $term = mb_substr(trim($request->query('player_search')), 0, 64);
                        $query->where(function ($q) use ($term): void {
                            $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%');
                            if (ctype_digit($term)) {
                                $q->orWhere('id', (int) $term);
                            }
                        });
                    })->orderByDesc('id')->limit(25)->get(['id', 'name', 'email']) : collect(),
        ]);
    }

    /** @return array{state:string, reference:?string, summary:string} */
    private function freeKassaTestStatus(Admin $admin): array
    {
        $test = DB::table('module_donation_freekassa_admin_tests')->where('admin_id', $admin->getKey())->latest('id')->first();
        if ($test === null) {
            return ['state' => 'none', 'reference' => null, 'summary' => ''];
        }
        $state = $test->status === 'verified' ? 'verified'
            : (Carbon::parse($test->expires_at)->isPast() ? 'expired' : 'pending');

        return ['state' => $state, 'reference' => (string) $test->reference,
            'summary' => app(DonationMoney::class)->format((int) $test->amount_minor, 2).' '.$test->currency_code];
    }

    public function clearFreeKassaTests(Request $request, ModuleAdminAuthorizer $access,
        FreeKassaAdminTestPayments $tests): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.freekassa.tests.clear', 'DELETE', $admin);
        $count = $tests->clearCompleted($admin);

        return redirect()->route('admin.module-pages.donations.providers.show', [
            'adminPath' => app(AdminPathSettings::class)->path(), 'providerCode' => 'freekassa',
        ])->with('status', __('module-donations::messages.freekassa_tests_cleared', ['count' => $count]));
    }

    public function startFreeKassaTestPurchase(Request $request, ModuleAdminAuthorizer $access,
        FreeKassaAdminTestPayments $tests): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.freekassa.test-purchase', 'POST', $admin);
        $currency = $request->input('currency_code');
        $amount = $request->input('amount');
        if (! is_string($currency) || ! is_string($amount)) {
            return back()->withErrors(['freekassa_test' => __('module-donations::messages.freekassa_test_invalid')]);
        }
        try {
            $checkout = $tests->start($admin, $currency, $amount);
        } catch (InvalidArgumentException|\LogicException) {
            return back()->withErrors(['freekassa_test' => __('module-donations::messages.freekassa_test_invalid')]);
        }

        // Finish the POST on our own origin. The provider can redirect to another
        // checkout host; an ordinary link must start that navigation, not this form.
        return redirect()->route('admin.module-pages.donations.providers.show', [
            'adminPath' => app(AdminPathSettings::class)->path(), 'providerCode' => 'freekassa',
        ], 303)->with('freekassa_test_checkout_url', $checkout['url']);
    }

    public function updateActivation(Request $request, AdminPathSettings $paths,
        ModuleAdminAuthorizer $access, DonationLiveActivation $activation): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.activation.update', 'PUT', $admin);
        $mode = $request->input('payment_mode');
        $pilot = $request->input('pilot_user_id');
        if (! is_string($mode) || (! is_null($pilot) && ! is_string($pilot))
            || (is_string($pilot) && $pilot !== '' && ! ctype_digit($pilot))) {
            return back()->withErrors(['activation' => __('module-donations::messages.activation_invalid')]);
        }
        try {
            $activation->update($admin, $mode, is_string($pilot) && $pilot !== '' ? (int) $pilot : null);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['activation' => __('module-donations::messages.activation_invalid')]);
        }

        $returnProvider = $request->input('return_provider');
        $returnProvider = in_array($returnProvider, ['yoomoney', 'freekassa'], true) ? $returnProvider : 'yoomoney';

        return redirect()->route('admin.module-pages.donations.providers.show', [
            'adminPath' => $paths->path(), 'providerCode' => $returnProvider,
        ])->with('status', __('module-donations::messages.activation_saved'));
    }

    /** "Test" button: wait up to five minutes for YooMoney's test notification. */
    public function startNotificationTest(Request $request, ModuleAdminAuthorizer $access,
        DonationYooMoneyNotificationTest $test): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.notification-test.start', 'POST', $admin);
        $secret = $request->input('secret', '');
        if (! is_string($secret) && $secret !== null) {
            return response()->json(['error' => __('module-donations::messages.yoomoney_test_secret_required')], 422);
        }

        try {
            $token = $test->start($admin, (string) $secret);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => __('module-donations::messages.yoomoney_test_secret_required')], 422);
        }

        return response()->json([
            'token' => $token,
            'expires_in' => DonationYooMoneyNotificationTest::TTL_SECONDS,
        ]);
    }

    public function notificationTestStatus(string $token, Request $request, ModuleAdminAuthorizer $access,
        DonationYooMoneyNotificationTest $test): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.notification-test.status', 'GET', $admin);

        return response()->json($test->status($token));
    }

    public function update(string $providerCode, Request $request, AdminPathSettings $paths,
        ModuleAdminAuthorizer $access, DonationProviderSettings $settings): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.update', 'PUT', $admin);

        // YooMoney has a single switch in the UI. Provider and activation are
        // still audited independently and changed in one database transaction.
        if ($providerCode === 'yoomoney' && $request->has('payment_enabled')) {
            $switch = $request->input('payment_enabled');
            $fields = $request->input('credentials', []);
            if (! in_array($switch, ['0', '1', 0, 1], true) || ! is_array($fields)
                || array_diff(array_keys($fields), ['receiver', 'notification_secret']) !== []) {
                return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
            }
            foreach ($fields as $value) {
                if (! is_string($value) && $value !== null) {
                    return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
                }
            }
            $fields = array_map(static fn ($value): string => is_string($value) ? $value : '', $fields);
            $wantPayment = (string) $switch === '1';
            $ready = false;
            try {
                DB::transaction(function () use ($admin, $fields, $wantPayment, $settings, &$ready): void {
                    // Even when OFF, keep credentials for the next enable and for
                    // verification of previously initiated transfers.
                    $settings->save($admin, 'yoomoney', $wantPayment, 'live', ['RUB'], $fields);
                    $activation = app(DonationLiveActivation::class);
                    if ($wantPayment) {
                        app(DonationSettings::class)->updateVisibility(true, $admin);
                        $ready = $activation->blockers() === [];
                    }
                    $activation->update($admin, $wantPayment && $ready ? 'live' : 'off', null);
                });
            } catch (InvalidArgumentException|\RuntimeException) {
                return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
            }
            if ($wantPayment && ! $ready) {
                return redirect()->route('admin.module-pages.donations.providers.show', [
                    'adminPath' => $paths->path(), 'providerCode' => 'yoomoney',
                ])->withErrors(['provider' => __('module-donations::messages.yoomoney_not_ready')]);
            }

            return redirect()->route('admin.module-pages.donations.providers.show', [
                'adminPath' => $paths->path(), 'providerCode' => 'yoomoney',
            ])->with('status', __('module-donations::messages.provider_saved'));
        }

        // Explicit failure handling avoids flashing API keys/passwords into session old input.
        $enabled = $request->input('enabled');
        $mode = $request->input('mode');
        $codes = $request->input('currency_codes', []);
        $credentials = $request->input('credentials', []);
        if (! in_array($enabled, ['0', '1', 0, 1], true) || ! is_string($mode)
            || ! is_array($codes) || ! is_array($credentials)) {
            return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
        }
        // Laravel ConvertEmptyStringsToNull turns blank password controls into null.
        // Preserve an existing credential when the owner leaves its field blank.
        foreach ($credentials as $field => $value) {
            if ($value === null) {
                $credentials[$field] = '';
            }
        }
        try {
            $senders = null;
            $proxies = null;
            if ($providerCode === 'freekassa') {
                $senders = $request->exists('callback_sender_ips')
                    ? FreeKassaCallbackSecurity::parseSubmitted($request->input('callback_sender_ips') ?? '') : null;
                $proxies = $request->exists('callback_proxy_ips')
                    ? FreeKassaCallbackSecurity::parseSubmitted($request->input('callback_proxy_ips') ?? '', true) : null;
            }
            $settings->save($admin, $providerCode, (string) $enabled === '1', $mode,
                $codes, $credentials, $senders, $proxies);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
        }

        return redirect()->route('admin.module-pages.donations.providers.show', [
            'adminPath' => $paths->path(), 'providerCode' => $providerCode,
        ])->with('status', __('module-donations::messages.provider_saved'));
    }
}
