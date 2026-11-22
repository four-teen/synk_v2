(() => {
    const dialog = document.querySelector('[data-position-catalog]');
    if (!dialog) return;

    const form = dialog.querySelector('[data-position-form]');
    const fields = form.querySelector('fieldset');
    const { operation, position_id: positionId, version, position_title: title, salary_grade: grade } = form.elements;
    const save = form.querySelector('[type="submit"]');
    const backToAdd = form.querySelector('[data-cancel-position-edit]');
    const close = dialog.querySelector('[data-close-position-catalog]');
    const success = dialog.querySelector('[data-position-success]');
    const error = dialog.querySelector('[data-position-error]');
    const search = dialog.querySelector('[data-position-search]');
    const rows = dialog.querySelector('[data-position-rows]');
    const table = rows.closest('table');
    const rowTemplate = dialog.querySelector('[data-position-row]');
    const empty = dialog.querySelector('[data-position-empty]');
    const loadingStatus = dialog.querySelector('[data-position-loading]');
    const retry = dialog.querySelector('[data-position-retry]');
    const confirm = document.querySelector('[data-position-confirm]');
    const deleteError = confirm.querySelector('[data-delete-position-error]');
    const cancelDelete = confirm.querySelector('[data-cancel-position-delete]');
    const confirmDelete = confirm.querySelector('[data-confirm-position-delete]');
    const sorter = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
    let positions = new Map();
    let loaded = false;
    let loading = false;
    let saving = false;
    let addDraft = { title: '', grade: '' };
    let toDelete;
    let employeeOpener;

    function showMessage(element, message = '') {
        element.querySelector('span').textContent = message;
        element.hidden = !message;
    }

    function updateControls() {
        const locked = loading || saving || !loaded;
        fields.disabled = search.disabled = backToAdd.disabled = locked;
        close.disabled = cancelDelete.disabled = confirmDelete.disabled = saving;
        rows.querySelectorAll('button').forEach((button) => { button.disabled = locked; });
    }

    function filterRows() {
        const terms = search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
        let found = false;
        for (const row of rows.rows) {
            const position = positions.get(Number(row.dataset.positionId));
            const searchable = `${position.position_title} ${position.salary_grade === null ? '' : `SG ${position.salary_grade}`}`.toLocaleLowerCase();
            row.hidden = !terms.every((term) => searchable.includes(term));
            found = found || !row.hidden;
        }
        table.hidden = !found;
        empty.hidden = !loaded || found;
        empty.textContent = positions.size ? 'No positions match your search.' : 'Add an approved position to start the catalog.';
    }

    function renderCatalog() {
        const sorted = [...positions.values()].sort((a, b) => sorter.compare(a.position_title, b.position_title));
        const content = document.createDocumentFragment();
        for (const position of sorted) {
            const row = rowTemplate.content.firstElementChild.cloneNode(true);
            row.dataset.positionId = position.position_id;
            row.querySelector('[data-position-title]').textContent = position.position_title;
            row.querySelector('[data-position-grade]').textContent = position.salary_grade === null ? 'Not recorded' : `SG ${position.salary_grade}`;
            row.querySelector('[data-edit-position]').setAttribute('aria-label', `Edit ${position.position_title}`);
            row.querySelector('[data-delete-position]').setAttribute('aria-label', `Delete ${position.position_title}`);
            content.append(row);
        }
        rows.replaceChildren(content);
        filterRows();
        updateControls();

        for (const select of document.querySelectorAll('[data-position-picker]')) {
            const selected = select.value;
            const options = sorted.map((position) => {
                const text = position.salary_grade === null ? position.position_title : `${position.position_title} · SG ${position.salary_grade}`;
                return new Option(text, position.position_id);
            });
            select.replaceChildren(new Option('Select a position', '', true), ...options);
            select.value = positions.has(Number(selected)) ? selected : '';
            select.parentElement.querySelector('[data-empty-position-picker]').hidden = positions.size > 0;
            window.jQuery?.(select).trigger('change.select2');
        }
    }

    async function requestCatalog(body) {
        const response = await fetch(form.action, {
            method: body ? 'POST' : 'GET',
            headers: { Accept: 'application/json' },
            body,
        });
        if (response.redirected) throw new Error('Your Kawani session changed. Reload the page and sign in again.');
        if (!response.headers.get('Content-Type')?.includes('application/json')) {
            throw new Error(response.status === 403 ? 'Kawani Administrator access is required.' : 'Kawani could not confirm this request. Try again.');
        }
        const result = await response.json().catch(() => { throw new Error('Kawani could not confirm this request. Try again.'); });
        if (!response.ok) throw Object.assign(new Error(result.message), { code: result.code });
        return result;
    }

    async function loadCatalog() {
        if (loading) return;
        loading = true;
        retry.hidden = true;
        loadingStatus.hidden = false;
        updateControls();
        try {
            const result = await requestCatalog();
            positions = new Map(result.positions.map((position) => [position.position_id, position]));
            loaded = true;
            renderCatalog();
        } catch (failure) {
            showMessage(error, failure instanceof TypeError ? 'Could not load positions. Check your connection and try again.' : failure.message);
            retry.hidden = false;
        } finally {
            loading = false;
            loadingStatus.hidden = true;
            updateControls();
        }
    }

    function openCatalog(trigger) {
        if (dialog.open) return;
        const mobileMenu = trigger?.closest('.hris-mobile-nav .hris-management-menu');
        if (mobileMenu) {
            mobileMenu.open = false;
            mobileMenu.querySelector('summary').focus();
        }
        employeeOpener = trigger?.matches('[data-empty-position-picker]') ? trigger : null;
        showMessage(error);
        dialog.showModal();
        loadCatalog();
    }

    function setEditor(position) {
        if (position && operation.value === 'add') addDraft = { title: title.value, grade: grade.value };

        operation.value = position ? 'edit' : 'add';
        positionId.value = position?.position_id ?? '';
        version.value = position?.version ?? '';
        title.value = position ? position.position_title : addDraft.title;
        grade.value = position ? position.salary_grade ?? '' : addDraft.grade;
        form.querySelector('[data-position-mode]').textContent = position ? 'Edit position' : 'Add a position';
        save.querySelector('span').textContent = position ? 'Save changes' : 'Add position';
        save.querySelector('i').className = position ? 'bx bx-save' : 'bx bx-plus';
        backToAdd.hidden = !position;
    }

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-position-catalog]');
        if (trigger) openCatalog(trigger);
    });
    search.addEventListener('input', filterRows);
    retry.addEventListener('click', () => {
        showMessage(error);
        loadCatalog();
    });
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        if (!employeeOpener?.hidden) return;
        const field = employeeOpener.parentElement;
        (field.querySelector('.select2-selection') || field.querySelector('select')).focus();
    });
    for (const modal of [dialog, confirm]) {
        modal.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });
    }
    backToAdd.addEventListener('click', () => {
        setEditor(null);
        showMessage(error);
        showMessage(success);
        title.focus();
    });

    rows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-edit-position], [data-delete-position]');
        if (!button || loading || saving) return;
        const position = positions.get(Number(button.closest('tr').dataset.positionId));
        if (button.hasAttribute('data-edit-position')) {
            setEditor(position);
            showMessage(error);
            showMessage(success);
            title.focus();
        } else {
            toDelete = position;
            confirm.querySelector('[data-delete-position-name]').textContent = position.position_title;
            showMessage(deleteError);
            confirm.showModal();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (loading || saving || !loaded) return;
        const body = new FormData(form);
        saving = true;
        updateControls();
        showMessage(error);
        showMessage(success);
        try {
            const { position, message } = await requestCatalog(body);
            positions.set(position.position_id, position);
            if (operation.value === 'edit') version.value = position.version;
            renderCatalog();
            showMessage(success, message);
        } catch (failure) {
            if (failure.code === 'stale_position') await loadCatalog();
            showMessage(error, failure instanceof TypeError ? 'Could not confirm the save. Your entries are still here.' : failure.message);
        } finally {
            saving = false;
            updateControls();
            save.focus({ preventScroll: true });
        }
    });

    cancelDelete.addEventListener('click', () => confirm.close());
    confirmDelete.addEventListener('click', async () => {
        if (saving || !toDelete) return;
        const body = new FormData();
        body.set('operation', 'delete');
        body.set('position_id', toDelete.position_id);
        body.set('version', toDelete.version);
        body.set('csrf_token', form.elements.csrf_token.value);
        saving = true;
        updateControls();
        showMessage(deleteError);
        showMessage(error);
        showMessage(success);
        try {
            const result = await requestCatalog(body);
            positions.delete(result.deleted_id);
            if (Number(positionId.value) === result.deleted_id) setEditor(null);
            confirm.close();
            renderCatalog();
            showMessage(success, result.message);
        } catch (failure) {
            if (failure.code === 'stale_position') await loadCatalog();
            showMessage(deleteError, failure instanceof TypeError ? 'Could not confirm the deletion. Open the catalog again to check the entry.' : failure.message);
        } finally {
            saving = false;
            updateControls();
            (confirm.open ? cancelDelete : search).focus({ preventScroll: true });
        }
    });

    if (window.location.hash === '#position-catalog') {
        history.replaceState(history.state, '', window.location.pathname + window.location.search);
        openCatalog();
    }
})();
