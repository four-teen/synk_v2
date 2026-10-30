// Browser QA of the real rendered workload page with rollback-only QA identities.
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),crypto=require('node:crypto');
const {spawn,spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex');
const html=path.join(root,'.tmp',`workload-${nonce}.html`),json=path.join(root,'.tmp',`workload-${nonce}.json`),profile=path.join(root,'.tmp',`workload-browser-${nonce}`);
let server,browser,ws,call,checks=0,mode='normal';
const errors=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 const render=spawnSync('php',[path.join(root,'tests/professor-workload-students.php'),'--render',html,json],{cwd:root,encoding:'utf8',windowsHide:true});
 if(render.status!==0)throw Error(render.stdout+render.stderr);
 const fixtures=JSON.parse(fs.readFileSync(json,'utf8'));
 server=http.createServer(async(req,res)=>{
  const url=new URL(req.url,'http://localhost');
  if(url.pathname==='/synk/professor/workload-students.php'){
   const currentMode=mode,other=Number(url.searchParams.get('offering_id'))===fixtures.other.class.offering_id;
   let data=JSON.parse(JSON.stringify(other?fixtures.other:fixtures.roster));
   if(currentMode==='slow')await delay(1600);
   if(currentMode==='empty'){data.students=[];data.counts={active:0,inactive:0,students:0};}
   if(currentMode==='unsafe')data.students[0].full_name='<img src=x onerror=alert(1)> Test Student';
   res.writeHead(currentMode==='error'?503:200,{'Content-Type':'application/json','Cache-Control':'no-store'});
   res.end(currentMode==='bad-json'?'<html>Sign in again</html>':JSON.stringify(currentMode==='error'?{ok:false,message:'Test roster unavailable. Please try again.'}:{ok:true,...data}));return;
  }
  let file;
  if(url.pathname==='/synk/professor/workload.php')file=html;
  else if(url.pathname.startsWith('/synk/assets/')||url.pathname==='/synk/student/profile-placeholder.svg'||/^\/synk\/professor\/[a-z-]+\.(css|js)$/.test(url.pathname)){
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
 const open=async(other=false)=>{await evaluate(`new Promise(resolve=>{document.getElementById('workload-students-modal').addEventListener('shown.bs.modal',()=>resolve(true),{once:true});document.querySelector('.workload-table-wrap [data-offering-id="${other?fixtures.other.class.offering_id:fixtures.roster.class.offering_id}"]').click()})`);};
 const close=async()=>{await evaluate('bootstrap.Modal.getInstance(document.getElementById("workload-students-modal")).hide()');await until('!document.getElementById("workload-students-modal").classList.contains("show")&&!document.body.classList.contains("modal-open")');await delay(180);};
 await resize(1440);await call('Page.navigate',{url:`http://127.0.0.1:${server.address().port}/synk/professor/workload.php`},session);await until('document.readyState==="complete"&&typeof bootstrap!=="undefined"');
 check(await evaluate('Array.from(document.querySelectorAll(".workload-table thead th"),el=>el.textContent.trim()).join("|")==="Subject|Enrolled|Active / Inactive|Action"'),'The requested workload columns are incorrect.');
 check(await evaluate('!document.querySelector(".workload-class-details")&&document.querySelectorAll(".workload-summary-schedule").length===4'),'Class details remain, or compact meeting summaries are missing.');
 check(await evaluate('document.getElementById("workload-total-active").textContent==="1"&&document.getElementById("workload-total-inactive").textContent==="3"'),'Activity totals double-count shared students.');
 check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display!=="none"&&getComputedStyle(document.getElementById("professor-bottom-nav")).display==="none"'),'Desktop must retain its sidebar.');
 await screenshot('professor-workload-desktop.png');
 await evaluate('document.getElementById("workload-search").value="zz-no-match";document.getElementById("workload-search").dispatchEvent(new Event("input"))');
 check(await evaluate('!document.getElementById("workload-no-matches").hidden&&document.getElementById("workload-result-count").textContent.startsWith("0 of 2")'),'Class search does not filter or deduplicate desktop/mobile entries.');
 await evaluate('document.getElementById("workload-clear-search").click()');
 await open();await until('!document.getElementById("workload-students-content").hidden');
 check(await evaluate('document.querySelectorAll("#workload-roster-active li").length===1&&document.querySelectorAll("#workload-roster-inactive li").length===2'),'Student groups are inaccurate.');
 check(await evaluate('document.querySelector("#workload-roster-active li strong").textContent==="José Test Student Jr."&&document.querySelectorAll(".workload-student img").length===3'),'Names and student pictures are missing.');
 await until('Array.from(document.querySelectorAll(".workload-student img")).every(img=>img.complete&&img.naturalWidth>0)');
 check(await evaluate('document.querySelector(".workload-students-modal .modal-dialog").getBoundingClientRect().width>=1000'),'The roster modal is not wide.');
 await screenshot('professor-workload-roster-desktop.png');
 await evaluate('document.getElementById("workload-student-search").value="jose";document.getElementById("workload-student-search").dispatchEvent(new Event("input"))');
 check(await evaluate('!document.querySelector("#workload-roster-active li").hidden&&Array.from(document.querySelectorAll("#workload-roster-inactive li")).every(el=>el.hidden)'),'Roster search does not handle accents or separate empty groups.');
 await close();mode='error';await open();await until('!document.getElementById("workload-students-retry").hidden');
 check(await evaluate('document.getElementById("workload-students-status").textContent.includes("Test roster unavailable")&&document.getElementById("workload-students-content").hidden'),'A failed request exposes an old roster or hides its error.');
 mode='normal';await evaluate('document.getElementById("workload-students-retry").click()');await until('!document.getElementById("workload-students-content").hidden');await close();
 mode='bad-json';await open();await until('!document.getElementById("workload-students-retry").hidden');check(await evaluate('document.getElementById("workload-students-status").textContent.includes("sign in again")'),'An expired/non-JSON response does not explain recovery.');await close();
 mode='unsafe';await open();await until('!document.getElementById("workload-students-content").hidden');check(await evaluate('Array.from(document.querySelectorAll(".workload-student strong")).some(el=>el.textContent.includes("<img"))&&!document.querySelector(".workload-student strong img")'),'Student names are interpreted as HTML.');await close();
 mode='empty';await open();await until('!document.getElementById("workload-students-content").hidden');check(await evaluate('document.querySelectorAll(".workload-student").length===0&&!document.getElementById("workload-roster-inactive-empty").hidden'),'An empty roster displays stale students.');await close();
 mode='slow';await open();await close();mode='normal';await open(true);await until('!document.getElementById("workload-students-content").hidden');await delay(1700);check(await evaluate('document.querySelectorAll(".workload-student").length===2'),'An older request replaces the newly selected class.');await close();
 for(const width of[1199,1024,768,390,320]){
  await resize(width,800);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'The page overflows at '+width+'px.');
  check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display==="none"&&document.getElementById("layout-menu").inert&&getComputedStyle(document.getElementById("professor-bottom-nav")).display==="grid"'),'Mobile navigation is incorrect at '+width+'px.');
  check(await evaluate('Array.from(document.querySelectorAll("#professor-bottom-nav>a,#professor-bottom-nav>button")).every(el=>el.getBoundingClientRect().height>=44)'),'Footer touch targets are too small at '+width+'px.');
 }
 await screenshot('professor-workload-mobile.png');
 await evaluate('document.querySelector(".workload-class-grid .workload-class").scrollIntoView({block:"start",behavior:"instant"})');
 await until('document.querySelector(".workload-class-grid .workload-show-students").getBoundingClientRect().top<innerHeight');
 check(await evaluate('document.querySelector(".workload-class-grid .workload-show-students").getBoundingClientRect().width<=innerWidth&&getComputedStyle(document.querySelector(".workload-class-grid")).display==="grid"'),'Mobile class cards overflow or hide the roster action.');
 await screenshot('professor-workload-mobile-classes.png');
 await evaluate('window.scrollTo({top:0,behavior:"instant"})');
 await evaluate('new Promise(resolve=>{document.getElementById("professor-mobile-more").addEventListener("shown.bs.offcanvas",()=>resolve(true),{once:true});document.getElementById("professor-more-toggle").click()})');
 check(await evaluate('document.querySelectorAll("#professor-mobile-more nav a").length===5&&Array.from(document.querySelectorAll("#professor-mobile-more nav a")).some(el=>el.getAttribute("href")==="faculty-evaluations.php")&&document.getElementById("professor-more-toggle").getAttribute("aria-expanded")==="true"'),'More does not expose every sidebar destination.');
 await screenshot('professor-workload-more-mobile.png');
 await resize(1440);await until('!document.getElementById("professor-mobile-more").classList.contains("show")');
 check(await evaluate('getComputedStyle(document.getElementById("layout-menu")).display!=="none"&&!document.getElementById("layout-menu").inert'),'Resizing from More leaves the desktop sidebar inaccessible.');
 await resize(320,568);await evaluate('new Promise(resolve=>{document.getElementById("workload-students-modal").addEventListener("shown.bs.modal",()=>resolve(true),{once:true});document.querySelector(".workload-class-grid .workload-show-students").click()})');await until('!document.getElementById("workload-students-content").hidden');
 check(await evaluate('getComputedStyle(document.getElementById("professor-bottom-nav")).visibility==="hidden"&&document.getElementById("professor-bottom-nav").inert'),'The footer competes with the roster modal.');
 check(await evaluate('(()=>{const m=document.querySelector(".workload-students-modal .modal-content").getBoundingClientRect(),b=document.querySelector(".workload-students-modal .modal-body"),f=document.querySelector(".workload-students-modal .modal-footer").getBoundingClientRect();return m.width<=innerWidth&&m.bottom<=innerHeight+1&&f.bottom<=innerHeight+1&&b.clientHeight>0})()'),'The mobile modal clips its roster or Close button.');
 await screenshot('professor-workload-roster-mobile.png');await close();
 check(await evaluate('getComputedStyle(document.getElementById("professor-bottom-nav")).visibility==="visible"&&!document.getElementById("professor-bottom-nav").inert'),'Closing the modal does not restore the footer.');
 check(errors.length===0,'Browser errors: '+errors.join('; '));
 console.log('Professor workload browser: '+checks+' checks passed. Desktop/mobile screenshots saved in .tmp.');
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
 if(call)try{await call('Browser.close');}catch{}
 if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(resolve=>browser.once('exit',resolve));}
 if(server)await new Promise(resolve=>server.close(resolve));
 for(const file of[html,json])if(fs.existsSync(file))fs.unlinkSync(file);
 const relative=path.relative(path.join(root,'.tmp'),profile);
 if(relative&&!relative.startsWith('..')&&!path.isAbsolute(relative)&&fs.existsSync(profile))fs.rmSync(profile,{recursive:true,force:true});
});
