(() => {
    const dialog = document.getElementById('work-experience');
    if (!dialog) return;

    const form = dialog.querySelector('form');
    const table = dialog.querySelector('[data-work-table]');
    const records = dialog.querySelector('[data-work-records]');
    const template = dialog.querySelector('[data-work-template]');
    const add = dialog.querySelector('[data-work-add]');
    const save = dialog.querySelector('[data-work-save]');
    const close = dialog.querySelector('[data-work-close]');
    const status = dialog.querySelector('[data-work-status]');
    const error = dialog.querySelector('[data-work-error]');
    const limit = Number(dialog.dataset.limit);
    const pesos = new Intl.NumberFormat('en-PH', {
        style: 'currency', currency: 'PHP', currencyDisplay: 'narrowSymbol'
    });
    const textFields = ['position', 'employer', 'pay_grade'];
    let trigger;
    let request;
    let url;
    let state;
    let saving = false;
    let dirty = false;
    let saved = false;

    function updateRows() {
        Array.from(records.children).forEach((row, index) => {
            row.querySelector('[data-work-remove]').setAttribute('aria-label', 'Remove row ' + (index + 1));
        });
        add.disabled = records.children.length >= limit;
    }

    function displayDate(value) {
        return value.trim()
            .replace(/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/, '$3/$2/$1')
            .replace(/^present$/i, 'PRESENT');
    }

    function salaryAmount(value) {
        const trimmed = value.trim();
        if (!/^₱?\s*(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?$/.test(trimmed)) return trimmed;
        return trimmed.replace(/[₱,\s]/g, '');
    }

    function displaySalary(value) {
        const amount = salaryAmount(value);
        return /^[0-9]{1,10}(?:\.[0-9]{1,2})?$/.test(amount) ? pesos.format(Number(amount)) : value;
    }

    function formatDateEntry(value) {
        if (/^[0-9]{3,4}$/.test(value) && !/^(19|20)/.test(value)) {
            return value.slice(0, 2) + '/' + value.slice(2);
        }
        return value.replace(/^([0-9]{2})\/?([0-9]{2})\/?([0-9]{1,4})$/, '$1/$2/$3');
    }

    function appendRow(values = {}) {
        const row = template.content.firstElementChild.cloneNode(true);
        row.dataset.recordIndex = values.record_index ?? '';
        row.querySelectorAll('[data-work-field]').forEach(field => {
            const key = field.dataset.workField;
            const value = values[key] ?? '';
            if (key === 'appointment_status' && value) {
                const appointment = value.toUpperCase();
                if (!Array.from(field.options).some(option => option.value === appointment)) {
                    field.add(new Option(appointment, appointment));
                }
                field.value = appointment;
            } else if (key === 'salary') {
                field.value = displaySalary(value);
            } else if (key.startsWith('date_')) {
                field.value = displayDate(value);
            } else {
                field.value = textFields.includes(key) ? value.toUpperCase() : value;
            }
        });
        records.append(row);
        return row;
    }

    function showError(message = '', errors = {}) {
        const general = [];
        records.querySelectorAll('[aria-invalid]').forEach(field => {
            field.removeAttribute('aria-invalid');
            field.removeAttribute('aria-errormessage');
        });
        records.querySelectorAll('[data-work-field-error]').forEach(label => { label.hidden = true; });
        for (const [path, detail] of Object.entries(errors)) {
            const [, index, key] = path.split('.');
            const field = Array.from(records.children[Number(index)]?.querySelectorAll('[data-work-field]') ?? [])
                .find(input => input.dataset.workField === key);
            if (!field) {
                general.push(detail);
                continue;
            }
            const label = field.closest('td').querySelector('[data-work-field-error]');
            label.id = 'work-error-' + index + '-' + key;
            label.textContent = detail;
            label.hidden = false;
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-errormessage', label.id);
        }
        error.textContent = [message, ...general].filter(Boolean).join(' ');
        error.hidden = !error.textContent;
        records.querySelector('[aria-invalid]')?.focus();
    }

    async function send(options) {
        const response = await fetch(url, options);
        const data = await response.json();
        if (!response.ok) throw data;
        return data;
    }

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-work-record]');
        if (!button || dialog.open) return;
        trigger = button;
        url = 'work-experience.php?record=' + encodeURIComponent(button.dataset.workRecord);
        state = null;
        dirty = false;
        saved = false;
        table.hidden = true;
        add.hidden = true;
        save.disabled = true;
        records.replaceChildren();
        showError();
        dialog.querySelector('[data-work-employee]').textContent = button.dataset.workName;
        status.textContent = 'Loading work experience…';
        const controller = new AbortController();
        request = controller;
        dialog.showModal();
        try {
            const data = await send({signal: controller.signal});
            if (controller.signal.aborted) return;
            state = data;
            state.values.work.forEach(appendRow);
            updateRows();
            table.hidden = false;
            add.hidden = false;
            status.textContent = records.childElementCount ? '' : 'No work experience recorded.';
        } catch (failure) {
            if (controller.signal.aborted) return;
            status.textContent = '';
            showError(failure instanceof Error ? 'Work experience could not be loaded. Reopen the form to retry.' : failure.message);
        }
    });

    function markChanged() {
        dirty = true;
        save.disabled = false;
        status.textContent = '';
    }

    add.addEventListener('click', () => {
        if (records.children.length >= limit) return;
        const row = appendRow();
        updateRows();
        markChanged();
        row.querySelector('input').focus();
    });
    records.addEventListener('input', event => {
        const field = event.target;
        if (textFields.includes(field.dataset.workField) && !event.isComposing) {
            const value = field.value.toUpperCase();
            if (value !== field.value) {
                const start = field.value.slice(0, field.selectionStart).toUpperCase().length;
                const end = field.value.slice(0, field.selectionEnd).toUpperCase().length;
                field.value = value;
                field.setSelectionRange(start, end);
            }
        } else if (field.dataset.workField?.startsWith('date_') && field.selectionEnd === field.value.length
            && !event.isComposing && !event.inputType?.startsWith('delete')) {
            const formatted = formatDateEntry(field.value);
            if (formatted !== field.value) field.value = formatted;
        }
        markChanged();
    });
    records.addEventListener('focusin', event => {
        const field = event.target;
        if (field.dataset.workField === 'salary') field.value = salaryAmount(field.value);
    });
    records.addEventListener('focusout', event => {
        const field = event.target;
        if (field.dataset.workField?.startsWith('date_')) field.value = displayDate(field.value);
        if (field.dataset.workField === 'salary') field.value = displaySalary(field.value);
        if (textFields.includes(field.dataset.workField)) field.value = field.value.toUpperCase();
    });
    records.addEventListener('click', event => {
        const remove = event.target.closest('[data-work-remove]');
        if (!remove || !window.confirm('Remove this work experience record when you save?')) return;
        remove.closest('tr').remove();
        updateRows();
        markChanged();
        (add.disabled ? save : add).focus();
    });

    function canClose() {
        return !saving && (!dirty || window.confirm('Close without saving your work experience changes?'));
    }
    close.addEventListener('click', () => { if (canClose()) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (!canClose()) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        request?.abort();
        trigger?.focus();
        if (saved) trigger?.dispatchEvent(new CustomEvent('work-experience-saved', { bubbles: true }));
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!state || !dirty || saving) return;
        const work = Array.from(records.children, row => ({
            record_index: row.dataset.recordIndex === '' ? null : Number(row.dataset.recordIndex),
            ...Object.fromEntries(Array.from(row.querySelectorAll('[data-work-field]'), field => [
                field.dataset.workField, field.dataset.workField === 'salary' ? salaryAmount(field.value) : field.value
            ]))
        }));
        saving = true;
        close.disabled = true;
        save.disabled = true;
        add.disabled = true;
        records.querySelectorAll('input, select, button').forEach(control => { control.disabled = true; });
        showError();
        status.textContent = 'Saving work experience…';
        let failure;
        try {
            state = await send({
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    csrf_token: dialog.dataset.csrf,
                    profile_id: state.profile_id,
                    revision: state.revision,
                    values: {work}
                })
            });
            dirty = false;
            saved = true;
            records.replaceChildren();
            state.values.work.forEach(appendRow);
            status.textContent = 'Work experience saved.';
        } catch (error) {
            failure = error;
            status.textContent = '';
        } finally {
            saving = false;
            close.disabled = false;
            save.disabled = !dirty;
            records.querySelectorAll('input, select, button').forEach(control => { control.disabled = false; });
            updateRows();
        }
        if (failure) showError(failure instanceof Error ? 'Work experience could not be saved. Try again.' : failure.message, failure.errors);
        else close.focus();
    });
})();
