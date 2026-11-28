<?php

namespace KaevCMS\Modules\Voting\Http\Controllers;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\AuditLogger;
use App\Services\GameAssets\GameItemCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use RuntimeException;

final class AdminVotingRewardController extends Controller
{
    public function __construct(private readonly GameItemCatalog $catalog, private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $servers = GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();
        $server = $servers->firstWhere('id', filter_var($request->query('server'), FILTER_VALIDATE_INT)) ?? $servers->first();
        $reward = $server === null ? null : VotingServerReward::query()->where('game_server_id', $server->id)->first();
        $items = $reward?->validatedItems() ?? [];

        return view('module-voting::admin.rewards', [
            'servers' => $servers,
            'server' => $server,
            'reward' => $reward,
            'items' => $items,
            'catalog' => $this->catalog,
        ]);
    }

    public function preview(GameServer $server, int $item): JsonResponse
    {
        abort_unless($item > 0 && $item <= 2147483647, 404);
        $data = $this->catalog->findForServer($server, $item);

        return response()->json(['item_id' => $item, 'name' => $data['name'], 'icon_url' => $data['icon']]);
    }

    public function update(Request $request, GameServer $server): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true), 403);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'rewards' => ['nullable', 'array', 'max:100'],
            'rewards.*' => ['array:item_id,amount'],
            'rewards.*.item_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'rewards.*.amount' => ['nullable', 'integer', 'min:1', 'max:2000000000'],
        ]);

        $items = [];
        $seen = [];
        foreach ($data['rewards'] ?? [] as $row) {
            if (($row['item_id'] ?? null) === null && ($row['amount'] ?? null) === null) {
                continue;
            }
            if (! isset($row['item_id'], $row['amount']) || isset($seen[(int) $row['item_id']])) {
                throw ValidationException::withMessages(['rewards' => __('module-voting::messages.invalid_rewards')]);
            }
            $id = (int) $row['item_id'];
            $seen[$id] = true;
            $items[] = ['item_id' => $id, 'amount' => (int) $row['amount']];
        }
        $enabled = (bool) $data['enabled'];

        DB::transaction(function () use ($admin, $server, $enabled, $items): void {
            VotingSetting::query()->where('provider_code', 'ktreon')->lockForUpdate()->firstOrFail();
            $locked = GameServer::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            if ($enabled && ($locked->login_server_id === null || $items === [])) {
                throw ValidationException::withMessages(['rewards' => __('module-voting::messages.reward_required')]);
            }
            $reward = VotingServerReward::query()->where('game_server_id', $locked->id)->lockForUpdate()->first() ?? new VotingServerReward;
            $before = $reward->exists ? $reward->only(['enabled', 'items']) : null;
            $reward->forceFill(['game_server_id' => $locked->id, 'enabled' => $enabled, 'items' => $items])->save();
            if ($this->audit->success('module', 'voting.rewards_updated', $admin, $locked, [
                'before' => $before, 'after' => ['enabled' => $enabled, 'items' => $items],
            ]) === null) {
                throw new RuntimeException('Voting reward audit could not be recorded.');
            }
        }, 3);

        return redirect()->route('admin.module-pages.voting.rewards.index', [
            'adminPath' => $request->route('adminPath'), 'server' => $server->id,
        ])->with('status', __('module-voting::messages.saved'));
    }
}
