<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAdminPathRequest;
use App\Services\Admin\AdminPathSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

final class AdminPathController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function update(
        SaveAdminPathRequest $request,
        AdminPathSettings $settings,
    ): RedirectResponse {
        $before = $settings->displayPath();
        $settings->updateSuffix((string) $request->validated('admin_path_suffix', ''));
        $after = $settings->displayPath();

        URL::defaults(['adminPath' => $settings->path()]);

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.admin_path_updated',
            target: __('Administrator panel address'),
            details: [
                'old_path' => $before,
                'new_path' => $after,
            ],
        );

        return redirect()
            ->route('admin.settings.admin-panel')
            ->with('status', __('Administrator panel address changed to :path. Save it in a secure place.', [
                'path' => $after,
            ]));
    }
}
