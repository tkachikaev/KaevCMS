<?php

namespace App\Services;

use App\Services\Localization\LanguageManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use InvalidArgumentException;

final class MailTemplateSettings
{
    public const EMAIL_VERIFICATION = 'email_verification';

    public const PASSWORD_RESET = 'password_reset';

    public const PASSWORD_CHANGED = 'password_changed';

    /** @var array<int, string> */
    private const EDITABLE_FIELDS = [
        'subject',
        'header',
        'heading',
        'body',
        'action_text',
        'footer',
    ];

    public function __construct(
        private readonly CmsSettings $settings,
        private readonly LanguageManager $languages,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function navigation(?string $locale = null): array
    {
        $items = [];

        foreach (array_keys($this->definitions()) as $key) {
            $definition = $this->definition($key, $locale);
            $items[$key] = [
                'title' => (string) ($definition['title'] ?? $key),
                'description' => (string) ($definition['description'] ?? ''),
            ];
        }

        return $items;
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /** @return array<string, mixed> */
    public function definition(string $key, ?string $locale = null): array
    {
        $definitions = $this->definitions();

        if (! isset($definitions[$key])) {
            throw new InvalidArgumentException('Unknown mail template: '.$key);
        }

        $base = $definitions[$key];
        $locale = $this->normalizeLocale($locale);
        $localized = $this->localizedDefaults($base, $locale);

        return array_merge($base, $localized, ['locale' => $locale]);
    }

    /**
     * @return array{
     *     key: string,
     *     locale:string,
     *     title: string,
     *     description: string,
     *     requires_action: bool,
     *     variables: array<int, string>,
     *     subject: string,
     *     header: string,
     *     heading: string,
     *     body: string,
     *     action_text: string,
     *     footer: string,
     *     customized: bool
     * }
     */
    public function values(string $key, ?string $locale = null): array
    {
        $definition = $this->definition($key, $locale);
        $locale = (string) $definition['locale'];
        $defaults = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $defaults[$this->settingKey($key, $locale, $field)] = (string) ($definition[$field] ?? '');
        }

        $stored = $this->settings->getMany($defaults);
        $values = [];
        $customized = false;

        foreach (self::EDITABLE_FIELDS as $field) {
            $settingKey = $this->settingKey($key, $locale, $field);
            $default = (string) ($definition[$field] ?? '');
            $value = $stored[$settingKey] ?? $default;

            if ($locale === 'ru' && $this->settings->get($settingKey) === null) {
                $legacy = $this->settings->get($this->legacySettingKey($key, $field));
                if ($legacy !== null) {
                    $value = $legacy;
                    $customized = true;
                }
            }

            $values[$field] = $value;

            if ($this->settings->get($settingKey) !== null) {
                $customized = true;
            }
        }

        return [
            'key' => $key,
            'locale' => $locale,
            'title' => (string) ($definition['title'] ?? $key),
            'description' => (string) ($definition['description'] ?? ''),
            'requires_action' => (bool) ($definition['requires_action'] ?? false),
            'variables' => array_values(array_map('strval', (array) ($definition['variables'] ?? []))),
            'subject' => $values['subject'],
            'header' => $values['header'],
            'heading' => $values['heading'],
            'body' => $values['body'],
            'action_text' => $values['action_text'],
            'footer' => $values['footer'],
            'customized' => $customized,
        ];
    }

    /** @param array{subject: string, header: string, heading: string, body: string, action_text: string, footer: string} $values */
    public function update(string $key, array $values, ?string $locale = null): void
    {
        $definition = $this->definition($key, $locale);
        $locale = (string) $definition['locale'];
        $settings = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $settings[$this->settingKey($key, $locale, $field)] = (string) $values[$field];
        }

        if ($locale === $this->languages->default()) {
            foreach (self::EDITABLE_FIELDS as $field) {
                $settings[$this->legacySettingKey($key, $field)] = (string) $values[$field];
            }
        }

        $this->settings->setMany($settings);
    }

    public function reset(string $key, ?string $locale = null): void
    {
        $definition = $this->definition($key, $locale);
        $locale = (string) $definition['locale'];
        $values = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $values[$this->settingKey($key, $locale, $field)] = null;
            if ($locale === $this->languages->default()) {
                $values[$this->legacySettingKey($key, $field)] = null;
            }
        }

        $this->settings->setMany($values);
    }

    /** @param array<string, string> $values @return array<string, array<int, string>> */
    public function unknownVariables(string $key, array $values, ?string $locale = null): array
    {
        $allowed = array_fill_keys($this->values($key, $locale)['variables'], true);
        $unknown = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $content = (string) ($values[$field] ?? '');
            preg_match_all('/\{\{([^{}]+)\}\}/u', $content, $matches);

            foreach ($matches[1] as $match) {
                $variable = trim((string) $match);

                if ($variable !== '' && ! isset($allowed[$variable])) {
                    $unknown[$field][] = $variable;
                }
            }
        }

        foreach ($unknown as $field => $items) {
            $unknown[$field] = array_values(array_unique($items));
        }

        return $unknown;
    }

    /** @param array<string, string> $values @return array<int, string> */
    public function fieldsContainingHtml(array $values): array
    {
        $fields = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            if (preg_match('/<\s*\/?\s*[a-z][^>]*>/iu', (string) ($values[$field] ?? '')) === 1) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param array<string, string> $variables @return array{subject: string, header: string, heading: string, body: string, action_text: string, footer: string} */
    public function render(string $key, array $variables, ?string $locale = null): array
    {
        $values = $this->values($key, $locale);
        $rendered = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $rendered[$field] = $this->replaceVariables((string) $values[$field], $variables);
        }

        return $rendered;
    }

    /** @param array<string, string> $variables */
    public function mailMessage(string $key, array $variables, ?string $actionUrl = null, ?string $locale = null): MailMessage
    {
        $locale = $this->normalizeLocale($locale);
        $previousLocale = App::getLocale();
        App::setLocale($locale);

        try {
            $template = $this->values($key, $locale);
            $rendered = $this->render($key, $variables, $locale);
            $brandName = $this->plainText($rendered['header']);
            if ($brandName === '') {
                $brandName = $this->plainText($variables['site_name'] ?? site_name($locale));
            }

            $message = (new MailMessage)
                ->subject($this->plainText($rendered['subject']))
                ->greeting($this->plainText($rendered['heading']))
                ->markdown('mail.system-notification', ['brandName' => $brandName]);

            foreach ($this->blocks($rendered['body']) as $block) {
                $message->line(e($block));
            }

            if ($template['requires_action'] && $actionUrl !== null && trim($rendered['action_text']) !== '') {
                $message->action($this->plainText($rendered['action_text']), $actionUrl);
            }

            foreach ($this->blocks($rendered['footer']) as $block) {
                $message->line(e($block));
            }

            $siteName = $this->plainText($variables['site_name'] ?? site_name($locale));

            return $message->salutation(__('Regards, the :site team', ['site' => $siteName]));
        } finally {
            App::setLocale($previousLocale);
        }
    }

    /** @return array<string, string> */
    public function userVariables(object $user, array $additional = [], ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale ?? (string) ($user->locale ?? ''));
        $mail = app(MailSettings::class)->values();
        $site = app(SiteSettings::class)->values($locale);
        $supportEmail = trim((string) ($mail['admin_email'] ?: $site['admin_email']));

        if ($supportEmail === '') {
            $supportEmail = trim((string) $mail['from_address']);
        }

        if ($supportEmail === '') {
            $supportEmail = $this->translateForLocale('site support', $locale);
        }

        return array_merge([
            'site_name' => $site['name'],
            'site_url' => rtrim((string) config('app.url', 'http://localhost'), '/').'/'.$locale,
            'username' => (string) ($user->name ?? $this->translateForLocale('user', $locale)),
            'user_email' => (string) ($user->email ?? ''),
            'support_email' => $supportEmail,
        ], $additional);
    }

    /** @return array<string, string> */
    public function demoVariables(string $key, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $this->definition($key, $locale);
        $siteUrl = rtrim((string) config('app.url', 'http://127.0.0.1:8000'), '/').'/'.$locale;

        return [
            'site_name' => app(SiteSettings::class)->name($locale),
            'site_url' => $siteUrl,
            'username' => 'TestPlayer',
            'user_email' => 'player@example.com',
            'verification_url' => $siteUrl.'/email/verify/example',
            'reset_url' => $siteUrl.'/reset-password/example',
            'expires_in' => $this->translateForLocale('60 minutes', $locale),
            'support_email' => 'support@example.com',
        ];
    }

    public function translatedDuration(string $locale): string
    {
        $locale = $this->normalizeLocale($locale);

        return $this->translateForLocale('60 minutes', $locale);
    }

    public function requiresAction(string $key): bool
    {
        return (bool) ($this->definitions()[$key]['requires_action'] ?? false);
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        $templates = config('mail_templates.templates', []);

        return is_array($templates) ? $templates : [];
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    private function localizedDefaults(array $base, string $locale): array
    {
        $locales = is_array($base['locales'] ?? null) ? $base['locales'] : [];
        $candidates = $this->languages->fallbackCandidates($locale);

        foreach ($candidates as $candidate) {
            if (isset($locales[$candidate]) && is_array($locales[$candidate])) {
                return $locales[$candidate];
            }
        }

        return [];
    }

    private function settingKey(string $template, string $locale, string $field): string
    {
        return 'mail.template.'.$template.'.'.$locale.'.'.$field;
    }

    private function legacySettingKey(string $template, string $field): string
    {
        return 'mail.template.'.$template.'.'.$field;
    }

    private function normalizeLocale(?string $locale): string
    {
        $locale = $this->languages->normalizeCode((string) ($locale ?? app()->getLocale()));

        return $locale !== null && $this->languages->isEnabled($locale)
            ? $locale
            : $this->languages->default();
    }

    /** @param array<string, string> $variables */
    private function replaceVariables(string $value, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/iu',
            static function (array $matches) use ($variables): string {
                $name = strtolower((string) $matches[1]);

                return array_key_exists($name, $variables)
                    ? (string) $variables[$name]
                    : (string) $matches[0];
            },
            $value,
        );
    }

    /** @return array<int, string> */
    private function blocks(string $value): array
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        if ($value === '') {
            return [];
        }

        $blocks = preg_split('/\n{2,}/u', $value) ?: [];

        return array_values(array_filter(array_map('trim', $blocks), static fn (string $block): bool => $block !== ''));
    }

    /** @param array<string, scalar> $replace */
    private function translateForLocale(string $key, string $locale, array $replace = []): string
    {
        $previousLocale = App::getLocale();
        App::setLocale($locale);

        try {
            return __($key, $replace);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    private function plainText(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
