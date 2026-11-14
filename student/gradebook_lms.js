(function(){
  'use strict';
  document.addEventListener('click',event=>{
    const link=event.target.closest('[data-grade-details]');if(!link)return;
    const detail=document.getElementById(link.dataset.gradeDetails);if(!detail)return;
    event.preventDefault();detail.open=true;
    detail.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});
    detail.querySelector('summary').focus({preventScroll:true});
  });
  // Refresh the server-rendered, already filtered view so a release or withdrawal
  // is also reflected in student gradebooks that are already open.
  if(!location.pathname.endsWith('/student/gradebook_lms.php'))return;
  let refreshing=false;
  async function refresh(){
    if(refreshing||document.hidden)return;
    const current=document.querySelector('.student-gradebook-container');if(!current)return;
    refreshing=true;
    try{
      const response=await fetch(new URL('gradebook_lms.php',location.href),{credentials:'same-origin',cache:'no-store',headers:{Accept:'text/html'}});
      if(response.redirected){location.assign(response.url);return;}
      const next=new DOMParser().parseFromString(await response.text(),'text/html').querySelector('.student-gradebook-container');
      if(!next)throw Error('Gradebook unavailable');
      if(current.innerHTML===next.innerHTML)return;
      const expanded=[...current.querySelectorAll('.student-gradebook-subject[open]')].map(node=>({id:node.id,work:[...node.querySelectorAll('.student-gradebook-entries[open]')].map(entry=>entry.closest('[data-grade-period]').dataset.gradePeriod)}));
      const focused=document.activeElement.closest('.student-gradebook-subject')?.id,restoreFocus=current.contains(document.activeElement),scroll=window.scrollY,tableScroll=current.querySelector('.student-gradebook-table-scroll')?.scrollLeft||0;
      expanded.forEach(({id,work})=>{const subject=next.querySelector('#'+CSS.escape(id));if(!subject)return;subject.open=true;work.forEach(period=>{const entries=subject.querySelector(`[data-grade-period="${period}"] .student-gradebook-entries`);if(entries)entries.open=true;});});
      current.replaceWith(next);
      const table=next.querySelector('.student-gradebook-table-scroll');if(table)table.scrollLeft=tableScroll;
      if(restoreFocus&&focused)next.querySelector('#'+CSS.escape(focused)+' > summary')?.focus({preventScroll:true});
      window.scrollTo({top:scroll,behavior:'auto'});
    }catch{
      // Do not leave a stale released grade visible when its visibility cannot be checked.
      current.querySelectorAll('.student-gradebook-summary,.student-gradebook-breakdowns,.student-gradebook-guidance').forEach(node=>node.remove());
      if(!current.querySelector('[data-gradebook-refresh-error]')){
        const message=document.createElement('p');message.className='alert alert-info';message.dataset.gradebookRefreshError='';message.setAttribute('role','status');message.textContent='Your gradebook could not be refreshed. Check your connection or select Refresh to try again.';current.append(message);
      }
    }finally{refreshing=false;}
  }
  setInterval(refresh,30000);
  window.addEventListener('focus',refresh);
  window.addEventListener('pageshow',event=>{if(event.persisted)refresh();});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
