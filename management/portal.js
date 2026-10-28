'use strict';
(() => {
  // Image failures must keep the reserved avatar size and avoid retry loops.
  document.querySelectorAll('.management-student-avatar').forEach(avatar=>{
    const fallback=()=>{if(avatar.getAttribute('src')!==avatar.dataset.fallback)avatar.src=avatar.dataset.fallback;};
    avatar.addEventListener('error',fallback);
    if(avatar.complete && !avatar.naturalWidth)fallback();
  });
  const menuElement=document.getElementById('layout-menu');
  const backdrop=document.getElementById('management-menu-backdrop');
  const mobileNav=document.getElementById('management-mobile-nav');
  const more=document.getElementById('management-mobile-more'),moreToggle=document.getElementById('management-more-toggle');
  const desktop=window.matchMedia('(min-width: 1200px)');
  if (!menuElement || !mobileNav) return;
  function sync() {
    menuElement.inert=!desktop.matches;
    mobileNav.inert=desktop.matches || document.body.classList.contains('modal-open');
    if(backdrop)backdrop.hidden=true;
    if(!desktop.matches && document.documentElement.classList.contains('layout-menu-expanded'))window.Helpers.setCollapsed(true,true);
    if(desktop.matches && more?.classList.contains('show'))bootstrap.Offcanvas.getOrCreateInstance(more).hide();
  }
  new MutationObserver(sync).observe(document.documentElement,{ attributes:true, attributeFilter:['class'] });
  new MutationObserver(sync).observe(document.body,{ attributes:true, attributeFilter:['class'] });
  desktop.addEventListener('change',sync);
  more?.addEventListener('show.bs.offcanvas',()=>moreToggle.setAttribute('aria-expanded','true'));
  more?.addEventListener('hidden.bs.offcanvas',()=>{moreToggle.setAttribute('aria-expanded','false');if(!desktop.matches)moreToggle.focus();});
  const viewport=window.visualViewport;
  function sizeViewport(){if(!viewport || viewport.scale===1)document.documentElement.style.setProperty('--management-viewport-height',`${Math.round(viewport?.height || innerHeight)}px`);}
  viewport?.addEventListener('resize',sizeViewport);window.addEventListener('resize',sizeViewport);sizeViewport();
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
