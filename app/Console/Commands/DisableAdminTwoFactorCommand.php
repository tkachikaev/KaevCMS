<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DisableAdminTwoFactorCommand extends Command
{
    protected $signature = 'kaevcms:admin-2fa:disable {email : Administrator email} {--force : Skip confirmation}';

    protected $aliases = ['l2forge:admin-2fa:disable'];

    protected $description = 'Disable two-factor authentication for an administrator';

    public function handle(AuditLogger $auditLogger): int
    {
        if (! Schema::hasColumn('admins', 'two_factor_secret')) {
            $this->error('The two-factor authentication migration is missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $admin = Admin::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->error('Administrator not found.');

            return self::FAILURE;
        }

        if (! $admin->twoFactorEnabled()) {
            $this->info('Two-factor authentication is already disabled.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Disable 2FA for {$admin->email} and invalidate active sessions?")) {
            $this->warn('Operation cancelled.');

            return self::SUCCESS;
        }

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'session_version' => $admin->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        $auditLogger->system(
            category: 'admin',
            action: 'administrator.2fa_reset_console',
            target: $admin,
            details: ['sessions_invalidated' => true],
        );

        $this->info("Two-factor authentication disabled for {$admin->email}.");

        return self::SUCCESS;
    }
}
