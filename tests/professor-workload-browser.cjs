// Browser QA of the real rendered workload page with rollback-only QA identities.
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto');
const {spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex');
const html=path.join(root,'.tmp',`workload-${nonce}.html`),profile=path.join(root,'.tmp',`workload-browser-${nonce}`);
let server,browser,ws,call,checks=0;
const errors=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 const render=spawnSync('php',[path.join(root,'tests/professor-workload-summary_lms.php'),'--render',html],{cwd:root,encoding:'utf8',windowsHide:true});
 if(render.status!==0)throw Error(render.stdout+render.stderr);
 server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,'http://localhost');
  let file;
  if(url.pathname==='/synk/professor/workload.php')file=html;
  else if(url.pathname.startsWith('/synk/assets/')||url.pathname==='/synk/student/profile-placeholder.svg'||/^\/synk\/professor\/[a-z_-]+\.(css|js)$/.test(url.pathname)){
   const candidate=path.resolve(root,url.pathname.slice('/synk/'.length));
   if(candidate.startsWith(root+path.sep))file=candidate;
  }
  if(!file||!fs.existsSync(file)){res.writeHead(404);res.end();return;}
  const mime={'.html':'text/html; charset=utf-8','.js':'application/javascript','.css':'text/css','.svg':'image/svg+xml','.png':'image/png','.jpg':'image/jpeg','.woff2':'font/woff2','.woff':'font/woff','.ttf':'font/ttf'};
  res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'application/octet-stream'});fs.createReadStream(file).pipe(res);
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 if(!exe)throw Error('Chrome or Edge is required for browser QA.');
 browser=spawn(exe,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const address=await new Promise((resolve,reject)=>{let logs='';const timer=setTimeout(()=>reject(Error('Browser start timeout.')),15000);browser.stderr.on('data',chunk=>{logs+=chunk;const match=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timer);resolve(match[1]);}});browser.on('error',reject);});
 ws=new WebSocket(address);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timer);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);});
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout.'));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);
 const evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
 const until=async expression=>{for(let i=0;i<100;i++){if(await evaluate('Boolean('+expression+')'))return;await delay(80);}throw Error('Condition timed out: '+expression+'; '+errors.join('; '));};
 const resize=async(width,height=1000)=>{await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false},session);await delay(140);};
 const screenshot=async name=>fs.writeFileSync(path.join(root,'.tmp',name),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 await resize(1440);await call('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/synk/professor/workload.php`},session);await until('document.readyState==="complete"&&typeof bootstrap!=="undefined"');
 check(await evaluate('document.querySelectorAll(".workload-class-grid>article").length===2 && !document.querySelector(".workload-table") && document.querySelectorAll(".workload-class-footer .workload-manage-class-lms").length===2 && !document.querySelector(".workload-show-students,.workload-students-modal")'),'Each subject renders once as a card, with Manage Class as its only footer action.');
 check(await evaluate('(()=>{const summaries=[...document.querySelectorAll(".workload-class-summary-lms")],first=summaries[0],style=getComputedStyle(first);return summaries.length===2&&summaries.every(summary=>summary.querySelectorAll("p").length===2)&&first.querySelector("p").textContent.trim()==="6 activities · 2 drafts"&&first.querySelectorAll("strong").length===2&&first.textContent.includes("Next deadline: Assignment 3")&&first.querySelector("time").dateTime.endsWith("+08:00")&&style.fontStyle==="italic"&&style.textAlign==="left"&&style.color==="rgb(0, 0, 0)"&&summaries[1].textContent.includes("None scheduled");})()'),'Each footer shows scoped activity counts and the next published deadline in two plain italic lines.');
 check(await evaluate('getComputedStyle(document.querySelector(".workload-class-grid")).gridTemplateColumns.split(" ").length===2 && [...document.querySelectorAll(".workload-class")].every(card=>card.querySelectorAll(".workload-class-stats>div").length===3)'),'The desktop subject grid has two cards per row with separate enrolled, active and inactive counts.');
 check(await evaluate('document.querySelectorAll(".workload-meeting-details").length===2 && [...document.querySelectorAll(".workload-meeting-details")].every(details=>!details.open) && [...document.querySelectorAll(".workload-class-header .workload-class-title")].every(title=>title.textContent.trim())'),'Each subject card has a readable title and a collapsed schedule disclosure.');
 await evaluate('document.querySelector(".workload-class-grid .workload-meeting-details>summary").click()');
 check(await evaluate('(()=>{const details=document.querySelector(".workload-class-grid .workload-meeting-details");return details.open && details.querySelector(".workload-meeting-content").getBoundingClientRect().height>0 && [...details.querySelectorAll(".workload-meeting-list>li")].every(meeting=>meeting.querySelector(".workload-meeting-days").textContent.trim()&&meeting.querySelector(".workload-meeting-time").textContent.trim()&&meeting.querySelector(".workload-meeting-room").textContent.trim());})()'),'Expanding a subject keeps each lecture or lab together with its own days, time and room.');
 await call('Page.bringToFront',{},session);await evaluate('document.querySelector(".workload-class-grid .workload-meeting-details>summary").focus()');
 await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',unmodifiedText:'\r',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13},session);await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13},session);
 await until('!document.querySelector(".workload-class-grid .workload-meeting-details").open');
 check(await evaluate('!document.querySelector(".workload-class-grid .workload-meeting-details").open'),'The schedule disclosure also works from the keyboard.');
 check(await evaluate('document.getElementById("workload-total-active").textContent==="1"&&document.getElementById("workload-total-inactive").textContent==="3"'),'Activity totals double-count shared students.');
 check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display!=="none"&&getComputedStyle(document.getElementById("professor-bottom-nav")).display==="none"'),'Desktop must retain its sidebar.');
 await screenshot('professor-workload-desktop.png');
 await evaluate('document.getElementById("workload-search").value="zz-no-match";document.getElementById("workload-search").dispatchEvent(new Event("input"))');
 check(await evaluate('!document.getElementById("workload-no-matches").hidden&&document.getElementById("workload-result-count").textContent.startsWith("0 of 2")'),'Class search does not filter or deduplicate desktop/mobile entries.');
 await evaluate('document.getElementById("workload-clear-search").click()');
 for(const width of[1199,1024,768,390,320]){
  await resize(width,800);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'The page overflows at '+width+'px.');
  check(await evaluate('(()=>{const grid=document.querySelector(".workload-class-grid"),details=grid.querySelector(".workload-meeting-details");details.open=true;const fits=document.documentElement.scrollWidth<=innerWidth&&details.querySelector("summary").getBoundingClientRect().height>=44&&details.querySelector(".workload-meeting-content").getBoundingClientRect().height>0;details.open=false;return fits;})()'),'Subject schedules expand without overflow and have a full-size touch target at '+width+'px.');
  check(await evaluate('(()=>{const grid=document.querySelector(".workload-class-grid"),columns=getComputedStyle(grid).gridTemplateColumns.split(" ").length;return columns===(innerWidth>=768?2:1)&&[...grid.querySelectorAll(".workload-class-footer")].every(footer=>[...footer.querySelectorAll(".btn")].every(button=>button.getBoundingClientRect().height>=44&&button.getBoundingClientRect().width<=footer.clientWidth));})()'),'Cards use the right column count and keep footer actions accessible at '+width+'px.');
  check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display==="none"&&document.getElementById("layout-menu").inert&&getComputedStyle(document.getElementById("professor-bottom-nav")).display==="grid"'),'Mobile navigation is incorrect at '+width+'px.');
  check(await evaluate('Array.from(document.querySelectorAll("#professor-bottom-nav>a,#professor-bottom-nav>button")).every(el=>el.getBoundingClientRect().height>=44)'),'Footer touch targets are too small at '+width+'px.');
 }
 await screenshot('professor-workload-mobile.png');
 await evaluate('document.querySelector(".workload-class-grid .workload-class").scrollIntoView({block:"start",behavior:"instant"})');
 await until('document.querySelector(".workload-class-grid .workload-manage-class-lms").getBoundingClientRect().top<innerHeight');
 check(await evaluate('document.querySelector(".workload-class-grid .workload-manage-class-lms").getBoundingClientRect().width<=innerWidth&&getComputedStyle(document.querySelector(".workload-class-grid")).display==="grid"'),'Mobile class cards overflow or hide the Manage Class action.');
 await screenshot('professor-workload-mobile-classes.png');
 await evaluate('window.scrollTo({top:0,behavior:"instant"})');
 await evaluate('new Promise(resolve=>{document.getElementById("professor-mobile-more").addEventListener("shown.bs.offcanvas",()=>resolve(true),{once:true});document.getElementById("professor-more-toggle").click()})');
 check(await evaluate('document.querySelectorAll("#professor-mobile-more nav a").length===5&&Array.from(document.querySelectorAll("#professor-mobile-more nav a")).some(el=>el.getAttribute("href")==="faculty-evaluations.php")&&document.getElementById("professor-more-toggle").getAttribute("aria-expanded")==="true"'),'More does not expose every sidebar destination.');
 await screenshot('professor-workload-more-mobile.png');
 await resize(1440);await until('!document.getElementById("professor-mobile-more").classList.contains("show")');
 check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display!=="none"&&!document.getElementById("layout-menu").inert'),'Resizing from More leaves the desktop sidebar inaccessible.');
 check(errors.length===0,'Browser errors: '+errors.join('; '));
 console.log('Professor workload browser: '+checks+' checks passed. Desktop/mobile screenshots saved in .tmp.');
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
 if(call)try{await call('Browser.close');}catch{}
 if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(resolve=>browser.once('exit',resolve));}
 if(server)await new Promise(resolve=>server.close(resolve));
 for(const file of[html])if(fs.existsSync(file))fs.unlinkSync(file);
 const relative=path.relative(path.join(root,'.tmp'),profile);
 if(relative&&!relative.startsWith('..')&&!path.isAbsolute(relative)&&fs.existsSync(profile))fs.rmSync(profile,{recursive:true,force:true});
});
