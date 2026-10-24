// node tests/scheduler-tba-browser.cjs [--screenshot]
// Local browser checks use rolled-back fixtures and intercept every save request.
const fs=require('node:fs');
const path=require('node:path');
const {spawn}=require('node:child_process');
const crypto=require('node:crypto');
const root=path.resolve(__dirname,'..');
const temp=path.join(root,'.tmp');
const nonce=crypto.randomBytes(8).toString('hex');
const token=crypto.randomBytes(24).toString('hex');
const phpQuote=s=>"'"+s.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
const apiName=`tba-browser-api-${nonce}.php`;
const apiPath=path.join(root,'tmp',apiName);
const pageName=`tba-browser-page-${nonce}.php`;
const pagePath=path.join(root,'tmp',pageName);
const profile=path.join(temp,`workload-browser-${nonce}`);
let browser,ws;
let checks=0;
const assert=(value,message)=>{if(!value)throw new Error(message);checks++;};
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function main(){
  const guard=`if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !hash_equals(${phpQuote(token)}, (string)($_GET['qa'] ?? ''))) { http_response_code(404);exit; }`;
  fs.writeFileSync(apiPath,`<?php ${guard} define('SYNK_SCHEDULER_TBA_QA',true); require ${phpQuote(path.join(root,'tests','fixtures','scheduler-tba-request.php'))};`);
  const fixture=async scenario=>{
    const response=await fetch(`http://localhost/synk/tmp/${apiName}?qa=${token}&scenario=${scenario}`,{method:'POST'});
    const data=await response.json();
    if(data.fixture_error)throw new Error(data.fixture_error);
    return data;
  };
  const rendered=await fixture('page');
  assert(typeof rendered.response==='string' && rendered.response.includes('btnSaveScheduleBlocks') && !/Fatal error|Parse error/.test(rendered.response),'Scheduling page did not render.');
  const loaded=await fixture('load_assigned');
  assert(loaded.response.status==='ok','Pending block fixture failed.');
  const html=rendered.response;
  fs.writeFileSync(pagePath,`<?php ${guard} header('Content-Type: text/html; charset=utf-8'); echo base64_decode(${phpQuote(Buffer.from(html).toString('base64'))});`);
  const executable=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env.ProgramFiles+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(name=>fs.existsSync(name));
  if(!executable)throw new Error('A Chromium browser is required.');
  browser=spawn(executable,['--headless=new','--disable-gpu','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
  const socket=await new Promise((resolve,reject)=>{
    const timeout=setTimeout(()=>reject(new Error('Browser startup timed out.')),15000);
    let logs='';browser.stderr.on('data',data=>{logs+=data;const match=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timeout);resolve(match[1]);}});
    browser.on('error',reject);
  });
  ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  let sequence=0;const pending=new Map();
  ws.addEventListener('message',event=>{const message=JSON.parse(event.data);if(message.id && pending.has(message.id)){const entry=pending.get(message.id);pending.delete(message.id);clearTimeout(entry.timeout);message.error?entry.reject(new Error(message.error.message)):entry.resolve(message.result);}});
  const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{
    const id=++sequence;const timeout=setTimeout(()=>{pending.delete(id);reject(new Error(method+' timed out.'));},15000);
    pending.set(id,{resolve,reject,timeout});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));
  });
  const target=await call('Target.createTarget',{url:'about:blank'});
  const attached=await call('Target.attachToTarget',{targetId:target.targetId,flatten:true});const session=attached.sessionId;
  await call('Page.enable',{},session);await call('Runtime.enable',{},session);
  const evaluate=async expression=>{
    const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);
    if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  const until=async(expression,message)=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await delay(100);}throw new Error(message);};
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
  await call('Page.navigate',{url:`http://localhost/synk/tmp/${pageName}?qa=${token}`},session);
  await until('window.jQuery && document.readyState === "complete" && document.getElementById("btnSaveScheduleBlocks")','Scheduling scripts did not load.');
  await evaluate(`
    window.__tbaMode='empty';window.__savedTba=[];window.__browserErrors=[];
    window.addEventListener('error',e=>window.__browserErrors.push(e.message));
    window.__loaded=${JSON.stringify(loaded.response)};
    $.ajax=function(options){
      const deferred=$.Deferred();const data=options.data || {};let response;
      if(String(options.url).includes('load_term_room_options')){
        response={status:'ok',rooms:window.__tbaMode==='empty'?[]:[{room_id:900001,label:'QA Room',room_type:'lec_lab'}]};
      }else if(data.load_schedule_blocks){
        response=window.__tbaMode==='empty'?{status:'ok',blocks:[],required_types:['LEC','LAB'],editable_types:['LEC','LAB'],tba_ready:true,has_assigned_blocks:false}:JSON.parse(JSON.stringify(window.__loaded));
        if(window.__tbaMode==='assigned'){
          response.blocks[0].schedule_status='scheduled';response.blocks[0].days=['M'];response.blocks[0].time_start='10:00:00';response.blocks[0].time_end='12:00:00';response.blocks[0].room_id=900001;
        }
        if(window.__tbaMode==='room'){
          response.blocks[0].schedule_status='room_tba';response.blocks[0].days=['M'];response.blocks[0].time_start='10:00:00';response.blocks[0].time_end='12:00:00';response.blocks[0].room_id=0;
        }
      }else if(data.save_schedule_blocks){window.__savedTba.push(JSON.parse(JSON.stringify(data)));response={status:'ok'};
      }else if(data.load_schedule_faculty_options){response={status:'ok',faculties:[],faculty_options:[],options:[]};
      }else if(String(options.url).includes('load_class_offerings')){response='';
      }else{response={status:'ok',rows:[],entries:[],options:[],faculties:[],programs:[],sets:[],live_status:{}};}
      setTimeout(()=>{if(typeof options.success==='function')options.success(response);deferred.resolve(response);},5);
      return deferred.promise({readyState:4,abort(){}});
    };
    const ay=document.getElementById('ay_id');if(!ay.value)ay.value=Array.from(ay.options).find(o=>o.value).value;
    const sem=document.getElementById('semester');if(!sem.value)sem.value=Array.from(sem.options).find(o=>o.value).value;
    const button=document.createElement('button');button.id='tbaQaOpen';button.className='btn-schedule';
    button.setAttribute('data-offering-id','900999');button.setAttribute('data-sub-code','QA 111');button.setAttribute('data-sub-desc','QA TBA Subject');button.setAttribute('data-section','QA 1A');
    button.setAttribute('data-lab-units','3');button.setAttribute('data-lec-units','2');button.setAttribute('data-total-units','3');document.body.append(button);
    button.click();
  `);
  await until('document.querySelectorAll(".schedule-block-status").length===2 && $("#blockScheduleModal").hasClass("show")','Block editor could not open without rooms.');
  await until('document.activeElement?.closest("#blockScheduleModal")','Block editor opening transition did not finish.');
  assert(await evaluate('document.querySelectorAll(".schedule-block-status option[value=tba]").length===2'),'TBA was not available per lecture/lab block.');
  await evaluate('for(let i=0;i<2;i++){const select=document.querySelectorAll(".schedule-block-status")[i];select.value="tba";select.dispatchEvent(new Event("change",{bubbles:true}));}');
  assert(await evaluate('Array.from(document.querySelectorAll(".schedule-block-card .row.g-3")).every(row=>row.classList.contains("d-none"))'),'Full TBA still showed reserved-slot fields.');
  assert(await evaluate('document.getElementById("scheduleBlockCoverageSummary").textContent.includes("TBA")'),'TBA was marked fully scheduled.');
  await evaluate('document.getElementById("btnSaveScheduleBlocks").click()');
  await until('window.__savedTba.length===1','Full TBA did not save without rooms.');
  const saved=await evaluate('window.__savedTba[0].blocks');
  assert(saved.length===2 && saved.every(block=>block.schedule_status==='tba' && !block.room_id && !block.time_start && !block.time_end && block.days_json==='[]'),'TBA submit retained a real slot.');
  await until('!$("#blockScheduleModal").hasClass("show") && !Swal.isVisible()','Modal did not close after save.');
  await evaluate('window.__tbaMode="assigned";document.getElementById("tbaQaOpen").click()');
  await until('document.querySelector(".schedule-block-status") && !document.querySelector(".schedule-block-status").disabled && $("#blockScheduleModal").hasClass("show")','Assigned editor did not load.');
  await until('document.activeElement?.closest("#blockScheduleModal")','Assigned editor opening transition did not finish.');
  assert(await evaluate('document.getElementById("btnAddLectureBlock").disabled && document.getElementById("btnAddLabBlock").disabled'),'Assigned block counts could change load totals.');
  assert(await evaluate('document.querySelectorAll(".btn-remove-schedule-block").length===0'),'Assigned meetings could be removed.');
  assert(await evaluate('document.getElementById("btnClearBlockSchedule").disabled'),'Assigned pending meetings could be cleared.');
  assert(await evaluate('!document.querySelector(".schedule-block-time-start").disabled && !document.querySelector(".schedule-block-room").disabled'),'Confirmed assigned meeting fields are still locked.');
  await evaluate(`
    const select=document.querySelectorAll('.schedule-block-status')[1];select.value='scheduled';select.dispatchEvent(new Event('change',{bubbles:true}));
    const card=document.querySelectorAll('.schedule-block-card')[1];card.querySelector('.schedule-block-day[value=M]').checked=true;card.querySelector('.schedule-block-time-start').value='13:00';card.querySelector('.schedule-block-time-end').value='16:00';card.querySelector('.schedule-block-room').value='900001';
    document.getElementById('btnSaveScheduleBlocks').click();
  `);
  await until('window.__savedTba.length===2','Assigned TBA could not be arranged.');
  const resolved=await evaluate('window.__savedTba[1].blocks');
  assert(resolved[0].schedule_id===loaded.response.blocks[0].schedule_id && resolved[1].schedule_id===loaded.response.blocks[1].schedule_id,'Resolution replaced meeting IDs.');
  assert(resolved.every(block=>block.schedule_status==='scheduled'),'Resolution retained an incorrect pending status.');
  await until('!$("#blockScheduleModal").hasClass("show") && !Swal.isVisible()','Resolved modal did not close.');
  await evaluate(`window.__loaded.blocks=${JSON.stringify(resolved)}.map(block=>({...block,days:JSON.parse(block.days_json),is_inherited:false,is_editable:true}));window.__tbaMode='confirmed';document.getElementById('tbaQaOpen').click();`);
  await until('document.querySelectorAll(".schedule-block-status").length===2 && $("#blockScheduleModal").hasClass("show")','Confirmed assigned editor did not reopen.');
  assert(await evaluate('Array.from(document.querySelectorAll(".schedule-block-time-start,.schedule-block-time-end,.schedule-block-room,.schedule-block-status")).every(input=>!input.disabled)'),'Reopened confirmed meetings are locked.');
  await evaluate(`
    const card=document.querySelector('.schedule-block-card');card.querySelector('.schedule-block-time-start').value='08:00';card.querySelector('.schedule-block-time-end').value='10:00';
    document.getElementById('btnSaveScheduleBlocks').click();
  `);
  await until('window.__savedTba.length===3','Confirmed assigned schedule could not be edited after reopening.');
  assert(await evaluate('window.__savedTba[2].blocks[0].time_start==="08:00" && window.__savedTba[2].blocks[0].schedule_id===window.__loaded.blocks[0].schedule_id'),'Confirmed edit lost its meeting ID or new time.');
  await until('!$("#blockScheduleModal").hasClass("show") && !Swal.isVisible()','Confirmed edit did not close.');
  await evaluate('window.__tbaMode="room";document.getElementById("tbaQaOpen").click()');
  await until('document.querySelector(".schedule-block-status")?.value==="room_tba" && $("#blockScheduleModal").hasClass("show")','Room TBA editor did not load.');
  await until('document.activeElement?.closest("#blockScheduleModal")','Room editor opening transition did not finish.');
  assert(await evaluate('!document.querySelector(".schedule-block-time-start").disabled && Array.from(document.querySelectorAll(".schedule-block-card:first-child .schedule-block-day")).some(input=>!input.disabled)'),'Assigned Room TBA days and times are locked.');
  await evaluate('const roomSelect=document.querySelector(".schedule-block-status");roomSelect.value="scheduled";roomSelect.dispatchEvent(new Event("change",{bubbles:true}));');
  assert(await evaluate('!document.querySelector(".schedule-block-room").disabled && document.querySelector(".schedule-block-time-start").disabled'),'Room resolution did not preserve fixed times.');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false},session);
  assert(await evaluate('document.querySelector("#blockScheduleModal .modal-content").getBoundingClientRect().width<=window.innerWidth'),'TBA editor overflowed mobile width.');
  await evaluate(`(() => {const body=document.querySelector('#blockScheduleModal .modal-body');const card=body.querySelector('.schedule-block-card');body.scrollTop+=card.getBoundingClientRect().top-body.getBoundingClientRect().top;})()`);
  assert(await evaluate('document.querySelector(".schedule-block-time-end").getBoundingClientRect().right <= document.querySelector(".schedule-block-card").getBoundingClientRect().right'),'Time controls overflowed the mobile card.');
  assert(await evaluate('document.querySelector("#blockScheduleModal .modal-body").scrollWidth <= document.querySelector("#blockScheduleModal .modal-body").clientWidth + 3'),'Pending controls caused horizontal scrolling on mobile.');
  assert((await evaluate('window.__browserErrors')).length===0,'Browser scripts raised an error.');
  if(process.argv.includes('--screenshot')) {
    const screenshot=await call('Page.captureScreenshot',{format:'png'},session);
    fs.writeFileSync(path.join(temp,`tba-browser-${nonce}.png`),Buffer.from(screenshot.data,'base64'));
  }
  console.log(`Scheduler TBA browser: ${checks} checks passed at 1440 px and 390 px. Save requests were intercepted; no production data was saved.`);
  await call('Browser.close');
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
  if(ws)ws.close();if(browser){browser.kill();await delay(800);}
  for(const file of [apiPath,pagePath])if(fs.existsSync(file))fs.unlinkSync(file);
  const resolved=path.resolve(profile);assert(resolved.startsWith(path.resolve(temp)+path.sep) && /^workload-browser-[0-9a-f]{16}$/.test(path.basename(resolved)),'Unexpected browser profile cleanup target.');
  if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
});
