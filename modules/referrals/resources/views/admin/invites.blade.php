@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.invitation_journal'))
@section('description', __('module-referrals::messages.invites_help'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@include('module-referrals::admin.navigation')
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-referrals::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<p class="form-help">{{ __('module-referrals::messages.invites_purpose') }}</p>
<p>{{ __('module-referrals::messages.frozen_count', ['count' => format_number($frozenCount)]) }}</p>
<div class="admin-card-list content-list">
    @forelse($invites as $invite)
        <article class="referrals-invite-row admin-card-row">
            <div class="referrals-row-main">
                <strong>{{ __('module-referrals::messages.invitation_number', ['number' => format_number($invite->id)]) }}</strong>
                <p>{{ __('module-referrals::messages.inviter') }}:
                    @if($invite->inviter)<a wire:navigate href="{{ route('admin.users.show', ['adminPath' => $adminPath, 'user' => $invite->inviter]) }}">{{ $invite->inviter->name }}</a>@else — @endif
                    → {{ __('module-referrals::messages.invitee') }}:
                    @if($invite->invitee)<a wire:navigate href="{{ route('admin.users.show', ['adminPath' => $adminPath, 'user' => $invite->invitee]) }}">{{ $invite->invitee->name }}</a>@else — @endif
                </p>
                <div class="referrals-row-meta">
                    <span>{{ $invite->status === 'frozen' ? __('module-referrals::messages.frozen') : __('module-referrals::messages.active') }}</span>
                    <span>{{ $invite->accepted_at?->format('d.m.Y H:i') }}</span>
                    @if($invite->status === 'frozen')<span>{{ $invite->frozen_reason }}</span>@endif
                </div>
                @foreach($progressByInvite->get($invite->id, collect()) as $progress)
                    <div class="referrals-row-meta">
                        <span>{{ $progress->gameServer?->nameFor() ?? '—' }}</span>
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
                    <form class="referrals-freeze-form settings-form" method="POST" action="{{ route('admin.module-pages.referrals.invites.unfreeze', ['adminPath' => $adminPath, 'invite' => $invite->id]) }}">
                        @csrf @method('PATCH')
                        <x-admin.button type="submit">{{ __('module-referrals::messages.unfreeze') }}</x-admin.button>
                    </form>
                @else
                    <form class="referrals-freeze-form settings-form" method="POST" action="{{ route('admin.module-pages.referrals.invites.freeze', ['adminPath' => $adminPath, 'invite' => $invite->id]) }}">
                        @csrf @method('PATCH')
                        <x-admin.field :for="'freeze_reason_'.$invite->id" name="reason" :label="__('module-referrals::messages.freeze_reason')">
                            <input id="freeze_reason_{{ $invite->id }}" name="reason" type="text" minlength="5" maxlength="500" required>
                        </x-admin.field>
                        <x-admin.button type="submit">{{ __('module-referrals::messages.freeze') }}</x-admin.button>
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
