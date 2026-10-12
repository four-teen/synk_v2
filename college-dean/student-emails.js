(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('deanStudentsConfig').textContent);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const modal = new bootstrap.Modal($('deanStudentEmailsModal'));
  const state = {token:'',rows:[],selected:new Set(),busy:false,generation:0,filters:{}};
  function notice(message='', type='danger') {
    $('deanStudentEmailsNotice').textContent=message;
    $('deanStudentEmailsNotice').className=`alert ${message ? 'alert-'+type : 'd-none'}`;
  }
  function controls() {
    ['deanStudentEmailsReload','deanStudentEmailsClose','deanStudentEmailsCancel','deanStudentEmailsAll','deanStudentEmailsReviewOnly'].forEach(id=>$(id).disabled=state.busy);
    $('deanStudentEmailsSave').disabled=state.busy || !state.token || !state.selected.size;
    $('deanStudentEmailsSave').textContent=state.busy ? 'Please wait...' : `Save Selected (${state.selected.size})`;
    const eligible=state.rows.filter(row=>row.status==='ready').length;
    $('deanStudentEmailsAll').checked=eligible>0 && state.selected.size===eligible;
    $('deanStudentEmailsAll').indeterminate=state.selected.size>0 && state.selected.size<eligible;
    $('deanStudentEmailsAll').disabled=state.busy || !state.token || !eligible;
    $('deanStudentEmailsModal').setAttribute('aria-busy',String(state.busy));
    $('deanStudentEmailsPreview').querySelectorAll('input[data-email-student]').forEach(input=>input.disabled=state.busy || !state.token);
    $('deanStudentEmailsPreview').querySelectorAll('button[data-email-edit]').forEach(button=>button.disabled=state.busy);
  }
  function render() {
    const rows=state.rows.filter(row=>!$('deanStudentEmailsReviewOnly').checked || row.status==='review');
    const labels={ready:'Proposed',preserved:'Kept',review:'Needs review'};
    const colors={ready:'success',preserved:'secondary',review:'warning'};
    $('deanStudentEmailsPreview').innerHTML=rows.length ? `<table class="table dean-email-table"><thead><tr><th scope="col">Select</th><th scope="col">Student</th><th scope="col">Email</th><th scope="col">Status</th></tr></thead><tbody>${rows.map(row=>`<tr><td data-label="Select">${row.status==='ready' ? `<input type="checkbox" class="form-check-input" data-email-student="${row.student_id}" aria-label="${escape('Approve email for '+row.student_name)}" ${state.selected.has(row.student_id) ? 'checked' : ''}>` : '<span class="text-muted">&mdash;</span>'}</td><td data-label="Student"><div class="fw-semibold">${escape(row.student_name)}</div><small class="dean-student-id">${escape(row.student_number)}</small></td><td data-label="Email" class="dean-student-email">${escape(row.email || 'Not generated')}</td><td data-label="Status"><span class="badge bg-label-${colors[row.status]}">${labels[row.status]}</span><p class="small text-muted mt-2 mb-0">${escape(row.reason)}</p><button type="button" class="btn btn-sm btn-outline-primary mt-2" data-email-edit="${row.student_id}" aria-label="${escape('Edit '+row.student_name)}">Edit Student</button></td></tr>`).join('')}</tbody></table>` : '<p class="text-muted py-3">No students match this view.</p>';
    controls();
  }
  async function request(action, params={}) {
    const body=new FormData();
    Object.entries({...config,action,...params}).forEach(([key,value])=>body.append(key,value));
    let response;
    try { response=await fetch('../backend/query_college_dean_students.php',{method:'POST',body,credentials:'same-origin',cache:'no-store'}); }
    catch (_) { throw new Error('Unable to connect. Preview again before retrying a save.'); }
    let data;
    try { data=await response.json(); } catch (_) { throw new Error('The server returned an unreadable response. Preview again.'); }
    if (!response.ok || data.status!=='ok') throw new Error(data.message || 'Unable to update student emails.');
    return data;
  }
  async function preview() {
    if (state.busy) return;
    const generation=++state.generation;
    state.busy=true; state.token=''; state.rows=[]; state.selected.clear();
    $('deanStudentEmailsReviewOnly').checked=false; $('deanStudentEmailsTools').hidden=true;
    $('deanStudentEmailsSummary').innerHTML='';
    $('deanStudentEmailsPreview').innerHTML='<p class="text-muted py-3">Checking student names and duplicate email addresses...</p>';
    notice(); controls();
    try {
      const data=await request('email_preview',state.filters);
      if (generation!==state.generation) return;
      state.token=data.preview_token; state.rows=data.rows;
      const s=data.summary;
      $('deanStudentEmailsSummary').innerHTML=`<span class="badge bg-label-primary">${s.students} unique students</span><span class="badge bg-label-success">${s.ready} eligible</span><span class="badge bg-label-secondary">${s.preserved} existing emails kept</span><span class="badge bg-label-warning">${s.review} need review</span>`;
      $('deanStudentEmailsTools').hidden=!state.rows.length;
      if (!s.ready) notice(s.review ? 'Use Edit Student to verify unclear names or enter the confirmed school address, then preview again.' : 'These students already have emails, or no students match your filters.','info');
      render();
    } catch (error) {
      if (generation!==state.generation) return;
      notice(error.message); $('deanStudentEmailsPreview').innerHTML='';
    } finally { if (generation===state.generation) { state.busy=false; controls(); } }
  }
  $('deanStudentEmailsOpen').addEventListener('click',()=>{
    state.filters={search:$('deanStudentsSearch').value.trim(),term:$('deanStudentsTerm').value || 'all',
      email_status:$('deanStudentsEmailStatus').value || 'all',
      program_id:$('deanStudentsProgram').value || '0',section_id:$('deanStudentsSection').value || '0'};
    modal.show(); preview();
  });
  $('deanStudentEmailsReload').addEventListener('click',preview);
  $('deanStudentEmailsReviewOnly').addEventListener('change',render);
  $('deanStudentEmailsAll').addEventListener('change',()=>{
    state.selected.clear();
    if ($('deanStudentEmailsAll').checked) state.rows.filter(row=>row.status==='ready').forEach(row=>state.selected.add(row.student_id));
    render();
  });
  $('deanStudentEmailsPreview').addEventListener('change',event=>{
    const input=event.target.closest('input[data-email-student]');
    if (!input || state.busy) return;
    const id=Number(input.dataset.emailStudent);
    if (!state.rows.some(row=>row.student_id===id && row.status==='ready')) return;
    if (input.checked) state.selected.add(id); else state.selected.delete(id);
    controls();
  });
  $('deanStudentEmailsSave').addEventListener('click',async()=>{
    if (state.busy || !state.token || !state.selected.size) return;
    state.busy=true; notice(); controls();
    try {
      const data=await request('email_save',{preview_token:state.token,selected_ids:JSON.stringify([...state.selected])});
      state.token=''; state.selected.clear();
      $('deanStudentEmailsPreview').innerHTML=''; $('deanStudentEmailsTools').hidden=true;
      $('deanStudentEmailsSummary').innerHTML=''; notice(data.message,'success');
      $('deanStudentsRefresh').dispatchEvent(new CustomEvent('student-emails-saved'));
    } catch (error) {
      state.token=''; state.selected.clear(); render(); notice(error.message);
    } finally { state.busy=false; controls(); }
  });
  $('deanStudentEmailsModal').addEventListener('hide.bs.modal',event=>{
    if (state.busy) { event.preventDefault(); return; }
    state.generation++; state.token=''; state.selected.clear();
  });
})();
