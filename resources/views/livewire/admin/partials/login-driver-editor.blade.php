<form class="ud-editor ud-login-editor" wire:submit="{{ $section === 'general' ? 'nextLoginStep' : 'saveProfile' }}" novalidate>
    <div class="ud-editor-heading">
        <h2>{{ $originalId === null ? __('drivers.new_login_driver') : __('drivers.edit').': '.$draft['name'] }}</h2>
        <button type="button" class="button button-secondary" wire:click="closeEditor">{{ __('Cancel') }}</button>
    </div>
    <nav class="ud-sections" aria-label="{{ __('drivers.sections') }}">
        <button type="button" @class(['ud-section-tab', 'active' => $section === 'general']) wire:click="setSection('general')">{{ __('drivers.login_step_settings') }}</button>
        <button type="button" @class(['ud-section-tab', 'active' => $section !== 'general']) wire:click="nextLoginStep">{{ __('drivers.login_step_mapping') }}</button>
    </nav>

    @if($section === 'general')
        @if($originalId === null)
            <div class="ud-template-picker">
                <label for="ud-login-template">{{ __('drivers.create_from_existing') }}</label>
                <div class="ud-template-controls">
                    <select id="ud-login-template" wire:model="templateId">
                        <option value="">{{ __('drivers.choose_template') }}</option>
                        @foreach($profiles as $entry)
                            @if($entry['profile']['type'] === 'login')
                                <option value="{{ $entry['profile']['id'] }}">{{ $entry['profile']['name'] }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button type="button" class="button button-secondary" wire:click="useLoginTemplate">{{ __('drivers.use_template') }}</button>
                </div>
                @error('templateId')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif

        <div class="ud-grid">
            <label for="ud-login-name">{{ __('drivers.name') }}
                <input id="ud-login-name" type="text" maxlength="100" wire:model="draft.name" placeholder="{{ __('drivers.name_example') }}" required>
                <small>{{ __('drivers.name_help') }}</small>
                @error('draft.name')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label for="ud-login-id">{{ __('drivers.short_name') }}
                <input id="ud-login-id" type="text" autocapitalize="none" spellcheck="false" maxlength="64" wire:model="draft.id" placeholder="my-login" @disabled($originalId !== null)>
                <small>{{ __('drivers.short_name_help') }}</small>
                @error('draft.id')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
        </div>

        @php
            $encodingOptions = [
                'utf8mb4' => ['utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8mb4_bin'],
                'utf8' => ['utf8_unicode_ci', 'utf8_general_ci', 'utf8_bin'],
                'utf8mb3' => ['utf8mb3_unicode_ci', 'utf8mb3_general_ci', 'utf8mb3_bin'],
                'cp1251' => ['cp1251_general_ci', 'cp1251_bin'],
                'latin1' => ['latin1_general_ci', 'latin1_swedish_ci', 'latin1_bin'],
            ];
            $charset = $config['database']['charset'];
            $collations = $encodingOptions[$charset] ?? [];
        @endphp
        <div class="ud-grid">
            <label for="ud-login-charset">{{ __('drivers.charset') }}
                <select id="ud-login-charset" wire:model.live="draft.config.database.charset">
                    @foreach(array_keys($encodingOptions) as $encoding)<option value="{{ $encoding }}">{{ $encoding }}</option>@endforeach
                    @unless(isset($encodingOptions[$charset]))<option value="{{ $charset }}">{{ $charset }}</option>@endunless
                </select>
                @error('draft.config.database.charset')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label for="ud-login-collation">{{ __('drivers.collation') }}
                <select id="ud-login-collation" wire:model="draft.config.database.collation" wire:key="login-collation-{{ $charset }}">
                    @foreach($collations as $collation)<option value="{{ $collation }}">{{ $collation }}</option>@endforeach
                    @unless(in_array($config['database']['collation'], $collations, true))<option value="{{ $config['database']['collation'] }}">{{ $config['database']['collation'] }}</option>@endunless
                </select>
                @error('draft.config.database.collation')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
        </div>

        <div class="ud-grid">
            <label for="ud-login-password-scheme">{{ __('drivers.password_scheme') }}
                <select id="ud-login-password-scheme" wire:model.live="draft.config.password.scheme">
                    <option value="">{{ __('drivers.choose_password_scheme') }}</option>
                    <option value="sha1_base64">SHA-1 / Base64</option>
                    <option value="bcrypt_2a">BCrypt ($2a$)</option>
                </select>
                <small>{{ __('drivers.password_scheme_help') }}</small>
                @error('draft.config.password.scheme')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            @if(($config['password']['scheme'] ?? '') === 'bcrypt_2a')
                <label for="ud-login-bcrypt-cost">{{ __('drivers.bcrypt_cost') }}
                    <input id="ud-login-bcrypt-cost" type="number" min="4" max="16" wire:model.number="draft.config.password.cost" placeholder="10">
                    @error('draft.config.password.cost')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
                </label>
            @endif
        </div>
    @else
        <p class="ud-info">{{ __('drivers.login_mapping_help') }}</p>
        <div class="ud-login-mappings">
            @foreach(['login','password','email','access_level','created_at','last_active','last_server','id'] as $field)
                @php($required = in_array($field, ['login','password'], true) || ($field === 'id' && $config['accounts']['ownership']['identity'] === 'id'))
                <div class="ud-mapping-row" wire:key="login-mapping-{{ $field }}">
                    <div class="ud-mapping-purpose">
                        <strong>{{ $field === 'id' ? __('drivers.field_id_login') : __('drivers.field_'.$field) }}</strong>
                        <small>{{ $required ? __('drivers.required_field') : __('drivers.empty_if_missing') }}</small>
                    </div>
                    <span class="ud-arrow" aria-hidden="true">→</span>
                    <label for="ud-login-table-{{ $field }}">{{ __('drivers.table') }}
                        <input id="ud-login-table-{{ $field }}" type="text" maxlength="64" wire:model.blur="draft.config.accounts.table" required>
                    </label>
                    <span class="ud-arrow" aria-hidden="true">→</span>
                    <label for="ud-login-column-{{ $field }}">{{ __('drivers.column') }}
                        <input id="ud-login-column-{{ $field }}" type="text" maxlength="64" wire:model="draft.config.accounts.fields.{{ $field }}" placeholder="{{ $required ? $field : __('drivers.optional') }}" @required($required)>
                        @error('draft.config.accounts.fields.'.$field)<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
                    </label>
                </div>
            @endforeach
        </div>
        @error('draft.config.accounts.table')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
        @error('draft.config.password.scheme')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror

        <details class="ud-advanced" wire:ignore.self @if($registrationRows !== []) open @endif>
            <summary>{{ __('drivers.registration_defaults') }}</summary>
            <p>{{ __('drivers.registration_defaults_help') }}</p>
            @foreach($registrationRows as $index => $row)
                <div class="ud-registration-row" wire:key="registration-row-{{ $index }}">
                    <label>{{ __('drivers.table') }}<input type="text" maxlength="64" wire:model.blur="draft.config.accounts.table"></label>
                    <label>{{ __('drivers.column') }}
                        <input type="text" maxlength="64" wire:model="registrationRows.{{ $index }}.column" placeholder="accessLevel">
                        @error('registrationRows.'.$index.'.column')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <label>{{ __('drivers.value') }}<input type="text" wire:model="registrationRows.{{ $index }}.value"></label>
                    <button type="button" class="button button-secondary" wire:click="removeRegistrationRow({{ $index }})">{{ __('drivers.remove') }}</button>
                </div>
            @endforeach
            <div class="ud-actions">
                <button type="button" class="button button-secondary" wire:click="addRegistrationRow" @disabled(count($registrationRows) >= 16)>+ {{ __('drivers.add_row') }}</button>
            </div>
        </details>
    @endif

    <div class="ud-savebar">
        @if($section === 'general')
            <button type="submit" class="button button-primary" wire:loading.attr="disabled">{{ __('drivers.next') }} →</button>
        @else
            <button type="button" class="button button-secondary" wire:click="setSection('general')">← {{ __('drivers.back') }}</button>
            <button type="submit" class="button button-primary" wire:loading.attr="disabled">{{ __('drivers.save') }}</button>
        @endif
        <button type="button" class="button button-secondary" wire:click="closeEditor">{{ __('Cancel') }}</button>
    </div>
</form>
