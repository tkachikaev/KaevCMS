<?php

namespace KaevCMS\Modules\DailyRewards\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;

final class AdminDailyRewardClaimController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = DailyRewardClaim::query()
            ->with(['calendar.gameServer.translations', 'day', 'user', 'gameAccount', 'rewardGrant.items'])
            ->latest('claimed_at')
            ->latest('id');

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(static function ($builder) use ($search): void {
                $builder->where('user_email', 'like', '%'.$search.'%')
                    ->orWhere('game_account_login', 'like', '%'.$search.'%');
            });
        }

        return view('module-daily-rewards::admin.claims', [
            'claims' => $query->paginate(30)->withQueryString(),
            'search' => $search,
        ]);
    }
}
