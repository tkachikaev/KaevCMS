<div class="forum-form-grid forum-permissions" data-forum-permissions>
    @foreach(['view_rule' => \KaevCMS\Modules\Forum\Services\ForumPermissions::VIEWS,
              'topic_rule' => \KaevCMS\Modules\Forum\Services\ForumPermissions::WRITES,
              'reply_rule' => \KaevCMS\Modules\Forum\Services\ForumPermissions::WRITES] as $field => $options)
        <label>
            <span>{{ __('module-forum::messages.'.$field) }}</span>
            <select name="{{ $field }}" required>
                @foreach($options as $option)
                    <option value="{{ $option }}" @selected(($item?->$field ?? ($field === 'view_rule' ? 'all' : 'registered')) === $option)>
                        {{ __('module-forum::messages.rule_'.$option) }}
                    </option>
                @endforeach
            </select>
        </label>
    @endforeach
</div>
<p class="forum-help">{{ __('module-forum::messages.permission_hint') }}</p>
