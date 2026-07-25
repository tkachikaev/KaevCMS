<?php

namespace App\Http\Requests\Admin;

use App\Services\Localization\LanguageManager;
use App\Services\MailTemplateSettings;
use Illuminate\Validation\Rule;

class SaveMailTemplateRequest extends AdminFormRequest
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
        $template = (string) $this->route('template');
        $requiresAction = app(MailTemplateSettings::class)->exists($template)
            && app(MailTemplateSettings::class)->requiresAction($template);
        $locales = app(LanguageManager::class)->enabledCodes();

        return [
            'locale' => ['required', 'string', Rule::in($locales)],
            'subject' => ['required', 'string', 'max:200'],
            'header' => ['required', 'string', 'max:150'],
            'heading' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'action_text' => [$requiresAction ? 'required' : 'nullable', 'string', 'max:100'],
            'footer' => ['nullable', 'string', 'max:3000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'subject.required' => __('Enter the email subject.'),
            'subject.max' => __('The email subject must not exceed 200 characters.'),
            'header.required' => __('Enter the name shown in the email header.'),
            'header.max' => __('The email header name must not exceed 150 characters.'),
            'heading.required' => __('Enter the email heading.'),
            'heading.max' => __('The email heading must not exceed 150 characters.'),
            'body.required' => __('Enter the main email text.'),
            'body.max' => __('The main email text must not exceed 5000 characters.'),
            'action_text.required' => __('Enter the button text.'),
            'action_text.max' => __('The button text must not exceed 100 characters.'),
            'footer.max' => __('The additional text must not exceed 3000 characters.'),
        ];
    }
}
