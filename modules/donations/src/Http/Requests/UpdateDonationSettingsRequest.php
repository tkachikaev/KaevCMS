<?php

namespace KaevCMS\Modules\Donations\Http\Requests;

use App\Http\Requests\Admin\AdminFormRequest;

final class UpdateDonationSettingsRequest extends AdminFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['show_in_account' => ['required', 'boolean']];
    }
}
