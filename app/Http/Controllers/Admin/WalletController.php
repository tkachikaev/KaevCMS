<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermission;
use App\Contracts\WalletGateway;
use App\Exceptions\WalletOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustWalletRequest;
use App\Http\Requests\Admin\ChangeWalletStatusRequest;
use App\Models\Admin;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletStatusEvent;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** CMS-only wallet administration; all modifications go through WalletGateway. */
final class WalletController extends Controller
{
    public function index(Request $request, WalletAppearanceSettings $appearance): View
    {
        $search = Str::limit(trim((string) $request->query('q', '')), 100, '');
        $status = $request->query('status');
        $status = in_array($status, ['blocked', 'available'], true) ? $status : '';

        $users = User::query()->with('wallet:id,user_id,balance,is_blocked')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                    if (ctype_digit($search) && strlen($search) < 19) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($status === 'blocked', fn ($query) => $query->whereHas('wallet', fn ($wallet) => $wallet->where('is_blocked', true)))
            ->when($status === 'available', fn ($query) => $query->where(function ($query): void {
                $query->whereDoesntHave('wallet')->orWhereHas('wallet', fn ($wallet) => $wallet->where('is_blocked', false));
            }))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('admin.wallets.index', [
            'users' => $users,
            'search' => $search,
            'status' => $status,
            'appearance' => $appearance->values(),
        ]);
    }

    public function show(User $user, WalletAppearanceSettings $appearance): View
    {
        $wallet = $user->wallet()->first();
        $entries = WalletEntry::query()->where('wallet_id', $wallet->id ?? 0)
            ->orderByDesc('id')->paginate(30);
        $statusEvents = WalletStatusEvent::query()->where('wallet_id', $wallet->id ?? 0)
            ->orderByDesc('id')->paginate(15, ['*'], 'status_page');

        return view('admin.wallets.show', [
            'user' => $user,
            'wallet' => $wallet,
            'entries' => $entries,
            'statusEvents' => $statusEvents,
            'appearance' => $appearance->values(),
            'operationKey' => (string) Str::uuid(),
            'canManageWallet' => (bool) auth('admin')->user()?->hasPermission(AdminPermission::WalletManage),
        ]);
    }

    public function adjust(AdjustWalletRequest $request, User $user, WalletGateway $gateway): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->hasPermission(AdminPermission::WalletManage), 403);

        $values = $request->validated();
        // The request regex + post-validator prohibit float/scientific notation and PHP int overflow.
        $amount = (int) $values['amount'];
        $key = 'adjust:'.$values['operation_key'];

        try {
            if ($values['operation'] === 'credit') {
                $gateway->manualCredit($admin, $user, $amount, $key, $values['reason']);
            } else {
                $gateway->manualDebit($admin, $user, $amount, $key, $values['reason']);
            }
        } catch (WalletOperationException $exception) {
            $message = match ($exception->reason) {
                'insufficient_funds' => __('Wallet has insufficient balance.'),
                'wallet_blocked' => __('Wallet spending is blocked.'),
                'balance_overflow' => __('Wallet balance limit exceeded.'),
                'ledger_mismatch' => __('Wallet balance verification failed. No adjustment was made.'),
                'idempotency_conflict' => __('This adjustment key was already used for another operation.'),
                default => __('Wallet adjustment failed. No changes were applied.'),
            };

            return back()->withErrors(['adjustment' => $message]);
        }

        return redirect()->route('admin.wallets.show', $user)
            ->with('status', __('Wallet adjustment recorded.'));
    }

    public function changeStatus(ChangeWalletStatusRequest $request, User $user, WalletGateway $gateway): RedirectResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin && $admin->hasPermission(AdminPermission::WalletManage), 403);

        $values = $request->validated();
        try {
            $gateway->changeBlockStatus(
                $admin, $user, $values['action'] === 'block',
                'status:'.$values['operation_key'], $values['reason'],
            );
        } catch (WalletOperationException $exception) {
            $message = match ($exception->reason) {
                'already_in_state' => __('Wallet already has the requested status.'),
                'idempotency_conflict' => __('This status change key was already used for another operation.'),
                'ledger_mismatch' => __('Wallet balance verification failed. Status was not changed.'),
                default => __('Wallet status change failed. No changes were applied.'),
            };

            return back()->withErrors(['status_change' => $message]);
        }

        return redirect()->route('admin.wallets.show', $user)
            ->with('status', __('Wallet status updated.'));
    }
}
