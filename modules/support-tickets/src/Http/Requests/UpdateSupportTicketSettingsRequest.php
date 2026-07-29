<?php

namespace KaevCMS\Modules\SupportTickets\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;

final class UpdateSupportTicketSettingsRequest extends AdminFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['allow_editor_management' => $this->boolean('allow_editor_management')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['allow_editor_management' => ['required', 'boolean']];
    }
}
