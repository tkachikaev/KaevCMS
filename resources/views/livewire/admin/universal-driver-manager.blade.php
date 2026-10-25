<div class="server-manager universal-drivers" data-testid="universal-driver-manager">
    @if($notice)<div class="notice notice-success" role="status">{{ $notice }}</div>@endif
    @if($error)<div class="notice notice-error" role="alert">{{ $error }}</div>@endif
    @error('importFile')<div class="notice notice-error" role="alert">{{ $message }}</div>@enderror

    @unless($editing)
        <nav class="ud-types" aria-label="{{ __('drivers.types') }}">
            <button type="button" @class(['button','button-primary' => $tab === 'login','button-secondary' => $tab !== 'login']) wire:click="switchType('login')">{{ __('drivers.type_login') }}</button>
            <button type="button" @class(['button','button-primary' => $tab === 'game','button-secondary' => $tab !== 'game']) wire:click="switchType('game')">{{ __('drivers.type_game') }}</button>
        </nav>
        <div class="ud-toolbar">
            <p>{{ __('drivers.catalog_help') }}</p>
            @if($canManage)
                <div class="ud-actions">
                    <button type="button" class="button button-secondary" wire:click="$set('importOpen', true)">{{ __('drivers.import') }}</button>
                    <button type="button" class="button button-primary" wire:click="createProfile('{{ $tab }}')">+ {{ __('drivers.create') }}</button>
                </div>
            @endif
        </div>
    @endunless

    @if($importOpen && $canManage && !$editing)
        <div class="ud-modal-backdrop" wire:key="ud-import-modal" wire:click.self="closeImport" wire:keydown.escape.window="closeImport">
            <form class="ud-modal" role="dialog" aria-modal="true" aria-labelledby="ud-import-title" wire:submit="importProfile">
                <h2 id="ud-import-title">{{ __('drivers.import') }}</h2>
                <p>{{ __('drivers.import_help') }}</p>
                <label class="ud-file">
                    <input class="ud-file-input" aria-label="JSON" type="file" accept=".json,application/json" wire:model="importFile">
                    <span class="button button-secondary">{{ __('drivers.choose_file') }}</span>
                    <span class="ud-file-name">{{ $importFile ? $importFile->getClientOriginalName() : __('drivers.no_file') }}</span>
                </label>
                @error('importFile')<p class="ud-field-error" role="alert">{{ $message }}</p>@enderror
                <div class="ud-modal-actions">
                    <button class="button button-secondary" type="button" wire:click="closeImport">{{ __('Cancel') }}</button>
                    <button class="button button-primary" type="submit" @disabled(! $importFile) wire:loading.attr="disabled" wire:target="importFile,importProfile">{{ __('drivers.import') }}</button>
                </div>
            </form>
        </div>
    @endif

    @if($confirmDelete && $canManage && !$editing)
        <div class="ud-modal-backdrop" wire:key="ud-delete-modal" wire:click.self="cancelDelete" wire:keydown.escape.window="cancelDelete">
            <div class="ud-modal" role="alertdialog" aria-modal="true" aria-labelledby="ud-delete-title">
                <h2 id="ud-delete-title">{{ __('drivers.delete') }}: {{ $originalId }}</h2>
                <p>{{ __('drivers.delete_warning') }}</p>
                <div class="ud-modal-actions">
                    <button type="button" class="button button-secondary" wire:click="cancelDelete">{{ __('Cancel') }}</button>
                    <button type="button" class="button button-danger" wire:click="deleteProfile">{{ __('drivers.delete') }}</button>
                </div>
            </div>
        </div>
    @endif

    @unless($editing)
        @php($visible = collect($profiles)->filter(fn ($entry) => $entry['profile']['type'] === $tab))
        @php($hidden = collect($hiddenProfiles)->filter(fn ($p) => $p['type'] === $tab))
        @if($visible->isEmpty() && $hidden->isEmpty())
            <div class="empty-state"><h2>{{ __('drivers.empty') }}</h2></div>
        @else
            <div class="ud-table-wrap">
                <table class="ud-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('drivers.col_name') }}</th>
                            <th scope="col">{{ __('drivers.col_id') }}</th>
                            <th scope="col">{{ __('drivers.col_origin') }}</th>
                            <th scope="col" class="ud-num">{{ __('drivers.col_functions') }}</th>
                            <th scope="col" aria-label="{{ __('drivers.more_actions') }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($visible as $entry)
                            @php($p = $entry['profile'])
                            <tr class="ud-profile" wire:key="profile-{{ $p['type'] }}-{{ $p['id'] }}">
                                <th scope="row" class="ud-cell-name">{{ $p['name'] }}</th>
                                <td data-label="{{ __('drivers.col_id') }}"><code>{{ $p['id'] }}</code></td>
                                <td data-label="{{ __('drivers.col_origin') }}">{{ $entry['origin'] === 'builtin' ? __('drivers.builtin') : __('drivers.custom') }}@if($entry['modified']) <span class="ud-badge">{{ __('drivers.modified') }}</span>@endif</td>
                                <td class="ud-num" data-label="{{ __('drivers.col_functions') }}">{{ format_number(count($p['config']['capabilities'])) }}</td>
                                <td class="ud-cell-actions">
                                    <div class="ud-actions">
                                        @if($canManage)
                                            <button class="button button-secondary" type="button" wire:click="editProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.edit') }}</button>
                                        @endif
                                        <details class="ud-menu" data-ud-menu>
                                            <summary aria-label="{{ __('drivers.more_actions') }}" aria-haspopup="menu">⋯</summary>
                                            <div class="ud-menu-items" role="menu">
                                                <button type="button" role="menuitem" wire:click="exportProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.export') }}</button>
                                                @if($canManage)
                                                    <button type="button" role="menuitem" wire:click="cloneProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.copy') }}</button>
                                                    @if($entry['origin'] === 'builtin' && $entry['modified'])
                                                        <button type="button" role="menuitem" wire:click="restoreProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.restore') }}</button>
                                                    @endif
                                                    <hr>
                                                    <button type="button" role="menuitem" class="ud-danger" wire:click="askDelete('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.delete') }}</button>
                                                @endif
                                            </div>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @foreach($hidden as $p)
                            <tr class="ud-profile ud-profile-hidden" wire:key="hidden-{{ $p['type'] }}-{{ $p['id'] }}">
                                <th scope="row" class="ud-cell-name">{{ $p['name'] }}</th>
                                <td data-label="{{ __('drivers.col_id') }}"><code>{{ $p['id'] }}</code></td>
                                <td colspan="2" data-label="{{ __('drivers.col_origin') }}">{{ __('drivers.hidden') }}</td>
                                <td class="ud-cell-actions">@if($canManage)<button class="button button-secondary" type="button" wire:click="restoreProfile('{{ $p['type'] }}', '{{ $p['id'] }}')">{{ __('drivers.restore') }}</button>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @else
        @php($config = $draft['config'])
        @if($draft['type'] === 'login')
            @include('livewire.admin.partials.login-driver-editor')
        @else
            @include('livewire.admin.partials.game-driver-editor')
        @endif
    @endunless
</div>
