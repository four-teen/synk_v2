// node tests/student-management-browser.cjs — actual controllers and Bootstrap, isolated API responses.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-management-browser-${nonce}.php`),profile=path.join(root,'.tmp',`student-management-browser-${nonce}`);
let browser,ws,call,checks=0;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.mkdirSync(path.join(root,'.tmp'),{recursive:true});
 const fixture=JSON.parse(execFileSync('php',['tests/fixtures/college-dean-students-request.php','page'],{cwd:root,windowsHide:true,encoding:'utf8'}));
 let html=fixture.response.replace('<head>','<head><base href="http://localhost/synk/college-dean/">');
 const mock=`<script>
 window.__requests=[];window.__failDelete=true;window.__failSave=false;
 const profiles=[{student_id:11,student_number:'000123',student_name:'Peña, José Niño',first_name:'José',last_name:'Peña',middle_name:'Niño',email_address:'JOSE.PENA@SKSU.EDU.PH',programs:'BSIS',sections:'BSIS 1A, BSIS 2C',subject_count:2,home_section:'Not verified',version:'server-version'},
 {student_id:12,student_number:'000124',student_name:'Ambrocio, Carol Jan',first_name:'Carol Jan',last_name:'Ambrocio',middle_name:'',email_address:'',programs:'BSIS',sections:'BSIS 2C',subject_count:1,home_section:'BSIS 2C',version:'server-version'}];
 window.fetch=async function(url,options){
 const p=Object.fromEntries(options.body.entries());window.__requests.push(p);let data;
 if(p.action==='bootstrap')data={status:'ok',options:{programs:[{program_id:1,label:'BSIS'}],terms:[{key:'2:1',ay_label:'2026-2027',label:'2026-2027 - 1st Semester'},{key:'1:2',ay_label:'2025-2026',label:'2025-2026 - 2nd Semester'}],sections:[{section_id:10,program_id:1,term:'2:1',section_label:'BSIS 1A'},{section_id:20,program_id:1,term:'1:2',section_label:'BSIS 2E'}]},current_term:{ay_id:2,semester:1}};
 else if(p.action==='list'){const filtered=profiles.filter(s=>p.email_status==='missing'?!s.email_address:p.email_status==='present'?!!s.email_address:true);const size=Number(p.page_size),total=filtered.length,pages=Math.max(1,Math.ceil(total/size)),page=Math.min(Number(p.page),pages);data={status:'ok',rows:filtered.slice((page-1)*size,page*size),summary:{students:total,classes:3,programs:1},pagination:{page,pages,total,from:total?(page-1)*size+1:0,to:Math.min(page*size,total)}};}
 else if(p.action==='selection')data={status:'ok',student_ids:p.email_status==='missing'?[12]:[11,12]};
 else if(p.action==='form_options')data={status:'ok',programs:[{program_id:1,label:'BSIS'}],academic_years:[{ay_id:2,ay:'2026-2027'},{ay_id:1,ay:'2025-2026'}],current_term:{ay_id:2,semester:1}};
 else if(p.action==='edit_load')data={status:'ok',student:profiles.find(s=>s.student_id===Number(p.student_id))};
 else if(p.action==='email_propose')data={status:'ok',candidate:p.first_name==='J.'?{status:'review',email:'',reason:'A first name appears to be an initial.'}:{status:'ready',email:'josepena@sksu.edu.ph'}};
 else if(p.action==='student_save'){data=window.__failSave?{status:'error',message:'This student was changed by another request. Reopen Edit Student before saving.'}:{status:'ok',student_id:Number(p.student_id)||13,message:'Student saved.'};if(!window.__failSave&&Number(p.student_id))profiles.find(s=>s.student_id===Number(p.student_id)).email_address=p.email_address;}
 else if(p.action==='delete_preview'){const selected=profiles.filter(s=>JSON.parse(p.selected_ids).includes(s.student_id));data={status:'ok',preview_token:'delete-preview',summary:{students:selected.length,class_records:selected.reduce((n,s)=>n+s.subject_count,0),classes:2,manual_records:0},students:selected.map(s=>({...s,class_records:s.subject_count}))};}
 else if(p.action==='delete_save')data=window.__failDelete?{status:'error',message:'Student records changed after the preview. Nothing was deleted. Preview again.'}:{status:'ok',removed:3,message:'3 class-list records removed for the selected academic term. Shared student profiles and other terms were kept.'};
 else if(p.action==='email_preview')data={status:'ok',preview_token:'email-preview',summary:{students:2,ready:1,preserved:0,review:1},rows:profiles.map((s,i)=>({...s,email:i?'caroljanambrocio@sksu.edu.ph':'',status:i?'ready':'review',reason:i?'Complete names.':'Verify the school email spelling.'}))};
 else if(p.action==='detail')data={status:'ok',student:profiles[0],subjects:[]};
 else throw Error('Unexpected student management action '+p.action);
 return {ok:data.status==='ok',json:async()=>data};
 };</script>`;
 html=html.replace('<script src="students.js',mock+'<script src="students.js');
 fs.writeFileSync(route,`<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals('${token}',$_GET['qa'])){http_response_code(404);exit;} header('Content-Type: text/html; charset=utf-8');echo base64_decode('${Buffer.from(html).toString('base64')}');`);
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 if(!exe)throw Error('Chrome or Edge is required for browser verification.');
 browser=spawn(exe,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timeout.')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();ws.addEventListener('message',e=>{const r=JSON.parse(e.data);if(r.id&&pending.has(r.id)){const p=pending.get(r.id);clearTimeout(p.timer);pending.delete(r.id);r.error?p.reject(Error(r.error.message)):p.resolve(r.result);}});
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout.'));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const t=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:t.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate(expression))return;await delay(100);}throw Error(expression+' did not become true.');};
 const click=async id=>evaluate(`document.getElementById(${JSON.stringify(id)}).click()`);
 const change=async(id,value)=>evaluate(`document.getElementById(${JSON.stringify(id)}).value=${JSON.stringify(value)};document.getElementById(${JSON.stringify(id)}).dispatchEvent(new Event('change'))`);
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
 await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}`},session);
 await until('document.getElementById("deanStudentAdd") && !document.getElementById("deanStudentAdd").disabled');
 check(await evaluate('document.getElementById("deanStudentsTerm").value==="2:1" && window.__requests.find(r=>r.action==="list").term==="2:1"'),'Default is current semester.');
 check(await evaluate('document.querySelector(".dean-student-directory-table thead").textContent.includes("Class Sections") && document.querySelector(".dean-student-directory-table thead").textContent.includes("Login Activity") && !document.querySelector(".dean-student-directory-table thead").textContent.includes("Verified Home Section") && document.querySelector("#deanStudentsRows tr").children.length===7'),'Directory keeps the combined identity column and adds login activity.');
 check(await evaluate('document.querySelector("#deanStudentsRows .dean-student-name").children[0].textContent==="000123" && document.querySelector("#deanStudentsRows .dean-student-name").children[1].textContent==="Peña, José Niño" && document.querySelector("#deanStudentsRows .dean-student-name").children[2].textContent==="jose.pena@sksu.edu.ph" && getComputedStyle(document.querySelector("#deanStudentsRows .dean-student-contact em")).fontStyle==="italic"'),'ID, name and lowercase italic email appear in order.');
 check(await evaluate('getComputedStyle(document.querySelector("#deanStudentsRows .dean-student-full-name")).whiteSpace==="nowrap" && document.querySelector(".dean-student-directory-table").parentElement.scrollWidth<=document.querySelector(".dean-student-directory-table").parentElement.clientWidth'),'Desktop directory fits all columns without wrapping student names.');
 fs.writeFileSync(path.join(root,'.tmp','student-directory-compact-desktop.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await call('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false},session);await delay(200);
 check(await evaluate('document.querySelector(".dean-student-directory-table").parentElement.scrollWidth<=document.querySelector(".dean-student-directory-table").parentElement.clientWidth && document.querySelector(".dean-student-row-actions").getBoundingClientRect().right<=document.querySelector(".dean-student-directory-table").getBoundingClientRect().right'),'Laptop directory shows the subject count and actions without horizontal scrolling.');
 fs.writeFileSync(path.join(root,'.tmp','student-directory-compact-laptop.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
 check(await evaluate('parseFloat(getComputedStyle(document.querySelector("#deanStudentsRows .dean-student-full-name")).marginTop)===0 && parseFloat(getComputedStyle(document.querySelector("#deanStudentsRows .dean-student-contact")).marginTop)===0 && document.querySelector("#deanStudentsRows .dean-student-contact").getBoundingClientRect().top-document.querySelector("#deanStudentsRows .dean-student-id").getBoundingClientRect().top<36'),'Student identity lines use tighter vertical spacing.');
 await change('deanStudentsEmailStatus','missing');await until('document.querySelectorAll("[data-select-student]").length===1 && document.querySelector("[data-select-student]").dataset.selectStudent==="12"');
 await click('deanStudentsSelectResults');await until('document.getElementById("deanStudentsSelectedCount").textContent==="1 selected"');
 check(await evaluate('window.__requests.filter(r=>r.action==="selection").at(-1).email_status==="missing" && document.getElementById("deanUniqueStudents").textContent==="1"'),'Missing-email selection and directory totals follow the new filter.');
 await evaluate('document.querySelector("[data-student-edit=\\"12\\"]").click()');await until('!document.getElementById("deanStudentEditorSave").disabled');
 await evaluate('document.getElementById("deanStudentEmail").value="caroljanambrocio@sksu.edu.ph";document.getElementById("deanStudentEditorForm").requestSubmit()');
 await until('document.getElementById("deanStudentsRows").textContent.includes("No matching students")');await delay(350);
 check(await evaluate('document.getElementById("deanStudentsEmailStatus").value==="missing" && document.getElementById("deanUniqueStudents").textContent==="0"'),'Saving a missing email removes the row while preserving the filter.');
 await click('deanStudentsReset');await until('document.querySelectorAll("[data-select-student]").length===2');
 check(await evaluate('document.getElementById("deanStudentsEmailStatus").value==="all" && document.getElementById("deanStudentsSelectedCount").textContent==="0 selected"'),'Reset clears email filtering and selection.');
 await evaluate('document.getElementById("deanStudentsPageSize").insertAdjacentHTML("beforeend",\'<option value="1">QA one per page</option>\')');
 await change('deanStudentsPageSize','1'); await until('document.querySelectorAll("[data-select-student]").length===1');
 await evaluate('document.querySelector("[data-select-student]").click()');
 await click('deanStudentsNext'); await until('document.querySelector("[data-select-student]")?.dataset.selectStudent==="12"');
 check(await evaluate('document.getElementById("deanStudentsSelectedCount").textContent==="1 selected"'),'Selection persists across pages.');
 await evaluate('document.querySelector("[data-select-student]").click()');
 check(await evaluate('document.getElementById("deanStudentsSelectedCount").textContent==="2 selected" && !document.getElementById("deanStudentsDelete").disabled'),'Multiple rows can be selected across pages.');
 await change('deanStudentsTerm','all'); await until('document.getElementById("deanStudentsSelectedCount").textContent==="0 selected"');
 check(await evaluate('document.getElementById("deanStudentsDelete").disabled'),'Changing scope clears selection and all-term deletion is disabled.');
 await change('deanStudentsTerm','year:1'); await until('!document.getElementById("deanStudentsSelectResults").disabled');
 await click('deanStudentsSelectResults'); await until('document.getElementById("deanStudentsSelectedCount").textContent==="2 selected"');
 check(await evaluate('window.__requests.filter(r=>r.action==="selection").at(-1).term==="year:1"'),'Select all matching students follows the selected academic year.');
 const previewsBeforeYear=await evaluate('window.__requests.filter(r=>r.action==="delete_preview").length');
 await click('deanStudentsDelete');await delay(150);
 check(await evaluate('window.__requests.filter(r=>r.action==="delete_preview").length')===previewsBeforeYear,'A year-wide historical view cannot start a deletion.');
 await change('deanStudentsTerm','2:1');await until('document.getElementById("deanStudentsSelectedCount").textContent==="0 selected"');await click('deanStudentsSelectResults');await until('document.getElementById("deanStudentsSelectedCount").textContent==="2 selected"');
 await click('deanStudentsDelete'); await until('document.getElementById("deanStudentDeletePreview").textContent.includes("3 class-list records")');
 check(await evaluate('document.getElementById("deanStudentDeleteConfirm").disabled && document.getElementById("deanStudentDeleteTerm").textContent.includes("2026-2027")'),'Delete preview identifies the current term and requires acknowledgement.');
 await delay(350);fs.writeFileSync(path.join(root,'.tmp','student-management-delete-desktop.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await click('deanStudentDeleteAcknowledge'); await click('deanStudentDeleteConfirm'); await until('document.getElementById("deanStudentDeleteNotice").textContent.includes("Nothing was deleted")');
 check(await evaluate('document.getElementById("deanStudentDeleteConfirm").disabled && window.__requests.filter(r=>r.action==="delete_save").at(-1).preview_token==="delete-preview"'),'Stale deletion consumes the UI token and cannot be retried blindly.');
 await click('deanStudentDeleteCancel');await until('!document.body.classList.contains("modal-open")');await delay(350);
 await evaluate('window.__failDelete=false');await click('deanStudentsDelete');await until('!document.getElementById("deanStudentDeleteAcknowledge").disabled');
 await click('deanStudentDeleteAcknowledge');await until('!document.getElementById("deanStudentDeleteConfirm").disabled');await click('deanStudentDeleteConfirm');await until('document.getElementById("deanStudentManagementNotice").classList.contains("alert-success")');await delay(350);
 check(await evaluate('document.getElementById("deanStudentManagementNotice").textContent.includes("Shared student profiles") && document.getElementById("deanStudentsSelectedCount").textContent==="0 selected"'),'Successful term deletion refreshes the directory and preserves its stated scope.');
 await click('deanStudentsReset');await until('document.getElementById("deanStudentsTerm").value==="2:1"');
 await change('deanStudentsPageSize','25');await until('document.querySelectorAll("[data-student-delete]").length===2');
 check(await evaluate('document.querySelectorAll(".dean-student-name [data-student-delete]").length===2 && document.querySelectorAll(".dean-student-account-actions a").length===2'),'Each student name has Delete and Show Account actions.');
 check(await evaluate('document.querySelector(".dean-student-account-actions a").getAttribute("href").includes("preview_student_id=11") && [...document.querySelectorAll(".dean-student-account-actions a")].every(a=>a.target==="" && !a.getAttribute("aria-label").includes("new tab"))'),'Show Account uses the same tab with a scoped student preview link.');
 await evaluate(`document.querySelector('[data-select-student="12"]').click();document.querySelector('[data-student-delete="11"]').click();`);
 await until('!document.getElementById("deanStudentDeleteAcknowledge").disabled');
 check(await evaluate('window.__requests.filter(r=>r.action==="delete_preview").at(-1).selected_ids==="[11]" && document.getElementById("deanStudentDeleteTitle").textContent==="Delete Student From This Term"'),'The row Delete previews only the clicked student.');
 check(await evaluate('window.__requests.filter(r=>r.action==="delete_preview").at(-1).section_id==="0" && window.__requests.filter(r=>r.action==="delete_preview").at(-1).program_id==="0" && document.getElementById("deanStudentDeleteScope").textContent.includes("all class lists")'),'Individual deletion covers that student’s entire college/semester list.');
 check(await evaluate('document.getElementById("deanStudentDeleteConfirm").disabled && document.getElementById("deanStudentsSelectedCount").textContent==="1 selected"'),'Preview keeps other selections and requires an acknowledgement.');
 await click('deanStudentDeleteCancel');await until('!document.body.classList.contains("modal-open")');await delay(350);
 check(await evaluate('document.getElementById("deanStudentsSelectedCount").textContent==="1 selected"'),'Cancelling individual deletion preserves the bulk selection.');
 await evaluate(`document.querySelector('[data-student-delete="11"]').click();`);await until('!document.getElementById("deanStudentDeleteAcknowledge").disabled');await click('deanStudentDeleteAcknowledge');try{await until('!document.getElementById("deanStudentDeleteConfirm").disabled');}catch(error){console.log(await evaluate('JSON.stringify({modal:document.getElementById("deanStudentDeleteModal").className,open:document.body.classList.contains("modal-open"),busy:document.getElementById("deanStudentDeleteModal").getAttribute("aria-busy"),checked:document.getElementById("deanStudentDeleteAcknowledge").checked,disabled:document.getElementById("deanStudentDeleteAcknowledge").disabled,notice:document.getElementById("deanStudentDeleteNotice").textContent,requests:window.__requests.filter(r=>r.action.startsWith("delete")).slice(-3).map(r=>({action:r.action,ids:r.selected_ids,term:r.term}))})'));throw error;}await click('deanStudentDeleteConfirm');await until('!document.getElementById("deanStudentDeleteModal").classList.contains("show")');await delay(350);
 check(await evaluate('document.getElementById("deanStudentsSelectedCount").textContent==="1 selected" && document.querySelector("[data-select-student=\\\"12\\\"]").checked'),'Saving individual deletion refreshes the list and retains the other selected student.');
 await click('deanStudentsClearSelection');
 await click('deanStudentAdd');await until('!document.getElementById("deanStudentEditorFields").disabled');
 check(await evaluate('!document.getElementById("deanStudentNumber").readOnly && !document.getElementById("deanStudentCreationFields").hidden && document.getElementById("deanStudentRecordYear").value==="2"'),'New students have program and academic-term inputs.');
 await evaluate('document.getElementById("deanStudentNumber").value="000777";document.getElementById("deanStudentFirstName").value="J.";document.getElementById("deanStudentLastName").value="Peña"');
 await click('deanStudentGenerateEmail');await until('document.getElementById("deanStudentEditorNotice").textContent.includes("initial")');
 check(await evaluate('document.getElementById("deanStudentEmail").value===""'),'An unclear name does not silently generate an address.');
 await evaluate('document.getElementById("deanStudentFirstName").value="José"');await click('deanStudentGenerateEmail');await until('document.getElementById("deanStudentEmail").value==="josepena@sksu.edu.ph"');
 check(await evaluate('document.getElementById("deanStudentFirstName").value==="José" && document.getElementById("deanStudentLastName").value==="Peña"'),'Email suggestion preserves display-name accents.');
 await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);await delay(350);
 check(await evaluate('document.querySelector("#deanStudentEditorModal .modal-content").getBoundingClientRect().right<=innerWidth && document.querySelector("#deanStudentEditorModal .modal-body").scrollWidth<=document.querySelector("#deanStudentEditorModal .modal-body").clientWidth'),'Mobile student editor fits.');
 check(await evaluate('document.getElementById("deanStudentEditorSave").getBoundingClientRect().bottom<=innerHeight'),'Mobile save action remains accessible.');
 fs.writeFileSync(path.join(root,'.tmp','student-management-editor-mobile.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await evaluate('document.getElementById("deanStudentEditorForm").requestSubmit()');await until('!document.getElementById("deanStudentEditorModal").classList.contains("show")');await delay(350);
 check(await evaluate('window.__requests.filter(r=>r.action==="student_save").at(-1).student_number==="000777" && window.__requests.filter(r=>r.action==="student_save").at(-1).record_term==="2:1"'),'Creating a student sends the exact ID and selected term.');
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
 await click('deanStudentEmailsOpen');await until('document.querySelector("[data-email-edit]") && !document.getElementById("deanStudentEmailsReload").disabled');await delay(350);
 await evaluate('document.querySelector("[data-email-edit=\\"11\\"]").click()');await until('document.getElementById("deanStudentEditorModal").classList.contains("show") && document.getElementById("deanStudentEditorTitle").textContent==="Edit Student" && !document.getElementById("deanStudentEditorFields").disabled');await delay(350);
 check(await evaluate('document.querySelectorAll(".modal.show").length===1 && document.getElementById("deanStudentNumber").readOnly && document.getElementById("deanStudentCreationFields").hidden'),'Editing from email review opens one editor and protects the shared student ID: '+JSON.stringify(await evaluate('({modals:[...document.querySelectorAll(".modal.show")].map(m=>m.id),readOnly:document.getElementById("deanStudentNumber").readOnly,hidden:document.getElementById("deanStudentCreationFields").hidden,title:document.getElementById("deanStudentEditorTitle").textContent})')));
 await evaluate('window.__failSave=true;document.getElementById("deanStudentEmail").value="josepena@sksu.edu.ph";document.getElementById("deanStudentEditorForm").requestSubmit()');await until('document.getElementById("deanStudentEditorNotice").textContent.includes("changed by another")');
 check(await evaluate('document.getElementById("deanStudentEditorModal").classList.contains("show") && !document.getElementById("deanStudentEditorSave").disabled && window.__requests.filter(r=>r.action==="student_save").at(-1).version==="server-version"'),'Editor shows stale-record errors and sends the loaded version.');
 await evaluate('window.__failSave=false;document.getElementById("deanStudentEditorForm").requestSubmit()');await until('!document.getElementById("deanStudentEditorModal").classList.contains("show")');
 check(await evaluate('window.__requests.every(r=>r.csrf_token && r.college_id && r.campus_id)'),'Management requests retain authoritative scope and CSRF fields.');
 console.log(`Student management browser: ${checks} checks passed at desktop and mobile widths.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
 if(call)try{await call('Browser.close');}catch{}
 if(ws)ws.close();
 if(browser&&browser.exitCode===null){browser.kill();await new Promise(resolve=>browser.once('exit',resolve));}
 if(fs.existsSync(route))fs.unlinkSync(route);
 if(path.dirname(profile)===path.join(root,'.tmp') && path.basename(profile)===`student-management-browser-${nonce}`) {
   for(let i=0;i<8;i++)try{fs.rmSync(profile,{recursive:true,force:true});break;}catch{await delay(250);}
 }
});
