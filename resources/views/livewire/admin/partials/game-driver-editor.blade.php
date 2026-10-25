<form class="ud-editor ud-game-editor" wire:submit="{{ $section === 'general' ? 'nextGameStep' : 'saveProfile' }}" novalidate>
    <div class="ud-editor-heading">
        <h2>{{ $originalId === null ? __('drivers.new_game_driver') : __('drivers.edit').': '.$draft['name'] }}</h2>
        <button type="button" class="button button-secondary" wire:click="closeEditor">{{ __('Cancel') }}</button>
    </div>
    <nav class="ud-sections" aria-label="{{ __('drivers.sections') }}">
        <button type="button" @class(['ud-section-tab', 'active' => $section === 'general']) wire:click="setSection('general')">{{ __('drivers.login_step_settings') }}</button>
        <button type="button" @class(['ud-section-tab', 'active' => $section === 'mapping']) wire:click="nextGameStep('mapping')">{{ __('drivers.login_step_mapping') }}</button>
        <button type="button" @class(['ud-section-tab', 'active' => $section === 'features']) wire:click="nextGameStep('features')">{{ __('drivers.game_step_features') }}</button>
    </nav>
    @if($errors->any())
        <div class="notice notice-warning ud-error-summary" role="alert">{{ __('drivers.check_fields') }}</div>
    @endif
    @if($section === 'general')
        @include('livewire.admin.partials.game-driver-settings')
    @elseif($section === 'mapping')
        @include('livewire.admin.partials.game-driver-mapping')
    @else
        @include('livewire.admin.partials.game-driver-features')
    @endif
    <div class="ud-savebar">
        @if($section === 'general')
            <button type="submit" class="button button-primary" wire:loading.attr="disabled">{{ __('drivers.next') }} →</button>
        @elseif($section === 'mapping')
            <button type="button" class="button button-secondary" wire:click="setSection('general')">← {{ __('drivers.back') }}</button>
            <button type="button" class="button button-primary" wire:click="nextGameStep('features')">{{ __('drivers.next') }} →</button>
            <button type="submit" class="button button-secondary" wire:loading.attr="disabled">{{ __('drivers.save') }}</button>
        @else
            <button type="button" class="button button-secondary" wire:click="setSection('mapping')">← {{ __('drivers.back') }}</button>
            <button type="submit" class="button button-primary" wire:loading.attr="disabled">{{ __('drivers.save') }}</button>
        @endif
        <button type="button" class="button button-secondary" wire:click="closeEditor">{{ __('Cancel') }}</button>
    </div>
</form>
