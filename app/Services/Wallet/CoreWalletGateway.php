<?php

namespace App\Services\Wallet;

use App\Auth\AdminPermission;
use App\Contracts\WalletGateway;
use App\Models\Admin;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletStatusEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Trusted server-side entry point. Module sources are disabled until their
 * integration is installed and explicitly enabled by application code.
 * PHP installed on the server is trusted; this does not sandbox arbitrary plugins.
 */
final class CoreWalletGateway implements WalletGateway
{
    public function __construct(
        private readonly WalletTransactionService $transactions,
        private readonly WalletStatusService $statuses,
    ) {}

    public function creditFromModule(WalletSource $source, User $recipient, int $amount, string $key, string $reference): WalletEntry
    {
        if (! $source->allowsCreditFromModule() || ! $this->sourceEnabled($source)) {
            throw new AuthorizationException('Wallet source is not enabled for credit.');
        }
        $reference = $this->requiredText($reference);

        return $this->transactions->credit($recipient, $amount, $source->value, $key, $reference);
    }

    public function debitForOwner(User $owner, int $amount, string $key, string $reference): WalletEntry
    {
        $authenticated = Auth::guard('web')->user();
        if (! $authenticated instanceof User || ! $authenticated->exists
            || (int) $authenticated->getKey() !== (int) $owner->getKey()
            || ! (bool) $authenticated->is_active
            || ! $this->sourceEnabled(WalletSource::Shop)) {
            throw new AuthorizationException('Wallet debit is not authorized.');
        }

        return $this->transactions->debit(
            $authenticated, $amount, WalletSource::Shop->value, $key,
            $this->requiredText($reference), actor: $authenticated,
        );
    }

    public function manualCredit(Admin $admin, User $recipient, int $amount, string $key, string $reason): WalletEntry
    {
        $this->assertWalletManager($admin);

        return $this->transactions->credit(
            $recipient, $amount, WalletSource::Manual->value, $key,
            reason: $this->requiredText($reason), actor: $admin,
        );
    }

    public function manualDebit(Admin $admin, User $recipient, int $amount, string $key, string $reason): WalletEntry
    {
        $this->assertWalletManager($admin);

        return $this->transactions->debit(
            $recipient, $amount, WalletSource::Manual->value, $key,
            reason: $this->requiredText($reason), actor: $admin, allowBlockedManualDebit: true,
        );
    }

    public function changeBlockStatus(Admin $admin, User $recipient, bool $blocked, string $key, string $reason): WalletStatusEvent
    {
        $this->assertWalletManager($admin);

        return $this->statuses->change($admin, $recipient, $blocked, $key, $this->requiredText($reason));
    }

    private function sourceEnabled(WalletSource $source): bool
    {
        // Integrations must set this in trusted configuration, not based on
        // any request-supplied source or module identifier.
        return config('wallet.enabled_sources.'.$source->value) === true;
    }

    private function assertWalletManager(Admin $admin): void
    {
        $current = Auth::guard('admin')->user();
        if (! $admin->exists || ! $current instanceof Admin
            || (int) $current->getKey() !== (int) $admin->getKey()) {
            throw new AuthorizationException('Wallet administration is not authorized.');
        }
        $stored = Admin::query()->whereKey($admin->getKey())->first();
        if ($stored === null || ! $stored->is_active || ! $stored->hasPermission(AdminPermission::WalletManage)) {
            throw new AuthorizationException('Wallet administration is not authorized.');
        }
    }

    private function requiredText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > 190) {
            throw new InvalidArgumentException('Wallet reference or reason must contain 1 to 190 characters.');
        }

        return $text;
    }
}
