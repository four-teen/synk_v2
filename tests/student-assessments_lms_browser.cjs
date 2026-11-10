// Actual student PHP page/assets, controlled delivery responses for UI scenarios.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),tag=crypto.randomBytes(10).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-assessments-${tag}_lms.php`),profile=path.join(root,'.tmp',`student-assessments-${tag}_lms`),pending=new Map(),errors=[],requests=[];
let browser,ws,call,session,config,checks=0,conflict=false;const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms)),clone=value=>JSON.parse(JSON.stringify(value));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
const stamp=()=>new Date().toLocaleString('sv-SE',{timeZone:'Asia/Manila'});
const questions=[{question_id:1,question_type:'multiple_choice',prompt:'What is `2 + 2`?',points:2,options:['3','4 <img src=x onerror=alert(1)>']},{question_id:2,question_type:'true_false',prompt:'2 > 3',points:1,options:[]},{question_id:3,question_type:'short_answer',prompt:'Name a language.',points:1,options:[]},{question_id:4,question_type:'essay',prompt:'Explain this code:\n```php\n    echo "<script>test</script>";\n```',points:5,options:[]}];
const records=[{assessment_id:1,grading_period:'midterm',assessment_type:'quiz',title:'Programming quiz',instructions:'Read the code carefully.',topic:'Programming',status:'published',total_points:9,opens_at:null,due_at:null,time_limit_minutes:15,subject_label:'CS 111: Fundamentals of Programming',questions},{assessment_id:2,grading_period:'final',assessment_type:'assignment',title:'Programming project',instructions:'Describe your solution.',topic:'Project',status:'published',total_points:100,opens_at:null,due_at:null,time_limit_minutes:null,subject_label:'CS 111: Fundamentals of Programming',questions:[]}];
const attempts={};
async function evaluate(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;}
async function until(expression){for(let i=0;i<150;i++){try{if(await evaluate(expression))return;}catch{}await delay(100);}const debug=await evaluate('({ready:document.readyState,list:document.querySelector("#studentAssessmentList")?.textContent,alert:document.querySelector("#swal2-html-container")?.textContent})');throw Error('Browser condition timed out: '+expression+' / '+errors.join(' / ')+' / actions: '+requests.map(r=>r.action).join(',')+' / '+JSON.stringify(debug));}
async function click(selector){await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);}
async function fill(selector,value){await evaluate(`(()=>{const n=document.querySelector(${JSON.stringify(selector)});n.value=${JSON.stringify(value)};n.dispatchEvent(new Event('input',{bubbles:true}));})()`);}
async function answer(confirmed=true){await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');await click(confirmed?'.swal2-confirm':'.swal2-cancel');await until('!document.querySelector(".swal2-popup:not(.swal2-toast)") && !synkLmsFeedback.isPending()');}
function api(request){
 requests.push(request);check(request.csrf_token===config.csrf_token,'Student requests include the session CSRF token.');
 const payload=request.payload||{},record=records.find(row=>row.assessment_id===payload.assessment_id);
 if(request.action==='list')return {ok:true,server_time:stamp(),assessments:records.map(({questions,...row})=>({...row,attempt_status:attempts[row.assessment_id]?.status||null}))};
 if(request.action==='start'&&!attempts[record.assessment_id])attempts[record.assessment_id]={attempt_id:record.assessment_id,version:1,status:'in_progress',started_at:stamp(),deadline_at:record.time_limit_minutes?new Date(Date.now()+15*60000).toLocaleString('sv-SE',{timeZone:'Asia/Manila'}):null,submitted_at:null,answers:{}};
 const attempt=attempts[record.assessment_id]||null;
 if(request.action==='save'||request.action==='submit'){
  if(conflict){conflict=false;return {ok:false,status:409,message:'Your responses changed in another tab. Reload the assessment to continue.'};}
  check(payload.version===attempt.version,'The student sends the latest attempt revision.');attempt.answers=clone(payload.answers);attempt.version++;
  if(request.action==='submit'){attempt.status='submitted';attempt.submitted_at=stamp();}
 }
 return {ok:true,server_time:stamp(),assessment:{...clone(record),questions:attempt?clone(record.questions):[]},attempt:clone(attempt)};
}
async function main(){
 const phpPath=path.join(root,'tests/fixtures/student-assessment-request_lms.php').replaceAll('\\','/');fs.writeFileSync(route,`<?php define('SYNK_PROFESSOR_LMS_QA','${token}');require '${phpPath}';`);
 const response=await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}`),html=await response.text();
 check(response.status===200&&!/Fatal error|Parse error|xdebug-error/.test(html),'The actual student assessments page renders without PHP errors: '+response.status+' / '+html.replace(/<[^>]*>/g,' ').slice(-2200));
 check(response.headers.get('x-qa-core-unchanged')==='yes','Rendering the actual page must roll back all student and core fixtures.');
 const match=html.match(/<script id="studentAssessmentConfig" type="application\/json">(.*?)<\/script>/s);if(!match)throw Error('Missing student assessment configuration.');config=JSON.parse(match[1]);
 const executable=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
 browser=spawn(executable,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error('Browser timeout: '+method));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 ws.addEventListener('message',event=>{
  const m=JSON.parse(event.data);if(pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timer);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}
  if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Page.javascriptDialogOpening'){errors.push('Unexpected native dialog: '+m.params.type);call('Page.handleJavaScriptDialog',{accept:true},session).catch(e=>errors.push(e.message));}
  if(m.method==='Fetch.requestPaused')(async()=>{const {requestId,request}=m.params,isApi=request.url.includes('assessments_api_lms.php'),isPresence=request.url.includes('student_presence.php'),data=isApi?api(JSON.parse(request.postData)):isPresence?{status:'ok',seconds_remaining:900}:html,status=isApi?(data.status||200):200;if(isApi)delete data.status;await call('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:isApi||isPresence?'application/json':'text/html; charset=utf-8'}],body:Buffer.from(isApi||isPresence?JSON.stringify(data):data).toString('base64')},session);})().catch(e=>errors.push(e.stack));
 });
 const target=await call('Target.createTarget',{url:'about:blank'});session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 for(const method of ['Page.enable','Runtime.enable','Network.enable'])await call(method,{},session);
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
 await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost/synk/student/assessments_lms.php',resourceType:'Document'},{urlPattern:'http://localhost/synk/student/assessments_api_lms.php'},{urlPattern:'http://localhost/synk/backend/student_presence.php'}]},session);
 await call('Page.navigate',{url:'http://localhost/synk/student/assessments_lms.php'},session);await until('!!document.querySelector("[data-open]")');
 check(await evaluate('document.querySelector("#studentAssessmentList").textContent.includes("Programming quiz") && !document.querySelector("#studentAssessmentList").textContent.includes("Programming project")'),'Student assessments are separated by grading period.');
 await click('[data-open="1"]');await until('!document.querySelector("#studentAssessmentEditor").hidden');
 check(await evaluate('document.querySelector("#studentAssessmentForm").hidden && document.querySelector("#studentAssessmentStatus").textContent.includes("One attempt")'),'The student sees instructions and the one-attempt rule before starting.');
 await click('#studentAssessmentStart');await answer(false);check(!attempts[1],'Canceling Start does not create an attempt.');
 await click('#studentAssessmentStart');await answer();await until('!document.querySelector("#studentAssessmentForm").hidden');
 check(await evaluate('document.querySelectorAll(".assessment-preview-question").length===4 && document.querySelector("#studentAssessmentTimer").textContent.includes("Time remaining")'),'Starting opens all question types and the countdown.');
 check(await evaluate('document.querySelector("pre code").textContent.includes("    echo") && !document.querySelector(".assessment-preview-question img,.assessment-preview-question script")'),'Code formatting and option text are escaped in the student form.');
 await click('[data-answer="1"][value="1"]');await click('[data-answer="2"][value="false"]');await fill('#answer3','PHP');await fill('#answer4','My explanation.');
 await click('#studentAssessmentSave');await until('document.querySelector("#studentAssessmentStatus").textContent==="Answers saved."');
 check(attempts[1].answers['1']===1&&attempts[1].answers['2']===false&&attempts[1].answers['3']==='PHP','Answer saving preserves numeric choices, False, and text.');
 const deadline=attempts[1].deadline_at;await click('#studentAssessmentBack');await until('!document.querySelector("#studentAssessmentLibrary").hidden && !!document.querySelector("[data-open=\\"1\\"]")');await click('[data-open="1"]');await until('!document.querySelector("#studentAssessmentForm").hidden');
 check(attempts[1].deadline_at===deadline&&await evaluate('document.querySelector("#answer4").value==="My explanation."'),'Reopening restores saved answers and keeps the original deadline.');
 await fill('#answer4','Changed explanation');conflict=true;await click('#studentAssessmentSave');await until('document.querySelector("#swal2-html-container")?.textContent.includes("another tab")');
 check(await evaluate('document.querySelector("#answer4").value==="Changed explanation"'),'A save conflict keeps the locally typed response.');await answer();
 await click('#studentAssessmentSubmit');await answer(false);check(attempts[1].status==='in_progress','Canceling submission keeps the attempt open.');
 await click('#studentAssessmentSubmit');await answer();await until('document.querySelector("#studentAssessmentStatus").textContent.includes("Submitted.")');await answer();
 check(attempts[1].status==='submitted'&&await evaluate('[...document.querySelectorAll("#studentAssessmentQuestions input,#studentAssessmentQuestions textarea")].every(n=>n.disabled) && document.querySelector("#studentAssessmentSave").hidden'),'Submitted responses are locked and cannot be edited.');
 await click('#studentAssessmentBack');await until('!document.querySelector("#studentAssessmentLibrary").hidden');await click('[data-student-period=final]');await click('[data-open="2"]');await until('!document.querySelector("#studentAssessmentEditor").hidden');await click('#studentAssessmentStart');await answer();await until('!!document.querySelector("#assignmentResponse")');
 await fill('#assignmentResponse','Written programming project.');
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);await delay(100);
 check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector("#assignmentResponse").getBoundingClientRect().width>240'),'The student assignment response fits mobile.');
 const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','student-assessment-mobile_lms.png'),Buffer.from(shot.data,'base64'));
 await click('#studentAssessmentSubmit');await answer();await until('document.querySelector("#studentAssessmentStatus").textContent.includes("Submitted.")');await answer();
 check(attempts[2].answers.assignment_response==='Written programming project.','Assignments submit a written response.');
 check(!errors.length,'No browser errors: '+errors.join('\n'));console.log(`Student LMS assessment browser: ${checks} checks passed.`);
}
main().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{
 if(call&&ws?.readyState===WebSocket.OPEN){try{await call('Browser.close');}catch{}}if(ws)ws.close();if(browser&&browser.exitCode===null){await delay(400);if(browser.exitCode===null)browser.kill();}for(const p of pending.values())clearTimeout(p.timer);if(fs.existsSync(route))fs.unlinkSync(route);
 const resolved=path.resolve(profile),allowed=path.resolve(root,'.tmp')+path.sep;if(!resolved.startsWith(allowed)||path.basename(resolved)!==`student-assessments-${tag}_lms`)throw Error('Unexpected browser profile path.');if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
});
