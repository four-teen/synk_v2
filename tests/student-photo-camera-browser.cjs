// Real local model + simulated camera stream + actual multipart save and public
// JPEG retrieval. Guarded fixtures roll back their database writes; remove all
// retained test photos in finally. No real student's camera/account is used.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-camera-${nonce}.php`),profile=path.join(root,'.tmp',`student-camera-${nonce}`);
const retained=new Set(),errors=[],delay=ms=>new Promise(r=>setTimeout(r,ms));
let browser,ws,call,checks=0;
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.writeFileSync(route,`<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root,'tests/fixtures/student-portal-request.php').replaceAll('\\','/')}';`);
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(exe,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const endpoint=await new Promise((resolve,reject)=>{let logs='';const t=setTimeout(()=>reject(Error('Browser startup timeout')),15000);browser.stderr.on('data',data=>{logs+=data;const m=logs.match(/DevTools listening on (ws:\/\/\S+)/);if(m){clearTimeout(t);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(endpoint);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}
  if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Network.responseReceived'&&m.params.response.url.includes(path.basename(route))){const headers=m.params.response.headers;const key=Object.keys(headers).find(k=>k.toLowerCase()==='x-qa-students');if(key){const ids=JSON.parse(decodeURIComponent(headers[key]));retained.add(path.join(root,'uploads','student-profile',`student_${ids.first}.jpg`));}}
  if(m.method==='Fetch.requestPaused'){const presence=m.params.request.url.includes('student_presence.php');call('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:presence?'application/json':'text/html'}],body:Buffer.from(presence?JSON.stringify({status:'ok',seconds_remaining:900,idle_seconds:900}):'<h1 id="qa-photo-complete">Saved and displayed</h1>').toString('base64')},m.sessionId).catch(e=>errors.push(e.message));}
 });
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout'));},60000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Network.enable',{},session);
 await call('Fetch.enable',{patterns:[{urlPattern:'*backend/student_presence.php*',requestStage:'Request'},{urlPattern:'*student/index.php',resourceType:'Document',requestStage:'Request'}]},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async (expression,timeout=60000)=>{const end=Date.now()+timeout;while(Date.now()<end){if(await evaluate('Boolean('+expression+')'))return;await delay(150);}throw Error('Timed out: '+expression+'; '+await evaluate('document.getElementById("student-photo-error")?.textContent')+'; '+errors.join('; '));};
 const url=`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=photo_required_browser`;
 await call('Page.navigate',{url},session);await until('document.getElementById("studentProfilePhotoModal")?.classList.contains("show")');
 await evaluate(`document.getElementById('student-photo-form').action=${JSON.stringify(url)}`);
 check(await evaluate('!document.querySelector("#student-photo-form input[type=file]")&&document.getElementById("student-photo-save").disabled'),'Old files can be selected or Save is enabled before capture.');
 await evaluate(`window.qaOriginalCamera=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);navigator.mediaDevices.getUserMedia=async()=>{throw new DOMException('Denied','NotAllowedError')};document.getElementById('student-camera-open').click()`);
 await until('!document.getElementById("student-camera-open").disabled');
 check(await evaluate('document.getElementById("student-photo-error").textContent.includes("blocked")&&document.getElementById("student-photo-save").disabled'),'Camera denial does not keep Save disabled with useful guidance.');
 await evaluate(`navigator.mediaDevices.getUserMedia=async()=>{throw new DOMException('None','NotFoundError')};document.getElementById('student-camera-open').click()`);
 await until('!document.getElementById("student-camera-open").disabled');
 check(await evaluate('document.getElementById("student-photo-error").textContent.includes("No suitable camera")'),'No-camera guidance is missing.');
 await evaluate(`(async()=>{window.qaCanvas=document.createElement('canvas');qaCanvas.width=qaCanvas.height=720;window.qaContext=qaCanvas.getContext('2d');window.qaPortrait=new Image();qaPortrait.src='../tests/fixtures/student-camera-portrait.jpg';await qaPortrait.decode();window.qaStreams=[];window.qaMode='blank';window.qaDraw=()=>{qaContext.fillStyle=qaMode==='dark_background'?'#171717':'#eeeeee';qaContext.fillRect(0,0,720,720);if(['face','background','dark_background'].includes(qaMode)){qaContext.drawImage(qaPortrait,270,0,270,380,210,25,300,420);}if(qaMode==='multiple'){qaContext.drawImage(qaPortrait,220,0,380,480,30,20,300,400);qaContext.drawImage(qaPortrait,220,0,380,480,390,20,300,400);}if(qaMode==='background'){for(let y=0;y<400;y+=20)for(let x=0;x<720;x+=20)if(x<100||x>610){qaContext.fillStyle=(x+y)%40===0?'#151515':'#eeeeee';qaContext.fillRect(x,y,20,20);}}};qaDraw();window.qaTimer=setInterval(qaDraw,50);navigator.mediaDevices.getUserMedia=async()=>{const s=qaCanvas.captureStream(20);qaStreams.push(s);return s};document.getElementById('student-camera-open').click()})()`);
 await until('!document.getElementById("student-camera-capture").disabled');
 check(await evaluate('!document.getElementById("student-photo-video").hidden&&document.getElementById("student-photo-save").disabled'),'The camera preview did not load with the local model.');
 await evaluate('document.getElementById("student-camera-capture").click()');
 await until('!document.getElementById("student-photo-error").hidden');
 check(await evaluate('document.getElementById("student-photo-error").textContent.includes("No clear face")&&document.getElementById("student-photo-save").disabled'),'The real detector accepted a blank frame.');
 await evaluate('qaMode="multiple";qaDraw()');await delay(250);await evaluate('document.getElementById("student-camera-capture").click()');
 await until('document.getElementById("student-photo-error").textContent.includes("Only one person")');
 check(await evaluate('document.getElementById("student-photo-save").disabled'),'A multiple-face capture enabled Save.');
 await evaluate('qaMode="background";qaDraw()');await delay(250);await evaluate('document.getElementById("student-camera-capture").click()');
 await until('!document.getElementById("student-photo-save").disabled');
 check(await evaluate('document.getElementById("student-photo-error").hidden'),'A clear face with a busy background was rejected.');
 check(await evaluate('document.getElementById("student-photo-preview").src.startsWith("blob:")&&document.getElementById("student-photo-video").hidden&&qaStreams.at(-1).getVideoTracks()[0].readyState==="ended"'),'A valid capture did not preview or stop the camera.');
 for(const [width,height]of[[1440,1000],[390,844],[320,640],[844,390]]){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);await delay(150);check(await evaluate(`(()=>{const m=document.querySelector('#studentProfilePhotoModal .modal-content').getBoundingClientRect(),f=document.querySelector('#studentProfilePhotoModal .modal-footer').getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&m.left>=0&&m.right<=innerWidth&&m.bottom<=innerHeight+1&&f.bottom<=innerHeight+1})()`),'The camera dialog overflows '+width+'x'+height);if(width===390)fs.writeFileSync(path.join(root,'.tmp','student-camera-preview-390.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));}
 fs.writeFileSync(path.join(root,'.tmp','student-camera-preview.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await evaluate('document.getElementById("student-camera-retake").click()');await until('!document.getElementById("student-camera-capture").disabled');
 check(await evaluate('document.getElementById("student-photo-save").disabled&&qaStreams.length===2'),'Retake did not discard the previous capture.');
 await evaluate('qaMode="dark_background";qaDraw()');await delay(250);
 await evaluate('document.getElementById("student-camera-capture").click()');await until('!document.getElementById("student-photo-save").disabled');
 await evaluate(`window.qaFetch=window.fetch;window.fetch=async(input,options)=>{if(options?.body?.has('profile_photo'))return new Response(JSON.stringify({success:false,message:'Test save unavailable. Please try again.'}),{status:503,headers:{'Content-Type':'application/json'}});return qaFetch(input,options)};document.getElementById('student-photo-save').click()`);
 await until('document.getElementById("student-photo-error").textContent.includes("Test save unavailable")&&!document.getElementById("student-photo-save").disabled');
 check(await evaluate('document.getElementById("student-photo-preview").src.startsWith("blob:")&&!document.getElementById("student-camera-retake").disabled'),'A server failure discarded the capture or prevented retry.');
 await evaluate('window.fetch=qaFetch');
 await evaluate('document.getElementById("student-photo-save").click()');await until('document.getElementById("qa-photo-complete")');
 check(await evaluate('document.getElementById("qa-photo-complete").textContent.includes("Saved and displayed")'),'The real JPEG save/display did not complete.');
 const saved=[...retained].filter(fs.existsSync);check(saved.length===1,'Saving left more than one test photo.');
 const response=await fetch('http://localhost/synk/uploads/student-profile/'+path.basename(saved[0]));
 check(response.status===200&&response.headers.get('content-type').includes('image/jpeg'),'The saved photo is not publicly readable as a JPEG.');
 check(crypto.createHash('sha256').update(Buffer.from(await response.arrayBuffer())).digest('hex')===crypto.createHash('sha256').update(fs.readFileSync(saved[0])).digest('hex'),'The served avatar differs from the saved JPEG.');
 check(errors.length===0,'Browser errors: '+errors.join('; '));console.log(`Student camera: ${checks} browser checks passed, including local face detection, capture, retake, save and public JPEG delivery.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(call)try{await call('Browser.close');}catch{}if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(r=>browser.once('exit',r));}if(fs.existsSync(route))fs.unlinkSync(route);for(const file of retained){if(path.dirname(file)===path.join(root,'uploads','student-profile')&&/^student_\d+\.jpg$/.test(path.basename(file))&&fs.existsSync(file))fs.unlinkSync(file);}const resolved=path.resolve(profile);if(resolved.startsWith(path.resolve(root,'.tmp')+path.sep)&&fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true});});
