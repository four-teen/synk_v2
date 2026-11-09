// Browser-independent interaction checks for reminder lifecycle; HTTP/SQL tests
// cover identity, Dean access, complete answers and final submission separately.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const script=fs.readFileSync(require('node:path').join(__dirname,'../student/evaluation-prompt.js'),'utf8');
let checks=0;
const check=(condition,message)=>{assert.ok(condition,message);checks++;};
class Element {
  constructor(){this.events=new Map();this.dataset={};this.textContent='';this.children=[];this.classes=new Set();this.classList={contains:name=>this.classes.has(name)};}
  addEventListener(name,callback,options={}){const entries=this.events.get(name)||[];entries.push({callback,once:options.once});this.events.set(name,entries);}
  dispatch(name,extra={}){const event={target:this,preventDefault(){this.defaultPrevented=true;},...extra};for(const entry of [...(this.events.get(name)||[])]){entry.callback(event);if(entry.once)this.events.set(name,this.events.get(name).filter(item=>item!==entry));}return event;}
  replaceChildren(...children){this.children=children;}
  append(...children){this.children.push(...children);}
  querySelector(){return this.editable ? {} : null;}
  setAttribute(){}
  scrollIntoView(){this.scrolled=true;}
  focus(){this.focused=true;}
}
const state=(extra={})=>{
  const value={required:true,academic_period:'2026-2027 / 1st Semester',academic_key:'2-1',remaining:2,drafts:0,missing:2,key:'pending-2',...extra};
  const base='class-program.php?term_key=2-1&evaluation_college_id=7';
  if(!extra.colleges)value.colleges=[{college_id:7,college_name:'Test College',faculty_count:2,submitted:2-value.remaining,remaining:value.remaining,drafts:value.drafts,missing:value.missing,ready_to_submit:value.missing===0,
    pending_faculty:value.missing?[{faculty_id:3,faculty_name:'Test Faculty',requirements:['Add your required comment.'],url:base+'#facultyEvaluationModal_faculty-3'}]:[],
    url:base+'#facultyEvaluationOverview',action_url:base+(value.missing?'#facultyEvaluationModal_faculty-3':'#finalEvaluationReviewModal')}];
  return value;
};
function page(initial,url='http://localhost/synk/student/index.php',storage=new Map()) {
  const nodes=new Map(),document=new Element(),window=new Element(),timers=[];
  const ids=['studentEvaluationPromptConfig','studentEvaluationPrompt','studentEvaluationPromptStart','studentEvaluationPromptTitle','studentEvaluationPromptDescription','studentEvaluationPromptNote','studentEvaluationPromptMissing','studentEvaluationPromptPeriod','studentEvaluationPromptProgress','studentEvaluationPromptColleges','facultyEvaluationOverview','facultyEvaluationModal_faculty-3','finalEvaluationReviewModal'];
  for(const id of ids){const node=new Element();node.id=id;nodes.set(id,node);}
  nodes.get('facultyEvaluationModal_faculty-3').editable=true;
  nodes.get('facultyEvaluationModal_faculty-3').classes.add('faculty-eval-modal');
  nodes.get(ids[0]).textContent=JSON.stringify({student_id:11,csrf_token:'token',endpoint:'../backend/query_student_evaluation_prompt.php',initial});
  const location=new URL(url);document.hidden=false;document.getElementById=id=>nodes.get(id);document.createElement=()=>new Element();
  const modalElement=nodes.get('studentEvaluationPrompt'),start=nodes.get('studentEvaluationPromptStart');
  let otherModal=false,shown=0,nextState=initial,requests=0;
  Object.defineProperty(start,'href',{get(){return new URL(this.rawHref||'class-program.php',location.href).href;},set(value){this.rawHref=value;}});
  document.querySelector=selector=>selector.startsWith('#finalFacultyEvaluationForm')?(initial.colleges?.some(c=>c.ready_to_submit)?{}:null):(otherModal||[...nodes.values()].some(node=>node.classes.has('show'))?{}:null);
  const modals=new Map();
  function getModal(node){
    if(!modals.has(node))modals.set(node,{show(){if(node===modalElement)shown++;node.classes.add('show');},hide(){const event=node.dispatch('hide.bs.modal');if(!event.defaultPrevented){node.classes.delete('show');node.dispatch('hidden.bs.modal');document.dispatch('hidden.bs.modal',{target:node});}}});
    return modals.get(node);
  }
  const modal=getModal(modalElement);
  const context={document,window,location,URL,URLSearchParams,AbortController,Date,
    bootstrap:{Modal:{getOrCreateInstance:getModal}},
    sessionStorage:{getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},
    setTimeout:()=>0,clearTimeout(){},setInterval:callback=>{timers.push(callback);return timers.length;},clearInterval(){},
    fetch:async()=>{requests++;return {ok:true,json:async()=>({status:'ok',evaluation:nextState})};}
  };
  vm.runInNewContext(script,context);
  document.dispatch('DOMContentLoaded');window.dispatch('pageshow',{persisted:false});
  return {nodes,document,window,modal,storage,close:id=>getModal(nodes.get(id)).hide(),get shown(){return shown;},get visible(){return modalElement.classes.has('show');},get requests(){return requests;},set otherModal(value){otherModal=value;},start(){return start.dispatch('click');},async poll(value){nextState=value;await timers.at(-1)();}};
}
(async()=>{
  const closed=page(state({required:false,colleges:[],remaining:0,drafts:0,missing:0}));check(!closed.visible,'Closed evaluations opened a prompt.');
  const pending=page(state());check(pending.visible&&pending.shown===1,'Required guidance did not open once.');
  pending.modal.hide();check(pending.visible,'Required guidance can be dismissed without Start.');
  check(pending.nodes.get('studentEvaluationPromptStart').href.endsWith('#facultyEvaluationModal_faculty-3')&&pending.nodes.get('studentEvaluationPromptProgress').textContent.includes('2 faculty'),'Continue or progress targets the wrong scope.');
  check(pending.nodes.get('studentEvaluationPromptMissing').children[0].children[1].textContent==='Add your required comment.','Missing requirements are not explained for the named faculty.');
  pending.start();check(pending.storage.size===1,'Start did not preserve the one navigation handoff.');
  const destination=page(state(),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',pending.storage);
  check(!destination.visible&&destination.storage.size===0,'Start displayed the same reminder again before faculty selection.');
  check(destination.nodes.get('facultyEvaluationModal_faculty-3').classes.has('show'),'Continue did not open the unfinished faculty form after navigation.');
  destination.close('facultyEvaluationModal_faculty-3');check(destination.visible,'Closing an unfinished form permanently suppressed the reminder.');
  const revisit=page(state(),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',destination.storage);
  check(revisit.visible,'An unsubmitted evaluation was permanently suppressed.');
  const startClick=revisit.start();check(startClick.defaultPrevented&&!revisit.visible,'Start on the faculty page did not reveal the faculty list.');
  revisit.window.dispatch('pageshow',{persisted:false});check(!revisit.visible,'The prompt reopened immediately after Start on the same page.');
  revisit.close('facultyEvaluationModal_faculty-3');
  await revisit.poll(state({key:'drafts-ready',drafts:2,missing:0}));check(revisit.visible&&revisit.nodes.get('studentEvaluationPromptStart').textContent==='Submit Evaluations','Saved answers did not expose final submission.');
  check(revisit.nodes.get('studentEvaluationPromptTitle').textContent==='Your evaluations are ready to submit'&&revisit.nodes.get('studentEvaluationPromptMissing').hidden,'A ready evaluation still shows missing-work instructions.');
  await revisit.poll(state({required:false,remaining:0,colleges:[],key:'complete'}));check(!revisit.visible,'Submitted evaluations retained the prompt.');
  const inactive=page(state({required:false,colleges:[],remaining:0}));await inactive.poll(state());check(inactive.visible,'Opening collection while signed in did not show guidance.');
  inactive.start();await inactive.poll(state({required:false,colleges:[],remaining:0}));await inactive.poll(state());check(inactive.visible,'A reopened evaluation period reused an old acknowledgment.');
  const busy=page(state({required:false,colleges:[],remaining:0}));busy.otherModal=true;await busy.poll(state());check(!busy.visible,'The reminder interrupted another open form.');
  busy.otherModal=false;busy.document.dispatch('hidden.bs.modal',{target:new Element()});check(busy.visible,'The reminder did not appear after another form closed.');
  const wrongCollege=new Map([['student-evaluation-start-11',JSON.stringify({key:'pending-2',college_id:8,at:Date.now()})]]);
  check(page(state(),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',wrongCollege).visible,'A ticket for another college suppressed guidance.');
  check(revisit.requests===2,'Reminder checks were not sent to the status endpoint.');
  const ready=page(state({key:'ready',drafts:2,missing:0}));ready.start();
  const review=page(state({key:'ready',drafts:2,missing:0}),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',ready.storage);
  check(!review.visible&&review.nodes.get('finalEvaluationReviewModal').classes.has('show'),'Returning with saved answers did not go straight to the final review.');
  review.close('finalEvaluationReviewModal');check(review.visible,'Closing final review incorrectly counted as submission.');
  const multiState=state(),readyCollege={...state({drafts:2,missing:0}).colleges[0],college_id:8,college_name:'Ready College',action_url:'class-program.php?term_key=2-1&evaluation_college_id=8#finalEvaluationReviewModal'};
  multiState.colleges.push(readyCollege);
  const multiple=page(multiState);check(multiple.nodes.get('studentEvaluationPromptStart').href.includes('evaluation_college_id=8')&&!multiple.nodes.get('studentEvaluationPromptColleges').hidden,'An incomplete college blocked another college ready for submission.');
  const selected=page(multiState,'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7');check(selected.nodes.get('studentEvaluationPromptStart').textContent==='Continue Evaluation','The reminder lost the selected college.');
  check(!page(state({key:'ready',drafts:2,missing:0})).nodes.get('finalEvaluationReviewModal').classes.has('show'),'The reminder opened or submitted final review without an explicit student action.');
  console.log(`Student evaluation reminder interactions: ${checks} checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
