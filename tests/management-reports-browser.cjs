// Isolated browser; every native report fixture is rolled back after rendering.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`reports-browser-${nonce}.php`),profile=path.join(root,'.tmp',`reports-browser-${nonce}`),pdfDir=path.join(root,'tmp','pdfs');
let browser,ws,call,checks=0;const errors=[],delay=ms=>new Promise(r=>setTimeout(r,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.mkdirSync(pdfDir,{recursive:true});
 fs.writeFileSync(route,`<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!hash_equals('${token}',(string)($_GET['qa']??''))){http_response_code(404);exit;}define('SYNK_MANAGEMENT_QA',true);define('SYNK_MANAGEMENT_QA_RENDER',true);require '${path.join(root,'tests/fixtures/management-request.php').replaceAll('\\','/')}';`);
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(exe,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let log='';const t=setTimeout(()=>reject(Error('Browser startup timeout')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/\S+)/);if(m){clearTimeout(t);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.t);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);});
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,t=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout'));},30000);pending.set(id,{resolve,reject,t});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<180;i++){if(await evaluate('Boolean('+expression+')'))return;await delay(100);}throw Error('Timed out: '+expression+' '+errors.join('; '));};
 const navigate=async(params={})=>{await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(route)}?`+new URLSearchParams({qa:token,scenario:'reports',...params})},session);await until('document.readyState==="complete"&&document.getElementById("management-report-form")');};
 const resize=async width=>{await call('Emulation.setDeviceMetricsOverride',{width,height:1050,deviceScaleFactor:1,mobile:false},session);await delay(100);};
 const screenshot=async name=>fs.writeFileSync(path.join(root,'.tmp',name),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 const pdf=async name=>{const filename=path.join(pdfDir,name+'.pdf');fs.writeFileSync(filename,Buffer.from((await call('Page.printToPDF',{printBackground:true,preferCSSPageSize:true,displayHeaderFooter:false},session)).data,'base64'));console.log('Print preview: '+filename);};
 await resize(1440);await navigate();
 check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Reports")&&document.querySelectorAll("#report-type option").length===4&&!document.getElementById("management-report-preview")&&document.getElementById("management-report-print").disabled'),'Single report menu, selector or initial generation state is wrong: '+JSON.stringify(await evaluate('({active:document.querySelector(".management-nav [aria-current=page]")?.textContent,types:document.querySelectorAll("#report-type option").length,preview:Boolean(document.getElementById("management-report-preview")),disabled:document.getElementById("management-report-print").disabled})')));
 await evaluate('window.submission=null;document.getElementById("management-report-form").addEventListener("submit",event=>{event.preventDefault();window.submission=Object.fromEntries(new FormData(event.target,event.submitter))});document.getElementById("report-type").value="event_attendance";document.getElementById("report-type").dispatchEvent(new Event("change"))');
 check(await evaluate('submission.report_type==="event_attendance"&&!submission.generate&&submission.view_ay_id&&submission.view_semester&&submission.exam_term'),'Report-type change did not refresh options with all three period fields.');
 await navigate({report_type:'event_attendance',generate:'1',institution:'QA INSTITUTION',checked_by:'<img src=x onerror=alert(1)>'});
 check(await evaluate('document.querySelector(".management-report-status").textContent==="FINALIZED ATTENDANCE"&&document.querySelectorAll("[data-report-category=absent] [data-attendance-number]").length===26&&document.querySelectorAll("[data-report-category=present] [data-attendance-number]").length===2'),'Final attendance categories or counts are wrong.');
 check(await evaluate('[...document.querySelectorAll("[data-attendance-number]")].map(row=>Number(row.dataset.attendanceNumber)).every((number,i)=>number===i+1)&&document.querySelector("[data-report-category]").dataset.reportCategory==="absent"&&!document.querySelector(".management-report-signatures img")&&document.querySelector(".management-report-signatures").textContent.includes("<img src=x")'),'Attendance numbering restarted or print header text was not escaped.');
 await pdf('management-attendance-report');
 for(const width of[1440,768,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Report page overflows '+width+'px');}
 await resize(390);await screenshot('management-reports-attendance-390.png');
 await resize(1440);await navigate({report_type:'event_attendance',generate:'1',qa_event:'open'});
 check(await evaluate('document.querySelector(".management-report-status").textContent.includes("PROVISIONAL")&&document.querySelector("[data-report-category=absent] h3").textContent.includes("Not yet recorded")'),'Open attendance mislabeled unscanned students as finalized absences.');
 await navigate({report_type:'collection_summary',generate:'1',institution:'QA INSTITUTION'});
 check(await evaluate('document.querySelector(".management-report-metrics strong").textContent==="₱225.50"&&document.querySelector(".management-report-table").textContent.includes("Violation fines")&&document.querySelector(".management-report-table").textContent.includes("Applied student violations only")'),'Summary merged categories or counted unavailable violations as collections.');
 await screenshot('management-reports-summary-1440.png');await pdf('management-collection-summary');
 await evaluate('window.printCalls=0;window.print=()=>window.printCalls++;document.getElementById("management-report-print").click()');check(await evaluate('printCalls===1'),'Print action did not invoke browser printing.');
 await evaluate('document.getElementById("report-institution").value="Changed name";document.getElementById("report-institution").dispatchEvent(new Event("input",{bubbles:true}))');
 check(await evaluate('document.getElementById("management-report-print").disabled&&!document.getElementById("management-report-stale").hidden'),'Changed print options left a stale report printable.');
 await navigate({report_type:'collection_register',generate:'1'});
 check(await evaluate('document.querySelectorAll("[data-report-category=contributions] tbody tr").length===2&&document.querySelectorAll("[data-report-category=event_fines] tbody tr").length===1&&document.querySelector("[data-report-category=violations]").textContent.includes("No payments recorded")'),'Receipt allocations are not separated by category.');
 await navigate({report_type:'absence_fines',generate:'1',institution:'QA INSTITUTION'});
 check(await evaluate('[...document.querySelectorAll(".management-report-metrics strong")].map(e=>e.textContent).join("|")==="₱663.00|₱25.50|₱637.50"'),'Assessed/collected/outstanding fine totals are wrong.');await pdf('management-absence-fines');
 await call('Emulation.setEmulatedMedia',{media:'print'},session);
 check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display==="none"&&getComputedStyle(document.querySelector(".management-report-controls")).display==="none"&&getComputedStyle(document.querySelector(".management-report-table thead")).display==="table-header-group"&&getComputedStyle(document.querySelector(".management-report-table-scroll")).overflowX==="visible"'),'Print view includes controls, clips tables, or loses repeating headings.');
 await call('Emulation.setEmulatedMedia',{media:''},session);
 // Chrome's native PDF renderer supplies visual QA without an external renderer.
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1320,deviceScaleFactor:1,mobile:false},session);
 for(const name of['management-collection-summary','management-attendance-report','management-absence-fines']){
  const file=path.join(pdfDir,name+'.pdf'),pages=fs.readFileSync(file).toString('latin1').match(/\/Type\s*\/Page(?!s)/g).length;
  for(let page=1;page<=pages;page++){await call('Page.navigate',{url:'file:///'+file.replaceAll('\\','/')},session);await delay(1800);if(page>1){await call('Input.dispatchMouseEvent',{type:'mouseWheel',x:1200,y:700,deltaX:0,deltaY:(page-1)*1140},session);await delay(500);}await screenshot(name+'-pdf-page-'+page+'.png');}
 }
 check(errors.length===0,'Browser errors: '+errors.join('; '));console.log(`Management reports UI: ${checks} browser checks passed; native fixtures rolled back.`);
}
main().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{if(call)try{await call('Browser.close');}catch{}if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(r=>browser.once('exit',r));}if(fs.existsSync(route))fs.unlinkSync(route);const resolved=path.resolve(profile);if(resolved.startsWith(path.resolve(root,'.tmp')+path.sep)&&/^reports-browser-[a-f0-9]{16}$/.test(path.basename(resolved))&&fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});});
