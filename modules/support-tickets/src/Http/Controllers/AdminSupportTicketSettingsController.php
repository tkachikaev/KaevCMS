<?php

namespace KaevCMS\Modules\SupportTickets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Http\Requests\UpdateSupportTicketSettingsRequest;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

final class AdminSupportTicketSettingsController extends Controller
{
    public function __construct(
        private readonly SupportTicketSettings $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function edit(): View
    {
        return view('module-support-tickets::admin.settings', [
            'allowEditorManagement' => $this->settings->editorsCanManage(),
        ]);
    }

    public function update(UpdateSupportTicketSettingsRequest $request): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $enabled = (bool) $request->validated('allow_editor_management');
        $this->settings->update($enabled, $admin);

        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.settings_updated',
            actor: $admin,
            target: 'support-tickets',
            details: ['allow_editor_management' => $enabled],
        );

        return redirect()->route('admin.module-pages.support-tickets.settings', [
            'adminPath' => $request->route('adminPath'),
        ])->with('status', __('module-support-tickets::messages.settings_saved'));
    }
}
