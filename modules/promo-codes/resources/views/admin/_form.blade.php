@inject('gameItemCatalog', 'App\Services\GameAssets\GameItemCatalog')
@php
    $adminPath = request()->route('adminPath');
    $rows = array_values(old('rewards', $rewardRows));
    if ($rows === []) {
        $rows = [['item_id' => '', 'amount' => '']];
    }
@endphp
@csrf
@if($promoCode->exists)
    @method('PUT')
@endif

<div class="editor-grid">
    <div class="editor-main">
        <section class="form-card">
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('module-promo-codes::messages.main_settings') }}</h2>
                    <p>{{ __('module-promo-codes::messages.main_settings_help') }}</p>
                </div>
            </div>

            <div class="form-group">
                <label for="code">{{ __('module-promo-codes::messages.code') }}</label>
                <input id="code" name="code" type="text" minlength="4" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{3,63}" value="{{ old('code', $promoCode->code) }}" autocomplete="off" required @disabled(! $canManage)>
                <small>{{ __('module-promo-codes::messages.code_help') }}</small>
            </div>

            <div class="form-group">
                <label for="game_server_id">{{ __('module-promo-codes::messages.game_server') }}</label>
                <select id="game_server_id" name="game_server_id" required @disabled(! $canManage)>
                    <option value="">{{ __('module-promo-codes::messages.select_server') }}</option>
                    @foreach($gameServers as $server)
                        <option value="{{ $server->id }}" @selected((int) old('game_server_id', $promoCode->game_server_id) === $server->id)>{{ $server->nameFor() }}</option>
                    @endforeach
                </select>
                <small>{{ __('module-promo-codes::messages.server_help') }}</small>
            </div>

            <div class="form-grid two-columns">
                <div class="form-group">
                    <label for="starts_at">{{ __('module-promo-codes::messages.starts_at') }}</label>
                    <input id="starts_at" name="starts_at" type="datetime-local" value="{{ old('starts_at', $promoCode->starts_at?->format('Y-m-d\\TH:i')) }}" @disabled(! $canManage)>
                    <small>{{ __('module-promo-codes::messages.starts_at_help') }}</small>
                </div>
                <div class="form-group">
                    <label for="ends_at">{{ __('module-promo-codes::messages.ends_at') }}</label>
                    <input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at', $promoCode->ends_at?->format('Y-m-d\\TH:i')) }}" @disabled(! $canManage)>
                    <small>{{ __('module-promo-codes::messages.ends_at_help') }}</small>
                </div>
            </div>
        </section>

        <section
            class="form-card"
            data-promo-rewards-editor
            data-max-rows="100"
            data-limit-message="{{ __('module-promo-codes::messages.reward_limit_reached') }}"
        >
            <div class="settings-card-heading">
                <div>
                    <h2>{{ __('module-promo-codes::messages.rewards') }}</h2>
                    <p>{{ __('module-promo-codes::messages.rewards_help') }}</p>
                </div>
            </div>

            <div class="promo-reward-list" data-promo-reward-list>
                @foreach($rows as $index => $row)
                    <div class="promo-reward-row" data-promo-reward-row>
                        <div class="form-group">
                            <label for="reward_item_{{ $index }}">{{ __('module-promo-codes::messages.item_id') }}</label>
                            <input
                                id="reward_item_{{ $index }}"
                                name="rewards[{{ $index }}][item_id]"
                                type="number"
                                min="1"
                                step="1"
                                value="{{ is_array($row) ? ($row['item_id'] ?? '') : '' }}"
                                inputmode="numeric"
                                data-promo-reward-item
                                @disabled(! $canManage)
                            >
                            @php($previewItemId = (int) (is_array($row) ? ($row['item_id'] ?? 0) : 0))
                            @php($previewServerId = (int) old('game_server_id', $promoCode->game_server_id ?? 0))
                            @if($previewItemId > 0 && ($previewItemName = $gameItemCatalog->knownName($previewServerId, $previewItemId)))
                                <small class="promo-reward-name-preview">{{ $previewItemName }}</small>
                            @endif
                        </div>
                        <div class="form-group">
                            <label for="reward_amount_{{ $index }}">{{ __('module-promo-codes::messages.amount') }}</label>
                            <input
                                id="reward_amount_{{ $index }}"
                                name="rewards[{{ $index }}][amount]"
                                type="number"
                                min="1"
                                step="1"
                                value="{{ is_array($row) ? ($row['amount'] ?? '') : '' }}"
                                inputmode="numeric"
                                data-promo-reward-amount
                                @disabled(! $canManage)
                            >
                        </div>
                        @if($canManage)
                            <button
                                class="button button-secondary promo-reward-remove"
                                type="button"
                                data-promo-reward-remove
                                aria-label="{{ __('module-promo-codes::messages.remove_reward') }}"
                                title="{{ __('module-promo-codes::messages.remove_reward') }}"
                                @if(count($rows) <= 1) hidden @endif
                            >×</button>
                        @endif
                    </div>
                @endforeach
            </div>

            @if($canManage)
                <div class="promo-reward-actions">
                    <button class="button button-secondary" type="button" data-promo-reward-add>+ {{ __('module-promo-codes::messages.add_reward') }}</button>
                    <small data-promo-reward-message>{{ __('module-promo-codes::messages.dynamic_rewards_help') }}</small>
                </div>

                <template data-promo-reward-template>
                    <div class="promo-reward-row" data-promo-reward-row>
                        <div class="form-group">
                            <label>{{ __('module-promo-codes::messages.item_id') }}</label>
                            <input type="number" min="1" step="1" inputmode="numeric" data-promo-reward-item>
                        </div>
                        <div class="form-group">
                            <label>{{ __('module-promo-codes::messages.amount') }}</label>
                            <input type="number" min="1" step="1" inputmode="numeric" data-promo-reward-amount>
                        </div>
                        <button
                            class="button button-secondary promo-reward-remove"
                            type="button"
                            data-promo-reward-remove
                            aria-label="{{ __('module-promo-codes::messages.remove_reward') }}"
                            title="{{ __('module-promo-codes::messages.remove_reward') }}"
                        >×</button>
                    </div>
                </template>
            @endif
        </section>
    </div>

    <aside class="editor-sidebar">
        <section class="form-card">
            <h2>{{ __('module-promo-codes::messages.limits') }}</h2>
            <div class="form-group compact">
                <label for="total_limit">{{ __('module-promo-codes::messages.total_limit') }}</label>
                <input id="total_limit" name="total_limit" type="number" min="0" step="1" value="{{ old('total_limit', $promoCode->total_limit ?? 0) }}" required @disabled(! $canManage)>
                <small>{{ __('module-promo-codes::messages.total_limit_help') }}</small>
            </div>
            <div class="form-group compact">
                <label for="per_user_limit">{{ __('module-promo-codes::messages.per_user_limit') }}</label>
                <input id="per_user_limit" name="per_user_limit" type="number" min="1" max="1000000" step="1" value="{{ old('per_user_limit', $promoCode->per_user_limit ?? 1) }}" required @disabled(! $canManage)>
                <small>{{ __('module-promo-codes::messages.per_user_limit_help') }}</small>
            </div>
        </section>

        <section class="form-card">
            <h2>{{ __('module-promo-codes::messages.state') }}</h2>
            <input type="hidden" name="enabled" value="0">
            <label class="switch-row" for="enabled">
                <input id="enabled" name="enabled" type="checkbox" value="1" @checked((bool) old('enabled', $promoCode->enabled ?? true)) @disabled(! $canManage)>
                <span>
                    <strong>{{ __('module-promo-codes::messages.enabled_switch') }}</strong>
                    <small>{{ __('module-promo-codes::messages.enabled_help') }}</small>
                </span>
            </label>
        </section>

        @if($promoCode->exists)
            <section class="form-card form-card-muted">
                <h2>{{ __('module-promo-codes::messages.statistics') }}</h2>
                <p>{{ __('module-promo-codes::messages.activation_count_value', ['count' => number_format($promoCode->activations_count, 0, '.', ' ')]) }}</p>
                <p>{{ __('module-promo-codes::messages.status_value', ['status' => $promoCode->availabilityLabel()]) }}</p>
            </section>
        @endif
    </aside>
</div>

<div class="admin-actions-panel editor-actions">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.promo-codes.index', ['adminPath' => $adminPath]) }}">{{ __('module-promo-codes::messages.cancel') }}</a>
    @if($canManage && $promoCode->exists)
        <button class="button button-danger" type="submit" form="delete-promo-code-form">{{ __('module-promo-codes::messages.delete') }}</button>
    @endif
    @if($canManage)
        <button class="button button-primary" type="submit">{{ $promoCode->exists ? __('module-promo-codes::messages.save') : __('module-promo-codes::messages.create') }}</button>
    @endif
</div>
