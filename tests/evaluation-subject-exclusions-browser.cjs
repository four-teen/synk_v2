// Headless checks against isolated, rollback-only localhost pages.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`subject-exclusions-browser-${nonce}.php`),profile=path.join(root,'.tmp',`subject-exclusions-browser-${nonce}`);
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
 await call('Page.addScriptToEvaluateOnNewDocument',{source:"for(const component of ['modal','offcanvas']){document.addEventListener('shown.bs.'+component,event=>event.target.dataset.qaReady='1');document.addEventListener('hide.bs.'+component,event=>delete event.target.dataset.qaReady);}"},session);
 const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
 const navigate=async scenario=>{
  await call('Network.clearBrowserCookies',{},session);
  const url=`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=subject_exclusion_${scenario}`;
  await call('Page.navigate',{url},session);
  for(let i=0;i<120;i++){if(await evaluate(`location.href===${JSON.stringify(url)} && document.readyState==='complete'`))return;await delay(100);}
  throw Error('Page load timed out: '+JSON.stringify(await evaluate('({url:location.href,body:document.body.innerText.slice(-1200)})')));
 };
 const resize=async(width,height)=>{await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);await delay(100);};
 const shot=async name=>{const result=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(root,'.tmp',`subject-exclusions-${name}.png`),Buffer.from(result.data,'base64'));};
 const until=async expression=>{for(let i=0;i<150;i++){if(await evaluate(`Boolean(${expression})`))return;await delay(60);}await shot('failure');throw Error('Timed out: '+expression);};
 const click=async selector=>{
  if(selector.startsWith('#subjectExclusionModal '))await until('document.querySelector("#subjectExclusionModal").dataset.qaReady==="1"');
  await evaluate(`document.querySelector(${JSON.stringify(selector)}).scrollIntoView({block:'center',behavior:'instant'})`);await delay(75);
  const point=await evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()`);
  await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point},session);await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point},session);
 };
 const setSearch=async(value,filter='all')=>evaluate(`(()=>{const s=document.querySelector('#subjectExclusionSearch'),f=document.querySelector('#subjectExclusionFilter');s.value=${JSON.stringify(value)};s.dispatchEvent(new Event('input',{bubbles:true}));f.value=${JSON.stringify(filter)};f.dispatchEvent(new Event('change',{bubbles:true}));})()`);
 await resize(1440,1000);await navigate('mixed');
 await until('document.querySelector("#subjectExclusionModal").dataset.qaReady==="1" && document.body.classList.contains("modal-open")');
 check(await evaluate('!document.body.innerText.match(/Fatal error|Parse error|Warning:/)'), 'The exclusion page renders without PHP errors.');
 check(await evaluate('Boolean(document.querySelector("#subjectExclusionModal #subjectExclusionForm"))'), 'The deep-linked subject opens its management form in the modal.');
 check(await evaluate('document.body.innerText.includes("Combined or unverified responses needing review")'), 'Mixed-subject responses are visible for review.');
 check(await evaluate('document.querySelector("#subjectExclusionStatus").value==="1" && !document.querySelector("#excludeMixedResponses")'), 'Mixed responses have no automatic whole-response exclusion control.');
 check(await evaluate('document.querySelector("#subjectExclusionReason").value.includes("PRIVATE_DEAN_REASON <script>private</script>") && !document.querySelector("#subjectExclusionForm script")'), 'Private reasons are treated as text.');
 await shot('desktop-modal');
 await click('#subjectExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');
 check(await evaluate('!new URL(location.href).searchParams.has("subject_id")'), 'Closing a modal returns to the list instead of jumping to a bottom form.');
 check(await evaluate('getComputedStyle(document.querySelector("#dean-mobile-nav")).display==="none" && !document.querySelector("#layout-menu").inert'), 'Desktop keeps the sidebar and hides the mobile footer.');
 await setSearch('introduction');
 check(await evaluate('document.querySelectorAll("[data-subject-row]:not([hidden])").length===1 && document.querySelector("#subjectExclusionCount").textContent==="1 of 4 subjects"'), 'Search matches the subject title and announces the matching count.');
 await setSearch('qa-math101');
 check(await evaluate('document.querySelector("[data-subject-row]:not([hidden])").textContent.includes("QA-MATH101")'), 'Search matches subject codes without case sensitivity.');
 await setSearch('','excluded');
 check(await evaluate('document.querySelectorAll("[data-subject-row]:not([hidden])").length===1'), 'The excluded filter shows the selected subject rule only.');
 await setSearch('','review');
 check(await evaluate('document.querySelectorAll("[data-subject-row]:not([hidden])").length===2'), 'The review filter includes both subjects of a counted mixed response.');
 await setSearch('no matching subject');
 check(await evaluate('!document.querySelector("#subjectExclusionNoMatches").hidden && document.querySelectorAll("[data-subject-row]:not([hidden])").length===0'), 'An unmatched search displays an explicit empty state.');
 await click('#subjectExclusionClear');
 check(await evaluate('document.querySelectorAll("[data-subject-row]:not([hidden])").length===4 && !new URL(location.href).searchParams.has("search")'), 'Clear restores the list and clears the saved search query.');
 await shot('desktop');
 await click('[data-manage-subject="2"]');await until('document.querySelector("#subjectExclusionModal.show")');
 check(await evaluate('document.querySelector("#subjectExclusionSubject").textContent.includes("QA-MATH101") && document.querySelector("#subjectExclusionStatus").value==="0" && document.querySelector("#subjectExclusionForm").elements.version.value==="0"'), 'Managing another row loads that subject and its own rule version.');
 await click('#subjectExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');

 // Mock only browser POST responses. Real persistence/authorization has separate
 // rollback suites; a browser must never POST fixture IDs to the live controller.
 let mode='validation',posts=0,lastPost='',successHtml='',redirectResponse=false;
 intercept=async event=>{
  if(event.request.method==='POST'){
   posts++;lastPost=event.request.postData||'';
   if(mode==='network')return call('Fetch.failRequest',{requestId:event.requestId,errorReason:'Failed'},session);
   if(mode==='validation')return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:422,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from('<div data-subject-form-error>These subject rules changed. Reload before saving.</div>').toString('base64')},session);
   await delay(300);redirectResponse=true;const url=new URL(event.request.url);url.searchParams.set('saved','1');
   return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:302,responseHeaders:[{name:'Location',value:url.href}]},session);
  }
  if(redirectResponse){redirectResponse=false;return call('Fetch.fulfillRequest',{requestId:event.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from(successHtml).toString('base64')},session);}
  return call('Fetch.continueRequest',{requestId:event.requestId},session);
 };
 await call('Fetch.enable',{patterns:[{urlPattern:`*${path.basename(route)}*`,requestStage:'Request'}]},session);
 await setSearch('QA-CS101');await evaluate('window.qaPageIdentity="same-page";window.scrollTo(0,250)');
 await click('[data-manage-subject="1"]');await until('document.querySelector("#subjectExclusionModal.show")');
 await evaluate('document.querySelector("#subjectExclusionReason").value=""');
 await click('#subjectExclusionSave');
 check(posts===0,'A missing reason cannot submit the form.');
 await evaluate('document.querySelector("#subjectExclusionReason").value="Reviewed test rule";document.querySelector("#subjectExclusionStatus").value="0"');
 await click('#subjectExclusionSave');await until('!document.querySelector("#subjectExclusionFormError").hidden');
 check(await evaluate('document.querySelector("#subjectExclusionModal").classList.contains("show") && document.querySelector("#subjectExclusionFormError").textContent.includes("Reload before saving") && document.querySelector("#subjectExclusionReason").value==="Reviewed test rule"'), 'Server validation errors stay in the modal without losing the entered reason.');
 check(posts===1 && lastPost.includes('name="csrf_token"') && lastPost.includes('name="version"') && lastPost.includes('name="subject_id"'), 'The existing scoped and CSRF-protected form fields are submitted.');
 successHtml=await evaluate(`(()=>{const d=document.cloneNode(true),data=d.querySelector('#subjectExclusionData'),values=JSON.parse(data.textContent);values['1'].excluded=0;values['1'].version=2;values['1'].reason='Reviewed test rule';data.textContent=JSON.stringify(values).replaceAll('<',String.fromCharCode(92)+'u003c');const row=d.querySelector('[data-manage-subject="1"]').closest('tr');row.dataset.rule='included';const badge=row.querySelector('[data-label="Rule"] .badge');badge.textContent='Included';badge.className='badge bg-label-success';d.querySelectorAll('[data-subject-row]').forEach(r=>{r.dataset.reviewCount='0';r.querySelector('[data-label="Needs Review (Counted)"]').textContent='0';});[...d.querySelectorAll('section h5')].find(h=>h.textContent==='Combined or unverified responses needing review')?.closest('section').remove();const notice=d.createElement('div');notice.setAttribute('data-subject-save-success','');notice.setAttribute('role','status');notice.textContent='Subject exclusion saved.';d.querySelector('#evaluationSubjectExclusions').prepend(notice);return '<!DOCTYPE html>'+d.documentElement.outerHTML;})()`);
 mode='success';await click('#subjectExclusionSave');
 await evaluate('document.querySelector("#subjectExclusionForm").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');
 await until('!document.body.classList.contains("modal-open") && document.querySelector("[data-subject-save-success]")');
 check(posts===2,'Repeated submit events during saving create just one request.');
 check(await evaluate('window.qaPageIdentity==="same-page" && document.querySelector("#subjectExclusionSearch").value==="QA-CS101" && document.querySelectorAll("[data-subject-row]:not([hidden])").length===1'), 'Successful saving refreshes the list without navigating or losing the search.');
 await click('[data-manage-subject="1"]');await until('document.querySelector("#subjectExclusionModal.show")');
 check(await evaluate('document.querySelector("#subjectExclusionForm").elements.version.value==="2" && document.querySelector("#subjectExclusionStatus").value==="0" && document.querySelector("#subjectExclusionReason").value==="Reviewed test rule"'), 'Reopening reads the refreshed rule version and saved reason.');
 mode='network';await click('#subjectExclusionSave');await until('!document.querySelector("#subjectExclusionFormError").hidden');
 check(await evaluate('document.querySelector("#subjectExclusionSave").disabled && !document.querySelector("#subjectExclusionReload").hidden'), 'An uncertain save requires a reload before retrying in the same modal.');
 await click('#subjectExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');
 await call('Fetch.disable',{},session);await setSearch('');

 for(const [width,height,label] of [[768,1024,'tablet'],[390,844,'mobile'],[320,720,'small-mobile']]){
  await resize(width,height);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'), `${label}: the page fits the viewport.`);
  check(await evaluate('getComputedStyle(document.querySelector("#subjectExclusionList thead")).display==="none" && getComputedStyle(document.querySelector("#dean-mobile-nav")).display==="grid" && document.querySelectorAll("#dean-mobile-nav>a,#dean-mobile-nav>button").length===5'), `${label}: subjects use cards and the footer has five destinations.`);
  check(await evaluate('document.querySelector("#dean-mobile-nav [aria-current=page]").textContent.includes("Evaluation") && document.querySelector("#layout-menu").inert'), `${label}: the correct navigation item is active and hidden sidebar controls are inert.`);
  if(width<576)check(await evaluate('document.querySelector("#subjectExclusionSearch").getBoundingClientRect().width>=innerWidth-110'), `${label}: search uses the full card width.`);
  await shot(label+'-list');
  await click('[data-manage-subject="1"]');await until('document.querySelector("#subjectExclusionModal").dataset.qaReady==="1"');
  check(await evaluate('(()=>{const r=document.querySelector("#subjectExclusionStatus").getBoundingClientRect();return r.width>200&&r.left>=0&&r.right<=innerWidth;})()'), `${label}: the subject controls fit the viewport.`);
  check(await evaluate('document.querySelector("#subjectExclusionReason").maxLength===1000 && document.querySelector("#subjectExclusionReason").required'), `${label}: a reason is required and length limited.`);
  check(await evaluate('getComputedStyle(document.querySelector("#dean-mobile-nav")).visibility==="hidden" && document.querySelector("#dean-mobile-nav").inert'), `${label}: the footer cannot cover or receive focus behind a modal.`);
  check(await evaluate('(()=>{const r=document.querySelector("#subjectExclusionModal .modal-footer").getBoundingClientRect();return r.bottom<=innerHeight+1&&r.top>=0;})()'), `${label}: modal actions remain in view.`);
  if(width<576){const bounds=await evaluate('(()=>{const r=document.querySelector("#subjectExclusionModal .modal-content").getBoundingClientRect();return {left:r.left,top:r.top,width:r.width,height:r.height,viewportWidth:innerWidth,viewportHeight:innerHeight};})()');check(Math.abs(bounds.left)<1&&Math.abs(bounds.width-bounds.viewportWidth)<1&&Math.abs(bounds.top)<1&&Math.abs(bounds.height-bounds.viewportHeight)<1, `${label}: the management modal fills the phone viewport: ${JSON.stringify(bounds)}`);}
  await shot(label);
  if(label==='mobile'){
   await resize(390,440);
   check(await evaluate('(()=>{const r=document.querySelector("#subjectExclusionModal .modal-footer").getBoundingClientRect();return r.bottom<=innerHeight+1&&r.top>=0;})()'), 'A reduced keyboard-sized viewport keeps modal actions visible.');
   await resize(width,height);
  }
  await click('#subjectExclusionModal [data-bs-dismiss="modal"]');await until('!document.body.classList.contains("modal-open")');
 }
 await click('#dean-mobile-more-toggle');await until('document.querySelector("#dean-mobile-more").dataset.qaReady==="1"');
 check(await evaluate('document.querySelector("#dean-mobile-more-toggle").getAttribute("aria-expanded")==="true" && [...document.querySelectorAll("#dean-mobile-more a")].some(link=>link.getAttribute("href")==="faculty-workload.php")'), 'More opens the remaining Dean pages and retains the existing Faculty Workload destination.');
 await shot('mobile-more');
 await resize(1440,1000);await until('!document.querySelector("#dean-mobile-more.show")');
 check(await evaluate('!document.querySelector("#layout-menu").inert'), 'Returning to desktop closes the drawer and restores sidebar access.');
 await resize(390,844);await navigate('faculty_report');
 check(await evaluate('Boolean(document.querySelector("#dean-mobile-nav")) && document.querySelector("#dean-mobile-nav [aria-current=page]").textContent.includes("Evaluation")'), 'Other Dean pages use the same footer navigation.');
 await navigate('student_normal');
 check(await evaluate('document.querySelectorAll("[data-faculty-evaluation-card]").length===2 && document.querySelectorAll("[name^=ratings]").length===30'), 'Students can evaluate both teachers with all questions.');
 check(await evaluate('!document.body.innerText.includes("PRIVATE_DEAN_REASON") && !document.querySelector("#subjectExclusionForm")'), 'Exclusion reasons and controls do not appear to students.');
 check(await evaluate('!document.querySelector("#dean-mobile-nav")'), 'Dean navigation is not injected into the student portal.');
 check(errors.length===0,'There are no browser script errors: '+errors.join('\n'));
 console.log(`Evaluation subject exclusions browser: ${checks} checks passed. Screenshots saved in .tmp.`);
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
