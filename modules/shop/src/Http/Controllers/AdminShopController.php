<?php

namespace KaevCMS\Modules\Shop\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GameServer;
use Illuminate\View\View;
use KaevCMS\Modules\Shop\Models\ShopServerSetting;
use KaevCMS\Modules\Shop\Models\ShopSetting;

final class AdminShopController extends Controller
{
    public function index(): View
    {
        return view('module-shop::admin.index', [
            'global' => ShopSetting::query()->where('setting_key', 'global')->firstOrFail(),
            'serverCount' => GameServer::query()->count(),
            'configuredCount' => ShopServerSetting::query()->count(),
        ]);
    }
}
