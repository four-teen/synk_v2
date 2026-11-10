(function () {
  'use strict';
  const $=id=>document.getElementById(id),root=$('studentAssessments');if(!root)return;
  const config=JSON.parse($('studentAssessmentConfig').textContent),feedback=window.synkLmsFeedback,{escape,formatQuestion}=window.synkLmsContent;
  const state={rows:[],period:'midterm',subject:null,data:null,busy:false,dirty:false,generation:0,clock:0,expiredCheck:false};
  const time=value=>value?Date.parse(value.replace(' ','T')+'+08:00'):null;
  const date=value=>value?new Date(time(value)).toLocaleString('en-PH',{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'short'}):'No deadline';
  const now=()=>state.clock+performance.now();
  async function api(action,payload={}){
    let response;try{response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,payload,csrf_token:config.csrf_token})});}catch{throw Error('The connection was interrupted. Keep this page open and try saving again.');}
    let data;try{data=await response.json();}catch{throw Error('The server returned an invalid response. Your local answers have been kept.');}
    if(!response.ok||!data.ok)throw Error(data.message||'The assessment request could not be completed.');
    if(data.server_time)state.clock=time(data.server_time)-performance.now();return data;
  }
  async function task(callback){if(state.busy)return;state.busy=true;root.querySelectorAll('button').forEach(button=>{button.dataset.wasDisabled=String(button.disabled);button.disabled=true;});try{await callback();}catch(error){feedback.error(error.message,'Assessment workspace');}finally{state.busy=false;root.querySelectorAll('[data-was-disabled]').forEach(button=>{button.disabled=button.dataset.wasDisabled==='true';delete button.dataset.wasDisabled;});updateActions();}}
  function renderList(){
    root.querySelectorAll('[data-student-period]').forEach(button=>{const active=button.dataset.studentPeriod===state.period;button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',String(active));});
    const rows=state.rows.filter(row=>row.subject_key===state.subject?.subject_key&&row.grading_period===state.period);
    $('studentAssessmentList').innerHTML=rows.length?rows.map(row=>{
      const submitted=row.attempt_status==='submitted',expired=row.attempt_status==='expired',result=submitted||expired?row.result:null;
      const label=row.attempt_status==='submitted'?'Submitted':row.attempt_status==='expired'?'Time ended':row.status==='closed'?'Closed':row.opens_at&&time(row.opens_at)>now()?'Not open yet':row.due_at&&time(row.due_at)<=now()?'Deadline passed':row.attempt_status==='in_progress'?'In progress':'Available';
      return `<article class="card assessment-list-card${submitted?' is-submitted':expired?' is-expired':''}" data-assessment-card="${row.assessment_id}"><span class="badge ${submitted?'bg-label-success':expired?'bg-label-warning':'bg-label-primary'}">${submitted?'<i class="bx bx-check-circle" aria-hidden="true"></i> ':expired?'<i class="bx bx-time-five" aria-hidden="true"></i> ':''}${escape(label)}</span><h2 class="student-assessment-list-title">${escape(row.title)}</h2><div class="assessment-preview-meta"><span>${escape(row.assessment_type)}</span><span>${escape(row.total_points)} points</span><span>${row.due_at?'Due '+escape(date(row.due_at)):'No deadline'}</span></div><div class="student-assessment-card-footer">${result?`<section class="student-list-result" aria-label="Assessment result"><h3>Results</h3>${resultTotals(result,true)}</section>`:''}<button class="btn btn-outline-primary" type="button" data-open="${row.assessment_id}">${result?'View results':'Open assessment'}</button></div></article>`;
    }).join(''):'<div class="card assessment-empty">No published assessments for this subject in this grading period yet.</div>';
  }
  function renderSubjectCounts(){
    root.querySelectorAll('[data-subject-key]').forEach(card=>{
      const rows=state.rows.filter(row=>row.subject_key===card.dataset.subjectKey);
      const active=rows.filter(row=>row.status==='published'&&!['submitted','expired'].includes(row.attempt_status)&&(!row.opens_at||time(row.opens_at)<=now())&&(!row.due_at||time(row.due_at)>now())).length;
      const completed=rows.filter(row=>row.attempt_status==='submitted').length;
      const summary=card.querySelector('[data-subject-count]');
      summary.innerHTML=`<div class="student-class-summary-row needs-attention"><span><i class="bx bx-bell" aria-hidden="true"></i>Needs attention</span><span class="badge student-count-badge" data-subject-active>${active}</span></div><div class="student-class-summary-row completed-assessments"><span><i class="bx bx-check-circle" aria-hidden="true"></i>Completed assessments</span><span class="badge student-count-badge" data-subject-completed>${completed}</span></div><div class="student-class-summary-row total-assessments"><span><i class="bx bx-list-ul" aria-hidden="true"></i>Total assessments</span><span class="badge student-count-badge" data-subject-total>${rows.length}</span></div>`;
      card.classList.toggle('has-pending',active>0);
    });
  }
  function openSubject(key){
    const subject=config.subjects.find(row=>row.subject_key===key);if(!subject)return;
    state.subject=subject;
    $('studentSubjectLibrary').hidden=true;$('studentAssessmentLibrary').hidden=false;
    $('studentClassTitle').textContent=`${subject.subject_code}: ${subject.descriptive_title}`;
    $('studentClassFaculty').textContent=subject.faculty_name||'Instructor to be posted';
    renderList();$('studentClassTitle').focus();
  }
  async function refresh(){const data=await api('list');state.rows=data.assessments;renderSubjectCounts();renderList();}
  function resultTotals(result,compact=false){
    return `<div class="student-result-totals${result.pending_review_count?' has-review':''}"><div class="student-result-correct"><strong>${escape(result.correct_count)}</strong><span>${compact?'Correct':'Total correct'}</span></div><div class="student-result-mistakes"><strong>${escape(result.mistake_count)}</strong><span>${compact?'Mistakes':'Total mistakes'}</span></div>${result.pending_review_count?`<div class="student-result-pending"><strong>${escape(result.pending_review_count)}</strong><span>${compact?'Awaiting review':'Awaiting professor review'}</span></div>`:''}</div>`;
  }
  function renderResult(data){
    const panel=$('studentAssessmentResult'),result=data.result;
    panel.hidden=!result;panel.innerHTML='';if(!result)return;
    panel.innerHTML=`<h3>Assessment result</h3>${resultTotals(result)}<details class="student-result-explanation"><summary>How results are checked</summary><p>${result.checked_count?`${escape(result.checked_count)} question${result.checked_count===1?'':'s'} checked automatically. Unanswered checked questions count as mistakes. Short answers ignore case and extra whitespace.`:'This written response needs professor review.'}${result.pending_review_count?' Written responses awaiting review are excluded from correct and mistake totals.':''}</p></details>`;
  }
  function updateActions(){
    if(!state.data)return;const {assessment:a,attempt:t}=state.data,editable=t?.status==='in_progress'&&a.status==='published';
    $('studentAssessmentStart').hidden=!!t||a.status!=='published';
    $('studentAssessmentStart').disabled=state.busy||(a.opens_at&&time(a.opens_at)>now())||(a.due_at&&time(a.due_at)<=now());
    $('studentAssessmentSave').hidden=!editable;$('studentAssessmentSubmit').hidden=!editable;
    $('studentAssessmentSave').disabled=state.busy||!state.dirty;$('studentAssessmentSubmit').disabled=state.busy;
    $('studentAssessmentQuestions').querySelectorAll('input,textarea').forEach(input=>{input.disabled=!editable;});
    tick();
  }
  function render(data){
    state.data=data;state.dirty=false;state.expiredCheck=false;
    const {assessment:a,attempt:t}=data,answers=t?.answers||{};
    if(t){const row=state.rows.find(item=>item.assessment_id===a.assessment_id);if(row){row.attempt_status=t.status;row.result=data.result;}renderSubjectCounts();}
    $('studentSubjectLibrary').hidden=true;$('studentAssessmentLibrary').hidden=true;$('studentAssessmentEditor').hidden=false;
    $('studentAssessmentTitle').textContent=a.title;
    $('studentAssessmentMeta').textContent=`${a.subject_label} / ${a.grading_period==='midterm'?'Midterm':'Final'} / ${a.total_points} points${a.time_limit_minutes?' / '+a.time_limit_minutes+' minutes':''}${a.due_at?' / Due '+date(a.due_at):''}`;
    $('studentAssessmentInstructions').textContent=a.instructions||'Read each question carefully.';
    $('studentAssessmentStatus').textContent=!t?(a.status==='closed'?'This assessment is closed.':a.opens_at&&time(a.opens_at)>now()?'Opens '+date(a.opens_at)+'.':a.due_at&&time(a.due_at)<=now()?'The deadline has passed.':'One attempt is allowed. Your timer, if set, begins when you choose Start assessment.'):t.status==='submitted'?'Submitted. Your responses are locked.':t.status==='expired'?'Time ended. Your last saved responses are locked.':'Your responses save automatically every 15 seconds while you are editing. Use Save answers before leaving.';
    renderResult(data);
    $('studentAssessmentForm').hidden=!t;
    $('studentAssessmentQuestions').innerHTML=!t?'':a.assessment_type==='assignment'?`<label class="form-label mt-4" for="assignmentResponse">Your response</label><textarea id="assignmentResponse" class="form-control" rows="12" maxlength="20000" data-assignment>${escape(answers.assignment_response||'')}</textarea>`:a.questions.map((q,index)=>{
      const value=answers[q.question_id];let inputs='';
      if(q.question_type==='multiple_choice'||q.question_type==='true_false'){
        const options=q.question_type==='true_false'?['True','False']:q.options;
        inputs=options.map((option,i)=>{const optionValue=q.question_type==='true_false'?i===0:i;return `<label class="student-assessment-option"><input type="radio" name="answer${q.question_id}" data-answer="${q.question_id}" data-kind="${q.question_type}" value="${optionValue}"${value===optionValue?' checked':''}><span>${escape(option)}</span></label>`;}).join('');
      }else inputs=`<label for="answer${q.question_id}" class="visually-hidden">Your answer to question ${index+1}</label><textarea id="answer${q.question_id}" class="form-control" data-answer="${q.question_id}" data-kind="${q.question_type}" rows="${q.question_type==='essay'?5:2}" maxlength="${q.question_type==='short_answer'?500:10000}">${escape(value||'')}</textarea>`;
      return `<section class="assessment-preview-question"><p class="assessment-help">Question ${index+1} / ${q.points} points</p><div class="assessment-formatted-prompt mb-3">${formatQuestion(q.prompt)}</div>${inputs}</section>`;
    }).join('');updateActions();
  }
  function readAnswers(){
    const result={};if(state.data.assessment.assessment_type==='assignment')return {assignment_response:$('assignmentResponse').value};
    $('studentAssessmentQuestions').querySelectorAll('[data-answer]').forEach(input=>{
      if(input.type==='radio'){if(input.checked)result[input.dataset.answer]=input.dataset.kind==='true_false'?input.value==='true':Number(input.value);}
      else result[input.dataset.answer]=input.value;
    });return result;
  }
  async function save(submit=false){
    if(!state.data?.attempt||state.data.attempt.status!=='in_progress'||state.busy)return;
    if(submit&&!await feedback.confirm({title:'Submit your answers?',text:'Your responses will be locked after submission. Check your work before continuing.',confirmText:'Submit answers',cancelText:'Keep answering'}))return;
    const generation=state.generation,payload={assessment_id:state.data.assessment.assessment_id,version:state.data.attempt.version,answers:readAnswers()};
    await task(async()=>{
      const data=await api(submit?'submit':'save',payload);
      if(data.attempt.status!=='in_progress'){render(data);feedback.info(data.attempt.status==='submitted'?'Your answers were submitted successfully.':'Time ended. Only responses saved before the deadline were accepted.',data.attempt.status==='submitted'?'Submitted':'Time ended');}
      else {state.data=data;state.dirty=generation!==state.generation;$('studentAssessmentStatus').textContent=state.dirty?'New changes are waiting to be saved.':'Answers saved.';}
    });
  }
  function tick(){
    if(!state.data)return;const attempt=state.data.attempt;
    if(!attempt){const a=state.data.assessment;$('studentAssessmentStart').disabled=state.busy||a.status!=='published'||(a.opens_at&&time(a.opens_at)>now())||(a.due_at&&time(a.due_at)<=now());}
    if(!attempt?.deadline_at||attempt.status!=='in_progress'){$('studentAssessmentTimer').textContent='';return;}
    const seconds=Math.max(0,Math.ceil((time(attempt.deadline_at)-now())/1000));
    $('studentAssessmentTimer').textContent=`Time remaining: ${Math.floor(seconds/60)}:${String(seconds%60).padStart(2,'0')}`;
    if(!seconds&&!state.busy&&!state.expiredCheck){state.expiredCheck=true;task(async()=>{try{render(await api('detail',{assessment_id:state.data.assessment.assessment_id}));feedback.info('The deadline has passed. Your last saved responses are retained.','Time ended');}catch(error){state.expiredCheck=false;throw error;}});}
  }
  root.addEventListener('click',event=>{
    if(state.busy)return;
    const subjectButton=event.target.closest('[data-open-subject]');if(subjectButton)openSubject(subjectButton.dataset.openSubject);
    const period=event.target.closest('[data-student-period]');if(period){state.period=period.dataset.studentPeriod;renderList();}
    const button=event.target.closest('[data-open]');if(button)task(async()=>render(await api('detail',{assessment_id:Number(button.dataset.open)})));
  });
  $('studentSubjectBack').addEventListener('click',()=>{
    if(state.busy)return;state.subject=null;$('studentAssessmentLibrary').hidden=true;$('studentSubjectLibrary').hidden=false;
    root.querySelector('[data-open-subject]')?.focus();
  });
  $('studentAssessmentStart').addEventListener('click',async()=>{
    if(!await feedback.confirm({title:'Start assessment?',text:'One attempt is allowed. Any time limit begins now and continues if you close this page.',confirmText:'Start assessment',cancelText:'Not yet'}))return;
    task(async()=>render(await api('start',{assessment_id:state.data.assessment.assessment_id})));
  });
  $('studentAssessmentForm').addEventListener('input',()=>{state.dirty=true;state.generation++;$('studentAssessmentStatus').textContent='Unsaved responses';updateActions();});
  $('studentAssessmentSave').addEventListener('click',()=>save());
  $('studentAssessmentForm').addEventListener('submit',event=>{event.preventDefault();save(true);});
  $('studentAssessmentBack').addEventListener('click',async()=>{
    if(state.busy)return;if(state.dirty&&!await feedback.confirm({title:'Leave unsaved answers?',text:'Your latest changes have not been saved. Saved answers will remain available.',confirmText:'Discard unsaved changes',cancelText:'Keep answering'}))return;
    state.dirty=false;state.data=null;$('studentAssessmentEditor').hidden=true;$('studentAssessmentLibrary').hidden=false;task(refresh);
  });
  $('studentAssessmentRefresh').addEventListener('click',()=>{if(!state.data)task(refresh);else feedback.info('Use Save answers to record your current work. Return to All assessments to refresh the list.','Assessment in progress');});
  feedback.guardNavigation(()=>state.dirty,()=>{state.dirty=false;},'Your unsaved answers will be discarded. Save your answers before leaving.');
  const search=$('subject-search');
  if(search){
    const cards=[...root.querySelectorAll('[data-subject-card]')],fold=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const update=()=>{const words=fold(search.value.trim()).split(/\s+/).filter(Boolean);let count=0;cards.forEach(card=>{card.hidden=!words.every(word=>fold(card.dataset.search).includes(word));if(!card.hidden)count++;});$('subject-search-empty').hidden=count>0;$('subject-search-result').textContent=`Showing ${count} of ${cards.length} subjects`;};
    search.addEventListener('input',update);$('subject-search-clear').addEventListener('click',()=>{search.value='';update();search.focus();});
  }
  window.addEventListener('beforeunload',event=>{if(state.dirty){event.preventDefault();event.returnValue='';}});
  setInterval(()=>{if(state.dirty&&document.visibilityState==='visible')save();},15000);setInterval(tick,1000);
  task(refresh);
})();
