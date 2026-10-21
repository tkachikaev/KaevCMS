<?php

namespace App\Services\Dashboard;

use App\Auth\AdminPermission;
use App\Models\Admin;

/**
 * Problems the dashboard asks the administrator to look at, most severe first. Only
 * problems are listed: an empty list means everything the viewer may see is fine.
 */
final class DashboardAttention
{
    /**
     * @param  array<string, mixed>  $monitor  ServerStatusOverview::get()
     * @param  array<string, mixed>|null  $playerOverview  DashboardPlayerOverview::forAdmin()
     * @param  array<string, mixed>|null  $overview  AdminDashboardOverview::forAdmin()
     * @param  array<string, mixed>  $mailDelivery  MailDeliveryMonitor::overview()
     * @param  array<string, mixed>|null  $runtime  RuntimeDiagnostics::overview()
     * @param  array<string, mixed>|null  $storage  StorageOverview::collect()
     * @return list<array{level:'danger'|'warning',title:string,url:string}>
     */
    public function collect(Admin $admin, array $monitor, ?array $playerOverview, ?array $overview, array $mailDelivery, ?array $runtime, ?array $storage): array
    {
        $items = [];

        if ($admin->hasPermission(AdminPermission::ServersView)) {
            foreach ($monitor['game_servers'] as $server) {
                if ($server['database_state'] === 'not_configured') {
                    $items[] = $this->item('danger', __('No connection to the :name database', ['name' => $server['name']]), route('admin.settings.game-server'));
                } elseif ($server['service_state'] === 'offline' && $server['state'] !== 'maintenance') {
                    $items[] = $this->item('danger', __(':name is not responding', ['name' => $server['name']]), route('admin.settings.game-server'));
                }
            }
            foreach ($monitor['login_servers'] as $server) {
                if ($server['service_state'] === 'offline' || $server['database_state'] === 'not_configured') {
                    $items[] = $this->item('danger', __(':name is not responding', ['name' => $server['name']]), route('admin.settings.login-server'));
                }
            }
        }

        $support = (int) ($playerOverview['support_attention'] ?? 0);
        if ($support > 0 && is_string($playerOverview['support_route'] ?? null)) {
            $items[] = $this->item('warning', trans_choice('{1} :count support request is waiting|[2,*] :count support requests are waiting', $support, ['count' => format_number($support)]), route($playerOverview['support_route']));
        }

        $rewards = (int) ($overview['rewards_attention'] ?? 0);
        if ($rewards > 0) {
            $items[] = $this->item('warning', trans_choice('{1} :count reward delivery needs a decision|[2,*] :count reward deliveries need a decision', $rewards, ['count' => format_number($rewards)]), route('admin.rewards.index'));
        }

        $donations = $overview['donations'] ?? null;
        if (is_array($donations) && $donations['review'] > 0) {
            $items[] = $this->item('warning', trans_choice('{1} :count payment needs review|[2,*] :count payments need review', $donations['review'], ['count' => format_number($donations['review'])]), route($donations['orders_route'] ?? $donations['route']));
        }

        if ($admin->hasPermission(AdminPermission::MailView)) {
            if ($mailDelivery['stale']) {
                $items[] = $this->item('danger', __('Emails are stuck in the queue'), route('admin.settings.mail'));
            } elseif ($mailDelivery['failed_recent'] > 0) {
                $items[] = $this->item('warning', trans_choice('{1} :count email failed in 7 days|[2,*] :count emails failed in 7 days', $mailDelivery['failed_recent'], ['count' => format_number($mailDelivery['failed_recent'])]), route('admin.settings.mail'));
            }
        }

        if ($runtime !== null && $admin->hasPermission(AdminPermission::SystemView)) {
            foreach ([__('Laravel scheduler') => $runtime['scheduler'], __('Queue processing') => $runtime['queue']] as $label => $status) {
                if ($status['state'] !== 'success') {
                    $items[] = $this->item($status['state'] === 'danger' ? 'danger' : 'warning', $label.': '.$status['status'], route('admin.settings.system.queue'));
                }
            }
            if ($runtime['jobs']['failed'] > 0) {
                $items[] = $this->item('warning', trans_choice('{1} :count background job failed|[2,*] :count background jobs failed', $runtime['jobs']['failed'], ['count' => format_number($runtime['jobs']['failed'])]), route('admin.settings.system.queue'));
            }
        }

        if ($storage !== null && $admin->hasPermission(AdminPermission::SystemView) && in_array($storage['disk']['state'], ['warning', 'danger'], true)) {
            $items[] = $this->item($storage['disk']['state'], __('Low disk space: :free free', ['free' => $storage['disk']['free']]), route('admin.settings.system'));
        }

        usort($items, static fn (array $left, array $right): int => ($left['level'] === 'danger' ? 0 : 1) <=> ($right['level'] === 'danger' ? 0 : 1));

        return $items;
    }

    /**
     * @param  'danger'|'warning'  $level
     * @return array{level:'danger'|'warning',title:string,url:string}
     */
    private function item(string $level, string $title, string $url): array
    {
        return ['level' => $level, 'title' => $title, 'url' => $url];
    }
}
