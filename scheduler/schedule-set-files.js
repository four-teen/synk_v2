/* College-term CSV set controls. Scheduling and workload editors stay unchanged. */
(function () {
    'use strict';
    window.SynkScheduleSetFiles = {
        init(config) {
            const fileInput = document.getElementById('scheduleSetFileInput');
            const exportButton = document.getElementById('btnExportScheduleFile');
            const importButton = document.getElementById('btnImportScheduleFile');
            const status = document.getElementById('scheduleFileSetStatus');
            if (!fileInput || !exportButton || !importButton) return;
            let busy = false;
            const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const scopeKey = scope => `${config.collegeId}:${scope.ay_id}:${scope.semester}`;
            function controls() {
                const scope = config.getScope();
                const disabled = busy || !scope.ay_id || !scope.semester;
                exportButton.disabled = disabled;
                importButton.disabled = disabled;
            }
            function setBusy(value) {
                busy = value;
                controls();
                exportButton.innerHTML = value ? '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Please wait' : '<i class="bx bx-download"></i> Export Set CSV';
            }
            async function request(action, scope, extra = {}) {
                const body = new FormData();
                const values = { [action]: 1, csrf_token: config.csrfToken, ay_id: scope.ay_id, semester: scope.semester,
                    scheduler_term_key: `${scope.ay_id}:${scope.semester}`, scheduler_college_id: config.collegeId, ...extra };
                for (const [key, value] of Object.entries(values)) body.append(key, value);
                const response = await fetch('../backend/query_class_schedule.php', { method: 'POST', credentials: 'same-origin', cache: 'no-store', body });
                let result;
                try { result = await response.json(); } catch (_) { throw new Error('The server could not read this request. Refresh the page and try again.'); }
                if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Unable to process this set.');
                return result;
            }
            function current(scope) {
                if (scopeKey(scope) !== scopeKey(config.getScope())) throw new Error('The selected term changed. Start this action again in the intended term.');
            }
            async function showError(error) {
                await Swal.fire({ icon: 'error', title: 'Set could not be processed', text: error.message || 'Please try again.' });
            }
            exportButton.addEventListener('click', async () => {
                if (busy) return;
                const scope = { ...config.getScope() };
                try {
                    setBusy(true);
                    const year = document.querySelector('#ay_id option:checked')?.textContent.trim() || '';
                    const semester = document.querySelector('#semester option:checked')?.textContent.trim() || '';
                    const defaultName = [year, semester].filter(Boolean).join(' - ');
                    const naming = await Swal.fire({
                        title: 'Export schedule set',
                        input: 'text',
                        inputLabel: 'Set name',
                        inputValue: defaultName,
                        inputAttributes: { maxlength: '120', autocomplete: 'off' },
                        text: 'Edit or add to this name. The .csv extension is added automatically.',
                        showCancelButton: true,
                        confirmButtonText: 'Download CSV',
                        cancelButtonText: 'Cancel',
                        allowOutsideClick: false,
                        focusConfirm: false,
                        didOpen() {
                            const input = Swal.getInput();
                            if (input) {
                                input.focus();
                                input.setSelectionRange(input.value.length, input.value.length);
                            }
                        },
                        preConfirm(value) {
                            const name = String(value || '').trim().replace(/\.csv$/i, '').trim();
                            if (!name) {
                                Swal.showValidationMessage('Enter a name for this set.');
                                return false;
                            }
                            return name;
                        }
                    });
                    if (!naming.isConfirmed) return;
                    current(scope);
                    const name = naming.value;
                    const result = await request('export_schedule_file', scope, { set_name: name });
                    current(scope);
                    const blob = new Blob([result.csv], { type: 'text/csv;charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url; link.download = result.filename;
                    document.body.appendChild(link); link.click(); link.remove();
                    window.setTimeout(() => URL.revokeObjectURL(url), 60000);
                    status.textContent = `Download requested: ${result.filename} — ${result.program_count} program(s), ${result.meeting_count} meeting(s), ${result.workload_count} workload assignment(s). Keep the file before preparing another version.`;
                    await Swal.fire({ icon: 'success', title: 'Set download started',
                        text: `${result.filename} includes schedules and workload for ${result.college}, ${result.term}. Check that the CSV is saved on your computer. Current data is unchanged.` });
                } catch (error) { await showError(error); }
                finally { setBusy(false); }
            });
            importButton.addEventListener('click', () => {
                if (busy) return;
                fileInput.value = '';
                fileInput.click();
            });
            fileInput.addEventListener('change', async () => {
                const file = fileInput.files[0];
                if (!file || busy) return;
                if (!/\.csv$/i.test(file.name) || file.size > 8 * 1024 * 1024) {
                    await showError(new Error('Choose an original Synk CSV set up to 8 MB.'));
                    fileInput.value = '';
                    return;
                }
                const scope = { ...config.getScope() };
                try {
                    setBusy(true);
                    status.textContent = `Checking ${file.name}… Current data stays unchanged until you confirm import.`;
                    const preview = await request('preview_schedule_file', scope, { schedule_set_file: file });
                    current(scope);
                    const item = preview.summary;
                    const confirmation = await Swal.fire({ icon: 'question', title: 'Import this set?',
                        html: `<div class="text-start"><p><strong>${escape(item.set_name)}</strong><br>${escape(item.college)}<br>${escape(item.term)}</p>
                            <p>${escape(item.program_count)} program(s) · ${escape(item.offering_count)} offering(s)<br>
                            ${escape(item.meeting_count)} meeting(s) · ${escape(item.workload_count)} workload assignment(s)</p>
                            <p>${escape(item.changed_meeting_count)} meeting(s) will change.<br>Workload will change in ${escape(item.changed_class_workload_count)} class(es).</p>
                            <p class="mb-0"><strong>This replaces schedules and workload for this college term.</strong> Export your current version first if you want to keep it.</p></div>`,
                        showCancelButton: true, confirmButtonText: 'Import Schedule & Workload', cancelButtonText: 'Cancel', allowOutsideClick: false });
                    if (!confirmation.isConfirmed) {
                        status.textContent = 'Import cancelled. Current schedules and workload are unchanged.';
                        return;
                    }
                    current(scope);
                    status.textContent = `Restoring ${item.set_name}…`;
                    const restored = await request('apply_schedule_file', scope, { schedule_set_file: file, preview_token: preview.preview_token });
                    current(scope);
                    status.textContent = `Imported ${item.set_name}: schedules and workload restored together for ${item.term}.`;
                    config.afterImport();
                    await Swal.fire({ icon: 'success', title: 'Set imported', text: restored.message });
                } catch (error) {
                    status.textContent = 'Import was not completed. Review the message and preview the file again.';
                    await showError(error);
                } finally { fileInput.value = ''; setBusy(false); }
            });
            document.querySelectorAll('#ay_id, #semester').forEach(input => {
                input.addEventListener('change', () => {
                    status.textContent = 'Export and import cover every program in the selected college term. Keep your original downloaded CSV files.';
                    controls();
                });
            });
            controls();
        }
    };
})();
