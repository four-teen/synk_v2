'use strict';
(() => {
  const source=document.getElementById('studentEvaluationPromptConfig'),element=document.getElementById('studentEvaluationPrompt');
  if(!source||!element)return;
  const config=JSON.parse(source.textContent),modal=bootstrap.Modal.getOrCreateInstance(element),start=document.getElementById('studentEvaluationPromptStart');
  let state=config.initial,acknowledged='',checking=false,timer=null,closing=false,pendingTarget='';
  const isEvaluation=()=>location.pathname.endsWith('/class-program.php');
  const selectedCollege=()=>new URL(location.href).searchParams.get('evaluation_college_id');
  const ticketKey='student-evaluation-start-'+config.student_id;
  // One navigation from Start reaches the faculty list without showing the same dialog twice.
  // No completion flag is stored: future page loads recheck actual submitted records.
  try{
    const ticket=JSON.parse(sessionStorage.getItem(ticketKey)||'null');sessionStorage.removeItem(ticketKey);
    if(isEvaluation()&&ticket&&ticket.key===state?.key&&String(ticket.college_id)===selectedCollege()&&Date.now()-ticket.at<15000){acknowledged=state.key;pendingTarget=ticket.target||'';}
  }catch(_){}
  const actionUrl=college=>college.action_url||college.url;
  function revealTarget(hash){
    let target=null;
    if(hash==='#finalEvaluationReviewModal'&&document.querySelector('#finalFacultyEvaluationForm button[type="submit"]:not([disabled])'))target=document.getElementById('finalEvaluationReviewModal');
    else if(/^#facultyEvaluationModal_faculty-[1-9][0-9]*$/.test(hash)){
      const candidate=document.getElementById(hash.slice(1));
      if(candidate?.querySelector('button[type="submit"]:not([disabled])'))target=candidate;
    }
    if(target){bootstrap.Modal.getOrCreateInstance(target).show();return;}
    const overview=document.getElementById('facultyEvaluationOverview');
    overview?.scrollIntoView({block:'start',behavior:'smooth'});overview?.setAttribute('tabindex','-1');overview?.focus({preventScroll:true});
  }
  function continueTo(event,college){
    const url=new URL(actionUrl(college),location.href);
    if(isEvaluation()&&url.pathname===location.pathname&&url.searchParams.get('term_key')===new URL(location.href).searchParams.get('term_key')&&url.searchParams.get('evaluation_college_id')===selectedCollege()){
      event.preventDefault();acknowledged=state.key;closing=true;
      element.addEventListener('hidden.bs.modal',()=>revealTarget(url.hash),{once:true});
      modal.hide();
    }else{
      try{sessionStorage.setItem(ticketKey,JSON.stringify({key:state.key,college_id:college.college_id,target:url.hash,at:Date.now()}));}catch(_){}
    }
  }
  function render(){
    if(!state?.required){acknowledged='';if(element.classList.contains('show')){closing=true;modal.hide();}return;}
    const target=state.colleges.find(college=>String(college.college_id)===selectedCollege())||state.colleges.find(college=>college.ready_to_submit)||state.colleges[0];
    const ready=target.ready_to_submit===true;
    document.getElementById('studentEvaluationPromptTitle').textContent=ready?'Your evaluations are ready to submit':'Your evaluation is incomplete';
    document.getElementById('studentEvaluationPromptDescription').textContent=ready
      ?`Your answers for ${target.college_name} are saved. Review them and submit to complete your evaluation.`
      :`Finish the items below for ${target.college_name}. Your saved answers are kept.`;
    document.getElementById('studentEvaluationPromptPeriod').textContent=state.academic_period;
    document.getElementById('studentEvaluationPromptProgress').textContent=ready
      ?`${target.faculty_count} of ${target.faculty_count} faculty evaluations answered · Final submission pending`
      :`${target.missing} faculty evaluation(s) need attention · ${(target.submitted||0)+(target.drafts||0)} of ${target.faculty_count} answered`;
    document.getElementById('studentEvaluationPromptNote').textContent=ready
      ?'Select Submit Evaluations to open the final review. Your answers will be locked only after you confirm submission.'
      :'Answer all 15 statements and add a comment for each faculty. Saving answers does not submit your evaluation.';
    const missing=document.getElementById('studentEvaluationPromptMissing');missing.replaceChildren();
    for(const person of target.pending_faculty||[]){
      const item=document.createElement('li'),name=document.createElement('strong'),details=document.createElement('p');
      name.textContent=person.faculty_name;details.textContent=person.requirements.join(' ');item.append(name,details);missing.append(item);
    }
    missing.hidden=ready;
    const colleges=document.getElementById('studentEvaluationPromptColleges');colleges.replaceChildren();
    colleges.hidden=state.colleges.length<2;
    for(const college of state.colleges){
      const line=document.createElement('div'),name=document.createElement('p'),link=document.createElement('a');
      name.textContent=`${college.college_name} · ${college.ready_to_submit?'Ready to submit':college.missing+' faculty evaluation(s) need attention'}`;
      link.href=actionUrl(college);link.className='btn btn-sm btn-outline-primary';link.textContent=college.ready_to_submit?'Submit Evaluations':'Continue Evaluation';
      link.addEventListener('click',event=>continueTo(event,college));line.append(name,link);colleges.append(line);
    }
    start.href=actionUrl(target);start.dataset.collegeId=target.college_id;start.textContent=ready?'Submit Evaluations':'Continue Evaluation';
    if(pendingTarget&&!document.hidden&&!document.querySelector('.modal.show,.modal.showing,.offcanvas.show,.swal2-container')){
      const hash=pendingTarget;pendingTarget='';revealTarget(hash);return;
    }
    if(acknowledged!==state.key&&!document.hidden&&!document.querySelector('.modal.show,.modal.showing,.offcanvas.show,.swal2-container'))modal.show();
  }
  start.addEventListener('click',event=>{
    const target=state.colleges.find(college=>String(college.college_id)===String(start.dataset.collegeId));
    if(target)continueTo(event,target);
  });
  element.addEventListener('hide.bs.modal',event=>{if(state?.required&&!closing)event.preventDefault();});
  element.addEventListener('hidden.bs.modal',()=>{closing=false;});
  document.addEventListener('hidden.bs.modal',event=>{
    if(event.target===element)return;
    if(event.target.id==='finalEvaluationReviewModal'||event.target.classList.contains('faculty-eval-modal'))acknowledged='';
    render();
  });
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
