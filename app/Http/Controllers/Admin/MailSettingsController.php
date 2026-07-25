<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveMailSettingsRequest;
use App\Http\Requests\Admin\SaveMailTemplateRequest;
use App\Http\Requests\Admin\SendCustomMailRequest;
use App\Http\Requests\Admin\SendMailTemplateTestRequest;
use App\Http\Requests\Admin\SendTestMailRequest;
use App\Mail\CustomHtmlMail;
use App\Notifications\MailTemplateTestNotification;
use App\Services\AuditLogger;
use App\Services\Infrastructure\RuntimeDiagnostics;
use App\Services\Localization\LanguageManager;
use App\Services\Mail\CustomMailHtmlSanitizer;
use App\Services\MailSettings;
use App\Services\MailTemplateSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;
use Throwable;

class MailSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function mail(MailSettings $mailSettings, MailTemplateSettings $mailTemplates): View
    {
        return view('admin.settings.mail', [
            'settings' => $mailSettings->values(),
            'mailTemplates' => $mailTemplates->navigation(app()->getLocale()),
        ]);
    }

    public function customMail(MailSettings $mailSettings, MailTemplateSettings $mailTemplates): View
    {
        return view('admin.settings.mail-custom', [
            'mailSettings' => $mailSettings->values(),
            'mailTemplates' => $mailTemplates->navigation(app()->getLocale()),
            'templateLocale' => app()->getLocale(),
            'exampleHtml' => $this->customMailExample(),
        ]);
    }

    public function sendCustomMail(
        SendCustomMailRequest $request,
        MailSettings $mailSettings,
        CustomMailHtmlSanitizer $sanitizer,
    ): RedirectResponse {
        if (! $mailSettings->isReady()) {
            return back()->withInput()->withErrors([
                'recipient' => __('Configure SMTP and successfully send a test email from the Connection tab first.'),
            ]);
        }

        $validated = $request->validated();
        $address = trim((string) $validated['recipient']);
        $subject = trim((string) preg_replace('/[\r\n\t]+/u', ' ', strip_tags((string) $validated['subject'])));
        $safeHtml = $sanitizer->sanitize((string) $validated['html']);

        if ($sanitizer->plainText($safeHtml) === '') {
            return back()->withInput()->withErrors([
                'html' => __('The email must contain visible text.'),
            ]);
        }

        $mailSettings->applyConfiguration();

        try {
            Mail::to($address)->send(new CustomHtmlMail($subject, $safeHtml));
        } catch (Throwable $exception) {
            Log::warning('Custom email sending failed.', [
                'exception' => $exception::class,
            ]);
            $this->auditLogger->failed(
                category: 'mail',
                action: 'mail.custom_failed',
                target: $address,
                details: [
                    'subject' => $subject,
                    'html_length' => strlen($safeHtml),
                    'exception_class' => $exception::class,
                ],
            );

            return back()->withInput()->withErrors([
                'recipient' => __('The custom email could not be sent. Check SMTP and try again.'),
            ]);
        }

        $this->auditLogger->success(
            category: 'mail',
            action: 'mail.custom_sent',
            target: $address,
            details: [
                'subject' => $subject,
                'html_length' => strlen($safeHtml),
            ],
        );

        return redirect()
            ->route('admin.settings.mail.custom')
            ->with('status', __('Custom email sent to :email.', ['email' => $address]));
    }

    public function updateMail(
        SaveMailSettingsRequest $request,
        MailSettings $mailSettings,
        RuntimeDiagnostics $runtimeDiagnostics,
    ): RedirectResponse {
        $validated = $request->validated();
        $before = $this->mailAuditValues($mailSettings->values());
        $passwordChanged = isset($validated['smtp_password']) && $validated['smtp_password'] !== '';

        $mailSettings->update([
            'host' => (string) $validated['smtp_host'],
            'port' => (int) $validated['smtp_port'],
            'encryption' => (string) $validated['encryption'],
            'username' => (string) ($validated['smtp_username'] ?? ''),
            'password' => isset($validated['smtp_password']) && $validated['smtp_password'] !== ''
                ? (string) $validated['smtp_password']
                : null,
            'from_address' => (string) $validated['from_address'],
            'from_name' => (string) $validated['from_name'],
            'admin_email' => (string) ($validated['notification_email'] ?? ''),
        ]);
        $mailSettings->applyConfiguration();
        $runtimeDiagnostics->markQueueRestartRequired('mail-settings-updated');
        $after = $this->mailAuditValues($mailSettings->values());

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.mail_updated',
            target: __('Mail settings'),
            details: [
                'changes' => $this->auditChanges($before, $after),
                'smtp_password_changed' => $passwordChanged,
            ],
        );

        return redirect()
            ->route('admin.settings.mail')
            ->with('status', __('Mail settings saved. Send a test email to verify them.'));
    }

    public function testMail(
        SendTestMailRequest $request,
        MailSettings $mailSettings,
    ): RedirectResponse {
        if (! $mailSettings->isConfigured()) {
            return back()->withErrors([
                'test_email' => __('Save the complete mail settings first.'),
            ]);
        }

        $mailSettings->applyConfiguration();
        $address = (string) $request->validated()['test_email'];

        try {
            Mail::raw(
                __('This is a test email from KaevCMS.')."\n\n".__('If you received it, the SMTP settings are working correctly.'),
                function (Message $message) use ($address): void {
                    $message->to($address)->subject(__('Mail test — :site', ['site' => site_name()]));
                }
            );

            $mailSettings->markTested();
        } catch (Throwable $exception) {
            Log::warning('SMTP test failed.', [
                'exception' => $exception::class,
            ]);
            $this->auditLogger->failed(
                category: 'mail',
                action: 'mail.test_failed',
                target: $address,
                details: ['exception_class' => $exception::class],
            );

            return back()->withErrors([
                'test_email' => __('The test email could not be sent. Check the server, port, encryption, username and password.'),
            ]);
        }

        $this->auditLogger->success(
            category: 'mail',
            action: 'mail.test_sent',
            target: $address,
        );

        return redirect()
            ->route('admin.settings.mail')
            ->with('status', __('Test email sent successfully to :email.', ['email' => $address]));
    }

    public function mailTemplate(
        Request $request,
        string $template,
        MailTemplateSettings $mailTemplates,
        MailSettings $mailSettings,
        LanguageManager $languages,
    ): View {
        abort_unless($mailTemplates->exists($template), 404);
        $locale = $languages->normalizeCode((string) $request->query('locale', app()->getLocale()));
        if ($locale === null || ! $languages->isEnabled($locale)) {
            $locale = $languages->default();
        }

        $values = $mailTemplates->values($template, $locale);

        return view('admin.settings.mail-template', [
            'template' => $values,
            'mailTemplates' => $mailTemplates->navigation($locale),
            'preview' => $mailTemplates->render($template, $mailTemplates->demoVariables($template, $locale), $locale),
            'mailSettings' => $mailSettings->values(),
            'languages' => $languages->enabled(),
            'templateLocale' => $locale,
        ]);
    }

    public function updateMailTemplate(
        SaveMailTemplateRequest $request,
        string $template,
        MailTemplateSettings $mailTemplates,
    ): RedirectResponse {
        abort_unless($mailTemplates->exists($template), 404);
        $validated = $request->validated();
        $locale = (string) $validated['locale'];
        $values = [
            'subject' => trim((string) $validated['subject']),
            'header' => trim((string) $validated['header']),
            'heading' => trim((string) $validated['heading']),
            'body' => trim((string) $validated['body']),
            'action_text' => trim((string) ($validated['action_text'] ?? '')),
            'footer' => trim((string) ($validated['footer'] ?? '')),
        ];

        $errors = [];
        foreach ($mailTemplates->unknownVariables($template, $values, $locale) as $field => $variables) {
            $errors[$field] = __('Unknown variables: ').implode(', ', array_map(
                static fn (string $variable): string => '{{'.$variable.'}}',
                $variables,
            )).'.';
        }

        foreach ($mailTemplates->fieldsContainingHtml($values) as $field) {
            $errors[$field] = __('HTML tags are not supported. Use plain text and the available variables.');
        }

        if ($errors !== []) {
            return back()->withInput()->withErrors($errors);
        }

        $before = $mailTemplates->values($template, $locale);
        $mailTemplates->update($template, $values, $locale);
        $after = $mailTemplates->values($template, $locale);
        $changedFields = array_keys($this->auditChanges(
            $this->mailTemplateAuditValues($before),
            $this->mailTemplateAuditValues($after),
        ));

        $this->auditLogger->success(
            category: 'mail',
            action: 'mail.template_updated',
            target: $after['title'],
            details: [
                'template' => $template,
                'locale' => $locale,
                'changed_fields' => $changedFields,
            ],
        );

        return redirect()
            ->route('admin.settings.mail.template', ['template' => $template, 'locale' => $locale])
            ->with('status', __('Mail template saved.'));
    }

    public function resetMailTemplate(
        Request $request,
        string $template,
        MailTemplateSettings $mailTemplates,
        LanguageManager $languages,
    ): RedirectResponse {
        abort_unless($mailTemplates->exists($template), 404);
        $locale = $languages->normalizeCode((string) $request->input('locale'));
        abort_unless($locale !== null && $languages->isEnabled($locale), 422);
        $title = $mailTemplates->values($template, $locale)['title'];
        $mailTemplates->reset($template, $locale);

        $this->auditLogger->success(
            category: 'mail',
            action: 'mail.template_reset',
            target: $title,
            details: ['template' => $template, 'locale' => $locale],
        );

        return redirect()
            ->route('admin.settings.mail.template', ['template' => $template, 'locale' => $locale])
            ->with('status', __('Default mail template restored.'));
    }

    public function testMailTemplate(
        SendMailTemplateTestRequest $request,
        string $template,
        MailTemplateSettings $mailTemplates,
        MailSettings $mailSettings,
    ): RedirectResponse {
        abort_unless($mailTemplates->exists($template), 404);

        if (! $mailSettings->isReady()) {
            return back()->withErrors([
                'test_email' => __('Configure SMTP and successfully send a test email from the Connection tab first.'),
            ]);
        }

        $validated = $request->validated();
        $address = (string) $validated['test_email'];
        $locale = (string) $validated['locale'];
        $title = $mailTemplates->values($template, $locale)['title'];
        $mailSettings->applyConfiguration();

        try {
            Notification::route('mail', $address)
                ->notify(new MailTemplateTestNotification($template, $locale));
        } catch (Throwable $exception) {
            Log::warning('Mail template test failed.', [
                'template' => $template,
                'exception' => $exception::class,
            ]);
            $this->auditLogger->failed(
                category: 'mail',
                action: 'mail.template_test_failed',
                target: $title,
                details: [
                    'template' => $template,
                    'locale' => $locale,
                    'exception_class' => $exception::class,
                ],
            );

            return back()->withErrors([
                'test_email' => __('The test email could not be sent. Check SMTP and try again.'),
            ]);
        }

        $this->auditLogger->success(
            category: 'mail',
            action: 'mail.template_test_sent',
            target: $title,
            details: ['template' => $template, 'locale' => $locale],
        );

        return redirect()
            ->route('admin.settings.mail.template', ['template' => $template, 'locale' => $locale])
            ->with('status', __('Test template sent to :email.', ['email' => $address]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function mailTemplateAuditValues(array $values): array
    {
        return [
            'subject' => (string) ($values['subject'] ?? ''),
            'header' => (string) ($values['header'] ?? ''),
            'heading' => (string) ($values['heading'] ?? ''),
            'body' => (string) ($values['body'] ?? ''),
            'action_text' => (string) ($values['action_text'] ?? ''),
            'footer' => (string) ($values['footer'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|int|bool>
     */
    private function mailAuditValues(array $values): array
    {
        return [
            'host' => $values['host'] ?? '',
            'port' => $values['port'] ?? 0,
            'encryption' => $values['encryption'] ?? '',
            'username' => $values['username'] ?? '',
            'from_address' => $values['from_address'] ?? '',
            'from_name' => $values['from_name'] ?? '',
            'admin_email' => $values['admin_email'] ?? '',
            'password_saved' => (bool) ($values['password_saved'] ?? false),
        ];
    }

    private function customMailExample(): string
    {
        $siteName = e(site_name());
        $locale = e(app()->getLocale());
        $heading = e(__('Custom email heading'));
        $body = e(__('Replace this text with your message.'));
        $button = e(__('Open website'));
        $url = e(rtrim((string) config('app.url', 'http://127.0.0.1:8000'), '/'));

        return <<<HTML
<!doctype html>
<html lang="{$locale}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;color:#111827;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">
                    <tr>
                        <td style="padding:22px 28px;background:#111827;color:#ffffff;font-size:20px;font-weight:700;">{$siteName}</td>
                    </tr>
                    <tr>
                        <td style="padding:34px 28px;">
                            <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;">{$heading}</h1>
                            <p style="margin:0 0 24px;font-size:16px;line-height:1.65;">{$body}</p>
                            <a href="{$url}" style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:7px;font-weight:700;">{$button}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
