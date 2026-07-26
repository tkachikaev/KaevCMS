<?php

namespace KaevCMS\Modules\DailyRewards\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameAssets\GameAssetUrlResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use KaevCMS\Modules\DailyRewards\Exceptions\DailyRewardClaimException;
use KaevCMS\Modules\DailyRewards\Http\Requests\ClaimDailyRewardRequest;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;
use KaevCMS\Modules\DailyRewards\Services\DailyRewardClaimService;

final class DailyRewardController extends Controller
{
    public function __construct(private readonly GameAssetUrlResolver $assets) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $calendars = DailyRewardCalendar::query()
            ->with(['gameServer.translations', 'days.items'])
            ->where('enabled', true)
            ->where('year', $now->year)
            ->where('month', $now->month)
            ->orderBy('game_server_id')
            ->get();

        $requestedCalendarId = (int) $request->query('calendar', 0);
        $calendar = $calendars->firstWhere('id', $requestedCalendarId) ?? $calendars->first();

        $accounts = collect();
        $selectedAccount = null;
        $claimsByDay = collect();
        $dayStates = [];
        $iconUrls = [];
        $today = null;
        $weekdayOffset = 0;

        if ($calendar instanceof DailyRewardCalendar) {
            $accounts = $this->accountsFor($user, $calendar);
            $requestedAccountId = (int) $request->query('account', 0);
            $selectedAccount = $accounts->firstWhere('id', $requestedAccountId) ?? $accounts->first();

            $today = CarbonImmutable::now($calendar->runtimeTimezone())->startOfDay();
            $monthStart = CarbonImmutable::create(
                $calendar->year,
                $calendar->month,
                1,
                0,
                0,
                0,
                $calendar->runtimeTimezone(),
            );
            $weekdayOffset = $monthStart->isoWeekday() - 1;

            if ($selectedAccount instanceof UserGameAccount) {
                $claimsByDay = DailyRewardClaim::query()
                    ->where('calendar_id', $calendar->id)
                    ->where('user_game_account_id', $selectedAccount->id)
                    ->get()
                    ->keyBy('day_id');
            }

            foreach ($calendar->days as $day) {
                $date = $monthStart->setDay($day->day_number);
                $claim = $claimsByDay->get($day->id);
                $status = match (true) {
                    $claim instanceof DailyRewardClaim => 'claimed',
                    ! $day->enabled || $day->items->isEmpty() => 'disabled',
                    $date->isBefore($today) => 'missed',
                    $date->isAfter($today) => 'future',
                    default => 'available',
                };

                $dayStates[$day->id] = [
                    'date' => $date,
                    'status' => $status,
                    'claim' => $claim,
                ];

                foreach ($day->items as $item) {
                    $iconUrls[$day->id][$item->item_id] = $this->assets->itemIcon(
                        $calendar->gameServer,
                        $item->item_id,
                    );
                }
            }
        }

        $recentClaims = DailyRewardClaim::query()
            ->with(['calendar.gameServer.translations', 'day', 'rewardGrant.items'])
            ->where('user_id', $user->id)
            ->latest('claimed_at')
            ->limit(10)
            ->get();

        return view('module-daily-rewards::account.index', [
            'user' => $user,
            'calendars' => $calendars,
            'calendar' => $calendar,
            'accounts' => $accounts,
            'selectedAccount' => $selectedAccount,
            'dayStates' => $dayStates,
            'iconUrls' => $iconUrls,
            'recentClaims' => $recentClaims,
            'requestToken' => (string) Str::uuid(),
            'weekdayOffset' => $weekdayOffset,
        ]);
    }

    public function claim(
        ClaimDailyRewardRequest $request,
        DailyRewardClaimService $claimService,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $calendarId = (int) $request->validated('calendar_id');
        $accountId = (int) $request->validated('user_game_account_id');

        try {
            $claim = $claimService->claim(
                user: $user,
                calendarId: $calendarId,
                gameAccountId: $accountId,
                requestToken: (string) $request->validated('request_token'),
            );
        } catch (DailyRewardClaimException $exception) {
            $message = $this->errorMessage($exception->reasonCode);

            return redirect()
                ->route('modules.daily-rewards.index', [
                    'calendar' => $calendarId,
                    'account' => $accountId,
                ])
                ->withErrors(['reward' => $message])
                ->with('account_operation', [
                    'type' => 'error',
                    'eyebrow' => __('module-daily-rewards::messages.account_title'),
                    'title' => __('module-daily-rewards::messages.claim_failed_title'),
                    'message' => $message,
                ]);
        }

        $items = [];
        foreach ($claim->rewardGrant?->items ?? [] as $reward) {
            $items[] = [
                'item_id' => $reward->item_id,
                'name' => $reward->displayName($claim->game_server_id),
                'amount' => $reward->amount,
                'icon_url' => $this->assets->itemIcon($claim->calendar->gameServer, $reward->item_id),
            ];
        }

        $message = __('module-daily-rewards::messages.claim_success');

        return redirect()
            ->route('modules.daily-rewards.index', [
                'calendar' => $claim->calendar_id,
                'account' => $claim->user_game_account_id,
            ])
            ->with('status', $message)
            ->with('account_operation', [
                'type' => 'success',
                'eyebrow' => __('module-daily-rewards::messages.account_title'),
                'title' => __('module-daily-rewards::messages.claim_success_title'),
                'message' => $message,
                'items' => $items,
                'action_url' => public_route('web-inventory.index', ['server' => $claim->game_server_id]),
                'action_label' => __('module-daily-rewards::messages.open_inventory'),
            ]);
    }

    /** @return Collection<int, UserGameAccount> */
    private function accountsFor(User $user, DailyRewardCalendar $calendar): Collection
    {
        $loginServerId = $calendar->gameServer->login_server_id;
        if ($loginServerId === null) {
            return collect();
        }

        return $user->availableGameAccounts()
            ->with(['loginServer', 'registrationGameServer.translations'])
            ->where('login_server_id', $loginServerId)
            ->orderBy('game_login')
            ->orderBy('id')
            ->get();
    }

    private function errorMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'calendar_unavailable' => __('module-daily-rewards::messages.claim_calendar_unavailable'),
            'day_unavailable' => __('module-daily-rewards::messages.claim_day_unavailable'),
            'account_unavailable' => __('module-daily-rewards::messages.claim_account_unavailable'),
            'already_claimed' => __('module-daily-rewards::messages.claim_already_claimed'),
            'no_rewards' => __('module-daily-rewards::messages.claim_no_rewards'),
            default => __('module-daily-rewards::messages.claim_invalid'),
        };
    }
}
