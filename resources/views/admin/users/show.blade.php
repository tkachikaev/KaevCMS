@extends('admin.layouts.panel')
@section('title', __('User :name', ['name' => $user->name]))
@section('description', __('Website account details and recent related audit events.'))
@section('content')
<div class="admin-page-toolbar user-detail-toolbar"><a wire:navigate class="button button-secondary" href="{{ route('admin.users.index') }}">← {{ __('Back to users') }}</a></div>
<div class="user-detail-grid">
    <div class="user-detail-main">
        <section class="form-card user-profile-card">
            <div class="user-profile-heading">
                <div><span class="user-profile-eyebrow">{{ __('CMS user') }}</span><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p></div>
                <div class="user-profile-badges">
                    <span @class(['status-badge','status-badge-success' => $user->is_active,'status-badge-muted' => ! $user->is_active])>{{ $user->is_active ? __('Active') : __('Disabled') }}</span>
                    <span @class(['status-badge','status-badge-success' => $user->hasVerifiedEmail(),'status-badge-warning' => ! $user->hasVerifiedEmail()])>{{ $user->hasVerifiedEmail() ? __('Email verified') : __('Email not verified') }}</span>
                </div>
            </div>
            <dl class="user-definition-list">
                <div><dt>ID</dt><dd>{{ $user->id }}</dd></div><div><dt>{{ __('Username') }}</dt><dd>{{ $user->name }}</dd></div><div><dt>Email</dt><dd>{{ $user->email }}</dd></div>
                <div><dt>{{ __('Registration date') }}</dt><dd>{{ $user->created_at?->format('d.m.Y H:i:s') ?? '—' }}</dd></div>
                <div><dt>{{ __('Email verification') }}</dt><dd>{{ $user->email_verified_at?->format('d.m.Y H:i:s') ?? __('Not verified') }}</dd></div>
                <div><dt>{{ __('Last successful sign in') }}</dt><dd>{{ $user->last_login_at?->format('d.m.Y H:i:s') ?? __('Never') }}</dd></div>
                <div><dt>{{ __('Preferred language') }}</dt><dd>{{ $user->locale }}</dd></div>
            </dl>
        </section>
        <section class="form-card user-activity-card">
            <div class="admin-card-heading user-card-heading"><div><h2>{{ __('Recent activity') }}</h2><p>{{ __('Up to 25 recent records where the user is the actor or target.') }}</p></div><a wire:navigate class="button button-secondary" href="{{ route('admin.logs.index', ['category' => 'user']) }}">{{ __('Open audit log') }}</a></div>
            @if ($activity->isEmpty())
                <div class="admin-empty-state user-activity-empty">{{ __('No related audit records yet.') }}</div>
            @else
                <div class="admin-table-wrap user-activity-table-wrap"><table class="admin-table user-activity-table"><thead><tr><th>{{ __('Date') }}</th><th>{{ __('Action') }}</th><th>{{ __('Result') }}</th><th>{{ __('IP address') }}</th><th>{{ __('Browser') }}</th></tr></thead><tbody>
                    @foreach($activity as $event)<tr><td><strong>{{ $event->created_at?->format('d.m.Y') }}</strong><span>{{ $event->created_at?->format('H:i:s') }}</span></td><td><a wire:navigate href="{{ route('admin.logs.show',$event) }}">{{ $event->actionLabel() }}</a><code>{{ $event->action }}</code></td><td><span @class(['status-badge','status-badge-success'=>$event->result==='success','status-badge-danger'=>$event->result==='failed'])>{{ $event->resultLabel() }}</span></td><td class="user-activity-monospace">{{ $event->ip_address ?: '—' }}</td><td class="user-agent-cell" title="{{ $event->user_agent ?: '' }}">{{ $event->user_agent ?: '—' }}</td></tr>@endforeach
                </tbody></table></div>
            @endif
        </section>
    </div>
    <aside class="user-detail-side">
        <section class="form-card user-action-card">
            <h2>{{ __('Website access') }}</h2>
            @if($user->is_active)
                <p>{{ __('Disabling ends saved sessions and blocks new sign-ins. User data and history are preserved.') }}</p>
                <form method="POST" action="{{ route('admin.users.status',$user) }}" data-user-status-form data-user-status-confirm="{{ __('Disable user :name?', ['name'=>$user->name]) }}">@csrf
                    @method('PATCH')<input type="hidden" name="is_active" value="0"><button class="button button-danger" type="submit">{{ __('Disable user') }}</button></form>
            @else
                <p>{{ __('The user cannot access the account area. Enabling restores access without changing the password.') }}</p>
                <form method="POST" action="{{ route('admin.users.status',$user) }}">@csrf
                    @method('PATCH')<input type="hidden" name="is_active" value="1"><button class="button button-primary" type="submit">{{ __('Enable user') }}</button></form>
            @endif
        </section>
        <section class="form-card user-action-card">
            <h2>{{ __('Mail actions') }}</h2><p>{{ __('Passwords and service tokens are never shown. A standard one-time link is sent for password changes.') }}</p>
            @if(!$mailReady)<div class="notice notice-warning user-mail-warning"><p>{{ __('SMTP is not configured or verified. Mail actions are temporarily unavailable.') }}</p></div>@endif
            <div class="user-mail-actions">
                @if(!$user->hasVerifiedEmail())<form method="POST" action="{{ route('admin.users.verification',$user) }}">@csrf<button class="button button-secondary" type="submit" @disabled(!$mailReady)>{{ __('Send email verification') }}</button></form>@else<span class="user-action-note">{{ __('Email is already verified.') }}</span>@endif
                <form method="POST" action="{{ route('admin.users.password-reset',$user) }}">@csrf<button class="button button-secondary" type="submit" @disabled(!$mailReady)>{{ __('Send password reset') }}</button></form>
            </div>
        </section>
        <section class="form-card user-action-card user-game-accounts-card">
            <div class="user-game-accounts-heading"><h2>{{ __('Game data') }}</h2><span class="status-badge status-badge-muted">{{ $user->gameAccounts->count() }}</span></div>
            @if($user->gameAccounts->isEmpty())
                <p>{{ __('The user has no linked game accounts yet.') }}</p>
            @else
                <div class="user-game-accounts-list">
                    @foreach($user->gameAccounts as $gameAccount)
                        <div><strong>{{ $gameAccount->game_login }}</strong><span>{{ $gameAccount->registrationGameServer?->nameFor() ?? $gameAccount->loginServer->name }}</span></div>
                    @endforeach
                </div>
            @endif
        </section>
    </aside>
</div>
@endsection
@push('scripts')<script src="{{ asset('assets/admin/js/users.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>@endpush
