'use strict';
(()=>{
  const form=document.getElementById('management-report-form');if(!form)return;
  const print=document.getElementById('management-report-print'),stale=document.getElementById('management-report-stale');
  const changed=()=>{print.disabled=true;stale.hidden=!document.getElementById('management-report-preview');};
  form.addEventListener('input',changed);
  form.addEventListener('change',changed);
  for(const id of['report-type','report-year','report-semester','report-term'])document.getElementById(id).addEventListener('change',()=>{
    changed();const event=document.getElementById('report-event');if(event && id!=='report-type')event.value='';
    // Refresh event choices without generating a report until explicitly requested.
    form.requestSubmit();
  });
  print.addEventListener('click',()=>{if(!print.disabled)window.print();});
  form.addEventListener('submit',event=>{
    if(event.submitter?.id!=='management-report-generate')return;
    // Keep the clicked button enabled so its generate=1 value is submitted.
    event.submitter.setAttribute('aria-busy','true');event.submitter.textContent='Generating…';
  });
})();
