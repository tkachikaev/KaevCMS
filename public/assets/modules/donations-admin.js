(() => {
    'use strict';
    const initialize = () => {
        const form = document.querySelector('[data-donations-workspace]');
        if (!(form instanceof HTMLFormElement)) return;
        const list = form.querySelector('[data-donations-rates]');
        const selector = form.querySelector('[data-donations-currency]');
        const template = form.querySelector('[data-donations-rate-template]');
        const status = form.querySelector('[data-donations-save-status]');
        const rows = () => Array.from(list.querySelectorAll('[data-donations-row]'));
        const dirty = () => {
            if (status) {
                status.textContent = status.dataset.dirty;
                status.hidden = false;
            }
        };
        const refresh = () => {
            const empty = form.querySelector('[data-donations-empty]');
            empty.hidden = rows().some((row) => row.querySelector('[data-donations-remove]').value !== '1');
            if (selector) Array.from(selector.options).forEach((option) => {
                option.disabled = option.value !== '' && rows().some((row) => row.dataset.code === option.value);
            });
            const add = form.querySelector('[data-donations-add]');
            if (add) add.disabled = !selector?.value;
        };
        const input = (event) => {
            if (event.target !== selector) dirty();
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
        form.addEventListener('input', input);
        form.addEventListener('change', input);
        form.addEventListener('click', click);
        refresh();
        return () => {
            form.removeEventListener('input', input);
            form.removeEventListener('change', input);
            form.removeEventListener('click', click);
        };
    };
    const initializeActivation = () => {
        const form = document.querySelector('[data-donations-activation]');
        if (!(form instanceof HTMLFormElement)) return;
        const mode = form.querySelector('#donations-payment-mode');
        const controls = form.querySelector('[data-donations-pilot-controls]');
        const player = form.querySelector('#donations-pilot-player');
        const refresh = () => {
            controls.hidden = mode.value !== 'pilot';
            player.required = mode.value === 'pilot';
            form.querySelectorAll('[data-donations-mode-help]').forEach((help) => {
                help.hidden = help.dataset.donationsModeHelp !== mode.value;
            });
        };
        mode.addEventListener('change', refresh);
        refresh();
        return () => mode.removeEventListener('change', refresh);
    };
    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('donations', initialize);
        window.KaevCMSAdmin.registerPage('donations-activation', initializeActivation);
    } else {
        initialize();
        initializeActivation();
    }
})();
