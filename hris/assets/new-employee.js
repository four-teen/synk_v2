(() => {
    function fillTemporaryEmployeeId(form) {
        const employeeId = form.elements.namedItem('employee_id');
        const names = ['first_name', 'middle_name', 'surname'].map((name) => form.elements.namedItem(name));
        let manualId = false;

        function update() {
            if (manualId) return;
            const [first, middle, last] = names.map((field) => field.value.toUpperCase().match(/\p{L}/u)?.[0] ?? '');
            employeeId.value = first && last ? `${first}${middle}${last}${form.dataset.temporaryIdSuffix}` : '';
        }

        for (const field of names) field.addEventListener('input', update);
        employeeId.addEventListener('input', () => { manualId = employeeId.value.trim() !== ''; });
        employeeId.addEventListener('blur', update);
        form.addEventListener('reset', () => { manualId = false; });
    }

    function employeeForm(dialog, onSaved) {
        const form = dialog.querySelector('form');
        const category = form.elements.namedItem('personnel_category');
        const classification = form.elements.namedItem('employment_classification');
        const position = form.elements.namedItem('position_id');
        const positionClassifications = JSON.parse(position.dataset.requiredClassifications);
        const positionMarker = form.querySelector('[data-position-required]');
        const campus = form.elements.namedItem('campus_name');
        const college = form.elements.namedItem('college_name');
        const cancel = form.querySelector('[data-cancel-employee-form]');
        const save = form.querySelector('[type="submit"]');
        const saveLabel = save.textContent;
        const alert = form.querySelector('[data-employee-error]');
        const personal = form.querySelector('details');
        const groups = form.querySelectorAll('[data-personnel-for]');
        const errorsByField = new Map(Array.from(form.querySelectorAll('[data-error-for]'), (element) => [element.dataset.errorFor, element]));
        let positionPicker;
        let saving = false;

        function requiresPosition() {
            const employment = classification?.value ?? form.dataset.employmentClassification ?? '';
            return category.value === 'non_teaching' && positionClassifications.includes(employment.trim().toLowerCase());
        }

        function updatePositionRequirement() {
            const required = requiresPosition();
            position.required = required && !positionPicker;
            positionMarker.hidden = !required;
            positionPicker?.next('.select2').find('[role="combobox"]').attr('aria-required', String(required));

            const error = errorsByField.get('position_id');
            if (!required && (!position.value || position.disabled) && !error.hidden) {
                position.removeAttribute('aria-invalid');
                error.hidden = true;
                if ([...errorsByField.values()].every((detail) => detail.hidden)) alert.hidden = true;
            }
        }

        function updateColleges() {
            if (!college) return;
            let available = false;
            for (const option of college.querySelectorAll('[data-campuses]')) {
                const matches = JSON.parse(option.dataset.campuses).includes(campus.value);
                option.hidden = !matches;
                option.disabled = !matches;
                available = available || matches;
            }
            if (college.selectedOptions[0]?.disabled) college.value = '';
            college.disabled = category.value !== 'teaching' || !available;
            college.options[0].textContent = !campus.value ? 'Select a campus first' : (available ? 'Select college' : 'No colleges listed');
        }

        function updateCategory() {
            for (const group of groups) {
                group.hidden = group.dataset.personnelFor !== category.value;
                for (const field of group.querySelectorAll('input, select')) field.disabled = group.hidden;
            }
            updateColleges();
            updatePositionRequirement();
            positionPicker?.trigger('change.select2');
        }

        function clearErrors() {
            alert.hidden = true;
            form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
            for (const error of errorsByField.values()) {
                error.textContent = '';
                error.hidden = true;
            }
        }

        function showError(message, errors = {}) {
            alert.querySelector('span').textContent = message;
            alert.hidden = false;
            let firstError;
            for (const [key, detail] of Object.entries(errors)) {
                const control = form.elements.namedItem(key);
                const field = control instanceof RadioNodeList ? control[0] : control;
                const error = errorsByField.get(key);
                if (!field || !error) continue;
                if (personal?.contains(field)) personal.open = true;
                field.setAttribute('aria-invalid', 'true');
                error.textContent = detail;
                error.hidden = false;
                firstError ??= field;
            }
            if (firstError === position && positionPicker) positionPicker.select2('open');
            else (firstError || alert).focus();
        }

        form.addEventListener('change', (event) => {
            if (event.target.name === 'personnel_category') updateCategory();
            if (event.target === campus) updateColleges();
            if (event.target === classification) updatePositionRequirement();
        });
        cancel.addEventListener('click', () => dialog.close());
        dialog.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });
        dialog.addEventListener('close', () => positionPicker?.select2('close'));
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving) return;
            clearErrors();
            if (requiresPosition() && !position.value) {
                const message = 'Select a non-teaching position from the catalog.';
                showError(message, { position_id: message });
                return;
            }
            saving = true;
            cancel.disabled = save.disabled = true;
            save.textContent = 'Saving…';
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: onSaved ? { Accept: 'application/json' } : {},
                    body: new FormData(form),
                });
                if (response.redirected && new URL(response.url).origin === window.location.origin) {
                    window.location.assign(response.url);
                    return;
                }
                const result = await response.json();
                if (response.ok && onSaved && result.message) {
                    await onSaved(result.message);
                    return;
                }
                if (response.ok && result.url) {
                    window.location.assign(result.url);
                    return;
                }
                showError(result.message || 'The details could not be saved.', result.errors);
            } catch (error) {
                showError('Could not confirm the save. Your entries are still here.');
            } finally {
                saving = false;
                cancel.disabled = save.disabled = false;
                save.textContent = saveLabel;
            }
        });

        return (prepare) => {
            form.reset();
            if (personal) personal.open = false;
            clearErrors();
            prepare?.(form);
            updateCategory();
            dialog.showModal();
            if (!positionPicker && window.jQuery?.fn.select2) {
                positionPicker = window.jQuery(position).select2({
                    width: '100%',
                    dropdownParent: window.jQuery(dialog),
                    placeholder: 'Select a position',
                    allowClear: true,
                });
                updatePositionRequirement();
            }
        };
    }

    const newEmployee = document.querySelector('[data-new-employee-dialog]');
    if (newEmployee) {
        fillTemporaryEmployeeId(newEmployee.querySelector('form'));
        const open = employeeForm(newEmployee);
        document.querySelector('[data-new-employee]').addEventListener('click', () => open());
    }

    const personnel = document.querySelector('[data-employee-personnel-dialog]');
    if (personnel) {
        let opener;
        const refresh = window.synkHrisRefreshEmployees;
        const open = employeeForm(personnel, refresh && ((message) => refresh(personnel, opener, message)));
        const action = personnel.querySelector('form').action;
        document.getElementById('directory-results')?.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-edit-personnel]');
            if (!trigger) return;
            opener = trigger;
            const values = trigger.dataset;
            open((form) => {
                form.action = new URL(values.returnQuery, action).href;
                form.dataset.employmentClassification = values.employmentClassification;
                form.elements.namedItem('record_key').value = values.recordKey;
                form.elements.namedItem('revision').value = values.revision;
                form.elements.namedItem('personnel_category').value = values.category;
                form.elements.namedItem('position_id').value = values.positionId;
                form.elements.namedItem('office_unit').value = values.office;
                form.elements.namedItem('plantilla_item_number').value = values.item;
                personnel.querySelector('[data-personnel-name]').textContent = values.employeeName;
                personnel.querySelector('[data-personnel-suggestion]').hidden = values.suggested !== 'true';
            });
        });
    }
})();
