@extends('admin.layouts.panel')
@section('title', __('Wallet of :name', ['name' => $user->name]))
@section('description', __('Manual adjustments are auditable and never overwrite the balance directly.'))
@section('content')
<div class="admin-page-toolbar"><a wire:navigate class="button button-secondary" href="{{ route('admin.wallets.index') }}">← {{ __('Back to wallets') }}</a><a wire:navigate class="button button-secondary" href="{{ route('admin.users.show', $user) }}">{{ __('User details') }}</a></div>
<div class="admin-overview audit-summary">
    <div class="admin-overview-stat"><span>{{ __('User') }}</span><strong>{{ $user->name }}</strong><small>{{ $user->email }} · ID {{ $user->id }}</small></div>
    <div class="admin-overview-stat"><span>{{ __('Balance') }}</span><strong>{{ number_format($wallet?->balance ?? 0, 0, '.', ' ') }} @if($appearance['icon_url'])<img src="{{ $appearance['icon_url'] }}" width="24" height="24" alt="">@else 🪙 @endif</strong></div>
    <div class="admin-overview-stat"><span>{{ __('Wallet status') }}</span><strong>{{ $wallet?->is_blocked ? __('Blocked') : __('Available') }}</strong></div>
</div>
@if($canManageWallet)
    <section class="form-card">
        <h2>{{ __('Wallet spending restriction') }}</h2>
        <p>{{ __('Blocking prevents player spending, including Shop. Incoming credits and authorized manual administrator adjustments remain possible. Unlocking does not change the balance.') }}</p>
        @if($errors->has('status_change'))<div class="notice notice-danger" role="alert">{{ $errors->first('status_change') }}</div>@endif
        <form action="{{ route('admin.wallets.status', $user) }}" method="POST" class="settings-form" data-testid="admin-wallet-status-form">
            @csrf
            <input name="operation_key" type="hidden" value="{{ $operationKey }}">
            <input name="action" type="hidden" value="{{ $wallet?->is_blocked ? 'unblock' : 'block' }}">
            <div class="form-group"><label for="wallet-status-reason">{{ __('Reason for wallet status change') }}</label><textarea id="wallet-status-reason" name="reason" minlength="3" maxlength="190" rows="2" required>{{ old('reason') }}</textarea>@error('reason')<small class="field-error">{{ $message }}</small>@enderror</div>
            <label class="settings-remove-row"><input name="confirm_status_change" value="1" type="checkbox" required><span>{{ __('I confirm changing the spending restriction for this wallet.') }}</span></label>
            @error('confirm_status_change')<small class="field-error">{{ $message }}</small>@enderror
            <div class="admin-actions-panel settings-actions"><button type="submit" class="button {{ $wallet?->is_blocked ? 'button-secondary' : 'button-danger' }}">{{ $wallet?->is_blocked ? __('Unblock wallet') : __('Block wallet') }}</button></div>
        </form>
    </section>

    <section class="form-card">
        <h2>{{ __('Manual wallet adjustment') }}</h2>
        <p>{{ __('All operations require a reason and are saved with administrator identity in the immutable journal. Negative balances are prohibited.') }}</p>
        @if($errors->has('adjustment'))<div class="notice notice-danger" role="alert">{{ $errors->first('adjustment') }}</div>@endif
        <form action="{{ route('admin.wallets.adjust', $user) }}" method="POST" class="settings-form" data-testid="admin-wallet-adjustment-form">
            @csrf
            <input name="operation_key" type="hidden" value="{{ $operationKey }}">
            <div class="settings-grid">
                <div class="form-group"><label for="wallet-operation">{{ __('Operation') }}</label><select name="operation" id="wallet-operation" required><option value="credit" @selected(old('operation') === 'credit')>{{ __('Credit coins') }}</option><option value="debit" @selected(old('operation') === 'debit')>{{ __('Debit coins') }}</option></select>@error('operation')<small class="field-error">{{ $message }}</small>@enderror</div>
                <div class="form-group"><label for="wallet-amount">{{ __('Amount of coins') }}</label><input id="wallet-amount" name="amount" type="text" inputmode="numeric" pattern="[1-9][0-9]*" maxlength="19" autocomplete="off" required value="{{ old('amount') }}">@error('amount')<small class="field-error">{{ $message }}</small>@enderror</div>
            </div>
            <div class="form-group"><label for="wallet-reason">{{ __('Reason for adjustment') }}</label><textarea id="wallet-reason" name="reason" minlength="3" maxlength="190" rows="3" required>{{ old('reason') }}</textarea>@error('reason')<small class="field-error">{{ $message }}</small>@enderror</div>
            <label class="settings-remove-row"><input name="confirm_adjustment" value="1" type="checkbox" required><span>{{ __('I confirm this manual adjustment to the selected user wallet.') }}</span></label>
            @error('confirm_adjustment')<small class="field-error">{{ $message }}</small>@enderror
            <div class="admin-actions-panel settings-actions"><button type="submit" class="button button-primary">{{ __('Apply wallet adjustment') }}</button></div>
        </form>
    </section>
@else
    <p class="admin-overview-copy">{{ __('Your role permits viewing wallets but not changing balances.') }}</p>
@endif
<section class="form-card">
    <h2>{{ __('Wallet operation history') }}</h2>
    @if($entries->isEmpty())
        <div class="admin-empty-state">{{ __('No wallet operations recorded.') }}</div>
    @else
        <div class="admin-table-wrap audit-table-wrap"><table class="admin-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Operation') }}</th><th>{{ __('Amount of coins') }}</th><th>{{ __('Balance') }}</th><th>{{ __('Source') }}</th><th>{{ __('Reason for adjustment') }}</th><th>{{ __('Actor') }}</th></tr></thead><tbody>
        @foreach($entries as $entry)
            <tr data-testid="admin-wallet-entry">
                <td data-label="{{ __('Date') }}">{{ $entry->occurred_at?->format('d.m.Y H:i:s') }}</td>
                <td data-label="{{ __('Operation') }}">{{ $entry->direction === 'credit' ? __('Credit coins') : __('Debit coins') }}</td>
                <td data-label="{{ __('Amount of coins') }}"><strong>{{ $entry->direction === 'credit' ? '+' : '−' }}{{ number_format($entry->amount, 0, '.', ' ') }}</strong></td>
                <td data-label="{{ __('Balance') }}">{{ number_format($entry->balance_after, 0, '.', ' ') }}</td>
                <td data-label="{{ __('Source') }}">{{ $entry->source }}</td>
                <td data-label="{{ __('Reason for adjustment') }}">{{ $entry->source === 'manual' ? ($entry->reason ?: '—') : '—' }}</td>
                <td data-label="{{ __('Actor') }}">{{ $entry->actor_admin_id ? __('Administrator ID :id', ['id' => $entry->actor_admin_id]) : '—' }}</td>
            </tr>
        @endforeach
        </tbody></table></div>
        <x-admin.pagination :paginator="$entries" :aria-label="__('Wallet history navigation')" :pages-label="__('Pages')" :previous-label="__('Back')" :next-label="__('Next')" numbered />
    @endif
</section>
<section class="form-card">
    <h2>{{ __('Wallet status history') }}</h2>
    @if($statusEvents->isEmpty())
        <div class="admin-empty-state">{{ __('No wallet status changes recorded.') }}</div>
    @else
        <div class="admin-table-wrap audit-table-wrap"><table class="admin-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Wallet status') }}</th><th>{{ __('Reason for wallet status change') }}</th><th>{{ __('Actor') }}</th></tr></thead><tbody>
        @foreach($statusEvents as $event)
            <tr data-testid="admin-wallet-status-event">
                <td data-label="{{ __('Date') }}">{{ $event->occurred_at?->format('d.m.Y H:i:s') }}</td>
                <td data-label="{{ __('Wallet status') }}">{{ $event->is_blocked ? __('Blocked') : __('Available') }}</td>
                <td data-label="{{ __('Reason for wallet status change') }}">{{ $event->reason }}</td>
                <td data-label="{{ __('Actor') }}">{{ __('Administrator ID :id', ['id' => $event->actor_admin_id]) }}</td>
            </tr>
        @endforeach
        </tbody></table></div>
        <x-admin.pagination :paginator="$statusEvents" :aria-label="__('Wallet status history')" :pages-label="__('Pages')" :previous-label="__('Back')" :next-label="__('Next')" numbered />
    @endif
</section>
@endsection
