<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\PlayerNotificationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PlayerNotificationController extends Controller
{
    public function __construct(private readonly PlayerNotificationCenter $notifications) {}

    public function count(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->notifications->unreadCount($this->user($request))])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function open(Request $request, int $notification): RedirectResponse
    {
        $url = $this->notifications->open($this->user($request), $notification);

        return $url === null ? redirect()->back() : redirect()->to($url);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($this->user($request));

        return redirect()->back();
    }

    private function user(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
