// Render real rollback-only DI pages in Chromium. Intercept every data request.
const fs=require('node:fs'), path=require('node:path'), crypto=require('node:crypto');
const {spawn,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'), temp=path.join(root,'.tmp');
const nonce=crypto.randomBytes(8).toString('hex'), token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`di-browser-${nonce}.php`), profile=path.join(temp,`di-browser-${nonce}`);
const quote=text=>"'"+text.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
const fixture=scenario=>JSON.parse(execFileSync(process.env.PHP_BINARY||'C:/xampp/php/php.exe',[path.join(root,'tests/fixtures/di-curriculum-request.php'),scenario],{encoding:'utf8'}));
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let browser,socket,checks=0;
function check(value,message){if(!value)throw Error(message);checks++;}
async function main(){
    fs.mkdirSync(temp,{recursive:true});
    const pages={},headers={};
    const structure=fixture('structure_layout').body;
    for(const name of ['manage-programs','manage-subject-masterlist','manage-prospectus','manage-prospectus-browser']){
        const rendered=fixture('page_'+name);
        headers[name]=rendered.header;
        check(rendered.protected_unchanged,'Rendering changed protected records.');
        const injection=`<script>
window.__diRequests=[];window.__diErrors=[];window.__diReject=false;
window.addEventListener('error',e=>window.__diErrors.push(e.message));
window.addEventListener('unhandledrejection',e=>window.__diErrors.push(String(e.reason)));
if(${JSON.stringify(name)}==='manage-programs'){
 const nativeFetch=window.fetch;
 const cfg=JSON.parse(document.getElementById('diProgramConfig').textContent);
 const firstCampus=String(${rendered.campus}),firstCollege=Number(${rendered.college});
 const secondCollege=cfg.colleges.find(c=>String(c.campus_id)===firstCampus&&Number(c.college_id)!==firstCollege);
 window.__diMockRows=Array.from({length:25},(_,i)=>({program_id:i+1,college_id:firstCollege,program_code:'DI'+String(i+1).padStart(3,'0'),program_name:i===0?'Program <img src=x onerror=alert(1)>':'DI Program '+(i+1),major:i%2?'Networks':'',status:'active',prospectus_count:i%3}));
 if(secondCollege)window.__diMockRows.push({program_id:26,college_id:Number(secondCollege.college_id),program_code:'DI026',program_name:'Another college program',major:'',status:'inactive',prospectus_count:2});
 window.__diFirstCampus=firstCampus;window.__diFirstCollege=String(firstCollege);
 window.__diOtherCampus=String(cfg.campuses.find(c=>String(c.campus_id)!==firstCampus).campus_id);
 window.fetch=async function(url,options={}){
  if(!String(url).includes('query_di_catalog.php'))return nativeFetch(url,options);
  const params=Object.fromEntries(new URLSearchParams(options.body));window.__diRequests.push({url:String(url),params});
  if(window.__diReject)return new Response('ERROR|This record is already in use.',{status:409});
  if(params.load_programs){
   const scoped=String(params.campus_id)===firstCampus?window.__diMockRows.filter(r=>!params.college_id||String(r.college_id)===String(params.college_id)):[];
   const body={campus_id:Number(params.campus_id),college_id:Number(params.college_id||0),rows:scoped.map(r=>({...r})),summary:{programs:scoped.length,active:scoped.filter(r=>r.status==='active').length,inactive:scoped.filter(r=>r.status!=='active').length,colleges:params.college_id?1:cfg.colleges.filter(c=>String(c.campus_id)===String(params.campus_id)).length,prospectuses:scoped.reduce((n,r)=>n+r.prospectus_count,0),subjects:scoped.length?12:0}};
   await new Promise(resolve=>setTimeout(resolve,window.__diRace&&String(params.campus_id)===firstCampus?180:10));
   return new Response(JSON.stringify(body),{status:200,headers:{'Content-Type':'application/json'}});
  }
  if(params.delete_program){window.__diMockRows.find(r=>r.program_id===Number(params.program_id)).status='inactive';return new Response('updated');}
  if(params.save_program){window.__diMockRows.push({program_id:99,college_id:Number(params.college_id),program_code:params.program_code,program_name:params.program_name,major:params.major,status:'active',prospectus_count:0});return new Response('success');}
  if(params.update_program){Object.assign(window.__diMockRows.find(r=>r.program_id===Number(params.program_id)),{program_code:params.program_code,program_name:params.program_name,major:params.major,status:params.status,college_id:Number(params.college_id)});return new Response('updated');}
  throw Error('Unexpected program action.');
 };
}
$.ajaxTransport('+*',function(options){
 if(!String(options.url).includes('query_di_'))return;
 return {send:function(headers,complete){
  const params=Object.fromEntries(new URLSearchParams(options.data||''));window.__diRequests.push({url:options.url,params});
  let body='OK|Saved successfully.',type='text/plain',status=200;
  const id=${rendered.header},program=${rendered.program};
  if(params.load_programs)body='<tr><td>1</td><td>Campus · College</td><td>DIQA</td><td>DI Course</td><td>Major</td><td>Active</td><td><button class="btnEdit" data-id="1" data-college="1" data-code="DIQA" data-name="DI Course" data-major="Major" data-status="active">Edit</button></td></tr>';
  if(params.load_subjects)body='<tr><td>1</td><td>DI101</td><td>DI Subject</td><td>DIQA</td><td>Active</td><td><button class="btnEdit" data-id="1" data-code="DI101" data-desc="DI Subject" data-status="active">Edit</button></td></tr>';
  if(params.save_program||params.save_subject)body='success';
  if(params.update_program||params.update_subject)body='updated';
  if(params.load_header)body='OK|'+program+'|DI Test CMO|2026-2027|';
  if(params.load_prospectus_by_program){body=JSON.stringify([{prospectus_id:id,program_id:program,cmo_no:'DI Test CMO',effective_sy:'2026-2027',subject_count:1,total_units:4}]);type='application/json';}
  if(params.load_year_sem)body=${JSON.stringify(structure)};
  if(params.load_prospectus_list){body=JSON.stringify([{prospectus_id:id,cmo_no:'DI Test CMO',effective_sy:'2026-2027',program_name:'DI Course',program_code:'DIQA'}]);type='application/json';}
  if(params.get_all_subjects)body='[]';
  if(params.load_browser_prospectuses){body=JSON.stringify([{prospectus_id:id,program_id:program,cmo_no:'DI Test CMO',effective_sy:'2026-2027',program_name:'DI Course',program_code:'DIQA',subject_count:1,yearsem_count:1,total_units:4}]);type='application/json';}
  if(window.__diReject){status=409;body='ERROR|This record is already in use.';}
  setTimeout(()=>complete(status,status===200?'OK':'Conflict',{text:body},'Content-Type: '+type),10);
 },abort:function(){}};
});</script>`;
        const jquery='<script src="../assets/vendor/libs/jquery/jquery.js"></script>';
        pages[name]=Buffer.from(rendered.body.replace('<head>','<head><base href="/synk/di/">').replace(jquery,jquery+injection)).toString('base64');
    }
    const source=`<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!hash_equals(${quote(token)},(string)($_GET['qa']??''))){http_response_code(404);exit;}$pages=${'['+Object.entries(pages).map(([k,v])=>quote(k)+'=>'+quote(v)).join(',')+']'};header('Content-Type: text/html; charset=utf-8');echo base64_decode($pages[$_GET['page']??'']??'');`;
    fs.writeFileSync(route,source);
    const executable=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
    if(!executable)throw Error('Chromium browser is required.');
    browser=spawn(executable,['--headless=new','--disable-gpu','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
    const url=await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);let logs='';browser.stderr.on('data',chunk=>{logs+=chunk;const m=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
    socket=new WebSocket(url);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
    let sequence=0;const pending=new Map();
    socket.addEventListener('message',event=>{const msg=JSON.parse(event.data),entry=pending.get(msg.id);if(!entry)return;pending.delete(msg.id);clearTimeout(entry.timer);msg.error?entry.reject(Error(msg.error.message)):entry.resolve(msg.result);});
    const call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timed out.'));},15000);pending.set(id,{resolve,reject,timer});socket.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
    const target=await call('Target.createTarget',{url:'about:blank'}),attached=await call('Target.attachToTarget',{targetId:target.targetId,flatten:true}),session=attached.sessionId;
    await call('Page.enable',{},session);await call('Runtime.enable',{},session);
    const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
    const until=async(expression,message)=>{for(let i=0;i<150;i++){if(await evaluate(expression))return;await delay(100);}throw Error(message+': '+JSON.stringify(await evaluate('({errors:window.__diErrors,requests:window.__diRequests,ready:document.readyState})')));};
    for(const name of Object.keys(pages)){
        await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
        await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&page=${name}&pid=${headers[name]}`},session);
        await until('document.readyState==="complete" && !!window.jQuery && !!window.Swal && Array.isArray(window.__diRequests)','DI page did not load.');
        check(await evaluate(`document.querySelectorAll('#layout-menu a[href*="manage-"]').length===4`),'DI curriculum links are incomplete.');
        if(name==='manage-programs'){
            check(await evaluate('window.__diRequests.length===0 && document.getElementById("programCollege").disabled && document.getElementById("addProgramButton").disabled'),'Programs loaded before campus selection.');
            check(await evaluate('document.querySelectorAll(".di-program-filters select").length===2 && ![...document.querySelectorAll("#programTable th")].some(th=>th.textContent.trim()==="College")'),'Program filters or columns do not match the requested layout.');
            await evaluate('document.getElementById("programCampus").value=window.__diFirstCampus;document.getElementById("programCampus").dispatchEvent(new Event("change"))');
            await until('document.querySelectorAll("#programRows [data-edit]").length===10','Campus programs did not load.');
            check(await evaluate('Number(document.getElementById("count-programs").textContent)===window.__diMockRows.length'),'Program count does not match campus programs.');
            check(await evaluate('!document.querySelector("#programRows img") && document.querySelector(".di-program-description").textContent.includes("<img")'),'Program labels were not escaped.');
            check(await evaluate('!!document.querySelector(".di-program-code") && !!document.querySelector(".di-program-description")'),'Program code and name are not shown on separate lines.');
            check(await evaluate('Array.from(document.getElementById("programCollege").options).filter(o=>o.value).every(o=>JSON.parse(document.getElementById("diProgramConfig").textContent).colleges.some(c=>String(c.college_id)===o.value&&String(c.campus_id)===window.__diFirstCampus))'),'Another campus college appeared in the filter.');
            await evaluate('document.getElementById("programNext").click()');
            check(await evaluate('document.getElementById("programPage").textContent==="Page 2 of 3"'),'Program pagination did not advance.');
            await evaluate('document.getElementById("programCollege").value=window.__diFirstCollege;document.getElementById("programCollege").dispatchEvent(new Event("change"))');
            await until('document.getElementById("count-programs").textContent==="25"','College did not filter campus programs.');
            check(await evaluate('document.getElementById("count-colleges").textContent==="1" && document.getElementById("count-subjects").textContent==="12"'),'Related program cards did not update.');
            await evaluate('document.getElementById("programSearch").value="DI025";document.getElementById("programSearch").dispatchEvent(new Event("input"))');
            check(await evaluate('document.querySelectorAll("#programRows [data-edit]").length===1 && document.getElementById("count-programs").textContent==="25"'),'Search or selection-based summary is incorrect.');
            await evaluate('document.getElementById("programSearch").value="";document.getElementById("programSearch").dispatchEvent(new Event("input"));document.getElementById("addProgramButton").click()');
            await until('document.getElementById("programModal").classList.contains("show")','Program form did not open.');
            const before=await evaluate('window.__diRequests.length');
            await evaluate('document.getElementById("saveProgramButton").click()');await delay(80);
            check(await evaluate(`window.__diRequests.length===${before}`),'Blank program form sent a write.');
            await evaluate('document.getElementById("editProgramCode").value="NEW";document.getElementById("editProgramName").value="New Campus Program";document.getElementById("saveProgramButton").click()');
            await until('document.getElementById("count-programs").textContent==="26" && getComputedStyle(document.getElementById("programModal")).display==="none" && !document.querySelector(".modal-backdrop")','New program did not refresh the selected college.');
            check(await evaluate('window.__diRequests.find(r=>r.params.save_program).params.csrf_token==="di-curriculum-qa" && window.__diRequests.find(r=>r.params.save_program).params.campus_id===window.__diFirstCampus'),'Program write lost campus or CSRF context.');
            await evaluate(`document.querySelector('[data-edit="1"]').click()`);
            await until('document.getElementById("programModal").classList.contains("show") && document.activeElement===document.getElementById("editProgramCode")','Program editor did not open.');
            await evaluate('window.__diReject=true;document.getElementById("saveProgramButton").click()');
            await until('document.getElementById("programFormError").textContent.includes("already in use")','Rejected program edit did not show its reason.');
            check(await evaluate('document.getElementById("programModal").classList.contains("show")'),'Rejected program edit closed the editor.');
            await evaluate('window.__diReject=false;document.querySelector("#programModal .btn-close").click()');
            await until('getComputedStyle(document.getElementById("programModal")).display==="none" && !document.querySelector(".modal-backdrop")','Program editor did not close.');
            await evaluate(`document.querySelector('[data-deactivate="1"]').click()`);
            await until('!!document.querySelector(".swal2-confirm")','Deactivation confirmation did not open.');
            await evaluate('document.querySelector(".swal2-cancel").click()');
            await until('!document.querySelector(".swal2-container") && !document.getElementById("programCampus").disabled','Cancelled deactivation did not return control.');
            check(await evaluate('!window.__diRequests.some(r=>r.params.delete_program)'),'Cancelled deactivation sent a write.');
            await evaluate(`document.querySelector('[data-deactivate="1"]').click()`);
            await until('!!document.querySelector(".swal2-confirm")','Deactivation dialog did not reopen.');
            await evaluate('document.querySelector(".swal2-confirm").click()');
            await until(`!document.querySelector('[data-deactivate="1"]') && !!document.querySelector('[data-edit="1"]') && document.getElementById('programNotice').textContent.includes('inactive')`,'Deactivation removed the program row or did not update its status.');
            check(await evaluate('document.getElementById("count-programs").textContent==="26" && document.getElementById("programStatusCounts").textContent.includes("1 inactive")'),'Deactivation reduced program count or did not update the inactive total.');
            await evaluate('window.__diRace=true;document.getElementById("programCampus").value=window.__diFirstCampus;document.getElementById("programCampus").dispatchEvent(new Event("change"));document.getElementById("programCampus").value=window.__diOtherCampus;document.getElementById("programCampus").dispatchEvent(new Event("change"))');
            await until('document.getElementById("count-programs").textContent==="0"','Empty campus did not show zero programs.');await delay(250);
            check(await evaluate('document.getElementById("count-programs").textContent==="0" && document.querySelectorAll("#programRows [data-edit]").length===0'),'A stale campus response replaced the selected campus.');
            await evaluate('window.__diRace=false;document.getElementById("programCampus").value=window.__diFirstCampus;document.getElementById("programCampus").dispatchEvent(new Event("change"))');
            await until('document.querySelectorAll("#programRows [data-edit]").length===10','Campus programs did not reload.');
        }
        if(name==='manage-subject-masterlist'){
            const program=false,kind='Subject',table='subjectTable';
            await until(`document.querySelectorAll('#${table} tbody tr').length>0`,'Catalog did not load.');
            await evaluate(`document.querySelector('[data-bs-target="#add${kind}Modal"]').click()`);
            await until(`document.getElementById('add${kind}Modal').classList.contains('show')`,'Add modal did not open.');
            const before=await evaluate('window.__diRequests.length');
            await evaluate(`document.getElementById('btnSave${kind}').click()`);await delay(100);
            check(await evaluate(`window.__diRequests.length===${before}`),'Blank form sent a write request.');
            await evaluate(`(()=>{const f=document.getElementById('add${kind}Form');for(const input of f.querySelectorAll('input'))input.value='DI New';${program?"f.querySelector('select').selectedIndex=1;":''}document.getElementById('btnSave${kind}').click();})()`);
            await until(`window.__diRequests.some(r=>r.params.${program?'save_program':'save_subject'})`,'Save did not reach DI.');
            check(await evaluate(`window.__diRequests.find(r=>r.params.${program?'save_program':'save_subject'}).params.csrf_token==='di-curriculum-qa'`),'DI catalog write omitted CSRF.');
            await evaluate('Swal.close()');
            await evaluate(`jQuery('#add${kind}Modal').modal('hide');void 0`);
            await until(`!document.getElementById('add${kind}Modal').classList.contains('show')`,'Add modal did not close.');
            await evaluate(`document.querySelector('#${table} .btnEdit').click()`);
            await until(`document.getElementById('edit${kind}Modal').classList.contains('show')`,'Catalog edit modal did not open.');
            await evaluate(`window.__diReject=true;document.getElementById('btnUpdate${kind}').click()`);
            await until('document.querySelector(".swal2-html-container")?.textContent.includes("already in use")','Rejected catalog write did not display its reason.');
            check(await evaluate(`document.getElementById('edit${kind}Modal').classList.contains('show')`),'Rejected catalog write was reported as successful.');
            await evaluate(`Swal.close();window.__diReject=false;jQuery('#edit${kind}Modal').modal('hide');void 0`);
        }
        if(name==='manage-prospectus'){
            // The fixture's deep link exercises the shared program-first builder.
            await until('document.getElementById("prospectus_id").value!=="" && !!document.querySelector(".btnAddSubject")','Prospectus version did not load.');
            check(await evaluate('document.getElementById("yearSemCard").style.display!=="none"'),'Builder structure stayed hidden.');
            await until('!!document.querySelector("#programProspectusRows .btnLoadProgramProspectus")','Program versions did not load.');
            check(await evaluate('!!document.getElementById("btnCreateProspectus") && !document.getElementById("btnSaveHeader") && document.getElementById("loadedCmo").textContent==="DI Test CMO"'),'DI did not use the program-first version layout.');
            check(await evaluate('document.querySelectorAll(".di-structure-year").length===2 && document.querySelectorAll(".di-semester-panel .collapse.show").length===4'),'Year groups or initially expanded semester panels are missing.');
            check(await evaluate('getComputedStyle(document.querySelector(".di-structure-semesters")).gridTemplateColumns.split(" ").length===2'),'Semester layout is not two columns on desktop.');
            const readsBeforeCollapse=await evaluate('window.__diRequests.length');
            await evaluate('document.querySelector("[data-di-structure-expand=hide]").click()');
            await until('document.querySelectorAll(".di-semester-panel .collapse:not(.show)").length===4','Collapse all did not close the panels.');
            await evaluate('document.querySelector("[data-di-structure-expand=show]").click()');
            await until('document.querySelectorAll(".di-semester-panel .collapse.show").length===4','Expand all did not open the panels independently.');
            check(await evaluate(`window.__diRequests.length===${readsBeforeCollapse}`),'Display controls sent a curriculum data request.');
            await evaluate('document.querySelector(".btnAddSubject").click()');
            await until('document.getElementById("subjectModal").classList.contains("show")','Builder subject modal did not open.');
            await evaluate('document.getElementById("ps_lec_units").value="3";document.getElementById("ps_lab_units").value="3";document.getElementById("ps_lab_units").dispatchEvent(new Event("input",{bubbles:true}))');
            check(await evaluate('Number(document.getElementById("ps_total_units").value)===4'),'Builder lecture/lab conversion changed.');
        }
        if(name==='manage-prospectus-browser'){
            await evaluate('document.getElementById("filterCampus").selectedIndex=1;jQuery("#filterCampus").trigger("change");document.getElementById("filterCollege").selectedIndex=1;jQuery("#filterCollege").trigger("change");void 0');
            await until(`!!document.querySelector('a[href^="manage-prospectus.php?pid="]')`,'Browser filters did not load prospectuses.');
            check(await evaluate(`!!document.querySelector('a[href^="manage-prospectus.php?pid="]')`),'Browser Manage link left DI.');
        }
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false},session);
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+2'),'DI page overflows at mobile width: '+name);
        if(name==='manage-prospectus') check(await evaluate('getComputedStyle(document.querySelector(".di-structure-semesters")).gridTemplateColumns.split(" ").length===1'),'Semester panels did not stack on mobile.');
        check((await evaluate('window.__diErrors')).length===0,'DI browser scripts failed: '+name+' '+JSON.stringify(await evaluate('window.__diErrors')));
        check(await evaluate('window.__diRequests.every(r=>r.url.includes("query_di_"))'),'DI sent an Admin API request.');
        if(process.argv.includes('--screenshot') && (name==='manage-prospectus-browser'||name==='manage-programs'||name==='manage-prospectus')) {
            const screenshotName=name==='manage-programs'?'di-programs':name==='manage-prospectus'?'di-prospectus-structure':'di-curriculum';
            const mobile=await call('Page.captureScreenshot',{format:'png'},session);
            fs.writeFileSync(path.join(temp,screenshotName+'-mobile.png'),Buffer.from(mobile.data,'base64'));
            await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
            const desktop=await call('Page.captureScreenshot',{format:'png'},session);
            fs.writeFileSync(path.join(temp,screenshotName+'-desktop.png'),Buffer.from(desktop.data,'base64'));
        }
    }
    console.log(`DI curriculum browser: ${checks} checks passed at 1440 px and 390 px. All data requests intercepted.`);
    await call('Browser.close');
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
    if(socket)socket.close();if(browser){browser.kill();await delay(800);}
    if(fs.existsSync(route))fs.unlinkSync(route);
    const target=path.resolve(profile);
    if(!target.startsWith(path.resolve(temp)+path.sep)||!/^di-browser-[0-9a-f]{16}$/.test(path.basename(target)))throw Error('Unexpected profile cleanup target.');
    if(fs.existsSync(target)){if(fs.lstatSync(target).isSymbolicLink())throw Error('Browser profile is a reparse point.');fs.rmSync(target,{recursive:true,force:true,maxRetries:5,retryDelay:200});}
});
