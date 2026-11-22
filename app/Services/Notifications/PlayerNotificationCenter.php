<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * The player's bell. Modules add notifications through notify(); the account area
 * and module pages read them. A notification stores a translation key, a few scalar
 * parameters (escaped when shown) and an internal path, never HTML or outside links.
 */
final class PlayerNotificationCenter
{
    public const READ_RETENTION_DAYS = 60;

    public const UNREAD_RETENTION_DAYS = 180;

    private const MAX_PARAMETER_LENGTH = 120;

    /** @param  array<string, scalar|null>  $parameters */
    public function notify(
        User|int $user,
        string $type,
        string $titleKey,
        array $parameters,
        string $url,
        ?string $groupKey = null,
    ): UserNotification {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        if (! self::isInternalPath($url)) {
            throw new InvalidArgumentException('Player notifications link to internal paths only.');
        }
        $parameters = self::cleanParameters($parameters);

        return DB::transaction(static function () use ($userId, $type, $titleKey, $parameters, $url, $groupKey): UserNotification {
            $now = now();
            if ($groupKey !== null) {
                $existing = UserNotification::query()
                    ->where('user_id', $userId)
                    ->where('group_key', $groupKey)
                    ->whereNull('read_at')
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $existing->forceFill([
                        'title_key' => $titleKey,
                        'parameters' => $parameters,
                        'url' => $url,
                        'occurrences' => min(9999, $existing->occurrences + 1),
                        'occurred_at' => $now,
                    ])->save();

                    return $existing;
                }
            }

            return UserNotification::query()->create([
                'user_id' => $userId,
                'type' => mb_substr($type, 0, 80),
                'group_key' => $groupKey === null ? null : mb_substr($groupKey, 0, 191),
                'title_key' => $titleKey,
                'parameters' => $parameters,
                'url' => $url,
                'occurrences' => 1,
                'occurred_at' => $now,
            ]);
        });
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()->where('user_id', $user->getKey())->whereNull('read_at')->count();
    }

    /** @return Collection<int, UserNotification> */
    public function latest(User $user, int $limit = 15): Collection
    {
        return UserNotification::query()
            ->where('user_id', $user->getKey())
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Marks one of the user's notifications read and returns its internal path. */
    public function open(User $user, int $notificationId): ?string
    {
        $notification = UserNotification::query()
            ->where('user_id', $user->getKey())
            ->whereKey($notificationId)
            ->first();
        if ($notification === null) {
            return null;
        }
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return self::isInternalPath($notification->url) ? $notification->url : null;
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /** Text for the bell; merged notifications use the "_many" variant when it exists. */
    public function text(UserNotification $notification): string
    {
        $parameters = ($notification->parameters ?? []) + ['count' => $notification->occurrences];
        $key = $notification->title_key;
        if ($notification->occurrences > 1 && Lang::has($key.'_many')) {
            $key .= '_many';
        }

        return (string) __($key, $parameters);
    }

    public function cleanup(): int
    {
        return UserNotification::query()
            ->where(static fn ($query) => $query
                ->where(static fn ($read) => $read->whereNotNull('read_at')
                    ->where('read_at', '<', now()->subDays(self::READ_RETENTION_DAYS)))
                ->orWhere('occurred_at', '<', now()->subDays(self::UNREAD_RETENTION_DAYS)))
            ->delete();
    }

    public static function isInternalPath(string $url): bool
    {
        return $url !== '' && strlen($url) <= 500 && str_starts_with($url, '/')
            && ! str_starts_with($url, '//') && ! str_contains($url, '\\')
            && preg_match('/[\x00-\x1F\x7F\s]/', $url) !== 1;
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     * @return array<string, string|int>
     */
    private static function cleanParameters(array $parameters): array
    {
        $clean = [];
        foreach ($parameters as $name => $value) {
            if (! is_string($name) || preg_match('/\A[a-z_]{1,30}\z/', $name) !== 1 || $name === 'count') {
                continue;
            }
            $clean[$name] = is_int($value) ? $value : mb_substr(trim((string) $value), 0, self::MAX_PARAMETER_LENGTH);
        }

        return $clean;
    }
}
