'use strict';
(() => {
  const nav=document.getElementById('dean-mobile-nav'),more=document.getElementById('dean-mobile-more'),menu=document.getElementById('layout-menu'),toggle=document.getElementById('dean-mobile-more-toggle');
  if(!nav||!more||!menu)return;
  // Place fixed navigation outside any page container that may establish a transform.
  document.body.append(nav,more);
  const desktop=matchMedia('(min-width:1200px)');
  function sync(){
    const modalOpen=document.body.classList.contains('modal-open');
    menu.inert=!desktop.matches;nav.inert=desktop.matches||modalOpen;
    nav.setAttribute('aria-hidden',desktop.matches||modalOpen?'true':'false');
    if(!desktop.matches&&document.documentElement.classList.contains('layout-menu-expanded'))window.Helpers?.setCollapsed(true,true);
    if(desktop.matches&&more.classList.contains('show'))window.bootstrap?.Offcanvas.getOrCreateInstance(more).hide();
  }
  new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['class']});
  new MutationObserver(sync).observe(document.body,{attributes:true,attributeFilter:['class']});
  desktop.addEventListener('change',sync);
  more.addEventListener('show.bs.offcanvas',()=>toggle.setAttribute('aria-expanded','true'));
  more.addEventListener('hidden.bs.offcanvas',()=>{toggle.setAttribute('aria-expanded','false');if(!desktop.matches)toggle.focus({preventScroll:true});});
  function viewportSize(){if(!window.visualViewport||visualViewport.scale===1)document.documentElement.style.setProperty('--dean-mobile-viewport-height',`${Math.round(window.visualViewport?.height||innerHeight)}px`);}
  window.visualViewport?.addEventListener('resize',viewportSize);window.addEventListener('resize',viewportSize);viewportSize();sync();
})();
