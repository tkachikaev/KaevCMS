<?php

namespace KaevCMS\Modules\NewsComments\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use KaevCMS\Modules\NewsComments\Services\NewsCommentSettings;

final class UpdateNewsCommentSettingsRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'allow_user_editing' => $this->boolean('allow_user_editing'),
            'allow_editor_moderation' => $this->boolean('allow_editor_moderation'),
        ]);
    }

    /** @return array<string, list<string|In>> */
    public function rules(): array
    {
        $rules = [
            'enabled' => ['required', 'boolean'],
            'publication_mode' => ['required', Rule::in([
                NewsCommentSettings::MODE_MANUAL,
                NewsCommentSettings::MODE_AUTOMATIC,
            ])],
            'allow_user_editing' => ['required', 'boolean'],
            'allow_editor_moderation' => ['required', 'boolean'],
        ];

        foreach (NewsCommentSettings::LIMITS as $field => [, $minimum, $maximum]) {
            $rules[$field] = ['required', 'integer', 'min:'.$minimum, 'max:'.$maximum];
        }

        return $rules;
    }
}
