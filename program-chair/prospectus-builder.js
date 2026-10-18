(function ($, config) {
    'use strict';
    if (!config) return;

    $(function () {
        const state = { versions: [], loaded: null, loading: 0, busy: false, programVersion: 0, headerVersion: 0, listVersion: 0 };
        let listRequest = null, headerRequest = null, targetsRequest = null;
        const id = value => Number(value) > 0 ? String(Number(value)) : '';
        const programId = () => id($('#program_id').val());
        const programLabel = () => $('#program_id option:selected').text().trim();
        const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
        const units = value => String(Math.round((Number(value) || 0) * 100) / 100);
        const post = (endpoint, data, success, type = 'text') => $.post(endpoint, { ...data, csrf_token: config.csrfToken }, success, type);
        const errorText = (xhr, fallback) => {
            const text = String(xhr.responseText || '');
            return text.startsWith('ERROR|') ? text.slice(6) : fallback;
        };

        function syncActions() {
            $('#program_id').prop('disabled', state.busy).trigger('change.select2');
            $('#btnCreateProspectus, #btnRefreshProspectuses').prop('disabled', state.busy || !programId());
            $('#btnCopyProspectus').prop('disabled', state.busy || !state.loaded || !!state.loading);
        }

        function setBusy(busy) {
            state.busy = busy;
            $('#createProspectusModal, #copyProspectusModal').find('button, input, select').prop('disabled', busy);
            $('#btnConfirmCreateProspectus').text(busy ? 'Creating...' : 'Create Prospectus');
            $('#btnConfirmCopyProspectus').text(busy ? 'Copying...' : 'Copy Prospectus');
            syncActions();
            renderVersions();
        }

        function updateUrl(prospectusId) {
            const url = new URL(window.location.href);
            if (prospectusId) url.searchParams.set('pid', prospectusId);
            else url.searchParams.delete('pid');
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        }

        function clearEditor() {
            state.loaded = null;
            $('#prospectus_id, #ys_prospectus_id, #ps_pys_id, #edit_ps_id').val('');
            $('#yearSemCard, #loadedProspectusCard').hide();
            $('#yearSemAccordion').empty();
            $('#yearSemModal, #subjectModal, #editSubjectModal').modal('hide');
            $(document).trigger('chair:prospectus-cleared');
            syncActions();
        }

        function listMessage(message) {
            $('#programProspectusRows').html(`<tr><td colspan="5" class="text-center text-muted py-4">${escape(message)}</td></tr>`);
        }

        function renderVersions() {
            if (!programId()) { listMessage('Select a program to view its prospectus versions.'); return; }
            if (!state.versions.length) { listMessage('No prospectus versions yet. Choose Create New Prospectus to start.'); return; }
            $('#programProspectusRows').html(state.versions.map(version => {
                const versionId = id(version.prospectus_id);
                const loaded = state.loaded && state.loaded.prospectus_id === versionId;
                const loading = state.loading === versionId;
                return `<tr${loaded ? ' class="table-active"' : ''}>
                    <td>${escape(version.cmo_no)}</td><td>${escape(version.effective_sy)}</td>
                    <td class="text-center">${Number(version.subject_count) || 0}</td><td class="text-center">${units(version.total_units)}</td>
                    <td class="text-end text-nowrap">${loaded ? '<span class="badge bg-label-primary me-2">Loaded</span>' : ''}
                        <button type="button" class="btn btn-sm btn-outline-primary btnLoadProgramProspectus" data-prospectus-id="${versionId}" ${state.busy || loading ? 'disabled' : ''}>${loading ? 'Loading...' : loaded ? 'Reload' : 'Load'}</button></td>
                </tr>`;
            }).join(''));
        }

        function refreshVersions() {
            const selectedProgram = programId();
            const requestVersion = ++state.listVersion;
            if (listRequest) listRequest.abort();
            if (!selectedProgram) {
                state.versions = [];
                $('#programProspectusStatus').text('Select a program to see its prospectuses.');
                renderVersions();
                return;
            }
            $('#programProspectusStatus').text('Loading prospectus versions...');
            listRequest = post(config.listEndpoint, { load_prospectus_by_program: 1, program_id: selectedProgram }, function (rows) {
                if (requestVersion !== state.listVersion || selectedProgram !== programId()) return;
                if (!Array.isArray(rows)) { listMessage('Unable to load prospectus versions. Choose Refresh to retry.'); return; }
                state.versions = rows.slice().sort((a, b) => String(b.effective_sy || '').localeCompare(String(a.effective_sy || ''), undefined, {numeric:true}) || Number(b.prospectus_id) - Number(a.prospectus_id));
                if (state.loaded && !state.versions.some(row => id(row.prospectus_id) === state.loaded.prospectus_id)) {
                    clearEditor();
                    updateUrl('');
                }
                $('#programProspectusStatus').text(`${rows.length} ${rows.length === 1 ? 'prospectus' : 'prospectuses'} in this program. Newer effectivity years appear first.`);
                renderVersions();
            }, 'json').fail(function (xhr, status) {
                if (status === 'abort' || requestVersion !== state.listVersion || selectedProgram !== programId()) return;
                $('#programProspectusStatus').text('Prospectus versions could not be loaded.');
                listMessage(errorText(xhr, 'Unable to load prospectus versions. Choose Refresh to retry.'));
            });
        }

        function changeProgram() {
            state.programVersion++;
            state.headerVersion++;
            if (headerRequest) headerRequest.abort();
            state.loading = 0;
            state.versions = [];
            clearEditor();
            updateUrl('');
            renderVersions();
            refreshVersions();
        }

        function loadProspectus(prospectusId, expectedProgram = programId()) {
            const requestedId = id(prospectusId);
            if (!requestedId) return;
            const requestVersion = ++state.headerVersion;
            if (headerRequest) headerRequest.abort();
            state.loading = requestedId;
            clearEditor();
            updateUrl('');
            renderVersions();
            headerRequest = post(config.headerEndpoint, { load_header: 1, prospectus_id: requestedId }, function (response) {
                if (requestVersion !== state.headerVersion) return;
                state.loading = 0;
                const parts = String(response).split('|');
                if (parts[0] !== 'OK' || !id(parts[1])) {
                    syncActions(); renderVersions();
                    Swal.fire('Unable to Load', parts[1] || 'This prospectus is unavailable.', 'error');
                    return;
                }
                const loadedProgram = id(parts[1]);
                if (expectedProgram && expectedProgram !== loadedProgram) {
                    syncActions(); renderVersions();
                    Swal.fire('Unable to Load', 'The prospectus does not belong to the selected program.', 'error');
                    return;
                }
                if (!$('#program_id option').toArray().some(option => option.value === loadedProgram)) {
                    syncActions(); renderVersions();
                    Swal.fire('Program Unavailable', 'This prospectus belongs to a program that is no longer available in this builder.', 'info');
                    return;
                }
                $('#program_id').val(loadedProgram).trigger('change.select2');
                state.loaded = { prospectus_id: requestedId, program_id: loadedProgram, cmo_no: parts[2] || '', effective_sy: parts[3] || '' };
                $('#prospectus_id, #ys_prospectus_id').val(requestedId);
                $('#loadedProgramName').text(programLabel());
                $('#loadedCmo').text(state.loaded.cmo_no);
                $('#loadedEffectivity').text(state.loaded.effective_sy);
                $('#loadedProspectusCard, #yearSemCard').show();
                $('#yearSemAccordion').html('<p class="text-muted mb-0">Loading year/semester structure...</p>');
                updateUrl(requestedId);
                syncActions(); renderVersions();
                refreshVersions();
                window.loadYearSem(requestedId, false);
            }).fail(function (xhr, status) {
                if (status === 'abort' || requestVersion !== state.headerVersion) return;
                state.loading = 0;
                syncActions(); renderVersions();
                Swal.fire('Unable to Load', errorText(xhr, 'The prospectus could not be loaded. Please try again.'), 'error');
            });
        }

        $('#program_id').on('change', changeProgram);
        $('#btnRefreshProspectuses').on('click', refreshVersions);
        $(document).on('click', '.btnLoadProgramProspectus', function () {
            if (!state.busy) loadProspectus($(this).attr('data-prospectus-id'));
        });
        $(document).on('chair:prospectus-structure-loaded', function (event, prospectusId) {
            if (state.loaded && state.loaded.prospectus_id === id(prospectusId)) refreshVersions();
        });

        $('#btnCreateProspectus').on('click', function () {
            if (state.busy || !programId()) return;
            $('#create_program_label').val(programLabel());
            $('#create_cmo_no, #create_effective_sy').val('');
            $('#createProspectusModal').modal('show');
        });
        $('#createProspectusForm').on('submit', function (event) {
            event.preventDefault();
            if (state.busy || !programId() || !this.reportValidity()) return;
            const selectedProgram = programId();
            const payload = {save_header:1, program_id:selectedProgram, cmo_no:$('#create_cmo_no').val().trim(), effective_sy:$('#create_effective_sy').val().trim()};
            if (!payload.cmo_no || !payload.effective_sy) return;
            setBusy(true);
            post(config.headerEndpoint, payload, function (response) {
                const parts = String(response).split('|');
                if (parts[0] === 'OK' && id(parts[1])) {
                    $('#createProspectusModal').modal('hide');
                    loadProspectus(parts[1], selectedProgram);
                } else if (parts[0] === 'EXISTING' && id(parts[1])) {
                    Swal.fire({icon:'info', title:'Prospectus Already Exists', text:'This program already has that CMO number and effectivity year. Load the existing version?', showCancelButton:true, confirmButtonText:'Load Existing Prospectus', cancelButtonText:'Keep Editing'}).then(result => {
                        if (result.isConfirmed && programId() === selectedProgram && !state.busy) {
                            $('#createProspectusModal').modal('hide');
                            loadProspectus(parts[1], selectedProgram);
                        }
                    });
                } else Swal.fire('Unable to Create', parts[2] || parts[1] || 'The prospectus could not be created.', 'error');
            }).fail(xhr => Swal.fire('Unable to Create', errorText(xhr, 'The request could not be completed. Refresh the prospectus list before retrying.'), 'error'))
                .always(() => setBusy(false));
        });

        $('#copy_target_program').select2({width:'100%', dropdownParent:$('#copyProspectusModal'), placeholder:'Select Destination Program'});
        $('#btnCopyProspectus').on('click', function () {
            if (state.busy || !state.loaded || state.loading) return;
            const source = {...state.loaded};
            const programVersion = state.programVersion;
            $('#copy_source_label').val(`${programLabel()}\n${source.cmo_no} — ${source.effective_sy}`);
            $('#copy_cmo_no').val(source.cmo_no);
            $('#copy_effective_sy').val(source.effective_sy);
            if (targetsRequest) targetsRequest.abort();
            $('#btnCopyProspectus').prop('disabled', true);
            targetsRequest = post(config.copyEndpoint, {load_programs:1}, function (programs) {
                if (programVersion !== state.programVersion || !state.loaded || source.prospectus_id !== state.loaded.prospectus_id) return;
                const select = $('#copy_target_program').empty().append(new Option('Select Destination Program', ''));
                (Array.isArray(programs) ? programs : []).forEach(program => {
                    let label = program.program_name;
                    if (program.major) label += ` major in ${program.major}`;
                    label += ` (${program.program_code})`;
                    const destinationId = id(program.program_id);
                    select.append(new Option(label, destinationId));
                    if (!$('#program_id option').toArray().some(option => option.value === destinationId)) {
                        $('#program_id').append(new Option(label, destinationId));
                    }
                });
                select.val(source.program_id).trigger('change.select2');
                $('#copyProspectusModal').modal('show');
            }, 'json').fail(function (xhr, status) {
                if (status !== 'abort') Swal.fire('Unable to Copy', errorText(xhr, 'Destination programs could not be loaded. Please try again.'), 'error');
            }).always(syncActions);
        });
        $('#copyProspectusForm').on('submit', function (event) {
            event.preventDefault();
            if (state.busy || !state.loaded || !this.reportValidity()) return;
            const destination = id($('#copy_target_program').val());
            const payload = {copy_prospectus:1, source_prospectus_id:state.loaded.prospectus_id, target_program_id:destination, cmo_no:$('#copy_cmo_no').val().trim(), effective_sy:$('#copy_effective_sy').val().trim()};
            if (!destination || !payload.cmo_no || !payload.effective_sy) return;
            setBusy(true);
            post(config.copyEndpoint, payload, function (response) {
                const parts = String(response).split('|');
                if (parts[0] !== 'OK' || !id(parts[1])) {
                    Swal.fire('Unable to Copy', parts[1] || 'The prospectus could not be copied.', 'error');
                    return;
                }
                $('#copyProspectusModal').modal('hide');
                $('#program_id').val(destination).trigger('change.select2');
                state.programVersion++;
                state.versions = [];
                loadProspectus(parts[1], destination);
            }).fail(xhr => Swal.fire('Unable to Copy', errorText(xhr, 'The request could not be completed. Refresh the prospectus list before retrying.'), 'error'))
                .always(() => setBusy(false));
        });

        syncActions();
        if (config.initialProspectusId) loadProspectus(config.initialProspectusId, '');
    });
})(jQuery, window.chairProspectusConfig);
