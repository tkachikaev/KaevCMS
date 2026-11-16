@props(['user'])
<form method="POST" action="{{ public_route('profile.display-name.update') }}" class="account-form-card account-display-name-form">
    @csrf
    @method('PUT')
    <label for="account-display-name">
        <span>{{ __('Display name') }}</span>
        <div class="account-field-control">
            <input id="account-display-name" name="display_name" type="text" minlength="3" maxlength="24" value="{{ old('display_name', $user->display_name) }}" placeholder="{{ $user->publicName() }}" autocomplete="nickname" @class(['account-field-invalid' => $errors->has('display_name')]) @error('display_name') aria-describedby="display-name-error" @enderror>
            @error('display_name')<small id="display-name-error" class="account-field-error" role="alert">{{ $message }}</small>@enderror
        </div>
    </label>
    <div class="account-form-actions"><button class="account-button primary" type="submit">{{ __('Save display name') }}</button></div>
</form>
