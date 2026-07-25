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

                if (!(input instanceof HTMLInputElement) || !(preview instanceof HTMLElement)) {
                    return;
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

                    image.src = objectUrl;
                    image.alt = container.dataset.previewAlt || 'Selected image preview';
                    preview.classList.add('has-image');
                    preview.classList.remove('marked-for-removal');

                    if (remove instanceof HTMLInputElement) {
                        remove.checked = false;
                    }
                };

                selectButton?.addEventListener('click', () => input.click());
                input.addEventListener('change', showSelectedFile);

                remove?.addEventListener('change', () => {
                    preview.classList.toggle('marked-for-removal', remove.checked);
                });

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
