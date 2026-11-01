            <article class="admin-card-row referrals-rule-row" data-referral-rule-id="{{ $rule->id }}" data-referral-rule-level="{{ $rule->level }}">
                <div class="referrals-item-icon">
                    @if($itemDetails[$rule->id]['icon'] ?? null)<img src="{{ $itemDetails[$rule->id]['icon'] }}" width="40" height="40" alt="">@endif
                </div>
                <div class="referrals-row-main">
                    <strong class="content-row-title">{{ __('module-referrals::messages.level') }} {{ format_number($rule->level) }}</strong>
                    <p>{{ $itemDetails[$rule->id]['name'] ?? __('module-referrals::messages.unknown_item') }}</p>
                    <div class="referrals-row-meta"><span>ID {{ format_number($rule->item_id) }}</span><span>× {{ format_number($rule->amount) }}</span></div>
                </div>
                @if($canManage)
                    <form method="POST" action="{{ route('admin.module-pages.referrals.rules.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'rule' => $rule->id]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="button button-secondary">{{ __('module-referrals::messages.delete') }}</button>
                    </form>
                @endif
            </article>
