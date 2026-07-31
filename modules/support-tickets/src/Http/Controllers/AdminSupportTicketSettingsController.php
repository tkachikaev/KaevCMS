<?php

namespace KaevCMS\Modules\SupportTickets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use KaevCMS\Modules\SupportTickets\Http\Requests\UpdateSupportTicketSettingsRequest;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketCleanupService;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketSettings;

final class AdminSupportTicketSettingsController extends Controller
{
    public function __construct(
        private readonly SupportTicketSettings $settings,
        private readonly SupportTicketCleanupService $cleanup,
        private readonly AuditLogger $auditLogger,
        private readonly AdminPathSettings $adminPathSettings,
    ) {}

    public function edit(): View
    {
        return view('module-support-tickets::admin.settings', [
            'settings' => $this->settings->values(),
            'cleanupPreview' => session('support_cleanup_preview'),
            'cleanupResult' => session('support_cleanup_result'),
            'adminPath' => $this->adminPathSettings->path(),
        ]);
    }

    public function update(UpdateSupportTicketSettingsRequest $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $allowEditorView = (bool) $request->validated('allow_editor_view');
        $values = [
            'allow_editor_view' => $allowEditorView,
            'allow_editor_reply' => $allowEditorView && (bool) $request->validated('allow_editor_reply'),
            'allow_editor_internal_notes' => $allowEditorView && (bool) $request->validated('allow_editor_internal_notes'),
            'retention_months' => (int) $request->validated('retention_months'),
            'automatic_cleanup_enabled' => (bool) $request->validated('automatic_cleanup_enabled'),
            'max_tickets_per_day' => (int) $request->validated('max_tickets_per_day'),
            'max_player_messages_per_day' => (int) $request->validated('max_player_messages_per_day'),
            'max_messages_per_ticket' => (int) $request->validated('max_messages_per_ticket'),
            'max_revisions_per_message' => (int) $request->validated('max_revisions_per_message'),
            'max_open_tickets_per_user' => (int) $request->validated('max_open_tickets_per_user'),
            'subject_max_length' => (int) $request->validated('subject_max_length'),
            'initial_message_max_length' => (int) $request->validated('initial_message_max_length'),
            'message_max_length' => (int) $request->validated('message_max_length'),
        ];
        $this->settings->update($values, $admin);

        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.settings_updated',
            actor: $admin,
            target: 'support-tickets',
            details: $values,
        );

        return $this->backToSettings()
            ->with('status', __('module-support-tickets::messages.settings_saved'));
    }

    public function previewCleanup(Request $request): RedirectResponse
    {
        $configuration = $this->settings->cleanupConfiguration();
        if ($configuration === null) {
            return $this->cleanupConfigurationError();
        }

        $result = $this->cleanup->preview($configuration['retention_months']);

        return $this->backToSettings('cleanup')->with('support_cleanup_preview', $result);
    }

    public function runCleanup(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $configuration = $this->settings->cleanupConfiguration();
        if ($configuration === null) {
            return $this->cleanupConfigurationError();
        }

        $result = $this->cleanup->cleanup($configuration['retention_months']);

        $this->auditLogger->success(
            category: 'module',
            action: 'support_ticket.cleanup_completed',
            actor: $admin,
            target: 'support-tickets',
            details: $result,
        );

        return $this->backToSettings('cleanup')
            ->with('support_cleanup_result', $result)
            ->with('status', __('module-support-tickets::messages.cleanup_completed'));
    }

    private function cleanupConfigurationError(): RedirectResponse
    {
        return $this->backToSettings('cleanup')->withErrors([
            'cleanup' => __('Support ticket cleanup was not started because its settings could not be read.'),
        ]);
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    private function backToSettings(?string $tab = null): RedirectResponse
    {
        $parameters = ['adminPath' => $this->adminPathSettings->path()];
        if ($tab !== null) {
            $parameters['tab'] = $tab;
        }

        return redirect()->route('admin.module-pages.support-tickets.settings', $parameters);
    }
}
