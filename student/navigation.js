'use strict';
(() => {
  const sidebar=document.getElementById('layout-menu'),bar=document.getElementById('student-bottom-nav'),more=document.getElementById('student-mobile-more'),toggle=document.getElementById('student-more-toggle');
  if(!sidebar||!bar||!more)return;
  const desktop=matchMedia('(min-width:1200px)');
  function sync(){
    sidebar.inert=!desktop.matches;
    bar.inert=desktop.matches||document.body.classList.contains('modal-open')||more.classList.contains('show');
    if(desktop.matches&&more.classList.contains('show'))bootstrap.Offcanvas.getOrCreateInstance(more).hide();
    if(!desktop.matches&&document.documentElement.classList.contains('layout-menu-expanded'))window.Helpers?.setCollapsed(true,true);
  }
  new MutationObserver(sync).observe(document.body,{attributes:true,attributeFilter:['class']});
  new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['class']});
  desktop.addEventListener('change',sync);
  more.addEventListener('show.bs.offcanvas',()=>{toggle.setAttribute('aria-expanded','true');bar.inert=true;});
  more.addEventListener('hidden.bs.offcanvas',()=>{toggle.setAttribute('aria-expanded','false');sync();if(!desktop.matches&&!document.body.classList.contains('modal-open'))toggle.focus();});
  sync();
})();
