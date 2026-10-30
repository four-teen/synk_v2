// Headless UI checks on rollback-only fixtures; clear requests are intercepted.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`dean-evaluation-browser-${nonce}.php`),profile=path.join(root,'.tmp',`dean-evaluation-browser-${nonce}`);
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));let browser,socket,checks=0;const errors=[];
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.writeFileSync(route,`<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root,'tests/fixtures/student-portal-request.php').replaceAll('\\','/')}';`);
 const executable=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(executable,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const address=await new Promise((resolve,reject)=>{let output='';const timeout=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{output+=data;const m=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timeout);resolve(m[1]);}});browser.on('error',reject);});
 socket=new WebSocket(address);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 socket.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timeout);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);});
 const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timeout=setTimeout(()=>{pending.delete(id);reject(Error(method+' timed out.'));},15000);pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Network.enable',{},session);await call('Network.setBlockedURLs',{urls:['https://*']},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate(`Boolean(${expression})`))return;await delay(75);}await shot('failure');throw Error('Timed out: '+expression+' '+JSON.stringify(await evaluate('({width:innerWidth,height:innerHeight,modal:document.querySelector("#deanClearEvaluationModal")?.className,status:document.querySelector("#deanClearEvaluationStatus")?.textContent,calls:window.qaCalls,errors:'+JSON.stringify(errors)+'})')));};
 const click=async selector=>{
  await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',inline:'center',behavior:'instant'})`);await delay(100);
  const point=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)}),r=e.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
  await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point},session);await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point},session);
 };
 const resize=async(width,height)=>{await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);await delay(120);};
 const shot=async name=>{const r=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(root,'.tmp',`dean-evaluations-${name}.png`),Buffer.from(r.data,'base64'));};
 const url=`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=dean_faculty_evaluations_clear_browser`;
 await call('Page.navigate',{url},session);await until(`location.href===${JSON.stringify(url)} && document.readyState==='complete'`);
 check(await evaluate('!document.body.innerText.match(/Fatal error|Parse error|Warning:/)'), 'The real Dean page renders without PHP errors.');
 check(await evaluate('document.querySelectorAll("[data-clear-evaluation]").length===2 && document.querySelectorAll("[data-show-evaluation]").length===2'), 'Each faculty retains Show Evaluation and a separate Clear Evaluation button.');
 check(await evaluate('document.querySelector(".dean-evaluation-submission-count").getAttribute("aria-label")==="2 submitted, 1 not submitted"'), 'Draft students remain in the not-submitted count.');
 check(await evaluate('document.querySelector(".dean-evaluation-subject-count").textContent==="2"'),'Repeated enrollment rows for the same subject count once.');
 check(await evaluate('getComputedStyle(document.querySelector(".dean-evaluation-submitted")).color==="rgb(29, 120, 83)" && getComputedStyle(document.querySelector(".dean-evaluation-pending")).color==="rgb(189, 53, 72)"'), 'Submitted is green and not submitted is red.');
 await evaluate(`window.qaCalls=[];window.qaPending=[];window.qaMode='ready';window.qaRealFetch=window.fetch;
 window.fetch=(url,options)=>{if(!String(url).includes('evaluation-clear.php'))return window.qaRealFetch(url,options);const values=Object.fromEntries(options.body);qaCalls.push(values);return new Promise((resolve,reject)=>{
  const send=()=>resolve({ok:true,json:async()=>({ok:true,preview_token:'a'.repeat(64),target:{faculty_name:document.querySelector('[data-clear-evaluation]').dataset.facultyName,college_name:'Evaluation QA College',term_text:'2026-2027 / 1st Semester',instrument_label:'All instruments'},counts:{submitted:2,drafts:1}})});
  if(values.action==='preview'){if(qaMode==='slow')qaPending.push(send);else if(qaMode==='error')resolve({ok:false,json:async()=>({ok:false,message:'Preview unavailable. Try again.'})});else send();}
  else if(qaMode==='timeout')options.signal.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true});
  else if(qaMode==='success')resolve({ok:true,json:async()=>({ok:true})});
  else qaPending.push(()=>resolve({ok:false,json:async()=>({ok:false,message:'These evaluations changed after preview. Nothing was cleared.'})}));
 });};`);
 for(const [width,height] of [[1440,1000],[1200,900],[992,900],[768,1000],[390,844],[320,568],[844,390]]){
  await resize(width,height);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Faculty list fits ${width}px.`);
  if(width===1440 || width===390){await evaluate('document.querySelector("#deanEvaluationFacultyList").scrollIntoView({block:"start",behavior:"instant"})');await delay(100);await shot('list-'+width);}
  await click('[data-clear-evaluation]');await until('document.querySelector("#deanClearEvaluationModal.show") && !document.querySelector("#deanClearEvaluationConfirm").disabled');await delay(200);
  check(await evaluate('document.querySelector("#deanClearEvaluationSubmitted").textContent==="2" && document.querySelector("#deanClearEvaluationDrafts").textContent==="1"'),`Confirmation displays submitted and draft counts at ${width}px.`);
  check(await evaluate(`['#deanClearEvaluationConfirm','#deanClearEvaluationModal .modal-footer [data-bs-dismiss]','#deanClearEvaluationModal .btn-close'].every(s=>{const e=document.querySelector(s),r=e.getBoundingClientRect();return r.top>=0 && r.bottom<=innerHeight+1 && r.left>=0 && r.right<=innerWidth+1 && e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));})`),`Confirm, Cancel and Close stay visible and clickable at ${width}×${height}.`);
  check(await evaluate('document.querySelector("#deanClearEvaluationModal").contains(document.activeElement)'),`Keyboard focus stays in the confirmation at ${width}px.`);
  if(width===1440 || width===390 || width===320)await shot('modal-'+width);
  await click('#deanClearEvaluationModal .modal-footer [data-bs-dismiss]');await until('!document.querySelector("#deanClearEvaluationModal.show")');await delay(180);
 }
 check(await evaluate('qaCalls.every(c=>c.action==="preview")'), 'Opening and cancelling the review never clears evaluations.');
 await resize(390,844);await evaluate('qaMode="slow"');await click('[data-clear-evaluation]');await until('qaPending.length===1 && document.querySelector("#deanClearEvaluationModal.show")');await delay(350);
 check(await evaluate('document.querySelector("#deanClearEvaluationConfirm").disabled && document.querySelector("#deanClearEvaluationPreview").hidden'),'Confirmation is disabled until fresh preview counts arrive.');
 await click('#deanClearEvaluationModal .btn-close');await until('!document.querySelector("#deanClearEvaluationModal.show")');await evaluate('qaPending.shift()()');await delay(350);
 check(await evaluate('document.querySelector("#deanClearEvaluationConfirm").disabled'),'A cancelled preview cannot re-enable clearing after its response arrives.');
 await evaluate('qaMode="error"');await click('[data-clear-evaluation]');await until('!document.querySelector("#deanClearEvaluationRetry").hidden && document.querySelector("#deanClearEvaluationModal.show")');await delay(350);
 check(await evaluate('document.querySelector("#deanClearEvaluationStatus").getAttribute("role")==="alert" && document.querySelector("#deanClearEvaluationConfirm").disabled'),'Preview errors are visible inside the modal and block clearing.');
 await evaluate('qaMode="ready"');await click('#deanClearEvaluationRetry');await until('!document.querySelector("#deanClearEvaluationConfirm").disabled');
 await click('#deanClearEvaluationConfirm');await until('document.querySelector("#deanClearEvaluationModal").getAttribute("aria-busy")==="true"');
 await evaluate('document.querySelector("#deanClearEvaluationConfirm").click();document.querySelector("#deanClearEvaluationConfirm").click()');
 check(await evaluate('qaCalls.filter(c=>c.action==="confirm").length===1 && document.querySelector("#deanClearEvaluationConfirm").disabled'),'Repeated clicks issue only one confirmation and show pending state.');
 await click('#deanClearEvaluationModal .modal-footer [data-bs-dismiss]');
 check(await evaluate('Boolean(document.querySelector("#deanClearEvaluationModal.show"))'),'The review remains open while a clear request is pending.');
 await evaluate('qaPending.shift()()');await until('!document.querySelector("#deanClearEvaluationRetry").hidden');
 check(await evaluate('document.querySelector("#deanClearEvaluationStatus").textContent.includes("Nothing was cleared") && document.querySelector("#deanClearEvaluationConfirm").disabled'),'Stale confirmation errors require a new preview.');
 await click('#deanClearEvaluationRetry');await until('!document.querySelector("#deanClearEvaluationConfirm").disabled');
 await evaluate('qaMode="timeout";window.qaSetTimeout=window.setTimeout;window.setTimeout=(fn,ms,...args)=>qaSetTimeout(fn,ms===30000?100:ms,...args)');
 await click('#deanClearEvaluationConfirm');await until('document.querySelector("#deanClearEvaluationStatus").textContent.includes("could not be confirmed")');
 check(await evaluate('document.querySelector("#deanClearEvaluationConfirm").disabled && !document.querySelector("#deanClearEvaluationRetry").hidden && !document.querySelector("#deanClearEvaluationModal").hasAttribute("aria-busy")'),'A network timeout releases pending state and requires checking latest records.');
 await evaluate('qaMode="ready";window.setTimeout=qaSetTimeout;history.replaceState(null,"",location.href+"&faculty_id=123&instrument_version=all&search=keep")');
 await click('#deanClearEvaluationRetry');await until('!document.querySelector("#deanClearEvaluationConfirm").disabled');await evaluate('qaMode="success"');
 await click('#deanClearEvaluationConfirm');await until('location.hash==="#deanEvaluationFacultyList" && document.readyState==="complete"');
 check(await evaluate('!new URL(location.href).searchParams.has("faculty_id") && new URL(location.href).searchParams.get("instrument_version")==="all" && new URL(location.href).searchParams.get("search")==="keep"'),'Successful clearing refreshes the list and preserves the selected filters.');
 check(errors.length===0,'No browser JavaScript errors: '+errors.join('; '));
 console.log(`College Dean evaluation browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
 if(socket?.readyState===WebSocket.OPEN)socket.close();if(browser){browser.kill();await delay(700);}if(fs.existsSync(route))fs.unlinkSync(route);
 if(fs.existsSync(profile)){
  const quote=v=>"'"+v.replaceAll("'","''")+"'";
  const cleanup=`$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root,'.tmp'))}).Path; $profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Profile cleanup is outside the workspace.' }; Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;
  const result=spawnSync('powershell.exe',['-NoProfile','-Command',cleanup],{windowsHide:true,encoding:'utf8'});if(result.status!==0){console.error(result.stderr||'Browser profile cleanup failed.');process.exitCode=1;}
 }
});
