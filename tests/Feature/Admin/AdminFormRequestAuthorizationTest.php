<?php

namespace Tests\Feature\Admin;

use App\Http\Requests\Admin\CleanupSecurityLogsRequest;
use App\Http\Requests\Admin\SaveGameAccountSettingsRequest;
use App\Http\Requests\Admin\SaveGameServerConnectionRequest;
use App\Http\Requests\Admin\SaveGeneralSettingsRequest;
use App\Http\Requests\Admin\SaveLanguageSettingsRequest;
use App\Http\Requests\Admin\SaveLoginServerRequest;
use App\Http\Requests\Admin\SaveMailSettingsRequest;
use App\Http\Requests\Admin\SaveMailTemplateRequest;
use App\Http\Requests\Admin\SaveNewsRequest;
use App\Http\Requests\Admin\SavePageRequest;
use App\Http\Requests\Admin\SaveRegistrationSettingsRequest;
use App\Http\Requests\Admin\SaveSecuritySettingsRequest;
use App\Http\Requests\Admin\SaveServerMonitorSettingsRequest;
use App\Http\Requests\Admin\SendCustomMailRequest;
use App\Http\Requests\Admin\SendMailTemplateTestRequest;
use App\Http\Requests\Admin\SendTestMailRequest;
use App\Http\Requests\Admin\UploadNewsImageRequest;
use App\Http\Requests\Admin\UploadPageImageRequest;
use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminFormRequestAuthorizationTest extends TestCase
{
    #[DataProvider('adminRequestClasses')]
    public function test_admin_form_requests_require_an_authenticated_admin(string $requestClass): void
    {
        /** @var FormRequest $request */
        $request = new $requestClass;
        $request->setUserResolver(static fn (?string $guard = null): null => null);

        $this->assertFalse($request->authorize());

        $admin = new Admin([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'is_active' => true,
        ]);
        $request->setUserResolver(
            static fn (?string $guard = null): ?Admin => $guard === 'admin' ? $admin : null,
        );

        $this->assertTrue($request->authorize());
    }

    /** @return array<string, array{class-string<FormRequest>}> */
    public static function adminRequestClasses(): array
    {
        return [
            'security log cleanup' => [CleanupSecurityLogsRequest::class],
            'game account settings' => [SaveGameAccountSettingsRequest::class],
            'game server connection' => [SaveGameServerConnectionRequest::class],
            'login server settings' => [SaveLoginServerRequest::class],
            'general settings' => [SaveGeneralSettingsRequest::class],
            'language settings' => [SaveLanguageSettingsRequest::class],
            'mail settings' => [SaveMailSettingsRequest::class],
            'mail template' => [SaveMailTemplateRequest::class],
            'news' => [SaveNewsRequest::class],
            'page' => [SavePageRequest::class],
            'registration settings' => [SaveRegistrationSettingsRequest::class],
            'security settings' => [SaveSecuritySettingsRequest::class],
            'server monitor settings' => [SaveServerMonitorSettingsRequest::class],
            'custom mail' => [SendCustomMailRequest::class],
            'mail template test' => [SendMailTemplateTestRequest::class],
            'mail test' => [SendTestMailRequest::class],
            'news image' => [UploadNewsImageRequest::class],
            'page image' => [UploadPageImageRequest::class],
        ];
    }
}
