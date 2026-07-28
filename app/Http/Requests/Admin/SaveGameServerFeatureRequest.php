<?php

namespace App\Http\Requests\Admin;

class SaveGameServerFeatureRequest extends AdminFormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'location_name' => ['required', 'string', 'max:100'],
            'x' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'y' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'z' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'offline_delay_minutes' => ['required', 'integer', 'between:0,1440'],
            'cooldown_hours' => ['required', 'integer', 'between:0,720'],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'enabled' => __('Enable character rescue'),
            'location_name' => __('Location name'),
            'x' => __('Coordinate X'),
            'y' => __('Coordinate Y'),
            'z' => __('Coordinate Z'),
            'offline_delay_minutes' => __('Minimum offline time'),
            'cooldown_hours' => __('Reuse cooldown'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled') ? 1 : 0,
            'location_name' => trim((string) $this->input('location_name')),
        ]);
    }
}
