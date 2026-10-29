'use strict';
(() => {
  const source=document.getElementById('studentEvaluationPromptConfig'),element=document.getElementById('studentEvaluationPrompt');
  if(!source||!element)return;
  const config=JSON.parse(source.textContent),modal=bootstrap.Modal.getOrCreateInstance(element),start=document.getElementById('studentEvaluationPromptStart');
  let state=config.initial,acknowledged='',checking=false,timer=null,closing=false;
  const isEvaluation=()=>location.pathname.endsWith('/class-program.php');
  const selectedCollege=()=>new URL(location.href).searchParams.get('evaluation_college_id');
  const ticketKey='student-evaluation-start-'+config.student_id;
  // One navigation from Start reaches the faculty list without showing the same dialog twice.
  // No completion flag is stored: future page loads recheck actual submitted records.
  try{
    const ticket=JSON.parse(sessionStorage.getItem(ticketKey)||'null');sessionStorage.removeItem(ticketKey);
    if(isEvaluation()&&ticket&&ticket.key===state?.key&&String(ticket.college_id)===selectedCollege()&&Date.now()-ticket.at<15000)acknowledged=state.key;
  }catch(_){}
  function render(){
    if(!state?.required){acknowledged='';if(element.classList.contains('show')){closing=true;modal.hide();}return;}
    document.getElementById('studentEvaluationPromptTitle').textContent=state.drafts||state.missing<state.remaining?'Complete your evaluation':'Your evaluation is ready';
    document.getElementById('studentEvaluationPromptPeriod').textContent=state.academic_period;
    document.getElementById('studentEvaluationPromptProgress').textContent=`${state.remaining} faculty evaluation(s) awaiting final submission · ${state.drafts} draft(s) ready · ${state.missing} still to complete`;
    const colleges=document.getElementById('studentEvaluationPromptColleges');colleges.replaceChildren();
    for(const college of state.colleges){const line=document.createElement('p');line.textContent=`${college.college_name}: ${college.remaining} remaining`;colleges.append(line);}
    const target=state.colleges.find(college=>String(college.college_id)===selectedCollege())||state.colleges[0];
    start.href=target.url;start.dataset.collegeId=target.college_id;start.textContent=state.drafts?'Continue evaluation':'Start evaluation';
    if(acknowledged!==state.key&&!document.hidden&&!document.querySelector('.modal.show,.modal.showing,.offcanvas.show,.swal2-container'))modal.show();
  }
  start.addEventListener('click',event=>{
    const url=new URL(start.href,location.href);
    if(isEvaluation()&&url.pathname===location.pathname&&url.searchParams.get('term_key')===new URL(location.href).searchParams.get('term_key')&&url.searchParams.get('evaluation_college_id')===selectedCollege()){
      event.preventDefault();acknowledged=state.key;closing=true;modal.hide();
      element.addEventListener('hidden.bs.modal',()=>{const overview=document.getElementById('facultyEvaluationOverview');overview?.scrollIntoView({block:'start',behavior:'smooth'});overview?.setAttribute('tabindex','-1');overview?.focus({preventScroll:true});},{once:true});
    }else{
      try{sessionStorage.setItem(ticketKey,JSON.stringify({key:state.key,college_id:start.dataset.collegeId,at:Date.now()}));}catch(_){}
    }
  });
  element.addEventListener('hide.bs.modal',event=>{if(state?.required&&!closing)event.preventDefault();});
  element.addEventListener('hidden.bs.modal',()=>{closing=false;});
  document.addEventListener('hidden.bs.modal',event=>{if(event.target!==element)render();});
  document.addEventListener('hidden.bs.offcanvas',render);
  async function check(){
    if(checking||document.hidden)return;checking=true;
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
    try{
      const response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'},body:new URLSearchParams({csrf_token:config.csrf_token})});
      if(!response.ok)return;const data=await response.json();if(data.status==='ok'){state=data.evaluation;render();}
    }catch(_){/* Keep the last confirmed state until the next successful check. */}
    finally{clearTimeout(timeout);checking=false;}
  }
  function schedule(){clearInterval(timer);timer=setInterval(check,45000);}
  document.addEventListener('visibilitychange',()=>{if(!document.hidden){check();render();}});
  window.addEventListener('pageshow',event=>{if(event.persisted){acknowledged='';check();}render();schedule();});
  window.addEventListener('pagehide',()=>clearInterval(timer));
  document.addEventListener('DOMContentLoaded',render,{once:true});schedule();
})();
