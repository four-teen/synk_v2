// Real PHP page/assets; browser submission is intercepted and never deletes records.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),tag=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`evaluation-reset-browser-${tag}.php`),profile=path.join(root,'.tmp',`evaluation-reset-browser-${tag}`);
let browser,ws,call,session,checks=0,posts=0;const pending=new Map(),errors=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function evaluate(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;}
async function until(expression){for(let i=0;i<120;i++){if(await evaluate(expression))return;await delay(100);}throw Error('Timed out: '+expression);}
async function click(selector){await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);}
async function fill(selector,value){await evaluate(`(()=>{const field=document.querySelector(${JSON.stringify(selector)});field.value=${JSON.stringify(value)};field.dispatchEvent(new Event('input',{bubbles:true}));})()`);}
async function main(){
 const php=path.join(root,'tests/fixtures/student-evaluation-reset-request.php').replaceAll('\\','/');fs.writeFileSync(route,`<?php define('SYNK_EVALUATION_RESET_QA','${token}');require '${php}';`);
 const r=await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&case=page`),html=await r.text();
 const state=JSON.parse(decodeURIComponent(r.headers.get('x-qa-reset-state')));check(r.status===200&&state.protected_unchanged&&state.remaining===4&&state.history===0,'Rendering the browser fixture leaves evaluations and protected records unchanged.');
 const exe=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);if(!exe)throw Error('Edge or Chrome is required.');
 browser=spawn(exe,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const address=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',chunk=>{log+=chunk;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(address);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timed out'));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 ws.addEventListener('message',event=>{
  const m=JSON.parse(event.data);if(pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}
  if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Fetch.requestPaused')(async()=>{
   const {requestId,request}=m.params;let body=html;
   if(request.method==='POST'){posts++;const data=new URLSearchParams(request.postData);check(data.get('confirmation')==='DELETE STUDENT EVALUATIONS'&&data.get('confirm_delete')==='1'&&/^[a-f0-9]{64}$/.test(data.get('preview_token'))&&data.get('college_id'),'Only reviewed, confirmed scope reaches the POST.');body='<p id="qaResetPosted">Confirmation intercepted for browser QA.</p>';}
   await call('Fetch.fulfillRequest',{requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from(body).toString('base64')},session);
  })().catch(e=>errors.push(e.stack));
 });
 const target=await call('Target.createTarget',{url:'about:blank'});session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost/synk/administrator/student-evaluation-reset.php*',resourceType:'Document'}]},session);
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
 const navigate=async()=>{await call('Page.navigate',{url:'http://localhost/synk/administrator/student-evaluation-reset.php'},session);await until('document.readyState==="complete"&&!!document.querySelector("#evaluationResetReview")');};
 await navigate();
 check(await evaluate('document.querySelectorAll("#resetCollege option[data-campus]").length===Array.from(document.querySelectorAll("#resetCollege option[data-campus]")).filter(o=>o.dataset.campus===document.querySelector("#resetCampus").value).length&&document.querySelector("#resetCollege").value!==""'),'College choices follow the selected campus.');
 check(await evaluate('document.querySelector("[data-reset-count=students]").textContent==="2"&&document.querySelector("[data-reset-count=records]").textContent==="4"'),'Unique students and evaluation records remain separate on the page.');
 for(const width of [1440,768,390,320]){
  await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600},session);await delay(100);
  await evaluate('document.querySelector("#evaluationResetReview").scrollIntoView({block:"center",behavior:"instant"})');
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth&&[...document.querySelectorAll(".evaluation-reset-filters select")].every(e=>e.getBoundingClientRect().right<=innerWidth)'),'Filters and review fit '+width+'px.');
  await click('[data-reset-review]');await until('document.querySelector("#evaluationResetModal").classList.contains("show")&&!bootstrap.Modal.getInstance(document.querySelector("#evaluationResetModal"))._isTransitioning');
  check(await evaluate('(()=>{const modal=document.querySelector("#evaluationResetModal .modal-content").getBoundingClientRect(),button=document.querySelector("#resetDelete").getBoundingClientRect();return modal.left>=0&&modal.right<=innerWidth&&button.height>=38;})()'),'The confirmation and controls fit '+width+'px.');
  if(width===1440||width===390){const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp',`student-evaluation-reset-${width}.png`),Buffer.from(shot.data,'base64'));}
  await click('#evaluationResetModal [data-bs-dismiss=modal]');await until('!document.querySelector("#evaluationResetModal").classList.contains("show")&&!bootstrap.Modal.getInstance(document.querySelector("#evaluationResetModal"))._isTransitioning');
 }
 check(posts===0,'Opening and canceling review never submits deletion.');
 await evaluate('const year=document.querySelector("#resetYear");year.dispatchEvent(new Event("change",{bubbles:true}));');
 check(await evaluate('document.querySelector("#evaluationResetReview").hidden&&document.querySelector("#resetDelete").disabled'),'Changing a filter invalidates the old visible review.');
 await navigate();await click('[data-reset-review]');await until('!bootstrap.Modal.getInstance(document.querySelector("#evaluationResetModal"))._isTransitioning');
 await click('#resetDelete');check(posts===0,'Empty confirmation and unchecked acknowledgment cannot submit.');
 await fill('#resetConfirmation','DELETE');await click('#resetAcknowledge');await click('#resetDelete');check(posts===0,'An incorrect confirmation phrase cannot submit.');
 await fill('#resetConfirmation','DELETE STUDENT EVALUATIONS');await click('#resetDelete');await until('!!document.querySelector("#qaResetPosted")');
 check(posts===1,'A valid confirmation submits exactly once.');check(errors.length===0,'No browser errors: '+errors.join('\n'));
 console.log(`Administrator evaluation reset browser: ${checks} checks passed. Submission intercepted; no evaluations deleted.`);
}
main().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{
 if(call&&ws?.readyState===WebSocket.OPEN)try{await call('Browser.close');}catch{}if(ws)ws.close();
 if(browser&&browser.exitCode===null){await delay(300);if(browser.exitCode===null)browser.kill();}
 for(const item of pending.values())clearTimeout(item.timer);if(fs.existsSync(route))fs.unlinkSync(route);
 const resolved=path.resolve(profile),allowed=path.resolve(root,'.tmp')+path.sep;
 if(!resolved.startsWith(allowed)||path.basename(resolved)!==`evaluation-reset-browser-${tag}`)throw Error('Unexpected browser profile path.');
 if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
});
