<?php

namespace App\Services\Notifications;

use App\Services\CmsSettings;
use App\Support\Notifications\AdminNotificationData;
use App\Support\Notifications\AdminNotificationType;

final class AdminNotificationSettings
{
    public const CATEGORY_SUPPORT = 'support';

    public const CATEGORY_MODULES = 'modules';

    public const CATEGORY_CMS_UPDATES = 'cms_updates';

    public const CATEGORY_BACKGROUND_TASKS = 'background_tasks';

    public const CATEGORY_LOGIN_SERVER = 'login_server';

    public const CATEGORY_GAME_SERVER = 'game_server';

    public const CATEGORY_DISK_SPACE = 'disk_space';

    public const CATEGORY_INSTALLER = 'installer';

    public const CATEGORY_DIAGNOSTICS = 'diagnostics';

    public const KEY_AUTO_CLEANUP = 'admin_notifications.auto_cleanup';

    public const KEY_RETENTION_DAYS = 'admin_notifications.retention_days';

    /** @var list<string> */
    private const CATEGORIES = [
        self::CATEGORY_SUPPORT,
        self::CATEGORY_MODULES,
        self::CATEGORY_CMS_UPDATES,
        self::CATEGORY_BACKGROUND_TASKS,
        self::CATEGORY_LOGIN_SERVER,
        self::CATEGORY_GAME_SERVER,
        self::CATEGORY_DISK_SPACE,
        self::CATEGORY_INSTALLER,
        self::CATEGORY_DIAGNOSTICS,
    ];

    /** @var list<int> */
    private const RETENTION_OPTIONS = [30, 60, 90, 180];

    public function __construct(private readonly CmsSettings $settings) {}

    /**
     * @return array{
     *     categories:array<string, bool>,
     *     auto_cleanup:bool,
     *     retention_days:int
     * }
     */
    public function values(): array
    {
        $defaults = [
            self::KEY_AUTO_CLEANUP => '1',
            self::KEY_RETENTION_DAYS => (string) config('cms.admin_notifications.retention_days', 90),
        ];

        foreach (self::CATEGORIES as $category) {
            $defaults[$this->categoryKey($category)] = '1';
        }

        $stored = $this->settings->getMany($defaults);
        $categories = [];
        foreach (self::CATEGORIES as $category) {
            $categories[$category] = $this->toBool($stored[$this->categoryKey($category)] ?? '1');
        }

        $retentionDays = (int) ($stored[self::KEY_RETENTION_DAYS] ?? '90');
        if (! in_array($retentionDays, self::RETENTION_OPTIONS, true)) {
            $retentionDays = 90;
        }

        return [
            'categories' => $categories,
            'auto_cleanup' => $this->toBool($stored[self::KEY_AUTO_CLEANUP] ?? '1'),
            'retention_days' => $retentionDays,
        ];
    }

    /**
     * @param  array<string, bool>  $categories
     */
    public function update(array $categories, bool $autoCleanup, int $retentionDays): void
    {
        $values = [
            self::KEY_AUTO_CLEANUP => $autoCleanup ? '1' : '0',
            self::KEY_RETENTION_DAYS => (string) $this->normalizeRetentionDays($retentionDays),
        ];

        foreach (self::CATEGORIES as $category) {
            $values[$this->categoryKey($category)] = ($categories[$category] ?? false) ? '1' : '0';
        }

        $this->settings->setMany($values);
    }

    public function enabledFor(AdminNotificationData $data): bool
    {
        return $this->categoryEnabled($this->categoryFor($data));
    }

    public function categoryEnabled(string $category): bool
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            return true;
        }

        return $this->values()['categories'][$category] ?? true;
    }

    public function autoCleanupEnabled(): bool
    {
        return $this->values()['auto_cleanup'];
    }

    public function retentionDays(): int
    {
        return $this->values()['retention_days'];
    }

    /** @return list<int> */
    public function retentionOptions(): array
    {
        return self::RETENTION_OPTIONS;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return self::CATEGORIES;
    }

    private function categoryFor(AdminNotificationData $data): string
    {
        return match ($data->type) {
            AdminNotificationType::SupportTicketCreated,
            AdminNotificationType::SupportTicketPlayerReply => self::CATEGORY_SUPPORT,
            AdminNotificationType::ModuleUpdateAvailable,
            AdminNotificationType::ModuleMigrationPending => self::CATEGORY_MODULES,
            AdminNotificationType::CoreMigrationPending,
            AdminNotificationType::SystemUpdateFailed,
            AdminNotificationType::ImportantOperationSucceeded => self::CATEGORY_CMS_UPDATES,
            AdminNotificationType::QueueProblem => self::CATEGORY_BACKGROUND_TASKS,
            AdminNotificationType::ServerUnavailable => $data->routeName === 'admin.settings.login-server'
                ? self::CATEGORY_LOGIN_SERVER
                : self::CATEGORY_GAME_SERVER,
            AdminNotificationType::DiskSpaceLow => self::CATEGORY_DISK_SPACE,
            AdminNotificationType::InstallerPresent => self::CATEGORY_INSTALLER,
            AdminNotificationType::DiagnosticProblem => self::CATEGORY_DIAGNOSTICS,
        };
    }

    private function categoryKey(string $category): string
    {
        return 'admin_notifications.type.'.$category;
    }

    private function normalizeRetentionDays(int $retentionDays): int
    {
        return in_array($retentionDays, self::RETENTION_OPTIONS, true) ? $retentionDays : 90;
    }

    private function toBool(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
