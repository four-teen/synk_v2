// Isolated QR screen/download checks. All student fixtures roll back.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-qr-browser-${nonce}.php`),profile=path.join(root,'.tmp',`student-qr-browser-${nonce}`),downloads=path.join(root,'.tmp',`student-qr-download-${nonce}`);
const payload='SYNK-ATTENDANCE:'+'a7'.repeat(32);
let browser,ws,call,checks=0;const errors=[],requests=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.mkdirSync(downloads);
 fs.writeFileSync(route,`<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root,'tests/fixtures/student-portal-request.php').replaceAll('\\','/')}';`);
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(exe,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let logs='';const timeout=setTimeout(()=>reject(Error('Browser startup timeout.')),20000);browser.stderr.on('data',data=>{logs+=data;const m=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timeout);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 ws.addEventListener('message',event=>{
  const m=JSON.parse(event.data);
  if(m.id&&pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timeout);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}
  if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Fetch.requestPaused'){
   requests.push(m.params.request);
   const response=m.params.request.url.includes('student_presence.php')?{status:'ok',seconds_remaining:900,idle_seconds:900}:{status:'ok',qr_code:payload};
   call('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(response)).toString('base64')},m.sessionId).catch(e=>errors.push(e.message));
  }
 });
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timeout=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout.'));},20000);pending.set(id,{resolve,reject,timeout});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 await call('Fetch.enable',{patterns:[{urlPattern:'*backend/query_management_student_qr.php*'},{urlPattern:'*backend/student_presence.php*'}]},session);
 await call('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:downloads});
 // Record actual exported labels so long names can be verified without OCR.
 await call('Page.addScriptToEvaluateOnNewDocument',{source:'window.qrExportLabels=[];const original=CanvasRenderingContext2D.prototype.fillText;CanvasRenderingContext2D.prototype.fillText=function(text,...args){qrExportLabels.push(String(text));return original.call(this,text,...args)};'},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate('Boolean('+expression+')'))return;await delay(100);}throw Error('Condition timed out: '+expression+'; '+errors.join('; ')+'; '+await evaluate('JSON.stringify({url:location.href,text:document.body.textContent.slice(-1800),error:document.getElementById("student-qr-error")?.textContent,requests:performance.getEntriesByType("resource").map(r=>r.name)})'));};
 const resize=async width=>{await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:false},session);await delay(100);};
 await resize(1440);
 await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=attendance_qr_page`},session);
 await until('document.readyState==="complete" && document.getElementById("student-qr-save")?.href.startsWith("blob:") && !document.getElementById("student-qr-save").hidden');
 const qrRequests=requests.filter(request=>request.url.includes('query_management_student_qr.php'));
 check(qrRequests.length===1&&new URLSearchParams(qrRequests[0].postData).get('csrf_token').length===64,'QR issuance lost its authenticated request token.');
 check(await evaluate('[...document.querySelectorAll("#layout-menu .student-menu-card")].some(a=>a.querySelector(".student-menu-title").textContent==="My QR") && [...document.querySelectorAll(".student-bottom-link")].some(a=>a.textContent.trim()==="QR")'),'Desktop/mobile QR labels are incorrect.');
 await evaluate('(0,eval)('+JSON.stringify(fs.readFileSync(path.join(root,'assets/vendor/libs/management-qr/jsQR.js'),'utf8'))+')');
 for(const width of[1440,390,360,320]){
  await resize(width);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'QR page overflows '+width+'px.');
  check(await evaluate('(()=>{const r=document.getElementById("student-attendance-qr").getBoundingClientRect();return r.width>=240&&Math.abs(r.width-r.height)<1&&r.left>=0&&r.right<=innerWidth})()'),'The QR is too small or clipped at '+width+'px.');
  check(await evaluate('(async()=>{const svg=document.querySelector("#student-attendance-qr svg"),size=Math.round(svg.getBoundingClientRect().width),bitmap=new Image(),canvas=document.createElement("canvas");bitmap.src="data:image/svg+xml;charset=utf-8,"+encodeURIComponent(new XMLSerializer().serializeToString(svg));await bitmap.decode();canvas.width=canvas.height=size;const ctx=canvas.getContext("2d");ctx.drawImage(bitmap,0,0,size,size);const pixels=ctx.getImageData(0,0,size,size),decoded=jsQR(pixels.data,size,size);return decoded?.data==='+JSON.stringify(payload)+'})()'),'The displayed QR cannot be decoded at '+width+'px.');
 }
 check(await evaluate('(()=>{const config=JSON.parse(document.getElementById("student-attendance-config").textContent);return qrExportLabels.includes(config.full_name)&&qrExportLabels.includes("Student ID: "+config.student_number)})()'),'The PNG omitted the full name or student ID.');
 const image=await evaluate('(async()=>{const blob=await(await fetch(document.getElementById("student-qr-save").href)).blob(),bitmap=await createImageBitmap(blob),canvas=document.createElement("canvas");canvas.width=bitmap.width;canvas.height=bitmap.height;const ctx=canvas.getContext("2d");ctx.drawImage(bitmap,0,0);bitmap.close();const pixels=ctx.getImageData(0,0,canvas.width,canvas.height);return {type:blob.type,width:canvas.width,height:canvas.height,payload:jsQR(pixels.data,pixels.width,pixels.height)?.data}})()');
 check(image.type==='image/png'&&image.width===1080&&image.payload===payload,'The saved PNG is not a full-resolution readable QR.');
 await evaluate('document.getElementById("student-qr-save").click()');
 let downloaded;for(let i=0;i<80;i++){downloaded=fs.readdirSync(downloads).find(file=>file.endsWith('.png'));if(downloaded)break;await delay(100);}
 check(Boolean(downloaded),'The Save QR action did not download a PNG file.');
 check(fs.readFileSync(path.join(downloads,downloaded)).subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])),'The downloaded file is not a PNG.');
 fs.copyFileSync(path.join(downloads,downloaded),path.join(root,'.tmp','student-qr-saved.png'));
 await evaluate('window.scrollTo(0,0)');
 fs.writeFileSync(path.join(root,'.tmp','student-qr-320.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 // Names longer than one line must remain complete in the exported image.
 await evaluate('window.originalStudentQrConfig=JSON.parse(document.getElementById("student-attendance-config").textContent);originalStudentQrConfig.full_name="ALEXANDRA MARIA ".repeat(12)+"DELA CRUZ";document.getElementById("student-attendance-config").textContent=JSON.stringify(originalStudentQrConfig);qrExportLabels=[];');
 await evaluate('(0,eval)('+JSON.stringify(fs.readFileSync(path.join(root,'student/attendance-qr.js'),'utf8'))+')');
 await until('!document.getElementById("student-qr-save").hidden');
 check(await evaluate('qrExportLabels.filter(line=>line!=="SYNK STUDENT · MY QR" && line!=="FULL NAME" && !line.startsWith("Student ID:") && !line.startsWith("Show to Management")).join(" ")===originalStudentQrConfig.full_name'),'A long full name was truncated in the QR image.');
 check(errors.length===0,'QR page runtime errors: '+errors.join('; '));
 console.log(`Student QR: ${checks} browser checks passed, including phone-size decoding and a real PNG download.`);
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
 if(call)try{await call('Browser.close');}catch{}
 if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await delay(600);}
 if(fs.existsSync(route))fs.unlinkSync(route);
 for(const directory of[profile,downloads]){
  const resolved=path.resolve(directory),parent=path.resolve(root,'.tmp');
  if(!resolved.startsWith(parent+path.sep)||!/^student-qr-(browser|download)-[a-f0-9]{16}$/.test(path.basename(resolved)))throw Error('Unexpected QR test cleanup path.');
  if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
 }
});
