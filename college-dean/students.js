(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('deanStudentsConfig').textContent);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(value || 0).toLocaleString();
  const state = {options:{programs:[],terms:[],sections:[]}, rows:[], page:1, pages:1, generation:0, detailGeneration:0, ready:false,currentTerm:'all'};
  const modal = new bootstrap.Modal($('deanStudentDetailsModal'));
  let searchTimer;
  function notice(message = '') {
    $('deanStudentsNotice').textContent = message;
    $('deanStudentsNotice').className = `alert mt-3 mb-0 ${message ? 'alert-danger' : 'd-none'}`;
  }
  function filters() {
    return {search:$('deanStudentsSearch').value.trim(), term:$('deanStudentsTerm').value || 'all',
      program_id:$('deanStudentsProgram').value || '0', section_id:$('deanStudentsSection').value || '0',
      email_status:$('deanStudentsEmailStatus').value || 'all',
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
    const choices = state.options.sections.filter(row => (!program || row.program_id === program) && (term === 'all' || row.term === term || (term.startsWith('year:') && row.term.split(':')[0] === term.slice(5))));
    const labels = new Map(state.options.terms.map(row=>[row.key,row.label]));
    $('deanStudentsSection').innerHTML = '<option value="0">All class sections</option>' + choices.map(row=>
      `<option value="${row.section_id}">${escape(row.section_label)}${term === 'all' || term.startsWith('year:') ? ` · ${escape(labels.get(row.term) || row.term)}` : ''}</option>`).join('');
    $('deanStudentsSection').value = choices.some(row=>String(row.section_id) === previous) ? previous : '0';
  }
  function loading() {
    state.rows = [];
    $('deanStudentsRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Loading students...</td></tr>';
    $('deanStudentsPrevious').disabled = true; $('deanStudentsNext').disabled = true;
    $('deanStudentsPageInfo').textContent = '';
    ['deanUniqueStudents','deanImportedClasses','deanImportedPrograms'].forEach(id=>{ $(id).textContent = '—'; });
    $('deanStudentDirectory').setAttribute('aria-busy','true');
    $('deanStudentsRefresh').synkStudentDirectory=null;
    if ($('deanStudentsRefresh').dispatchEvent) $('deanStudentsRefresh').dispatchEvent(new CustomEvent('student-directory-loading',{detail:{filters:filters()}}));
  }
  function render(data) {
    state.rows = data.rows; state.page = data.pagination.page; state.pages = data.pagination.pages;
    $('deanUniqueStudents').textContent = number(data.summary.students);
    $('deanImportedClasses').textContent = number(data.summary.classes);
    $('deanImportedPrograms').textContent = number(data.summary.programs);
    if (!data.rows.length) {
      const query = filters();
      const filtered = query.search || query.term !== 'all' || query.program_id !== '0' || query.section_id !== '0' || query.email_status !== 'all';
      $('deanStudentsRows').innerHTML = `<tr><td colspan="6"><div class="dean-empty-state my-2"><i class="bx bx-user-pin" aria-hidden="true"></i><h5>${filtered ? 'No matching students' : 'No students yet'}</h5><p class="mb-0">${filtered ? 'Try another name or ID, or choose another academic term.' : 'Add a student or import students through Faculty Workload.'}</p></div></td></tr>`;
    } else {
      $('deanStudentsRows').innerHTML = data.rows.map(row=>{
        const email=String(row.email_address || '').trim().toLowerCase();
        return `<tr><td><input type="checkbox" class="form-check-input" data-select-student="${row.student_id}" aria-label="${escape('Select '+row.student_name)}"></td><td class="dean-student-name"><div class="dean-student-id">${escape(row.student_number)}</div><div class="dean-student-full-name" title="${escape(row.student_name)}">${escape(row.student_name)}</div><div class="dean-student-contact${email ? '' : ' text-warning'}" title="${escape(email)}"><em>${escape(email || 'missing email')}</em></div></td><td><div class="dean-student-context" title="${escape(row.programs)}">${escape(row.programs || '—')}</div></td><td><div class="dean-student-context" title="${escape(row.sections)}">${escape(row.sections || '—')}</div></td><td class="text-center"><span class="badge bg-label-primary">${number(row.subject_count)}</span></td><td><div class="dean-student-row-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-student="${row.student_id}" aria-label="${escape('View details for ' + row.student_name)}">View</button><button type="button" class="btn btn-sm btn-outline-secondary" data-student-edit="${row.student_id}" aria-label="${escape('Edit '+row.student_name)}">Edit</button></div></td></tr>`;
      }).join('');
    }
    $('deanStudentsPageInfo').textContent = data.pagination.total ? `${number(data.pagination.from)}–${number(data.pagination.to)} of ${number(data.pagination.total)} students · Page ${state.page} of ${state.pages}` : '0 students';
    $('deanStudentsPrevious').disabled = state.page <= 1;
    $('deanStudentsNext').disabled = state.page >= state.pages;
    $('deanStudentsRefresh').synkStudentDirectory={rows:state.rows,filters:filters(),options:state.options,pagination:data.pagination};
    if ($('deanStudentsRefresh').dispatchEvent) $('deanStudentsRefresh').dispatchEvent(new CustomEvent('student-directory-rendered',{detail:$('deanStudentsRefresh').synkStudentDirectory}));
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
    const previous = preserve ? filters() : {program_id:'0',section_id:'0',term:'',email_status:'all'};
    state.ready = false; state.generation++; loading(); notice();
    $('deanStudentEmailsOpen').disabled = true;
    $('deanStudentAdd').disabled = true;
    $('deanStudentsRefresh').disabled = true;
    try {
      const data = await request('bootstrap'); state.options = data.options;
      const currentKey = `${data.current_term.ay_id}:${data.current_term.semester}`;
      state.currentTerm=data.options.terms.some(row=>row.key===currentKey) ? currentKey : 'all';
      $('deanStudentsProgram').innerHTML = '<option value="0">All programs</option>' + data.options.programs.map(row=>`<option value="${row.program_id}">${escape(row.label)}</option>`).join('');
      const years=[...new Map(data.options.terms.map(row=>[row.key.split(':')[0],row.ay_label || row.label.split(' - ')[0]])).entries()];
      $('deanStudentsTerm').innerHTML = '<option value="all">All academic terms</option>' + data.options.terms.map(row=>`<option value="${escape(row.key)}">${escape(row.label)}${row.key === currentKey ? ' (Current)' : ''}</option>`).join('') + years.map(([id,label])=>`<option value="year:${escape(id)}">${escape(label)} - All semesters</option>`).join('');
      $('deanStudentsProgram').value = data.options.programs.some(row=>String(row.program_id) === previous.program_id) ? previous.program_id : '0';
      $('deanStudentsTerm').value = previous.term==='all' || data.options.terms.some(row=>row.key === previous.term) || (previous.term.startsWith('year:') && years.some(([id])=>`year:${id}`===previous.term)) ? previous.term : state.currentTerm;
      $('deanStudentsEmailStatus').value=previous.email_status;
      sections();
      if (preserve && state.options.sections.some(row=>String(row.section_id) === previous.section_id
          && (!Number(previous.program_id) || row.program_id === Number(previous.program_id))
          && (previous.term === 'all' || row.term === previous.term || (previous.term.startsWith('year:') && row.term.split(':')[0] === previous.term.slice(5))))) $('deanStudentsSection').value = previous.section_id;
      ['deanStudentsProgram','deanStudentsTerm','deanStudentsSection','deanStudentsEmailStatus','deanStudentsSearchButton','deanStudentsReset'].forEach(id=>{ $(id).disabled = false; });
      state.ready = true; await load();
    } catch (error) {
      notice(error.message);
      $('deanStudentsRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5">Students could not be loaded. Use Refresh to try again.</td></tr>';
    } finally { $('deanStudentsRefresh').disabled = false; $('deanStudentEmailsOpen').disabled = !state.ready; $('deanStudentAdd').disabled = !state.ready; $('deanStudentDirectory').setAttribute('aria-busy','false'); }
  }
  async function details(row) {
    const generation = ++state.detailGeneration;
    const term = $('deanStudentsTerm').value || 'all';
    $('deanStudentDetailsTitle').textContent = row.student_name;
    $('deanStudentDetailsTerm').textContent = `${$('deanStudentsTerm').selectedOptions?.[0]?.textContent || state.options.terms.find(item=>item.key === term)?.label || term} · Your assigned college`;
    $('deanStudentDetailsContent').innerHTML = '<p class="text-muted py-4 mb-0">Loading student details...</p>';
    $('deanStudentDetailsEdit').disabled=true;
    $('deanStudentDetailsEdit').setAttribute('data-student-edit',String(row.student_id));
    modal.show();
    try {
      const data = await request('detail',{student_id:row.student_id,term});
      if (generation !== state.detailGeneration) return;
      const student = data.student;
      $('deanStudentDetailsTitle').textContent = student.student_name;
      const profile = `<div class="dean-student-profile"><div class="dean-student-id">${escape(student.student_number)}</div><div class="dean-student-full-name" title="${escape(student.student_name)}">${escape(student.student_name)}</div><div class="dean-student-contact"><em>${escape(String(student.email_address || 'Not provided').toLowerCase())}</em></div></div>`;
      const subjects = data.subjects.length ? `<div class="table-responsive"><table class="table dean-students-table"><thead><tr><th>Subject</th><th>Program / Class Section</th><th>Academic Term</th><th>Faculty</th><th>Imported From</th></tr></thead><tbody>${data.subjects.map(subject=>`<tr><td><div class="fw-semibold text-primary">${escape(subject.subject_code)}</div><div class="small mt-1">${escape(subject.descriptive_title)}</div></td><td>${escape(subject.section_label)}<div class="small text-muted mt-1">${escape(subject.program_label)}</div></td><td>${escape(subject.term_label)}</td><td>${escape(subject.faculty_name || 'Not specified')}</td><td class="dean-student-file">${escape(subject.source_file_name || '—')}<div class="small text-muted mt-1">${escape(subject.imported_at ? subject.imported_at.slice(0,10) : '')}</div></td></tr>`).join('')}</tbody></table></div>` : '<div class="dean-empty-state"><h5>No subjects in this term</h5><p class="mb-0">This student has no imported subjects for the selected academic term.</p></div>';
      $('deanStudentDetailsContent').innerHTML = `${profile}<h6 class="mb-3">Imported Subjects <span class="badge bg-label-primary ms-1">${number(data.subjects.length)}</span></h6>${subjects}`;
      $('deanStudentDetailsEdit').disabled=false;
    } catch (error) {
      if (generation !== state.detailGeneration) return;
      $('deanStudentDetailsContent').innerHTML = `<div class="alert alert-danger mb-0" role="alert">${escape(error.message)}</div>`;
    }
  }
  $('deanStudentsFilters').addEventListener('submit',event=>{ event.preventDefault(); load(); });
  $('deanStudentsSearch').addEventListener('input',()=>{ clearTimeout(searchTimer); searchTimer = setTimeout(()=>load(),300); });
  ['deanStudentsProgram','deanStudentsTerm'].forEach(id=>$(id).addEventListener('change',()=>{ sections(); load(); }));
  ['deanStudentsSection','deanStudentsEmailStatus','deanStudentsSort','deanStudentsPageSize'].forEach(id=>$(id).addEventListener('change',()=>load()));
  $('deanStudentsPrevious').addEventListener('click',()=>{ if (state.page > 1) { state.page--; load(false); } });
  $('deanStudentsNext').addEventListener('click',()=>{ if (state.page < state.pages) { state.page++; load(false); } });
  $('deanStudentsRefresh').addEventListener('click',()=>initialize(true));
  $('deanStudentsRefresh').addEventListener('student-emails-saved',()=>load(false));
  $('deanStudentsRefresh').addEventListener('student-records-changed',()=>initialize(true));
  $('deanStudentsReset').addEventListener('click',()=>{
    $('deanStudentsSearch').value = ''; $('deanStudentsProgram').value = '0'; $('deanStudentsTerm').value = state.currentTerm; $('deanStudentsEmailStatus').value='all'; sections(); load();
  });
  $('deanStudentsRows').addEventListener('click',event=>{
    const button = event.target.closest('[data-student]');
    const row = button && state.rows.find(item=>item.student_id === Number(button.dataset.student));
    if (row) details(row);
  });
  $('deanStudentDetailsModal').addEventListener('hide.bs.modal',()=>{ state.detailGeneration++; });
  initialize();
})();
