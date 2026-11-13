<form class="news-comments-settings" method="POST" action="{{ route('admin.module-pages.news-comments.settings.update', ['adminPath' => $adminPath]) }}">
    @csrf
    @method('PUT')

    <div class="news-comments-settings-grid">
        <x-admin.card>
            <x-admin.card-heading
                :title="__('module-news-comments::messages.publication_title')"
                :description="__('module-news-comments::messages.settings_hint')"
            />

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
                    @foreach(['manual_review', 'automatic'] as $mode)
                        <option value="{{ $mode }}" @selected(old('publication_mode', $settings['publication_mode']) === $mode)>
                            {{ __('module-news-comments::messages.'.$mode) }}
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
        </x-admin.card>

        <x-admin.card>
            <x-admin.card-heading
                :title="__('module-news-comments::messages.limits_title')"
                :description="__('module-news-comments::messages.limits_hint')"
            />
            <div class="news-comments-limits">
                @foreach(\KaevCMS\Modules\NewsComments\Services\NewsCommentSettings::LIMITS as $field => [$default, $min, $max])
                    <x-admin.field
                        :for="'news_comments_'.$field"
                        :name="$field"
                        :label="__('module-news-comments::messages.'.$field)"
                        :hint="__('module-news-comments::messages.range', ['min' => format_number($min), 'max' => format_number($max)])"
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
        </x-admin.card>
    </div>

    @if($canManage)
        <div class="admin-actions-panel settings-actions">
            <x-admin.button type="submit" variant="primary">{{ __('module-news-comments::messages.save') }}</x-admin.button>
        </div>
    @else
        <p class="news-comments-read-only">{{ __('module-news-comments::messages.read_only') }}</p>
    @endif
</form>
