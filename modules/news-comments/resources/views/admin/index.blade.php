@extends('admin.layouts.panel')
@section('title', __('module-news-comments::messages.admin_title'))
@section('description', __('module-news-comments::messages.admin_description'))
@section('content')
@if(session('status'))
    <div class="notice notice-success" role="status">{{ session('status') }}</div>
@endif
@if($errors->any())
    <div class="notice notice-error" role="alert">
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

@if($settings === null)
    <div class="notice notice-error" role="alert">{{ __('module-news-comments::messages.unavailable') }}</div>
@else
    <x-admin.card narrow>
        <x-admin.card-heading
            :title="__('module-news-comments::messages.settings_title')"
            :description="__('module-news-comments::messages.settings_hint')"
        />
        <form class="settings-form" method="POST" action="{{ route('admin.module-pages.news-comments.settings.update', ['adminPath' => $adminPath]) }}">
            @csrf
            @method('PUT')

            <x-admin.toggle
                id="news_comments_enabled"
                name="enabled"
                :label="__('module-news-comments::messages.enabled')"
                :hint="__('module-news-comments::messages.enabled_hint')"
                :checked="(bool) old('enabled', $settings['enabled'])"
                :disabled="! $canManage"
            />

            <x-admin.field
                for="news_comments_publication_mode"
                name="publication_mode"
                :label="__('module-news-comments::messages.publication_mode')"
                :hint="__('module-news-comments::messages.publication_hint')"
            >
                <select id="news_comments_publication_mode" name="publication_mode" @disabled(! $canManage)>
                    @foreach([
                        'manual_review' => 'manual_review',
                        'automatic' => 'automatic',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(old('publication_mode', $settings['publication_mode']) === $value)>
                            {{ __('module-news-comments::messages.'.$label) }}
                        </option>
                    @endforeach
                </select>
            </x-admin.field>

            <x-admin.toggle
                id="news_comments_allow_user_editing"
                name="allow_user_editing"
                :label="__('module-news-comments::messages.allow_user_editing')"
                :hint="__('module-news-comments::messages.editing_hint')"
                :checked="(bool) old('allow_user_editing', $settings['allow_user_editing'])"
                :disabled="! $canManage"
            />
            <x-admin.toggle
                id="news_comments_allow_editor_moderation"
                name="allow_editor_moderation"
                :label="__('module-news-comments::messages.allow_editor_moderation')"
                :hint="__('module-news-comments::messages.editor_hint')"
                :checked="(bool) old('allow_editor_moderation', $settings['allow_editor_moderation'])"
                :disabled="! $canManage"
            />

            <x-admin.card-heading
                :title="__('module-news-comments::messages.limits_title')"
                :description="__('module-news-comments::messages.limits_hint')"
            />
            <div class="settings-grid two-columns">
                @foreach(\KaevCMS\Modules\NewsComments\Services\NewsCommentSettings::LIMITS as $field => [$default, $min, $max])
                    <x-admin.field
                        :for="'news_comments_'.$field"
                        :name="$field"
                        :label="__('module-news-comments::messages.'.$field)"
                        :hint="__('module-news-comments::messages.range', ['min' => $min, 'max' => $max])"
                    >
                        <input
                            id="news_comments_{{ $field }}"
                            name="{{ $field }}"
                            type="number"
                            min="{{ $min }}"
                            max="{{ $max }}"
                            value="{{ old($field, $settings[$field]) }}"
                            required
                            @disabled(! $canManage)
                            @if($errors->has($field)) aria-invalid="true" @endif
                        >
                    </x-admin.field>
                @endforeach
            </div>
            @if($canManage)
                <div class="admin-actions-panel settings-actions">
                    <x-admin.button type="submit" variant="primary">{{ __('module-news-comments::messages.save') }}</x-admin.button>
                </div>
            @else
                <p>{{ __('module-news-comments::messages.read_only') }}</p>
            @endif
        </form>
    </x-admin.card>

    <x-admin.card>
        <x-admin.card-heading
            :title="__('module-news-comments::messages.news_title')"
            :description="__('module-news-comments::messages.news_hint')"
        />
        @forelse($news as $article)
            <div class="admin-card-list content-list">
                <div class="referrals-server-row">
                    <strong>{{ $article->titleFor() }}</strong>
                    <form method="POST" action="{{ route('admin.module-pages.news-comments.news.update', ['adminPath' => $adminPath, 'news' => $article->id, 'page' => $news->currentPage()]) }}">
                        @csrf
                        @method('PUT')
                        <label class="admin-field-label" for="news_policy_{{ $article->id }}">{{ __('module-news-comments::messages.news_policy') }}</label>
                        <select id="news_policy_{{ $article->id }}" name="policy" @disabled(! $canManage)>
                            @foreach(['inherit' => 'inherit', 'enabled' => 'policy_enabled', 'disabled' => 'policy_disabled'] as $value => $translation)
                                <option value="{{ $value }}" @selected(($policies[$article->id] ?? 'inherit') === $value)>
                                    {{ __('module-news-comments::messages.'.$translation) }}
                                </option>
                            @endforeach
                        </select>
                        @if($canManage)
                            <x-admin.button type="submit">{{ __('module-news-comments::messages.save_news') }}</x-admin.button>
                        @endif
                    </form>
                </div>
            </div>
        @empty
            <div class="admin-empty-state empty-state">{{ __('module-news-comments::messages.news_empty') }}</div>
        @endforelse
        {{ $news->links() }}
    </x-admin.card>
@endif
@endsection
