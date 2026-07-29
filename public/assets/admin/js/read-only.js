(() => {
    'use strict';

    const initialize = () => {
        const scope = document.querySelector('[data-admin-read-only-role]');
        if (!scope) {
            return;
        }

        const formMethod = (form) => (form?.getAttribute('method') || 'get').toLowerCase();
        const isGetControl = (element) => {
            const form = element.closest('form');

            return form !== null && formMethod(form) === 'get';
        };
        const isAllowed = (element) => element.hasAttribute('data-read-only-allowed')
            || isGetControl(element)
            || (
                element instanceof HTMLButtonElement
                && !element.hasAttribute('wire:click')
                && element.closest('form') === null
            );

        const protect = (root) => {
            const elements = root.matches?.('input, select, textarea, button')
                ? [root]
                : Array.from(root.querySelectorAll?.('input, select, textarea, button') ?? []);

            elements.forEach((element) => {
                if (isAllowed(element)) {
                    return;
                }

                if (!element.disabled) {
                    element.disabled = true;
                }
                element.setAttribute('data-read-only-disabled', '');
            });
        };

        protect(scope);

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes' && mutation.target instanceof Element) {
                    protect(mutation.target);
                }

                mutation.addedNodes.forEach((node) => {
                    if (node instanceof Element) {
                        protect(node);
                    }
                });
            });
        });

        observer.observe(scope, {
            attributes: true,
            attributeFilter: ['disabled'],
            childList: true,
            subtree: true,
        });

        return () => observer.disconnect();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('read-only-role', initialize);
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
