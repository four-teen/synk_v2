(() => {
    const field = document.querySelector('[data-faculty-picker]');
    const $ = window.jQuery;

    if (field && $ && $.fn.select2) {
        const employeeSelect = field.querySelector('select');
        const picker = $(employeeSelect);
        const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();

        picker.select2({
            width: '100%',
            placeholder: 'Search by name, employee ID, or email',
            allowClear: true,
            minimumResultsForSearch: 0,
            dropdownParent: $(field),
            matcher: (params, data) => {
                const terms = normalize(params.term).trim().split(/\s+/).filter(Boolean);
                const nameAndAccount = normalize(data.text);
                return terms.every((term) => nameAndAccount.includes(term)) ? data : null;
            }
        });

        const combobox = field.querySelector('[role="combobox"]');
        if (combobox) {
            const renderedLabel = combobox.getAttribute('aria-labelledby');
            combobox.setAttribute('aria-labelledby', ['faculty-member-label', renderedLabel].filter(Boolean).join(' '));
            combobox.setAttribute('aria-required', 'true');
        }

        employeeSelect.addEventListener('invalid', (event) => {
            event.preventDefault();
            picker.select2('open');
        });
    }

    const dialog = document.querySelector('[data-remove-account-dialog]');
    if (dialog && typeof dialog.showModal === 'function') {
        dialog.removeAttribute('open');
        dialog.showModal();
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            window.location.replace(dialog.querySelector('[data-cancel-remove]').href);
        });
    }
})();
