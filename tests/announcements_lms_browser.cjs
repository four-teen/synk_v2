// Real PHP pages and assets, controlled API responses. PHP storage/security tests are separate.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),tag=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const professorRoute=path.join(root,'tmp',`announcement-professor-${tag}_lms.php`),studentRoute=path.join(root,'tmp',`announcement-student-${tag}_lms.php`),profile=path.join(root,'.tmp',`announcements-${tag}_lms`);
const pending=new Map(),errors=[],requests=[];let browser,ws,call,session,checks=0,professorConfig,studentConfig,professorHtml,studentHtml,assessmentHtml,assessmentConfig;
let records=[],readFailure=false,saveConflict=false;
const delay=ms=>new Promise(r=>setTimeout(r,ms)),check=(ok,message)=>{if(!ok)throw Error(message);checks++;},quote=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
async function evaluate(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;}
async function until(expression){for(let i=0;i<120;i++){if(await evaluate(expression))return;await delay(100);}throw Error('Timed out: '+expression+' / '+errors.join(' / '));}
async function click(selector){await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);}
async function fill(selector,value){await evaluate(`(()=>{const n=document.querySelector(${JSON.stringify(selector)});n.value=${JSON.stringify(value)};n.dispatchEvent(new Event('input',{bubbles:true}));})()`);}
async function dialog(confirm=true){await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');await click(confirm?'.swal2-confirm':'.swal2-cancel');await until('!document.querySelector(".swal2-popup:not(.swal2-toast)")&&!synkLmsFeedback.isPending()');}
async function screenshot(name){await evaluate('document.fonts.ready.then(()=>true)');const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp',name),Buffer.from(shot.data,'base64'));}
function api(url,input){
  requests.push({url,...input});
  if(url.includes('/professor/')){
    check(input.csrf_token===professorConfig.csrf_token&&JSON.stringify(input.context)===JSON.stringify(professorConfig.context),'Professor requests preserve CSRF and signed class scope.');
    if(input.action==='save'){
      if(saveConflict){saveConflict=false;return {ok:false,status:409,message:'This announcement changed in another tab. Refresh before continuing.'};}
      const p=input.payload,row=records.find(r=>Number(r.announcement_id)===p.announcement_id);
      if(row){check(p.version===row.version,'Edits use the current version.');Object.assign(row,p,{version:row.version+1});}
      else records.unshift({announcement_id:1,version:1,created_at:'2026-11-13 08:00:00',updated_at:'2026-11-13 08:00:00',...p});
    }
    if(input.action==='archive')records=records.filter(r=>Number(r.announcement_id)!==input.payload.announcement_id);
    return {ok:true,announcements:records};
  }
  check(input.csrf_token===studentConfig.csrf_token&&input.action==='read','Student acknowledgments include the student CSRF token.');
  check(JSON.stringify(input.payload.event_keys)===JSON.stringify(studentConfig.unread_keys),'Only the displayed unread snapshot is acknowledged.');
  if(readFailure){readFailure=false;return {ok:false,status:503,message:'Read status could not be saved.'};}
  return {ok:true,read_keys:input.payload.event_keys};
}
async function main(){
  fs.writeFileSync(professorRoute,`<?php define('SYNK_PROFESSOR_LMS_QA','${token}');require ${quote(path.join(root,'tests/fixtures/professor-class-request_lms.php'))};`);
  fs.writeFileSync(studentRoute,`<?php define('SYNK_PROFESSOR_LMS_QA','${token}');define('SYNK_STUDENT_ANNOUNCEMENTS_QA',true);require ${quote(path.join(root,'tests/fixtures/student-assessment-request_lms.php'))};`);
  for(const [portal,route,query] of [['professor',professorRoute,'case=announcements'],['student',studentRoute,'view=dashboard'],['assessments',studentRoute,'view=assessments']]){
    const response=await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&${query}`),html=await response.text();
    check(response.status===200&&!/Fatal error|Parse error|xdebug-error/.test(html),portal+' page renders without PHP errors: '+html.slice(-500));
    check(response.headers.get('x-qa-core-unchanged')==='yes','Rendering '+portal+' preserves core data.');
    if(portal==='professor'){professorHtml=html;professorConfig=JSON.parse(html.match(/id="announcementConfig" type="application\/json">(.*?)<\/script>/s)[1]);}
    else if(portal==='student'){studentHtml=html;studentConfig=JSON.parse(html.match(/id="studentAnnouncementConfig" type="application\/json">(.*?)<\/script>/s)[1]);}
    else {assessmentHtml=html;assessmentConfig=JSON.parse(html.match(/id="studentAssessmentConfig" type="application\/json">(.*?)<\/script>/s)[1]);}
  }
  check(studentConfig.unread_keys.length===2,'Real student dashboard contains one class notice and one automatic activity notice.');
  const executable=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  if(!executable)throw Error('Edge or Chrome is required.');
  browser=spawn(executable,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
  const socket=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
  ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  let sequence=0;call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error('Browser timeout: '+method));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
  ws.addEventListener('message',event=>{
    const m=JSON.parse(event.data);if(pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}
    if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
    if(m.method==='Fetch.requestPaused')(async()=>{
      const {requestId,request}=m.params,url=request.url;
      let data,isJson=true;
      if(url.includes('/announcements_api_lms.php'))data=api(url,JSON.parse(request.postData));
      else if(url.includes('/student_presence.php'))data={status:'ok',seconds_remaining:900};
      else if(url.includes('/query_student_evaluation_prompt.php'))data={status:'ok',evaluation:{required:false,colleges:[]}};
      else if(url.includes('/assessments_api_lms.php')){
        const input=JSON.parse(request.postData),row={assessment_id:123,grading_period:'final',assessment_type:'assignment',title:'New class project',instructions:'Write your response.',topic:'',status:'published',total_points:100,opens_at:'2099-01-01 08:00:00',due_at:'2099-01-02 17:00:00',time_limit_minutes:null,subject_key:assessmentConfig.subjects[0].subject_key,subject_label:'QA subject',questions:[]};
        data=input.action==='list'?{ok:true,server_time:'2026-11-13 08:00:00',assessments:[row]}:{ok:true,assessment:row,attempt:null,result:null,server_time:'2026-11-13 08:00:00'};
      } else {isJson=false;data=url.includes('/professor/')?professorHtml:url.includes('/assessments_lms.php')?assessmentHtml:studentHtml;}
      const status=data.status&&Number.isInteger(data.status)?data.status:200;
      await call('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:isJson?'application/json':'text/html; charset=utf-8'}],body:Buffer.from(isJson?JSON.stringify(data):data).toString('base64')},session);
    })().catch(error=>errors.push(error.stack));
  });
  const target=await call('Target.createTarget',{url:'about:blank'});session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
  for(const method of ['Page.enable','Runtime.enable'])await call(method,{},session);
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false},session);
  await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost/synk/professor/class_lms.php*',resourceType:'Document'},{urlPattern:'http://localhost/synk/student/index.php',resourceType:'Document'},{urlPattern:'http://localhost/synk/student/assessments_lms.php*',resourceType:'Document'},{urlPattern:'*announcements_api_lms.php'},{urlPattern:'*assessments_api_lms.php'},{urlPattern:'*student_presence.php'},{urlPattern:'*query_student_evaluation_prompt.php'}]},session);
  await call('Page.navigate',{url:'http://localhost/synk/professor/class_lms.php?'+new URLSearchParams(professorConfig.context)},session);
  await until('document.readyState==="complete" && document.querySelector("#announcementList").textContent.includes("No class notices")');
  await fill('#announcementSubject','Notice <img src=x onerror=alert(1)>');await fill('#announcementBody','First line\n<script>window.noticeExecuted=true</script>');await click('#announcementPost');
  await until('document.querySelectorAll(".announcement-notice").length===1 && !document.querySelector("#announcementPost").disabled');
  check(await evaluate('!document.querySelector(".announcement-notice img,.announcement-notice script")&&!window.noticeExecuted&&document.querySelector(".announcement-message").textContent.includes("<script>")'),'Professor preview escapes HTML and preserves plain text.');
  await click('[data-edit="1"]');await fill('#announcementBody','Updated message');saveConflict=true;await click('#announcementPost');await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  check(await evaluate('document.querySelector("#announcementBody").value==="Updated message"'),'A version conflict preserves unsaved text.');await dialog();await click('#announcementPost');
  await until('document.querySelector(".announcement-message").textContent==="Updated message"&&!document.querySelector("#announcementPost").disabled');
  await click('[data-edit="1"]');await fill('#announcementBody','Unsaved edit');await click('#announcementCancel');await dialog(false);
  check(await evaluate('document.querySelector("#announcementBody").value==="Unsaved edit"'),'Canceling discard keeps the editor intact.');await click('#announcementCancel');await dialog();
  for(const width of [1440,390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:1050,deviceScaleFactor:1,mobile:width<600},session);
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Professor announcements fit '+width+'px.');
    if(width!==320)await screenshot(`professor-announcements-${width}_lms.png`);
  }
  await click('[data-archive="1"]');await dialog(false);check(records.length===1,'Canceling removal keeps the notice.');await click('[data-archive="1"]');await dialog();await until('document.querySelector("#announcementList").textContent.includes("No class notices")');check(records.length===0,'Confirmed removal updates the list.');
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1050,deviceScaleFactor:1,mobile:false},session);
  await call('Page.navigate',{url:'http://localhost/synk/student/index.php'},session);
  await until('document.readyState==="complete"&&!!document.querySelector("#studentUpdatesModal")');
  await evaluate(`(()=>{const e=document.createElement('div');e.id='qaBlockingModal';e.className='modal';e.innerHTML='<div class="modal-dialog"><div class="modal-content">Other prompt</div></div>';document.body.append(e);bootstrap.Modal.getOrCreateInstance(e).show();})()`);
  await delay(1000);check(await evaluate('!document.querySelector("#studentUpdatesModal").classList.contains("show")'),'The update alert waits for another open modal.');
  await evaluate('bootstrap.Modal.getInstance(document.querySelector("#qaBlockingModal")).hide()');await until('document.querySelector("#studentUpdatesModal").classList.contains("show")&&!bootstrap.Modal.getInstance(document.querySelector("#studentUpdatesModal"))._isTransitioning');
  check(await evaluate('document.querySelectorAll(".student-update").length===2&&document.querySelectorAll(".student-update-alert-list li").length===2&&!document.querySelector(".student-update img,.student-update script")'),'Student board and alert display both authorized notices safely.');
  readFailure=true;await click('#announcementViewBoard');await until('document.querySelector("#announcementReadStatus").textContent.includes("try again")');
  check(await evaluate('!document.querySelector("#announcementUnreadCount").hidden&&document.querySelector(".student-update").classList.contains("is-unread")'),'A failed read acknowledgment keeps updates unread and offers retry.');
  await click('#announcementMarkRead');await until('document.querySelector("#announcementUnreadCount").hidden');
  check(await evaluate('document.querySelectorAll(".student-update").length===2&&!document.querySelector(".student-update.is-unread")'),'Marking read removes badges while retaining notices.');
  for(const width of [1440,390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:1050,deviceScaleFactor:1,mobile:width<600},session);await evaluate('document.querySelector("#studentAnnouncements").scrollIntoView({block:"start"})');
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Student announcements fit '+width+'px.');if(width!==320)await screenshot(`student-class-updates-${width}_lms.png`);
  }
  await call('Page.navigate',{url:'http://localhost/synk/student/assessments_lms.php?assessment_id=123'},session);
  await until('document.querySelector("#studentAssessmentEditor")&&!document.querySelector("#studentAssessmentEditor").hidden&&!document.querySelector("#studentAssessmentRefresh").disabled');
  check(await evaluate('document.querySelector("#studentAssessmentEditor").textContent.includes("New class project")&&document.querySelector("[data-student-period=final]").getAttribute("aria-pressed")==="true"'),'Activity links open the correct published activity and grading period.');
  check(!errors.length,'No browser errors: '+errors.join(' / '));
  console.log(`LMS announcements browser: ${checks} checks passed, including editing, conflicts, read alerts, activity links and mobile layouts.`);
}
main().catch(error=>{console.error(error.stack);console.error(JSON.stringify({errors,requests:requests.map(r=>r.action)}));process.exitCode=1;}).finally(async()=>{
  if(call&&ws?.readyState===WebSocket.OPEN){try{await call('Browser.close');}catch{}}if(ws)ws.close();
  if(browser&&browser.exitCode===null){await delay(400);if(browser.exitCode===null)browser.kill();}for(const item of pending.values())clearTimeout(item.timer);
  for(const route of [professorRoute,studentRoute])if(fs.existsSync(route))fs.unlinkSync(route);
  const resolved=path.resolve(profile),allowed=path.resolve(root,'.tmp')+path.sep;if(!resolved.startsWith(allowed)||path.basename(resolved)!==`announcements-${tag}_lms`)throw Error('Unexpected browser profile path.');
  if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
});
