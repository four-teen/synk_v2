(() => {
    const dialog = document.querySelector('[data-delete-employee-dialog]');
    const results = document.getElementById('directory-results');
    if (!dialog || !results) return;

    const form = dialog.querySelector('form');
    const endpoint = form.action;
    const reviewButton = form.querySelector('[data-review-delete-employee]');
    const confirm = form.querySelector('[data-confirm-delete-employee]');
    const cancel = form.querySelector('[data-cancel-delete-employee]');
    const alert = dialog.querySelector('[data-delete-employee-error]');
    const review = dialog.querySelector('[data-delete-employee-review]');
    const reviewTitle = review.querySelector('h3');
    const records = review.querySelector('ul');
    const protectedSection = dialog.querySelector('[data-delete-employee-protected]');
    const protectedTitle = protectedSection.querySelector('h3');
    const protectedRecords = protectedSection.querySelector('ul');
    const description = dialog.querySelector('#delete-employee-description');
    const initialDescription = description.textContent;
    const reviewToken = form.elements.namedItem('review_token');
    let pending = false;

    function clearReview() {
        reviewToken.value = '';
        records.replaceChildren();
        protectedRecords.replaceChildren();
        review.hidden = protectedSection.hidden = true;
        reviewButton.hidden = false;
        confirm.hidden = confirm.disabled = true;
        description.textContent = initialDescription;
    }

    function showReview(plan) {
        for (const entry of plan.protected || []) {
            const item = document.createElement('li');
            const table = document.createElement('small');
            item.textContent = entry.title;
            if (entry.table) {
                table.textContent = entry.table;
                item.append(table);
            }
            protectedRecords.append(item);
        }

        let total = 0;
        for (const entry of plan.records) {
            total += entry.count;
            const item = document.createElement('li');
            const heading = document.createElement('strong');
            const table = document.createElement('small');
            heading.textContent = entry.title;
            table.textContent = `${entry.table} · ${entry.count} ${entry.count === 1 ? 'record' : 'records'}`;
            if (!entry.fields.length) {
                item.append(heading, table);
                records.append(item);
                continue;
            }

            const detail = document.createElement('details');
            const summary = document.createElement('summary');
            const fields = document.createElement('dl');
            detail.open = entry.table === 'tbl_faculty' || entry.table === 'tbl_hr_faculty_statuses';
            summary.append(heading, table);
            fields.className = 'hris-preview-values';
            for (const field of entry.fields) {
                const row = document.createElement('div');
                const label = document.createElement('dt');
                const value = document.createElement('dd');
                label.textContent = field.label;
                value.textContent = field.value;
                row.append(label, value);
                fields.append(row);
            }
            detail.append(summary, fields);
            item.append(detail);
            records.append(item);
        }
        const canDelete = Boolean(plan.review_token);
        reviewToken.value = plan.review_token || '';
        review.hidden = false;
        protectedSection.hidden = !protectedRecords.children.length;
        confirm.hidden = !canDelete;
        reviewButton.hidden = true;
        reviewTitle.textContent = canDelete ? 'Records to be deleted' : 'Employee records reviewed';
        description.textContent = canDelete
            ? `Delete employee permanently removes ${total} ${total === 1 ? 'record' : 'records'}. Review the list below before confirming.`
            : 'The employee has linked records that Kawani must keep.';
        (canDelete ? reviewTitle : protectedTitle).focus();
    }

    results.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-delete-employee]');
        if (!trigger) return;

        clearReview();
        const { facultyId, employeeName, employeeId, returnQuery } = trigger.dataset;
        form.elements.namedItem('faculty_id').value = facultyId;
        form.action = new URL(returnQuery, endpoint).href;
        dialog.querySelector('[data-delete-employee-name]').textContent = employeeName;
        const number = dialog.querySelector('[data-delete-employee-number]');
        number.textContent = employeeId;
        number.hidden = !employeeId;
        alert.hidden = true;
        dialog.showModal();
    });

    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', clearReview);
    dialog.addEventListener('cancel', (event) => { if (pending) event.preventDefault(); });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending) return;

        const deleting = event.submitter === confirm;
        if (deleting && !reviewToken.value) return;
        if (!deleting) clearReview();
        const button = deleting ? confirm : reviewButton;
        const label = button.textContent;
        const data = new FormData(form);
        data.set('operation', deleting ? 'delete' : 'review');

        pending = true;
        reviewButton.disabled = confirm.disabled = cancel.disabled = true;
        button.textContent = deleting ? 'Deleting…' : 'Checking…';
        alert.hidden = true;
        let message;
        try {
            const response = await fetch(form.action, { method: 'POST', body: data });
            if (response.redirected && new URL(response.url).origin === window.location.origin) {
                window.location.assign(response.url);
                return;
            }
            const result = await response.json();
            if (response.ok && deleting && result.url) {
                window.location.assign(result.url);
                return;
            }
            if (response.ok && !deleting && Array.isArray(result.records) && result.records.length) {
                showReview(result);
                return;
            }
            message = result.message || 'Kawani could not verify the records for deletion.';
        } catch (failure) {
            message = deleting ? 'Kawani could not confirm the deletion. Refresh Employee Profiles to check the record.'
                : 'Kawani could not load the deletion review. Try again.';
        } finally {
            pending = false;
            reviewButton.disabled = cancel.disabled = false;
            confirm.disabled = !reviewToken.value;
            button.textContent = label;
        }
        clearReview();
        alert.querySelector('span').textContent = message;
        alert.hidden = false;
        alert.focus();
    });
})();
