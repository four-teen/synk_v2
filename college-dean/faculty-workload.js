(() => {
  'use strict';
  const config = JSON.parse(document.getElementById('deanWorkloadConfig').textContent);
  const $ = id => document.getElementById(id);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const state = { facultyId: 0, faculties: [], classes: [], metrics: [], rosterStatus: 'all', roster: [], selected: null, token: '', importReady: false, busy: false, loading: false, importView: null, generation: 0, rosterGeneration: 0 };
  const facultyPlaceholder = 'faculty-placeholder.svg';
  state.canImport = true;
  const rosterModal = new bootstrap.Modal($('deanRosterModal'));
  const importModal = new bootstrap.Modal($('deanImportModal'));
  const label = row => `${row.subject_code} · ${row.section_display}`;
  const empty = (title, copy) => `<div class="dean-empty-state"><i class="bx bx-book-content" aria-hidden="true"></i><h5>${escape(title)}</h5><p class="mb-0">${escape(copy)}</p></div>`;
  const time = value => {
    if (!value) return '—';
    const [hour, minute] = value.split(':').map(Number);
    return `${hour % 12 || 12}:${String(minute).padStart(2,'0')} ${hour >= 12 ? 'PM' : 'AM'}`;
  };
  const number = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
  function renderFacultyPhoto(faculty = null) {
    const image = $('deanFacultyPhoto');
    image.src = faculty && faculty.profile_image_url || facultyPlaceholder;
    image.alt = faculty ? `Profile image for ${faculty.faculty_name}` : 'Faculty profile placeholder';
  }
  $('deanFacultyPhoto').addEventListener('error', () => {
    const image = $('deanFacultyPhoto');
    if (image.getAttribute('src') !== facultyPlaceholder) image.src = facultyPlaceholder;
  });
  function notice(id, message = '', kind = 'danger') {
    const element = $(id);
    element.textContent = message;
    element.className = `alert mt-3 mb-0 ${message ? `alert-${kind}` : 'd-none'}`;
  }
  async function request(action, params = {}, file = null, endpoint = '../backend/query_college_dean_rosters.php') {
    const body = new FormData();
    Object.entries({...config, action, ...params}).forEach(([key,value]) => body.append(key,value));
    if (file) body.append('csv_file', file);
    let response;
    try { response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' }); }
    catch (_) { throw new Error('Unable to connect. Please try again.'); }
    let data;
    try { data = await response.json(); }
    catch (_) { throw new Error(response.status === 413 ? 'The server rejected the upload because it exceeds its file-size limit. Choose a CSV up to 2 MB.' : `The server returned an unreadable response${response.status ? ` (HTTP ${response.status})` : ''}. Reload the page and try again.`); }
    if (!response.ok || data.status !== 'ok') {
      const error = new Error(data.message || 'Unable to complete the request.');
      error.issues = Array.isArray(data.issues) ? data.issues : [];
      throw error;
    }
    return data;
  }
  function captureWorkloadView() {
    const cards = Array.from($('deanClasses').querySelectorAll('article[data-offering-id]'));
    const anchor = cards.find(card => {
      const bounds = card.getBoundingClientRect();
      return bounds.bottom > 0 && bounds.top < window.innerHeight;
    });
    return {
      facultyId: state.facultyId, x: window.scrollX, y: window.scrollY,
      anchorId: anchor ? Number(anchor.dataset.offeringId) : null,
      anchorTop: anchor ? anchor.getBoundingClientRect().top : 0,
      expanded: new Set(cards.filter(card => card.querySelector('details').open).map(card => Number(card.dataset.offeringId)))
    };
  }
  async function restoreWorkloadView(view, generation) {
    if (!view) return;
    await new Promise(resolve => window.requestAnimationFrame(resolve));
    if (generation !== state.generation || view.facultyId !== state.facultyId) return;
    const anchor = view.anchorId ? $('deanClasses').querySelector(`article[data-offering-id="${view.anchorId}"]`) : null;
    const top = anchor ? window.scrollY + anchor.getBoundingClientRect().top - view.anchorTop : view.y;
    window.scrollTo({left: view.x, top: Math.max(0, top), behavior: 'instant'});
  }
  function setWorkloadBusy(busy) {
    state.loading = busy;
    $('deanRosterFilter').disabled = busy || !state.facultyId;
    $('deanClasses').setAttribute('aria-busy', String(busy));
    $('deanClasses').querySelectorAll('[data-roster], [data-import]').forEach(button => {
      button.disabled = busy || !state.importReady || (button.hasAttribute('data-import') && !state.canImport);
      if (button.hasAttribute('data-import')) button.hidden = !state.canImport;
    });
  }
  function renderClasses(metrics = [], expanded = new Set()) {
    const bySchedule = new Map(metrics.map(row => [Number(row.schedule_id), row]));
    $('deanClassCount').textContent = state.classes.length;
    $('deanRosterCount').textContent = state.classes.filter(row => row.roster_count > 0).length;
    $('deanMissingCount').textContent = state.classes.filter(row => row.roster_count === 0).length;
    const visible = state.classes.filter(row => state.rosterStatus === 'all' || (state.rosterStatus === 'missing' ? row.roster_count === 0 : row.roster_count > 0));
    $('deanClassVisibility').textContent = `Showing ${visible.length} of ${state.classes.length} assigned classes.`;
    if (!state.classes.length) {
      $('deanClasses').innerHTML = empty('No assigned classes', 'This faculty member has no scheduled class assignments in your college for the selected term.');
      return;
    }
    if (!visible.length) {
      $('deanClasses').innerHTML = empty('No classes match this filter', 'Choose another student list filter to see the faculty’s assigned classes.');
      return;
    }
    $('deanClasses').innerHTML = visible.map(row => {
      const typeBadge = schedule => `<span class="badge bg-label-${schedule.type === 'LAB' ? 'info' : 'primary'}">${escape(schedule.type)}</span>`;
      const scheduleTime = schedule => schedule.schedule_status === 'tba' ? 'TBA' : `${escape(time(schedule.time_start))} – ${escape(time(schedule.time_end))}`;
      const preview = row.schedules.map(schedule =>
        `<span class="dean-schedule-line"><span class="dean-schedule-field"><span class="dean-schedule-label">Type:</span>${typeBadge(schedule)}</span><span class="dean-schedule-field"><span class="dean-schedule-label">Days:</span><span>${escape(schedule.days || '—')}</span></span><span class="dean-schedule-field"><span class="dean-schedule-label">Time:</span><span>${scheduleTime(schedule)}</span></span><span class="dean-schedule-field"><span class="dean-schedule-label">Room:</span><span>${escape(schedule.room || '—')}</span></span></span>`
      ).join('');
      const details = row.schedules.map(schedule => {
        const metric = bySchedule.get(Number(schedule.schedule_id));
        return `<tr><td>${typeBadge(schedule)}</td><td>${escape(schedule.days || '—')}</td><td class="text-nowrap">${scheduleTime(schedule)}</td><td>${escape(schedule.room || '—')}</td><td>${metric ? number(metric.units) : '—'}</td><td>${metric ? number(metric.lec) : '—'}</td><td>${metric ? number(metric.lab_hours) : '—'}</td><td>${metric ? number(metric.faculty_load) : '—'}</td></tr>`;
      }).join('');
      return `<article class="card dean-page-card mb-3" data-offering-id="${row.offering_id}"><div class="card-body p-3"><div class="dean-class-header mb-3"><div><h5 class="dean-class-code mb-1">${escape(row.subject_code)} <span class="badge bg-label-secondary ms-1">${escape(row.section_display)}</span></h5><div class="dean-class-title">${escape(row.descriptive_title)}</div><p class="small ${row.roster_count > 0 ? 'dean-student-count-populated' : 'text-muted'} mt-2 mb-0">${number(row.roster_count)} students in class list</p></div><div class="dean-class-actions"><button type="button" class="btn btn-sm btn-outline-primary me-sm-1" data-roster="${row.offering_id}" ${state.importReady ? '' : 'disabled'}><i class="bx bx-group me-1" aria-hidden="true"></i>View Students</button><button type="button" class="btn btn-sm btn-primary" data-import="${row.offering_id}" ${state.importReady ? '' : 'disabled'}><i class="bx bx-upload me-1" aria-hidden="true"></i>Import CSV</button></div></div><details class="dean-workload-details"${expanded.has(row.offering_id) ? ' open' : ''}><summary aria-label="Workload details for ${escape(label(row))}" aria-describedby="dean-schedule-preview-${row.offering_id}"><span class="dean-schedule-preview" id="dean-schedule-preview-${row.offering_id}">${preview}</span><span class="dean-workload-toggle"><i class="bx bx-chevron-down" aria-hidden="true"></i>Workload details</span></summary><div class="table-responsive"><table class="table dean-workload-table" aria-label="Workload details for ${escape(label(row))}"><thead><tr><th scope="col">Type</th><th scope="col">Days</th><th scope="col">Time</th><th scope="col">Room</th><th scope="col">Units</th><th scope="col">Lec</th><th scope="col">Lab Hrs</th><th scope="col">Load</th></tr></thead><tbody>${details}</tbody></table></div></details></div></article>`;
    }).join('');
  }
  async function loadFaculty(clearNotice = true, view = null) {
    const generation = ++state.generation;
    state.facultyId = Number($('deanFaculty').value || 0);
    if (view && view.facultyId !== state.facultyId) view = null;
    renderFacultyPhoto(state.faculties.find(row => row.faculty_id === state.facultyId));
    $('deanSummary').hidden = !state.facultyId;
    $('deanClassFilters').hidden = !state.facultyId;
    if (clearNotice) notice('deanNotice');
    if (!state.facultyId) {
      setWorkloadBusy(false);
      $('deanRefresh').disabled = false;
      state.classes = [];
      state.metrics = [];
      $('deanClasses').innerHTML = empty('Select a faculty member', 'Their assigned classes for the selected academic term will appear here.');
      return;
    }
    // Keep the existing page height during imports and refreshes so the browser
    // cannot clamp a scroll position near the bottom to a short loading message.
    if (!view || !state.classes.length) $('deanClasses').innerHTML = empty('Loading workload…', 'Fetching the faculty’s assigned classes and student counts.');
    setWorkloadBusy(true);
    $('deanRefresh').disabled = true;
    $('deanPreparationCount').textContent = '—'; $('deanLoadCount').textContent = '—';
    $('deanClassCount').textContent = '—'; $('deanRosterCount').textContent = '—';
    $('deanMissingCount').textContent = '—';
    const params = {faculty_id: state.facultyId};
    // Load formulas and merged schedule metrics come from the same endpoint as the dashboard.
    const [classes, workload] = await Promise.allSettled([
      request('classes', params), request('workload', params, null, '../backend/query_college_dean_faculty_workload.php')
    ]);
    if (generation !== state.generation) return;
    setWorkloadBusy(false);
    $('deanRefresh').disabled = false;
    if (classes.status !== 'fulfilled') {
      if (!view || !state.classes.length) {
        state.classes = [];
        $('deanClasses').innerHTML = empty('Workload could not be loaded', 'Use Refresh to try again.');
      }
      notice('deanNotice', classes.reason.message);
      await restoreWorkloadView(view, generation);
      return;
    }
    state.classes = classes.value.classes;
    state.metrics = workload.status === 'fulfilled' ? workload.value.rows : [];
    if (classes.value.faculty) {
      const faculty = classes.value.faculty;
      state.faculties = state.faculties.map(row => row.faculty_id === faculty.faculty_id ? faculty : row);
      renderFacultyPhoto(faculty);
    }
    if (workload.status === 'fulfilled') {
      $('deanPreparationCount').textContent = number(workload.value.meta.total_preparations);
      $('deanLoadCount').textContent = number(workload.value.meta.total_load);
      renderClasses(workload.value.rows, view ? view.expanded : undefined);
    } else {
      renderClasses([], view ? view.expanded : undefined);
      notice('deanNotice', 'Classes loaded, but workload totals are temporarily unavailable. Use Refresh to try again.', 'warning');
    }
    await restoreWorkloadView(view, generation);
  }
  function renderRoster() {
    const query = $('deanStudentSearch').value.trim().toLocaleLowerCase();
    const rows = state.roster.filter(row => `${row.student_number} ${row.student_name}`.toLocaleLowerCase().includes(query));
    $('deanStudentTotal').textContent = `${state.roster.length} students`;
    $('deanRosterContent').innerHTML = !rows.length ? empty(state.roster.length ? 'No matching students' : 'No students imported yet', state.roster.length ? 'Try another name or student ID.' : 'Use Import CSV on this class to preview and add its student list.') :
      `<div class="table-responsive"><table class="table dean-workload-table"><thead><tr><th>#</th><th>Student ID</th><th>Student Name</th></tr></thead><tbody>${rows.map((row,i) => `<tr><td>${i+1}</td><td>${escape(row.student_number)}</td><td>${escape(row.student_name)}</td></tr>`).join('')}</tbody></table></div>`;
  }
  async function openRoster(row) {
    const generation = ++state.rosterGeneration;
    state.roster = [];
    $('deanRosterSubtitle').textContent = `${label(row)} · ${row.term_text}`;
    $('deanStudentSearch').value = '';
    $('deanStudentSearch').disabled = true;
    $('deanStudentTotal').textContent = 'Loading…';
    $('deanRosterContent').innerHTML = empty('Loading students…', 'Fetching the student list for this class.');
    rosterModal.show();
    try {
      const data = await request('roster', {faculty_id:state.facultyId, offering_id:row.offering_id});
      if (generation !== state.rosterGeneration) return;
      state.roster = data.students; $('deanStudentSearch').disabled = false; renderRoster();
    } catch (error) {
      if (generation !== state.rosterGeneration) return;
      $('deanStudentTotal').textContent = 'Unavailable';
      $('deanRosterContent').innerHTML = empty('Unable to load students', error.message);
    }
  }
  function setImportBusy(busy) {
    state.busy = busy;
    ['deanCsvFile','deanPreviewButton','deanImportCancel','deanImportClose'].forEach(id => { $(id).disabled = busy; });
    $('deanConfirmButton').disabled = busy || !state.token;
    $('deanPreviewButton').textContent = busy ? 'Please wait…' : 'Preview Import';
  }
  function cancelPreview() {
    const token = state.token;
    state.token = '';
    $('deanConfirmButton').hidden = true; $('deanConfirmButton').disabled = true;
    if (token) request('cancel', {preview_token:token}).catch(() => {});
  }
  function clearPreview() {
    cancelPreview();
    $('deanImportPreview').hidden = true; $('deanImportPreview').innerHTML = '';
    notice('deanImportNotice');
  }
  function openImport(row) {
    state.importView = captureWorkloadView();
    clearPreview(); state.selected = row;
    $('deanImportSubtitle').textContent = `${label(row)} · ${row.term_text}`;
    $('deanImportForm').reset(); setImportBusy(false);
    importModal.show();
  }
  function renderImportIssues(error) {
    notice('deanImportNotice', error.message);
    if (!error.issues || !error.issues.length) return;
    const location = issue => !issue.row ? 'File' : (issue.column && /^[A-Z]$/.test(issue.column) ? issue.column + issue.row : `Row ${issue.row}${issue.column ? ` · ${issue.column}` : ''}`);
    $('deanImportPreview').innerHTML = `<section class="dean-import-issues" aria-labelledby="dean-import-issues-title"><h6 id="dean-import-issues-title">${error.issues.length} ${error.issues.length === 1 ? 'issue' : 'issues'} to correct</h6><p class="small text-muted">No students were imported. Correct the items below, then click Preview Import again.${error.issues.length >= 50 ? ' The first 50 issues are shown.' : ''}</p><div class="dean-preview-table"><table class="table dean-workload-table"><thead><tr><th>CSV cell</th><th>Field and values</th><th>Problem and correction</th></tr></thead><tbody>${error.issues.map(issue=>`<tr><td>${escape(location(issue))}</td><td><strong>${escape(issue.field)}</strong><div class="small mt-1"><b>Found:</b> ${escape(issue.found || '(empty)')}</div><div class="small mt-1"><b>Expected:</b> ${escape(issue.expected)}</div></td><td><div>${escape(issue.reason)}</div><div class="small mt-2"><b>How to fix:</b> ${escape(issue.fix)}</div></td></tr>`).join('')}</tbody></table></div></section>`;
    $('deanImportPreview').hidden = false;
  }
  function renderPreview(data) {
    const {summary, rows, notes, can_confirm} = data.preview;
    state.token = data.preview_token;
    const actionLabel = {add:'Add to class', existing:'Already in class', conflict:'Review required'};
    const actionColor = {add:'success', existing:'secondary', conflict:'danger'};
    const emailCell = row => `<div class="small" style="overflow-wrap:anywhere">${escape(row.email_preview || row.email_address || 'Not generated')}</div><small class="d-block mt-1 ${row.email_status === 'review' ? 'text-warning' : 'text-muted'}">${escape(row.email_status === 'preserved' ? 'Existing email kept' : row.email_status === 'review' ? row.email_reason : row.action === 'existing' ? 'Use Student Management to fill missing emails' : 'Will fill missing email')}</small>`;
    $('deanImportPreview').innerHTML = `<p class="small text-muted">${escape(data.file_name)}</p><div class="dean-import-stats"><span class="badge bg-label-primary">${summary.students} unique students</span><span class="badge bg-label-success">${summary.add} to add</span><span class="badge bg-label-secondary">${summary.existing} already in class</span><span class="badge bg-label-info">${summary.new_profiles} new student profiles</span>${summary.duplicate_rows ? `<span class="badge bg-label-warning">${summary.duplicate_rows} duplicate CSV rows skipped</span>` : ''}${summary.conflicts ? `<span class="badge bg-label-danger">${summary.conflicts} conflicts</span>` : ''}</div><ul class="dean-import-notes">${notes.map(note=>`<li>${escape(note)}</li>`).join('')}</ul><div class="dean-preview-table"><table class="table dean-workload-table"><thead><tr><th>CSV Row</th><th>Student ID</th><th>Student Name</th><th>Email</th><th>Action</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${row.source_row_number}</td><td>${escape(row.student_number)}</td><td>${escape(row.student_name_raw)}</td><td>${emailCell(row)}</td><td><span class="badge bg-label-${actionColor[row.action]}">${actionLabel[row.action]}</span>${row.issue ? `<div class="small text-danger mt-1">${escape(row.issue)}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>`;
    $('deanImportPreview').hidden = false;
    if (data.preview.checks && data.preview.checks.length) {
      const checks = `<details class="dean-import-checks"><summary>Header checks passed — compare CSV with the selected class</summary><div class="table-responsive"><table class="table dean-workload-table"><thead><tr><th>Header</th><th>CSV value</th><th>Selected class</th></tr></thead><tbody>${data.preview.checks.map(check=>`<tr><td>${escape(check.field)} <small class="d-block">${escape(check.cell)}</small></td><td>${escape(check.found)}</td><td>${escape(check.expected)}</td></tr>`).join('')}</tbody></table></div></details>`;
      $('deanImportPreview').innerHTML = checks + $('deanImportPreview').innerHTML;
    }
    $('deanConfirmButton').hidden = !can_confirm;
    if (summary.conflicts) notice('deanImportNotice', 'Resolve the highlighted student ID conflicts, then preview the corrected CSV.', 'warning');
    else if (!summary.add) notice('deanImportNotice', 'All students in this CSV are already in this class. Nothing needs to be imported.', 'info');
  }
  $('deanImportForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (state.busy || !state.selected) return;
    clearPreview();
    const file = $('deanCsvFile').files[0];
    if (!file || !/\.csv$/i.test(file.name) || file.size > 2*1024*1024) {
      notice('deanImportNotice', !file ? 'Choose the class CSV before previewing.' : (!/\.csv$/i.test(file.name) ? `“${file.name}” is not a .csv file. Export it as CSV UTF-8 (comma delimited).` : `“${file.name}” is ${(file.size / 1024 / 1024).toFixed(2)} MB; the maximum is 2 MB.`)); return;
    }
    setImportBusy(true);
    try {
      renderPreview(await request('preview', {faculty_id:state.facultyId, offering_id:state.selected.offering_id}, file));
    } catch (error) { renderImportIssues(error); }
    finally { setImportBusy(false); }
  });
  $('deanConfirmButton').addEventListener('click', async () => {
    if (state.busy || !state.token || !state.selected) return;
    setImportBusy(true); notice('deanImportNotice');
    try {
      const data = await request('confirm', {faculty_id:state.facultyId, offering_id:state.selected.offering_id, preview_token:state.token});
      const view = state.importView;
      state.token = ''; setImportBusy(false);
      // Restore after Bootstrap has unlocked the page and finished its transition.
      await new Promise(resolve => {
        $('deanImportModal').addEventListener('hidden.bs.modal', resolve, {once: true});
        importModal.hide();
      });
      notice('deanNotice', data.message, 'success'); await loadFaculty(false, view);
    } catch (error) { notice('deanImportNotice', error.message); }
    finally { setImportBusy(false); }
  });
  $('deanImportModal').addEventListener('hide.bs.modal', event => {
    if (state.busy) event.preventDefault();
    else { cancelPreview(); state.selected = null; state.importView = null; }
  });
  $('deanCsvFile').addEventListener('change', clearPreview);
  $('deanClasses').addEventListener('click', event => {
    if (state.loading) return;
    const button = event.target.closest('[data-roster], [data-import]');
    if (!button || button.disabled) return;
    const row = state.classes.find(item => item.offering_id === Number(button.dataset.roster || button.dataset.import));
    if (!row) return;
    if (button.dataset.roster) openRoster(row); else openImport(row);
  });
  $('deanStudentSearch').addEventListener('input', renderRoster);
  $('deanRosterModal').addEventListener('hide.bs.modal', () => { state.rosterGeneration++; });
  $('deanFaculty').addEventListener('change', () => loadFaculty());
  $('deanRefresh').addEventListener('click', () => loadFaculty(true, captureWorkloadView()));
  $('deanRosterFilter').addEventListener('change', () => {
    if (state.loading) return;
    const expanded = captureWorkloadView().expanded;
    state.rosterStatus = $('deanRosterFilter').value;
    renderClasses(state.metrics, expanded);
  });
  async function initialize() {
    try {
      const url = new URLSearchParams(window.location.search);
      state.rosterStatus = ['all','with','missing'].includes(url.get('list_status')) ? url.get('list_status') : 'all';
      $('deanRosterFilter').value = state.rosterStatus;
      const data = await request('bootstrap'); state.importReady = data.import_ready; state.canImport = data.can_import !== false; state.faculties = data.faculties;
      $('deanFaculty').innerHTML = `<option value="">Select faculty</option>${data.faculties.map(row=>`<option value="${row.faculty_id}">${escape(row.faculty_name)}</option>`).join('')}`;
      $('deanFaculty').disabled = false; $('deanRefresh').disabled = false;
      if (!data.faculties.length) notice('deanNotice', 'No faculty assignments are available for your college in the selected term.', 'info');
      else if (!state.canImport) notice('deanNotice', 'This term is for viewing only. Student lists and workload details are available; switch to the current term to import students.', 'info');
      else if (!state.importReady) notice('deanNotice', 'Workloads are available. Student roster storage is not available yet.', 'warning');
      const facultyId = Number(url.get('faculty_id'));
      if (data.faculties.some(row=>row.faculty_id === facultyId)) {
        $('deanFaculty').value = String(facultyId); await loadFaculty(false);
        const offeringId = Number(url.get('offering_id'));
        if (state.classes.some(row=>row.offering_id===offeringId)) {
          await new Promise(resolve=>window.requestAnimationFrame(resolve));
          const card = $('deanClasses').querySelector(`article[data-offering-id="${offeringId}"]`);
          if (card) card.scrollIntoView({block:'start',behavior:'instant'});
        }
      }
    } catch (error) { notice('deanNotice', error.message); $('deanFaculty').innerHTML = '<option value="">Faculty could not be loaded</option>'; }
  }
  initialize();
})();
