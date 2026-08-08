(() => {
    'use strict';

    const fallbackCopy = (value) => {
        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            return document.execCommand('copy');
        } finally {
            textarea.remove();
        }
    };

    const copy = async (value) => {
        if (typeof value !== 'string' || value === '') return false;

        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return true;
            } catch {
                // Fall back to execCommand for browsers where Clipboard API access is denied.
            }
        }

        try {
            return fallbackCopy(value);
        } catch {
            return false;
        }
    };

    window.KaevCMSAdminClipboard = Object.freeze({ copy });
})();
