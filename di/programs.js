(function () {
    'use strict';
    const config = JSON.parse(document.getElementById('diProgramConfig').textContent);
    const el = id => document.getElementById(id);
    const campus = el('programCampus'), college = el('programCollege'), form = el('programForm');
    const modal = new bootstrap.Modal(el('programModal'));
    const campuses = new Map(config.campuses.map(row => [String(row.campus_id), row]));
    const colleges = new Map(config.colleges.map(row => [String(row.college_id), row]));
    let rows = [], page = 1, loading = false, writing = false, modalReady = false, sequence = 0, controller;
    const size = 10;
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const campusLabel = row => row.campus_name + (row.status === 'active' ? '' : ' (inactive)');
    const collegeLabel = row => row.college_name + (row.college_code ? ' (' + row.college_code + ')' : '') + (row.status === 'active' ? '' : ' (inactive)');
    const scopeKey = () => campus.value + ':' + college.value;

    function notice(message, success = false) {
        el('programNotice').textContent = message;
        el('programNotice').className = message ? 'alert alert-' + (success ? 'success' : 'warning') : 'alert d-none';
    }
    function formError(message = '') {
        el('programFormError').textContent = message;
        el('programFormError').classList.toggle('d-none', !message);
    }
    function fillColleges(select, campusId, selected = '', editingCollege = '') {
        const filtering = select === college;
        select.replaceChildren(new Option(filtering ? (campusId ? 'All colleges in this campus' : 'Select a campus first') : 'Select a college', ''));
        for (const row of config.colleges) {
            if (String(row.campus_id) !== campusId || (!filtering && row.status !== 'active' && String(row.college_id) !== editingCollege)) continue;
            select.add(new Option(collegeLabel(row), String(row.college_id)));
        }
        select.value = [...select.options].some(option => option.value === selected) ? selected : '';
        select.disabled = !campusId || writing;
    }
    function controls() {
        const activeCampus = campuses.get(campus.value)?.status === 'active';
        const hasCollege = config.colleges.some(row => String(row.campus_id) === campus.value && row.status === 'active');
        campus.disabled = writing;
        college.disabled = !campus.value || writing;
        el('addProgramButton').disabled = loading || writing || !activeCampus || !hasCollege;
        el('programSearch').disabled = !campus.value || loading || writing;
        form.querySelectorAll('input,select,button').forEach(input => { input.disabled = writing; });
        el('saveProgramButton').textContent = writing ? 'Saving…' : (el('editProgramId').value ? 'Save Changes' : 'Save Program');
        document.querySelectorAll('.di-program-actions button').forEach(button => { button.disabled = writing; });
        const query = el('programSearch').value.trim().toLocaleLowerCase();
        const total = rows.filter(row => [row.program_code,row.program_name,row.major].join(' ').toLocaleLowerCase().includes(query)).length;
        el('programPrevious').disabled = loading || writing || page <= 1;
        el('programNext').disabled = loading || writing || page >= Math.max(1, Math.ceil(total / size));
    }
    async function request(data, signal) {
        const response = await fetch(config.endpoint, {
            method:'POST', credentials:'same-origin', cache:'no-store', signal,
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:new URLSearchParams({...data, csrf_token:config.csrfToken}),
        });
        const body = await response.text();
        if (!response.ok || body.startsWith('ERROR|')) throw new Error(body.startsWith('ERROR|') ? body.slice(6) : 'The request could not be completed. Please try again.');
        return body;
    }
    function metrics(summary) {
        for (const key of ['programs','colleges','prospectuses','subjects']) el('count-' + key).textContent = summary ? Number(summary[key]).toLocaleString() : '—';
        el('programStatusCounts').textContent = summary ? summary.active + ' active · ' + summary.inactive + ' inactive' : (campus.value ? 'Loading programs…' : 'Select a campus');
        el('collegeCountNote').textContent = college.value ? 'Selected college' : (campus.value ? 'All colleges in this campus' : 'Select a campus');
    }
    function render() {
        const query = el('programSearch').value.trim().toLocaleLowerCase();
        const filtered = rows.filter(row => [row.program_code,row.program_name,row.major].join(' ').toLocaleLowerCase().includes(query));
        const pages = Math.max(1, Math.ceil(filtered.length / size));
        page = Math.max(1, Math.min(page, pages));
        const start = (page - 1) * size;
        el('programRows').innerHTML = filtered.slice(start, start + size).map((row, index) => {
            const active = row.status === 'active', name = escapeHtml(row.program_code);
            return `<tr><td>${start + index + 1}</td><td class="di-program-name"><span class="di-program-code">${name}</span><span class="di-program-description">${escapeHtml(row.program_name)}</span></td><td class="di-program-major">${escapeHtml(row.major) || '<span class="text-muted">—</span>'}</td><td><span class="badge bg-label-primary">${row.prospectus_count}</span></td><td><span class="badge bg-label-${active ? 'success' : 'secondary'}">${active ? 'Active' : 'Inactive'}</span></td><td><div class="di-program-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-edit="${row.program_id}" aria-label="Edit ${name}"><i class="bx bx-edit-alt me-1" aria-hidden="true"></i>Edit</button>${active ? `<button type="button" class="btn btn-sm btn-outline-danger" data-deactivate="${row.program_id}" aria-label="Deactivate ${name}"><i class="bx bx-power-off me-1" aria-hidden="true"></i>Deactivate</button>` : ''}</div></td></tr>`;
        }).join('') || `<tr><td colspan="6" class="text-center text-muted py-5">${query ? 'No programs match your search.' : 'No programs are recorded for this selection.'}</td></tr>`;
        el('programResultCount').textContent = filtered.length ? `${start + 1}–${Math.min(start + size, filtered.length)} of ${filtered.length} programs` + (query ? ` (${rows.length} in this selection)` : '') : '0 programs';
        el('programPage').textContent = `Page ${page} of ${pages}`;
        el('programPrevious').disabled = writing || page <= 1;
        el('programNext').disabled = writing || page >= pages;
        controls();
    }
    async function load() {
        const version = ++sequence;
        controller?.abort(); controller = new AbortController();
        rows = []; page = 1; metrics(null); loading = !!campus.value;
        el('programPrevious').disabled = el('programNext').disabled = true;
        el('programPage').textContent = 'Page 1 of 1';
        el('programResultCount').textContent = campus.value ? 'Loading programs…' : 'No campus selected';
        el('programRows').innerHTML = `<tr><td colspan="6" class="text-center text-muted py-5">${campus.value ? 'Loading programs…' : 'Select a campus to view its programs.'}</td></tr>`;
        el('programScopeLabel').textContent = campus.value ? campusLabel(campuses.get(campus.value)) + ' / ' + (college.value ? collegeLabel(colleges.get(college.value)) : 'All colleges') : 'Select a campus to get started.';
        el('programList').setAttribute('aria-busy', String(loading)); controls();
        const url = new URL(location.href);
        for (const [key,value] of [['campus_id',campus.value],['college_id',college.value]]) value ? url.searchParams.set(key,value) : url.searchParams.delete(key);
        history.replaceState(null, '', url);
        if (!campus.value) return;
        try {
            const result = JSON.parse(await request({load_programs:'1',campus_id:campus.value,college_id:college.value}, controller.signal));
            if (version !== sequence) return;
            rows = result.rows;
            metrics(result.summary); render();
        } catch (error) {
            if (version !== sequence || error.name === 'AbortError') return;
            notice(error.message);
            el('programStatusCounts').textContent = 'Programs unavailable';
            el('programRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Programs could not be loaded. Select the campus or college again to retry.</td></tr>';
            el('programResultCount').textContent = 'Programs unavailable';
        } finally {
            if (version === sequence) { loading = false; el('programList').setAttribute('aria-busy','false'); controls(); }
        }
    }
    function openForm(row = null) {
        form.reset(); formError();
        el('editProgramId').value = row ? row.program_id : '';
        el('editProgramCampus').value = campus.value;
        el('programCampusLabel').value = campusLabel(campuses.get(campus.value));
        fillColleges(el('editProgramCollege'), campus.value, row ? String(row.college_id) : college.value, row ? String(row.college_id) : '');
        el('editProgramCode').value = row?.program_code || '';
        el('editProgramName').value = row?.program_name || '';
        el('editProgramMajor').value = row?.major || '';
        el('editProgramStatus').value = row?.status || 'active';
        el('programStatusField').hidden = !row;
        el('programModalTitle').textContent = row ? 'Edit Program' : 'Add Program';
        controls(); modal.show();
    }
    campus.addEventListener('change', () => { notice(''); el('programSearch').value = ''; fillColleges(college,campus.value); load(); });
    college.addEventListener('change', () => { notice(''); el('programSearch').value = ''; load(); });
    el('programSearch').addEventListener('input', () => { page = 1; render(); });
    el('programPrevious').addEventListener('click', () => { page--; render(); });
    el('programNext').addEventListener('click', () => { page++; render(); });
    el('addProgramButton').addEventListener('click', () => openForm());
    el('programModal').addEventListener('show.bs.modal', () => { modalReady = false; });
    el('programModal').addEventListener('shown.bs.modal', () => { modalReady = true; el('editProgramCode').focus(); });
    el('programRows').addEventListener('click', async event => {
        const button = event.target.closest('button[data-edit],button[data-deactivate]');
        if (!button || writing || loading) return;
        const row = rows.find(item => item.program_id === Number(button.dataset.edit || button.dataset.deactivate));
        if (!row) return;
        if (button.dataset.edit) { openForm(row); return; }
        writing = true; controls();
        try {
            const result = await Swal.fire({icon:'warning',title:'Deactivate program?',text:`${row.program_code} will be marked inactive. Its prospectuses, schedules, and workload records will be preserved.`,showCancelButton:true,confirmButtonText:'Set Inactive',confirmButtonColor:'#d33',focusCancel:true});
            if (!result.isConfirmed) return;
            const body = await request({delete_program:'1',program_id:row.program_id,campus_id:campus.value,college_id:row.college_id});
            if (body.trim() !== 'updated') throw new Error('The program could not be deactivated. Please try again.');
            notice('Program set to inactive. Its records have been preserved.', true);
            await load();
        } catch (error) { notice(error.message); }
        finally { writing = false; controls(); }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (writing || !form.reportValidity()) return;
        const data = Object.fromEntries(new FormData(form));
        data[data.program_id ? 'update_program' : 'save_program'] = '1';
        writing = true; formError(); controls();
        try {
            const body = await request(data);
            if (!['success','updated'].includes(body.trim())) throw new Error('The program could not be saved. Please try again.');
            if (modalReady) modal.hide();
            else el('programModal').addEventListener('shown.bs.modal', () => modal.hide(), {once:true});
            notice(data.program_id ? 'Program updated successfully.' : 'Program added successfully.', true);
            // Keep the saved record visible if its college was changed in the editor.
            if (college.value) { college.value = data.college_id; }
            await load();
        } catch (error) { formError(error.message); }
        finally { writing = false; controls(); }
    });
    for (const row of config.campuses) campus.add(new Option(campusLabel(row), String(row.campus_id)));
    campus.value = campuses.has(String(config.campusId)) ? String(config.campusId) : '';
    fillColleges(college, campus.value, String(config.collegeId));
    load();
})();
