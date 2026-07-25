<?php

namespace App\Http\Requests\Admin;

use App\Services\Mail\CustomMailHtmlSanitizer;
use Illuminate\Validation\Validator;

class SendCustomMailRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:200'],
            'html' => ['required', 'string', 'max:'.CustomMailHtmlSanitizer::MAX_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('html')) {
                return;
            }

            $html = (string) $this->input('html', '');
            $violations = app(CustomMailHtmlSanitizer::class)->violations($html);

            if ($violations !== []) {
                $labels = array_map(static fn (string $item): string => __($item), $violations);
                $validator->errors()->add(
                    'html',
                    __('The HTML contains blocked code: :items.', ['items' => implode(', ', $labels)]),
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recipient.required' => __('Enter the recipient email address.'),
            'recipient.email' => __('Enter a valid recipient email address.'),
            'subject.required' => __('Enter the email subject.'),
            'subject.max' => __('The email subject must not exceed 200 characters.'),
            'html.required' => __('Enter the HTML content of the email.'),
            'html.max' => __('The HTML content must not exceed 200000 characters.'),
        ];
    }
}
