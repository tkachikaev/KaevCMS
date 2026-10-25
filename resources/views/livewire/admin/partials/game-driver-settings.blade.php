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


</fieldset>
