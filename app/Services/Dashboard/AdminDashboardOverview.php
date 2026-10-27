<?php

namespace App\Services\Dashboard;

use App\Auth\AdminPermission;
use App\Models\Admin;
use App\Models\RewardDelivery;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\Servers\ServerOnlineHistory;
use App\Support\Database\TableExistence;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Figures for the administration dashboard that are not part of the server monitor,
 * mail or runtime diagnostics: growth of players, recent registrations and donations.
 * Every block is gated by the viewer's permissions; a missing block is null.
 */
final class AdminDashboardOverview
{
    public const SERIES_DAYS = 30;

    /** Five-minute buckets for the short chart periods: the last 5 hours. */
    public const RECENT_BUCKETS = 60;

    public function __construct(
        private readonly ModuleNavigationRegistry $moduleNavigation,
        private readonly ModuleAdminAccessRegistry $moduleAccess,
        private readonly ServerOnlineHistory $onlineHistory,
        private readonly TableExistence $tables,
    ) {}

    /**
     * @return array{
     *     master_accounts: array{total:int,new_today:int,verified_percent:float|null}|null,
     *     game_accounts: array{total:int,new_today:int,per_player:float|null}|null,
     *     registrations: array{days:list<array{date:CarbonImmutable,count:int,game_accounts:int}>,hours:list<array{masters:int,games:int}>,minutes:list<array{masters:int,games:int}>,total:int,last_7_days:int,game_accounts_total:int,max:int}|null,
     *     latest_master_accounts: list<array{id:int,name:string,email:string,verified:bool,game_accounts:int,created_at:CarbonImmutable}>|null,
     *     online: array{servers:list<array{id:int,name:string}>,total:array{days:list<int|null>,hours:list<int|null>,minutes:list<int|null>},per_server:array<int,array{days:list<int|null>,hours:list<int|null>,minutes:list<int|null>}>,peak:int|null}|null,
     *     donations: array{totals:list<array{currency:string,amount:float,previous:float}>,payments:int,average:float|null,currency:string|null,days:list<array{date:CarbonImmutable,amount:float}>,hours:list<float>,minutes:list<float>,max:float,latest:list<array{player:string,amount:float,currency:string,credited_at:CarbonImmutable,order_uuid:string}>,route:string,orders_route:string|null,review:int}|null,
     *     rewards_attention: int|null
     * }
     */
    public function forAdmin(Admin $admin): array
    {
        $canViewUsers = $admin->hasPermission(AdminPermission::UsersView);

        return [
            'master_accounts' => $canViewUsers ? $this->masterAccounts() : null,
            'game_accounts' => $canViewUsers ? $this->gameAccounts() : null,
            'registrations' => $canViewUsers ? $this->registrations() : null,
            'latest_master_accounts' => $canViewUsers ? $this->latestMasterAccounts() : null,
            'online' => $admin->hasPermission(AdminPermission::ServersView)
                ? $this->onlineHistory->chart(self::SERIES_DAYS, 24, self::RECENT_BUCKETS)
                : null,
            'donations' => $this->donations($admin),
            'rewards_attention' => $admin->hasPermission(AdminPermission::RewardsView)
                ? RewardDelivery::query()->whereIn('status', [RewardDelivery::STATUS_FAILED, RewardDelivery::STATUS_REVIEW])->count()
                : null,
        ];
    }

    /** @return array{total:int,new_today:int,verified_percent:float|null} */
    private function masterAccounts(): array
    {
        $total = User::query()->count();
        $verified = User::query()->whereNotNull('email_verified_at')->count();

        return [
            'total' => $total,
            'new_today' => User::query()->where('created_at', '>=', now()->subDay())->count(),
            'verified_percent' => $total > 0 ? $verified / $total * 100 : null,
        ];
    }

    /** @return array{total:int,new_today:int,per_player:float|null} */
    private function gameAccounts(): array
    {
        $active = UserGameAccount::query()->where('creation_status', UserGameAccount::STATUS_ACTIVE);
        $total = (clone $active)->count();
        $owners = (clone $active)->distinct()->count('user_id');

        return [
            'total' => $total,
            'new_today' => (clone $active)->where('created_at', '>=', now()->subDay())->count(),
            'per_player' => $owners > 0 ? $total / $owners : null,
        ];
    }

    /**
     * New master accounts per day for the last 30 days, with the game accounts created
     * on the same days, so the step from site registration to the game is visible.
     *
     * @return array{days:list<array{date:CarbonImmutable,count:int,game_accounts:int}>,hours:list<array{masters:int,games:int}>,minutes:list<array{masters:int,games:int}>,total:int,last_7_days:int,game_accounts_total:int,max:int}
     */
    private function registrations(): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $start = $today->subDays(self::SERIES_DAYS - 1);

        // Raw "Y-m-d H:i:s" strings: a busy month has tens of thousands of registrations,
        // and building a date object for each of them made the dashboard slow.
        $masterTimes = User::query()->toBase()->where('created_at', '>=', $start)->pluck('created_at')->all();
        $gameTimes = UserGameAccount::query()
            ->toBase()
            ->where('creation_status', UserGameAccount::STATUS_ACTIVE)
            ->where('created_at', '>=', $start)
            ->pluck('created_at')
            ->all();
        $masters = $this->countByDay($masterTimes);
        $games = $this->countByDay($gameTimes);
        $masterHours = $this->countByHour($masterTimes);
        $gameHours = $this->countByHour($gameTimes);
        $hours = [];
        $currentHour = CarbonImmutable::now()->startOfHour();
        for ($hour = $currentHour->subHours(23); $hour->lte($currentHour); $hour = $hour->addHour()) {
            $key = $hour->format('Y-m-d H');
            $hours[] = ['masters' => $masterHours[$key] ?? 0, 'games' => $gameHours[$key] ?? 0];
        }
        $masterMinutes = $this->countByFiveMinutes($masterTimes);
        $gameMinutes = $this->countByFiveMinutes($gameTimes);
        $minutes = [];
        foreach (ServerOnlineHistory::recentBuckets(self::RECENT_BUCKETS) as $bucket) {
            $key = $bucket->format('Y-m-d H:i');
            $minutes[] = ['masters' => $masterMinutes[$key] ?? 0, 'games' => $gameMinutes[$key] ?? 0];
        }

        $days = [];
        for ($day = $start; $day->lte($today); $day = $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $day, 'count' => $masters[$key] ?? 0, 'game_accounts' => $games[$key] ?? 0];
        }

        $values = array_column($days, 'count');
        $gameValues = array_column($days, 'game_accounts');

        return [
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'total' => array_sum($values),
            'last_7_days' => array_sum(array_slice($values, -7)),
            'game_accounts_total' => array_sum($gameValues),
            'max' => max([0, ...$values, ...$gameValues]),
        ];
    }

    /**
     * @param  list<mixed>  $timestamps
     * @return array<string, int>
     */
    private function countByDay(array $timestamps): array
    {
        return $this->countByPrefix($timestamps, 10);
    }

    /**
     * Keys are "Y-m-d H", the first 13 characters of a stored timestamp.
     *
     * @param  list<mixed>  $timestamps
     * @return array<string, int>
     */
    private function countByHour(array $timestamps): array
    {
        return $this->countByPrefix($timestamps, 13);
    }

    /**
     * Keys are "Y-m-d H:i" of the five-minute bucket.
     *
     * @param  list<mixed>  $timestamps
     * @return array<string, int>
     */
    private function countByFiveMinutes(array $timestamps): array
    {
        $counts = [];
        foreach ($timestamps as $timestamp) {
            $key = ServerOnlineHistory::bucketKey((string) $timestamp);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<mixed>  $timestamps
     * @return array<string, int>
     */
    private function countByPrefix(array $timestamps, int $length): array
    {
        $counts = [];
        foreach ($timestamps as $timestamp) {
            $key = substr((string) $timestamp, 0, $length);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return list<array{id:int,name:string,email:string,verified:bool,game_accounts:int,created_at:CarbonImmutable}> */
    private function latestMasterAccounts(): array
    {
        return User::query()
            ->withCount(['gameAccounts as active_game_accounts_count' => static function ($query): void {
                $query->where('creation_status', UserGameAccount::STATUS_ACTIVE);
            }])
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get(['id', 'name', 'email', 'email_verified_at', 'created_at'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'verified' => $user->email_verified_at !== null,
                'game_accounts' => (int) $user->getAttribute('active_game_accounts_count'),
                'created_at' => CarbonImmutable::parse($user->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Credited donations for the last 30 days, compared with the 30 days before, with daily
     * sums for the main currency. Amounts in different currencies are never added together.
     *
     * @return array{totals:list<array{currency:string,amount:float,previous:float}>,payments:int,average:float|null,currency:string|null,days:list<array{date:CarbonImmutable,amount:float}>,hours:list<float>,minutes:list<float>,max:float,latest:list<array{player:string,amount:float,currency:string,credited_at:CarbonImmutable,order_uuid:string}>,route:string,orders_route:string|null,review:int}|null
     */
    private function donations(Admin $admin): ?array
    {
        $route = null;
        foreach ($this->moduleNavigation->availableAdminLinks($admin, $this->moduleAccess) as $link) {
            if ($link['module_id'] === 'donations') {
                $route = $link['route'];
                break;
            }
        }
        if ($route === null || ! $this->tables->has('module_donation_payment_receipts')) {
            return null;
        }

        try {
            $now = CarbonImmutable::now();
            $today = $now->startOfDay();
            $start = $today->subDays(self::SERIES_DAYS - 1);
            // Only successful payments, valued at verified payer gross in the order
            // currency. Net/fee may be NULL or denominated in another currency;
            // they must never be summed into these turnover charts.
            $credited = DB::table('module_donation_payment_receipts as receipts')
                ->join('module_donation_orders as orders', 'orders.id', '=', 'receipts.order_id')
                ->where('receipts.status', 'credited')
                ->whereNotNull('receipts.credited_at');

            $payments = (clone $credited)
                ->where('receipts.credited_at', '>=', $now->subDays(60))
                ->get(['receipts.currency_code as currency', 'orders.exponent as exponent', 'receipts.payer_amount_minor as amount_minor', 'receipts.credited_at as credited_at'])
                // Times stay "Y-m-d H:i:s" strings, compared as text, like registrations.
                ->map(static fn (object $row): array => [
                    'currency' => strtoupper((string) $row->currency),
                    'amount' => (int) $row->amount_minor / (10 ** (int) $row->exponent),
                    'at' => (string) $row->credited_at,
                ]);

            $totals = [];
            $count = [];
            $monthAgo = $now->subDays(30)->format('Y-m-d H:i:s');
            foreach ($payments as $payment) {
                $currency = $payment['currency'];
                $totals[$currency] ??= ['currency' => $currency, 'amount' => 0.0, 'previous' => 0.0];
                if ($payment['at'] >= $monthAgo) {
                    $totals[$currency]['amount'] += $payment['amount'];
                    $count[$currency] = ($count[$currency] ?? 0) + 1;
                } else {
                    $totals[$currency]['previous'] += $payment['amount'];
                }
            }
            usort($totals, static fn (array $left, array $right): int => $right['amount'] <=> $left['amount']);
            // Before the first payment the chart still shows, in a currency players can pay in.
            $main = $totals[0]['currency'] ?? ($this->tables->has('module_donation_currency_rates')
                ? DB::table('module_donation_currency_rates')->where('enabled', true)->orderBy('currency_code')->value('currency_code')
                : null);
            $main = is_string($main) ? strtoupper($main) : null;

            $daily = [];
            $startAt = $start->format('Y-m-d H:i:s');
            foreach ($payments as $payment) {
                if ($payment['currency'] === $main && $payment['at'] >= $startAt) {
                    $key = substr($payment['at'], 0, 10);
                    $daily[$key] = ($daily[$key] ?? 0.0) + $payment['amount'];
                }
            }
            $days = [];
            for ($day = $start; $day->lte($today); $day = $day->addDay()) {
                $days[] = ['date' => $day, 'amount' => $daily[$day->toDateString()] ?? 0.0];
            }
            $hourly = [];
            $currentHour = $now->startOfHour();
            $firstHourAt = $currentHour->subHours(23)->format('Y-m-d H:i:s');
            foreach ($payments as $payment) {
                if ($payment['currency'] === $main && $payment['at'] >= $firstHourAt) {
                    $key = substr($payment['at'], 0, 13);
                    $hourly[$key] = ($hourly[$key] ?? 0.0) + $payment['amount'];
                }
            }
            $hours = [];
            for ($hour = $currentHour->subHours(23); $hour->lte($currentHour); $hour = $hour->addHour()) {
                $hours[] = $hourly[$hour->format('Y-m-d H')] ?? 0.0;
            }
            $recent = [];
            foreach ($payments as $payment) {
                if ($payment['currency'] === $main) {
                    $key = ServerOnlineHistory::bucketKey($payment['at']);
                    $recent[$key] = ($recent[$key] ?? 0.0) + $payment['amount'];
                }
            }
            $minutes = array_map(
                static fn (CarbonImmutable $bucket): float => $recent[$bucket->format('Y-m-d H:i')] ?? 0.0,
                ServerOnlineHistory::recentBuckets(self::RECENT_BUCKETS),
            );

            $latest = (clone $credited)
                ->join('users', 'users.id', '=', 'orders.user_id')
                ->orderByDesc('receipts.credited_at')
                ->limit(5)
                ->get(['users.name as player', 'receipts.payer_amount_minor as amount_minor', 'orders.exponent as exponent', 'receipts.currency_code as currency', 'receipts.credited_at as credited_at', 'orders.order_uuid as order_uuid'])
                ->map(static fn (object $row): array => [
                    'player' => (string) $row->player,
                    'amount' => (int) $row->amount_minor / (10 ** (int) $row->exponent),
                    'currency' => (string) $row->currency,
                    'credited_at' => CarbonImmutable::parse((string) $row->credited_at),
                    'order_uuid' => (string) $row->order_uuid,
                ])
                ->values()
                ->all();

            $ordersRoute = preg_replace('/index$/', 'orders.index', $route);
            $mainCount = $main !== null ? ($count[$main] ?? 0) : 0;

            return [
                'totals' => $totals,
                // The card shows the main currency's total and average, so the count beside
                // them is of the same payments.
                'payments' => $mainCount,
                'average' => $mainCount > 0 ? $totals[0]['amount'] / $mainCount : null,
                'currency' => $main,
                'days' => $days,
                'hours' => $hours,
                'minutes' => $minutes,
                'max' => max([0.0, ...array_column($days, 'amount')]),
                'latest' => $latest,
                'route' => $route,
                'orders_route' => is_string($ordersRoute) && Route::has($ordersRoute) ? $ordersRoute : null,
                'review' => DB::table('module_donation_payment_receipts')->where('status', 'review')->count(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
