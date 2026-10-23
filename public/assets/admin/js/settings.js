(() => {
    'use strict';

    const initialize = () => {
        return (() => {
            'use strict';

            const cleanupCallbacks = [];

            const initializeImageUpload = (container) => {
                const input = container.querySelector('[data-settings-file]');
                const selectButton = container.querySelector('[data-settings-file-select]');
                const preview = container.querySelector('[data-settings-preview]');
                const remove = container.querySelector('[data-settings-remove]');
                const removeToggle = container.querySelector('[data-settings-remove-toggle]');
                const wideToggle = container.querySelector('[data-settings-logo-wide]');

                if (!(input instanceof HTMLInputElement) || !(preview instanceof HTMLElement)) {
                    return;
                }

                // A chosen logo much wider than tall softly highlights the "Wide logo" box; the
                // administrator still decides whether to tick it.
                const suggestWideLogo = (image) => {
                    if (!(wideToggle instanceof HTMLElement)) {
                        return;
                    }

                    const checkbox = wideToggle.querySelector('input[type="checkbox"]');
                    const isWide = image.naturalHeight > 0 && image.naturalWidth / image.naturalHeight >= 1.6;
                    wideToggle.classList.toggle('is-suggested', isWide && checkbox instanceof HTMLInputElement && !checkbox.checked);
                };

                if (wideToggle instanceof HTMLElement) {
                    wideToggle.querySelector('input[type="checkbox"]')?.addEventListener('change', () => {
                        wideToggle.classList.remove('is-suggested');
                    });

                    // The logo already saved gets the same hint when it is wide and the box is clear.
                    const current = preview.querySelector('[data-settings-preview-image]');
                    if (current instanceof HTMLImageElement) {
                        if (current.complete) {
                            suggestWideLogo(current);
                        } else {
                            current.addEventListener('load', () => suggestWideLogo(current), { once: true });
                        }
                    }
                }

                let objectUrl = null;

                const clearObjectUrl = () => {
                    if (objectUrl) {
                        URL.revokeObjectURL(objectUrl);
                        objectUrl = null;
                    }
                };

                const showSelectedFile = () => {
                    const file = input.files?.[0];
                    if (!file) {
                        return;
                    }

                    clearObjectUrl();
                    objectUrl = URL.createObjectURL(file);

                    let image = preview.querySelector('[data-settings-preview-image]');
                    if (!(image instanceof HTMLImageElement)) {
                        image = document.createElement('img');
                        image.dataset.settingsPreviewImage = '';
                        preview.replaceChildren(image);
                    }

                    image.addEventListener('load', () => suggestWideLogo(image), { once: true });
                    image.src = objectUrl;
                    image.alt = container.dataset.previewAlt || 'Selected image preview';
                    preview.classList.add('has-image');
                    markForRemoval(false);
                };

                // "Delete" only marks the current image; it is removed when the form is saved,
                // and the same button takes the mark back.
                function markForRemoval(marked) {
                    preview.classList.toggle('marked-for-removal', marked);
                    if (remove instanceof HTMLInputElement) {
                        remove.value = marked ? '1' : '0';
                    }
                    if (removeToggle instanceof HTMLButtonElement) {
                        removeToggle.textContent = marked ? removeToggle.dataset.labelKeep : removeToggle.dataset.labelRemove;
                        removeToggle.setAttribute('aria-pressed', marked ? 'true' : 'false');
                    }
                }

                selectButton?.addEventListener('click', () => input.click());
                input.addEventListener('change', showSelectedFile);

                removeToggle?.addEventListener('click', () => {
                    markForRemoval(!(remove instanceof HTMLInputElement && remove.value === '1'));
                });
                markForRemoval(remove instanceof HTMLInputElement && remove.value === '1');

                cleanupCallbacks.push(clearObjectUrl);
            };

            document.querySelectorAll('[data-settings-image]').forEach(initializeImageUpload);

            return () => cleanupCallbacks.forEach((callback) => callback());
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('settings', initialize);
    } else {
        initialize();
    }
})();
