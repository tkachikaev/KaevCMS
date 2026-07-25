<?php

namespace App\Http\Requests\Admin;

use App\Services\Servers\ServerDriverRegistry;
use Illuminate\Validation\Rule;

class SaveLoginServerRequest extends AdminFormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'connection_action' => ['required', Rule::in(['save', 'test'])],
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(app(ServerDriverRegistry::class)->loginDriverKeys())],
            'database_host' => ['required', 'string', 'max:255'],
            'database_port' => ['required', 'integer', 'between:1,65535'],
            'database_name' => ['required', 'string', 'max:64'],
            'database_username' => ['required', 'string', 'max:128'],
            'database_password' => ['nullable', 'string', 'max:1024'],
            'database_charset' => ['required', Rule::in(['utf8mb4', 'utf8', 'latin1', 'cp1251'])],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'name' => __('LoginServer name'),
            'driver' => __('LoginServer driver'),
            'database_host' => __('Database host'),
            'database_port' => __('Database port'),
            'database_name' => __('Database name'),
            'database_username' => __('Database username'),
            'database_password' => __('Database password'),
            'database_charset' => __('Database charset'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'connection_action' => trim((string) $this->input('connection_action', 'save')),
            'name' => trim((string) $this->input('name')),
            'driver' => trim((string) $this->input('driver')),
            'database_host' => trim((string) $this->input('database_host')),
            'database_name' => trim((string) $this->input('database_name')),
            'database_username' => trim((string) $this->input('database_username')),
            'database_password' => (string) $this->input('database_password', ''),
            'database_charset' => trim((string) $this->input('database_charset', 'utf8mb4')),
        ]);
    }
}
