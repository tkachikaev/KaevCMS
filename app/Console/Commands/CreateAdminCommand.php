<?php

namespace App\Console\Commands;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Rules\PasswordWithinHasherLimit;
use App\Services\Localization\LanguageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminCommand extends Command
{
    protected $signature = 'kaevcms:admin-create {--name=} {--email=}';

    protected $aliases = ['l2forge:admin-create', 'l2cms:admin-create'];

    protected $description = 'Create an administrator for the KaevCMS control panel';

    public function handle(LanguageManager $languages): int
    {
        if (! Schema::hasTable('admins')) {
            $this->error('The admins table is missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Administrator name')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Administrator email'))));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $passwordConfirmation = (string) $this->secret('Repeat password');

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:admins,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers(),
                new PasswordWithinHasherLimit,
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = Admin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
            'role' => AdminRole::Owner,
            'locale' => $languages->default(),
        ]);

        $this->newLine();
        $this->info("Administrator created: {$admin->email}");
        $this->line('Login page: '.route('admin.login'));

        return self::SUCCESS;
    }
}
