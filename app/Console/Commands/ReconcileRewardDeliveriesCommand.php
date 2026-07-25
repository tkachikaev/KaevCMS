<?php

namespace App\Console\Commands;

use App\Models\RewardDelivery;
use App\Services\Rewards\RewardDeliveryReconciler;
use Illuminate\Console\Command;

final class ReconcileRewardDeliveriesCommand extends Command
{
    protected $signature = 'kaevcms:rewards-reconcile
        {--limit=50 : Maximum number of operations to inspect}
        {--older-than=300 : Minimum operation age in seconds}';

    protected $description = 'Safely verify stale pending GameServer reward queue transfers';

    public function handle(RewardDeliveryReconciler $reconciler): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 500);
        $olderThan = min(max((int) $this->option('older-than'), 30), 86400);

        $deliveries = RewardDelivery::query()
            ->where('status', RewardDelivery::STATUS_PENDING)
            ->where('updated_at', '<=', now()->subSeconds($olderThan))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $counts = [
            RewardDelivery::STATUS_QUEUED => 0,
            RewardDelivery::STATUS_FAILED => 0,
            RewardDelivery::STATUS_REVIEW => 0,
        ];

        foreach ($deliveries as $delivery) {
            $status = $reconciler->reconcile($delivery)->status;
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        $this->info(__('Reward operations checked: :count.', ['count' => $deliveries->count()]));
        $this->line(__('Transferred to queue: :count.', ['count' => $counts[RewardDelivery::STATUS_QUEUED]]));
        $this->line(__('Returned to inventory: :count.', ['count' => $counts[RewardDelivery::STATUS_FAILED]]));
        $this->line(__('Still needs review: :count.', ['count' => $counts[RewardDelivery::STATUS_REVIEW]]));

        return self::SUCCESS;
    }
}
