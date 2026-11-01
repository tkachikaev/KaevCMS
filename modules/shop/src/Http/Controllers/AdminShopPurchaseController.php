<?php

namespace KaevCMS\Modules\Shop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GameServer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\Shop\Models\ShopPurchase;

/** Read-only administration of already settled Shop purchases. */
final class AdminShopPurchaseController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'server_id' => ['nullable', 'integer', 'min:1', 'exists:game_servers,id'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (filled($filters['from'] ?? null) && filled($filters['to'] ?? null) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages([
                'to' => __('validation.after_or_equal', [
                    'attribute' => __('module-shop::messages.purchase_filter_to'),
                    'date' => __('module-shop::messages.purchase_filter_from'),
                ]),
            ]);
        }

        $query = ShopPurchase::query()->with(['user', 'gameServer.translations']);
        if (filled($filters['server_id'] ?? null)) {
            $query->where('game_server_id', $filters['server_id']);
        }
        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', $filters['user_id']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (filled($filters['to'] ?? null)) {
            $nextDay = Carbon::createFromFormat('!Y-m-d', $filters['to'])->addDay()->format('Y-m-d');
            $query->where('created_at', '<', $nextDay.' 00:00:00');
        }

        return view('module-shop::admin.purchases', [
            'purchases' => $query->orderByDesc('id')->paginate(20)->withQueryString(),
            'servers' => GameServer::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(int $purchase): View
    {
        $record = ShopPurchase::query()->with(['user', 'gameServer.translations'])->findOrFail($purchase);

        return view('module-shop::admin.purchase', ['purchase' => $record]);
    }
}
