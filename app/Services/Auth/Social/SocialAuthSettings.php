<?php

namespace App\Services\Auth\Social;

use App\Services\CmsSettings;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** Website-user social login settings. Never include decrypted secrets in view or audit values. */
final class SocialAuthSettings
{
    private const PREFIX = 'social_auth.';

    private const PROVIDER_NAMES = [
        SocialProviders::GOOGLE => 'Google',
        SocialProviders::YANDEX => 'Яндекс ID',
        SocialProviders::VK => 'VK ID',
        SocialProviders::TELEGRAM => 'Telegram',
        SocialProviders::DISCORD => 'Discord',
        SocialProviders::FACEBOOK => 'Facebook',
    ];

    private const PROVIDER_CONSOLES = [
        SocialProviders::GOOGLE => 'https://console.cloud.google.com/apis/credentials',
        SocialProviders::YANDEX => 'https://oauth.yandex.ru/',
        SocialProviders::VK => 'https://id.vk.ru/',
        SocialProviders::TELEGRAM => 'https://t.me/BotFather',
        SocialProviders::DISCORD => 'https://discord.com/developers/applications',
        SocialProviders::FACEBOOK => 'https://developers.facebook.com/apps/',
    ];

    public function __construct(private readonly CmsSettings $settings) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $result = [];
        foreach (SocialProviders::all() as $provider) {
            $result[$provider] = $this->values($provider);
        }

        return $result;
    }

    /** @return array<string, mixed> No secret plaintext is returned. */
    public function values(string $provider): array
    {
        SocialProviders::assertAllowed($provider);

        $data = $this->settings->getMany([
            $this->key($provider, 'client_id') => '',
            $this->key($provider, 'client_secret_encrypted') => '',
            $this->key($provider, 'enabled') => '0',
        ]);
        $clientId = trim((string) $data[$this->key($provider, 'client_id')]);
        $ciphertext = (string) $data[$this->key($provider, 'client_secret_encrypted')];
        $secretSaved = $ciphertext !== '';
        $secretValid = $secretSaved && $this->decryptedSecret($ciphertext) !== null;
        $callback = $this->callbackUrl($provider);
        $enabled = $data[$this->key($provider, 'enabled')] === '1';
        $requiresSecret = $provider !== SocialProviders::VK;
        $configured = $clientId !== '' && (! $requiresSecret || $secretValid) && $callback !== null;
        $integrationReady = $provider !== SocialProviders::TELEGRAM || app(TelegramIdTokenVerifier::class)->available();
        $status = $secretSaved && ! $secretValid ? 'invalid' : (
            $configured && ! $integrationReady ? 'pending' : (
                $enabled && $configured ? 'enabled' : ($enabled ? 'invalid' : ($configured ? 'ready' : 'unconfigured'))
            )
        );

        return [
            'name' => self::PROVIDER_NAMES[$provider],
            'console' => self::PROVIDER_CONSOLES[$provider],
            'client_id' => $clientId,
            'secret_saved' => $secretSaved,
            'secret_valid' => $secretValid,
            'requires_secret' => $requiresSecret,
            'configured' => $configured,
            'enabled' => $enabled,
            'integration_ready' => $integrationReady,
            'available' => $enabled && $configured && $integrationReady,
            'status' => $status,
            'callback_url' => $callback,
        ];
    }

    public function isAvailable(string $provider): bool
    {
        return (bool) $this->values($provider)['available'];
    }

    /**
     * Server-side integration only. Never pass the result to a view, log or audit.
     *
     * @return array{client_id: string, client_secret: string|null, redirect_uri: string|null}|null
     */
    public function credentialsFor(string $provider): ?array
    {
        if (! $this->isAvailable($provider)) {
            return null;
        }

        $secret = (string) $this->settings->get($this->key($provider, 'client_secret_encrypted'), '');

        return [
            'client_id' => (string) $this->values($provider)['client_id'],
            'client_secret' => $secret === '' ? null : $this->decryptedSecret($secret),
            'redirect_uri' => $this->callbackUrl($provider),
        ];
    }

    /**
     * Save configuration; a blank secret keeps the previous encrypted value.
     *
     * @return array{client_id_changed: bool, secret_changed: bool, disabled_on_id_change: bool}
     */
    public function updateCredentials(string $provider, string $clientId, ?string $secret): array
    {
        SocialProviders::assertAllowed($provider);
        $clientId = trim($clientId);
        $old = $this->values($provider);
        $values = [$this->key($provider, 'client_id') => $clientId];
        if ($provider !== SocialProviders::VK && $secret !== null && $secret !== '') {
            $values[$this->key($provider, 'client_secret_encrypted')] = Crypt::encryptString($secret);
        }

        $idChanged = $old['client_id'] !== $clientId;
        // A rotated client ID may change the identity namespace. Require deliberate re-enabling.
        if ($idChanged) {
            $values[$this->key($provider, 'enabled')] = '0';
        }

        $this->settings->setMany($values);

        return [
            'client_id_changed' => $idChanged,
            'secret_changed' => $provider !== SocialProviders::VK && $secret !== null && $secret !== '',
            'disabled_on_id_change' => $idChanged && $old['enabled'],
        ];
    }

    public function setEnabled(string $provider, bool $enabled): void
    {
        SocialProviders::assertAllowed($provider);
        if ($enabled && (! $this->values($provider)['configured'] || ! $this->values($provider)['integration_ready'])) {
            throw new DomainException('Social login provider configuration is incomplete.');
        }
        $this->settings->set($this->key($provider, 'enabled'), $enabled ? '1' : '0');
    }

    /** Explicitly removing the secret always disables the provider. */
    public function clearSecret(string $provider): void
    {
        SocialProviders::assertAllowed($provider);
        $this->settings->setMany([
            $this->key($provider, 'client_secret_encrypted') => null,
            $this->key($provider, 'enabled') => '0',
        ]);
    }

    /** Absolute URL derived only from configured APP_URL, never from request Host. */
    public function callbackUrl(string $provider): ?string
    {
        SocialProviders::assertAllowed($provider);
        $url = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $local = in_array((string) config('app.env'), ['local', 'testing'], true)
            && in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
        if ($host === '' || ($scheme !== 'https' && ! ($local && $scheme === 'http'))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $url.'/auth/social/'.$provider.'/callback';
    }

    private function key(string $provider, string $field): string
    {
        return self::PREFIX.$provider.'.'.$field;
    }

    private function decryptedSecret(string $ciphertext): ?string
    {
        try {
            $secret = Crypt::decryptString($ciphertext);

            return trim($secret) === '' ? null : $secret;
        } catch (DecryptException) {
            return null;
        }
    }
}
