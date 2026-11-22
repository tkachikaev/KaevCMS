<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
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

    /** @var array<string, Closure(User, UserNotification): bool> */
    private array $visibilityPolicies = [];

    /**
     * Optional module-specific visibility check. An existing notification may stop
     * being visible when its target topic or section becomes private or is deleted.
     *
     * @param  Closure(User, UserNotification): bool  $policy
     */
    public function registerVisibilityPolicy(string $typePrefix, Closure $policy): void
    {
        $this->visibilityPolicies[$typePrefix] = $policy;
    }

    private function canSee(User $user, UserNotification $notification): bool
    {
        foreach ($this->visibilityPolicies as $prefix => $policy) {
            if (str_starts_with($notification->type, $prefix)) {
                return (bool) $policy($user, $notification);
            }
        }

        return true;
    }

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
        // Normalize before looking up an existing group as well as inserting it.
        $groupKey = $groupKey === null ? null : mb_substr($groupKey, 0, 191);

        return DB::transaction(static function () use ($userId, $type, $titleKey, $parameters, $url, $groupKey): UserNotification {
            $now = now();
            if ($groupKey !== null) {
                // Serialize a recipient's grouped notifications even when there is
                // no existing unread row to lock. Otherwise concurrent first replies
                // can both insert a notification for the same topic.
                User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
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
                'group_key' => $groupKey,
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
        $query = UserNotification::query()->where('user_id', $user->getKey())->whereNull('read_at');
        if ($this->visibilityPolicies === []) {
            return $query->count();
        }

        $count = 0;
        foreach ($query->orderBy('id')->lazy(100) as $notification) {
            if ($this->canSee($user, $notification)) {
                $count++;
            }
        }

        return $count;
    }

    /** @return Collection<int, UserNotification> */
    public function latest(User $user, int $limit = 15): Collection
    {
        $query = UserNotification::query()
            ->where('user_id', $user->getKey())
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($this->visibilityPolicies === []) {
            return $query->limit($limit)->get();
        }

        $visible = new Collection;
        foreach ($query->lazy(100) as $notification) {
            if ($this->canSee($user, $notification)) {
                $visible->push($notification);
                if ($visible->count() >= $limit) {
                    break;
                }
            }
        }

        return $visible;
    }

    /** Marks one of the user's notifications read and returns its internal path. */
    public function open(User $user, int $notificationId): ?string
    {
        $notification = UserNotification::query()
            ->where('user_id', $user->getKey())
            ->whereKey($notificationId)
            ->first();
        if ($notification === null || ! $this->canSee($user, $notification)) {
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

    /**
     * The same text with chosen parameters wrapped for highlighting. Everything is
     * escaped first; only our own <span> wrappers are added afterwards.
     *
     * @param  list<string>  $highlight
     */
    public function html(UserNotification $notification, array $highlight = ['name', 'topic']): HtmlString
    {
        $parameters = ($notification->parameters ?? []) + ['count' => $notification->occurrences];
        $key = $notification->title_key;
        if ($notification->occurrences > 1 && Lang::has($key.'_many')) {
            $key .= '_many';
        }

        $markers = [];
        foreach ($highlight as $index => $name) {
            if (isset($parameters[$name]) && is_string($parameters[$name])) {
                $marker = "\u{E000}{$index}\u{E001}";
                $markers[$marker] = '<span class="notification-'.$name.'">'.e($parameters[$name]).'</span>';
                $parameters[$name] = $marker;
            }
        }

        return new HtmlString(strtr(e((string) __($key, $parameters)), $markers));
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
