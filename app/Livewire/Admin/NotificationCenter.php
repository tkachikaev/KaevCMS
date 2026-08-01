<?php

namespace App\Livewire\Admin;

use App\Models\Admin;
use App\Models\AdminNotification;
use App\Services\Notifications\AdminNotificationCenter as NotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

final class NotificationCenter extends Component
{
    public string $filter = 'all';

    public ?string $notice = null;

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';
    }

    public function refreshCenter(): void
    {
        // The render pass refreshes counts and the compact list.
    }

    public function openNotification(int $notificationId, NotificationService $notifications): mixed
    {
        $notification = $notifications->markRead($this->admin(), $notificationId);
        if (! $notification instanceof AdminNotification) {
            return null;
        }

        $url = $notification->actionUrl();
        if ($url === null) {
            $this->notice = __('The target page is no longer available.');

            return null;
        }

        return $this->redirect($url, navigate: true);
    }

    public function markAllRead(NotificationService $notifications): void
    {
        $notifications->markAllRead($this->admin());
        $this->notice = __('All notifications were marked as read.');
    }

    public function clearRead(NotificationService $notifications): void
    {
        $notifications->dismissRead($this->admin());
        $this->notice = __('Read notifications were cleared.');
    }

    public function clearAll(NotificationService $notifications): void
    {
        $notifications->dismissAll($this->admin());
        $this->notice = __('All notifications were cleared.');
    }

    public function render(NotificationService $notifications): View
    {
        $admin = $this->admin();
        $unreadCount = $notifications->unreadCount($admin);
        $totalCount = $notifications->visibleCount($admin);
        $readCount = $notifications->readCount($admin);
        $items = new Collection;

        if ($notifications->available()) {
            $query = $notifications->inboxQuery($admin)
                ->orderByDesc('last_occurred_at')
                ->orderByDesc('id');

            if ($this->filter === 'unread') {
                $query->unread();
            }

            /** @var Collection<int, AdminNotification> $items */
            $items = $query->limit(20)->get();
        }

        return view('livewire.admin.notification-center', [
            'items' => $items,
            'unreadCount' => $unreadCount,
            'unreadLabel' => $unreadCount > 99 ? '99+' : (string) $unreadCount,
            'totalCount' => $totalCount,
            'readCount' => $readCount,
        ]);
    }

    private function admin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin && $admin->is_active, 401);

        return $admin;
    }
}
