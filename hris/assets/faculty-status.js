(() => {
    const dialog = document.querySelector('[data-faculty-status-dialog]');
    const results = document.getElementById('directory-results');
    if (!dialog || !results) return;

    const form = dialog.querySelector('form');
    const status = form.elements.namedItem('status');
    const confirmed = form.elements.namedItem('verified_by_hr');
    const verification = dialog.querySelector('[data-status-verification]');
    const facultyName = dialog.querySelector('[data-status-faculty-name]');
    const employeeId = dialog.querySelector('[data-status-employee-id]');
    const reviewDetail = dialog.querySelector('[data-status-review-detail]');
    const cancel = form.querySelector('[data-cancel-faculty-status]');
    const save = form.querySelector('[type="submit"]');
    const saveLabel = save.textContent;
    const alert = form.querySelector('[data-status-error]');
    let opener;
    let saving = false;

    function updateVerification() {
        const active = status.value === 'active';
        verification.hidden = !active;
        confirmed.disabled = !active;
        confirmed.required = active;
        if (!active) confirmed.checked = false;
    }

    results.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-edit-faculty-status]');
        if (!trigger) return;

        opener = trigger;
        form.reset();
        alert.hidden = true;
        form.action = 'faculty-status.php' + trigger.dataset.returnQuery;
        form.elements.namedItem('record_key').value = trigger.dataset.recordKey;
        form.elements.namedItem('revision').value = trigger.dataset.revision;
        status.value = trigger.dataset.status;
        facultyName.textContent = trigger.dataset.facultyName;
        employeeId.textContent = trigger.dataset.employeeId || 'Employee ID not recorded';
        reviewDetail.textContent = trigger.dataset.reviewDetail;
        reviewDetail.hidden = !trigger.dataset.reviewDetail;
        updateVerification();
        dialog.showModal();
    });

    function showError(message) {
        alert.querySelector('span').textContent = message;
        alert.hidden = false;
        alert.focus();
    }

    status.addEventListener('change', updateVerification);
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });
    form.addEventListener('submit', async (event) => {
        if (!window.synkHrisRefreshEmployees) return;
        event.preventDefault();
        if (saving) return;

        alert.hidden = true;
        saving = true;
        cancel.disabled = save.disabled = true;
        save.textContent = 'Saving…';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(form),
            });
            if (response.redirected && new URL(response.url).origin === window.location.origin) {
                window.location.assign(response.url);
                return;
            }
            const result = await response.json();
            if (response.ok && result.kind === 'success') {
                await window.synkHrisRefreshEmployees(dialog, opener, result.message);
                return;
            }
            showError(result.message || 'The HR status could not be saved.');
        } catch (error) {
            showError('Could not confirm the save. Your selection is still here.');
        } finally {
            saving = false;
            cancel.disabled = save.disabled = false;
            save.textContent = saveLabel;
        }
    });
})();
