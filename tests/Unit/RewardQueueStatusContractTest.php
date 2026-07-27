<?php

namespace Tests\Unit;

use App\Models\RewardDelivery;
use App\Support\Rewards\RewardQueueDiagnostic;
use App\Support\Rewards\RewardQueueRowStatus;
use Tests\TestCase;

class RewardQueueStatusContractTest extends TestCase
{
    public function test_cms_reward_delivery_transitions_match_the_runtime_contract(): void
    {
        $this->assertSame([
            RewardDelivery::STATUS_QUEUED,
            RewardDelivery::STATUS_FAILED,
            RewardDelivery::STATUS_REVIEW,
        ], RewardDelivery::ALLOWED_TRANSITIONS[RewardDelivery::STATUS_PENDING]);
        $this->assertSame([
            RewardDelivery::STATUS_QUEUED,
            RewardDelivery::STATUS_FAILED,
            RewardDelivery::STATUS_REVIEW,
        ], RewardDelivery::ALLOWED_TRANSITIONS[RewardDelivery::STATUS_REVIEW]);
        $this->assertSame([], RewardDelivery::ALLOWED_TRANSITIONS[RewardDelivery::STATUS_QUEUED]);
        $this->assertSame([], RewardDelivery::ALLOWED_TRANSITIONS[RewardDelivery::STATUS_FAILED]);
    }

    public function test_gameserver_consumer_statuses_have_explicit_terminal_transitions(): void
    {
        $this->assertTrue(RewardQueueRowStatus::Pending->canTransitionTo(RewardQueueRowStatus::Processing));
        $this->assertTrue(RewardQueueRowStatus::Pending->canTransitionTo(RewardQueueRowStatus::Delivered));
        $this->assertTrue(RewardQueueRowStatus::Pending->canTransitionTo(RewardQueueRowStatus::Failed));
        $this->assertTrue(RewardQueueRowStatus::Processing->canTransitionTo(RewardQueueRowStatus::Delivered));
        $this->assertTrue(RewardQueueRowStatus::Processing->canTransitionTo(RewardQueueRowStatus::Failed));
        $this->assertFalse(RewardQueueRowStatus::Delivered->canTransitionTo(RewardQueueRowStatus::Pending));
        $this->assertFalse(RewardQueueRowStatus::Failed->canTransitionTo(RewardQueueRowStatus::Pending));
    }

    public function test_every_reward_queue_diagnostic_has_ru_and_en_message_and_action(): void
    {
        $english = require base_path('lang/en/rewards.php');
        $russian = require base_path('lang/ru/rewards.php');

        foreach (RewardQueueDiagnostic::cases() as $diagnostic) {
            $this->assertNotEmpty(data_get($english, 'queue.diagnostics.'.$diagnostic->value.'.message'));
            $this->assertNotEmpty(data_get($english, 'queue.diagnostics.'.$diagnostic->value.'.action'));
            $this->assertNotEmpty(data_get($russian, 'queue.diagnostics.'.$diagnostic->value.'.message'));
            $this->assertNotEmpty(data_get($russian, 'queue.diagnostics.'.$diagnostic->value.'.action'));
        }
    }
}
