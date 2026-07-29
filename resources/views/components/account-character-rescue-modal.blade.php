<dialog class="account-operation-modal account-operation-modal-warning account-character-rescue-modal" data-character-rescue-modal aria-labelledby="character-rescue-modal-title">
    <form class="account-operation-modal-card" data-character-rescue-form method="POST" action="">
        @csrf
        <header class="account-operation-modal-head">
            <span class="account-operation-modal-mark" aria-hidden="true">↗</span>
            <div>
                <span class="account-eyebrow">{{ __('Character rescue') }}</span>
                <h2
                    id="character-rescue-modal-title"
                    data-character-rescue-title
                    data-offline-text="{{ __('Return character to city?') }}"
                    data-online-text="{{ __('Character must be offline') }}"
                >{{ __('Return character to city?') }}</h2>
                <p
                    data-character-rescue-description
                    data-offline-text="{{ __('The character must remain offline while the operation is performed.') }}"
                    data-online-text="{{ __('Exit the game before returning this character to the city.') }}"
                >{{ __('The character must remain offline while the operation is performed.') }}</p>
            </div>
            <button type="button" class="account-operation-modal-close" data-character-rescue-close aria-label="{{ __('Close') }}">×</button>
        </header>
        <div class="account-character-rescue-confirmation" data-character-rescue-confirmation>
            <p>{{ __('Character') }}: <strong data-character-rescue-name>—</strong></p>
            <p>{{ __('Destination') }}: <strong data-character-rescue-location>—</strong></p>
            <small>{{ __('Only the saved coordinates will be changed. Inventory, status, effects and game restrictions are not modified.') }}</small>
        </div>
        <div class="account-character-rescue-confirmation account-character-rescue-online-message" data-character-rescue-online-message hidden>
            <p><strong>{{ __('The character is currently online.') }}</strong></p>
            <small>{{ __('Log out of the game, wait for the character status to become offline, and press the button again.') }}</small>
        </div>
        <footer class="account-operation-modal-actions">
            <button type="button" class="account-button secondary" data-character-rescue-close>{{ __('Cancel') }}</button>
            <button type="submit" class="account-button primary" data-character-rescue-submit>{{ __('Return character') }}</button>
        </footer>
    </form>
</dialog>
