const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn, spawnSync, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), nonce = crypto.randomBytes(8).toString('hex'), token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `manual-roster-browser-${nonce}.php`), profile = path.join(root, '.tmp', `manual-roster-browser-${nonce}`);
const url = `http://localhost/synk/tmp/${path.basename(route)}?qa=${token}`;
let browser, socket, call, checks = 0; const errors = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };

async function main() {
  const fixture = JSON.parse(execFileSync('php', ['tests/fixtures/college-dean-roster-request.php', 'page'], { cwd: root, windowsHide: true, encoding: 'utf8' }));
  const target = { ...fixture.target, subject_code: 'CS 101', descriptive_title: 'Introduction to Computing', section_display: 'BSIS 1A', faculty_name: 'Test Faculty', year_level: 1, roster_count: 0 };
  let html = fixture.response.replace('<head>', '<head><base href="http://localhost/synk/college-dean/">');
  const mock = `<script>
  const qaClass=${JSON.stringify(target).replaceAll('<', '\u003c')};
  window.__manualRequests=[];window.__manualStudents=[];window.__manualSaves=0;window.__manualPending=null;window.__manualError='';window.__manualHold=false;window.__manualHeld=null;window.__xssExecuted=false;window.__clearSaves=0;
  window.fetch=async function(url,options){
    const p=Object.fromEntries(options.body.entries());window.__manualRequests.push(p);let data;
    if(p.action==='bootstrap')data={status:'ok',import_ready:true,can_import:!location.search.includes('history=1'),can_add_students:!location.search.includes('history=1'),can_clear_students:!location.search.includes('history=1'),faculties:[{faculty_id:qaClass.faculty_id,faculty_name:'Test Faculty',profile_image_url:''}]};
    else if(p.action==='classes')data={status:'ok',faculty:{faculty_id:qaClass.faculty_id,faculty_name:'Test Faculty',profile_image_url:''},classes:[{...qaClass,roster_count:window.__manualStudents.length}]};
    else if(p.action==='workload')data={status:'ok',rows:[],meta:{total_preparations:1,total_load:3}};
    else if(p.action==='roster')data={status:'ok',class:qaClass,students:window.__manualStudents};
    else if(url.includes('query_college_dean_clear_roster.php')){
      if(window.__manualHold){window.__manualHold=false;await new Promise(resolve=>{window.__manualHeld=resolve;});}
      if(window.__manualError)return {ok:false,json:async()=>({status:'error',message:window.__manualError})};
      if(p.action==='preview')data={status:'ok',preview_token:'c'.repeat(64),class:qaClass,students:window.__manualStudents,summary:{students:window.__manualStudents.length,memberships:window.__manualStudents.length}};
      else if(p.action==='confirm'){window.__clearSaves++;window.__manualStudents=[];data={status:'ok',result:{offering_id:qaClass.offering_id,roster_count:0,message:'Class cleared. Students remain in the Students list.'}};}
      else if(p.action==='cancel')data={status:'ok'};
      else throw Error('Unexpected clear action '+p.action);
    }
    else if(url.includes('query_college_dean_manual_roster.php')){
      if(window.__manualHold){window.__manualHold=false;await new Promise(resolve=>{window.__manualHeld=resolve;});}
      if(window.__manualError)return {ok:false,json:async()=>({status:'error',message:window.__manualError})};
      if(p.action==='lookup')data={status:'ok',found:p.student_number!=='000124',student:p.student_number==='000124'?null:{student_number:p.student_number,student_name:p.student_number==='000125'?'Transfer, Student':'Peña, José Niño',version:'profile-version'},programs:[{program_id:qaClass.program_id,label:'BSIS'}]};
      else if(p.action==='preview'){
        if(window.__manualStudents.some(s=>s.student_number===p.student_number))return {ok:false,json:async()=>({status:'error',message:'This student is already listed in this class.'})};
        const transfer=p.student_number==='000125';
        const review={student_number:p.student_number,student_name:p.student_mode==='new'?p.last_name+', '+p.first_name:p.student_number==='000125'?'Transfer, Student':'Peña, José Niño',new_profile:p.student_mode==='new',email_address:p.email_address,student_program:'BSIS',student_year_level:Number(p.student_year_level),target:qaClass,reason:{late_enrollee:'Late enrollee',transferee:'Transferee',list_correction:'Class-list correction'}[p.reason_code],note:p.reason_note,action:transfer?'transfer':'add',previous_classes:transfer?[{subject_code:'CS 101',section:'BSIS 1B',faculty_name:'Previous Faculty'}]:[]};
        window.__manualPending={token:'a'.repeat(64),review};data={status:'ok',preview_token:window.__manualPending.token,review};
      }else if(p.action==='confirm'){
        const review=window.__manualPending.review;window.__manualSaves++;
        window.__manualStudents.push({student_id:window.__manualSaves,student_number:review.student_number,student_name:review.student_name,entry_source:'manual',manual_reason_label:review.reason,manual_note:review.note,added_by:'QA Dean',manually_added_at:'2026-10-20 00:00:00'});
        data={status:'ok',result:{student_id:window.__manualSaves,offering_id:qaClass.offering_id,roster_count:window.__manualStudents.length,message:review.action==='transfer'?'Student transferred to this class.':'Student added to this class.'}};window.__manualPending=null;
      }else if(p.action==='cancel'){window.__manualPending=null;data={status:'ok'};}
      else throw Error('Unexpected manual action '+p.action);
    }else throw Error('Unexpected request '+p.action);
    return {ok:true,json:async()=>data};
  };</script>`;
  html = html.replace('<script src="faculty-workload.js', mock + '<script src="faculty-workload.js');
  fs.writeFileSync(route, `<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals('${token}',$_GET['qa'])){http_response_code(404);exit;}header('Content-Type: text/html; charset=utf-8');echo base64_decode('${Buffer.from(html).toString('base64')}');`);
  const executable = [process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe', process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  browser = spawn(executable, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const address = await new Promise((resolve, reject) => { let output = '';const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{output+=data;const match=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timer);resolve(match[1]);}});browser.on('error',reject); });
  socket = new WebSocket(address); await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
  let sequence=0;const pending=new Map();
  socket.addEventListener('message',event=>{const message=JSON.parse(event.data);if(message.id&&pending.has(message.id)){const item=pending.get(message.id);pending.delete(message.id);clearTimeout(item.timer);message.error?item.reject(Error(message.error.message)):item.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);});
  call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timed out.'));},15000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
  const tab=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:tab.targetId,flatten:true})).sessionId;
  await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Network.enable',{},session);await call('Network.setBlockedURLs',{urls:['https://*']},session);
  const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate(`Boolean(${expression})`))return;await delay(100);}throw Error('Timed out: '+expression);};
  const click=id=>evaluate(`document.getElementById(${JSON.stringify(id)}).click()`);
  const settle=()=>until('document.getElementById("deanManualRosterModal").getAttribute("aria-busy")==="false"');
  const resize=async width=>{await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false},session);await delay(150);};
  const shown=id=>until(`document.getElementById(${JSON.stringify(id)}).classList.contains('show')&&!bootstrap.Modal.getInstance(document.getElementById(${JSON.stringify(id)}))._isTransitioning`);
  const hidden=id=>until(`!document.getElementById(${JSON.stringify(id)}).classList.contains('show')&&!bootstrap.Modal.getInstance(document.getElementById(${JSON.stringify(id)}))._isTransitioning`);
  const screenshot=async name=>fs.writeFileSync(path.join(root,'.tmp',name),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
  const open=async()=>{await until('!document.querySelector(".modal-backdrop")&&!document.getElementById("deanRefresh").disabled');await evaluate('document.querySelector("[data-manual-add]").click()');await shown('deanManualRosterModal');};
  const lookup=async number=>{await evaluate(`document.getElementById('deanManualNumber').value=${JSON.stringify(number)};document.getElementById('deanManualNumber').dispatchEvent(new Event('input'));document.getElementById('deanManualLookupForm').requestSubmit()`);await settle();};
  const review=async(reason='late_enrollee')=>{await evaluate(`document.getElementById('deanManualReason').value=${JSON.stringify(reason)};document.getElementById('deanManualDetailsForm').requestSubmit()`);await settle();};
  await resize(1440);await call('Page.navigate',{url},session);await until('document.getElementById("deanFaculty")&&!document.getElementById("deanFaculty").disabled');
  await evaluate(`document.getElementById('deanFaculty').value=${JSON.stringify(String(target.faculty_id))};document.getElementById('deanFaculty').dispatchEvent(new Event('change'))`);await until('document.querySelector("[data-manual-add]")&&!document.getElementById("deanRefresh").disabled');
  check(await evaluate('document.querySelectorAll(".dean-class-actions button").length===3'),'Class cards provide Add Student, View Students and Import CSV.');
  for(const width of [1440,1280,768,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Class actions fit ${width}px.`);}
  await resize(1440);await open();await lookup('000123');
  check(await evaluate('document.getElementById("deanManualIdentity").textContent.includes("Peña, José Niño")&&document.getElementById("deanManualNewFields").hidden&&!document.getElementById("deanManualReviewButton").disabled'),'Existing IDs reuse the saved identity without exposing editable profile fields.');
  await evaluate('document.getElementById("deanManualNote").value=\'<img src=x onerror="window.__xssExecuted=true">\'');await review();
  check(await evaluate('!document.getElementById("deanManualReview").hidden&&!document.querySelector("#deanManualReviewContent img")&&!window.__xssExecuted&&window.__manualSaves===0'),'Review is read only and escapes notes.');
  await click('deanManualBack');await settle();await until('window.__manualRequests.some(r=>r.action==="cancel")');
  check(await evaluate('!document.getElementById("deanManualEntry").hidden&&document.getElementById("deanManualReview").hidden'),'Back returns to editable details and discards the previous review.');
  await review();await evaluate('document.getElementById("deanManualConfirm").click();document.getElementById("deanManualConfirm").click()');await hidden('deanManualRosterModal');await until('document.getElementById("deanNotice").textContent.includes("Student added")&&!document.getElementById("deanRefresh").disabled');
  check(await evaluate('window.__manualSaves===1&&document.getElementById("deanNotice").textContent.includes("Student added")'),'Double clicking cannot save an addition twice, and the workload refreshes: '+JSON.stringify(await evaluate('({saves:window.__manualSaves,notice:document.getElementById("deanNotice").textContent,manualNotice:document.getElementById("deanManualNotice").textContent,requests:window.__manualRequests.map(r=>({action:r.action,faculty_id:r.faculty_id})),errors:document.querySelectorAll(".modal.show").length})')));
  await evaluate('document.querySelector("[data-roster]").click()');await shown('deanRosterModal');await until('document.getElementById("deanStudentTotal").textContent==="1 students"');
  check(await evaluate('document.getElementById("deanRosterContent").textContent.includes("Manual")&&document.getElementById("deanRosterContent").textContent.includes("QA Dean")&&!document.querySelector("#deanRosterContent img")'),'Roster details identify manual source, actor and escaped notes.');
  await click('deanRosterAddStudent');await shown('deanManualRosterModal');
  check(await evaluate('document.querySelectorAll(".modal.show").length===1&&!document.getElementById("deanRosterModal").classList.contains("show")'),'Adding from View Students opens a single modal.');
  await lookup('000124');
  await evaluate('document.getElementById("deanManualFirst").value="María";document.getElementById("deanManualLast").value="Muñoz";document.getElementById("deanManualYear").value="2"');
  check(await evaluate('!document.getElementById("deanManualNewFields").hidden&&!document.getElementById("deanManualNewFields").disabled&&document.getElementById("deanManualEmail").value===""'),'An unknown ID opens a new profile form and permits pending email verification.');
  for(const width of [1440,768,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth&&document.querySelector("#deanManualRosterModal .modal-content").getBoundingClientRect().right<=innerWidth&&document.querySelector("#deanManualRosterModal .modal-body").scrollWidth<=document.querySelector("#deanManualRosterModal .modal-body").clientWidth'),`The manual student form fits ${width}px.`);if(width===1440||width===390)await screenshot(`manual-class-roster-${width}.png`);}
  await review('transferee');
  check(await evaluate('document.getElementById("deanManualReviewContent").textContent.includes("BSIS · Year 2")&&document.getElementById("deanManualReviewContent").textContent.includes("Pending verification")'),'Review distinguishes the student program/year from the destination class and shows pending email verification.');
  await click('deanManualConfirm');await hidden('deanManualRosterModal');await shown('deanRosterModal');await until('document.getElementById("deanStudentTotal").textContent==="2 students"');
  check(await evaluate('document.getElementById("deanRosterContent").textContent.includes("Muñoz, María")&&window.__manualSaves===2'),'A successful addition from the roster returns to the updated roster.');
  await evaluate('bootstrap.Modal.getInstance(document.getElementById("deanRosterModal")).hide()');await hidden('deanRosterModal');await resize(1440);await open();await lookup('000125');await review('list_correction');
  check(await evaluate('!document.getElementById("deanManualTransfer").hidden&&document.getElementById("deanManualPrevious").textContent.includes("BSIS 1B")&&document.getElementById("deanManualConfirm").disabled'),'Section transfers list the previous class and require acknowledgement.');
  await click('deanManualConfirm');check(await evaluate('window.__manualSaves===2'),'An unconfirmed transfer cannot submit.');
  await evaluate('document.getElementById("deanManualTransferConfirmed").checked=true;document.getElementById("deanManualTransferConfirmed").dispatchEvent(new Event("change"))');await click('deanManualConfirm');await hidden('deanManualRosterModal');await until('!document.getElementById("deanRefresh").disabled');
  check(await evaluate('window.__manualSaves===3&&window.__manualRequests.filter(r=>r.action==="confirm").at(-1).confirm_transfer==="1"'),'Transfer consent is sent explicitly on confirmation.');
  await open();await lookup('000123');await review();
  check(await evaluate('document.getElementById("deanManualNotice").textContent.includes("already listed")&&document.getElementById("deanManualConfirm").hidden'),'Duplicate membership errors leave no confirmable preview: '+JSON.stringify(await evaluate('({notice:document.getElementById("deanManualNotice").textContent,hidden:document.getElementById("deanManualConfirm").hidden,requests:window.__manualRequests.slice(-3),valid:document.getElementById("deanManualDetailsForm").checkValidity()})')));
  await evaluate('window.__manualError="Your college access changed. Reload the page."');await lookup('000124');
  check(await evaluate('document.getElementById("deanManualNotice").textContent.includes("college access changed")&&document.getElementById("deanManualReviewButton").disabled&&!document.getElementById("deanManualClose").disabled'),'Lookup errors are visible, retryable and cannot reuse an old identity.');
  await evaluate('window.__manualError="";window.__manualHold=true;document.getElementById("deanManualLookupForm").requestSubmit()');await until('Boolean(window.__manualHeld)');
  await evaluate('bootstrap.Modal.getInstance(document.getElementById("deanManualRosterModal")).hide()');
  check(await evaluate('document.getElementById("deanManualRosterModal").classList.contains("show")&&document.getElementById("deanManualClose").disabled'),'An in-flight request cannot be dismissed into an uncertain state.');
  await evaluate('window.__manualHeld();window.__manualHeld=null');await settle();await click('deanManualCancel');await hidden('deanManualRosterModal');
  check(await evaluate('window.__manualRequests.filter(r=>r.action==="confirm").length===3&&window.__manualRequests.every(r=>r.csrf_token&&r.college_id&&r.campus_id&&r.ay_id&&r.semester)'),'Only reviewed additions save, with scope and semester carried on every request.');
  await until('!document.querySelector(".modal-backdrop")&&!document.getElementById("deanRefresh").disabled');
  await evaluate('window.__manualStudents[0].entry_source="csv";window.__manualStudents[0].student_name="<img src=x onerror=window.__xssExecuted=true>";document.querySelector("[data-roster]").click()');await shown('deanRosterModal');await until('!document.getElementById("deanRosterClearStudents").disabled');
  await click('deanRosterClearStudents');await shown('deanClearRosterModal');await until('!document.getElementById("deanClearConfirmed").disabled');
  check(await evaluate('document.getElementById("deanClearPreview").textContent.includes("3 students")&&document.getElementById("deanClearPreview").textContent.includes("Imported")&&document.getElementById("deanClearPreview").textContent.includes("Manual")'),'Clear preview includes all imported and manual students.');
  check(await evaluate('!window.__xssExecuted&&!document.getElementById("deanClearPreview").querySelector("img")&&document.getElementById("deanClearPreview").textContent.includes("<img")'),'Clear preview escapes saved student names.');
  check(await evaluate('document.getElementById("deanClearConfirm").disabled&&document.getElementById("deanClearRosterModal").textContent.includes("remain in the Students list")'),'Clearing explains preservation and requires an explicit acknowledgement.');
  for(const width of [1440,390]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Clear modal fits viewport '+width);const shot=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(root,'.tmp',`clear-class-roster-${width}.png`),Buffer.from(shot.data,'base64'));}
  await click('deanClearCancel');await hidden('deanClearRosterModal');await shown('deanRosterModal');await until('document.getElementById("deanStudentTotal").textContent==="3 students"');
  check(await evaluate('window.__clearSaves===0'),'Cancel preserves the class list and returns to the roster.');
  await evaluate('window.__manualError="The class list changed. Preview again."');await click('deanRosterClearStudents');await shown('deanClearRosterModal');await until('!document.getElementById("deanClearRetry").hidden');
  check(await evaluate('document.getElementById("deanClearConfirm").disabled&&document.getElementById("deanClearNotice").textContent.includes("changed")'),'Failed previews cannot be confirmed and offer a new preview.');
  await evaluate('window.__manualError=""');await click('deanClearRetry');await until('!document.getElementById("deanClearAcknowledgement").hidden');
  await evaluate('document.getElementById("deanClearConfirmed").checked=true;document.getElementById("deanClearConfirmed").dispatchEvent(new Event("change"));window.__manualHold=true');await click('deanClearConfirm');await until('Boolean(window.__manualHeld)');
  await evaluate('bootstrap.Modal.getInstance(document.getElementById("deanClearRosterModal")).hide();document.getElementById("deanClearConfirm").click()');
  check(await evaluate('document.getElementById("deanClearRosterModal").classList.contains("show")&&document.getElementById("deanClearClose").disabled&&window.__clearSaves===0'),'An in-flight clearing cannot be closed or submitted twice.');
  await evaluate('window.__manualHeld();window.__manualHeld=null');await hidden('deanClearRosterModal');await shown('deanRosterModal');await until('document.getElementById("deanStudentTotal").textContent==="0 students"');
  check(await evaluate('window.__clearSaves===1&&document.getElementById("deanRosterClearStudents").disabled&&document.getElementById("deanNotice").textContent.includes("Students remain")'),'Clearing refreshes the roster, disables clearing an empty list and reports preserved students.');
  await evaluate('bootstrap.Modal.getInstance(document.getElementById("deanRosterModal")).hide()');await hidden('deanRosterModal');
  await call('Page.navigate',{url:url+'&history=1'},session);await until('location.search.includes("history=1")&&document.readyState==="complete"&&document.getElementById("deanFaculty")&&!document.getElementById("deanFaculty").disabled');await evaluate(`document.getElementById('deanFaculty').value=${JSON.stringify(String(target.faculty_id))};document.getElementById('deanFaculty').dispatchEvent(new Event('change'))`);await until('document.querySelector("[data-roster]")&&!document.getElementById("deanRefresh").disabled');
  check(await evaluate('!document.querySelector("[data-manual-add]")&&document.querySelector("[data-import]").hidden'),'Historical terms expose viewing while hiding class-add and import controls: '+JSON.stringify(await evaluate('({search:location.search,hasAdd:!!document.querySelector("[data-manual-add]"),importHidden:document.querySelector("[data-import]").hidden,requests:window.__manualRequests.map(r=>r.action)})')));
  await evaluate('document.querySelector("[data-roster]").click()');await shown('deanRosterModal');check(await evaluate('document.getElementById("deanRosterClearStudents").hidden'),'Historical class lists hide clearing.');
  check(errors.length===0,'No browser errors: '+errors.join('; '));
  console.log(`Manual additions and class clearing browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
  if(call)try{await call('Browser.close');}catch(_){}
  if(socket?.readyState===WebSocket.OPEN)socket.close();if(browser&&browser.exitCode===null)browser.kill();await delay(500);
  if(fs.existsSync(route))fs.unlinkSync(route);
  if(fs.existsSync(profile)){const quote=value=>"'"+value.replaceAll("'","''")+"'";const cleanup=`$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root,'.tmp'))}).Path;$profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path;if(-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)){throw 'Profile cleanup is outside the workspace.'};Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;const result=spawnSync('powershell.exe',['-NoProfile','-Command',cleanup],{windowsHide:true,encoding:'utf8'});if(result.status!==0){console.error(result.stderr);process.exitCode=1;}}
});
