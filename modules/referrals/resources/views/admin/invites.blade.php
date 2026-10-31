@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.invitation_journal'))
@section('description', __('module-referrals::messages.invites_help'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<p><a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.back') }}</a></p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-referrals::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<p>{{ __('module-referrals::messages.frozen_count', ['count' => format_number($frozenCount)]) }}</p>
<div class="admin-card-list content-list">
    @forelse($invites as $invite)
        <article class="admin-card-row content-row">
            <div class="content-row-main">
                <strong class="content-row-title">#{{ format_number($invite->id) }}</strong>
                <p>{{ __('module-referrals::messages.inviter_id') }}: #{{ format_number($invite->inviter_user_id) }} · {{ __('module-referrals::messages.invitee_id') }}: #{{ format_number($invite->invitee_user_id) }}</p>
                <div class="content-row-meta">
                    <span>{{ $invite->status === 'frozen' ? __('module-referrals::messages.frozen') : __('module-referrals::messages.active') }}</span>
                    <span>{{ $invite->accepted_at?->format('d.m.Y H:i') }}</span>
                    @if($invite->status === 'frozen')<span>{{ $invite->frozen_reason }}</span>@endif
                </div>
                @foreach($progressByInvite->get($invite->id, collect()) as $progress)
                    <div class="content-row-meta">
                        <span>{{ __('module-referrals::messages.progress_server', ['id' => format_number($progress->game_server_id)]) }}</span>
                        <span>{{ __('module-referrals::messages.progress_max_level', ['level' => format_number($progress->max_seen_level)]) }}</span>
                        <span>{{ __('module-referrals::messages.progress_state_'.$progress->status) }}</span>
                        @if($progress->last_error_code)
                            <span>{{ __('module-referrals::messages.scan_error_'.$progress->last_error_code) }}</span>
                        @endif
                        @if($progress->next_check_at)
                            <span>{{ __('module-referrals::messages.progress_next_check', ['date' => $progress->next_check_at->format('d.m.Y H:i')]) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            @if($canManage)
                @if($invite->status === 'frozen')
                    <form method="POST" action="{{ route('admin.module-pages.referrals.invites.unfreeze', ['adminPath' => $adminPath, 'invite' => $invite->id]) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="button button-secondary">{{ __('module-referrals::messages.unfreeze') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.module-pages.referrals.invites.freeze', ['adminPath' => $adminPath, 'invite' => $invite->id]) }}">
                        @csrf @method('PATCH')
                        <label class="admin-field-label" for="freeze_reason_{{ $invite->id }}">{{ __('module-referrals::messages.freeze_reason') }}</label>
                        <input id="freeze_reason_{{ $invite->id }}" name="reason" type="text" minlength="5" maxlength="500" required>
                        <button type="submit" class="button button-secondary">{{ __('module-referrals::messages.freeze') }}</button>
                    </form>
                @endif
            @endif
        </article>
    @empty
        <div class="admin-empty-state empty-state"><p>{{ __('module-referrals::messages.no_invites') }}</p></div>
    @endforelse
</div>
{{ $invites->links() }}
@endsection
