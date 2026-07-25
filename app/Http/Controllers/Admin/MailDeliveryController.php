<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProbeMailDeliveryModeRequest;
use App\Http\Requests\Admin\SaveMailDeliveryModeRequest;
use App\Jobs\Mail\MailDeliveryModeProbe;
use App\Services\AuditLogger;
use App\Services\MailSettings;
use App\Services\MailTemplateSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class MailDeliveryController extends Controller
{
    public function index(MailSettings $mailSettings, MailTemplateSettings $mailTemplates): View
    {
        $configuredUrl = rtrim((string) config('app.url', ''), '/');
        $currentUrl = rtrim(request()->root(), '/');

        return view('admin.settings.mail-delivery', [
            'settings' => $mailSettings->values(),
            'mailTemplates' => $mailTemplates->navigation(app()->getLocale()),
            'configuredUrl' => $configuredUrl,
            'currentUrl' => $currentUrl,
            'appUrlMismatch' => $configuredUrl !== '' && strcasecmp($configuredUrl, $currentUrl) !== 0,
        ]);
    }

    public function update(
        SaveMailDeliveryModeRequest $request,
        MailSettings $mailSettings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $mode = (string) $request->validated()['delivery_mode'];
        $before = $mailSettings->deliveryMode();

        if ($mode !== MailSettings::MODE_SYNC && ! $mailSettings->modeSupported($mode)) {
            return $this->startProbe(
                mode: $mode,
                activateOnSuccess: true,
                mailSettings: $mailSettings,
                auditLogger: $auditLogger,
            );
        }

        $mailSettings->setDeliveryMode($mode);
        $auditLogger->success(
            category: 'mail',
            action: 'settings.mail_delivery_mode_updated',
            target: __('Mail delivery mode'),
            details: ['old' => $before, 'new' => $mode],
        );

        return back()->with('status', __('Mail delivery mode saved.'));
    }

    public function probe(
        ProbeMailDeliveryModeRequest $request,
        MailSettings $mailSettings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        return $this->startProbe(
            mode: (string) $request->validated()['delivery_mode'],
            activateOnSuccess: false,
            mailSettings: $mailSettings,
            auditLogger: $auditLogger,
        );
    }

    public function probeStatus(
        ProbeMailDeliveryModeRequest $request,
        MailSettings $mailSettings,
    ): JsonResponse {
        $mode = (string) $request->validated()['delivery_mode'];
        $values = $mailSettings->values();
        $probe = $mode === MailSettings::MODE_DATABASE
            ? [
                'status' => $values['database_probe_status'],
                'supported' => $values['database_supported'],
                'completed_at' => $values['database_probe_completed_at'],
            ]
            : [
                'status' => $values['background_probe_status'],
                'supported' => $values['background_supported'],
                'completed_at' => $values['background_probe_completed_at'],
            ];

        return response()
            ->json([
                'mode' => $mode,
                'status' => $probe['status'],
                'supported' => $probe['supported'],
                'completed_at' => $probe['completed_at'],
                'active' => $values['delivery_mode'] === $mode,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function startProbe(
        string $mode,
        bool $activateOnSuccess,
        MailSettings $mailSettings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $token = $mailSettings->beginProbe($mode, $activateOnSuccess);

        try {
            MailDeliveryModeProbe::dispatch($mode, $token)
                ->onConnection($mailSettings->probeConnection($mode))
                ->onQueue('mail-probe');
        } catch (Throwable $exception) {
            $mailSettings->failProbe($mode, $token, $exception::class);

            $auditLogger->failed(
                category: 'mail',
                action: 'mail.delivery_probe_failed',
                target: __('Mail delivery mode'),
                details: ['mode' => $mode, 'exception_class' => $exception::class],
            );

            return back()->withErrors([
                'delivery_mode' => __('The selected asynchronous mode is not supported by this server. Synchronous delivery remains active.'),
            ]);
        }

        $auditLogger->success(
            category: 'mail',
            action: 'mail.delivery_probe_started',
            target: __('Mail delivery mode'),
            details: ['mode' => $mode, 'activate_on_success' => $activateOnSuccess],
        );

        return back()->with('status', $activateOnSuccess
            ? __('The mode check has started. It will be enabled automatically after a successful test.')
            : __('The mode check has started. The page will update automatically.'));
    }
}
