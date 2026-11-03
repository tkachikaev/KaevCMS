            <article class="referrals-rule-row" data-referral-rule-id="{{ $rule->id }}" data-referral-rule-level="{{ $rule->level }}">
                <span class="referrals-level-badge admin-num">{{ __('module-referrals::messages.level_badge', ['level' => format_number($rule->level)]) }}</span>
                <span class="referrals-item-icon" aria-hidden="true">
                    @if($itemDetails[$rule->id]['icon'] ?? null)<img src="{{ $itemDetails[$rule->id]['icon'] }}" width="36" height="36" alt="">@else<i>◇</i>@endif
                </span>
                <span class="referrals-row-main">
                    <strong>{{ $itemDetails[$rule->id]['name'] ?? __('module-referrals::messages.unknown_item') }}</strong>
                    <small class="admin-num">ID {{ $rule->item_id }} · × {{ format_number($rule->amount) }}</small>
                </span>
                @if($canManage)
                    <form method="POST" action="{{ route('admin.module-pages.referrals.rules.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'rule' => $rule->id]) }}" data-referrals-delete-rule data-confirm="{{ __('module-referrals::messages.delete_rule_confirm', ['level' => format_number($rule->level)]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="referrals-row-delete" aria-label="{{ __('module-referrals::messages.delete') }}" title="{{ __('module-referrals::messages.delete') }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>
                        </button>
                    </form>
                @endif
            </article>
