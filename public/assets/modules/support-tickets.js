(() => {
    const initializedConversations = new WeakSet();

    const scrollConversations = (force = false) => {
        document.querySelectorAll('[data-support-conversation]').forEach((conversation) => {
            if (!force && initializedConversations.has(conversation)) {
                return;
            }

            conversation.scrollTop = conversation.scrollHeight;
            initializedConversations.add(conversation);
        });
    };

    // After a message is sent the chat scrolls to it and the cursor stays in the emptied field.
    const afterSend = () => {
        scrollConversations(true);
        document.querySelectorAll('[data-support-composer] textarea[data-autogrow]').forEach((textarea) => {
            textarea.style.height = 'auto';
            textarea.focus({ preventScroll: true });
        });
    };

    const initialize = (root = document) => {
        root.querySelectorAll('[data-character-input]').forEach((input) => {
            // The counter sits beside the field, or under the chat composer in the same form.
            const counter = input.parentElement?.querySelector('[data-character-counter]')
                ?? input.closest('form')?.querySelector('[data-character-counter]');
            if (!counter) {
                return;
            }

            const update = () => {
                const maximum = Number(input.getAttribute('maxlength') || 0);
                const length = Array.from(input.value || '').length;
                counter.textContent = `${length} / ${maximum}`;
                counter.classList.toggle('near-limit', maximum > 0 && length >= Math.floor(maximum * 0.9));
            };

            if (input.dataset.characterCounterReady !== '1') {
                input.addEventListener('input', update);
                input.dataset.characterCounterReady = '1';
            }
            update();
        });

        // Chat composers grow with the text (up to a limit) and send on Ctrl + Enter.
        root.querySelectorAll('textarea[data-autogrow]').forEach((textarea) => {
            const maximum = Number(textarea.dataset.autogrowMax || 220);
            const fit = () => {
                textarea.style.height = 'auto';
                textarea.style.height = `${Math.min(textarea.scrollHeight, maximum)}px`;
            };

            if (textarea.dataset.autogrowReady !== '1') {
                textarea.addEventListener('input', fit);
                textarea.addEventListener('keydown', (event) => {
                    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && textarea.value.trim() !== '') {
                        event.preventDefault();
                        textarea.form?.requestSubmit();
                    }
                });
                textarea.dataset.autogrowReady = '1';
            }
            fit();
        });

        root.querySelectorAll('[data-support-editor-permissions]').forEach((form) => {
            if (form.dataset.editorPermissionsReady === '1') {
                return;
            }

            const viewToggle = form.querySelector('[data-editor-view-toggle]');
            const dependentToggles = Array.from(form.querySelectorAll('[data-editor-dependent]'));
            if (!viewToggle) {
                return;
            }

            const update = () => {
                dependentToggles.forEach((toggle) => {
                    toggle.disabled = !viewToggle.checked;
                    if (!viewToggle.checked) {
                        toggle.checked = false;
                    }
                });
            };

            viewToggle.addEventListener('change', update);
            form.dataset.editorPermissionsReady = '1';
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

        scrollConversations();
    };

    const setupLivewireHooks = () => {
        if (!window.Livewire || window.__supportTicketsLivewireHooksReady) {
            return;
        }

        window.__supportTicketsLivewireHooksReady = true;
        window.Livewire.hook('morph.updated', () => {
            window.requestAnimationFrame(() => initialize());
        });
        window.Livewire.on('support-conversation-updated', () => {
            window.requestAnimationFrame(afterSend);
        });
    };

    initialize();
    setupLivewireHooks();
    document.addEventListener('livewire:init', setupLivewireHooks);
    document.addEventListener('livewire:initialized', setupLivewireHooks);
    document.addEventListener('livewire:navigated', () => {
        initialize();
        setupLivewireHooks();
    });
    window.addEventListener('support-conversation-updated', () => {
        window.requestAnimationFrame(afterSend);
    });

    let mutationInitializationScheduled = false;
    const observer = new MutationObserver(() => {
        if (mutationInitializationScheduled) {
            return;
        }

        mutationInitializationScheduled = true;
        window.requestAnimationFrame(() => {
            mutationInitializationScheduled = false;
            initialize();
        });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
