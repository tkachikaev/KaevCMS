<fieldset class="ud-step-fields" wire:loading.attr="disabled" wire:target="useGameTemplate">
        @if($originalId === null)
            <div class="ud-template-picker">
                <label for="ud-game-template">{{ __('drivers.create_from_existing') }}</label>
                <div class="ud-template-controls">
                    <select id="ud-game-template" wire:model="templateId">
                        <option value="">{{ __('drivers.choose_template') }}</option>
                        @foreach($profiles as $entry)
                            @if($entry['profile']['type'] === 'game')
                                <option value="{{ $entry['profile']['id'] }}">{{ $entry['profile']['name'] }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button type="button" class="button button-secondary" wire:click="useGameTemplate">{{ __('drivers.use_template') }}</button>
                </div>
                @error('templateId')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif

        <div class="ud-grid">
            <label for="ud-game-name">{{ __('drivers.name') }}
                <input id="ud-game-name" type="text" maxlength="100" wire:model="draft.name" placeholder="{{ __('drivers.name_example') }}" required>
                <small>{{ __('drivers.name_help') }}</small>
                @error('draft.name')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label for="ud-game-id">{{ __('drivers.short_name') }}
                <input id="ud-game-id" type="text" autocapitalize="none" spellcheck="false" maxlength="64" wire:model="draft.id" placeholder="my-game" @disabled($originalId !== null)>
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
            <label for="ud-game-charset">{{ __('drivers.charset') }}
                <select id="ud-game-charset" wire:model.live="draft.config.database.charset">
                    @foreach(array_keys($encodingOptions) as $encoding)<option value="{{ $encoding }}">{{ $encoding }}</option>@endforeach
                    @unless(isset($encodingOptions[$charset]))<option value="{{ $charset }}">{{ $charset }}</option>@endunless
                </select>
                @error('draft.config.database.charset')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
            <label for="ud-game-collation">{{ __('drivers.collation') }}
                <select id="ud-game-collation" wire:model="draft.config.database.collation" wire:key="login-collation-{{ $charset }}">
                    @foreach($collations as $collation)<option value="{{ $collation }}">{{ $collation }}</option>@endforeach
                    @unless(in_array($config['database']['collation'], $collations, true))<option value="{{ $config['database']['collation'] }}">{{ $config['database']['collation'] }}</option>@endunless
                </select>
                @error('draft.config.database.collation')<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
            </label>
        </div>


</fieldset>
