const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`scheduler-lists-browser-${nonce}.php`),profile=path.join(root,'.tmp',`scheduler-lists-browser-${nonce}`);
let browser,ws,call,checks=0,targetTerm={ay_id:2,semester:2,term_text:'Current academic term'},kind='faculty';
let copied=[],removed=false,failCopy=false,delaySource=false,testDesignation=7;
const requests=[],errors=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
function payload(request){const result={};if((request.postData||'').includes('Content-Disposition: form-data')){for(const m of request.postData.matchAll(/name="([^"]+)"\r\n\r\n([^\r\n]*)/g))result[m[1]]=m[2];}else Object.assign(result,Object.fromEntries(new URLSearchParams(request.postData||'')));return result;}
async function main(){
 fs.writeFileSync(route,`<?php define('SYNK_SCHEDULER_LIST_QA','${token}');require '${path.join(root,'tests/fixtures/scheduler-term-lists-request.php').replaceAll('\\','/')}';`);
 const exe=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
 browser=spawn(exe,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let logs='';const timeout=setTimeout(()=>reject(Error('Browser startup timeout.')),15000);browser.stderr.on('data',data=>{logs+=data;const m=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timeout);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 ws.addEventListener('message',event=>{
  const m=JSON.parse(event.data);
  if(m.id&&pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timeout);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}
  if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Fetch.requestPaused')(async()=>{
   const p=payload(m.params.request);requests.push({url:m.params.request.url,p});let body,status=200,type='application/json';
   if(m.params.request.url.includes('query_scheduler_term_lists.php')){
    if(p.action==='options')body={status:'ok',target:targetTerm,terms:[{key:'previous:1',ay_id:1,semester:1,label:'Previous year - 1st Semester'},{key:'previous:2',ay_id:1,semester:2,label:'Previous year - 2nd Semester'}]};
    else if(p.action==='preview'){
     if(delaySource&&p.source_semester==='1')await delay(500);
     const offset=p.source_semester==='2'?20:0;
     body={status:'ok',target:targetTerm,preview_token:'preview-'+kind+'-'+p.source_semester,rows:[
      {id:11+offset,label:kind==='faculty'?'COPY, Alpha':'ROOM 101 — Lecture',detail:'First available entry',eligible:true,reason:''},
      {id:12+offset,label:kind==='faculty'?'COPY, Beta':'ROOM 102 — Laboratory',detail:'Second available entry',eligible:true,reason:''},
      {id:13+offset,label:'Already listed entry',detail:'Current list',eligible:false,reason:'Already in current list'}]};
    }else if(p.action==='copy'){
     if(failCopy){status=409;body={status:'error',message:'The current academic term changed. Open a new preview.'};}
     else {copied=JSON.parse(p.selected_ids);body={status:'ok',copied:copied.length,skipped:0,target:targetTerm};}
    }else throw Error('Unexpected copy action: '+JSON.stringify(p));
   }else if(m.params.request.url.includes('query_college_faculty.php')){
    if(p.load_college_faculty){const ids=[...(removed?[]:[13]),...copied];body={status:'success',data:ids.map(id=>({college_faculty_id:100+id,faculty_id:id,full_name:id===13?'COPY, Existing':'COPY, Alpha',designation_id:testDesignation,designation_name:testDesignation===7?'Dean':testDesignation===8?'Program Chair':'',designation_style:'other',employment_classification:'permanent',employment_classification_label:'Permanent',status:'active',assigned_count:2})),pagination:{total:ids.length,has_more:false}};}
    else if(p.load_faculty_dropdown)body=[];
    else if(p.load_designation_options)body={status:'success',data:[{id:7,text:'Dean'},{id:8,text:'Program Chair'}]};
    else if(p.update_faculty_designation){testDesignation=Number(p.designation_id);body={status:'updated',designation_id:testDesignation,designation_name:testDesignation===8?'Program Chair':'',designation_style:''};}
    else if(p.remove_assignment){removed=true;body={status:'removed'};}
    else body={status:'success',data:[]};
   }else{
    type='text/html';body=`<tr><td>1</td><td>EXISTING</td><td>Existing room</td><td>QA</td><td>Owner</td><td>None</td><td>Lecture</td><td>40</td><td>Actions</td></tr>`;
   }
   await call('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:type}],body:Buffer.from(typeof body==='string'?body:JSON.stringify(body)).toString('base64')},m.sessionId);
  })().catch(error=>{console.error(error);errors.push(error.message);});
 });
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timeout=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout.'));},15000);pending.set(id,{resolve,reject,timeout});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 await call('Fetch.enable',{patterns:[{urlPattern:'*backend/query_scheduler_term_lists.php*',requestStage:'Request'},{urlPattern:'*backend/query_college_faculty.php*',requestStage:'Request'},{urlPattern:'*backend/query_rooms.php*',requestStage:'Request'}]},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate('Boolean('+expression+')'))return;await delay(100);}throw Error('Condition timed out: '+expression+'; errors: '+errors.join('; '));};
 for(kind of ['faculty','rooms']){
  copied=[];removed=false;failCopy=false;delaySource=false;
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
  await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=${kind}_page`},session);
  await until('document.getElementById("termListCopyConfirm") && window.Helpers?.mainMenu');await delay(300);
  if(kind==='faculty'){
   await until('document.querySelectorAll("#collegeFacultyTable tbody tr").length===1');
   targetTerm=await evaluate('({ay_id:facultyListState.termAyId,semester:facultyListState.termSemester,term_text:facultyListState.termText})');
   check(await evaluate('![...document.querySelectorAll("#collegeFacultyTable th")].some(th=>th.textContent.trim()==="Status") && !document.querySelector(".btn-reactivate-assignment")'),'Faculty list has no status or reactivation controls.');
   await evaluate('document.querySelector(".btn-edit-designation").click()');
   await until('document.getElementById("designationModal").classList.contains("show") && !document.querySelector("button[form=designationForm]").disabled');
   check(await evaluate('document.getElementById("designation_id").value==="7" && [...document.getElementById("designation_id").options].some(o=>o.value==="0"&&o.textContent==="No designation")'),'Designation dialog loads the existing appointment and an explicit removal option.');
   check(await evaluate('document.getElementById("designationTermNote").textContent.includes("Previous semesters")'),'Designation dialog explains which term changes.');
   await evaluate('jQuery("#designation_id").val("0").trigger("change");document.querySelector("button[form=designationForm]").click()');
   await until('document.querySelector(".btn-edit-designation").dataset.designationId==="0"');
   const designationRequest=requests.filter(r=>r.p.update_faculty_designation).at(-1).p;
   check(designationRequest.designation_id==="0"&&Number(designationRequest.ay_id)===targetTerm.ay_id&&Number(designationRequest.semester)===targetTerm.semester,'Removing submits No designation with the displayed academic term.');
   check(await evaluate('[...document.querySelectorAll(".faculty-designation-slot")].every(e=>e.textContent.trim()==="No designation") && document.querySelector(".btn-edit-designation").dataset.designationId==="0"'),'Removal updates both desktop and mobile faculty views.');
   await evaluate('Swal.close();document.querySelector(".btn-edit-designation").click()');
   await until('document.getElementById("designation_id").value==="0" && !document.querySelector("button[form=designationForm]").disabled');
   check(true,'Reopening preserves the cleared appointment.');
   await evaluate('jQuery("#designation_id").val("8").trigger("change");document.querySelector("button[form=designationForm]").click()');
   await until('document.querySelector(".btn-edit-designation").dataset.designationId==="8"');
   check(await evaluate('document.querySelector(".faculty-designation-slot").textContent==="Program Chair"'),'A new designation can be assigned after removal.');
   await evaluate('Swal.close()');

  }else{
   await until('window.jQuery?.fn.DataTable && typeof roomTable!=="undefined" && roomTable');
   targetTerm=await evaluate('({ay_id:Number(document.getElementById("ay_id").value),semester:Number(document.getElementById("semester").value),term_text:document.getElementById("ay_id").selectedOptions[0].textContent+" - "+document.getElementById("semester").selectedOptions[0].textContent})');
  }
  const copiesBefore=requests.filter(r=>r.p.action==='copy').length;
  await evaluate(`document.getElementById(${JSON.stringify(kind==='faculty'?'btnCopyPreviousFaculty':'btnOpenCopyRooms')}).click()`);
  await until('document.querySelectorAll("#termListCopyRows [data-copy-id]").length===3 && !document.getElementById("termListCopyConfirm").disabled');
  check(requests.filter(r=>r.p.action==='copy').length===copiesBefore,'Opening '+kind+' preview does not write data.');
  check(await evaluate(`document.querySelectorAll('[data-copy-id]:checked').length===2 && document.querySelector('[data-copy-id="13"]').disabled`),'Duplicate '+kind+' entries are disabled and only eligible entries are selected.');
  await evaluate('document.getElementById("termListCopyAll").checked=false;document.getElementById("termListCopyAll").dispatchEvent(new Event("change"))');
  check(await evaluate('document.getElementById("termListCopyConfirm").disabled && document.querySelectorAll("[data-copy-id]:checked").length===0'),'Clear selection disables copying for '+kind+'.');
  await evaluate(`document.querySelector('[data-copy-id="11"]').checked=true;document.querySelector('[data-copy-id="11"]').dispatchEvent(new Event("change",{bubbles:true}))`);
  check(await evaluate('document.getElementById("termListCopyConfirm").textContent.includes("(1)")'),'Selected '+kind+' count follows individual choices.');
  for(const width of [1440,1024,768,390,320]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768},session);await delay(150);
   check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector("#termListCopyModal .modal-content").scrollWidth<=document.querySelector("#termListCopyModal .modal-content").clientWidth+1'),kind+' copy preview fits '+width+'px.');
   if([1440,390].includes(width))fs.writeFileSync(path.join(root,'.tmp',`scheduler-${kind}-copy-${width}.png`),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
  }
  await evaluate('document.getElementById("termListCopyConfirm").click()');
  await until('!document.body.classList.contains("modal-open") && document.querySelector(".swal2-popup")');
  const applied=requests.filter(r=>r.p.action==='copy').at(-1);
  check(JSON.stringify(JSON.parse(applied.p.selected_ids))==='[11]','Copying '+kind+' sends only the checked entry.');
  await evaluate('Swal.close()');
  if(kind==='faculty'){
   await until('document.querySelectorAll("#collegeFacultyTable tbody tr").length===2');
   check(true,'The faculty list refreshes after selected copying.');
   await evaluate(`document.querySelector('.btn-remove-assignment[data-id="113"]').click()`);
   await until('document.querySelector(".swal2-confirm")');
   check(await evaluate('document.querySelector(".swal2-html-container").textContent.includes("faculty record") && document.querySelector(".swal2-html-container").textContent.includes("scheduled workloads")'),'Removal explains that faculty records and workloads are kept.');
   await evaluate('document.querySelector(".swal2-confirm").click()');
   await until('document.querySelectorAll("#collegeFacultyTable tbody tr").length===1');
   check(requests.some(r=>r.p.remove_assignment&&r.p.college_faculty_id==='113'),'Removal submits only the selected college-list assignment.');
   await evaluate('Swal.close()');
  }else check(await evaluate(`document.getElementById("ay_id").value===${JSON.stringify(String(targetTerm.ay_id))} && document.getElementById("semester").value===${JSON.stringify(String(targetTerm.semester))}`),'Rooms refresh in the current target term.');
  await evaluate(`document.getElementById(${JSON.stringify(kind==='faculty'?'btnCopyPreviousFaculty':'btnOpenCopyRooms')}).click()`);
  await until('document.querySelectorAll("#termListCopyRows [data-copy-id]").length===3 && !document.getElementById("termListCopyConfirm").disabled');
  delaySource=true;
  await evaluate('document.getElementById("termListCopySource").value="previous:1";document.getElementById("termListCopySource").dispatchEvent(new Event("change"));document.getElementById("termListCopySource").value="previous:2";document.getElementById("termListCopySource").dispatchEvent(new Event("change"))');
  await until(`document.querySelector('[data-copy-id="31"]')`);await delay(650);
  check(await evaluate(`document.querySelector('[data-copy-id="31"]')!==null && !document.querySelector('[data-copy-id="11"]')`),'Changing '+kind+' sources cannot restore a stale preview.');
  failCopy=true;
  await evaluate('document.getElementById("termListCopyConfirm").click()');
  await until('!document.getElementById("termListCopyNotice").hidden && document.getElementById("termListCopyNotice").textContent.includes("current academic term changed")');
  check(await evaluate('document.getElementById("termListCopyModal").classList.contains("show") && document.getElementById("termListCopyConfirm").disabled && !document.getElementById("termListCopyCancel").disabled'),'A stale '+kind+' target keeps the error visible and requires a fresh preview.');
  await evaluate('document.getElementById("termListCopyCancel").click()');await until('!document.body.classList.contains("modal-open")');
 }
 check(errors.length===0,'No browser runtime errors: '+errors.join('; '));
 console.log(`Scheduler term lists: ${checks} browser checks passed.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
 if(call)try{await call('Browser.close');}catch{}
 if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(resolve=>browser.once('exit',resolve));}
 if(fs.existsSync(route))fs.unlinkSync(route);const resolved=path.resolve(profile);if(resolved.startsWith(path.resolve(root,'.tmp')+path.sep)&&fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true});
});
