(() => {
    'use strict';
    const initialize = () => {
        const form = document.querySelector('[data-donations-workspace]');
        if (!(form instanceof HTMLFormElement)) return;
        const list = form.querySelector('[data-donations-rates]');
        const selector = form.querySelector('[data-donations-currency]');
        const template = form.querySelector('[data-donations-rate-template]');
        const preview = form.querySelector('[data-donations-preview]');
        const status = form.querySelector('[data-donations-save-status]');
        const rows = () => Array.from(list.querySelectorAll('[data-donations-row]'));
        const dirty = () => { if (status) status.textContent = status.dataset.dirty; };
        const refresh = () => {
            const count = preview?.value ?? '';
            rows().forEach((row) => {
                const output = row.querySelector('[data-donations-total]');
                output.textContent = output.dataset.empty;
                const price = row.querySelector('[data-donations-price]').value.replace(',', '.');
                const exponent = Number(row.dataset.exponent);
                if (!/^[0-9]{1,12}(?:\.[0-9]{1,4})?$/.test(price) || !/^[1-9][0-9]{0,6}$/.test(count) || Number(count) > 1000000) return;
                const [whole, fraction = ''] = price.split('.');
                if (fraction.length > exponent) return;
                const factor = 10n ** BigInt(exponent);
                const minor = BigInt(whole) * factor + BigInt(fraction.padEnd(exponent, '0') || '0');
                if (minor < 1n || minor > 999999999n) return;
                const total = minor * BigInt(count);
                const amount = `${total / factor}${exponent ? `.${String(total % factor).padStart(exponent, '0')}` : ''}`;
                output.textContent = `${count} × ${price} = ${amount} ${row.dataset.code}`;
            });
            const empty = form.querySelector('[data-donations-empty]');
            empty.hidden = rows().some((row) => row.querySelector('[data-donations-remove]').value !== '1');
            if (selector) Array.from(selector.options).forEach((option) => {
                option.disabled = option.value !== '' && rows().some((row) => row.dataset.code === option.value);
            });
            const add = form.querySelector('[data-donations-add]');
            if (add) add.disabled = !selector?.value;
        };
        const input = (event) => {
            if (event.target !== preview && event.target !== selector) dirty();
            refresh();
        };
        const click = (event) => {
            if (!(event.target instanceof Element)) return;
            if (event.target.closest('[data-donations-add]')) {
                const option = selector?.selectedOptions[0];
                if (!option || !/^[A-Z]{3}$/.test(option.value) || option.disabled || !(template instanceof HTMLTemplateElement)) return;
                const fragment = template.content.cloneNode(true);
                const row = fragment.querySelector('[data-donations-row]');
                row.dataset.code = option.value;
                row.dataset.exponent = option.dataset.exponent;
                row.dataset.testid = `donations-rate-${option.value}`;
                row.querySelectorAll('*').forEach((element) => {
                    ['name', 'id', 'for', 'aria-labelledby'].forEach((attribute) => {
                        if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__CODE__', option.value));
                    });
                });
                row.querySelector('[data-currency-code]').textContent = option.value;
                row.querySelector('[data-removed-code]').textContent = option.value;
                row.querySelector('[data-currency-name]').textContent = option.dataset.name;
                list.append(fragment);
                selector.value = '';
                row.querySelector('[data-donations-price]').focus();
                dirty(); refresh();
                return;
            }
            const remove = event.target.closest('[data-donations-delete]');
            const undo = event.target.closest('[data-donations-undo]');
            const row = (remove || undo)?.closest('[data-donations-row]');
            if (!row) return;
            if (remove && row.dataset.existing === '0') {
                row.remove();
            } else {
                const removed = Boolean(remove);
                row.querySelector('[data-donations-remove]').value = removed ? '1' : '0';
                row.querySelector('[data-donations-fields]').disabled = removed;
                row.querySelector('[data-donations-fields]').hidden = removed;
                row.querySelector('[data-donations-removal]').hidden = !removed;
                row.classList.toggle('is-removed', removed);
            }
            dirty(); refresh();
        };
        const keydown = (event) => {
            if (event.target === preview && event.key === 'Enter') event.preventDefault();
        };
        form.addEventListener('input', input);
        form.addEventListener('change', input);
        form.addEventListener('click', click);
        form.addEventListener('keydown', keydown);
        refresh();
        return () => {
            form.removeEventListener('input', input);
            form.removeEventListener('change', input);
            form.removeEventListener('click', click);
            form.removeEventListener('keydown', keydown);
        };
    };
    if (window.KaevCMSAdmin?.registerPage) window.KaevCMSAdmin.registerPage('donations', initialize);
    else initialize();
})();
