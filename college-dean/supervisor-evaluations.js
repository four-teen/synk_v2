'use strict';
(() => {
  const search=document.querySelector('[data-sef-search]');
  search?.addEventListener('input',()=>{
    const words=search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);let visible=0;
    document.querySelectorAll('[data-sef-row]').forEach(row=>{row.hidden=!words.every(word=>row.dataset.name.toLocaleLowerCase().includes(word));if(!row.hidden)visible++;});
    const empty=document.querySelector('[data-sef-empty]');if(empty)empty.hidden=visible>0;
  });
  const source=document.getElementById('sefDraftData'),element=document.getElementById('sefEvaluationModal'),form=document.getElementById('sefEvaluationForm');
  if(source&&element&&form&&window.bootstrap){
    const data=JSON.parse(source.textContent),modal=bootstrap.Modal.getOrCreateInstance(element);let trigger;
    function open(id,button){
      const row=data[id];if(!row)return;trigger=button;
      form.elements.faculty_id.value=id;form.elements.assignment_revision.value=row.assignment_revision;form.elements.version.value=row.version;
      document.getElementById('sefModalFaculty').textContent=row.name;
      document.getElementById('sefAcademicRank').textContent=row.academic_rank||'Not recorded';
      document.getElementById('sefSubjectContext').textContent=row.subjects||'No class-list subjects recorded';
      document.getElementById('sefProgramYear').textContent=row.program_years||'Not recorded';
      form.querySelectorAll('select[name^="ratings"]').forEach(select=>{const key=select.name.slice(8,-1);select.value=String(row.ratings[key]??'');});
      form.elements.comment_text.value=row.comment;modal.show(button);
    }
    document.querySelectorAll('[data-sef-evaluate]').forEach(button=>button.addEventListener('click',()=>open(button.dataset.sefEvaluate,button)));
    element.addEventListener('hidden.bs.modal',()=>trigger?.focus({preventScroll:true}));
    if(Number(element.dataset.selected)>0)open(element.dataset.selected,document.querySelector(`[data-sef-evaluate="${element.dataset.selected}"]`));
  }
  document.querySelectorAll('[data-sef-once]').forEach(target=>{
    target.addEventListener('submit',event=>{
      if(target.dataset.submitting==='1'){event.preventDefault();return;}
      if(!target.reportValidity()){event.preventDefault();return;}
      target.dataset.submitting='1';target.setAttribute('aria-busy','true');
      // Disable submit/dismiss controls only; answer and scope inputs must be posted.
      target.querySelectorAll('button[type="submit"],button:not([type]),[data-bs-dismiss]').forEach(button=>button.disabled=true);
    });
    target.closest('.modal')?.addEventListener('hide.bs.modal',event=>{if(target.dataset.submitting==='1')event.preventDefault();});
  });
  window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
  document.getElementById('sefPrintReport')?.addEventListener('click',()=>window.print());
})();
