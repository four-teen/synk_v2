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
  append(child){this.children.push(child);}
  setAttribute(){}
  scrollIntoView(){this.scrolled=true;}
  focus(){this.focused=true;}
}
const state=(extra={})=>({required:true,academic_period:'2026-2027 / 1st Semester',academic_key:'2-1',remaining:2,drafts:0,missing:2,key:'pending-2',colleges:[{college_id:7,college_name:'Test College',remaining:2,url:'class-program.php?term_key=2-1&evaluation_college_id=7#facultyEvaluationOverview'}],...extra});
function page(initial,url='http://localhost/synk/student/index.php',storage=new Map()) {
  const nodes=new Map(),document=new Element(),window=new Element(),timers=[];
  const ids=['studentEvaluationPromptConfig','studentEvaluationPrompt','studentEvaluationPromptStart','studentEvaluationPromptTitle','studentEvaluationPromptPeriod','studentEvaluationPromptProgress','studentEvaluationPromptColleges','facultyEvaluationOverview'];
  for(const id of ids)nodes.set(id,new Element());
  nodes.get(ids[0]).textContent=JSON.stringify({student_id:11,csrf_token:'token',endpoint:'../backend/query_student_evaluation_prompt.php',initial});
  const location=new URL(url);document.hidden=false;document.getElementById=id=>nodes.get(id);document.createElement=()=>new Element();
  const modalElement=nodes.get('studentEvaluationPrompt'),start=nodes.get('studentEvaluationPromptStart');
  let otherModal=false,shown=0,nextState=initial,requests=0;
  Object.defineProperty(start,'href',{get(){return new URL(this.rawHref||'class-program.php',location.href).href;},set(value){this.rawHref=value;}});
  document.querySelector=()=>otherModal||modalElement.classes.has('show')?{}:null;
  const modal={show(){shown++;modalElement.classes.add('show');},hide(){const event=modalElement.dispatch('hide.bs.modal');if(!event.defaultPrevented){modalElement.classes.delete('show');modalElement.dispatch('hidden.bs.modal');}}};
  const context={document,window,location,URL,URLSearchParams,AbortController,Date,
    bootstrap:{Modal:{getOrCreateInstance:()=>modal}},
    sessionStorage:{getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},
    setTimeout:()=>0,clearTimeout(){},setInterval:callback=>{timers.push(callback);return timers.length;},clearInterval(){},
    fetch:async()=>{requests++;return {ok:true,json:async()=>({status:'ok',evaluation:nextState})};}
  };
  vm.runInNewContext(script,context);
  document.dispatch('DOMContentLoaded');window.dispatch('pageshow',{persisted:false});
  return {nodes,document,window,modal,storage,get shown(){return shown;},get visible(){return modalElement.classes.has('show');},get requests(){return requests;},set otherModal(value){otherModal=value;},start(){return start.dispatch('click');},async poll(value){nextState=value;await timers.at(-1)();}};
}
(async()=>{
  const closed=page(state({required:false,colleges:[],remaining:0,drafts:0,missing:0}));check(!closed.visible,'Closed evaluations opened a prompt.');
  const pending=page(state());check(pending.visible&&pending.shown===1,'Required guidance did not open once.');
  pending.modal.hide();check(pending.visible,'Required guidance can be dismissed without Start.');
  check(pending.nodes.get('studentEvaluationPromptStart').href.includes('evaluation_college_id=7')&&pending.nodes.get('studentEvaluationPromptProgress').textContent.includes('2 faculty'),'Start or progress targets the wrong scope.');
  pending.start();check(pending.storage.size===1,'Start did not preserve the one navigation handoff.');
  const destination=page(state(),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',pending.storage);
  check(!destination.visible&&destination.storage.size===0,'Start displayed the same reminder again before faculty selection.');
  const revisit=page(state(),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',destination.storage);
  check(revisit.visible,'An unsubmitted evaluation was permanently suppressed.');
  const startClick=revisit.start();check(startClick.defaultPrevented&&!revisit.visible,'Start on the faculty page did not reveal the faculty list.');
  revisit.window.dispatch('pageshow',{persisted:false});check(!revisit.visible,'The prompt reopened immediately after Start on the same page.');
  await revisit.poll(state({key:'drafts-ready',drafts:2,missing:0}));check(revisit.visible&&revisit.nodes.get('studentEvaluationPromptStart').textContent==='Continue evaluation','Saving drafts incorrectly counted as final submission.');
  await revisit.poll(state({required:false,remaining:0,colleges:[],key:'complete'}));check(!revisit.visible,'Submitted evaluations retained the prompt.');
  const inactive=page(state({required:false,colleges:[],remaining:0}));await inactive.poll(state());check(inactive.visible,'Opening collection while signed in did not show guidance.');
  inactive.start();await inactive.poll(state({required:false,colleges:[],remaining:0}));await inactive.poll(state());check(inactive.visible,'A reopened evaluation period reused an old acknowledgment.');
  const busy=page(state({required:false,colleges:[],remaining:0}));busy.otherModal=true;await busy.poll(state());check(!busy.visible,'The reminder interrupted another open form.');
  busy.otherModal=false;busy.document.dispatch('hidden.bs.modal',{target:new Element()});check(busy.visible,'The reminder did not appear after another form closed.');
  const wrongCollege=new Map([['student-evaluation-start-11',JSON.stringify({key:'pending-2',college_id:8,at:Date.now()})]]);
  check(page(state(),'http://localhost/synk/student/class-program.php?term_key=2-1&evaluation_college_id=7',wrongCollege).visible,'A ticket for another college suppressed guidance.');
  check(revisit.requests===2,'Reminder checks were not sent to the status endpoint.');
  console.log(`Student evaluation reminder interactions: ${checks} checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
