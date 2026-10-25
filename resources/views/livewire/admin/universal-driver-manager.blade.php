<div class="server-manager universal-drivers" data-testid="universal-driver-manager">
    @if($notice)<div class="notice notice-success" role="status">{{ $notice }}</div>@endif
    @if($error)<div class="notice notice-error" role="alert">{{ $error }}</div>@endif
    @error('importFile')<div class="notice notice-error" role="alert">{{ $message }}</div>@enderror

    <nav class="ud-types" aria-label="{{ __('drivers.types') }}">
        <button type="button" @class(['button','button-primary' => $tab === 'login','button-secondary' => $tab !== 'login']) wire:click="switchType('login')">{{ __('drivers.type_login') }}</button>
        <button type="button" @class(['button','button-primary' => $tab === 'game','button-secondary' => $tab !== 'game']) wire:click="switchType('game')">{{ __('drivers.type_game') }}</button>
    </nav>
    <div class="ud-toolbar">
        <p>{{ __('drivers.catalog_help') }}</p>
        @if($canManage && !$editing)
            <div class="ud-actions">
                <button type="button" class="button button-secondary" wire:click="$set('importOpen', true)">{{ __('drivers.import') }}</button>
                <button type="button" class="button button-primary" wire:click="createProfile('{{ $tab }}')">+ {{ __('drivers.create') }}</button>
            </div>
        @endif
    </div>

    @if($importOpen && $canManage)
        <form class="ud-panel" wire:submit="importProfile">
            <h2>{{ __('drivers.import') }}</h2>
            <p>{{ __('drivers.import_help') }}</p>
            <input aria-label="JSON" type="file" accept=".json,application/json" wire:model="importFile" required>
            <div class="ud-actions">
                <button class="button button-primary" type="submit" wire:loading.attr="disabled" wire:target="importFile,importProfile">{{ __('drivers.import') }}</button>
                <button class="button button-secondary" type="button" wire:click="$set('importOpen', false)">{{ __('Cancel') }}</button>
            </div>
        </form>
    @endif

    @if($confirmDelete && $canManage)
        <div class="ud-panel" role="alertdialog" aria-modal="true" aria-label="{{ __('drivers.delete') }}">
            <h2>{{ __('drivers.delete') }} — {{ $originalId }}</h2>
            <p>{{ __('drivers.delete_warning') }}</p>
            <div class="ud-actions">
                <button type="button" class="button button-danger" wire:click="deleteProfile">{{ __('drivers.delete') }}</button>
                <button type="button" class="button button-secondary" wire:click="$set('confirmDelete', false)">{{ __('Cancel') }}</button>
            </div>
        </div>
    @endif

    @unless($editing)
        <div class="ud-list">
            @forelse($profiles as $entry)
                @php($p = $entry['profile'])
                @if($p['type'] === $tab)
                    <article class="ud-profile" wire:key="profile-{{ $p['type'] }}-{{ $p['id'] }}">
                        <div class="ud-profile-copy">
                            <h2>{{ $p['name'] }}</h2>
                            <div class="ud-meta"><code>{{ $p['id'] }}</code> · {{ $entry['origin'] === 'builtin' ? __('drivers.builtin') : __('drivers.custom') }}@if($entry['modified']) · {{ __('drivers.modified') }}@endif · {{ __('drivers.function_count', ['count' => format_number(count($p['config']['capabilities']))]) }}</div>
                        </div>
                        <div class="ud-actions">
                            <button class="button button-secondary" type="button" wire:click="exportProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.export') }}</button>
                            @if($canManage)
                                <button class="button button-secondary" type="button" wire:click="editProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.edit') }}</button>
                                <button class="button button-secondary" type="button" wire:click="cloneProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.copy') }}</button>
                                @if($entry['origin'] === 'builtin' && $entry['modified'])
                                    <button class="button button-secondary" type="button" wire:click="restoreProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.restore') }}</button>
                                @endif
                                <details class="server-card-menu ud-delete-menu">
                                    <summary aria-label="{{ __('drivers.delete') }}">⋯</summary>
                                    <button type="button" wire:click="askDelete('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.delete') }}</button>
                                </details>
                            @endif
                        </div>
                    </article>
                @endif
            @empty
                <div class="empty-state"><h2>{{ __('drivers.empty') }}</h2></div>
            @endforelse
            @foreach($hiddenProfiles as $p)
                @if($p['type'] === $tab)
                    <article class="ud-profile ud-profile-hidden" wire:key="hidden-{{ $p['type'] }}-{{ $p['id'] }}">
                        <div class="ud-profile-copy"><h2>{{ $p['name'] }}</h2><p>{{ __('drivers.hidden') }}</p></div>
                        @if($canManage)<button class="button button-secondary" type="button" wire:click="restoreProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.restore') }}</button>@endif
                    </article>
                @endif
            @endforeach
        </div>
    @else
        @php($config = $draft['config'])
        @if($draft['type'] === 'login')
            @include('livewire.admin.partials.login-driver-editor')
        @else
            @include('livewire.admin.partials.game-driver-editor')
        @endif
    @endunless
</div>
