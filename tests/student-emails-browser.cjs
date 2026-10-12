// node tests/student-emails-browser.cjs — actual UI, isolated API responses, no real email writes.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-emails-browser-${nonce}.php`),profile=path.join(root,'.tmp',`student-emails-browser-${nonce}`);
let browser,ws,call,checks=0;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.mkdirSync(path.join(root,'.tmp'),{recursive:true});
 const fixture=JSON.parse(execFileSync('php',['tests/fixtures/college-dean-students-request.php','page'],{cwd:root,windowsHide:true,encoding:'utf8'}));
 let html=fixture.response.replace('<head>','<head><base href="http://localhost/synk/college-dean/">');
 const mock=`<script>
 window.__emailRequests=[];window.__saveError=true;window.__holdSave=false;window.__saved=false;
 window.fetch=async function(url,options){
 const params=Object.fromEntries(options.body.entries());window.__emailRequests.push(params);let data;
 const rows=[{student_id:11,student_number:'000123',student_name:'Peña, José Niño',email_address:window.__saved?'josepena@sksu.edu.ph':'',programs:'BSCS',sections:'BSCS 1A',subject_count:2},
 {student_id:12,student_number:'000124',student_name:'Ambrocio, Carol Jan',email_address:'',programs:'BSCS',sections:'BSCS 1A',subject_count:1}];
 if(params.action==='bootstrap')data={status:'ok',options:{programs:[{program_id:1,label:'BSCS'}],terms:[{key:'2:1',label:'2026-2027 - 1st Semester'}],sections:[{section_id:10,program_id:1,term:'2:1',section_label:'BSCS 1A'}]},current_term:{ay_id:2,semester:1}};
 else if(params.action==='list')data={status:'ok',rows,summary:{students:2,classes:3,programs:1},pagination:{page:1,pages:1,total:2,from:1,to:2}};
 else if(params.action==='email_preview')data={status:'ok',preview_token:'server-preview',summary:{students:4,ready:2,preserved:1,review:1},rows:[
 {...rows[0],email:'josepena@sksu.edu.ph',status:'ready',reason:'Confirm the school address.'},
 {...rows[1],email:'caroljanambrocio@sksu.edu.ph',status:'ready',reason:'Uses the complete given names.'},
 {student_id:13,student_number:'000125',student_name:'Verified, Student',email:'verified.alias@sksu.edu.ph',status:'preserved',reason:'Existing email will be kept.'},
 {student_id:14,student_number:'000126',student_name:'<img src=x onerror=alert(1)> Peña, José',email:'josepena@sksu.edu.ph',status:'review',reason:'Another student would receive this address.'}]};
 else if(params.action==='email_save'){
 if(window.__holdSave)await new Promise(resolve=>window.__resolveSave=resolve);
 data=window.__saveError ? {status:'error',message:'A proposed email now conflicts. Nothing was saved. Preview again.'} : {status:'ok',saved:1,message:'1 missing student email saved. Existing emails were kept.'};
 if(!window.__saveError)window.__saved=true;
 } else throw Error('Unexpected email browser action '+params.action);
 return {ok:data.status==='ok',json:async()=>data};
 };</script>`;
 html=html.replace('<script src="students.js',mock+'<script src="students.js');
 fs.writeFileSync(route,`<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals('${token}',$_GET['qa'])){http_response_code(404);exit;} header('Content-Type: text/html; charset=utf-8');echo base64_decode('${Buffer.from(html).toString('base64')}');`);
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 if(!exe)throw Error('Chrome or Edge is required for browser verification.');
 browser=spawn(exe,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timeout.')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let id=0;const pending=new Map();ws.addEventListener('message',e=>{const r=JSON.parse(e.data);if(r.id&&pending.has(r.id)){const p=pending.get(r.id);clearTimeout(p.timer);pending.delete(r.id);r.error?p.reject(Error(r.error.message)):p.resolve(r.result);}});
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const sequence=++id,timer=setTimeout(()=>{pending.delete(sequence);reject(Error(method+' timeout.'));},15000);pending.set(sequence,{resolve,reject,timer});ws.send(JSON.stringify({id:sequence,method,params,...(sessionId?{sessionId}:{})}));});
 const t=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:t.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await delay(100);}throw Error(expression+' did not become true.');};
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
 await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}`},session);
 await until('document.getElementById("deanStudentEmailsOpen") && !document.getElementById("deanStudentEmailsOpen").disabled');
 await evaluate('document.getElementById("deanStudentsProgram").value="1";document.getElementById("deanStudentsProgram").dispatchEvent(new Event("change"));document.getElementById("deanStudentsSearch").value="Peña";document.getElementById("deanStudentEmailsOpen").click()');
 await until('document.querySelectorAll("input[data-email-student]").length===2 && !document.getElementById("deanStudentEmailsReload").disabled');
 check(await evaluate('window.__emailRequests.find(r=>r.action==="email_preview").search==="Peña" && window.__emailRequests.find(r=>r.action==="email_preview").program_id==="1"'),'Preview follows current filters.');
 check(await evaluate('document.getElementById("deanStudentEmailsSave").disabled && document.querySelectorAll("input[data-email-student]:checked").length===0'),'Saving requires explicit selection.');
 check(await evaluate('document.getElementById("deanStudentEmailsPreview").textContent.includes("Peña, José Niño") && document.getElementById("deanStudentEmailsPreview").textContent.includes("caroljanambrocio@sksu.edu.ph")'),'Unicode names and compound given-name emails display correctly.');
 check(await evaluate('!document.querySelector("#deanStudentEmailsPreview img")'),'Student names are escaped.');
 check(await evaluate('document.querySelectorAll("input[data-email-student]").length===2'),'Existing and review emails cannot be selected.');
 await evaluate('document.getElementById("deanStudentEmailsAll").click()');
 check(await evaluate('document.getElementById("deanStudentEmailsSave").textContent==="Save Selected (2)" && !document.getElementById("deanStudentEmailsSave").disabled'),'Select all includes only eligible emails.');
 await delay(350);
 fs.writeFileSync(path.join(root,'.tmp','student-emails-desktop.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);await delay(300);
 check(await evaluate('document.querySelector("#deanStudentEmailsModal .modal-content").getBoundingClientRect().right<=innerWidth'),'Mobile modal fits.');
 check(await evaluate('document.getElementById("deanStudentEmailsPreview").scrollWidth<=document.getElementById("deanStudentEmailsPreview").clientWidth'),'Mobile email cards fit without horizontal scrolling.');
 check(await evaluate('getComputedStyle(document.querySelector(".dean-email-table tr")).display==="block"'),'Mobile preview uses stacked cards.');
 fs.writeFileSync(path.join(root,'.tmp','student-emails-mobile.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await evaluate('document.getElementById("deanStudentEmailsReviewOnly").click()');
 check(await evaluate('document.querySelectorAll(".dean-email-table tbody tr").length===1 && document.querySelector(".dean-email-table tbody").textContent.includes("Needs review") && document.getElementById("deanStudentEmailsSave").textContent==="Save Selected (2)"'),'Review filter does not discard selected approvals.');
 await evaluate('document.getElementById("deanStudentEmailsSave").click()');
 await until('document.getElementById("deanStudentEmailsNotice").textContent.includes("Nothing was saved")');
 check(await evaluate('document.getElementById("deanStudentEmailsSave").disabled && !document.getElementById("deanStudentEmailsReload").disabled'),'Failed save requires a fresh preview.');
 await evaluate('window.__saveError=false;document.getElementById("deanStudentEmailsReload").click()');
 await until('document.querySelectorAll("input[data-email-student]").length===2 && !document.getElementById("deanStudentEmailsReload").disabled');
 await evaluate('document.querySelector("[data-email-student=\\"11\\"]").click();window.__holdSave=true;document.getElementById("deanStudentEmailsSave").click()');
 await until('!!window.__resolveSave');
 check(await evaluate('document.getElementById("deanStudentEmailsSave").disabled && document.getElementById("deanStudentEmailsClose").disabled && document.querySelector("[data-email-student=\\"11\\"]").disabled'),'Pending save prevents duplicate submissions and selection changes.');
 await evaluate('window.__resolveSave()');
 await until('document.getElementById("deanStudentEmailsNotice").classList.contains("alert-success")');
 check(await evaluate('JSON.parse(window.__emailRequests.filter(r=>r.action==="email_save").at(-1).selected_ids).join(",")==="11"'),'Save submits only approved student IDs.');
 await until('document.getElementById("deanStudentsRows").textContent.includes("josepena@sksu.edu.ph")');
 check(await evaluate('document.getElementById("deanStudentEmailsSave").disabled'),'Successful save consumes the preview and refreshes the directory.');
 console.log(`Student emails browser: ${checks} checks passed at desktop and mobile widths.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
 if(call)try{await call('Browser.close');}catch{}
 if(ws)ws.close();
 if(browser&&browser.exitCode===null){browser.kill();await new Promise(resolve=>browser.once('exit',resolve));}
 if(fs.existsSync(route))fs.unlinkSync(route);
 // Delete only this test's verified temporary browser profile.
 if(path.dirname(profile)===path.join(root,'.tmp') && path.basename(profile)===`student-emails-browser-${nonce}`) {
   for(let i=0;i<8;i++)try{fs.rmSync(profile,{recursive:true,force:true});break;}catch{await delay(250);}
 }
});
