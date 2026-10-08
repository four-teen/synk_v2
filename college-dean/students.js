(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('deanStudentsConfig').textContent);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(value || 0).toLocaleString();
  const state = {options:{programs:[],terms:[],sections:[]}, rows:[], page:1, pages:1, generation:0, detailGeneration:0, ready:false};
  const modal = new bootstrap.Modal($('deanStudentDetailsModal'));
  let searchTimer;
  function notice(message = '') {
    $('deanStudentsNotice').textContent = message;
    $('deanStudentsNotice').className = `alert mt-3 mb-0 ${message ? 'alert-danger' : 'd-none'}`;
  }
  function filters() {
    return {search:$('deanStudentsSearch').value.trim(), term:$('deanStudentsTerm').value || 'all',
      program_id:$('deanStudentsProgram').value || '0', section_id:$('deanStudentsSection').value || '0',
      sort:$('deanStudentsSort').value || 'name', page_size:$('deanStudentsPageSize').value || '25', page:state.page};
  }
  async function request(action, params = {}) {
    const body = new FormData();
    Object.entries({...config,action,...params}).forEach(([key,value]) => body.append(key,value));
    let response;
    try { response = await fetch('../backend/query_college_dean_students.php',{method:'POST',body,credentials:'same-origin',cache:'no-store'}); }
    catch (_) { throw new Error('Unable to connect. Please try again.'); }
    let data;
    try { data = await response.json(); }
    catch (_) { throw new Error('Unable to load students. Reload the page and try again.'); }
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to load the student directory.');
    return data;
  }
  function sections(preserve = false) {
    const previous = preserve ? $('deanStudentsSection').value : '0';
    const program = Number($('deanStudentsProgram').value || 0);
    const term = $('deanStudentsTerm').value || 'all';
    const choices = state.options.sections.filter(row => (!program || row.program_id === program) && (term === 'all' || row.term === term));
    const labels = new Map(state.options.terms.map(row=>[row.key,row.label]));
    $('deanStudentsSection').innerHTML = '<option value="0">All sections</option>' + choices.map(row=>
      `<option value="${row.section_id}">${escape(row.section_label)}${term === 'all' ? ` · ${escape(labels.get(row.term) || row.term)}` : ''}</option>`).join('');
    $('deanStudentsSection').value = choices.some(row=>String(row.section_id) === previous) ? previous : '0';
  }
  function loading() {
    state.rows = [];
    $('deanStudentsRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Loading students...</td></tr>';
    $('deanStudentsPrevious').disabled = true; $('deanStudentsNext').disabled = true;
    $('deanStudentsPageInfo').textContent = '';
    ['deanUniqueStudents','deanImportedClasses','deanImportedPrograms'].forEach(id=>{ $(id).textContent = '—'; });
    $('deanStudentDirectory').setAttribute('aria-busy','true');
  }
  function render(data) {
    state.rows = data.rows; state.page = data.pagination.page; state.pages = data.pagination.pages;
    $('deanUniqueStudents').textContent = number(data.summary.students);
    $('deanImportedClasses').textContent = number(data.summary.classes);
    $('deanImportedPrograms').textContent = number(data.summary.programs);
    if (!data.rows.length) {
      const query = filters();
      const filtered = query.search || query.term !== 'all' || query.program_id !== '0' || query.section_id !== '0';
      $('deanStudentsRows').innerHTML = `<tr><td colspan="6"><div class="dean-empty-state my-2"><i class="bx bx-user-pin" aria-hidden="true"></i><h5>${filtered ? 'No matching students' : 'No imported students yet'}</h5><p class="mb-0">${filtered ? 'Try another name or ID, or reset the filters.' : 'Use Faculty Workload to import students into assigned classes.'}</p></div></td></tr>`;
    } else {
      $('deanStudentsRows').innerHTML = data.rows.map(row=>`<tr><td class="dean-student-id">${escape(row.student_number)}</td><td class="dean-student-name">${escape(row.student_name)}</td><td><div class="dean-student-context" title="${escape(row.programs)}">${escape(row.programs || '—')}</div></td><td><div class="dean-student-context" title="${escape(row.sections)}">${escape(row.sections || '—')}</div></td><td><span class="badge bg-label-primary">${number(row.subject_count)}</span></td><td><button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-student="${row.student_id}" aria-label="${escape('View details for ' + row.student_name)}">View Details</button></td></tr>`).join('');
    }
    $('deanStudentsPageInfo').textContent = data.pagination.total ? `${number(data.pagination.from)}–${number(data.pagination.to)} of ${number(data.pagination.total)} students · Page ${state.page} of ${state.pages}` : '0 students';
    $('deanStudentsPrevious').disabled = state.page <= 1;
    $('deanStudentsNext').disabled = state.page >= state.pages;
  }
  async function load(resetPage = true) {
    if (!state.ready) return;
    clearTimeout(searchTimer);
    if (resetPage) state.page = 1;
    const generation = ++state.generation;
    const params = filters(); notice(); loading();
    try {
      const data = await request('list',params);
      if (generation !== state.generation) return;
      render(data);
    } catch (error) {
      if (generation !== state.generation) return;
      notice(error.message);
      $('deanStudentsRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Students could not be loaded. Use Refresh to try again.</td></tr>';
    } finally {
      if (generation === state.generation) $('deanStudentDirectory').setAttribute('aria-busy','false');
    }
  }
  async function initialize(preserve = false) {
    const previous = preserve ? filters() : {program_id:'0',section_id:'0',term:'all'};
    state.ready = false; state.generation++; loading(); notice();
    $('deanStudentsRefresh').disabled = true;
    try {
      const data = await request('bootstrap'); state.options = data.options;
      const currentKey = `${data.current_term.ay_id}:${data.current_term.semester}`;
      $('deanStudentsProgram').innerHTML = '<option value="0">All programs</option>' + data.options.programs.map(row=>`<option value="${row.program_id}">${escape(row.label)}</option>`).join('');
      $('deanStudentsTerm').innerHTML = '<option value="all">All imported terms</option>' + data.options.terms.map(row=>`<option value="${escape(row.key)}">${escape(row.label)}${row.key === currentKey ? ' (Current)' : ''}</option>`).join('');
      $('deanStudentsProgram').value = data.options.programs.some(row=>String(row.program_id) === previous.program_id) ? previous.program_id : '0';
      $('deanStudentsTerm').value = data.options.terms.some(row=>row.key === previous.term) ? previous.term : 'all';
      sections();
      if (preserve && state.options.sections.some(row=>String(row.section_id) === previous.section_id
          && (!Number(previous.program_id) || row.program_id === Number(previous.program_id))
          && (previous.term === 'all' || row.term === previous.term))) $('deanStudentsSection').value = previous.section_id;
      ['deanStudentsProgram','deanStudentsTerm','deanStudentsSection','deanStudentsSearchButton','deanStudentsReset'].forEach(id=>{ $(id).disabled = false; });
      state.ready = true; await load();
    } catch (error) {
      notice(error.message);
      $('deanStudentsRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Students could not be loaded. Use Refresh to try again.</td></tr>';
    } finally { $('deanStudentsRefresh').disabled = false; $('deanStudentDirectory').setAttribute('aria-busy','false'); }
  }
  async function details(row) {
    const generation = ++state.detailGeneration;
    const term = $('deanStudentsTerm').value || 'all';
    $('deanStudentDetailsTitle').textContent = row.student_name;
    $('deanStudentDetailsTerm').textContent = term === 'all' ? 'All imported terms · Your assigned college' : `${state.options.terms.find(item=>item.key === term)?.label || term} · Your assigned college`;
    $('deanStudentDetailsContent').innerHTML = '<p class="text-muted py-4 mb-0">Loading student details...</p>';
    modal.show();
    try {
      const data = await request('detail',{student_id:row.student_id,term});
      if (generation !== state.detailGeneration) return;
      const student = data.student;
      $('deanStudentDetailsTitle').textContent = student.student_name;
      const profile = `<div class="dean-student-profile"><div class="row g-3"><div class="col-sm-6"><span class="small text-muted">Student ID</span><div class="dean-student-id mt-1">${escape(student.student_number)}</div></div><div class="col-sm-6"><span class="small text-muted">Email</span><div class="mt-1">${escape(student.email_address || 'Not provided')}</div></div></div></div>`;
      const subjects = data.subjects.length ? `<div class="table-responsive"><table class="table dean-students-table"><thead><tr><th>Subject</th><th>Program / Section</th><th>Academic Term</th><th>Faculty</th><th>Imported From</th></tr></thead><tbody>${data.subjects.map(subject=>`<tr><td><div class="fw-semibold text-primary">${escape(subject.subject_code)}</div><div class="small mt-1">${escape(subject.descriptive_title)}</div></td><td>${escape(subject.section_label)}<div class="small text-muted mt-1">${escape(subject.program_label)}</div></td><td>${escape(subject.term_label)}</td><td>${escape(subject.faculty_name || 'Not specified')}</td><td class="dean-student-file">${escape(subject.source_file_name || '—')}<div class="small text-muted mt-1">${escape(subject.imported_at ? subject.imported_at.slice(0,10) : '')}</div></td></tr>`).join('')}</tbody></table></div>` : '<div class="dean-empty-state"><h5>No subjects in this term</h5><p class="mb-0">This student has no imported subjects for the selected academic term.</p></div>';
      $('deanStudentDetailsContent').innerHTML = `${profile}<h6 class="mb-3">Imported Subjects <span class="badge bg-label-primary ms-1">${number(data.subjects.length)}</span></h6>${subjects}`;
    } catch (error) {
      if (generation !== state.detailGeneration) return;
      $('deanStudentDetailsContent').innerHTML = `<div class="alert alert-danger mb-0" role="alert">${escape(error.message)}</div>`;
    }
  }
  $('deanStudentsFilters').addEventListener('submit',event=>{ event.preventDefault(); load(); });
  $('deanStudentsSearch').addEventListener('input',()=>{ clearTimeout(searchTimer); searchTimer = setTimeout(()=>load(),300); });
  ['deanStudentsProgram','deanStudentsTerm'].forEach(id=>$(id).addEventListener('change',()=>{ sections(); load(); }));
  ['deanStudentsSection','deanStudentsSort','deanStudentsPageSize'].forEach(id=>$(id).addEventListener('change',()=>load()));
  $('deanStudentsPrevious').addEventListener('click',()=>{ if (state.page > 1) { state.page--; load(false); } });
  $('deanStudentsNext').addEventListener('click',()=>{ if (state.page < state.pages) { state.page++; load(false); } });
  $('deanStudentsRefresh').addEventListener('click',()=>initialize(true));
  $('deanStudentsReset').addEventListener('click',()=>{
    $('deanStudentsSearch').value = ''; $('deanStudentsProgram').value = '0'; $('deanStudentsTerm').value = 'all'; sections(); load();
  });
  $('deanStudentsRows').addEventListener('click',event=>{
    const button = event.target.closest('[data-student]');
    const row = button && state.rows.find(item=>item.student_id === Number(button.dataset.student));
    if (row) details(row);
  });
  $('deanStudentDetailsModal').addEventListener('hide.bs.modal',()=>{ state.detailGeneration++; });
  initialize();
})();
