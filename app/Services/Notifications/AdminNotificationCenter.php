<?php

namespace App\Services\Notifications;

use App\Auth\AdminPermission;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Support\Notifications\AdminNotificationData;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AdminNotificationCenter
{
    public function __construct(private readonly AdminNotificationSettings $settings) {}

    public function available(): bool
    {
        try {
            return Schema::hasTable('admin_notifications');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  (Closure(Admin): bool)|null  $recipientFilter
     */
    public function notifyOnce(
        AdminNotificationData $data,
        string $externalKey,
        ?AdminPermission $permission = null,
        ?Closure $recipientFilter = null,
    ): int {
        if (! $this->available() || ! $this->settings->enabledFor($data)) {
            return 0;
        }

        try {
            $payload = $this->payload($data);
            $externalKey = $this->key($externalKey, 'external key');
            $recipients = $this->recipients($permission, $recipientFilter);
        } catch (Throwable) {
            return 0;
        }

        $created = 0;
        foreach ($recipients as $admin) {
            try {
                $notification = AdminNotification::query()->firstOrCreate(
                    [
                        'admin_id' => $admin->id,
                        'external_key' => $externalKey,
                    ],
                    [
                        ...$payload,
                        'occurred_at' => now(),
                        'last_occurred_at' => now(),
                    ],
                );

                if ($notification->wasRecentlyCreated) {
                    $created++;
                }
            } catch (QueryException) {
                // A concurrent delivery with the same external key already won.
            } catch (Throwable) {
                // Notifications must never break the source operation.
            }
        }

        return $created;
    }

    /**
     * @param  (Closure(Admin): bool)|null  $recipientFilter
     */
    public function openProblem(
        AdminNotificationData $data,
        string $deduplicationKey,
        ?AdminPermission $permission = null,
        ?Closure $recipientFilter = null,
    ): int {
        if (! $this->available()) {
            return 0;
        }

        try {
            $deduplicationKey = $this->key($deduplicationKey, 'deduplication key');
            if (! $this->settings->enabledFor($data)) {
                $this->resolveProblem($deduplicationKey);

                return 0;
            }

            $payload = $this->payload($data);
            $recipients = $this->recipients($permission, $recipientFilter);
        } catch (Throwable) {
            return 0;
        }

        $activeExternalKey = 'problem:'.hash('sha256', $deduplicationKey);
        $created = 0;
        foreach ($recipients as $admin) {
            try {
                $wasCreated = DB::transaction(function () use ($admin, $deduplicationKey, $activeExternalKey, $payload): bool {
                    $active = AdminNotification::query()
                        ->where('admin_id', $admin->id)
                        ->where('deduplication_key', $deduplicationKey)
                        ->whereNull('resolved_at')
                        ->lockForUpdate()
                        ->latest('id')
                        ->first();

                    if ($active instanceof AdminNotification) {
                        $active->forceFill([
                            ...$payload,
                            'last_occurred_at' => now(),
                            'occurrences' => min(4_294_967_295, $active->occurrences + 1),
                        ])->save();

                        return false;
                    }

                    AdminNotification::query()->create([
                        ...$payload,
                        'admin_id' => $admin->id,
                        'external_key' => $activeExternalKey,
                        'deduplication_key' => $deduplicationKey,
                        'occurred_at' => now(),
                        'last_occurred_at' => now(),
                    ]);

                    return true;
                }, 3);

                if ($wasCreated) {
                    $created++;
                }
            } catch (Throwable) {
                // Notifications must never break the source operation.
            }
        }

        return $created;
    }

    public function resolveProblem(string $deduplicationKey): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return AdminNotification::query()
                ->where('deduplication_key', $this->key($deduplicationKey, 'deduplication key'))
                ->whereNull('resolved_at')
                ->update([
                    'external_key' => null,
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return Builder<AdminNotification> */
    public function inboxQuery(Admin $admin): Builder
    {
        return AdminNotification::query()
            ->where('admin_id', $admin->id)
            ->visible();
    }

    public function unreadCount(Admin $admin): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return $this->inboxQuery($admin)->unread()->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function visibleCount(Admin $admin): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return $this->inboxQuery($admin)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function readCount(Admin $admin): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return $this->inboxQuery($admin)->whereNotNull('read_at')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function markRead(Admin $admin, int $notificationId): ?AdminNotification
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $notification = $this->inboxQuery($admin)->find($notificationId);
            if (! $notification instanceof AdminNotification) {
                return null;
            }

            if ($notification->read_at === null) {
                $notification->forceFill(['read_at' => now()])->save();
            }

            return $notification;
        } catch (Throwable) {
            return null;
        }
    }

    public function markAllRead(Admin $admin): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return $this->inboxQuery($admin)
                ->unread()
                ->update([
                    'read_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            return 0;
        }
    }

    public function dismissRead(Admin $admin): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return $this->inboxQuery($admin)
                ->whereNotNull('read_at')
                ->update([
                    'dismissed_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            return 0;
        }
    }

    public function dismissAll(Admin $admin): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return $this->inboxQuery($admin)->update([
                'dismissed_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            return 0;
        }
    }

    public function cleanupExpired(?int $retentionDays = null): int
    {
        if (! $this->available()) {
            return 0;
        }

        if ($retentionDays === null && ! $this->settings->autoCleanupEnabled()) {
            return 0;
        }

        $days = max(7, min(3650, $retentionDays ?? $this->settings->retentionDays()));

        try {
            return AdminNotification::query()
                ->where('last_occurred_at', '<', now()->subDays($days))
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  (Closure(Admin): bool)|null  $recipientFilter
     * @return Collection<int, Admin>
     */
    private function recipients(?AdminPermission $permission, ?Closure $recipientFilter): Collection
    {
        return Admin::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(static function (Admin $admin) use ($permission, $recipientFilter): bool {
                if ($permission instanceof AdminPermission && ! $admin->hasPermission($permission)) {
                    return false;
                }

                return $recipientFilter === null || $recipientFilter($admin) === true;
            })
            ->values();
    }

    /** @return array<string, mixed> */
    private function payload(AdminNotificationData $data): array
    {
        $titleKey = trim($data->titleKey);
        $messageKey = is_string($data->messageKey) ? trim($data->messageKey) : null;
        $routeName = is_string($data->routeName) ? trim($data->routeName) : null;

        if ($titleKey === '' || mb_strlen($titleKey) > 191) {
            throw new InvalidArgumentException('The notification title key is invalid.');
        }

        if ($messageKey === '') {
            $messageKey = null;
        }
        if ($messageKey !== null && mb_strlen($messageKey) > 191) {
            throw new InvalidArgumentException('The notification message key is invalid.');
        }

        if ($routeName === '') {
            $routeName = null;
        }
        if ($routeName !== null && (mb_strlen($routeName) > 191 || ! $data->type->allowsRoute($routeName))) {
            throw new InvalidArgumentException('The notification route is not allowed for this type.');
        }

        return [
            'type' => $data->type,
            'severity' => $data->severity,
            'title_key' => $titleKey,
            'message_key' => $messageKey,
            'parameters' => $this->parameters($data->parameters),
            'route_name' => $routeName,
            'route_parameters' => $this->routeParameters($data->routeParameters),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $parameters
     * @return array<string, bool|float|int|string|null>
     */
    private function parameters(array $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || $key === '' || mb_strlen($key) > 64) {
                continue;
            }

            if (is_string($value)) {
                $value = Str::limit(trim($value), 190, '');
            }

            if (is_scalar($value) || $value === null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<mixed, mixed>  $parameters
     * @return array<string, bool|int|string>
     */
    private function routeParameters(array $parameters): array
    {
        $normalized = [];
        foreach ($parameters as $key => $value) {
            if (! is_string($key) || $key === '' || mb_strlen($key) > 64) {
                continue;
            }

            if (is_string($value)) {
                $value = Str::limit(trim($value), 190, '');
            }

            if (is_bool($value) || is_int($value) || is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function key(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException("The notification {$label} is empty.");
        }

        return mb_strlen($value) <= 191 ? $value : hash('sha256', $value);
    }
}
