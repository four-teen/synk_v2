'use strict';
(() => {
  const modalElement=document.getElementById('subjectExclusionModal');
  if (!modalElement || !window.bootstrap) return;
  const modal=bootstrap.Modal.getOrCreateInstance(modalElement),form=document.getElementById('subjectExclusionForm');
  const error=document.getElementById('subjectExclusionFormError'),reload=document.getElementById('subjectExclusionReload'),save=document.getElementById('subjectExclusionSave');
  let busy=false,uncertain=false,activeId='',returnScroll=null;
  const main=()=>document.getElementById('evaluationSubjectExclusions');
  const inputs=()=>({search:document.getElementById('subjectExclusionSearch'),filter:document.getElementById('subjectExclusionFilter')});
  function updateUrl(subjectId) {
    const url=new URL(location.href),fields=inputs();
    if(fields.search?.value.trim())url.searchParams.set('search',fields.search.value.trim());else url.searchParams.delete('search');
    if(fields.filter?.value!=='all')url.searchParams.set('rule_filter',fields.filter.value);else url.searchParams.delete('rule_filter');
    if(subjectId)url.searchParams.set('subject_id',subjectId);else url.searchParams.delete('subject_id');
    url.searchParams.delete('saved');
    if(['#subjectExclusionForm','#subjectExclusionModal'].includes(url.hash))url.hash='';
    history.replaceState(null,'',url);
  }
  function filterSubjects() {
    const fields=inputs();if(!fields.search)return;
    const words=fields.search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean),rows=[...main().querySelectorAll('[data-subject-row]')];
    let visible=0;
    rows.forEach(row=>{
      const matches=words.every(word=>row.dataset.subjectLabel.toLocaleLowerCase().includes(word));
      const state=fields.filter.value,ruleMatch=state==='all'||(state==='review'?Number(row.dataset.reviewCount)>0:row.dataset.rule===state);
      row.hidden=!(matches&&ruleMatch);if(!row.hidden)visible++;
    });
    document.getElementById('subjectExclusionCount').textContent=`${visible} of ${rows.length} subjects`;
    document.getElementById('subjectExclusionNoMatches').hidden=visible>0;
  }
  function openSubject(id,trigger) {
    if(busy)return;
    const data=JSON.parse(document.getElementById('subjectExclusionData').textContent),subject=data[id];if(!subject)return;
    activeId=String(id);uncertain=false;
    form.elements.subject_id.value=activeId;form.elements.version.value=subject.version;
    form.elements.excluded.value=String(subject.excluded);form.elements.reason.value=subject.reason;
    document.getElementById('subjectExclusionSubject').textContent=subject.label;
    error.textContent='';error.hidden=true;reload.hidden=true;save.disabled=false;
    updateUrl(activeId);form.action=location.href;
    modal.show(trigger);
  }
  document.addEventListener('input',event=>{if(event.target.id==='subjectExclusionSearch'){filterSubjects();updateUrl(modalElement.classList.contains('show')?activeId:null);}});
  document.addEventListener('change',event=>{if(event.target.id==='subjectExclusionFilter'){filterSubjects();updateUrl(null);}});
  document.addEventListener('click',event=>{
    const manage=event.target.closest('[data-manage-subject]');
    if(manage && main()?.contains(manage)){event.preventDefault();openSubject(manage.dataset.manageSubject,manage);}
    if(event.target.closest('#subjectExclusionClear')){const fields=inputs();fields.search.value='';fields.filter.value='all';filterSubjects();updateUrl(null);fields.search.focus();}
  });
  document.addEventListener('submit',event=>{if(event.target.id==='subjectExclusionSearchForm'){event.preventDefault();filterSubjects();updateUrl(null);}});
  modalElement.addEventListener('hide.bs.modal',event=>{if(busy)event.preventDefault();});
  modalElement.addEventListener('hidden.bs.modal',()=>{
    updateUrl(null);
    const target=main()?.querySelector(`[data-manage-subject="${activeId}"]`);
    (target && !target.closest('[hidden]')?target:inputs().search)?.focus({preventScroll:true});
    if(returnScroll!==null){window.scrollTo({top:returnScroll,behavior:'instant'});returnScroll=null;}
  });
  reload.addEventListener('click',()=>{updateUrl(activeId);location.assign(location.href);});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||uncertain||!form.reportValidity())return;
    const payload=new FormData(form),fields=inputs(),search=fields.search.value,filter=fields.filter.value,scroll=window.scrollY;
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
    busy=true;save.disabled=true;save.textContent='Saving…';form.setAttribute('aria-busy','true');error.hidden=true;reload.hidden=true;
    modalElement.querySelectorAll('[data-bs-dismiss],select,textarea').forEach(control=>control.disabled=true);
    try {
      updateUrl(activeId);form.action=location.href;
      const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const documentResult=new DOMParser().parseFromString(await response.text(),'text/html');
      const updated=documentResult.getElementById('evaluationSubjectExclusions');
      if(!response.ok || !response.redirected || !updated?.querySelector('[data-subject-save-success]')) {
        const message=documentResult.querySelector('[data-subject-form-error]:not([hidden]),[data-subject-page-error]')?.textContent.trim();
        if(!message)uncertain=true;
        throw Error(message||'The save status could not be confirmed. Reload the latest rule before trying again.');
      }
      main().replaceWith(document.importNode(updated,true));
      inputs().search.value=search;inputs().filter.value=filter;filterSubjects();
      returnScroll=scroll;busy=false;modal.hide();
    } catch(exception) {
      if(exception.name==='AbortError'||exception instanceof TypeError)uncertain=true;
      error.textContent=uncertain?'The save status could not be confirmed. Reload the latest rule before trying again.':exception.message;
      error.hidden=false;reload.hidden=false;error.scrollIntoView({block:'nearest'});
    } finally {
      clearTimeout(timeout);busy=false;save.textContent='Save Subject Rule';save.disabled=uncertain;form.removeAttribute('aria-busy');
      modalElement.querySelectorAll('[data-bs-dismiss],select,textarea').forEach(control=>control.disabled=false);
    }
  });
  filterSubjects();
  if(modalElement.dataset.open==='1') {
    activeId=form.elements.subject_id.value;
    // Server-rendered validation errors and posted reasons stay intact on a normal POST.
    modal.show(main()?.querySelector(`[data-manage-subject="${activeId}"]`));
  }
})();
