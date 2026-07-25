(() => {
    'use strict';

    const initialize = () => {
        (() => {
            const editor = document.querySelector('[data-custom-mail-editor]');
            if (!editor) {
                return;
            }

            const textarea = editor.querySelector('[data-custom-mail-html]');
            const frame = editor.querySelector('[data-custom-mail-preview]');
            const button = editor.querySelector('[data-custom-mail-preview-button]');

            if (!textarea || !frame) {
                return;
            }

            const updatePreview = () => {
                frame.srcdoc = textarea.value || '<!doctype html><html><body></body></html>';
            };

            button?.addEventListener('click', updatePreview);
            updatePreview();
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('custom-mail', initialize);
    } else {
        initialize();
    }
})();
