// Local rolled-back page fixture; mocked cash collection responses. Real ledger
// and authorization are covered by management-payments.php/management-crud.php.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`payment-browser-${nonce}.php`),profile=path.join(root,'.tmp',`payment-browser-${nonce}`),avatar=path.join(root,'uploads','student-profile','student_4294967294.jpg');
let browser,ws,call,checks=0,avatarCreated=false;const errors=[],requests=[],delay=ms=>new Promise(r=>setTimeout(r,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 if(fs.existsSync(avatar))throw Error('Reserved avatar fixture already exists.');fs.copyFileSync(path.join(root,'tests','fixtures','student-camera-portrait.jpg'),avatar);avatarCreated=true;
 fs.writeFileSync(route,`<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!hash_equals('${token}',(string)($_GET['qa']??''))){http_response_code(404);exit;}define('SYNK_MANAGEMENT_QA',true);define('SYNK_MANAGEMENT_QA_RENDER',true);require '${path.join(root,'tests/fixtures/management-request.php').replaceAll('\\','/')}';`);
 const fixtureUrl=`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=students`;
 const fixturePage=await (await fetch(fixtureUrl)).text();
 if(!fixturePage.includes('id="management-config"'))throw Error('Could not render the Management directory fixture.');
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(exe,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const endpoint=await new Promise((resolve,reject)=>{let log='';const t=setTimeout(()=>reject(Error('Browser startup timeout')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/\S+)/);if(m){clearTimeout(t);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(endpoint);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0,paid=0,failPayment=true,failRefresh=false;
 const pending=new Map(),receipts=[];
 function charge(key,title,amount,due) {
   return {key,title,date:'2026-10-28',amount:amount.toFixed(2),amount_due:due.toFixed(2),amount_paid:(amount-due).toFixed(2),payment_status:due===0?'PAID':amount===due?'UNPAID':'PARTIALLY_PAID'};
 }
 function detail(studentId) {
   const contributionDues=paid===0?[150,400,400]:paid===500?[0,225,225]:[0,0,0];
   const contributions=[150,400,400].map((value,i)=>({...charge('C:'+(i+1),'Charge '+(i+1),value,contributionDues[i]),contribution_id:i+1,contribution_date:'2026-10-28'}));
   const extras=type=>paid===0?[]:Array.from({length:12},(_,i)=>{
     const value=type==='E'?10.25:5;
     return charge(type+':'+(i+1),(type==='E'?'Event fine ':'Violation ')+(i+1),value,paid===500?value:0);
   });
   const fines=extras('E'),violations=extras('V'),due=(paid===0?950:1133)-paid;
   return {status:'ok',student:{student_id:studentId,first_name:'TEST',last_name:'ACCOUNT',student_name:'TEST ACCOUNT',student_number:'999990000001',email_address:'ccsi@sksu.edu.ph',original_section:'BSCS 2A',original_year_level:2,home_section:'BSCS 3A',photo_url:'../uploads/student-profile/student_4294967294.jpg?v=abcdef1234567890'},subjects:[{program_name:'BSCS',section_label:'BSCS 4B',year_level:4}],financial:{period:{key:'2:1:FINAL',text:'2026-2027 - 1st Semester - Final'},organization:{code:'CSSO'},can_pay:due>0,account_version:String(paid).padStart(64,'0'),charges:[...contributions,...fines,...violations],contributions,events:fines.map(row=>({...row,absence_fine:row.amount,event_date:row.date,student_attendance:'ABSENT'})),violations,payments:receipts.filter(row=>row.student_id===studentId),summary:{contribution_count:3,contribution_catalog_total:'950.00',event_count:fines.length,absence_count:fines.length,event_fine_total:paid===0?'0.00':'123.00',violation_total:paid===0?'0.00':'60.00',amount_paid:paid.toFixed(2),total_to_pay:due.toFixed(2),payment_status:due===0?'PAID':paid>0?'PARTIALLY_PAID':'UNPAID'}}};
 }
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);clearTimeout(p.t);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}
  if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Fetch.requestPaused'){
   if(m.params.resourceType==='Document'){
     // Replay one fixture page so its temporary cashier and organization IDs stay stable across reloads.
     call('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html; charset=utf-8'}],body:Buffer.from(fixturePage).toString('base64')},m.sessionId).catch(e=>errors.push(e.message));
     return;
   }
   const data=Object.fromEntries(new URLSearchParams(m.params.request.postData||''));requests.push(data);let payload=detail(data.student_id),status=200;
   if(data.action==='payment_preview'){
     const selected=JSON.parse(data.selected);
     const items=data.allocation_mode==='SELECTED'
       ? payload.financial.charges.filter(row=>selected.includes(row.key)).map(row=>({key:row.key,title:row.title,amount:row.amount_due}))
       : ['150.00','175.00','175.00'].map((amount,i)=>({key:'C:'+(i+1),title:'Charge '+(i+1),amount}));
     payload={status:'ok',amount:data.amount,balance_after:(Number(payload.financial.summary.total_to_pay)-Number(data.amount)).toFixed(2),items};
   }
   if(data.action==='payment_post'){
     if(failPayment){failPayment=false;status=503;payload={status:'error',message:'Temporary payment connection error. Retry the same payment.'};}
     else{
       paid+=Number(data.amount);
       const paymentId=receipts.length+1,number=data.receipt_number||'SQ-TEST-RECEIPT';
       receipts.unshift({payment_id:paymentId,student_id:data.student_id,reference:number,amount:data.amount,date:'2026-10-28 09:00:00',recorded_by_name:'Cashier'});
       failRefresh=paymentId===1;
       payload={status:'ok',payment_id:paymentId,receipt_number:number,receipt_url:'payment-receipt.php?payment_id='+paymentId};
     }
   }
   if(data.action==='student_detail'&&failRefresh){failRefresh=false;status=503;payload={status:'error',message:'Temporary overview connection error.'};}
   call('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(payload)).toString('base64')},m.sessionId).catch(e=>errors.push(e.message));}
 });
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,t=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout'));},20000);pending.set(id,{resolve,reject,t});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 async function openTab() {
   const {targetId}=await call('Target.createTarget',{url:'about:blank'});
   const {sessionId}=await call('Target.attachToTarget',{targetId,flatten:true});
   await call('Page.enable',{},sessionId);await call('Runtime.enable',{},sessionId);
   await call('Fetch.enable',{patterns:[{urlPattern:'*query_management.php*'},{urlPattern:fixtureUrl,resourceType:'Document'}]},sessionId);
   return {targetId,sessionId};
 }
 const tab=await openTab();
 let session=tab.sessionId;
 const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<160;i++){if(await evaluate('Boolean('+expression+')'))return;await delay(100);}throw Error('Timed out: '+expression+' '+errors.join('; '));};
 await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
 await call('Page.navigate',{url:fixtureUrl},session);await until('document.querySelector("[data-student]")&&document.readyState==="complete"');
 const openStudent=index=>evaluate(`new Promise(resolve=>{document.getElementById("management-student-detail").addEventListener("shown.bs.modal",()=>resolve(true),{once:true});document.querySelectorAll("[data-student]")[${index}].click()})`);
 await openStudent(0);
 await until('document.querySelector(".management-financial-avatar img")?.naturalWidth>0');
 check(await evaluate('document.querySelector(".management-financial-avatar img").src.includes("student_4294967294")'),'The student avatar did not load in Financial overview.');
 check(await evaluate('document.querySelector(".management-financial-profile-fields > div:nth-child(2) dd").textContent==="BSCS 2A, Year 2"'),'Financial overview used an enrolled subject or later home section instead of the original placement.');
 check(await evaluate('getComputedStyle(document.querySelector(".management-financial-stat-due")).backgroundColor==="rgb(255, 237, 237)"'),'Unpaid total is not light red.');
 const receiptKey=await evaluate('(()=>{const config=JSON.parse(document.getElementById("management-config").textContent);return "synk-management-next-receipt-"+config.organization_id+"-"+config.user_id})()');
 const storedReceipt=()=>evaluate(`JSON.parse(sessionStorage.getItem(${JSON.stringify(receiptKey)}))?.number ?? ""`);
 await evaluate('document.querySelector(".management-financial-pay").click()');
 check(await evaluate('(()=>{const amount=document.getElementById("management-payment-amount"),receipt=document.getElementById("management-payment-receipt");return document.querySelector("input[name=allocation_mode]:checked").value==="DISTRIBUTE"&&!document.querySelector(".management-payment-panel").hidden&&document.querySelector(".management-financial-layout").hidden&&document.querySelector(".management-payment-picker").hidden&&!amount.readOnly&&amount.value==="950.00"&&!receipt.value&&!receipt.required&&document.activeElement===receipt})()'),'Pay did not fill Distribute with the current whole-peso balance and focus Receipt number.');
 await evaluate('document.querySelector(".management-payment-back").click()');
 check(await evaluate('!document.querySelector(".management-financial-layout").hidden&&document.activeElement===document.querySelector(".management-financial-pay")'),'Back to account did not restore the overview and focus.');
 await evaluate('document.querySelector(".management-financial-pay").click();document.querySelector("input[value=SELECTED]").click();document.querySelector("input[value=DISTRIBUTE]").click()');
 check(await evaluate('document.getElementById("management-payment-amount").value==="950.00"&&document.activeElement===document.getElementById("management-payment-receipt")'),'Switching to Distribute did not restore the amount and receipt focus.');
 await evaluate('document.getElementById("management-payment-amount").value="500";document.getElementById("management-payment-amount").dispatchEvent(new Event("input",{bubbles:true}));document.getElementById("management-payment-receipt").value="74111";document.querySelector(".management-payment-form").requestSubmit()');await until('!document.querySelector(".management-payment-record").disabled');
 check(await evaluate('document.querySelector(".management-payment-edit").hidden&&!document.querySelector(".management-payment-itemized").open&&[...document.querySelectorAll(".management-payment-preview li strong")].map(e=>e.textContent.replace(/[^0-9.]/g,"")).join(",")==="150.00,175.00,175.00"'),'Whole-peso review did not replace the form or show the capped distribution.');
 for(const width of[1440,768,390,320]){await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false},session);await delay(100);check(await evaluate('document.documentElement.scrollWidth<=innerWidth&&document.querySelector(".management-payment-panel").scrollWidth<=document.querySelector(".management-payment-panel").clientWidth+1'),'Payment form overflows '+width+'px');if(width===390){await evaluate('document.querySelector(".management-payment-panel").scrollIntoView({block:"start",behavior:"instant"})');await delay(120);fs.writeFileSync(path.join(root,'.tmp','management-payment-distribute-390.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));}}
 await evaluate('document.querySelector(".management-payment-record").click()');await until('!document.querySelector(".management-payment-error").hidden');
 check(await evaluate('!document.querySelector(".management-payment-record").disabled&&document.querySelector(".management-payment-error").textContent.includes("Retry")') && await storedReceipt()==='', 'A failed save advanced the receipt number or prevented retry.');
 await evaluate('document.querySelector(".management-payment-record").click()');await until('document.querySelector(".management-payment-error.alert-warning")?.textContent.includes("Reload the balance")');
 check(await evaluate('(()=>{const confirmation=document.querySelector(".management-payment-completion");return document.querySelector(".management-payment-record").hidden&&confirmation.querySelector("[role=status] h3").textContent==="Payment complete"&&confirmation.textContent.includes("₱500.00")&&confirmation.textContent.includes("Receipt No.: 74111")&&confirmation.querySelector("a").getAttribute("href")==="payment-receipt.php?payment_id=1"&&document.activeElement===confirmation})()') && await storedReceipt()==='74112','A saved payment did not show its confirmation and receipt while refreshing the balance failed.');
 await evaluate('document.querySelector(".management-payment-reload").click()');await until('document.getElementById("financial-payments").textContent.includes("74111")');
 const posts=requests.filter(r=>r.action==='payment_post');check(posts.length===2&&posts[0].request_key===posts[1].request_key&&posts[0].request_key.length===64,'Retry changed the request key or submitted extra payments.');
 check(await evaluate('!document.querySelector(".management-financial-stat-due").classList.contains("is-paid")&&document.getElementById("financial-payments").querySelector("a").getAttribute("href")==="payment-receipt.php?payment_id=1"'),'Partial payment lost unpaid styling or receipt.');
 check(await evaluate('(()=>{const balance=document.querySelector("[data-student-balance]");return balance.dataset.balanceStatus==="PARTIALLY_PAID"&&balance.textContent==="₱633.00"})()'),'The directory did not refresh the remaining balance after a partial payment.');
 check(await evaluate('(()=>{const reference=document.querySelector(".management-payment-history-reference"),number=reference.querySelector("strong");return reference.textContent.trim()==="Receipt No.: 74111"&&number.textContent==="74111"&&getComputedStyle(number).color==="rgb(180, 35, 24)"&&getComputedStyle(reference).color!==getComputedStyle(number).color})()'),'Payment history did not show the Receipt No. label with only the number in red.');
 await evaluate('window.beforeReceiptRefresh=true');
 await call('Page.reload',{},session);
 await until('!window.beforeReceiptRefresh&&document.querySelector("[data-student]")&&document.readyState==="complete"');
 await openStudent(1);
 await until('document.querySelector(".management-payment-panel")');
 await evaluate('document.querySelector(".management-financial-pay").click()');
 check(await evaluate('(()=>{const receipt=document.getElementById("management-payment-receipt");return document.getElementById("management-payment-amount").value==="630.00"&&document.querySelector(".management-payment-help").textContent.includes("633.00")&&receipt.value==="74112"&&document.activeElement===receipt})()'),'The next student after a refresh did not get the next receipt number or the permitted Distribute amount.');
 await evaluate('document.querySelector("input[value=SELECTED]").click()');
 const filter=async (category,search)=>evaluate(`(()=>{const category=document.getElementById("management-payment-category"),search=document.getElementById("management-payment-search");category.value=${JSON.stringify(category)};category.dispatchEvent(new Event("change"));search.value=${JSON.stringify(search)};search.dispatchEvent(new Event("input"));})()`);
 await filter('E','missing charge');
 check(await evaluate('!document.querySelector(".management-payment-no-matches").hidden&&document.querySelector("input[name=select_matching]").disabled'),'An empty search can still bulk-select charges.');
 await filter('E','Event fine');
 await evaluate('document.querySelector("input[name=select_matching]").click()');
 check(await evaluate('document.querySelector(".management-payment-match-count").textContent==="Select all matching (12)"&&document.querySelectorAll("input[name=charge]:checked").length===12&&document.getElementById("management-payment-amount").value==="123.00"'),'Bulk select did not select only the matching event fines.');
 await filter('V','');
 await evaluate('document.querySelector("input[name=select_matching]").click()');
 check(await evaluate('document.querySelector(".management-payment-selected-count").textContent==="24 selected"&&document.getElementById("management-payment-amount").value==="183.00"'),'Changing categories lost selections or the exact total.');
 await evaluate('document.querySelector(".management-payment-clear").click()');
 check(await evaluate('!document.querySelector("input[name=charge]:checked")&&document.querySelector(".management-payment-review").disabled'),'Clear selection left checked charges or enabled an empty payment.');
 await filter('','');
 await evaluate('document.querySelector("input[name=select_matching]").click()');
 check(await evaluate('document.getElementById("management-payment-amount").readOnly&&document.getElementById("management-payment-amount").value==="633.00"&&document.querySelector(".management-payment-selected-count").textContent==="26 selected"'),'All 26 charges did not calculate the exact selected amount.');
 for(const width of[1440,768,390,320]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:width===320?568:900,deviceScaleFactor:1,mobile:false},session);
   await delay(100);
   check(await evaluate('(()=>{const amount=document.getElementById("management-payment-amount"),footer=document.querySelector(".management-payment-footer"),region=document.querySelector(".management-payment-scroll"),before=amount.getBoundingClientRect().top;region.scrollTop=region.scrollHeight;return region.scrollTop>0&&amount.getBoundingClientRect().top===before&&footer.getBoundingClientRect().bottom<=innerHeight&&region.scrollWidth<=region.clientWidth+1&&document.documentElement.scrollWidth<=innerWidth})()'),'Large payment list moves or clips the footer at '+width+'px.');
   await evaluate('document.querySelector(".management-payment-scroll").scrollTop=0');
   if(width===390)fs.writeFileSync(path.join(root,'.tmp','management-payment-selected-390.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 }
 await filter('C','Charge 2');
 await evaluate('document.querySelector(".management-payment-form").requestSubmit()');await until('!document.querySelector(".management-payment-record").disabled');
 check(await evaluate('document.querySelector(".management-payment-edit").hidden&&document.querySelectorAll(".management-payment-breakdown > div").length===4&&!document.querySelector(".management-payment-itemized").open&&document.querySelectorAll(".management-payment-itemized li").length===26'),'Review did not show category totals and the full expandable allocation.');
 await evaluate('document.querySelector(".management-payment-edit-back").click()');
 check(await evaluate('document.getElementById("management-payment-category").value==="C"&&document.getElementById("management-payment-search").value==="Charge 2"&&document.getElementById("management-payment-receipt").value==="74112"&&document.querySelectorAll("input[name=charge]:checked").length===26'),'Back to edit lost the filters, receipt or selection.');
 await evaluate('document.querySelector(".management-payment-form").requestSubmit()');await until('!document.querySelector(".management-payment-record").disabled');
 await evaluate('document.querySelector(".management-payment-record").click()');await until('document.querySelector(".management-financial-stat-due.is-paid")');
 check(await evaluate('getComputedStyle(document.querySelector(".management-financial-stat-due")).backgroundColor==="rgb(232, 247, 238)"&&document.querySelector(".management-financial-pay").disabled'),'Fully paid total is not green or still accepts payment.');
 check(await evaluate('(()=>{const balance=document.querySelectorAll("[data-student-balance]")[1];return balance.dataset.balanceStatus==="PAID"&&balance.textContent==="Paid"})()'),'The directory did not show Paid after full settlement.');
 for(const [width,height] of [[320,568],[390,900],[1440,1000]]){
   await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);
   await delay(100);
   check(await evaluate('(()=>{const confirmation=document.querySelector(".management-financial-main .management-payment-completion"),body=document.getElementById("management-student-detail-content").getBoundingClientRect(),card=confirmation.getBoundingClientRect();return confirmation.textContent.includes("Payment complete")&&confirmation.textContent.includes("₱633.00")&&confirmation.textContent.includes("Receipt No.: 74112")&&confirmation.querySelector("a").getAttribute("href")==="payment-receipt.php?payment_id=2"&&card.top>=body.top-1&&card.bottom<=body.bottom+1&&confirmation.scrollWidth<=confirmation.clientWidth+1})()'),'Payment confirmation was incomplete or clipped at '+width+'px.');
   if(width===390)fs.writeFileSync(path.join(root,'.tmp','management-payment-complete-390.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 }
 check(requests.at(-2).allocation_mode==='SELECTED'&&JSON.parse(requests.at(-2).selected).length===26&&requests.at(-2).receipt_number==='74112'&&requests.at(-2).student_id!==posts[0].student_id,'Selected payment lost the checked charge IDs or next receipt number for a different student.');
 check(await evaluate('document.getElementById("financial-payments").textContent.includes("74112")') && await storedReceipt()==='74113','The saved receipt history or following receipt number is incorrect.');
 await call('Target.closeTarget',{targetId:tab.targetId});
 session=(await openTab()).sessionId;
 paid=0;
 await call('Page.navigate',{url:fixtureUrl},session);
 await until('document.querySelector("[data-student]")&&document.readyState==="complete"');
 await openStudent(2);
 await until('document.querySelector(".management-payment-panel")');
 await evaluate('document.querySelector(".management-financial-pay").click()');
 check(await evaluate('!document.getElementById("management-payment-receipt").value') && await storedReceipt()==='', 'A newly opened tab inherited the closed tab’s receipt sequence.');
 check(errors.length===0,'Browser runtime errors: '+errors.join('; '));console.log(`Management payment UI: ${checks} checks passed.`);
}
main().catch(e=>{console.error(e.stack);process.exitCode=1;}).finally(async()=>{if(call)try{await call('Browser.close');}catch{}if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(r=>browser.once('exit',r));}if(fs.existsSync(route))fs.unlinkSync(route);if(avatarCreated&&fs.existsSync(avatar))fs.unlinkSync(avatar);const resolved=path.resolve(profile);if(resolved.startsWith(path.resolve(root,'.tmp')+path.sep)&&/^payment-browser-[a-f0-9]{16}$/.test(path.basename(resolved))&&fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});});
