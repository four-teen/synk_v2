// Real pages on protected rollback-only fixtures; all browser POSTs are intercepted.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`supervisor-browser-${nonce}.php`),profile=path.join(root,'.tmp',`supervisor-browser-${nonce}`);
let browser,socket,checks=0;const errors=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.writeFileSync(route,`<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root,'tests/fixtures/student-portal-request.php').replaceAll('\\','/')}';`);
 const executable=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(executable,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const address=await new Promise((resolve,reject)=>{let output='';const timeout=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{output+=data;const m=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timeout);resolve(m[1]);}});browser.on('error',reject);});
 socket=new WebSocket(address);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
 let sequence=0,intercept;const pending=new Map();
 socket.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.timeout);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);if(m.method==='Fetch.requestPaused')intercept?.(m.params).catch(error=>errors.push(error.stack));});
 const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timeout=setTimeout(()=>{pending.delete(id);reject(Error(method+' timed out.'));},20000);pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Network.enable',{},session);await call('Network.setBlockedURLs',{urls:['https://*','*student_presence.php*']},session);
 await call('Page.addScriptToEvaluateOnNewDocument',{source:"document.addEventListener('shown.bs.modal',event=>event.target.dataset.qaReady='1');document.addEventListener('hide.bs.modal',event=>delete event.target.dataset.qaReady);"},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const shot=async name=>{const r=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(root,'.tmp',`supervisor-${name}.png`),Buffer.from(r.data,'base64'));};
 const until=async expression=>{for(let i=0;i<160;i++){if(await evaluate(`Boolean(${expression})`))return;await delay(75);}await shot('failure');throw Error('Timed out: '+expression+' '+JSON.stringify(await evaluate('({url:location.href,text:document.body.innerText.slice(-1000)})')));};
 const navigate=async scenario=>{await call('Network.clearBrowserCookies',{},session);const url=`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=supervisor_${scenario}`;await call('Page.navigate',{url},session);await until(`location.href===${JSON.stringify(url)} && document.readyState==='complete'`);check(await evaluate('!document.body.innerText.match(/Fatal error|Parse error|Warning:/)'), 'Page renders without PHP errors: '+scenario);};
 const resize=async(width,height)=>{await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);await delay(120);};
 const click=async selector=>{await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);await delay(80);const point=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point},session);await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point},session);};
 let posts=0,lastPayload='';
 intercept=async event=>{
  if(event.request.method!=='POST')return call('Fetch.continueRequest',{requestId:event.requestId},session);
  posts++;lastPayload=event.request.postData||'';await delay(250);
  return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from('<!doctype html><title>Intercepted test submission</title><p id="qaSaved">Test submission captured safely</p>').toString('base64')},session);
 };
 await call('Fetch.enable',{patterns:[{urlPattern:'*',requestStage:'Request'}]},session);
 await resize(1440,1000);await navigate('chair_get');await until('document.querySelector("#sefEvaluationModal").dataset.qaReady==="1"');
 check(await evaluate('document.querySelectorAll("#sefEvaluationForm select[name^=ratings]").length===15 && document.querySelectorAll(".sef-category").length===3'), 'The supervisor tool has 15 ratings in three categories.');
 check(await evaluate('document.querySelectorAll(".sef-question details").length===15 && document.querySelector("#sefSubjectContext").textContent.includes("QA-CS101")'), 'Evidence guidance and the evaluated faculty teaching context are available.');
 check(await evaluate('!document.body.innerText.includes("Supervisor evaluation controls") && document.querySelectorAll("[data-sef-evaluate]").length===2'), 'The chair can evaluate assigned teacher/Dean targets without collection controls.');
 await shot('desktop-form');
 await evaluate('document.querySelectorAll("#sefEvaluationForm select[name^=ratings]").forEach(select=>select.value="4")');
 await click('#sefEvaluationForm button[type=submit]');
 check(posts===0,'Missing required comments prevent submitting the draft.');
 await evaluate('document.querySelector("#sefComment").value="Complete supervisor feedback"');
 await click('#sefEvaluationForm button[type=submit]');
 await until('document.querySelector("#qaSaved")');
 const saved=new URLSearchParams(lastPayload);
 check(posts===1 && saved.get('action')==='save' && saved.get('ratings[SEF15]')==='4' && saved.get('comment_text')==='Complete supervisor feedback' && Boolean(saved.get('csrf_token')), 'A draft posts every rating, its comment and the protected scope exactly once.');
 await navigate('chair_drafts');await until('document.querySelector("#sefEvaluationModal").dataset.qaReady==="1"');
 check(await evaluate('document.querySelector("#sefRating1").value==="5" && document.querySelector("#sefRating15").value==="4" && document.querySelector("#sefComment").value.includes("PRIVATE_CHAIR_DRAFT")'), 'Reopening a saved draft restores all original values.');
 await click('#sefEvaluationModal [data-bs-dismiss=modal]');await until('!document.body.classList.contains("modal-open")');
 await click('[data-bs-target="#sefFinalReview"]');await until('document.querySelector("#sefFinalReview").dataset.qaReady==="1"');
 check(await evaluate('document.querySelectorAll("#sefFinalForm article").length===2 && !document.querySelector("#sefFinalForm script")'), 'Final review shows the evaluator’s pending faculty and safely rendered comments.');
 await shot('final-review');
 await click('#sefFinalSubmit');check(posts===1,'Final submission requires explicit confirmation.');
 await click('#sefFinalConfirm');await click('#sefFinalSubmit');await until('document.querySelector("#qaSaved")');
 const finalized=new URLSearchParams(lastPayload);
 check(posts===2 && finalized.get('confirm_final')==='1' && [...finalized.keys()].filter(key=>key.startsWith('draft_versions[')).length===2, 'Final submission carries the reviewed draft revisions and confirmation once.');
 await navigate('dean_get');
 check(await evaluate('document.body.innerText.includes("Supervisor evaluation controls") && document.querySelectorAll("[data-sef-evaluate]").length===1'), 'The Dean sees automatic routing and evaluates the Program Chair only.');
 await shot('dean-workspace');
 await navigate('dean_private');check(await evaluate('!document.documentElement.innerHTML.includes("PRIVATE_CHAIR_DRAFT")'), 'Another evaluator’s drafts do not appear anywhere in the Dean HTML.');
 for(const [width,height,label] of [[768,1024,'tablet'],[390,844,'mobile'],[320,720,'small-mobile']]){
  await resize(width,height);await navigate('chair_get');await until('document.querySelector("#sefEvaluationModal").dataset.qaReady==="1"');
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), label+': the page fits the viewport.');
  check(await evaluate('(()=>{const r=document.querySelector("#sefEvaluationForm .modal-footer").getBoundingClientRect();return r.bottom<=innerHeight+1&&r.top>=0;})()'), label+': Cancel and Save Draft remain visible.');
  if(width<576)check(await evaluate('(()=>{const r=document.querySelector("#sefEvaluationForm").getBoundingClientRect();return r.left===0&&Math.abs(r.width-innerWidth)<1&&r.top===0&&Math.abs(r.height-innerHeight)<1;})()'), label+': the long evaluation form fills the phone viewport.');
  await evaluate('document.querySelector("#sefEvaluationModal .modal-body").scrollTop=0');await shot(label+'-form');
  await click('#sefEvaluationModal [data-bs-dismiss=modal]');await until('!document.body.classList.contains("modal-open")');
  check(await evaluate('getComputedStyle(document.querySelector(".sef-table thead")).display==="none" && !document.querySelector("#dean-mobile-nav")'), label+': assignments use cards without injecting Dean controls into the chair portal.');
  await shot(label+'-list');
 }
 await resize(390,844);await navigate('dean_get');
 check(await evaluate('getComputedStyle(document.querySelector("#dean-mobile-nav")).display==="grid" && document.querySelector("#dean-mobile-nav [aria-current=page]").textContent.includes("Evaluation")'), 'Dean mobile navigation recognizes the supervisor workspace.');
 await click('[data-sef-evaluate]');await until('document.querySelector("#sefEvaluationModal").dataset.qaReady==="1"');
 check(await evaluate('getComputedStyle(document.querySelector("#dean-mobile-nav")).visibility==="hidden"'), 'The Dean footer does not cover the evaluation modal.');
 await navigate('chair_results');
 check(await evaluate('document.body.innerText.includes("82.20%") && document.body.innerText.includes("43.80") && document.body.innerText.includes("38.40")'), 'The shared results match the attached sample’s 60/40 totals.');
 await shot('mobile-results');
 await resize(1440,1100);await navigate('dean_report');
 check(await evaluate('document.querySelector("[data-sef-total]").textContent==="82.20%" && document.body.innerText.includes("Student feedback <script>unsafe()</script>") && !document.querySelector("#sefIndividualReport script")'), 'The report combines original student and supervisor feedback as safe text.');
 check(await evaluate('document.querySelector("#sefIndividualReport").textContent.includes("College Dean") && document.querySelectorAll(".sef-category-grid").length===2'), 'The report includes both category breakdowns and approval signature lines.');
 await shot('report');
 await call('Emulation.setEmulatedMedia',{media:'print'},session);
 check(await evaluate('getComputedStyle(document.querySelector("#layout-menu")).display==="none" && getComputedStyle(document.querySelector(".sef-print-tools")).display==="none" && getComputedStyle(document.querySelector("#sefIndividualReport")).display!=="none"'), 'Print mode contains the report without navigation or controls.');
 await shot('report-print');
 await call('Emulation.setEmulatedMedia',{media:''},session);
 await call('Emulation.setEmulatedMedia',{media:''},session);
 await resize(390,844);await navigate('chair_shared_draft');await until('document.querySelector("#sefEvaluationModal").dataset.qaReady==="1"');
 check(await evaluate('document.querySelector("#sefRating1").value==="5" && document.querySelector("#sefComment").value.includes("PRIVATE_CHAIR_DRAFT")'), 'A tied Chair can reopen the shared ratings and comment from the first Chair.');
 await navigate('chair_shared_done');
 check(await evaluate('[...document.querySelectorAll(".sef-table .badge")].some(badge=>badge.textContent.trim()==="Completed") && document.body.textContent.includes("Submitted by") && document.querySelectorAll("[data-sef-evaluate]").length===0'), 'The other tied Chair sees the submitted evaluation as done, without a second rating action.');
 check(await evaluate('!document.querySelector("#sefYear") && !document.querySelector("#sefSemester") && !document.body.innerText.includes("Load Evaluations")'), 'The workspace follows the module term without duplicate term selectors.');
 await shot('shared-completed-mobile');
 check(errors.length===0,'No browser script errors: '+errors.join('\n'));
 console.log(`Supervisor evaluation browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
 if(socket?.readyState===WebSocket.OPEN)socket.close();if(browser){browser.kill();await delay(700);}if(fs.existsSync(route))fs.unlinkSync(route);
 if(fs.existsSync(profile)){
  const quote=value=>"'"+value.replaceAll("'","''")+"'";
  const cleanup=`$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root,'.tmp'))}).Path; $profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Profile cleanup is outside the workspace.' }; Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;
  const result=spawnSync('powershell.exe',['-NoProfile','-Command',cleanup],{windowsHide:true,encoding:'utf8'});if(result.status!==0){console.error(result.stderr||'Browser profile cleanup failed.');process.exitCode=1;}
 }
});
