@props(['user'])
@php
    $avatars = app(\App\Services\Account\AccountAvatarCatalog::class)->all();
    $availableAvatarFilenames = array_column($avatars, 'filename');
    $requestedAvatar = old('avatar_filename', $user->avatar_filename);
    $selectedAvatar = is_string($requestedAvatar) && in_array($requestedAvatar, $availableAvatarFilenames, true)
        ? $requestedAvatar
        : null;
    $returnTo = request()->getRequestUri();
@endphp
<dialog
    class="account-avatar-modal"
    data-avatar-modal
    @if($errors->has('avatar_filename')) data-avatar-modal-auto-open @endif
    aria-labelledby="account-avatar-modal-title"
>
    <form method="POST" action="{{ public_route('profile.avatar.update') }}" class="account-avatar-modal-card">
        @csrf
        @method('PUT')
        <input type="hidden" name="return_to" value="{{ $returnTo }}">

        <header class="account-avatar-modal-head">
            <div>
                <span class="account-eyebrow">{{ __('Profile avatar') }}</span>
                <h2 id="account-avatar-modal-title">{{ __('Choose avatar') }}</h2>
                <p>{{ __('Choose an avatar from the administrator-provided collection. It is used only for your KaevCMS profile.') }}</p>
            </div>
            <button type="button" class="account-avatar-modal-close" data-avatar-modal-close aria-label="{{ __('Close') }}">×</button>
        </header>

        <div class="account-avatar-modal-body">
            <div class="account-avatar-picker" role="radiogroup" aria-label="{{ __('Available avatars') }}">
                <label class="account-avatar-tile" title="{{ __('Default avatar') }}">
                    <input type="radio" name="avatar_filename" value="" @checked($selectedAvatar === null || $selectedAvatar === '')>
                    <span class="account-avatar-tile-visual account-avatar-option-fallback" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                    <span class="account-avatar-tile-label">{{ __('Default avatar') }}</span>
                    <span class="account-avatar-tile-check" aria-hidden="true">✓</span>
                </label>

                @foreach($avatars as $index => $avatar)
                    <label class="account-avatar-tile" title="{{ __('Avatar :number', ['number' => $index + 1]) }}">
                        <input type="radio" name="avatar_filename" value="{{ $avatar['filename'] }}" @checked($selectedAvatar === $avatar['filename'])>
                        <span class="account-avatar-tile-visual" aria-hidden="true"><img src="{{ $avatar['url'] }}" alt="" loading="lazy" decoding="async"></span>
                        <span class="account-avatar-tile-label">{{ __('Avatar :number', ['number' => $index + 1]) }}</span>
                        <span class="account-avatar-tile-check" aria-hidden="true">✓</span>
                    </label>
                @endforeach
            </div>

            @error('avatar_filename')<p class="account-field-error">{{ $message }}</p>@enderror

            @if($avatars === [])
                <div class="account-inline-warning">{{ __('No optional avatars are available yet. The default avatar remains active.') }}</div>
            @endif
        </div>

        <footer class="account-avatar-modal-actions">
            <button class="account-button secondary" type="button" data-avatar-modal-close>{{ __('Cancel') }}</button>
            <button class="account-button primary" type="submit">{{ __('Save avatar') }}</button>
        </footer>
    </form>
</dialog>
