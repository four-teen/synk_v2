// Actual PHP dashboard and 1,000+ rolled-back students; browser API calls intercepted.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), nonce = crypto.randomBytes(8).toString('hex');
const route = path.join(root, 'tmp', `student-live-browser-${nonce}.php`), profile = path.join(root, '.tmp', `student-live-browser-${nonce}`);
const errors = [], gridRequests = [], workloadRequests = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let browser, ws, call, session, mode = 'ok', workloadMode='ok', checks = 0;
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };
async function main() {
  fs.writeFileSync(route, `<?php define('SYNK_STUDENT_PORTAL_QA','${nonce}'); require __DIR__.'/../tests/fixtures/student-portal-request.php';`);
  const response = await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${nonce}&scenario=activity_grid_current`);
  const data = await response.json();
  check(response.ok && data.students.length > 1000, 'The actual API fixture supplies every student beyond 1,000.');
  data.students[0].student_name = '<img src=x onerror=alert(1)> Student';
  const pastResponse = await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${nonce}&scenario=activity_grid_historical`);
  const historical = await pastResponse.json();
  const currentPage = await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${nonce}&scenario=activity_grid_page_current`);
  const html = await currentPage.text();
  const pastPage = await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${nonce}&scenario=activity_grid_page_historical`);
  const historicalHtml = await pastPage.text();
  check(currentPage.ok && pastPage.ok && pastResponse.ok && historical.live_presence === false,'Current and historical dashboard pages and data render from real PHP fixtures.');
  const pageConfig = JSON.parse(html.match(/<script id="studentActivityConfig"[^>]*>([^<]+)<\/script>/)[1]);
  const pastConfig = JSON.parse(historicalHtml.match(/<script id="studentActivityConfig"[^>]*>([^<]+)<\/script>/)[1]);
  let pageHtml = html;
  const fixture = scenario => JSON.parse(execFileSync('php', [path.join(root, 'tests/fixtures/college-dean-request.php'), scenario], { cwd:root, encoding:'utf8', windowsHide:true })).response;
  const heatmap = fixture('dashboard_heatmap');
  const workload = fixture('student_workload_ok'), workloadSecond = fixture('student_workload_page');
  workload.rows[0].student_name='<img src=x onerror=alert(1)> Workload student';
  const executable = [process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe', process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  browser = spawn(executable, ['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'], { windowsHide:true, stdio:['ignore','ignore','pipe'] });
  const address = await new Promise((resolve,reject) => {
    let output = ''; const timeout = setTimeout(() => reject(Error('Browser startup timed out.')),15000);
    browser.stderr.on('data', chunk => { output += chunk; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } }); browser.on('error',reject);
  });
  ws = new WebSocket(address); await new Promise((resolve,reject) => { ws.onopen=resolve; ws.onerror=reject; });
  let sequence=0; const pending=new Map();
  call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{
    const id=++sequence,timeout=setTimeout(()=>reject(Error('CDP timeout: '+method)),15000);
    pending.set(id,{resolve,reject,timeout});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));
  });
  ws.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(pending.has(message.id)){const item=pending.get(message.id);pending.delete(message.id);clearTimeout(item.timeout);message.error?item.reject(Error(message.error.message)):item.resolve(message.result);}
    if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);
    if(message.method==='Fetch.requestPaused'){
      const url=message.params.request.url,document=url.includes('/college-dean/index.php'),grid=url.includes('query_college_dean_student_activity.php'),isWorkload=url.includes('query_college_dean_student_workload.php');
      if(grid)gridRequests.push(new URLSearchParams(message.params.request.postData));
      let workloadData=workload;
      if(isWorkload){const post=new URLSearchParams(message.params.request.postData);workloadRequests.push(post);if(post.get('page')==='2')workloadData=workloadSecond;if(post.get('search'))workloadData={...workload,rows:[],pagination:{page:1,pages:1,total:0,from:0,to:0}};if(workloadMode==='error')workloadData={status:'error',message:'Workload retry test'};}
      const body=document?pageHtml:JSON.stringify(isWorkload?workloadData:grid?(mode==='error'?{status:'error',message:'Retry test'}:mode==='denied'?{status:'error',message:'College access changed.'}:mode==='historical'?historical:data):heatmap);
      call('Fetch.fulfillRequest',{requestId:message.params.requestId,responseCode:isWorkload&&workloadMode==='error'?503:grid&&['error','denied'].includes(mode)?(mode==='denied'?403:503):200,responseHeaders:[{name:'Content-Type',value:document?'text/html; charset=utf-8':'application/json'}],body:Buffer.from(body).toString('base64')},session).catch(error=>errors.push(error.message));
    }
  });
  const target=await call('Target.createTarget',{url:'about:blank'});
  session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
  await call('Page.enable',{},session);await call('Runtime.enable',{},session);
  await call('Fetch.enable',{patterns:[{urlPattern:'*college-dean/index.php*',resourceType:'Document'},{urlPattern:'*query_college_dean_student_activity.php*'},{urlPattern:'*query_college_dean_student_workload.php*'},{urlPattern:'*dashboard_college_dean_schedule_heatmap.php*'}]},session);
  const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
  const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate(`Boolean(${expression})`))return;await delay(100);}throw Error('Timed out: '+expression);};
  await call('Emulation.setDeviceMetricsOverride',{width:1600,height:1000,deviceScaleFactor:1,mobile:false},session);
  await call('Page.navigate',{url:'http://localhost/synk/college-dean/index.php'},session);
  await until(`document.readyState==='complete'&&document.querySelectorAll('.student-activity-square').length===${data.students.length}`);
  await until('document.querySelectorAll("[data-workload-student]").length===10');
  check(workloadRequests.at(-1).get('ay_id')===String(pageConfig.ay_id)&&workloadRequests.at(-1).get('semester')===String(pageConfig.semester)&&workloadRequests.at(-1).get('csrf_token')===pageConfig.csrf_token,'Workload requests carry the dashboard term and authenticated token.');
  check(await evaluate('document.getElementById("studentWorkloadList").textContent.includes("<img")&&!document.querySelector("#studentWorkloadList img")'),'Workload names are escaped rather than rendered as HTML.');
  check(await evaluate('(()=>{const a=document.querySelector("[data-workload-student] > a"),u=new URL(a.href);return !a.target&&u.searchParams.get("student_term")==="'+pageConfig.ay_id+':'+pageConfig.semester+'"&&u.searchParams.get("return_to")==="../college-dean/index.php#collegeStudentWorkload";})()'),'Show account stays in the same tab and preserves the selected academic term and return location.');
  await evaluate('document.querySelector(".student-workload-details summary").click()');
  check(await evaluate('document.querySelector(".student-workload-details").open&&document.querySelector(".student-workload-details li").textContent.includes("/")'),'Overlap details expand to show the conflicting subjects, sections and times.');
  for(const width of[1600,1024,768,390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false},session);await delay(450);
    const workloadFits=await evaluate('(()=>{const a=document.getElementById("collegeStudentActivity").getBoundingClientRect(),w=document.getElementById("collegeStudentWorkload").getBoundingClientRect(),p=document.querySelector(".college-programs-card").getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&Math.abs(a.left-w.left)<1&&Math.abs(a.width-w.width)<1&&Math.abs(p.width-w.width)<1&&w.top>=a.bottom&&p.top>=w.bottom;})()');
    if(!workloadFits)console.log(await evaluate('JSON.stringify({width:innerWidth,document:document.documentElement.scrollWidth,cards:[...document.querySelectorAll("#collegeStudentActivity,#collegeStudentWorkload,.college-programs-card")].map(e=>({id:e.id,...Object.fromEntries(["left","right","top","bottom","width"].map(k=>[k,e.getBoundingClientRect()[k]]))})),overflow:[...document.querySelectorAll("body *")].filter(e=>e.getBoundingClientRect().right>innerWidth+1&&getComputedStyle(e).position!=="fixed").slice(0,15).map(e=>({tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right}))})'));
    if(width===1600||width===390){await evaluate('document.getElementById("collegeStudentWorkload").scrollIntoView({block:"start",behavior:"instant"})');await delay(100);const shot=await call('Page.captureScreenshot',{format:'png'},session);fs.writeFileSync(path.join(root,'.tmp',`student-workload-${width}.png`),Buffer.from(shot.data,'base64'));}
    check(workloadFits,'The full-width workload card stays below access, above programs, and fits '+width+'px.');
  }
  await evaluate('document.getElementById("studentWorkloadNext").click()');await until('document.getElementById("studentWorkloadPage").textContent.includes("Page 2")');
  check(workloadRequests.at(-1).get('page')==='2','Next retrieves the second workload page.');
  await evaluate('document.getElementById("studentWorkloadSearch").value="NoMatchingStudent";document.getElementById("studentWorkloadSearch").dispatchEvent(new Event("input"))');
  await until('document.getElementById("studentWorkloadList").textContent.includes("No students match")');
  check(workloadRequests.at(-1).get('page')==='1'&&workloadRequests.at(-1).get('search')==='NoMatchingStudent','Search resets paging and presents an empty state.');
  await evaluate('document.getElementById("studentWorkloadSearch").value="";document.getElementById("studentWorkloadSignal").value="conflicts";document.getElementById("studentWorkloadSignal").dispatchEvent(new Event("change"))');await until('document.querySelectorAll("[data-workload-student]").length===10');
  check(workloadRequests.at(-1).get('signal')==='conflicts','The schedule-overlap filter reaches the endpoint.');
  workloadMode='error';await evaluate('document.getElementById("studentWorkloadRefresh").click()');await until('!document.getElementById("studentWorkloadNotice").hidden');
  check(await evaluate('document.getElementById("studentWorkloadNotice").textContent.includes("retry test")&&!document.getElementById("studentWorkloadRefresh").disabled'),'Workload failures leave a visible message and usable retry button.');
  workloadMode='ok';await evaluate('document.getElementById("studentWorkloadRefresh").click()');await until('document.querySelectorAll("[data-workload-student]").length===10&&document.getElementById("studentWorkloadNotice").hidden');
  await call('Emulation.setDeviceMetricsOverride',{width:1600,height:1000,deviceScaleFactor:1,mobile:false},session);await evaluate('window.scrollTo(0,0)');
  check(gridRequests.at(-1).get('ay_id')===String(pageConfig.ay_id)&&gridRequests.at(-1).get('semester')===String(pageConfig.semester),'Grid requests carry the academic year and semester of their own dashboard tab.');
  check(await evaluate(`document.getElementById('studentActivityTerm').textContent===${JSON.stringify(data.term.term_text)}`),'The card identifies the selected academic term.');
  check(await evaluate(`JSON.stringify([...document.querySelectorAll('.student-activity-square')].map(e=>Number(e.dataset.studentId)))===${JSON.stringify(JSON.stringify(data.students.map(row=>row.student_id)))}`),'Every square preserves the server alphabetical order.');
  for(const [level,count] of [[0,0],[1,1],[2,5],[3,10]]){
    const row=data.students.find(row=>row.successful_logins===count&&row.presence!=='online');
    check(await evaluate(`document.querySelector('[data-student-id="${row.student_id}"]').classList.contains('level-${level}')`),`The ${count}-login boundary uses the intended green level.`);
  }
  check(await evaluate(`document.querySelectorAll('.student-activity-square.is-online').length===${data.students.filter(row=>row.presence==='online').length}`),'Only live sessions are orange.');
  await evaluate('document.getElementById("collegeStudentActivity").scrollIntoView({block:"start",behavior:"instant"})');
  await delay(150);
  await evaluate('document.querySelector(".student-activity-square").dispatchEvent(new PointerEvent("pointerover",{bubbles:true}))');
  check(await evaluate('!document.getElementById("studentActivityTooltip").hidden && document.querySelector("#studentActivityTooltip strong").textContent.includes("<img") && !document.querySelector("#studentActivityTooltip img") && document.querySelector("#studentActivityTooltip .student-activity-swatch").getBoundingClientRect().width===42'),'Hover shows a magnified square and escaped student details.');
  await evaluate('window.__hoverSquare=document.querySelector(".student-activity-square");document.getElementById("studentActivityRefresh").click()');
  await until('!document.getElementById("studentActivityRefresh").disabled');
  check(await evaluate('window.__hoverSquare===document.querySelector(".student-activity-square") && !document.getElementById("studentActivityTooltip").hidden'),'Refreshing preserves square elements and an open hover popup.');
  await evaluate('window.__activityHeight=document.getElementById("studentActivityMap").clientHeight;document.getElementById("studentActivityRefresh").click()');
  await until('!document.getElementById("studentActivityRefresh").disabled');
  check(await evaluate('(()=>{const m=document.getElementById("studentActivityMap");return m.clientHeight===window.__activityHeight&&m.scrollHeight<=m.clientHeight+1&&m.scrollTop===0;})()'),'Refreshing retains the expanded grid with every square and no internal scrolling.');
  await evaluate('document.querySelector(".student-activity-square").focus();document.querySelector(".student-activity-square").dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowRight",bubbles:true}))');
  check(await evaluate('document.activeElement===document.querySelectorAll(".student-activity-square")[1] && !document.getElementById("studentActivityTooltip").hidden'),'Keyboard arrows move through squares and expose the same student details.');
  const find=data.students.find(row=>row.student_number.includes('-grid-0'));
  await evaluate(`document.getElementById('studentActivitySearch').value=${JSON.stringify(find.student_number)};document.getElementById('studentActivitySearch').dispatchEvent(new Event('input'))`);
  check(await evaluate('document.querySelectorAll(".student-activity-square").length===1'),'Search finds a student without paging through thousands of squares.');
  await evaluate('document.getElementById("studentActivitySearch").value="";document.getElementById("studentActivitySearch").dispatchEvent(new Event("input"));document.activeElement.blur()');
  const layout=async width=>{
    await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false},session);await delay(450);
    check(await evaluate(`(()=>{const c=document.getElementById('collegeStudentActivity').getBoundingClientRect(),g=document.querySelector('.college-dashboard-grid').getBoundingClientRect(),p=document.querySelector('.college-programs-card').getBoundingClientRect(),m=document.getElementById('studentActivityMap'),last=m.querySelector('.student-activity-square:last-child');return Math.abs(c.width-g.width)<1&&p.top>=c.bottom&&document.documentElement.scrollWidth<=innerWidth+1&&m.scrollHeight<=m.clientHeight+1&&m.scrollWidth<=m.clientWidth+1&&last.getBoundingClientRect().bottom<=m.getBoundingClientRect().bottom;})()`),`The full-width activity card contains every square without internal scrollbars at ${width}px.`);
  };
  await layout(2560);await layout(1920);await layout(1600);
  await evaluate('document.getElementById("collegeStudentActivity").scrollIntoView({block:"start"})');
  const capture=async name=>{const rect=await evaluate('(()=>{const r=document.getElementById("collegeStudentActivity").getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()');const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip:rect},session);fs.writeFileSync(path.join(root,'.tmp',name),Buffer.from(shot.data,'base64'));};
  await capture('student-activity-desktop.png');
  await layout(1024);await layout(768);await layout(390);
  await capture('student-activity-mobile.png');
  mode='historical';pageHtml=historicalHtml;
  await call('Page.navigate',{url:'http://localhost/synk/college-dean/index.php?preview=historical'},session);
  await until(`document.getElementById('studentActivityTerm')?.textContent===${JSON.stringify(historical.term.term_text)}&&document.querySelectorAll('.student-activity-square').length===${historical.students.length}&&document.getElementById('studentActivityRefresh')?.disabled===false`);
  check(gridRequests.at(-1).get('ay_id')===String(pastConfig.ay_id)&&gridRequests.at(-1).get('semester')===String(pastConfig.semester),'Historical tabs send their own selected academic year and semester.');
  check(await evaluate('document.getElementById("studentActivityOnlineLegend").hidden&&!document.querySelector(".online-summary")&&!document.querySelector(".student-activity-square.is-online")&&document.getElementById("studentActivityPresenceNote").textContent.includes("Historical term")'),'Historical terms show term counts without present-day online indicators.');
  mode='ok';pageHtml=html;
  await call('Page.navigate',{url:'http://localhost/synk/college-dean/index.php?preview=current'},session);
  await until(`document.querySelectorAll('.student-activity-square').length===${data.students.length}&&document.getElementById('studentActivityRefresh')?.disabled===false`);
  mode='error';await evaluate('document.getElementById("studentActivityRefresh").click()');await until('!document.getElementById("studentActivityNotice").hidden');
  check(await evaluate(`document.querySelectorAll('.student-activity-square').length===${data.students.length}`),'Temporary network/service errors retain the grid and show a retry notice.');
  mode='ok';data.students.forEach(row=>{if(row.presence==='online')row.online_seconds=1;});
  await evaluate('document.getElementById("studentActivityRefresh").click()');await until('document.getElementById("studentActivityNotice").hidden');
  await delay(5500);
  check(await evaluate('document.querySelectorAll(".student-activity-square.is-online").length===0'),'Orange expires locally even without another successful refresh.');
  mode='denied';await evaluate('document.getElementById("studentActivityRefresh").click()');await until('document.getElementById("studentActivityNotice").textContent.includes("College access changed")');
  check(await evaluate('!document.querySelector(".student-activity-square") && document.getElementById("studentActivitySummary").textContent.includes("unavailable")'),'Revoked access clears student squares and statistics.');
  check(errors.length===0,'No browser runtime errors: '+errors.join('; '));
  console.log(`Student live activity browser: ${checks} checks passed with ${data.students.length} students.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{
  if(call)try{await call('Browser.close');}catch(_){}
  if(ws)ws.close();
  if(browser&&browser.exitCode===null){browser.kill();await Promise.race([new Promise(resolve=>browser.once('exit',resolve)),delay(3000)]);}
  if(fs.existsSync(route))fs.unlinkSync(route);
  const resolved=path.resolve(profile);if(resolved.startsWith(path.resolve(root,'.tmp')+path.sep)&&fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true});
});
