'use strict';
(() => {
  const toggle=document.getElementById('management-menu-toggle');
  const menuElement=document.getElementById('layout-menu');
  const backdrop=document.getElementById('management-menu-backdrop');
  const desktop=window.matchMedia('(min-width: 1200px)');
  if (!toggle || !menuElement || !backdrop) return;
  function sync() {
    const open=!desktop.matches && document.documentElement.classList.contains('layout-menu-expanded');
    backdrop.hidden=!open; menuElement.inert=!desktop.matches && !open;
    toggle.setAttribute('aria-expanded',String(open)); toggle.setAttribute('aria-label',open ? 'Close navigation' : 'Open navigation');
  }
  new MutationObserver(sync).observe(document.documentElement,{ attributes:true, attributeFilter:['class'] });
  desktop.addEventListener('change',sync);
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape' && document.documentElement.classList.contains('layout-menu-expanded')) {
      window.Helpers.setCollapsed(true,true); sync(); toggle.focus();
    }
  });
  backdrop.addEventListener('click',()=>toggle.focus());
  document.querySelector('.management-menu-close')?.addEventListener('click',()=>toggle.focus());
  const year=document.getElementById('management-view-year'), semester=document.getElementById('management-view-semester');
  const examTerm=document.getElementById('management-view-exam-term'), key=document.getElementById('management-term');
  function setPeriod(term, exam) {
    const parts=term.split(':'); year.value=parts[0]; semester.value=parts[1]; examTerm.value=exam;
    key.value=term;
  }
  function syncPeriod() { key.value=`${year.value}:${semester.value}`; }
  year?.addEventListener('change',syncPeriod); semester?.addEventListener('change',syncPeriod);
  document.getElementById('management-current-term')?.addEventListener('click',event=>{
    setPeriod(event.currentTarget.dataset.term,event.currentTarget.dataset.examTerm);
  });
  document.getElementById('management-term-modal')?.addEventListener('show.bs.modal',()=>{
    const config=JSON.parse(document.getElementById('management-config').textContent);
    setPeriod(config.term,config.exam_term);
    document.getElementById('management-current-exam-term').value=config.current_exam_term;
    const error=document.getElementById('management-current-period-error');
    error.textContent=''; error.hidden=true;
  });
  sync();
})();
