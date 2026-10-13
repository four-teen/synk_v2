(() => {
  'use strict';
  const $=id=>document.getElementById(id);
  const config=JSON.parse($('deanStudentsConfig').textContent);
  const escape=value=>String(value==null?'':value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const editor=new bootstrap.Modal($('deanStudentEditorModal'));
  const deletion=new bootstrap.Modal($('deanStudentDeleteModal'));
  const state={selected:new Set(),rows:[],filters:{},key:'',ready:false,selecting:false,editorBusy:false,editorReady:false,editorShown:false,editorGeneration:0,deleteBusy:false,deleteShown:false,deleteToken:'',deleteIds:[],deleteSingle:false};
  const key=filters=>JSON.stringify([filters.search,filters.term,filters.program_id,filters.section_id,filters.email_status || 'all']);
  function notice(id,message='',type='danger') { $(id).textContent=message; $(id).className=`alert ${id==='deanStudentManagementNotice'?'mt-3 mb-0 ':''}${message?'alert-'+type:'d-none'}`; }
  async function request(action,params={}) {
    const body=new FormData(); Object.entries({...config,action,...params}).forEach(([field,value])=>body.append(field,value));
    let response,data;
    try { response=await fetch('../backend/query_college_dean_students.php',{method:'POST',body,credentials:'same-origin',cache:'no-store'}); }
    catch (_) { throw Error('Unable to connect. Reload the directory before retrying a save or deletion.'); }
    try { data=await response.json(); } catch (_) { throw Error('Unable to read the server response. Reload the directory and try again.'); }
    if (!response.ok || data.status!=='ok') throw Error(data.message || 'Unable to complete this student request.');
    return data;
  }
  function selectionControls() {
    const disabled=!state.ready || state.selecting;
    const visible=state.rows.filter(row=>state.selected.has(row.student_id)).length;
    $('deanStudentsSelectedCount').textContent=`${state.selected.size.toLocaleString()} selected`;
    $('deanStudentsSelectPage').disabled=disabled || !state.rows.length;
    $('deanStudentsSelectPage').checked=state.rows.length>0 && visible===state.rows.length;
    $('deanStudentsSelectPage').indeterminate=visible>0 && visible<state.rows.length;
    $('deanStudentsSelectResults').disabled=disabled || !state.rows.length;
    $('deanStudentsClearSelection').disabled=state.selecting || !state.selected.size;
    $('deanStudentsDelete').disabled=disabled || !state.selected.size || state.filters.term==='all';
    $('deanStudentsDeleteHint').textContent=state.filters.term==='all' ? 'Choose a specific academic year or semester to delete its records.' : 'Selections carry across pages. Deletion is limited to the selected academic term and class filters.';
    $('deanStudentsRows').querySelectorAll('[data-select-student]').forEach(input=>{ input.checked=state.selected.has(Number(input.dataset.selectStudent)); input.disabled=disabled; });
  }
  function rendered(data) {
    if (state.key!==key(data.filters)) state.selected.clear();
    state.key=key(data.filters); state.filters=data.filters; state.rows=data.rows; state.adminTerm=data.admin_term || `${config.ay_id}:${config.semester}`; state.ready=true; selectionControls();
  }
  $('deanStudentsRefresh').addEventListener('student-directory-loading',event=>{
    if (state.key!==key(event.detail.filters)) state.selected.clear();
    state.key=key(event.detail.filters); state.filters=event.detail.filters; state.ready=false; selectionControls();
  });
  $('deanStudentsRefresh').addEventListener('student-directory-rendered',event=>rendered(event.detail));
  $('deanStudentsSelectPage').addEventListener('change',()=>{
    state.rows.forEach(row=>{ if ($('deanStudentsSelectPage').checked) state.selected.add(row.student_id); else state.selected.delete(row.student_id); }); selectionControls();
  });
  $('deanStudentsClearSelection').addEventListener('click',()=>{ state.selected.clear(); selectionControls(); });
  $('deanStudentsSelectResults').addEventListener('click',async()=>{
    if (!state.ready || state.selecting) return;
    const previousKey=state.key; state.selecting=true; selectionControls(); notice('deanStudentManagementNotice');
    try {
      const data=await request('selection',state.filters);
      if (state.key!==previousKey) return;
      state.selected=new Set(data.student_ids);
    } catch (error) { notice('deanStudentManagementNotice',error.message); }
    finally { state.selecting=false; selectionControls(); }
  });
  $('deanStudentsRows').addEventListener('change',event=>{
    const input=event.target.closest('[data-select-student]'); if (!input || !state.ready) return;
    const id=Number(input.dataset.selectStudent); if (!state.rows.some(row=>row.student_id===id)) return;
    if (input.checked) state.selected.add(id); else state.selected.delete(id); selectionControls();
  });
  function editorControls() {
    $('deanStudentEditorFields').disabled=state.editorBusy || !state.editorReady;
    $('deanStudentEditorSave').disabled=state.editorBusy || !state.editorReady || !state.editorShown;
    $('deanStudentEditorSave').textContent=state.editorBusy?'Please wait...':'Save Student';
    $('deanStudentEditorClose').disabled=state.editorBusy; $('deanStudentEditorCancel').disabled=state.editorBusy;
    $('deanStudentEditorModal').setAttribute('aria-busy',String(state.editorBusy));
  }
  async function openEditor(id=0) {
    if (state.editorBusy) return;
    const generation=++state.editorGeneration;
    state.editorBusy=true; state.editorReady=false; state.editorShown=false; $('deanStudentEditorForm').reset();
    $('deanStudentEditId').value=String(id); $('deanStudentEditVersion').value='';
    $('deanStudentNumber').readOnly=!!id; $('deanStudentCreationFields').hidden=!!id;
    ['deanStudentRecordProgram','deanStudentRecordYear','deanStudentRecordSemester'].forEach(field=>$(field).required=!id);
    $('deanStudentEditorTitle').textContent=id?'Edit Student':'Add Student';
    $('deanStudentEditorHelp').textContent=id?'Name and email changes apply to this shared student profile across every academic term. Verify the actual school address before saving.':'Add a unique student profile. You can import their class lists later.';
    notice('deanStudentEditorNotice','Loading student form...','info'); editorControls(); editor.show();
    try {
      if (id) {
        const data=await request('edit_load',{student_id:id}); if (generation!==state.editorGeneration) return;
        const s=data.student;
        $('deanStudentNumber').value=s.student_number; $('deanStudentEditVersion').value=s.version;
        for (const [field,element] of Object.entries({first_name:'deanStudentFirstName',last_name:'deanStudentLastName',middle_name:'deanStudentMiddleName',suffix_name:'deanStudentSuffixName',email_address:'deanStudentEmail'})) $(element).value=s[field] || '';
      } else {
        const data=await request('form_options'); if (generation!==state.editorGeneration) return;
        $('deanStudentRecordProgram').innerHTML='<option value="">Choose program</option>'+data.programs.map(p=>`<option value="${p.program_id}">${escape(p.label)}</option>`).join('');
        $('deanStudentRecordYear').innerHTML=data.academic_years.map(ay=>`<option value="${ay.ay_id}">${escape(ay.ay)}</option>`).join('');
        const term=state.filters.term || 'all'; const ay=term.startsWith('year:')?term.slice(5):term==='all'?String(data.current_term.ay_id):term.split(':')[0];
        $('deanStudentRecordYear').value=ay; $('deanStudentRecordSemester').value=term==='all'||term.startsWith('year:')?String(data.current_term.semester || 1):term.split(':')[1];
        $('deanStudentRecordProgram').value=data.programs.some(p=>String(p.program_id)===String(state.filters.program_id))?String(state.filters.program_id):data.programs.length===1?String(data.programs[0].program_id):'';
      }
      state.editorReady=true; notice('deanStudentEditorNotice');
    } catch (error) { if (generation===state.editorGeneration) notice('deanStudentEditorNotice',error.message); }
    finally { if (generation===state.editorGeneration) { state.editorBusy=false; editorControls(); } }
  }
  function names() { return {student_number:$('deanStudentNumber').value.trim(),first_name:$('deanStudentFirstName').value.trim(),last_name:$('deanStudentLastName').value.trim(),middle_name:$('deanStudentMiddleName').value.trim(),suffix_name:$('deanStudentSuffixName').value.trim()}; }
  $('deanStudentAdd').addEventListener('click',()=>openEditor());
  $('deanStudentsRows').addEventListener('click',event=>{ const button=event.target.closest('[data-student-edit]'); if (button) openEditor(Number(button.dataset.studentEdit)); });
  function editFromModal(modalId,id) {
    const element=$(modalId); const instance=bootstrap.Modal.getInstance(element);
    if (element.classList.contains('show') && instance) { element.addEventListener('hidden.bs.modal',()=>openEditor(id),{once:true}); instance.hide(); }
    else openEditor(id);
  }
  $('deanStudentDetailsEdit').addEventListener('click',()=>editFromModal('deanStudentDetailsModal',Number($('deanStudentDetailsEdit').getAttribute('data-student-edit'))));
  $('deanStudentEmailsPreview').addEventListener('click',event=>{ const button=event.target.closest('[data-email-edit]'); if (button) editFromModal('deanStudentEmailsModal',Number(button.dataset.emailEdit)); });
  $('deanStudentGenerateEmail').addEventListener('click',async()=>{
    if (state.editorBusy || !state.editorReady) return;
    const input=names(); state.editorBusy=true; editorControls(); notice('deanStudentEditorNotice');
    try {
      const data=await request('email_propose',input);
      if (JSON.stringify(input)!==JSON.stringify(names())) return;
      if (data.candidate.status==='ready') { $('deanStudentEmail').value=data.candidate.email; notice('deanStudentEditorNotice','Suggested email. Confirm that it matches the actual school account before saving.','info'); }
      else notice('deanStudentEditorNotice',data.candidate.reason,'warning');
    } catch (error) { notice('deanStudentEditorNotice',error.message); }
    finally { state.editorBusy=false; editorControls(); }
  });
  $('deanStudentEditorForm').addEventListener('submit',async event=>{
    event.preventDefault(); if (state.editorBusy || !state.editorReady || !state.editorShown) return;
    const payload={...names(),student_id:$('deanStudentEditId').value,version:$('deanStudentEditVersion').value,email_address:$('deanStudentEmail').value.trim(),
      record_program_id:$('deanStudentRecordProgram').value,record_term:`${$('deanStudentRecordYear').value}:${$('deanStudentRecordSemester').value}`,year_level:$('deanStudentYearLevel').value};
    state.editorBusy=true; editorControls(); notice('deanStudentEditorNotice');
    try {
      const data=await request('student_save',payload);
      state.editorBusy=false; editorControls(); editor.hide(); state.selected.clear();
      notice('deanStudentManagementNotice',data.message,'success'); $('deanStudentsRefresh').dispatchEvent(new CustomEvent('student-records-changed'));
    } catch (error) { notice('deanStudentEditorNotice',error.message); }
    finally { state.editorBusy=false; editorControls(); }
  });
  $('deanStudentEditorModal').addEventListener('shown.bs.modal',()=>{ state.editorShown=true; editorControls(); });
  $('deanStudentEditorModal').addEventListener('hide.bs.modal',event=>{ if (state.editorBusy) event.preventDefault(); else { state.editorShown=false; state.editorGeneration++; } });
  function deleteControls() {
    ['deanStudentDeleteClose','deanStudentDeleteCancel'].forEach(id=>$(id).disabled=state.deleteBusy || !state.deleteShown);
    $('deanStudentDeleteAcknowledge').disabled=state.deleteBusy || !state.deleteShown || !state.deleteToken;
    $('deanStudentDeleteConfirm').disabled=state.deleteBusy || !state.deleteShown || !state.deleteToken || !$('deanStudentDeleteAcknowledge').checked;
    $('deanStudentDeleteConfirm').textContent=state.deleteBusy?'Please wait...':'Delete Term Records';
    $('deanStudentDeleteModal').setAttribute('aria-busy',String(state.deleteBusy));
  }
  async function openDeletion(ids,single=false) {
    if (!state.ready || !ids.length || state.filters.term==='all' || state.deleteBusy || config.read_only
      || state.filters.term!==state.adminTerm) return;
    state.deleteIds=[...ids]; state.deleteSingle=single;
    $('deanStudentDeleteTitle').textContent=single?'Delete Student From This Term':'Delete Selected Term Records';
    $('deanStudentDeleteScope').textContent=single
      ? 'This removes this student from all class lists and manual directory entries in your college for the displayed semester. The student profile, login email, and other terms stay available.'
      : 'This removes the selected students from the matching class lists and manual directory entries for this academic term. Shared student profiles, login emails, and records in other terms stay available.';
    state.deleteToken=''; state.deleteBusy=true; state.deleteShown=false; $('deanStudentDeleteAcknowledge').checked=false;
    $('deanStudentDeleteTerm').textContent=$('deanStudentsTerm').selectedOptions[0]?.textContent || state.filters.term;
    $('deanStudentDeletePreview').innerHTML='<p class="text-muted">Checking the selected students and matching class-list records...</p>';
    notice('deanStudentDeleteNotice'); deleteControls(); deletion.show();
    try {
      const deletionFilters=single ? {...state.filters,search:'',program_id:'0',section_id:'0',email_status:'all'} : state.filters;
      const data=await request('delete_preview',{...deletionFilters,selected_ids:JSON.stringify(state.deleteIds)}); state.deleteToken=data.preview_token;
      const s=data.summary;
      $('deanStudentDeletePreview').innerHTML=`<p><strong>${s.students} selected students</strong> · ${s.class_records} class-list records · ${s.classes} classes${s.manual_records?` · ${s.manual_records} manual directory entries`:''}</p><div class="table-responsive"><table class="table dean-students-table"><thead><tr><th>Student ID</th><th>Student name</th><th>Class records to remove</th></tr></thead><tbody>${data.students.map(row=>`<tr><td class="dean-student-id">${escape(row.student_number)}</td><td>${escape(row.student_name)}</td><td>${row.class_records}</td></tr>`).join('')}</tbody></table></div>`;
    } catch (error) { $('deanStudentDeletePreview').innerHTML=''; notice('deanStudentDeleteNotice',error.message); }
    finally { state.deleteBusy=false; deleteControls(); }
  }
  $('deanStudentsDelete').addEventListener('click',()=>openDeletion([...state.selected]));
  $('deanStudentsRows').addEventListener('click',event=>{
    const button=event.target.closest('[data-student-delete]');
    const id=Number(button?.dataset.studentDelete || 0);
    if (state.rows.some(row=>row.student_id===id)) openDeletion([id],true);
  });
  $('deanStudentDeleteAcknowledge').addEventListener('change',deleteControls);
  $('deanStudentDeleteConfirm').addEventListener('click',async()=>{
    if (state.deleteBusy || !state.deleteShown || !state.deleteToken || !$('deanStudentDeleteAcknowledge').checked) return;
    state.deleteBusy=true; deleteControls(); notice('deanStudentDeleteNotice');
    try {
      const data=await request('delete_save',{preview_token:state.deleteToken}); state.deleteToken='';
      if (state.deleteSingle) state.deleteIds.forEach(id=>state.selected.delete(id)); else state.selected.clear();
      state.deleteBusy=false; deleteControls(); deletion.hide();
      notice('deanStudentManagementNotice',data.message,'success'); $('deanStudentsRefresh').dispatchEvent(new CustomEvent('student-records-changed'));
    } catch (error) { state.deleteToken=''; notice('deanStudentDeleteNotice',error.message); }
    finally { state.deleteBusy=false; deleteControls(); }
  });
  $('deanStudentDeleteModal').addEventListener('shown.bs.modal',()=>{ state.deleteShown=true; deleteControls(); });
  $('deanStudentDeleteModal').addEventListener('hide.bs.modal',event=>{ if (state.deleteBusy) event.preventDefault(); else { state.deleteShown=false; state.deleteToken=''; deleteControls(); } });
  if ($('deanStudentsRefresh').synkStudentDirectory) rendered($('deanStudentsRefresh').synkStudentDirectory);
  else selectionControls();
})();
