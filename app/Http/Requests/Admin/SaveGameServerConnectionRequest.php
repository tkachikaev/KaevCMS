<?php

namespace App\Http\Requests\Admin;

use App\Services\Servers\ServerDriverRegistry;
use Illuminate\Validation\Rule;

class SaveGameServerConnectionRequest extends AdminFormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'connection_action' => ['required', Rule::in(['save', 'test'])],
            'login_server_id' => ['required', 'integer', 'exists:login_servers,id'],
            'driver' => ['required', Rule::in(app(ServerDriverRegistry::class)->gameDriverKeys())],
            'use_login_server_connection' => ['required', 'boolean'],
            'database_host' => ['nullable', 'required_if:use_login_server_connection,0', 'string', 'max:255'],
            'database_port' => ['nullable', 'required_if:use_login_server_connection,0', 'integer', 'between:1,65535'],
            'database_name' => ['nullable', 'required_if:use_login_server_connection,0', 'string', 'max:64'],
            'database_username' => ['nullable', 'required_if:use_login_server_connection,0', 'string', 'max:128'],
            'database_password' => ['nullable', 'string', 'max:1024'],
            'database_charset' => ['nullable', 'required_if:use_login_server_connection,0', Rule::in(['utf8mb4', 'utf8', 'latin1', 'cp1251'])],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'login_server_id' => __('LoginServer'),
            'driver' => __('GameServer driver'),
            'use_login_server_connection' => __('Use LoginServer database connection'),
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
            'driver' => trim((string) $this->input('driver')),
            'use_login_server_connection' => $this->boolean('use_login_server_connection') ? 1 : 0,
            'database_host' => trim((string) $this->input('database_host')),
            'database_name' => trim((string) $this->input('database_name')),
            'database_username' => trim((string) $this->input('database_username')),
            'database_password' => (string) $this->input('database_password', ''),
            'database_charset' => trim((string) $this->input('database_charset', 'utf8mb4')),
        ]);
    }
}
