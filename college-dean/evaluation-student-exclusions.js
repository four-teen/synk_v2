'use strict';
(() => {
  const modalElement=document.getElementById('studentExclusionModal');
  if (!modalElement || !window.bootstrap) return;
  const modal=bootstrap.Modal.getOrCreateInstance(modalElement),form=document.getElementById('studentExclusionForm');
  const error=document.getElementById('studentExclusionFormError'),reload=document.getElementById('studentExclusionReload'),save=document.getElementById('studentExclusionSave');
  let busy=false,uncertain=false,activeId='',returnScroll=null;
  const main=()=>document.getElementById('evaluationStudentExclusions');
  const inputs=()=>({search:document.getElementById('studentExclusionSearch'),filter:document.getElementById('studentExclusionFilter')});
  function updateUrl(studentId) {
    const url=new URL(location.href),fields=inputs();
    if(fields.search?.value.trim())url.searchParams.set('search',fields.search.value.trim());else url.searchParams.delete('search');
    if(fields.filter?.value!=='all')url.searchParams.set('rule_filter',fields.filter.value);else url.searchParams.delete('rule_filter');
    if(studentId)url.searchParams.set('student_id',studentId);else url.searchParams.delete('student_id');
    url.searchParams.delete('saved');
    if(['#studentExclusionForm','#studentExclusionModal'].includes(url.hash))url.hash='';
    history.replaceState(null,'',url);
  }
  function filterStudents() {
    const fields=inputs();if(!fields.search)return;
    const words=fields.search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean),rows=[...main().querySelectorAll('[data-student-row]')];
    let visible=0;
    rows.forEach(row=>{
      const matches=words.every(word=>row.dataset.studentLabel.toLocaleLowerCase().includes(word));
      const state=fields.filter.value,ruleMatch=state==='all'||row.dataset.rule===state;
      row.hidden=!(matches&&ruleMatch);if(!row.hidden)visible++;
    });
    document.getElementById('studentExclusionCount').textContent=`${visible} of ${rows.length} students`;
    document.getElementById('studentExclusionNoMatches').hidden=visible>0;
  }
  function openStudent(id,trigger) {
    if(busy)return;
    const data=JSON.parse(document.getElementById('studentExclusionData').textContent),student=data[id];if(!student)return;
    activeId=String(id);uncertain=false;
    form.elements.student_id.value=activeId;form.elements.version.value=student.version;
    form.elements.excluded.value=String(student.excluded);form.elements.reason.value=student.reason;
    document.getElementById('studentExclusionStudent').textContent=student.label;
    error.textContent='';error.hidden=true;reload.hidden=true;save.disabled=false;
    updateUrl(activeId);form.action=location.href;
    modal.show(trigger);
  }
  document.addEventListener('input',event=>{if(event.target.id==='studentExclusionSearch'){filterStudents();updateUrl(modalElement.classList.contains('show')?activeId:null);}});
  document.addEventListener('change',event=>{if(event.target.id==='studentExclusionFilter'){filterStudents();updateUrl(null);}});
  document.addEventListener('click',event=>{
    const manage=event.target.closest('[data-manage-student]');
    if(manage && main()?.contains(manage)){event.preventDefault();openStudent(manage.dataset.manageStudent,manage);}
    if(event.target.closest('#studentExclusionClear')){const fields=inputs();fields.search.value='';fields.filter.value='all';filterStudents();updateUrl(null);fields.search.focus();}
  });
  document.addEventListener('submit',event=>{if(event.target.id==='studentExclusionSearchForm'){event.preventDefault();filterStudents();updateUrl(null);}});
  modalElement.addEventListener('hide.bs.modal',event=>{if(busy)event.preventDefault();});
  modalElement.addEventListener('hidden.bs.modal',()=>{
    updateUrl(null);
    const target=main()?.querySelector(`[data-manage-student="${activeId}"]`);
    (target && !target.closest('[hidden]')?target:inputs().search)?.focus({preventScroll:true});
    if(returnScroll!==null){window.scrollTo({top:returnScroll,behavior:'instant'});returnScroll=null;}
  });
  reload.addEventListener('click',()=>{updateUrl(activeId);location.assign(location.href);});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||uncertain||!form.reportValidity())return;
    const payload=new FormData(form),fields=inputs(),search=fields.search.value,filter=fields.filter.value,scroll=window.scrollY;
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
    busy=true;save.disabled=true;save.textContent='Saving...';form.setAttribute('aria-busy','true');error.hidden=true;reload.hidden=true;
    modalElement.querySelectorAll('[data-bs-dismiss],select,textarea').forEach(control=>control.disabled=true);
    try {
      updateUrl(activeId);form.action=location.href;
      const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const documentResult=new DOMParser().parseFromString(await response.text(),'text/html');
      const updated=documentResult.getElementById('evaluationStudentExclusions');
      if(!response.ok || !response.redirected || !updated?.querySelector('[data-student-save-success]')) {
        const message=documentResult.querySelector('[data-student-form-error]:not([hidden]),[data-student-page-error]')?.textContent.trim();
        if(!message)uncertain=true;
        throw Error(message||'The save status could not be confirmed. Reload the latest rule before trying again.');
      }
      main().replaceWith(document.importNode(updated,true));
      inputs().search.value=search;inputs().filter.value=filter;filterStudents();
      returnScroll=scroll;busy=false;modal.hide();
    } catch(exception) {
      if(exception.name==='AbortError'||exception instanceof TypeError)uncertain=true;
      error.textContent=uncertain?'The save status could not be confirmed. Reload the latest rule before trying again.':exception.message;
      error.hidden=false;reload.hidden=false;error.scrollIntoView({block:'nearest'});
    } finally {
      clearTimeout(timeout);busy=false;save.textContent='Save Student Rule';save.disabled=uncertain;form.removeAttribute('aria-busy');
      modalElement.querySelectorAll('[data-bs-dismiss],select,textarea').forEach(control=>control.disabled=false);
    }
  });
  filterStudents();
  if(modalElement.dataset.open==='1') {
    activeId=form.elements.student_id.value;
    // Server-rendered validation errors and posted reasons stay intact on a normal POST.
    modal.show(main()?.querySelector(`[data-manage-student="${activeId}"]`));
  }
})();
