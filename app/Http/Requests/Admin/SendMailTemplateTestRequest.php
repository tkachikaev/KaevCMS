<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use Illuminate\Validation\Rule;

class SendMailTemplateTestRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'locale' => (string) ($this->input('locale') ?: $this->query('locale') ?: app()->getLocale()),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(app(LanguageManager::class)->enabledCodes())],
            'test_email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'test_email.required' => __('Enter an address for the test email.'),
            'test_email.email' => __('The test email address is invalid.'),
        ];
    }
}
