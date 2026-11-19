@extends('admin.layouts.panel')
@section('title', __('module-forum::messages.settings'))
@section('description', __('module-forum::messages.settings_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))

<div class="admin-section-stack">
    <x-admin.card narrow>
        <x-admin.card-heading
            :title="__('module-forum::messages.global_access_title')"
            :description="__('module-forum::messages.global_access_help')"
        />
        <form class="settings-form" method="POST" action="{{ route('admin.module-pages.forum.settings.update', ['adminPath' => $adminPath]) }}">
            @csrf
            @method('PUT')
            <x-admin.field
                for="forum_visibility"
                name="visibility"
                :label="__('module-forum::messages.global_visibility')"
                :hint="__('module-forum::messages.global_visibility_help')"
            >
                <select id="forum_visibility" name="visibility" required @if($errors->has('visibility')) aria-invalid="true" @endif>
                    @foreach(['all' => 'visibility_all', 'registered' => 'visibility_registered'] as $value => $key)
                        <option value="{{ $value }}" @selected(old('visibility', $settings->visibility) === $value)>{{ __('module-forum::messages.'.$key) }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field
                for="forum_links_after_days"
                name="links_after_days"
                :label="__('module-forum::messages.links_after_days')"
                :hint="__('module-forum::messages.links_after_days_help')"
            >
                <input id="forum_links_after_days" name="links_after_days" type="number" min="0" max="3650" step="1"
                       value="{{ old('links_after_days', $settings->links_after_days) }}" required
                       @if($errors->has('links_after_days')) aria-invalid="true" @endif>
            </x-admin.field>
            <div class="admin-actions-panel settings-actions">
                <x-admin.button type="submit" variant="primary">{{ __('module-forum::messages.save') }}</x-admin.button>
            </div>
        </form>
    </x-admin.card>

    <x-admin.card narrow>
        <x-admin.card-heading
            :title="__('module-forum::messages.moderators_title')"
            :description="__('module-forum::messages.moderators_help')"
        />
        <form class="admin-filter-bar" method="GET" action="{{ route('admin.module-pages.forum.settings', ['adminPath' => $adminPath]) }}">
            <x-admin.field for="forum_moderator_search" name="q" :label="__('module-forum::messages.moderator_search')">
                <input id="forum_moderator_search" name="q" type="search" maxlength="80"
                       placeholder="{{ __('module-forum::messages.moderator_search_placeholder') }}" value="{{ $search }}">
            </x-admin.field>
            <x-admin.button type="submit">{{ __('module-forum::messages.search') }}</x-admin.button>
        </form>

        @if($search !== '')
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>{{ __('module-forum::messages.player') }}</th>
                            <th>{{ __('module-forum::messages.email') }}</th>
                            <th>{{ __('module-forum::messages.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($candidates as $candidate)
                            <tr>
                                <td>{{ $candidate->publicName() }} <small class="admin-num">#{{ format_number($candidate->id) }}</small></td>
                                <td>{{ $candidate->email }}</td>
                                <td>
                                    @if(in_array((int) $candidate->id, $moderatorIds, true))
                                        <span class="status-badge status-badge-success">{{ __('module-forum::messages.moderator_assigned') }}</span>
                                    @else
                                        <form method="POST" action="{{ route('admin.module-pages.forum.moderators.store', ['adminPath' => $adminPath]) }}">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $candidate->id }}">
                                            <input type="hidden" name="q" value="{{ $search }}">
                                            <x-admin.button type="submit" variant="primary" compact>{{ __('module-forum::messages.moderator_assign') }}</x-admin.button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3">{{ __('module-forum::messages.moderator_search_empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <p class="admin-field-help">{{ __('module-forum::messages.moderator_search_prompt') }}</p>
        @endif

        <h3>{{ __('module-forum::messages.moderator_current') }} <span class="admin-num">({{ format_number($moderators->count()) }})</span></h3>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('module-forum::messages.player') }}</th>
                        <th>{{ __('module-forum::messages.email') }}</th>
                        <th>{{ __('module-forum::messages.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($moderators as $moderator)
                        <tr>
                            <td>
                                {{ $moderator->publicName() }} <small class="admin-num">#{{ format_number($moderator->id) }}</small>
                                @unless($moderator->is_active)
                                    <span class="status-badge">{{ __('module-forum::messages.moderator_inactive') }}</span>
                                @endunless
                            </td>
                            <td>{{ $moderator->email }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.module-pages.forum.moderators.destroy', ['adminPath' => $adminPath, 'user' => $moderator->id]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="q" value="{{ $search }}">
                                    <x-admin.button type="submit" compact>{{ __('module-forum::messages.moderator_remove') }}</x-admin.button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3">{{ __('module-forum::messages.moderator_list_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <p>
        <x-admin.button wire:navigate :href="route('modules.forum.index')">
            {{ __('module-forum::messages.back_to_forum') }}
        </x-admin.button>
    </p>
</div>
@endsection
