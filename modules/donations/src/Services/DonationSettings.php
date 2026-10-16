<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use RuntimeException;
use Throwable;

/** B1 controls only the visibility of a read-only page; it never enables paid checkout. */
final class DonationSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function isVisibleToPlayers(): bool
    {
        try {
            if (! Schema::hasTable('module_donation_settings')) {
                return false;
            }

            $setting = DonationSetting::query()->find(1);
            // Compare the original DB value, not an Eloquent boolean cast of malformed data.
            return $setting instanceof DonationSetting
                && in_array($setting->getRawOriginal('show_in_account'), [true, 1, '1'], true);
        } catch (Throwable) {
            // Settings failures must never expose a page or permit payment attempts.
            return false;
        }
    }

    public function updateVisibility(bool $visible, Admin $admin): void
    {
        if (! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        DB::transaction(function () use ($visible, $admin): void {
            $settings = DonationSetting::query()->whereKey(1)->lockForUpdate()->first();
            if (! $settings instanceof DonationSetting) {
                throw new RuntimeException('Donations settings have not been installed.');
            }

            $settings->forceFill([
                'show_in_account' => $visible,
                'updated_by_admin_id' => $admin->getKey(),
            ])->save();

            // Fail closed: settings and audit must be committed or rolled back together.
            $entry = $this->audit->success(
                category: 'module',
                action: 'donations.visibility_updated',
                actor: $admin,
                target: 'donations',
                details: ['show_in_account' => $visible],
            );
            if ($entry === null) {
                throw new RuntimeException('Unable to audit Donations configuration change.');
            }
        });
    }
}
