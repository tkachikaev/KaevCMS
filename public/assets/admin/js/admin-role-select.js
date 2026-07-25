(() => {
    const initRoleSelects = () => {
        document.querySelectorAll('[data-admin-role-select]').forEach((select) => {
            if (select.dataset.roleDescriptionReady === '1') {
                return;
            }

            const description = select.form?.querySelector('[data-admin-role-description]')
                ?? document.querySelector('[data-admin-role-description]');

            const updateDescription = () => {
                const option = select.options[select.selectedIndex];

                if (description && option) {
                    description.textContent = option.dataset.description ?? '';
                }
            };

            select.addEventListener('change', updateDescription);
            select.dataset.roleDescriptionReady = '1';
            updateDescription();
        });
    };

    document.addEventListener('DOMContentLoaded', initRoleSelects);
    document.addEventListener('livewire:navigated', initRoleSelects);
    initRoleSelects();
})();
