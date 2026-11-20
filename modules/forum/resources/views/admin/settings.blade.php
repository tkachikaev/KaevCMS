@extends('admin.layouts.panel')
@section('title', __('module-forum::messages.settings'))
@section('description', __('module-forum::messages.settings_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))

<div class="forum-settings">
    <x-admin.card class="forum-settings-card">
        <x-admin.card-heading
            :title="__('module-forum::messages.global_access_title')"
            :description="__('module-forum::messages.global_access_help')"
        >
            <x-slot:actions>
                <a wire:navigate class="forum-settings-open" href="{{ route('modules.forum.index') }}">{{ __('module-forum::messages.back_to_forum') }}</a>
            </x-slot:actions>
        </x-admin.card-heading>

        <form class="forum-settings-form" method="POST" action="{{ route('admin.module-pages.forum.settings.update', ['adminPath' => $adminPath]) }}">
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

            <div @class(['admin-field', 'has-error' => $errors->has('links_after_days')])>
                <label class="admin-field-label" for="forum_links_after_days">{{ __('module-forum::messages.links_after_days') }}</label>
                <div class="forum-settings-unit">
                    <input id="forum_links_after_days" name="links_after_days" type="number" min="0" max="3650" step="1" inputmode="numeric"
                           value="{{ old('links_after_days', $settings->links_after_days) }}" required
                           aria-describedby="forum_links_after_days_unit forum_links_after_days_help"
                           @if($errors->has('links_after_days')) aria-invalid="true" @endif>
                    <span id="forum_links_after_days_unit">{{ __('module-forum::messages.links_after_days_unit') }}</span>
                </div>
                @error('links_after_days')<small class="admin-field-error" role="alert">{{ $message }}</small>@enderror
                <small id="forum_links_after_days_help" class="admin-field-help">{{ __('module-forum::messages.links_after_days_help') }}</small>
            </div>

            <div class="forum-settings-actions">
                <x-admin.button type="submit" variant="primary">{{ __('module-forum::messages.save') }}</x-admin.button>
            </div>
        </form>
    </x-admin.card>

    <x-admin.card class="forum-settings-card">
        <x-admin.card-heading
            :title="__('module-forum::messages.moderators_title')"
            :description="__('module-forum::messages.moderators_help')"
        />

        <form class="forum-moderator-search" method="GET" action="{{ route('admin.module-pages.forum.settings', ['adminPath' => $adminPath]) }}" role="search">
            <label class="forum-settings-hidden" for="forum_moderator_search">{{ __('module-forum::messages.moderator_search') }}</label>
            <input id="forum_moderator_search" name="q" type="search" maxlength="80"
                   placeholder="{{ __('module-forum::messages.moderator_search_placeholder') }}" value="{{ $search }}">
            <x-admin.button type="submit">{{ __('module-forum::messages.search') }}</x-admin.button>
            @if($search !== '')
                <x-admin.button wire:navigate :href="route('admin.module-pages.forum.settings', ['adminPath' => $adminPath])">{{ __('module-forum::messages.search_reset') }}</x-admin.button>
            @endif
        </form>

        @if($search !== '')
            <section class="forum-people" aria-labelledby="forum-search-results">
                <h3 id="forum-search-results">{{ __('module-forum::messages.moderator_search_results') }}</h3>
                @forelse($candidates as $candidate)
                    <div class="forum-person">
                        <div class="forum-person-main">
                            <strong>{{ $candidate->publicName() }} <span class="admin-num">#{{ format_number($candidate->id) }}</span></strong>
                            <small>{{ $candidate->email }}</small>
                        </div>
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
                    </div>
                @empty
                    <p class="forum-people-empty">{{ __('module-forum::messages.moderator_search_empty') }}</p>
                @endforelse
            </section>
        @else
            <p class="admin-field-help forum-moderator-prompt">{{ __('module-forum::messages.moderator_search_prompt') }}</p>
        @endif

        <section class="forum-people" aria-labelledby="forum-moderators-current">
            <h3 id="forum-moderators-current">{{ __('module-forum::messages.moderator_current') }} <span class="admin-num">{{ format_number($moderators->count()) }}</span></h3>
            @forelse($moderators as $moderator)
                <div class="forum-person">
                    <div class="forum-person-main">
                        <strong>{{ $moderator->publicName() }} <span class="admin-num">#{{ format_number($moderator->id) }}</span></strong>
                        <small>{{ $moderator->email }}</small>
                    </div>
                    @unless($moderator->is_active)
                        <span class="status-badge status-badge-warning">{{ __('module-forum::messages.moderator_inactive') }}</span>
                    @endunless
                    <x-admin.button compact
                        data-forum-moderator-remove
                        data-url="{{ route('admin.module-pages.forum.moderators.destroy', ['adminPath' => $adminPath, 'user' => $moderator->id]) }}"
                        data-name="{{ $moderator->publicName() }}">{{ __('module-forum::messages.moderator_remove') }}</x-admin.button>
                </div>
            @empty
                <p class="forum-people-empty">{{ __('module-forum::messages.moderator_list_empty') }}</p>
            @endforelse
        </section>
    </x-admin.card>
</div>

<dialog class="confirm-dialog" data-forum-moderator-dialog aria-labelledby="forum-moderator-remove-title">
    <div class="confirm-dialog-card">
        <div class="confirm-dialog-copy">
            <span class="confirm-dialog-mark" aria-hidden="true">!</span>
            <div>
                <h2 id="forum-moderator-remove-title">{{ __('module-forum::messages.moderator_remove_title') }}</h2>
                <p>{{ __('module-forum::messages.moderator_remove_text') }} <strong data-forum-moderator-name></strong></p>
            </div>
        </div>
        <div class="confirm-dialog-actions">
            <button class="button button-secondary" type="button" data-forum-moderator-cancel>{{ __('module-forum::messages.cancel') }}</button>
            <form method="POST" action="" data-forum-moderator-form>
                @csrf
                @method('DELETE')
                <input type="hidden" name="q" value="{{ $search }}">
                <button class="button button-danger" type="submit">{{ __('module-forum::messages.moderator_remove_confirm') }}</button>
            </form>
        </div>
    </div>
</dialog>
@endsection

@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/forum/admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush

@push('scripts')
<script src="{{ asset('assets/modules/forum/admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
