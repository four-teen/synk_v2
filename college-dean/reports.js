(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('deanReportConfig').textContent);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(value || 0).toLocaleString();
  const state = {rows:[], options:null, generation:0, detailGeneration:0, detail:null, searchTimer:null};
  const modal = new bootstrap.Modal($('deanFacultyClassesModal'));
  const filters = () => ({term:$('deanReportTerm').value, program_id:$('deanReportProgram').value || 0,
    status:$('deanReportStatus').value || 'all', search:$('deanReportSearch').value.trim()});
  function notice(message='') {
    $('deanReportNotice').textContent = message;
    $('deanReportNotice').className = `alert mt-3 mb-0 ${message ? 'alert-danger' : 'd-none'}`;
  }
  async function request(action, params={}) {
    const body = new FormData();
    Object.entries({...config,action,...params}).forEach(([key,value])=>body.append(key,value));
    let response, data;
    try { response = await fetch('../backend/query_college_dean_faculty_students.php', {method:'POST', body, credentials:'same-origin', cache:'no-store'}); }
    catch (_) { throw new Error('Unable to connect. Please try again.'); }
    try { data = await response.json(); } catch (_) { throw new Error('Unable to load the faculty report. Reload the page and try again.'); }
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to load the faculty report.');
    return data;
  }
  function render(data) {
    state.rows = data.rows;
    const summary = data.summary;
    [['deanReportUnique','unique_students'],['deanReportEnrollments','total_enrollments'],['deanReportMissing','without_lists'],['deanReportFacultyMissing','faculties_missing']].forEach(([id,key])=>{ $(id).textContent=number(summary[key]); });
    $('deanReportSummary').hidden = false;
    $('deanReportRows').innerHTML = state.rows.length ? state.rows.map(row=>`<tr><td><span class="fw-semibold">${escape(row.faculty_name)}</span>${!row.assigned_classes ? '<div class="small text-muted mt-1">No assigned classes</div>' : ''}</td><td>${number(row.assigned_classes)}</td><td>${number(row.with_lists)}</td><td class="${row.without_lists ? 'dean-report-missing' : 'text-muted'}">${number(row.without_lists)}</td><td>${number(row.unique_students)}</td><td>${number(row.total_enrollments)}</td><td><button type="button" class="btn btn-sm btn-outline-primary" data-faculty-classes="${row.faculty_id}">View Classes</button></td></tr>`).join('') : '<tr><td colspan="7" class="text-center py-4 text-muted">No faculty match the selected filters.</td></tr>';
    $('deanReportCaption').textContent = `${number(summary.faculties)} faculty · ${number(summary.assigned_classes)} assigned classes · ${number(summary.with_lists)} with student lists · ${data.term.label}`;
  }
  async function load() {
    const generation = ++state.generation;
    notice(); $('deanReportRefresh').disabled = true; $('deanReportRows').setAttribute('aria-busy','true');
    try {
      const data = await request('list',filters());
      if (generation !== state.generation) return;
      render(data);
    } catch (error) {
      if (generation !== state.generation) return;
      notice(error.message);
      if (!state.rows.length) $('deanReportRows').innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Report could not be loaded. Use Refresh to try again.</td></tr>';
    } finally {
      if (generation === state.generation) { $('deanReportRefresh').disabled = false; $('deanReportRows').setAttribute('aria-busy','false'); }
    }
  }
  function renderClasses() {
    if (!state.detail) return;
    const status = $('deanFacultyClassStatus').value;
    const rows = state.detail.classes.filter(row=>status==='all' || (status==='missing' ? row.student_count===0 : row.student_count>0));
    const emptyCopy = state.detail.classes.length ? 'No classes match this student list filter.' : 'No classes are assigned to this faculty under the selected term and program.';
    $('deanFacultyClassesContent').innerHTML = rows.length ? `<div class="table-responsive"><table class="table dean-report-table mb-0"><thead><tr><th scope="col">Subject</th><th scope="col">Program</th><th scope="col">Section</th><th scope="col">Imported Students</th><th scope="col">Student List</th><th scope="col">Workload</th></tr></thead><tbody>${rows.map(row=>`<tr><td><span class="text-primary fw-semibold">${escape(row.subject_code)}</span><div class="small mt-1">${escape(row.descriptive_title)}</div></td><td>${escape(row.program_label)}</td><td>${escape(row.section_display)}</td><td>${number(row.student_count)}</td><td><span class="badge bg-label-${row.student_count ? 'primary' : 'warning'}">${row.student_count ? 'With Student List' : 'No Students Imported'}</span></td><td>${row.workload_url ? `<a class="btn btn-sm btn-outline-primary" href="${escape(row.workload_url)}">Open Workload</a>` : '<span class="small text-muted">Previous term</span>'}</td></tr>`).join('')}</tbody></table></div>` : `<div class="dean-empty-state">${emptyCopy}</div>`;
  }
  async function openClasses(id) {
    const row = state.rows.find(item=>item.faculty_id===id);
    if (!row) return;
    const generation = ++state.detailGeneration;
    state.detail = null;
    $('deanFacultyClassesTitle').textContent = row.faculty_name;
    $('deanFacultyClassesSubtitle').textContent = '';
    $('deanFacultyClassesTotals').textContent = 'Loading classes…';
    $('deanFacultyClassStatus').value = $('deanReportStatus').value==='missing' ? 'missing' : 'all';
    $('deanFacultyClassStatus').disabled = true;
    $('deanFacultyClassesNotice').hidden = true;
    $('deanFacultyClassesContent').innerHTML = '<div class="dean-empty-state">Loading assigned classes…</div>';
    modal.show();
    try {
      const data = await request('detail',{...filters(),faculty_id:id});
      if (generation !== state.detailGeneration) return;
      state.detail = data;
      $('deanFacultyClassesSubtitle').textContent = data.term.label;
      $('deanFacultyClassesTotals').textContent = `Faculty totals: ${number(data.faculty.unique_students)} unique students · ${number(data.faculty.total_enrollments)} class enrollments · ${number(data.faculty.without_lists)} classes without lists`;
      $('deanFacultyClassesNotice').hidden = data.is_current_term;
      $('deanFacultyClassStatus').disabled = false; renderClasses();
    } catch (error) {
      if (generation !== state.detailGeneration) return;
      $('deanFacultyClassesTotals').textContent = 'Classes unavailable';
      $('deanFacultyClassesContent').innerHTML = `<div class="dean-empty-state">${escape(error.message)}</div>`;
    }
  }
  $('deanReportRows').addEventListener('click',event=>{
    const button = event.target.closest('[data-faculty-classes]');
    if (button) openClasses(Number(button.dataset.facultyClasses));
  });
  $('deanFacultyClassStatus').addEventListener('change',renderClasses);
  $('deanFacultyClassesModal').addEventListener('hide.bs.modal',()=>{state.detailGeneration++;});
  ['deanReportTerm','deanReportProgram','deanReportStatus'].forEach(id=>$(id).addEventListener('change',()=>{ clearTimeout(state.searchTimer); load(); }));
  $('deanReportSearch').addEventListener('input',()=>{ clearTimeout(state.searchTimer); state.searchTimer=setTimeout(load,250); });
  $('deanReportRefresh').addEventListener('click',()=>{clearTimeout(state.searchTimer);load();});
  $('deanReportReset').addEventListener('click',()=>{
    $('deanReportTerm').value = defaultTerm(); $('deanReportProgram').value='0'; $('deanReportStatus').value='all'; $('deanReportSearch').value='';
    clearTimeout(state.searchTimer); load();
  });
  function defaultTerm() {
    const current = state.options.current_term;
    const key = `${current.ay_id}:${current.semester}`;
    return state.options.terms.some(term=>term.key===key) ? key : (state.options.terms[0] || {}).key || '';
  }
  async function initialize() {
    try {
      const data = await request('bootstrap'); state.options = data.options;
      $('deanReportTerm').innerHTML = state.options.terms.map(term=>`<option value="${escape(term.key)}">${escape(term.label)}</option>`).join('');
      $('deanReportTerm').value = defaultTerm();
      $('deanReportProgram').innerHTML = '<option value="0">All programs</option>'+state.options.programs.map(program=>`<option value="${program.program_id}">${escape(program.label)}</option>`).join('');
      $('deanReportProgram').value = '0'; $('deanReportStatus').value='all';
      if (!state.options.terms.length) { notice('No academic terms are available for your college.'); $('deanReportRows').innerHTML='<tr><td colspan="7" class="text-center py-4 text-muted">No report available.</td></tr>'; return; }
      ['deanReportTerm','deanReportProgram','deanReportStatus','deanReportSearch','deanReportRefresh','deanReportReset'].forEach(id=>{ $(id).disabled=false; });
      await load();
    } catch (error) { notice(error.message); $('deanReportRows').innerHTML='<tr><td colspan="7" class="text-center py-4 text-muted">Report could not be loaded. Reload the page to try again.</td></tr>'; }
  }
  initialize();
})();
