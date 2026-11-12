(function () {
  'use strict';
  const $=id=>document.getElementById(id),root=$('gradebookWorkspace'),configNode=$('gradebookConfig');if(!root||!configNode)return;
  const config=JSON.parse(configNode.textContent),feedback=window.synkLmsFeedback;
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=value=>value===null||value===undefined?'—':Number(value).toLocaleString('en-PH',{maximumFractionDigits:2});
  const percent=value=>value===null||value===undefined?'—':Number(value).toFixed(2)+'%';
  const rating=value=>value===null||value===undefined?'—':Number(value).toFixed(2);
  const hasScore=value=>Number.isFinite(Number(value))&&Number(value)>0;
  const valueCell=(value,content,classes='')=>`<td class="${classes}${hasScore(value)?' gb-has-score':''}">${content}</td>`;
  const labels={midterm:'Midterm',final:'Final',summary:'Semester summary'},fold=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  const state={data:null,period:'midterm',busy:false,requestFailed:false,syncing:false,item:null,preview:null,editingItem:null,editPeriod:null,assignment:null,drafts:new Map(),originals:new Map(),changed:new Set(),loaded:false,returnToWork:false,afterItemClose:null,paste:null};
  const dirty=()=>state.changed.size>0;
  const book=(period=state.period)=>state.data.periods[period];
  const modal=bootstrap.Modal.getOrCreateInstance($('gbItemModal'));
  const workModal=bootstrap.Modal.getOrCreateInstance($('gbWorkModal'));
  let workModalReady=false;
  $('gbWorkModal').addEventListener('shown.bs.modal',()=>{workModalReady=true;});
  $('gbWorkModal').addEventListener('hidden.bs.modal',()=>{workModalReady=false;state.assignment=null;$('gbAssignmentForm').hidden=true;setTimeout(syncAvailableWork,0);});
  function afterWorkClosed(callback){const node=$('gbWorkModal');if(!node.classList.contains('show')){callback();return;}node.addEventListener('hidden.bs.modal',callback,{once:true});if(workModalReady)workModal.hide();else node.addEventListener('shown.bs.modal',()=>workModal.hide(),{once:true});}
  let itemModalReady=false;
  $('gbItemModal').addEventListener('shown.bs.modal',()=>{itemModalReady=true;});
  $('gbItemModal').addEventListener('hidden.bs.modal',()=>{itemModalReady=false;const next=state.afterItemClose;state.afterItemClose=null;if(next){state.returnToWork=false;next();}else if(state.returnToWork){state.returnToWork=false;openWork();}});
  function closeItemModal(){if(itemModalReady)modal.hide();else $('gbItemModal').addEventListener('shown.bs.modal',()=>modal.hide(),{once:true});}
  async function api(action,payload={}){
    let response;try{response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,payload,context:config.context,csrf_token:config.csrf_token})});}catch{throw Error('The connection was interrupted. Your unsaved entries are still on this page. Try saving again.');}
    let data;try{data=await response.json();}catch{throw Error('The server returned an invalid response. Your entries have been kept.');}
    if(!response.ok||!data.ok)throw Error(data.message||'The gradebook request could not be completed.');
    return data;
  }
  async function task(callback){
    if(state.busy)return;state.busy=true;state.requestFailed=false;root.setAttribute('aria-busy','true');
    root.querySelectorAll('button,input,select,textarea').forEach(node=>{node.dataset.gbDisabled=String(node.disabled);node.disabled=true;});updateSaveState();
    try{await callback();}catch(error){state.requestFailed=true;updateSaveState();await feedback.error(error.message,'Grade Book');}
    finally{state.busy=false;root.removeAttribute('aria-busy');root.querySelectorAll('[data-gb-disabled]').forEach(node=>{node.disabled=node.dataset.gbDisabled==='true';delete node.dataset.gbDisabled;});updateSaveState();}
  }
  function revision(period=state.period){const b=book(period);return {grading_period:period,version:b.version,scheme_version:b.scheme.version};}
  async function load(){await task(async()=>{
    state.data=await api('get');
    if(!state.loaded){
      const waiting=unlinkedAssessments(),midtermWork=state.data.periods.midterm.items.length||waiting.some(a=>a.grading_period==='midterm'&&a.submission_count>0),finalWork=state.data.periods.final.items.length||waiting.some(a=>a.grading_period==='final'&&a.submission_count>0);
      if(!midtermWork&&finalWork)state.period='final';state.loaded=true;
    }
    $('gbLoadState').hidden=true;$('gbRetry').hidden=true;$('gbContent').hidden=false;renderOverview();
  });if(!state.data){$('gbLoadState').textContent='Unable to load the gradebook. Try again.';$('gbRetry').hidden=false;}}
  function canSync(){return state.loaded&&!state.busy&&!dirty()&&!state.item&&!state.preview&&!state.assignment&&document.visibilityState==='visible'&&!document.querySelector('.modal.show,.modal-backdrop,.swal2-popup:not(.swal2-toast)');}
  async function syncAvailableWork(){
    if(state.syncing||!canSync())return;
    state.syncing=true;const previous=state.data;
    try{const data=await api('get');if(state.data===previous&&canSync()){state.data=data;renderOverview();updateSaveState();}}
    catch{/* Keep the last loaded grades; Refresh reports connection errors on request. */}
    finally{state.syncing=false;}
  }
  function unlinkedAssessments(){const linked=new Set(Object.values(state.data.periods).flatMap(b=>b.items.filter(i=>i.assessment_id).map(i=>i.assessment_id)));return state.data.assessments.filter(a=>(a.link_status??'unlinked')==='unlinked'&&!linked.has(a.assessment_id));}
  function person(student){return `<strong>${escape(student.full_name)}</strong><small>${escape(student.student_number)}</small>`;}
  function workRows(){
    const rows=[];
    for(const period of ['midterm','final'])for(const item of book(period).items)rows.push({...item,key:'item-'+item.item_id,period});
    for(const assessment of unlinkedAssessments())rows.push({key:'assessment-'+assessment.assessment_id,period:assessment.grading_period,assessment_id:assessment.assessment_id,item_id:0,entry_kind:'lms',title:assessment.title,max_points:assessment.total_points,component_id:null});
    return rows.filter(row=>state.period==='summary'||row.period===state.period);
  }
  function renderOverview(){
    root.querySelectorAll('[data-gb-period]').forEach(button=>{const active=button.dataset.gbPeriod===state.period;button.setAttribute('aria-pressed',String(active));button.classList.toggle('is-active',active);});
    const summary=state.period==='summary';$('gbPeriodTitle').textContent=summary?'Semester summary':labels[state.period]+' gradebook';
    $('gbResultsTitle').textContent=summary?'Midterm, Final & semester grade':'Student grades';
    $('gbAdd').hidden=summary;$('gbAddManual').hidden=summary;
    const notice=$('gbSchemeNotice');notice.hidden=true;notice.innerHTML='';
    if(!summary){
      const b=book(),scheme=b.scheme,ready=scheme.components.length&&scheme.total_weight===100;
      if(!ready){notice.hidden=false;notice.innerHTML=`<strong>Set up ${labels[state.period]} grading first.</strong><p>Save components totaling 100% in Grading system.</p>`;}
      else if(b.scheme_changed){notice.hidden=false;notice.innerHTML=`<strong>Your grading system has a newer revision.</strong><p>Recorded scores use revision ${escape(scheme.version)}. The latest saved revision is ${escape(b.latest_scheme.version)}.</p><button type="button" class="btn btn-outline-primary" id="gbApplyScheme">Review &amp; apply latest revision</button>`;}
    }
    $('gbWorkCount').textContent=workRows().length;
    renderWork();renderResults();
  }
  function renderWork(){
    const summary=state.period==='summary',words=fold($('gbWorkSearch').value.trim()).split(/\s+/).filter(Boolean);
    const rows=workRows().filter(row=>words.every(word=>fold(row.title).includes(word)));
    $('gbWorkTitle').textContent=(summary?'All periods':labels[state.period])+' · Graded work';
    $('gbWorkTable').innerHTML=`<table class="gb-table gb-work-table"><caption class="visually-hidden">Graded work and component assignments</caption><thead><tr><th>Graded work</th>${summary?'<th>Period</th>':''}<th>Type</th><th>Max points</th><th>Grading component</th><th>Actions</th></tr></thead><tbody>${rows.map(row=>{
      const scheme=book(row.period).scheme,component=scheme.components.find(c=>c.component_id===row.component_id),ready=scheme.components.length&&scheme.total_weight===100;
      return `<tr data-work-key="${row.key}"><th scope="row">${escape(row.title)}</th>${summary?`<td>${labels[row.period]}</td>`:''}<td>${row.entry_kind==='attendance'?'Attendance':row.entry_kind==='lms'?'LMS assessment':'Manual score'}</td><td>${number(row.max_points)}</td><td><button type="button" class="btn btn-outline-primary gb-assign-button" data-assign-work="${row.key}"${ready?'':' disabled'}>${component?escape(component.name):'Assign component'}<i class="bx bx-chevron-down" aria-hidden="true"></i></button></td><td><div class="gb-work-row-actions">${row.item_id?`<button type="button" class="btn btn-outline-primary" data-score-work="${row.key}">Enter scores</button><button type="button" class="gb-text-button" data-edit-work="${row.key}">Edit</button><button type="button" class="gb-text-button gb-danger" data-archive-work="${row.key}">Archive</button>`:`<button type="button" class="btn btn-outline-secondary" data-preview-work="${row.key}">View scores</button>`}</div></td></tr>`;
    }).join('')||`<tr><td colspan="${summary?6:5}">No graded work to show.</td></tr>`}</tbody></table>`;
    if(!state.assignment)$('gbAssignmentForm').hidden=true;
  }
  function openWork(){state.assignment=null;$('gbAssignmentForm').hidden=true;$('gbWorkSearch').value='';renderWork();workModal.show();}
  function assignWork(key){
    const row=workRows().find(row=>row.key===key);if(!row)return;state.assignment=row;
    $('gbAssignmentTitle').textContent=row.title;
    $('gbAssignmentComponent').innerHTML='<option value="">Choose grading component</option>'+book(row.period).scheme.components.map(c=>`<option value="${c.component_id}">${escape(c.name)} · ${number(c.weight)}%</option>`).join('');
    $('gbAssignmentComponent').value=row.component_id||'';$('gbAssignmentForm').hidden=false;$('gbAssignmentComponent').focus();
  }
  async function saveAssignment(event){
    event.preventDefault();if(state.busy||!state.assignment||!$('gbAssignmentForm').reportValidity())return;
    const row=state.assignment,payload={...revision(row.period),item_id:row.item_id,component_id:Number($('gbAssignmentComponent').value),entry_kind:row.entry_kind,assessment_id:row.assessment_id,title:row.title,max_points:row.max_points,recorded_date:row.recorded_date||null};
    await task(async()=>{state.data=await api('save_item',payload);state.assignment=null;$('gbAssignmentForm').hidden=true;renderOverview();feedback.toast('Component assigned. Grades recalculated.');});
  }
  function previewAssessment(id){
    const assessment=unlinkedAssessments().find(a=>a.assessment_id===id);if(!assessment)return;state.preview=assessment;
    $('gbOverview').hidden=true;$('gbAssessmentPreview').hidden=false;
    $('gbAssessmentPreviewTitle').textContent=assessment.title;
    $('gbAssessmentPreviewHelp').textContent=`${labels[assessment.grading_period]} · ${number(assessment.total_points)} max points. These submissions are saved. Assign a grading component to include their scores in calculated grades.`;
    $('gbPreviewLink').disabled=!book(assessment.grading_period).scheme.components.length||book(assessment.grading_period).scheme.total_weight!==100;
    $('gbAssessmentPreviewTable').innerHTML=`<table class="gb-table"><caption class="visually-hidden">LMS scores for ${escape(assessment.title)}</caption><thead><tr><th>Student</th><th>Score</th></tr></thead><tbody>${state.data.roster.map(student=>{const points=assessment.scores?.[student.student_id]?.points??0;return `<tr><th scope="row">${person(student)}</th>${valueCell(points,`<strong>${number(points)} / ${number(assessment.total_points)}</strong>`)}</tr>`;}).join('')||'<tr><td colspan="2">No students listed for this class.</td></tr>'}</tbody></table>`;
    $('gbAssessmentPreviewTitle').focus();
  }
  function closePreview(reopen=true){state.preview=null;$('gbAssessmentPreview').hidden=true;$('gbOverview').hidden=false;if(reopen)openWork();}
  function linkAssessment(id){
    const assessment=unlinkedAssessments().find(a=>a.assessment_id===id);if(!assessment)return;
    closePreview(false);openWork();assignWork('assessment-'+id);
  }
  function renderResults(){
    const summary=state.period==='summary',b=summary?null:book();
    let headers=summary?'<th>Midterm</th><th>Final</th><th>Semester average</th><th>Rating</th><th>Status</th>':b.scheme.components.map(c=>`<th>${escape(c.name)}<small>${number(c.weight)}% weight</small></th>`).join('')+'<th>Period grade</th><th>Rating</th>';
    const words=fold($('gbStudentSearch').value.trim()).split(/\s+/).filter(Boolean),students=state.data.roster.filter(s=>words.every(word=>fold(s.full_name+' '+s.student_number).includes(word)));
    const rows=students.map(student=>{
      let cells='';
      if(summary){const r=state.data.summary[student.student_id];cells=valueCell(r.midterm,percent(r.midterm))+valueCell(r.final,percent(r.final))+valueCell(r.average,percent(r.average),'gb-grade')+valueCell(r.average,rating(r.rating))+valueCell(r.average,escape(r.remark));}
      else{const r=b.results[student.student_id];cells=r.components.map(c=>valueCell(c.contribution,number(c.contribution))).join('')+valueCell(r.percent,percent(r.percent),'gb-grade')+valueCell(r.percent,rating(r.rating));}
      return `<tr data-result-student="${student.student_id}"><th scope="row">${person(student)}</th>${cells}</tr>`;
    }).join('');
    $('gbResultsTable').innerHTML=`<table class="gb-table"><caption class="visually-hidden">${escape(labels[state.period])} student grades</caption><thead><tr><th scope="col">Student</th>${headers}</tr></thead><tbody>${rows||`<tr><td colspan="${summary?6:(b.scheme.components.length+3)}">${state.data.roster.length?'No matching students.':'No students are listed for this class.'}</td></tr>`}</tbody></table>`;
  }
  function itemPeriodFields(period){
    const b=book(period),item=state.editingItem;state.editPeriod=period;
    $('gbItemComponent').innerHTML='<option value="">Choose grading component</option>'+b.scheme.components.map(c=>`<option value="${c.component_id}">${escape(c.name)} · ${number(c.weight)}%</option>`).join('');
    if(item)$('gbItemComponent').value=item.component_id;
    const available=new Set(unlinkedAssessments().map(a=>a.assessment_id));
    $('gbItemAssessment').innerHTML='<option value="">Choose an assessment</option>'+state.data.assessments.filter(a=>a.grading_period===period&&(available.has(a.assessment_id)||item?.assessment_id===a.assessment_id)).map(a=>`<option value="${a.assessment_id}">${escape(a.title)} · ${number(a.total_points)} pts</option>`).join('');
    if(item?.assessment_id)$('gbItemAssessment').value=item.assessment_id;
  }
  function editItem(id=0,period=state.period,manualOnly=false,fromWork=true){
    const b=book(period),item=b.items.find(i=>i.item_id===id);state.editingItem=item||null;state.editPeriod=period;state.returnToWork=fromWork;state.afterItemClose=null;
    $('gbItemForm').reset();$('gbItemForm').classList.remove('was-validated');$('gbItemTitle').textContent=item?'Edit gradebook entry':manualOnly?'Add manual entry':'Add '+labels[period]+' entry';
    $('gbItemIntro').textContent=item?'Update this entry. Its recorded scores are kept.':'Record face-to-face work under any grading component. After creating the entry, enter scores using the class roster.';
    $('gbItemPeriod').value=period;$('gbItemPeriod').disabled=!!item;
    [...$('gbItemPeriod').options].forEach(option=>{const scheme=book(option.value).scheme;option.disabled=!scheme.components.length||scheme.total_weight!==100;});
    $('gbItemKindField').hidden=manualOnly;$('gbItemKind').disabled=!!item;$('gbItemKind').value=item?.entry_kind||'score';$('gbItemName').value=item?.title||'';$('gbItemMax').value=item?.max_points||100;$('gbItemDate').value=item?.recorded_date||'';
    itemPeriodFields(period);
    updateItemType();modal.show();
  }
  function updateItemType(){
    const kind=$('gbItemKind').value,lms=kind==='lms',attendance=kind==='attendance';
    $('gbAssessmentField').hidden=!lms;$('gbItemAssessment').required=lms;$('gbItemAssessment').disabled=!!state.editingItem;
    $('gbItemMax').readOnly=lms||attendance;
    if(attendance)$('gbItemMax').value=1;
    if(lms&&!state.editingItem){const a=state.data.assessments.find(a=>a.assessment_id===Number($('gbItemAssessment').value));if(a){$('gbItemName').value=a.title;$('gbItemMax').value=a.total_points;}}
    $('gbItemHelp').textContent=lms?'Scores come from the LMS. Unrecorded scores count as zero.':attendance?'Present = 1. Absent or unrecorded = 0.':'Use this for handwritten exams, quizzes, activities or other classroom work. Unrecorded scores count as zero.';
    $('gbItemSubmit').textContent=state.editingItem?'Save entry':lms?'Link assessment':'Create and enter scores';
  }
  async function saveItem(event){
    event.preventDefault();if(state.busy||!$('gbItemForm').reportValidity())return;
    const payload={...revision(state.editPeriod),item_id:state.editingItem?.item_id||0,component_id:Number($('gbItemComponent').value),entry_kind:$('gbItemKind').value,title:$('gbItemName').value,max_points:$('gbItemMax').value,recorded_date:$('gbItemDate').value,assessment_id:$('gbItemAssessment').value?Number($('gbItemAssessment').value):null};
    await task(async()=>{
      const data=await api('save_item',payload);state.data=data;
      if(!state.editingItem&&payload.entry_kind!=='lms'){
        const id=data.saved_item_id;if(!book(state.editPeriod).items.some(item=>item.item_id===id))throw Error('The entry was saved. Open graded work to enter its scores.');
        const period=state.editPeriod;state.returnToWork=false;state.afterItemClose=()=>{state.period=period;renderOverview();openScores(id,period,true);};
      }
      closeItemModal();renderOverview();feedback.toast('Gradebook entry saved.');
    });
  }
  function openScores(id,period=state.period,focusFirst=false){
    const b=book(period),item=b.items.find(i=>i.item_id===id);if(!item)return;state.item=item;state.editPeriod=period;state.drafts.clear();state.originals.clear();state.changed.clear();closePaste();
    $('gbOverview').hidden=true;$('gbScoreEditor').hidden=false;$('gbScoreTitle').textContent=item.title;
    const component=b.scheme.components.find(c=>c.component_id===item.component_id);
    $('gbScoreHelp').textContent=`${labels[period]} · ${component.name} (${number(component.weight)}%) · ${number(item.max_points)} max points. `+(item.entry_kind==='lms'?'Blank overrides use the LMS score. A manual override requires a note.':'Blank = unrecorded; enter 0 for a recorded zero. Unrecorded scores count as zero in grades.');
    $('gbScoreSearch').value='';$('gbMarkPresent').hidden=item.entry_kind!=='attendance';$('gbPasteScores').hidden=item.entry_kind!=='score';
    $('gbScoreTable').innerHTML=`<table class="gb-table gb-score-table"><caption class="visually-hidden">Scores for ${escape(item.title)}</caption><thead><tr><th>Student</th><th>${item.entry_kind==='attendance'?'Attendance':item.entry_kind==='lms'?'Manual total override':'Score'}${item.entry_kind==='score'?' / '+number(item.max_points):''}</th><th>Recording status</th><th>Note ${item.entry_kind==='lms'?'(required for an override)':'(optional)'}</th></tr></thead><tbody>${state.data.roster.map(student=>{
      const saved=b.scores[item.item_id]?.[student.student_id],manual=saved?.source==='manual',draft={student_id:student.student_id,points:manual?saved.points:'',remark:manual?saved.remark:''};state.drafts.set(student.student_id,draft);state.originals.set(student.student_id,{...draft});
      const automatic=item.entry_kind==='lms'?(saved?.automatic_points??(saved?.source==='lms'?saved.points:0)):0;
      const description=`aria-describedby="gbScoreError-${student.student_id}"`;
      const input=item.entry_kind==='attendance'?`<select class="form-select" data-gb-input="${student.student_id}" ${description} aria-label="Attendance for ${escape(student.full_name)}"><option value="">Unrecorded</option><option value="1"${String(draft.points)==='1'?' selected':''}>Present (1)</option><option value="0"${String(draft.points)==='0'?' selected':''}>Absent (0)</option></select>`:`<input type="number" class="form-control" data-gb-input="${student.student_id}" data-gb-auto-points="${automatic}" value="${escape(draft.points)}" min="0" max="${item.max_points}" step="0.01" inputmode="decimal" placeholder="${item.entry_kind==='lms'?'LMS: '+number(automatic):'Unrecorded'}" ${description} aria-label="Score for ${escape(student.full_name)}">`;
      return `<tr data-gb-student="${student.student_id}" data-search="${escape(fold(student.full_name+' '+student.student_number))}"><th scope="row">${person(student)}</th>${valueCell(draft.points===''?automatic:draft.points,input+`<small id="gbScoreError-${student.student_id}" class="gb-input-error" hidden></small>`)}<td><span data-gb-status="${student.student_id}" class="gb-record-status"></span></td><td><details class="gb-score-note"${draft.remark?' open':''}><summary>${item.entry_kind==='lms'?'Override note':'Add / view note'}</summary><input type="text" class="form-control" data-gb-note="${student.student_id}" maxlength="1000" value="${escape(draft.remark)}" aria-label="Score note for ${escape(student.full_name)}"></details></td></tr>`;
    }).join('')||'<tr><td colspan="4">No students are listed for this class.</td></tr>'}</tbody></table>`;
    $('gbPasteStart').innerHTML=state.data.roster.map(student=>`<option value="${student.student_id}">${escape(student.full_name)} · ${escape(student.student_number)}</option>`).join('');
    state.data.roster.forEach(student=>updateScoreStatus(student.student_id));filterScores();updateSaveState();
    if(focusFirst)$('gbScoreTable').querySelector('[data-gb-input]')?.focus();else $('gbScoreTitle').focus();
  }
  function scoreError(value,badInput=false){
    if(badInput)return 'Enter a valid score.';
    if(value==='')return '';
    if(!/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?$/.test(String(value))||Number(value)>state.item.max_points)return 'Use 0 to '+number(state.item.max_points)+', with up to two decimals.';
    if(state.item.entry_kind==='attendance'&&Number(value)!==0&&Number(value)!==1)return 'Choose present (1) or absent (0).';
    return '';
  }
  function scoreRecorded(id){const d=state.drafts.get(id),saved=book(state.editPeriod).scores[state.item.item_id]?.[id];return String(d.points)!==''||(state.item.entry_kind==='lms'&&(saved?.source==='lms'||saved?.automatic_points!==undefined));}
  function updateScoreStatus(id){
    const input=$('gbScoreTable').querySelector(`[data-gb-input="${id}"]`),error=scoreError(input.value,input.validity.badInput);
    input.setCustomValidity(error);input.setAttribute('aria-invalid',String(!!error));
    const help=$('gbScoreError-'+id);help.textContent=error;help.hidden=!error;
    const status=$('gbScoreTable').querySelector(`[data-gb-status="${id}"]`),recorded=scoreRecorded(id);
    status.textContent=error?'Check score':!recorded?'Unrecorded':input.value===''?'LMS score':Number(input.value)===0?'Recorded zero':'Recorded';
    status.classList.toggle('is-recorded',recorded&&!error);status.classList.toggle('is-invalid',!!error);
  }
  function changedDraft(id){
    const d=state.drafts.get(id),original=state.originals.get(id),input=$('gbScoreTable').querySelector(`[data-gb-input="${id}"]`);
    const samePoints=String(d.points)===String(original.points)||(d.points!==''&&original.points!==''&&!scoreError(d.points)&&Number(d.points)===Number(original.points));
    if(samePoints&&d.remark===original.remark&&!input.validity.badInput)state.changed.delete(id);else state.changed.add(id);
    updateScoreHighlight(input);updateScoreStatus(id);
  }
  function filterScores(){
    const words=fold($('gbScoreSearch').value.trim()).split(/\s+/).filter(Boolean);let shown=0;
    $('gbScoreTable').querySelectorAll('[data-gb-student]').forEach(row=>{row.hidden=!words.every(word=>row.dataset.search.includes(word));if(!row.hidden)shown++;});
    $('gbScoreFilterState').textContent=words.length?shown+' of '+state.data.roster.length+' students shown. Recording progress includes the whole class.':'';
    $('gbScoreEmpty').hidden=shown>0||state.data.roster.length===0;
  }
  function updateSaveState(){
    if(!$('gbSaveScores'))return;
    const invalid=$('gbScoreTable').querySelectorAll('[data-gb-input][aria-invalid="true"]').length;
    $('gbSaveScores').disabled=state.busy||!dirty()||!!invalid;
    $('gbSaveState').textContent=state.busy&&state.item&&!state.requestFailed?'Saving changes…':dirty()?state.changed.size+' unsaved '+(state.changed.size===1?'student':'students'):'All changes saved';
    if(state.item){const recorded=[...state.drafts.keys()].filter(id=>scoreRecorded(id)).length,total=state.drafts.size;$('gbScoreProgress').textContent=recorded+' of '+total+' recorded · '+(total-recorded)+' unrecorded'+(invalid?' · '+invalid+' to correct':'');}
    if(state.data){const ready=state.period!=='summary'&&book().scheme.components.length&&book().scheme.total_weight===100;$('gbAdd').disabled=$('gbAddManual').disabled=state.busy||!ready;}
  }
  async function leaveScores(reopen=true){if(dirty()&&!await feedback.confirm({title:'Discard unsaved scores?',text:'Your latest score changes have not been saved.',confirmText:'Discard changes',cancelText:'Keep editing'}))return false;closePaste();state.changed.clear();state.item=null;$('gbScoreEditor').hidden=true;$('gbOverview').hidden=false;updateSaveState();if(reopen)openWork();return true;}
  async function saveScores(){
    if(state.busy||!dirty())return;
    if(!$('gbPastePanel').hidden&&$('gbPasteText').value.trim())return feedback.error('Apply the pasted scores to the table first, or close the paste panel before saving.');
    const scores=[...state.changed].map(id=>{const d=state.drafts.get(id);return {...d,points:d.points===''?null:d.points};});
    for(const score of scores){if(score.points!==null&&(!/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?$/.test(String(score.points))||Number(score.points)>state.item.max_points))return feedback.error('Use a score from 0 to '+number(state.item.max_points)+', with up to two decimal places.');if(score.points!==null&&state.item.entry_kind==='lms'&&!score.remark.trim())return feedback.error('Add a note for each manually reviewed LMS total or override.');}
    await task(async()=>{const id=state.item.item_id,period=state.editPeriod;state.data=await api('save_scores',{...revision(period),item_id:id,scores});state.changed.clear();renderOverview();openScores(id,period);feedback.toast('Scores saved. Grades recalculated.');});
  }
  root.addEventListener('input',event=>{
    const input=event.target;
    if(input.matches('[data-gb-input],[data-gb-note]')){if(state.paste)resetPastePreview();const id=Number(input.dataset.gbInput||input.dataset.gbNote),draft=state.drafts.get(id);draft[input.dataset.gbInput?'points':'remark']=input.value;changedDraft(id);if(state.item.entry_kind==='lms'&&draft.points!==''&&!draft.remark.trim())$('gbScoreTable').querySelector(`[data-gb-note="${id}"]`).closest('details').open=true;updateSaveState();}
  });
  function updateScoreHighlight(input){input.closest('td').classList.toggle('gb-has-score',hasScore(input.value===''?input.dataset.gbAutoPoints??0:input.value));}
  function resetPastePreview(){state.paste=null;$('gbPasteTable').hidden=true;$('gbPasteTable').replaceChildren();$('gbPasteState').textContent='';$('gbPasteApply').disabled=true;}
  function closePaste(){resetPastePreview();$('gbPastePanel').hidden=true;$('gbPasteScores').setAttribute('aria-expanded','false');$('gbPasteText').value='';}
  function openPaste(studentId=null,text=null){
    if(state.busy||state.item?.entry_kind!=='score')return;
    resetPastePreview();$('gbPastePanel').hidden=false;$('gbPasteScores').setAttribute('aria-expanded','true');
    if(studentId)$('gbPasteStart').value=String(studentId);
    if(text!==null){$('gbPasteText').value=text;previewPaste();}else $('gbPasteText').focus();
  }
  function previewPaste(){
    if(state.busy||state.item?.entry_kind!=='score')return;resetPastePreview();
    const raw=$('gbPasteText').value.replace(/^\uFEFF/,'').replace(/\r\n?/g,'\n').replace(/\n$/,''),start=state.data.roster.findIndex(student=>student.student_id===Number($('gbPasteStart').value));
    if(raw.length>20000){$('gbPasteState').textContent='Paste up to 20,000 characters at a time. No scores have been applied.';return;}
    if(!raw.trim()){$('gbPasteState').textContent='Paste at least one score before previewing.';return;}
    const lines=raw.split('\n');
    if(start<0||lines.length>state.data.roster.length-start||lines.length>1000){$('gbPasteState').textContent='There are more pasted rows than students from the selected starting student. No scores have been applied.';return;}
    let invalid=0,updates=0,replacements=0;
    const rows=lines.map((line,index)=>{
      const student=state.data.roster[start+index],points=line.trim(),current=state.drafts.get(student.student_id).points;
      const error=line.includes('\t')?'Paste one score column only.':scoreError(points);
      if(error)invalid++;else if(points!==''){updates++;if(current!=='')replacements++;}
      return {student,points,current,error};
    });
    $('gbPasteTable').innerHTML=`<table class="gb-table"><caption class="visually-hidden">Review students and scores before applying</caption><thead><tr><th>Student</th><th>Current score</th><th>Pasted score</th><th>Preview</th></tr></thead><tbody>${rows.map(row=>`<tr><th scope="row">${person(row.student)}</th><td>${row.current===''?'Unrecorded':number(row.current)}</td><td>${escape(row.points)||'Blank'}</td><td class="${row.error?'gb-input-error':''}">${escape(row.error|| (row.points===''?'Keep current score':row.current!==''?'Replace current score':'Record score'))}</td></tr>`).join('')}</tbody></table>`;
    $('gbPasteTable').hidden=false;
    $('gbPasteState').textContent=invalid?invalid+' invalid '+(invalid===1?'score':'scores')+'. Correct the pasted column and preview again. No scores have been applied.':updates+' scores ready · '+replacements+' current scores will be replaced. Apply to the score table, then use Save scores to record them.';
    if(!invalid&&updates){state.paste={rows,itemId:state.item.item_id,period:state.editPeriod};$('gbPasteApply').disabled=false;}
  }
  function applyPaste(){
    if(state.busy||!state.paste||state.paste.itemId!==state.item?.item_id||state.paste.period!==state.editPeriod)return;
    const rows=state.paste.rows.filter(row=>row.points!=='');
    for(const row of rows){const id=row.student.student_id,input=$('gbScoreTable').querySelector(`[data-gb-input="${id}"]`);state.drafts.get(id).points=row.points;input.value=row.points;changedDraft(id);}
    closePaste();updateSaveState();
    const first=rows.find(row=>!$('gbScoreTable').querySelector(`[data-gb-student="${row.student.student_id}"]`).hidden);
    if(first)$('gbScoreTable').querySelector(`[data-gb-input="${first.student.student_id}"]`).focus();
    feedback.toast(rows.length+' pasted scores applied to the table. Save scores to record them.');
  }
  $('gbScoreTable').addEventListener('keydown',event=>{
    const input=event.target;if(state.busy||event.isComposing||event.key!=='Enter'||!input.matches('[data-gb-input]'))return;
    event.preventDefault();if(!input.reportValidity())return;
    const inputs=[...$('gbScoreTable').querySelectorAll('[data-gb-input]')].filter(node=>!node.closest('[data-gb-student]').hidden&&!node.disabled),index=inputs.indexOf(input),next=inputs[index+(event.shiftKey?-1:1)];
    if(next){next.focus();if(next.select)next.select();}else if(!event.shiftKey&&!$('gbSaveScores').disabled)$('gbSaveScores').focus();
  });
  $('gbScoreTable').addEventListener('paste',event=>{
    if(state.busy||state.item?.entry_kind!=='score'||!event.target.matches('[data-gb-input]'))return;
    const text=event.clipboardData?.getData('text/plain')??'';
    if(/[\r\n\t]/.test(text)){event.preventDefault();openPaste(Number(event.target.dataset.gbInput),text);}
  });
  $('gbPasteScores').addEventListener('click',()=>openPaste());$('gbPasteClose').addEventListener('click',closePaste);
  $('gbPasteText').addEventListener('input',resetPastePreview);$('gbPasteStart').addEventListener('change',resetPastePreview);
  $('gbPastePreview').addEventListener('click',previewPaste);$('gbPasteApply').addEventListener('click',applyPaste);
  $('gbItemForm').addEventListener('submit',saveItem);feedback.validation($('gbItemForm'));
  root.addEventListener('click',async event=>{
    if(state.busy)return;const period=event.target.closest('[data-gb-period]');
    if(period){if(state.item&&!await leaveScores(false))return;if(state.preview)closePreview(false);state.period=period.dataset.gbPeriod;renderOverview();syncAvailableWork();return;}
    const action=event.target.closest('[data-assign-work],[data-score-work],[data-edit-work],[data-archive-work],[data-preview-work]');
    if(action){
      const key=action.dataset.assignWork||action.dataset.scoreWork||action.dataset.editWork||action.dataset.archiveWork||action.dataset.previewWork,row=workRows().find(row=>row.key===key);if(!row)return;
      if(action.dataset.assignWork)assignWork(key);
      if(action.dataset.scoreWork)afterWorkClosed(()=>openScores(row.item_id,row.period));
      if(action.dataset.editWork)afterWorkClosed(()=>editItem(row.item_id,row.period));
      if(action.dataset.previewWork)afterWorkClosed(()=>previewAssessment(row.assessment_id));
      if(action.dataset.archiveWork&&await feedback.confirm({title:'Archive this entry?',text:'It will be excluded from grades. Scores and the change history remain stored.',confirmText:'Archive entry',cancelText:'Keep entry',danger:true}))await task(async()=>{state.data=await api('archive_item',{...revision(row.period),item_id:row.item_id});renderOverview();feedback.toast('Entry archived.');});
    }
    if(event.target.closest('#gbApplyScheme')&&await feedback.confirm({title:'Apply the latest grading revision?',text:'This replaces the saved weights used by this period and recalculates every student grade. Scores are kept.',confirmText:'Apply & recalculate',cancelText:'Keep current revision'}))await task(async()=>{state.data=await api('use_latest_scheme',revision());renderOverview();feedback.toast('Latest grading revision applied.');});
  });
  $('gbPreviewBack').addEventListener('click',()=>closePreview());$('gbPreviewLink').addEventListener('click',()=>{if(state.preview)linkAssessment(state.preview.assessment_id);});
  $('gbItemKind').addEventListener('change',updateItemType);$('gbItemAssessment').addEventListener('change',updateItemType);
  $('gbItemPeriod').addEventListener('change',()=>{itemPeriodFields($('gbItemPeriod').value);updateItemType();});
  $('gbAddManual').addEventListener('click',()=>{if(!state.busy&&state.period!=='summary')editItem(0,state.period,true,false);});
  $('gbOpenWork').addEventListener('click',openWork);$('gbAdd').addEventListener('click',()=>afterWorkClosed(()=>editItem()));$('gbReload').addEventListener('click',load);$('gbRetry').addEventListener('click',load);
  $('gbAssignmentForm').addEventListener('submit',saveAssignment);feedback.validation($('gbAssignmentForm'));
  $('gbAssignmentCancel').addEventListener('click',()=>{state.assignment=null;$('gbAssignmentForm').hidden=true;});$('gbWorkSearch').addEventListener('input',renderWork);
  $('gbStudentSearch').addEventListener('input',renderResults);
  $('gbPrintGradesheet')?.addEventListener('click',event=>{if(dirty()||state.busy){event.preventDefault();feedback.error(dirty()?'Save your unsaved scores before printing the grade sheet.':'Wait for the current gradebook request to finish before printing.','Print grade sheet');}});
  $('gbScoreSearch').addEventListener('input',filterScores);
  $('gbScoreBack').addEventListener('click',()=>leaveScores());$('gbSaveScores').addEventListener('click',saveScores);
  $('gbMarkPresent').addEventListener('click',async()=>{const unmarked=[...state.drafts.values()].filter(d=>String(d.points)==='');if(!unmarked.length)return;if(!await feedback.confirm({title:'Mark unrecorded students present?',text:`This will mark ${unmarked.length} unrecorded students present across the whole roster. Recorded absences stay unchanged. Save scores to record these changes.`,confirmText:'Mark present',cancelText:'Cancel'}))return;unmarked.forEach(d=>{d.points='1';const input=$('gbScoreTable').querySelector(`[data-gb-input="${d.student_id}"]`);input.value='1';changedDraft(d.student_id);});updateSaveState();});
  feedback.guardNavigation(dirty,()=>state.changed.clear(),'Your unsaved scores will be discarded. Save scores before leaving.');
  window.addEventListener('beforeunload',event=>{if(dirty()){event.preventDefault();event.returnValue='';}});
  window.addEventListener('focus',syncAvailableWork);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)syncAvailableWork();});
  setInterval(syncAvailableWork,30000);
  load();
})();
