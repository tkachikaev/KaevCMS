<?php

namespace App\Support\Rewards;

enum RewardQueueDiagnostic: string
{
    case NotInstalled = 'reward_queue_not_installed';
    case SchemaInvalid = 'reward_queue_schema_invalid';
    case Unavailable = 'reward_queue_unavailable';
    case EmptyPayload = 'empty_reward_queue_payload';
    case PayloadConflict = 'reward_queue_payload_conflict';
    case WriteFailed = 'reward_queue_write_failed';
    case WriteUnknown = 'reward_queue_write_unknown';
    case LegacyRequiresReview = 'legacy_bridge_operation_requires_review';

    public function messageKey(): string
    {
        return 'rewards.queue.diagnostics.'.$this->value.'.message';
    }

    public function actionKey(): string
    {
        return 'rewards.queue.diagnostics.'.$this->value.'.action';
    }

    public static function messageFor(?string $code): string
    {
        $diagnostic = self::tryFrom(trim((string) $code));

        return $diagnostic instanceof self
            ? __($diagnostic->messageKey())
            : __('rewards.queue.diagnostics.unknown.message');
    }

    public static function actionFor(?string $code): string
    {
        $diagnostic = self::tryFrom(trim((string) $code));

        return $diagnostic instanceof self
            ? __($diagnostic->actionKey())
            : __('rewards.queue.diagnostics.unknown.action');
    }
}
