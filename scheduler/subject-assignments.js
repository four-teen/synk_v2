(() => {
    'use strict';
    const root = document.getElementById('subjectAssignments');
    if (!root) return;
    const el = id => document.getElementById(id);
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[char]));
    const endpoint = '../backend/query_subject_assignments.php';
    const state = {ready:false, options:null, page:1, pages:1, listVersion:0, detailVersion:0, listAbort:null, detailAbort:null, timer:null};
    const modal = new bootstrap.Modal(el('saDetailModal'));
    const notice = message => { el('saNotice').textContent = message || ''; el('saNotice').classList.toggle('d-none', !message); };
    const filters = () => ({term:el('saTerm').value, program_id:el('saProgram').value, assignment:el('saAssignment').value, search:el('saSearch').value.trim(), page:state.page});
    const request = async (params, signal) => {
        const response = await fetch(endpoint + '?' + new URLSearchParams({college_id:root.dataset.collegeId, ...params}), {credentials:'same-origin', cache:'no-store', signal});
        let data;
        try { data = await response.json(); } catch (_) { throw new Error('Unable to load subject assignments. Please try again.'); }
        if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to load subject assignments.');
        if (String(data.college_id) !== root.dataset.collegeId) throw new Error('Your college workspace changed. Refresh this page.');
        return data;
    };
    const setControls = enabled => ['saTerm','saProgram','saAssignment','saSearch','saReset'].forEach(id => { el(id).disabled = !enabled; });
    const invalidateDetail = () => {
        state.detailVersion++;
        state.detailAbort?.abort();
        modal.hide();
    };
    function renderList(data) {
        state.page = data.page; state.pages = data.pages;
        el('saTermLabel').textContent = data.term.label;
        for (const [id, key] of [['saTotal','subjects'],['saAssigned','assigned'],['saUnassigned','unassigned'],['saFaculty','faculty']]) el(id).textContent = data.summary[key].toLocaleString();
        el('saRows').innerHTML = data.rows.length ? data.rows.map(row => `<tr>
            <td><span class="sa-code">${escape(row.subject_code)}</span><small class="text-muted">${row.section_count} section${row.section_count === 1 ? '' : 's'}</small></td>
            <td>${escape(row.description)}</td><td>${escape(row.program)}</td>
            <td>${row.faculty_count ? `<span class="badge bg-label-primary sa-faculty-count">${row.faculty_count} faculty</span><small>${row.faculty_names.map(escape).join('<br>')}</small>` : '<span class="badge bg-label-secondary">Unassigned</span>'}
                ${row.need_count ? `<small><span class="badge bg-label-warning">${row.need_count} Faculty Need</span></small>` : ''}
                ${row.faculty_count && row.unassigned_meetings ? '<small class="text-muted">Some meetings have no faculty</small>' : ''}</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary sa-view" data-subject="${row.subject_id}" data-program="${row.program_id}" aria-label="View faculty for ${escape(row.subject_code)} in ${escape(row.program)}"><i class="bx bx-group me-1" aria-hidden="true"></i>View Faculty</button></td>
        </tr>`).join('') : '<tr><td colspan="5" class="text-center py-5">No subjects match these filters.</td></tr>';
        el('saResultCount').textContent = data.total ? `Showing ${(data.page - 1) * data.per_page + 1}–${Math.min(data.page * data.per_page, data.total)} of ${data.total} subject / program entries` : '0 subject / program entries';
        el('saPage').textContent = `Page ${data.page} of ${data.pages}`;
        el('saPrevious').disabled = data.page <= 1; el('saNext').disabled = data.page >= data.pages;
    }
    async function loadList() {
        if (!state.ready) return;
        clearTimeout(state.timer);
        invalidateDetail();
        const version = ++state.listVersion;
        state.listAbort?.abort(); state.listAbort = new AbortController();
        notice(''); el('saTableWrap').setAttribute('aria-busy','true');
        el('saRows').innerHTML = '<tr><td colspan="5" class="text-center py-5">Loading subject assignments…</td></tr>';
        ['saPrevious','saNext'].forEach(id => { el(id).disabled = true; });
        try {
            const data = await request({action:'list', ...filters()}, state.listAbort.signal);
            if (version === state.listVersion) renderList(data);
        } catch (error) {
            if (error.name !== 'AbortError' && version === state.listVersion) {
                notice(error.message);
                el('saRows').innerHTML = '<tr><td colspan="5" class="text-center py-5">Subject assignments could not be loaded. Use Refresh to try again.</td></tr>';
                ['saTotal','saAssigned','saUnassigned','saFaculty'].forEach(id => { el(id).textContent = '—'; });
                el('saResultCount').textContent = ''; el('saPage').textContent = '';
            }
        } finally { if (version === state.listVersion) el('saTableWrap').setAttribute('aria-busy','false'); }
    }
    const statusBadge = meeting => {
        const labels = {tba:['warning','TBA'], room_tba:['warning','Room TBA'], scheduled:['success','Scheduled'], unscheduled:['secondary','Not scheduled']};
        const [color, label] = labels[meeting.status] || labels.unscheduled;
        return `<span class="badge bg-label-${color} sa-status">${label}</span>${meeting.merged ? '<small class="d-block text-muted mt-1">Merged meeting</small>' : ''}`;
    };
    function renderDetail(detail) {
        el('saDetailTitle').textContent = `${detail.subject_code} — ${detail.description}`;
        el('saDetailSubtitle').textContent = `${detail.program} · ${detail.term} · ${detail.section_count} section${detail.section_count === 1 ? '' : 's'}`;
        const cards = detail.faculty.map(person => {
            const meetings = detail.meetings.filter(meeting => meeting.faculty.some(item => item.faculty_id === person.faculty_id));
            const sections = new Set(meetings.map(meeting => meeting.offering_id));
            const types = [...new Set(meetings.map(meeting => meeting.type))].join(' / ');
            return `<div class="col-sm-6 col-lg-4"><div class="sa-faculty-card"><h6>${escape(person.name)}</h6><p class="small text-muted mb-2">${sections.size} section${sections.size === 1 ? '' : 's'} · ${escape(types)}</p>${person.workload_url ? `<a class="btn btn-sm btn-outline-primary" href="${escape(person.workload_url)}">Open Workload<i class="bx bx-link-external ms-1" aria-hidden="true"></i></a>` : ''}</div></div>`;
        }).join('');
        el('saDetailContent').innerHTML = `${cards ? `<h6>Assigned Faculty (${detail.faculty.length})</h6><div class="row g-3 mb-4">${cards}</div>` : '<div class="alert alert-secondary">No faculty has been assigned to this subject in the selected program and term.</div>'}
            ${detail.needs.length ? `<div class="alert alert-warning"><strong>Faculty Need</strong><div class="mt-1">${detail.needs.map(need => escape(need.label)).join(', ')}</div><small>These are staffing placeholders and do not count as assigned faculty.</small></div>` : ''}
            ${detail.unassigned_meetings && detail.faculty.length ? `<p class="small text-muted">${detail.unassigned_meetings} meeting${detail.unassigned_meetings === 1 ? ' has' : 's have'} no faculty assigned.</p>` : ''}
            <h6>Section Assignments</h6><div class="table-responsive"><table class="table sa-meetings mb-0"><thead><tr><th>Section</th><th>Type</th><th>Faculty / Staffing</th><th>Days</th><th>Time</th><th>Room / Status</th></tr></thead><tbody>${detail.meetings.length ? detail.meetings.map(meeting => `<tr><td>${escape(meeting.section)}</td><td><span class="badge ${meeting.type === 'LAB' ? 'bg-label-info' : 'bg-label-primary'}">${escape(meeting.type)}</span></td><td>${meeting.faculty.map(person => `<span class="sa-person">${escape(person.name)}</span>`).join('')}${meeting.needs.map(need => `<span class="sa-person text-warning">${escape(need.label)} <small>(Faculty Need)</small></span>`).join('')}${!meeting.faculty.length && !meeting.needs.length ? '<span class="text-muted">Unassigned</span>' : ''}</td><td>${escape(meeting.days)}</td><td>${escape(meeting.time)}</td><td>${escape(meeting.room)}${statusBadge(meeting)}</td></tr>`).join('') : '<tr><td colspan="6" class="text-center">No meetings have been scheduled.</td></tr>'}</tbody></table></div>`;
    }
    async function openDetail(subject, program) {
        const version = ++state.detailVersion;
        state.detailAbort?.abort(); state.detailAbort = new AbortController();
        el('saDetailTitle').textContent = 'Subject Faculty'; el('saDetailSubtitle').textContent = '';
        el('saDetailContent').innerHTML = '<p class="text-center py-4" role="status">Loading faculty assignments…</p>';
        modal.show();
        try {
            const data = await request({action:'detail', term:el('saTerm').value, subject_id:subject, program_id:program}, state.detailAbort.signal);
            if (version === state.detailVersion) renderDetail(data.detail);
        } catch (error) {
            if (error.name !== 'AbortError' && version === state.detailVersion) el('saDetailContent').innerHTML = `<div class="alert alert-danger" role="alert">${escape(error.message)}</div>`;
        }
    }
    async function initialize() {
        el('saRefresh').disabled = true; notice('');
        try {
            const data = await request({action:'options'});
            state.options = data.options;
            el('saTerm').innerHTML = data.options.terms.map(term => `<option value="${escape(term.key)}">${escape(term.label)}</option>`).join('');
            el('saProgram').innerHTML = '<option value="0">All programs</option>' + data.options.programs.map(program => `<option value="${program.program_id}">${escape(program.label)}</option>`).join('');
            if (data.options.terms.some(term => term.key === data.options.current_term)) el('saTerm').value = data.options.current_term;
            if (!data.options.terms.length) throw new Error('No academic term is available for this college.');
            state.ready = true; setControls(true); await loadList();
        } catch (error) { notice(error.message); el('saRows').innerHTML = '<tr><td colspan="5" class="text-center py-5">Subject assignments are unavailable. Use Refresh to try again.</td></tr>'; el('saTableWrap').setAttribute('aria-busy','false'); }
        finally { el('saRefresh').disabled = false; }
    }
    ['saTerm','saProgram','saAssignment'].forEach(id => el(id).addEventListener('change', () => {state.page = 1; loadList();}));
    el('saSearch').addEventListener('input', () => {clearTimeout(state.timer); state.page = 1; state.listVersion++; state.listAbort?.abort(); invalidateDetail(); state.timer = setTimeout(loadList, 300);});
    el('saFilters').addEventListener('submit', event => {event.preventDefault(); state.page = 1; loadList();});
    el('saRefresh').addEventListener('click', () => state.ready ? loadList() : initialize());
    el('saReset').addEventListener('click', () => {el('saTerm').value = state.options.terms.some(term => term.key === state.options.current_term) ? state.options.current_term : state.options.terms[0].key; el('saProgram').value = '0'; el('saAssignment').value = 'all'; el('saSearch').value = ''; state.page = 1; loadList();});
    el('saPrevious').addEventListener('click', () => {if (state.page > 1) {state.page--; loadList();}});
    el('saNext').addEventListener('click', () => {if (state.page < state.pages) {state.page++; loadList();}});
    el('saRows').addEventListener('click', event => {const button = event.target.closest('.sa-view'); if (button && root.contains(button)) openDetail(button.dataset.subject, button.dataset.program);});
    el('saDetailModal').addEventListener('hidden.bs.modal', () => {state.detailVersion++; state.detailAbort?.abort();});
    initialize();
})();
