<?php

namespace App\Providers;

use App\Auth\Passwords\UtcPasswordBrokerManager;
use App\Http\Middleware\RequireAdminAuthentication;
use App\Services\Account\AccountAvatarCatalog;
use App\Services\Admin\AdminPathSettings;
use App\Services\AdminLoginService;
use App\Services\AdminTwoFactorAuthentication;
use App\Services\AuditLogger;
use App\Services\GameAccountSettings;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\GameAssets\GameItemProfileCatalog;
use App\Services\GameServerSettings;
use App\Services\Html\SafeHtmlSanitizer;
use App\Services\Infrastructure\PublicUploadProtection;
use App\Services\Infrastructure\QueueMaintenance;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Localization\LanguageManager;
use App\Services\Localization\LocalizedContentResolver;
use App\Services\Mail\MailDeliveryDispatcher;
use App\Services\Mail\MailDeliveryMonitor;
use App\Services\MailSettings;
use App\Services\MailTemplateSettings;
use App\Services\News\NewsHtmlSanitizer;
use App\Services\News\NewsImageStorage;
use App\Services\Pages\PageHtmlSanitizer;
use App\Services\Pages\PageImageStorage;
use App\Services\Pages\PageNavigation;
use App\Services\RegistrationSettings;
use App\Services\Security\EncryptionHealth;
use App\Services\SecurityLogMaintenance;
use App\Services\SecuritySettings;
use App\Services\Servers\ServerMonitorSettings;
use App\Services\Settings\SettingsImageStorage;
use App\Services\SiteSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('auth.password', fn ($manager, $app): UtcPasswordBrokerManager => new UtcPasswordBrokerManager($app));
        $this->app->scoped(AccountAvatarCatalog::class);
        $this->app->singleton(AdminPathSettings::class);
        $this->app->singleton(AdminLoginService::class);
        $this->app->singleton(AdminTwoFactorAuthentication::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(GameAccountSettings::class);
        $this->app->singleton(GameItemProfileCatalog::class);
        $this->app->singleton(GameAssetUrlResolver::class);
        $this->app->singleton(GameItemCatalog::class);
        $this->app->singleton(GameServerSettings::class);
        $this->app->singleton(RuntimeDiagnostics::class);
        $this->app->singleton(PublicUploadProtection::class);
        $this->app->singleton(QueueMaintenance::class);
        $this->app->singleton(SafeHtmlSanitizer::class);
        $this->app->singleton(MailSettings::class);
        $this->app->singleton(MailDeliveryMonitor::class);
        $this->app->singleton(MailDeliveryDispatcher::class);
        $this->app->singleton(MailTemplateSettings::class);
        $this->app->singleton(LanguageManager::class);
        $this->app->singleton(LocalizedContentResolver::class);
        $this->app->singleton(NewsHtmlSanitizer::class);
        $this->app->singleton(NewsImageStorage::class);
        $this->app->singleton(PageHtmlSanitizer::class);
        $this->app->singleton(PageImageStorage::class);
        $this->app->singleton(PageNavigation::class);
        $this->app->singleton(RegistrationSettings::class);
        $this->app->singleton(EncryptionHealth::class);
        $this->app->singleton(SecurityLogMaintenance::class);
        $this->app->singleton(SecuritySettings::class);
        $this->app->singleton(ServerMonitorSettings::class);
        $this->app->singleton(SettingsImageStorage::class);
        $this->app->singleton(SiteSettings::class);
    }

    public function boot(
        SiteSettings $siteSettings,
        MailSettings $mailSettings,
        LanguageManager $languages,
        SecuritySettings $securitySettings,
        AdminPathSettings $adminPathSettings,
        RuntimeDiagnostics $runtimeDiagnostics,
        PublicUploadProtection $publicUploadProtection,
    ): void {
        $publicUploadProtection->ensure();

        Livewire::addPersistentMiddleware([
            RequireAdminAuthentication::class,
        ]);

        Queue::before(static function (JobProcessing $event) use ($runtimeDiagnostics): void {
            $runtimeDiagnostics->recordQueueStarted(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getJobId(),
                $event->job->resolveName(),
            );
        });

        Queue::after(static function (JobProcessed $event) use ($runtimeDiagnostics): void {
            $runtimeDiagnostics->recordQueueSucceeded(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getJobId(),
                $event->job->resolveName(),
            );
        });

        Queue::exceptionOccurred(static function (JobExceptionOccurred $event) use ($runtimeDiagnostics): void {
            $runtimeDiagnostics->recordQueueException(
                $event->connectionName,
                $event->job->getQueue(),
                $event->exception,
                $event->job->getJobId(),
                $event->job->resolveName(),
            );
        });

        Queue::failing(static function (JobFailed $event) use ($runtimeDiagnostics): void {
            $runtimeDiagnostics->recordQueueFailed(
                $event->connectionName,
                $event->job->getQueue(),
                $event->exception,
                $event->job->getJobId(),
                $event->job->resolveName(),
            );
        });

        $this->configureRateLimiters($securitySettings);

        $defaultLocale = $languages->default();
        $fallbackLocale = $languages->fallback();
        config()->set('app.locale', $defaultLocale);
        config()->set('app.fallback_locale', $fallbackLocale);
        app()->setLocale($defaultLocale);
        app('translator')->setFallback($fallbackLocale);
        $siteSettings->applyConfiguredTimezone();
        $mailSettings->applyConfiguration();
        URL::defaults(['adminPath' => $adminPathSettings->path()]);

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    private function configureRateLimiters(SecuritySettings $securitySettings): void
    {
        RateLimiter::for('public-login', static function (Request $request): array {
            $ip = hash('sha256', $request->ip() ?? 'unknown');
            $identity = hash('sha256', Str::lower(trim((string) $request->input('login', 'unknown'))));

            return [
                Limit::perMinute(max(1, (int) config('cms.public_auth.login_ip_per_minute', 10)))
                    ->by('public-login-ip:'.$ip),
                Limit::perHour(max(1, (int) config('cms.public_auth.login_identity_per_hour', 20)))
                    ->by('public-login-identity:'.$identity),
            ];
        });

        RateLimiter::for('public-registration', static function (Request $request): array {
            $ip = hash('sha256', $request->ip() ?? 'unknown');
            $identity = hash('sha256', Str::lower(trim((string) $request->input('email', 'unknown'))));

            return [
                Limit::perMinute(max(1, (int) config('cms.public_auth.registration_ip_per_minute', 5)))
                    ->by('public-registration-ip:'.$ip),
                Limit::perHour(max(1, (int) config('cms.public_auth.registration_identity_per_hour', 5)))
                    ->by('public-registration-identity:'.$identity),
            ];
        });

        RateLimiter::for('public-password-email', static function (Request $request): array {
            $ip = hash('sha256', $request->ip() ?? 'unknown');
            $identity = hash('sha256', Str::lower(trim((string) $request->input('email', 'unknown'))));

            return [
                Limit::perMinute(max(1, (int) config('cms.public_auth.password_email_ip_per_minute', 5)))
                    ->by('public-password-email-ip:'.$ip),
                Limit::perHour(max(1, (int) config('cms.public_auth.password_email_identity_per_hour', 3)))
                    ->by('public-password-email-identity:'.$identity),
            ];
        });

        RateLimiter::for('public-password-reset', static function (Request $request): array {
            $ip = hash('sha256', $request->ip() ?? 'unknown');
            $identity = hash('sha256', Str::lower(trim((string) $request->input('email', 'unknown'))));

            return [
                Limit::perMinute(max(1, (int) config('cms.public_auth.password_reset_ip_per_minute', 5)))
                    ->by('public-password-reset-ip:'.$ip),
                Limit::perHour(max(1, (int) config('cms.public_auth.password_reset_identity_per_hour', 5)))
                    ->by('public-password-reset-identity:'.$identity),
            ];
        });

        RateLimiter::for('admin-login-ip', static function (Request $request) use ($securitySettings): array {
            $ip = $request->ip() ?? 'unknown';
            $key = 'admin-login-ip:'.hash('sha256', $ip);

            $settings = $securitySettings->values();

            return [
                Limit::perMinute($settings['login_ip_per_minute'])->by($key.':minute'),
                Limit::perHour($settings['login_ip_per_hour'])->by($key.':hour'),
            ];
        });

        RateLimiter::for('admin-two-factor-challenge', static function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $challenge = $request->session()->get('admin_two_factor_challenge');
            $adminId = is_array($challenge) && isset($challenge['admin_id'])
                ? (string) $challenge['admin_id']
                : 'unknown';
            $key = 'admin-two-factor:'.hash('sha256', $adminId.'|'.$ip);
            $perMinute = max(1, (int) config('cms.admin.two_factor_max_attempts_per_minute', 5));
            $perHour = max($perMinute, (int) config('cms.admin.two_factor_max_attempts_per_hour', 20));

            return [
                Limit::perMinute($perMinute)->by($key.':minute'),
                Limit::perHour($perHour)->by($key.':hour'),
            ];
        });

        RateLimiter::for('game-account-create', static function (Request $request): Limit {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinutes(10, 3)->by('game-account-create:'.$userId);
        });

        RateLimiter::for('game-account-password', static function (Request $request): Limit {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perHour(5)->by('game-account-password:'.$userId);
        });

        RateLimiter::for('account-password', static function (Request $request): Limit {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return Limit::perHour(5)->by('account-password:'.$userId);
        });
    }
}
