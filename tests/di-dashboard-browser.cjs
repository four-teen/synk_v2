// Browser validation of rendered, rollback-only dashboard fixtures.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {spawn,execFileSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),temp=path.join(root,'.tmp');
const nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp','di-dashboard-'+nonce+'.php'),profile=path.join(temp,'di-dashboard-'+nonce);
const quote=text=>"'"+text.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let browser,socket,checks=0;
function check(condition,message){if(!condition)throw Error(message);checks++;}
async function main(){
    const pages={},known={};
    for(const scenario of ['page','page_empty','page_missing_term','page_next_semester']){
        const data=JSON.parse(execFileSync(process.env.PHP_BINARY||'C:/xampp/php/php.exe',[path.join(root,'tests/fixtures/di-dashboard-request.php'),scenario],{encoding:'utf8'}));
        check(data.unchanged&&data.status===200,'Rendering a dashboard changed data or failed.');
        known[scenario]=data;
        const injection='<script>window.__diErrors=[];window.addEventListener("error",e=>window.__diErrors.push(e.message));window.addEventListener("unhandledrejection",e=>window.__diErrors.push(String(e.reason)));</script>';
        pages[scenario]=Buffer.from(data.html.replace('<head>','<head><base href="/synk/di/">'+injection)).toString('base64');
    }
    fs.mkdirSync(temp,{recursive:true});
    const phpPages='['+Object.entries(pages).map(([key,value])=>quote(key)+'=>'+quote(value)).join(',')+']';
    fs.writeFileSync(route,"<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!hash_equals("+quote(token)+",(string)($_GET['qa']??''))){http_response_code(404);exit;}$pages="+phpPages+";header('Content-Type: text/html; charset=utf-8');echo base64_decode($pages[$_GET['page']??'']??'');");
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
        await call('Page.navigate',{url:'http://localhost/synk/tmp/'+path.basename(route)+'?qa='+token+'&page='+name},session);
        await until('document.readyState==="complete" && !!document.getElementById("academic")','Dashboard did not load.');
        check(await evaluate('Array.from(document.querySelectorAll(".di-dashboard-jumps a")).map(a=>a.hash).join(",")==="#academic,#campus-faculty,#qualifications"'),'Dashboard groups are missing or unordered.');
        check(await evaluate('!document.body.textContent.includes("25%+")'),'The old account-completion threshold remains.');
        if(name==='page'){
            await until('document.querySelectorAll("#educationChart .apexcharts-canvas").length===1 && document.querySelectorAll("#employmentChart .apexcharts-canvas").length===1','Profile charts did not render.');
            check(await evaluate('document.querySelector("[data-metric=faculty]").textContent==="3"'),'University faculty count is incorrect.');
            check(await evaluate('document.querySelectorAll(".di-campus-card").length===Number(document.querySelector("[data-metric=campuses]").textContent)'),'Campus card count differs from the campus metric.');
            check(await evaluate('document.querySelectorAll(".di-profile-row").length===3'),'Teaching faculty register was not distinct.');
            check(await evaluate('document.querySelector(".di-dashboard-term").textContent.includes("2091-2092 - 1st Semester")'),'The reporting period did not follow official settings.');
            await evaluate('document.getElementById("campusFilter").value="'+known.page.campus+'";document.getElementById("campusFilter").dispatchEvent(new Event("change"))');
            check(await evaluate('Array.from(document.getElementById("collegeFilter").options).filter(o=>o.value!=="0"&&!o.hidden).every(o=>o.dataset.campus==="'+known.page.campus+'")'),'Campus selection exposes other-campus colleges.');
            await evaluate('document.getElementById("profileSearch").value="no_profile";document.getElementById("profileSearch").dispatchEvent(new Event("input"))');
            check(await evaluate('document.querySelectorAll(".di-profile-row:not([hidden])").length===1 && document.getElementById("profileTableCount").textContent.includes("of 1 teaching faculty")'),'Faculty register search did not follow the teaching cohort.');
            await evaluate('document.getElementById("profileSearch").value="";document.getElementById("profileSearch").dispatchEvent(new Event("input"))');
            check(await evaluate('document.querySelectorAll(".di-profile-row:not([hidden])").length===3'),'Register reset failed.');
            if(process.argv.includes('--screenshot')){
                for(const section of ['academic','campus-faculty','qualifications']){
                    await evaluate('document.getElementById("'+section+'").scrollIntoView({block:"start",behavior:"instant"});void 0');await delay(150);
                    const shot=await call('Page.captureScreenshot',{format:'png'},session);
                    fs.writeFileSync(path.join(temp,'di-dashboard-'+section+'-desktop.png'),Buffer.from(shot.data,'base64'));
                }
            }
        }
        if(name==='page_empty'){
            check(await evaluate('document.querySelectorAll(".di-campus-card").length===1 && document.querySelector("[data-metric=faculty]").textContent==="0"'),'Empty campus was omitted or showed foreign faculty.');
            check(await evaluate('document.getElementById("educationChart").textContent.includes("No teaching faculty")'),'Empty chart state is misleading.');
        }
        if(name==='page_missing_term')check(await evaluate('document.body.textContent.includes("Set the current academic year and semester") && document.querySelector("[data-metric=faculty]").textContent==="0"'),'Missing term borrowed historical records.');
        if(name==='page_next_semester')check(await evaluate('document.querySelector(".di-dashboard-term").textContent.includes("2nd Semester") && document.querySelector("[data-metric=faculty]").textContent==="1"'),'A changed official semester did not update the dashboard.');
        await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false},session);
        await delay(850);
        const fits=await evaluate('document.documentElement.scrollWidth<=innerWidth+2');
        if(!fits){const shot=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(temp,'di-dashboard-mobile-overflow.png'),Buffer.from(shot.data,'base64'));}
        check(fits,'Dashboard overflows at mobile width: '+name+' '+JSON.stringify(await evaluate('Array.from(document.querySelectorAll("main *")).filter(e=>e.getBoundingClientRect().right>innerWidth+2&&getComputedStyle(e).position!=="absolute").slice(0,8).map(e=>({tag:e.tagName,id:e.id,cls:e.className,width:e.getBoundingClientRect().width}))')));
        check((await evaluate('window.__diErrors')).length===0,'Dashboard browser error: '+JSON.stringify(await evaluate('window.__diErrors')));
        if(name==='page'&&process.argv.includes('--screenshot')){
            await evaluate('scrollTo({top:0,left:0,behavior:"instant"})');await delay(100);
            const shot=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(temp,'di-dashboard-mobile.png'),Buffer.from(shot.data,'base64'));
        }
    }
    console.log('DI dashboard browser: '+checks+' checks passed at 1440 px and 390 px.');
    await call('Browser.close');
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
    if(socket)socket.close();if(browser){browser.kill();await delay(800);}
    if(fs.existsSync(route))fs.unlinkSync(route);
    const target=path.resolve(profile);
    if(!target.startsWith(path.resolve(temp)+path.sep)||!/^di-dashboard-[0-9a-f]{16}$/.test(path.basename(target)))throw Error('Unexpected profile cleanup target.');
    if(fs.existsSync(target)){if(fs.lstatSync(target).isSymbolicLink())throw Error('Browser profile is a reparse point.');fs.rmSync(target,{recursive:true,force:true,maxRetries:5,retryDelay:200});}
});

