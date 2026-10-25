<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

final class UniversalDriverController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.settings.drivers');
    }
}
