<div>
    @php
        $currentPasswordError = $errors->first('currentPassword') ?: $errors->first('current_password');
        $gamePasswordError = $errors->first('gamePassword') ?: $errors->first('game_password');
        $gamePasswordConfirmationError = $errors->first('gamePasswordConfirmation') ?: $errors->first('game_password_confirmation');
    @endphp

    @if($status)<div class="account-notice success" role="status"><span aria-hidden="true">✓</span><div>{{ $status }}</div></div>@endif

    <form class="account-form-card compact" method="POST" action="{{ public_route('game-accounts.password', ['gameAccount' => $accountId]) }}" wire:submit="save">
        @csrf
        @method('PUT')
        <div class="account-form-title"><span aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path></svg></span><div><h2>{{ __('Secure password change') }}</h2><p>{{ __('The new password is sent directly to the game LoginServer.') }}</p></div></div>
        <label><span>{{ __('Current personal account password') }}</span><div class="account-field-control"><input type="password" name="current_password" wire:model="currentPassword" autocomplete="current-password" required @class(['account-field-invalid' => $currentPasswordError !== '']) @if($currentPasswordError !== '') aria-describedby="current-password-error" @endif>@if($currentPasswordError !== '')<small class="account-field-error" id="current-password-error" role="alert">{{ $currentPasswordError }}</small>@endif</div></label>
        <div class="account-form-grid">
            <label><span>{{ __('New game password') }}</span><div class="account-field-control"><input type="password" name="game_password" wire:model="gamePassword" autocomplete="new-password" required @class(['account-field-invalid' => $gamePasswordError !== '']) @if($gamePasswordError !== '') aria-describedby="new-game-password-error" @endif>@if($gamePasswordError !== '')<small class="account-field-error" id="new-game-password-error" role="alert">{{ $gamePasswordError }}</small>@endif</div></label>
            <label><span>{{ __('Repeat game password') }}</span><div class="account-field-control"><input type="password" name="game_password_confirmation" wire:model="gamePasswordConfirmation" autocomplete="new-password" required @class(['account-field-invalid' => $gamePasswordConfirmationError !== '']) @if($gamePasswordConfirmationError !== '') aria-describedby="new-game-password-confirmation-error" @endif>@if($gamePasswordConfirmationError !== '')<small class="account-field-error" id="new-game-password-confirmation-error" role="alert">{{ $gamePasswordConfirmationError }}</small>@endif</div></label>
        </div>
        <div class="account-form-actions"><button class="account-button primary" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">{{ __('Change password') }}</span><span wire:loading wire:target="save">{{ __('Saving…') }}</span></button></div>
    </form>
</div>
