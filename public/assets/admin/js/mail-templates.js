(() => {
    'use strict';

    const initialize = () => {
        (() => {
            const editor = document.querySelector('[data-mail-template-editor]');

            if (!editor) {
                return;
            }

            const fields = Array.from(editor.querySelectorAll('[data-mail-template-field]'));
            let activeField = fields.find((field) => field.name === 'body') || fields[0] || null;

            const demoValues = {
                site_name: document.querySelector('.admin-brand strong')?.textContent?.trim() || 'KaevCMS',
                site_url: window.location.origin,
                username: 'TestPlayer',
                user_email: 'player@example.com',
                verification_url: `${window.location.origin}/email/verify/example`,
                reset_url: `${window.location.origin}/reset-password/example`,
                expires_in: editor.dataset.demoExpires || '60 minutes',
                support_email: 'support@example.com',
            };

            const renderVariables = (value) => value.replace(/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/gi, (match, name) => {
                const key = String(name).toLowerCase();
                return Object.prototype.hasOwnProperty.call(demoValues, key) ? demoValues[key] : match;
            });

            const updatePreview = () => {
                fields.forEach((field) => {
                    const target = editor.querySelector(`[data-mail-preview="${field.dataset.mailTemplateField}"]`);
                    if (!target) {
                        return;
                    }

                    const value = renderVariables(field.value || '');
                    target.textContent = value || '—';
                });
            };

            fields.forEach((field) => {
                field.addEventListener('focus', () => {
                    activeField = field;
                });
                field.addEventListener('input', updatePreview);
            });

            editor.querySelectorAll('[data-template-variable]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!activeField) {
                        return;
                    }

                    const variable = `{{${button.dataset.templateVariable}}}`;
                    const start = activeField.selectionStart ?? activeField.value.length;
                    const end = activeField.selectionEnd ?? start;
                    activeField.value = `${activeField.value.slice(0, start)}${variable}${activeField.value.slice(end)}`;
                    activeField.focus();
                    activeField.setSelectionRange(start + variable.length, start + variable.length);
                    activeField.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });

            document.querySelectorAll('[data-mail-template-reset]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                    if (!window.confirm(form.dataset.resetConfirm || 'Restore the default template? Current changes will be removed.')) {
                        event.preventDefault();
                    }
                });
            });

            updatePreview();
        })();
    };

    if (window.KaevCMSAdmin?.registerPage) {
        window.KaevCMSAdmin.registerPage('mail-templates', initialize);
    } else {
        initialize();
    }
})();
