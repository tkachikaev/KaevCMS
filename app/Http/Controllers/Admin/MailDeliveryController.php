<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProbeMailDeliveryModeRequest;
use App\Http\Requests\Admin\SaveMailDeliveryModeRequest;
use App\Jobs\Mail\MailDeliveryModeProbe;
use App\Services\AuditLogger;
use App\Services\Mail\MailHostingDiagnostics;
use App\Services\MailSettings;
use App\Services\MailTemplateSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class MailDeliveryController extends Controller
{
    public function index(MailSettings $mailSettings, MailTemplateSettings $mailTemplates, MailHostingDiagnostics $hosting): View
    {
        $settings = $mailSettings->values();
        $configuredUrl = rtrim((string) config('app.url', ''), '/');
        $currentUrl = rtrim(request()->root(), '/');

        return view('admin.settings.mail-delivery', [
            'settings' => $settings,
            'hosting' => $hosting->report(),
            'cronCheck' => $hosting->cronCheck(),
            'explanations' => [
                'background' => $settings['background_probe_status'] === 'failed'
                    ? $hosting->explainFailure(MailSettings::MODE_BACKGROUND, $settings['background_probe_error'])
                    : null,
                'database' => $settings['database_probe_status'] === 'failed'
                    ? $hosting->explainFailure(MailSettings::MODE_DATABASE, $settings['database_probe_error'])
                    : null,
            ],
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

    public function cronCheck(MailHostingDiagnostics $hosting, AuditLogger $auditLogger): RedirectResponse
    {
        if ($hosting->cronCheck()['status'] !== 'pending') {
            $hosting->beginCronCheck();
            $auditLogger->success(
                category: 'mail',
                action: 'mail.cron_check_started',
                target: __('Mail delivery mode'),
            );
        }

        return back()->with('status', __('The cron check has started. It finishes within about two minutes.'));
    }

    public function cronCheckStatus(MailHostingDiagnostics $hosting): JsonResponse
    {
        return response()
            ->json($hosting->cronCheck())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function startProbe(
        string $mode,
        bool $activateOnSuccess,
        MailSettings $mailSettings,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        if ($mailSettings->probeInProgress($mode)) {
            if ($activateOnSuccess) {
                $mailSettings->requestProbeActivation($mode);
            }

            return back()->with('status', $activateOnSuccess
                ? __('The mode check is already running. It will be enabled automatically after a successful test.')
                : __('The mode check is already running. The page will update automatically.'));
        }

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

            $explanation = app(MailHostingDiagnostics::class)->explainFailure($mode, $exception::class);

            return back()->withErrors([
                'delivery_mode' => $explanation['reason'].' '.$explanation['solution'],
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
