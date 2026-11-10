// Protected localhost fixtures roll back every database write. Browser POSTs are intercepted.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-exclusions-browser-${nonce}.php`),profile=path.join(root,'.tmp',`student-exclusions-browser-${nonce}`);
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let browser,socket,checks=0;const errors=[];
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.writeFileSync(route,`<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root,'tests/fixtures/student-portal-request.php').replaceAll('\\','/')}';`);
 const executable=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 if(!executable)throw Error('Chrome or Edge is required.');
 browser=spawn(executable,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const address=await new Promise((resolve,reject)=>{let output='';const timeout=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{output+=data;const match=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timeout);resolve(match[1]);}});browser.on('error',reject);});
 socket=new WebSocket(address);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
 let sequence=0,intercept;const pending=new Map();
 socket.addEventListener('message',event=>{const message=JSON.parse(event.data);if(message.id&&pending.has(message.id)){const item=pending.get(message.id);pending.delete(message.id);clearTimeout(item.timeout);message.error?item.reject(Error(message.error.message)):item.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);if(message.method==='Fetch.requestPaused')intercept?.(message.params).catch(error=>errors.push(error.stack));});
 const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timeout=setTimeout(()=>{pending.delete(id);reject(Error(method+' timed out.'));},20000);pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Network.enable',{},session);await call('Network.setBlockedURLs',{urls:['https://*','*student_presence.php*']},session);
 await call('Page.addScriptToEvaluateOnNewDocument',{source:"document.addEventListener('shown.bs.modal',event=>event.target.dataset.qaReady='1');document.addEventListener('hide.bs.modal',event=>delete event.target.dataset.qaReady);"},session);
 const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
 const shot=async name=>{const result=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(root,'.tmp',`student-exclusions-${name}.png`),Buffer.from(result.data,'base64'));};
 const until=async expression=>{for(let i=0;i<150;i++){if(await evaluate(`Boolean(${expression})`))return;await delay(60);}await shot('failure');throw Error('Timed out: '+expression);};
 const navigate=async scenario=>{
  await call('Network.clearBrowserCookies',{},session);
  const url=`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=student_exclusion_${scenario}`;
  await call('Page.navigate',{url},session);await until(`location.href===${JSON.stringify(url)} && document.readyState==='complete'`);
 };
 const resize=async(width,height)=>{await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);await delay(100);};
 const click=async selector=>{
  if(selector.startsWith('#studentExclusionModal '))await until('document.querySelector("#studentExclusionModal").dataset.qaReady==="1"');
  await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);await delay(75);
  const point=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
  await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point},session);await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point},session);
 };
 const setSearch=async(value,filter='all')=>evaluate(`(()=>{const s=document.querySelector('#studentExclusionSearch'),f=document.querySelector('#studentExclusionFilter');s.value=${JSON.stringify(value)};s.dispatchEvent(new Event('input',{bubbles:true}));f.value=${JSON.stringify(filter)};f.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await resize(1440,1000);await navigate('get');
 await until('document.querySelector("#studentExclusionModal").dataset.qaReady==="1"');
 const studentId=await evaluate('document.querySelector("#studentExclusionForm").elements.student_id.value');
 const manage=`[data-manage-student="${studentId}"]`;
 check(await evaluate('!document.body.innerText.match(/Fatal error|Parse error|Warning:/)'), 'The student management page renders without server errors.');
 check(await evaluate('document.querySelector("#studentExclusionStatus").value==="1" && document.querySelector("#studentExclusionReason").value.includes("PRIVATE_STUDENT_REASON")'), 'A deep link opens the correct current student rule.');
 check(await evaluate('!document.querySelector("#evaluationStudentExclusions").innerText.includes("Preserved original student feedback")'), 'The named student list does not reveal submitted feedback.');
 await click('#studentExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');
 await shot('desktop-list');
 check(await evaluate('document.querySelectorAll("[data-student-row]").length===2'), 'The list includes both students in the college and term.');
 await setSearch('Alex','excluded');
 check(await evaluate('document.querySelectorAll("[data-student-row]:not([hidden])").length===1 && document.querySelector("#studentExclusionCount").textContent==="1 of 2 students"'), 'Name search combines with the student-rule filter.');
 const number=await evaluate('document.querySelector("[data-student-row]:not([hidden])").dataset.studentLabel.split(" - ").at(-1)');
 await setSearch(number);check(await evaluate('document.querySelectorAll("[data-student-row]:not([hidden])").length===1'), 'Student number search selects the matching student.');
 await setSearch('no student matches');check(await evaluate('!document.querySelector("#studentExclusionNoMatches").hidden'), 'An unmatched search displays the empty result message.');
 await click('#studentExclusionClear');check(await evaluate('document.querySelectorAll("[data-student-row]:not([hidden])").length===2'), 'Clear restores the full scoped list.');

 let mode='validation',posts=0,lastPost='',successHtml='',redirectResponse=false;
 intercept=async event=>{
  if(event.request.method==='POST'){
   posts++;lastPost=event.request.postData||'';
   if(mode==='network')return call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'},session);
   if(mode==='validation')return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:422,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from('<div data-student-form-error>This student rule changed. Reload before saving.</div>').toString('base64')},session);
   await delay(300);redirectResponse=true;const url=new URL(event.request.url);url.searchParams.set('saved','1');
   return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:302,responseHeaders:[{name:'Location',value:url.href}]},session);
  }
  if(redirectResponse){redirectResponse=false;return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from(successHtml).toString('base64')},session);}
  return call('Fetch.continueRequest',{requestId:event.requestId},session);
 };
 await call('Fetch.enable',{patterns:[{urlPattern:`*${path.basename(route)}*`,requestStage:'Request'}]},session);
 await setSearch('Alex');await evaluate('window.qaPageIdentity="same-page"');await click(manage);
 await evaluate('document.querySelector("#studentExclusionReason").value=""');await click('#studentExclusionSave');
 check(posts===0,'A missing reason cannot submit the student form.');
 await evaluate('document.querySelector("#studentExclusionReason").value="Reviewed student rule";document.querySelector("#studentExclusionStatus").value="0"');
 await click('#studentExclusionSave');await until('!document.querySelector("#studentExclusionFormError").hidden');
 check(await evaluate('document.querySelector("#studentExclusionModal").classList.contains("show") && document.querySelector("#studentExclusionFormError").textContent.includes("Reload before saving") && document.querySelector("#studentExclusionReason").value==="Reviewed student rule"'), 'Server errors remain inside the dialog and preserve the entered reason.');
 check(posts===1 && ['csrf_token','version','student_id','college_id','ay_id','semester'].every(key=>lastPost.includes(`name="${key}"`)), 'The form submits the student, college, term, CSRF token and rule version.');
 successHtml=await evaluate(`(()=>{const d=document.cloneNode(true),data=d.querySelector('#studentExclusionData'),values=JSON.parse(data.textContent);values[${JSON.stringify(studentId)}].excluded=0;values[${JSON.stringify(studentId)}].version=2;values[${JSON.stringify(studentId)}].reason='Reviewed student rule';data.textContent=JSON.stringify(values).replaceAll('<',String.fromCharCode(92)+'u003c');const row=d.querySelector(${JSON.stringify(manage)}).closest('tr');row.dataset.rule='included';row.querySelector('[data-label="Student Rule"] .badge').textContent='Included';row.querySelector('[data-label="Excluded Responses"]').textContent='0';const notice=d.createElement('div');notice.setAttribute('data-student-save-success','');notice.setAttribute('role','status');notice.textContent='Student exclusion saved.';d.querySelector('#evaluationStudentExclusions').prepend(notice);return '<!DOCTYPE html>'+d.documentElement.outerHTML;})()`);
 mode='success';await click('#studentExclusionSave');await evaluate('document.querySelector("#studentExclusionForm").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');
 await until('!document.body.classList.contains("modal-open") && document.querySelector("[data-student-save-success]")');
 check(posts===2,'Duplicate submit events during saving create one request.');
 check(await evaluate('window.qaPageIdentity==="same-page" && document.querySelector("#studentExclusionSearch").value==="Alex"'), 'Successful saving refreshes the list and retains the search without navigation.');
 await click(manage);await until('document.querySelector("#studentExclusionModal").dataset.qaReady==="1"');
 check(await evaluate('document.querySelector("#studentExclusionForm").elements.version.value==="2" && document.querySelector("#studentExclusionStatus").value==="0"'), 'Reopening the student reads the refreshed version and rule.');
 mode='network';await click('#studentExclusionSave');await until('!document.querySelector("#studentExclusionFormError").hidden');
 check(await evaluate('document.querySelector("#studentExclusionSave").disabled && !document.querySelector("#studentExclusionReload").hidden'), 'Uncertain saves require reloading the current rule before retry.');
 await click('#studentExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');await call('Fetch.disable',{},session);await setSearch('');

 for(const [width,height,label] of [[768,1024,'tablet'],[390,844,'mobile'],[320,720,'small-mobile']]){
  await resize(width,height);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2 && getComputedStyle(document.querySelector("#studentExclusionList thead")).display==="none"'), `${label}: the student cards fit the viewport.`);
  check(await evaluate('document.querySelector("#dean-mobile-nav [aria-current=page]").textContent.includes("Evaluation")'), `${label}: Evaluation remains the active mobile destination.`);
  await shot(label+'-list');await click(manage);await until('document.querySelector("#studentExclusionModal").dataset.qaReady==="1"');
  check(await evaluate('(()=>{const r=document.querySelector("#studentExclusionModal .modal-footer").getBoundingClientRect();return r.bottom<=innerHeight+1&&r.top>=0;})()'), `${label}: save and cancel remain visible.`);
  check(await evaluate('document.querySelector("#studentExclusionReason").required && document.querySelector("#studentExclusionReason").maxLength===1000 && getComputedStyle(document.querySelector("#dean-mobile-nav")).visibility==="hidden"'), `${label}: the reason is required and navigation stays behind the dialog.`);
  if(width<576)check(await evaluate('(()=>{const r=document.querySelector("#studentExclusionModal .modal-content").getBoundingClientRect();return Math.abs(r.left)<1&&Math.abs(r.top)<1&&Math.abs(r.width-innerWidth)<1&&Math.abs(r.height-innerHeight)<1;})()'), `${label}: the dialog fills the phone viewport.`);
  await shot(label+'-dialog');await click('#studentExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');
 }
 await navigate('student_normal');
 check(await evaluate('document.querySelectorAll("[data-faculty-evaluation-card]").length===2 && document.querySelectorAll("[name^=ratings]").length===30'), 'Excluded students can still evaluate every linked teacher.');
 check(await evaluate('!document.body.innerText.includes("PRIVATE_STUDENT_REASON") && !document.querySelector("#studentExclusionForm")'), 'Exclusion reasons and controls remain private from students.');
 check(errors.length===0,'No browser script errors: '+errors.join('\n'));
 console.log(`Evaluation student exclusions browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
 if(socket?.readyState===WebSocket.OPEN)socket.close();
 if(browser){browser.kill();await delay(700);}
 if(fs.existsSync(route))fs.unlinkSync(route);
 if(fs.existsSync(profile)){
  const quote=value=>"'"+value.replaceAll("'","''")+"'";
  const cleanup=`$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root,'.tmp'))}).Path; $profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Profile cleanup is outside the workspace.' }; Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;
  const result=spawnSync('powershell.exe',['-NoProfile','-Command',cleanup],{windowsHide:true,encoding:'utf8'});if(result.status!==0){console.error(result.stderr||'Browser profile cleanup failed.');process.exitCode=1;}
 }
});
