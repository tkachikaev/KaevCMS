<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class GameServerController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.game-server');
    }
}
