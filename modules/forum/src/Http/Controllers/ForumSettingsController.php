<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class ForumSettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('module-forum::admin.settings');
    }
}
