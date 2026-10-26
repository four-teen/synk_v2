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
  document.getElementById('management-current-term')?.addEventListener('click',event=>{
    document.getElementById('management-term').value=event.currentTarget.dataset.term;
  });
  document.getElementById('management-term-modal')?.addEventListener('show.bs.modal',()=>{
    document.getElementById('management-term').value=JSON.parse(document.getElementById('management-config').textContent).term;
  });
  sync();
})();
