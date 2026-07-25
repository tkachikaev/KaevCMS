<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemInformation;
use Illuminate\View\View;

final class SystemSettingsController extends Controller
{
    public function system(SystemInformation $systemInformation): View
    {
        return view('admin.settings.system', [
            'system' => $systemInformation->collect(),
        ]);
    }
}
