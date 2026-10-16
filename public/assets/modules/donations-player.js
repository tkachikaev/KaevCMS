(() => {
    'use strict';
    const initialized = new WeakSet();
    const initialize = () => {
        const root = document.querySelector('[data-donations-calculator]');
        const form = root?.querySelector('[data-testid="donations-quote-form"]');
        if (!form || initialized.has(root) || typeof BigInt !== 'function') return;
        initialized.add(root);
        const coins = form.elements.namedItem('coins');
        const currency = form.elements.namedItem('currency');
        const slider = form.querySelector('[type="range"]');
        const result = root.querySelector('[data-testid="donations-quote-result"]');
        const error = root.querySelector('[data-testid="donations-quote-error"]');
        const total = result.querySelector('[data-donations-total]');
        const breakdown = result.querySelector('[data-donations-breakdown]');
        const draft = root.querySelector('[data-testid="donations-create-test-draft"]');
        const draftButton = draft.querySelector('[type="submit"]');
        const format = (minor, exponent) => {
            const factor = 10n ** BigInt(exponent);
            return `${minor / factor}${exponent ? `.${String(minor % factor).padStart(exponent, '0')}` : ''}`;
        };
        const refresh = () => {
            const option = currency.selectedOptions[0];
            const count = coins.value;
            const validCoins = /^[1-9][0-9]{0,6}$/.test(count) && coins.checkValidity();
            const exponent = Number(option?.dataset.exponent);
            const price = option?.dataset.priceMinor ?? '';
            const validCurrency = /^[A-Z]{3}$/.test(option?.value ?? '') && /^[1-9][0-9]{0,8}$/.test(price)
                && Number.isInteger(exponent) && exponent >= 0 && exponent <= 4;
            const valid = validCoins && validCurrency;
            coins.setAttribute('aria-invalid', String(!validCoins));
            error.hidden = valid;
            error.textContent = valid ? '' : (validCoins ? error.dataset.invalidCurrency : error.dataset.invalidCoins);
            draftButton.disabled = !valid;
            draft.elements.namedItem('coins').value = valid ? count : '';
            draft.elements.namedItem('currency').value = valid ? option.value : '';
            result.hidden = false;
            if (!valid) {
                total.textContent = '—';
                breakdown.textContent = '';
                return false;
            }
            slider.value = count;
            const span = Number(slider.max) - Number(slider.min);
            slider.style.setProperty('--donations-progress', `${span ? (Number(count) - Number(slider.min)) / span * 100 : 100}%`);
            total.textContent = `${format(BigInt(price) * BigInt(count), exponent)} ${option.value}`;
            breakdown.textContent = breakdown.dataset.template.replace(/:coins|:price|:currency/g, (key) => ({
                ':coins': count, ':price': format(BigInt(price), exponent), ':currency': option.value,
            })[key]);
            return true;
        };
        const input = (event) => {
            if (event.target === slider) coins.value = slider.value;
            refresh();
        };
        form.addEventListener('input', input);
        form.addEventListener('change', input);
        form.addEventListener('submit', (event) => { event.preventDefault(); refresh(); });
        draft.addEventListener('submit', (event) => {
            if (!refresh()) { event.preventDefault(); coins.focus(); }
        });
        refresh();
        form.querySelector('[data-donations-range-control]').hidden = false;
        form.querySelector('[data-donations-live-help]').hidden = false;
        form.querySelector('[data-donations-calculate-fallback]').hidden = true;
    };
    document.addEventListener('livewire:navigated', initialize);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
