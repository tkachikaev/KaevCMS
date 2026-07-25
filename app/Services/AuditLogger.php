<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuditLogger
{
    private const REDACTED = '[REDACTED]';

    /** @var array<int, string> */
    private const SENSITIVE_PARTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'app_key',
        'api_key',
        'authorization',
        'cookie',
        'remember',
        'smtp_password',
        'database_password',
        'db_password',
        'private_key',
        'recovery_code',
        'authentication_code',
        'totp',
        'otp',
    ];

    /**
     * @param  array<string, mixed>  $details
     */
    public function success(
        string $category,
        string $action,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        array $details = [],
        ?string $actorType = null,
    ): ?AuditLog {
        return $this->write($category, $action, 'success', $actor, $target, $details, $actorType);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function failed(
        string $category,
        string $action,
        Model|string|null $actor = null,
        Model|string|null $target = null,
        array $details = [],
        ?string $actorType = null,
    ): ?AuditLog {
        return $this->write($category, $action, 'failed', $actor, $target, $details, $actorType);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function system(
        string $category,
        string $action,
        Model|string|null $target = null,
        array $details = [],
        string $result = 'success',
    ): ?AuditLog {
        return $this->write($category, $action, $result, __('System'), $target, $details, 'system');
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function write(
        string $category,
        string $action,
        string $result = 'success',
        Model|string|null $actor = null,
        Model|string|null $target = null,
        array $details = [],
        ?string $forcedActorType = null,
    ): ?AuditLog {
        try {
            [$actorType, $actorId, $actorName] = $this->actorData($actor, $forcedActorType);
            [$targetType, $targetId, $targetName] = $this->targetData($target);
            $request = app()->runningInConsole() ? null : request();

            return AuditLog::query()->create([
                'category' => Str::limit(Str::lower(trim($category)), 64, ''),
                'action' => Str::limit(Str::lower(trim($action)), 100, ''),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'target_name' => $targetName,
                'result' => $result === 'failed' ? 'failed' : 'success',
                'ip_address' => $request?->ip(),
                'user_agent' => $request !== null
                    ? Str::limit((string) $request->userAgent(), 512, '')
                    : null,
                'details' => $details === [] ? null : $this->sanitize($details),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unable to write CMS audit log.', [
                'category' => $category,
                'action' => $action,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} */
    private function actorData(Model|string|null $actor, ?string $forcedType): array
    {
        if ($actor === null) {
            $actor = Auth::guard('admin')->user() ?? Auth::user();
        }

        if (is_string($actor)) {
            return [$forcedType ?? 'system', null, Str::limit($actor, 190, '')];
        }

        if ($actor instanceof Admin) {
            return ['admin', (string) $actor->getKey(), $this->modelName($actor)];
        }

        if ($actor instanceof User) {
            return ['user', (string) $actor->getKey(), $this->modelName($actor)];
        }

        if ($actor instanceof Model) {
            return [Str::snake(class_basename($actor)), (string) $actor->getKey(), $this->modelName($actor)];
        }

        return [$forcedType, null, null];
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} */
    private function targetData(Model|string|null $target): array
    {
        if (is_string($target)) {
            return [null, null, Str::limit($target, 255, '')];
        }

        if (! $target instanceof Model) {
            return [null, null, null];
        }

        return [
            Str::snake(class_basename($target)),
            (string) $target->getKey(),
            Str::limit($this->modelName($target), 255, ''),
        ];
    }

    private function modelName(Model $model): string
    {
        foreach (['title', 'name', 'email', 'slug', 'game_login'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return Str::limit(trim((string) $value), 190, '');
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    private function isSensitiveKey(string $key, mixed $value): bool
    {
        $normalized = Str::lower($key);

        if (is_bool($value) && Str::endsWith($normalized, [
            '_changed',
            '_saved',
            '_configured',
            '_present',
            '_valid',
        ])) {
            return false;
        }

        foreach (self::SENSITIVE_PARTS as $part) {
            if (str_contains($normalized, $part)) {
                return true;
            }
        }

        return false;
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6) {
            return __('Structure depth limit reached');
        }

        if (is_array($value)) {
            $result = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if ($count >= 100) {
                    $result['__truncated'] = __('Remaining items were not stored.');
                    break;
                }

                $stringKey = (string) $key;
                $result[$key] = $this->isSensitiveKey($stringKey, $item)
                    ? self::REDACTED
                    : $this->sanitize($item, $depth + 1);
                $count++;
            }

            return $result;
        }

        if (is_object($value)) {
            if ($value instanceof Model) {
                return [
                    'type' => Str::snake(class_basename($value)),
                    'id' => (string) $value->getKey(),
                    'name' => $this->modelName($value),
                ];
            }

            return $this->sanitize((array) $value, $depth + 1);
        }

        if (is_string($value)) {
            return Str::limit($value, 2000, '…');
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }
}
