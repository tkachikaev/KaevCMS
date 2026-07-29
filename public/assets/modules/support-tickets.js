(() => {
    const initialize = (root = document) => {
        root.querySelectorAll('[data-character-input]').forEach((input) => {
            if (input.dataset.characterCounterReady === '1') {
                return;
            }

            const counter = input.parentElement?.querySelector('[data-character-counter]');
            if (!counter) {
                return;
            }

            const maximum = Number(input.getAttribute('maxlength') || 0);
            const update = () => {
                const length = Array.from(input.value || '').length;
                counter.textContent = `${length} / ${maximum}`;
                counter.classList.toggle('near-limit', maximum > 0 && length >= Math.floor(maximum * 0.9));
            };

            input.addEventListener('input', update);
            input.dataset.characterCounterReady = '1';
            update();
        });

        root.querySelectorAll('form[data-confirm]').forEach((form) => {
            if (form.dataset.confirmReady === '1') {
                return;
            }

            form.addEventListener('submit', (event) => {
                const message = form.dataset.confirm || '';
                if (message !== '' && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
            form.dataset.confirmReady = '1';
        });
    };

    initialize();
    document.addEventListener('livewire:navigated', () => initialize());
})();
