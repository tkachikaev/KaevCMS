<?php

namespace KaevCMS\Modules\DailyRewards\Http\Controllers;

use App\Auth\AdminPermission;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Services\GameAssets\GameAssetUrlResolver;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\DailyRewards\Http\Requests\StoreDailyRewardCalendarRequest;
use KaevCMS\Modules\DailyRewards\Http\Requests\UpdateDailyRewardCalendarRequest;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardDay;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardItem;

final class AdminDailyRewardController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GameAssetUrlResolver $assets,
        private readonly GameItemCatalog $items,
    ) {}

    public function index(): View
    {
        $calendars = DailyRewardCalendar::query()
            ->with(['gameServer.translations', 'days.items'])
            ->withCount('claims')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->paginate(20);

        return view('module-daily-rewards::admin.index', [
            'calendars' => $calendars,
            'totalCount' => DailyRewardCalendar::query()->count(),
            'enabledCount' => DailyRewardCalendar::query()->where('enabled', true)->count(),
            'claimCount' => DailyRewardClaim::query()->count(),
            'canManage' => $this->canManage(),
            'canViewJournal' => $this->canViewJournal(),
        ]);
    }

    public function create(): View
    {
        abort_unless($this->canManage(), 403);

        $now = now();

        return view('module-daily-rewards::admin.create', [
            'calendar' => new DailyRewardCalendar([
                'year' => (int) $now->year,
                'month' => (int) $now->month,
                'timezone' => (string) config('app.timezone', 'Europe/Moscow'),
                'enabled' => false,
            ]),
            'gameServers' => $this->gameServers(),
        ]);
    }

    public function store(StoreDailyRewardCalendarRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $admin = $request->user('admin');

        $calendar = DB::transaction(function () use ($payload, $admin): DailyRewardCalendar {
            $calendar = DailyRewardCalendar::query()->create([
                'game_server_id' => (int) $payload['game_server_id'],
                'year' => (int) $payload['year'],
                'month' => (int) $payload['month'],
                'timezone' => $this->configuredTimezone(),
                'enabled' => (bool) $payload['enabled'],
                'created_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);

            foreach (range(1, $calendar->daysInMonth()) as $dayNumber) {
                $calendar->days()->create([
                    'day_number' => $dayNumber,
                    'enabled' => false,
                ]);
            }

            return $calendar->load(['gameServer.translations', 'days.items']);
        }, 3);

        $this->auditLogger->success(
            category: 'module',
            action: 'daily_reward_calendar.created',
            target: $calendar,
            details: $this->auditDetails($calendar),
        );

        return $this->redirectTo('edit', ['calendar' => $calendar])
            ->with('status', __('module-daily-rewards::messages.created'));
    }

    public function edit(DailyRewardCalendar $calendar): View
    {
        $calendar->loadCount('claims')->load([
            'gameServer.translations',
            'days' => static fn ($query) => $query->with('items')->withCount('claims')->orderBy('day_number'),
        ]);

        $iconUrls = [];
        foreach ($calendar->days as $day) {
            foreach ($day->items as $item) {
                $iconUrls[$day->id][$item->item_id] = $this->assets->itemIcon($calendar->gameServer, $item->item_id);
            }
        }

        return view('module-daily-rewards::admin.edit', [
            'calendar' => $calendar,
            'iconUrls' => $iconUrls,
            'canManage' => $this->canManage(),
            'weekdayOffset' => now($calendar->runtimeTimezone())
                ->setDate($calendar->year, $calendar->month, 1)
                ->startOfDay()
                ->isoWeekday() - 1,
        ]);
    }

    public function itemPreview(DailyRewardCalendar $calendar, int $item): JsonResponse
    {
        abort_unless($item > 0, 404);
        $calendar->loadMissing('gameServer.translations');

        return response()->json([
            'item_id' => $item,
            'name' => $this->items->knownName($calendar->gameServer, $item)
                ?? __('module-daily-rewards::messages.unknown_item'),
            'icon_url' => $this->assets->itemIcon($calendar->gameServer, $item),
        ]);
    }

    public function update(
        UpdateDailyRewardCalendarRequest $request,
        DailyRewardCalendar $calendar,
    ): RedirectResponse {
        $payload = $request->validated();
        $admin = $request->user('admin');

        $before = $this->auditDetails($calendar->load(['days.items']));

        DB::transaction(function () use ($calendar, $payload, $admin): void {
            $locked = DailyRewardCalendar::query()->lockForUpdate()->findOrFail($calendar->id);
            $locked->loadCount('claims')->load([
                'days' => static fn ($query) => $query->with('items')->withCount('claims')->orderBy('day_number'),
            ]);

            $days = (array) $payload['days'];
            foreach ($locked->days as $day) {
                $dayPayload = $days[(string) $day->day_number] ?? $days[$day->day_number] ?? null;
                if (! is_array($dayPayload)) {
                    continue;
                }

                $normalizedRewards = $this->normalizedRewards((array) ($dayPayload['rewards'] ?? []));
                $requestedEnabled = (bool) ($dayPayload['enabled'] ?? false);

                if ((int) ($day->claims_count ?? 0) > 0) {
                    if ($requestedEnabled !== $day->enabled || $normalizedRewards !== $this->currentRewards($day)) {
                        throw ValidationException::withMessages([
                            'days.'.$day->day_number => __('module-daily-rewards::messages.claimed_day_locked', [
                                'day' => $day->day_number,
                            ]),
                        ]);
                    }

                    continue;
                }

                $day->update(['enabled' => $requestedEnabled]);
                $day->items()->delete();
                foreach ($normalizedRewards as $index => $reward) {
                    $day->items()->create([
                        'item_id' => $reward['item_id'],
                        'amount' => $reward['amount'],
                        'sort_order' => $index,
                    ]);
                }
            }

            $locked->update([
                'timezone' => $this->configuredTimezone(),
                'enabled' => (bool) $payload['enabled'],
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);
        }, 3);

        $calendar->refresh()->load(['gameServer.translations', 'days.items']);
        $this->auditLogger->success(
            category: 'module',
            action: 'daily_reward_calendar.updated',
            target: $calendar,
            details: [
                'before' => $before,
                'after' => $this->auditDetails($calendar),
            ],
        );

        return $this->redirectTo('edit', ['calendar' => $calendar])
            ->with('status', __('module-daily-rewards::messages.updated'));
    }

    public function toggle(Request $request, DailyRewardCalendar $calendar): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $admin = $request->user('admin');

        if (! $calendar->enabled && ! $calendar->days()->where('enabled', true)->whereHas('items')->exists()) {
            return $this->redirectTo('index')
                ->withErrors(['calendar' => __('module-daily-rewards::messages.enable_requires_rewards')]);
        }

        $calendar->update([
            'enabled' => ! $calendar->enabled,
            'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
        ]);

        $this->auditLogger->success(
            category: 'module',
            action: $calendar->enabled ? 'daily_reward_calendar.enabled' : 'daily_reward_calendar.disabled',
            target: $calendar,
            details: $this->auditDetails($calendar->load('days.items')),
        );

        return $this->redirectTo('index')->with(
            'status',
            $calendar->enabled
                ? __('module-daily-rewards::messages.enabled')
                : __('module-daily-rewards::messages.disabled'),
        );
    }

    /** @return Collection<int, GameServer> */
    private function gameServers(): Collection
    {
        return GameServer::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @param array<int, mixed> $rewards @return list<array{item_id:int,amount:int}> */
    private function normalizedRewards(array $rewards): array
    {
        $normalized = [];
        foreach ($rewards as $reward) {
            if (! is_array($reward)) {
                continue;
            }

            $normalized[] = [
                'item_id' => (int) ($reward['item_id'] ?? 0),
                'amount' => (int) ($reward['amount'] ?? 0),
            ];
        }

        return $normalized;
    }

    /** @return list<array{item_id:int,amount:int}> */
    private function currentRewards(DailyRewardDay $day): array
    {
        return $day->items
            ->map(static fn (DailyRewardItem $item): array => [
                'item_id' => $item->item_id,
                'amount' => $item->amount,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function auditDetails(DailyRewardCalendar $calendar): array
    {
        return [
            'game_server_id' => $calendar->game_server_id,
            'year' => $calendar->year,
            'month' => $calendar->month,
            'timezone' => $calendar->runtimeTimezone(),
            'enabled' => $calendar->enabled,
            'configured_days' => $calendar->days->where('enabled', true)->count(),
            'reward_items' => $calendar->days->sum(static fn (DailyRewardDay $day): int => $day->items->count()),
        ];
    }

    private function configuredTimezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    private function canManage(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->hasPermission(AdminPermission::ModulesManage);
    }

    private function canViewJournal(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->hasPermission(AdminPermission::RewardsView);
    }

    /** @param array<string, mixed> $parameters */
    private function redirectTo(string $route, array $parameters = []): RedirectResponse
    {
        return redirect()->route(
            'admin.module-pages.daily-rewards.'.$route,
            array_merge(['adminPath' => request()->route('adminPath')], $parameters),
        );
    }
}
