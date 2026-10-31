@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.admin_title'))
@section('description', __('module-referrals::messages.admin_description'))
@section('content')
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.servers') }}</span><strong class="admin-num">{{ format_number($serverCount) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.configured_servers') }}</span><strong class="admin-num">{{ format_number($configuredServerCount) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.invites') }}</span><strong class="admin-num">{{ format_number($inviteCount) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.grants') }}</span><strong class="admin-num">{{ format_number($grantCount) }}</strong></div>
</div>
<div class="admin-empty-state empty-state">
    <h2>{{ __('module-referrals::messages.not_configured_title') }}</h2>
    <p>{{ __('module-referrals::messages.not_configured_description') }}</p>
</div>
@endsection
