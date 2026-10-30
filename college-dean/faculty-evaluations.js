(function () {
  'use strict';
  const modal=document.getElementById('deanClearEvaluationModal');
  if (!modal) return;
  const status=document.getElementById('deanClearEvaluationStatus'),preview=document.getElementById('deanClearEvaluationPreview');
  const confirm=document.getElementById('deanClearEvaluationConfirm'),retry=document.getElementById('deanClearEvaluationRetry');
  let facultyId='',token='',controller=null,version=0,saving=false;
  function error(message) {
    token='';confirm.disabled=true;preview.hidden=true;status.classList.add('is-error');status.setAttribute('role','alert');status.textContent=message;retry.hidden=false;
  }
  async function request(action,values,signal) {
    const response=await fetch(modal.dataset.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal,
      headers:{Accept:'application/json'},body:new URLSearchParams({action,csrf_token:modal.dataset.csrf,...values})});
    const result=await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'The evaluations could not be reviewed.');
    return result;
  }
  async function loadPreview() {
    const current=++version;
    controller?.abort();controller=new AbortController();
    const pending=controller;
    token='';confirm.disabled=true;preview.hidden=true;retry.hidden=true;
    status.classList.remove('is-error');status.setAttribute('role','status');status.textContent='Reviewing this faculty’s submitted evaluations and drafts…';
    const timeout=setTimeout(()=>pending.abort(),20000);
    try {
      const data=await request('preview',{faculty_id:facultyId,ay_id:modal.dataset.ayId,semester:modal.dataset.semester,instrument_version:modal.dataset.instrument},pending.signal);
      if (current!==version) return;
      document.getElementById('deanClearEvaluationFaculty').textContent=data.target.faculty_name;
      document.getElementById('deanClearEvaluationCollege').textContent=data.target.college_name;
      document.getElementById('deanClearEvaluationTerm').textContent=data.target.term_text;
      document.getElementById('deanClearEvaluationInstrument').textContent=data.target.instrument_label;
      document.getElementById('deanClearEvaluationSubmitted').textContent=data.counts.submitted;
      document.getElementById('deanClearEvaluationDrafts').textContent=data.counts.drafts;
      token=data.preview_token;preview.hidden=false;confirm.disabled=false;status.textContent='';
    } catch (cause) {
      if (current===version) error(cause.name==='AbortError'?'The preview took too long. Review the latest records and try again.':cause instanceof SyntaxError?'The preview could not be loaded. Reload the page and sign in again if needed.':cause.message);
    } finally {clearTimeout(timeout);}
  }
  modal.addEventListener('show.bs.modal',event=>{
    facultyId=event.relatedTarget?.dataset.facultyId || '';
    document.getElementById('deanClearEvaluationFaculty').textContent=event.relatedTarget?.dataset.facultyName || 'the selected faculty';
    confirm.textContent='Clear Evaluation';loadPreview();
  });
  modal.addEventListener('hide.bs.modal',event=>{
    if (saving) {event.preventDefault();return;}
    version++;controller?.abort();token='';confirm.disabled=true;
  });
  confirm.addEventListener('click',async()=>{
    if (saving || !token) return;
    saving=true;confirm.disabled=true;confirm.textContent='Clearing…';retry.hidden=true;
    modal.setAttribute('aria-busy','true');status.setAttribute('role','status');status.textContent='Saving clearing history and clearing this faculty’s evaluations…';
    const pending=new AbortController(),timeout=setTimeout(()=>pending.abort(),30000);
    try {
      await request('confirm',{preview_token:token},pending.signal);
      const url=new URL(window.location.href);url.searchParams.delete('faculty_id');url.hash='deanEvaluationFacultyList';window.location.assign(url);
    } catch (cause) {
      saving=false;modal.removeAttribute('aria-busy');confirm.textContent='Clear Evaluation';
      error(cause.name==='AbortError' || cause instanceof SyntaxError || cause instanceof TypeError?'The result could not be confirmed. Review the latest records before trying again.':cause.message);
    } finally {clearTimeout(timeout);}
  });
  retry.addEventListener('click',loadPreview);
})();
