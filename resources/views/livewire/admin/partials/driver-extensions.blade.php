@if(! empty($moduleExtensions))
    <section class="ud-game-group ud-extensions" data-driver-extensions>
        <div class="ud-section-head"><div><h3>{{ __('drivers.modules_section') }}</h3><p>{{ __('drivers.modules_section_help') }}</p></div></div>
        @foreach($moduleExtensions as $declaration)
            <div class="ud-group" wire:key="driver-extension-{{ $declaration['module'] }}-{{ $declaration['key'] }}">
                <div class="ud-section-head">
                    <strong>{{ $declaration['module_name'] }} — {{ $declaration['title'] }}</strong>
                    <span class="ud-badge">{{ $declaration['required'] ? __('drivers.module_required') : __('drivers.module_optional') }}</span>
                </div>
                @if($declaration['description'] !== '')<p class="ud-info">{{ $declaration['description'] }}</p>@endif
                <div class="ud-grid">
                    @foreach($declaration['fields'] as $field)
                        @php($path = 'draft.config.extensions.'.$declaration['module'].'.'.$declaration['key'].'.'.$field['name'])
                        <label for="ud-ext-{{ $declaration['module'] }}-{{ $declaration['key'] }}-{{ $field['name'] }}">{{ $field['label'] }}@if($field['required']) <span aria-hidden="true">*</span>@endif
                            @if($field['kind'] === 'choice')
                                <select id="ud-ext-{{ $declaration['module'] }}-{{ $declaration['key'] }}-{{ $field['name'] }}" wire:model="{{ $path }}">
                                    <option value="">{{ __('drivers.ext_choose') }}</option>
                                    @foreach($field['options'] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                </select>
                            @else
                                <input id="ud-ext-{{ $declaration['module'] }}-{{ $declaration['key'] }}-{{ $field['name'] }}" type="text" wire:model="{{ $path }}" maxlength="{{ in_array($field['kind'], ['table','column'], true) ? 64 : 100 }}" autocomplete="off" spellcheck="false">
                            @endif
                            @error($path)<span class="ud-field-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>
@endif
