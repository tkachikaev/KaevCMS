<?php

namespace KaevCMS\Modules\PromoCodes\Http\Controllers;

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
use KaevCMS\Modules\PromoCodes\Http\Requests\SavePromoCodeRequest;
use KaevCMS\Modules\PromoCodes\Models\PromoCode;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeActivation;
use KaevCMS\Modules\PromoCodes\Models\PromoCodeReward;

final class AdminPromoCodeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly GameAssetUrlResolver $assets,
        private readonly GameItemCatalog $items,
    ) {}

    public function index(Request $request): View
    {
        $validated = validator($request->query(), [
            'scope' => ['nullable', 'in:active,archived,all'],
        ])->validate();
        $scope = (string) ($validated['scope'] ?? 'active');

        $query = PromoCode::query();
        if ($scope === 'archived') {
            $query->onlyTrashed();
        } elseif ($scope === 'all') {
            $query->withTrashed();
        }

        $promoCodes = $query
            ->with(['gameServer.translations', 'rewards'])
            ->withExists('activations')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $iconUrls = [];
        foreach ($promoCodes as $promoCode) {
            foreach ($promoCode->rewards as $reward) {
                $iconUrls[$promoCode->id][$reward->item_id] = $this->assets->itemIcon(
                    $promoCode->gameServer,
                    $reward->item_id,
                );
            }
        }

        return view('module-promo-codes::admin.index', [
            'promoCodes' => $promoCodes,
            'iconUrls' => $iconUrls,
            'totalCount' => PromoCode::withTrashed()->count(),
            'activeCount' => PromoCode::query()->count(),
            'archivedCount' => PromoCode::onlyTrashed()->count(),
            'enabledCount' => PromoCode::query()->where('enabled', true)->count(),
            'disabledCount' => PromoCode::query()->where('enabled', false)->count(),
            'activationsCount' => PromoCodeActivation::query()->count(),
            'canManage' => $this->canManage(),
            'canViewJournal' => $this->canViewJournal(),
            'scope' => $scope,
        ]);
    }

    public function create(): View
    {
        abort_unless($this->canManage(), 403);

        return view('module-promo-codes::admin.create', [
            'promoCode' => new PromoCode([
                'enabled' => true,
                'total_limit' => 0,
                'per_user_limit' => 1,
            ]),
            'gameServers' => $this->gameServers(),
            'rewardRows' => $this->emptyRewardRows(),
            'canManage' => true,
        ]);
    }

    public function store(SavePromoCodeRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $admin = $request->user('admin');

        $promoCode = DB::transaction(function () use ($payload, $admin): PromoCode {
            $promoCode = PromoCode::query()->create([
                'game_server_id' => (int) $payload['game_server_id'],
                'code' => (string) $payload['code'],
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'total_limit' => (int) $payload['total_limit'],
                'per_user_limit' => (int) $payload['per_user_limit'],
                'enabled' => (bool) $payload['enabled'],
                'created_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);

            $this->syncRewards($promoCode, (array) $payload['rewards']);

            return $promoCode->load(['gameServer.translations', 'rewards']);
        }, 3);

        $this->auditLogger->success(
            category: 'module',
            action: 'promo_code.created',
            target: $promoCode,
            details: $this->auditDetails($promoCode),
        );

        return $this->redirectTo('index')
            ->with('status', __('module-promo-codes::messages.created', ['code' => $promoCode->code]));
    }

    public function edit(PromoCode $promoCode): View
    {
        $promoCode->load(['gameServer.translations', 'rewards']);

        $rows = $promoCode->rewards
            ->map(static fn (PromoCodeReward $reward): array => [
                'item_id' => (string) $reward->item_id,
                'amount' => (string) $reward->amount,
            ])
            ->all();

        return view('module-promo-codes::admin.edit', [
            'promoCode' => $promoCode,
            'gameServers' => $this->gameServers(),
            'rewardRows' => $rows !== [] ? $rows : $this->emptyRewardRows(),
            'canManage' => $this->canManage(),
            'hasActivations' => $promoCode->activations()->exists(),
        ]);
    }

    public function itemPreview(GameServer $server, int $item): JsonResponse
    {
        abort_unless($item > 0, 404);
        $server->loadMissing('translations');

        return response()->json([
            'item_id' => $item,
            'name' => $this->items->knownName($server, $item)
                ?? __('module-promo-codes::messages.unknown_item'),
            'icon_url' => $this->assets->itemIcon($server, $item),
        ]);
    }

    public function update(SavePromoCodeRequest $request, PromoCode $promoCode): RedirectResponse
    {
        $payload = $request->validated();
        $admin = $request->user('admin');
        $before = $this->auditDetails($promoCode->load('rewards'));

        DB::transaction(function () use ($promoCode, $payload, $admin): void {
            $locked = PromoCode::query()->lockForUpdate()->findOrFail($promoCode->id);
            $totalLimit = (int) $payload['total_limit'];
            if ($totalLimit > 0 && $totalLimit < $locked->activations_count) {
                throw ValidationException::withMessages([
                    'total_limit' => __('module-promo-codes::messages.total_limit_below_activations', [
                        'count' => $locked->activations_count,
                    ]),
                ]);
            }

            $gameServerId = (int) $payload['game_server_id'];
            if ($locked->activations_count > 0 && $gameServerId !== $locked->game_server_id) {
                throw ValidationException::withMessages([
                    'game_server_id' => __('module-promo-codes::messages.server_locked_after_activation'),
                ]);
            }

            $locked->update([
                'game_server_id' => (int) $payload['game_server_id'],
                'code' => (string) $payload['code'],
                'starts_at' => $payload['starts_at'] ?? null,
                'ends_at' => $payload['ends_at'] ?? null,
                'total_limit' => (int) $payload['total_limit'],
                'per_user_limit' => (int) $payload['per_user_limit'],
                'enabled' => (bool) $payload['enabled'],
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);

            $this->syncRewards($locked, (array) $payload['rewards']);
        }, 3);

        $promoCode->refresh()->load(['gameServer.translations', 'rewards']);
        $this->auditLogger->success(
            category: 'module',
            action: 'promo_code.updated',
            target: $promoCode,
            details: [
                'before' => $before,
                'after' => $this->auditDetails($promoCode),
            ],
        );

        return $this->redirectTo('index')
            ->with('status', __('module-promo-codes::messages.updated', ['code' => $promoCode->code]));
    }

    public function toggle(Request $request, PromoCode $promoCode): RedirectResponse
    {
        $admin = $request->user('admin');

        $promoCode->update([
            'enabled' => ! $promoCode->enabled,
            'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
        ]);

        $this->auditLogger->success(
            category: 'module',
            action: $promoCode->enabled ? 'promo_code.enabled' : 'promo_code.disabled',
            target: $promoCode,
            details: [
                'game_server_id' => $promoCode->game_server_id,
                'enabled' => $promoCode->enabled,
            ],
        );

        return $this->redirectTo('index')->with(
            'status',
            $promoCode->enabled
                ? __('module-promo-codes::messages.enabled', ['code' => $promoCode->code])
                : __('module-promo-codes::messages.disabled', ['code' => $promoCode->code]),
        );
    }

    public function destroy(Request $request, PromoCode $promoCode): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $admin = $request->user('admin');
        /** @var array{promo_code: PromoCode, details: array<string, mixed>, history_preserved: bool} $result */
        $result = DB::transaction(function () use ($promoCode, $admin): array {
            $locked = PromoCode::query()->lockForUpdate()->findOrFail($promoCode->id);
            $locked->load(['gameServer.translations', 'rewards']);
            $details = $this->auditDetails($locked);
            $hasActivations = $locked->activations()->exists();

            if ($hasActivations) {
                $locked->update([
                    'enabled' => false,
                    'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
                ]);
                $locked->delete();
            } else {
                $locked->forceDelete();
            }

            return [
                'promo_code' => $locked,
                'details' => $details,
                'history_preserved' => $hasActivations,
            ];
        }, 3);

        $archived = $result['history_preserved'];
        $target = $result['promo_code'];
        $this->auditLogger->success(
            category: 'module',
            action: $archived ? 'promo_code.archived' : 'promo_code.deleted',
            target: $target,
            details: array_merge($result['details'], [
                'history_preserved' => $archived,
            ]),
        );

        return $this->redirectTo('index')->with(
            'status',
            $archived
                ? __('module-promo-codes::messages.archived', ['code' => $target->code])
                : __('module-promo-codes::messages.deleted', ['code' => $target->code]),
        );
    }

    public function restore(Request $request, int $promoCode): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $admin = $request->user('admin');
        $restored = DB::transaction(function () use ($promoCode, $admin): PromoCode {
            $locked = PromoCode::onlyTrashed()->lockForUpdate()->findOrFail($promoCode);
            $locked->restore();
            $locked->update([
                'enabled' => false,
                'updated_by_admin_id' => $admin instanceof Admin ? $admin->id : null,
            ]);

            return $locked->load(['gameServer.translations', 'rewards']);
        }, 3);

        $this->auditLogger->success(
            category: 'module',
            action: 'promo_code.restored',
            target: $restored,
            details: array_merge($this->auditDetails($restored), [
                'restored_disabled' => true,
            ]),
        );

        return $this->redirectTo('index', ['scope' => 'archived'])
            ->with('status', __('module-promo-codes::messages.restored', ['code' => $restored->code]));
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

    /** @return list<array{item_id:string,amount:string}> */
    private function emptyRewardRows(): array
    {
        return [['item_id' => '', 'amount' => '']];
    }

    /** @param array<int, mixed> $rewards */
    private function syncRewards(PromoCode $promoCode, array $rewards): void
    {
        $promoCode->rewards()->delete();

        foreach (array_values($rewards) as $index => $reward) {
            if (! is_array($reward)) {
                continue;
            }

            $promoCode->rewards()->create([
                'item_id' => (int) ($reward['item_id'] ?? 0),
                'amount' => (int) ($reward['amount'] ?? 0),
                'sort_order' => $index,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function auditDetails(PromoCode $promoCode): array
    {
        return [
            'game_server_id' => $promoCode->game_server_id,
            'starts_at' => $promoCode->starts_at?->toIso8601String(),
            'ends_at' => $promoCode->ends_at?->toIso8601String(),
            'total_limit' => $promoCode->total_limit,
            'per_user_limit' => $promoCode->per_user_limit,
            'enabled' => $promoCode->enabled,
            'rewards' => $promoCode->rewards
                ->map(static fn (PromoCodeReward $reward): array => [
                    'item_id' => $reward->item_id,
                    'amount' => $reward->amount,
                ])
                ->all(),
        ];
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
            'admin.module-pages.promo-codes.'.$route,
            array_merge(['adminPath' => request()->route('adminPath')], $parameters),
        );
    }
}
