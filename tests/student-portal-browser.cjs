// node tests/student-portal-browser.cjs -- guarded, rollback-only local fixtures.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),nonce=crypto.randomBytes(8).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`student-portal-browser-${nonce}.php`),profile=path.join(root,'.tmp',`student-portal-browser-${nonce}`);
const photoFixture=path.join(root,'.tmp',`student-photo-browser-${nonce}.png`);
let browser,ws,call,checks=0;const errors=[],requests=[],delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let pendingFinalSubmission=null,finalSubmittingState=null;
const evaluationOnly=process.argv.includes('--evaluation-only');
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
async function main(){
 fs.writeFileSync(route,`<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root,'tests/fixtures/student-portal-request.php').replaceAll('\\','/')}';`);
 const exe=[process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe',process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
 browser=spawn(exe,['--headless=new','--no-sandbox','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 const socket=await new Promise((resolve,reject)=>{let logs='';const timeout=setTimeout(()=>reject(Error('Browser startup timeout.')),15000);browser.stderr.on('data',data=>{logs+=data;const m=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timeout);resolve(m[1]);}});browser.on('error',reject);});
 ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 let sequence=0;const pending=new Map();
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.id&&pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timeout);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
  if(m.method==='Runtime.consoleAPICalled'&&m.params.args[0]?.value==='QA_FINAL_SUBMIT_STATE')finalSubmittingState=JSON.parse(m.params.args[1].value);
  if(m.method==='Fetch.requestPaused'){
   requests.push(m.params.request);
   if(m.params.request.method==='POST'&&m.params.request.url.includes('/student/class-program.php')){
    // Hold the real browser POST before it reaches PHP; no student response is submitted.
    pendingFinalSubmission={requestId:m.params.requestId,sessionId:m.sessionId};return;
   }
   const presence=m.params.request.url.includes('/student_presence.php'),reminder=m.params.request.url.includes('/query_student_evaluation_prompt.php');call('Fetch.fulfillRequest',{requestId:m.params.requestId,responseCode:reminder?503:200,responseHeaders:[{name:'Content-Type',value:presence||reminder?'application/json':'text/html'}],body:Buffer.from(reminder?JSON.stringify({status:'error'}):presence?JSON.stringify({status:'ok',seconds_remaining:900,idle_seconds:900}):'<p id="qa-google-redirect">Opening Google</p>').toString('base64')},m.sessionId).catch(error=>errors.push(error.message));
  }
 });
 call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timeout=setTimeout(()=>{pending.delete(id);reject(Error(method+' timeout.'));},15000);pending.set(id,{resolve,reject,timeout});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const target=await call('Target.createTarget',{url:'about:blank'}),session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
 await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Fetch.enable',{patterns:[{urlPattern:'*backend/auth_google_student_start.php*',requestStage:'Request'},{urlPattern:'*backend/student_presence.php*',requestStage:'Request'},{urlPattern:'*backend/query_student_evaluation_prompt.php*',requestStage:'Request'},{urlPattern:'*student/class-program.php*',requestStage:'Request'}]},session);
 const evaluate=async expression=>{let r;try{r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true,userGesture:true},session);}catch(error){throw Error(error.message+' while evaluating '+expression.slice(0,180));}if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const until=async expression=>{for(let i=0;i<120;i++){if(await evaluate('Boolean('+expression+')'))return;await delay(100);}throw Error('Condition timed out: '+expression+'; '+errors.join('; '));};
 const fixtureUrl=scenario=>`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=${scenario}`;
 const navigate=async scenario=>{
  await call('Page.navigate',{url:fixtureUrl(scenario)},session);await until('document.readyState==="complete"');await delay(200);
  if(scenario==='student_evaluation'||scenario.startsWith('evaluation_access_student_')||scenario.startsWith('evaluation_access_prompt_')||scenario==='student_legacy_draft'){
   await until('document.getElementById("studentEvaluationPromptConfig")');
   // Finish Bootstrap's entry transition before pressing Start in the fixture.
   await delay(350);
   await evaluate(`(()=>{const c=JSON.parse(document.getElementById('studentEvaluationPromptConfig').textContent);if(!c.initial?.required)return;const u=new URL(document.getElementById('studentEvaluationPromptStart').href);history.replaceState(null,'',u);document.getElementById('studentEvaluationPromptStart').click()})()`);
   await until('!document.getElementById("studentEvaluationPrompt").classList.contains("show")&&!document.body.classList.contains("modal-open")');
  }
 };
 const resize=async (width,height=1000,mobile=false)=>{await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile},session);await delay(120);};
 const screenshot=async name=>fs.writeFileSync(path.join(root,'.tmp',name),Buffer.from((await call('Page.captureScreenshot',{format:'png'},session)).data,'base64'));
 const clickAt=async(x,y)=>{await call('Input.dispatchMouseEvent',{type:'mouseMoved',x,y},session);await call('Input.dispatchMouseEvent',{type:'mousePressed',x,y,button:'left',clickCount:1},session);await call('Input.dispatchMouseEvent',{type:'mouseReleased',x,y,button:'left',clickCount:1},session);};
 const clickElement=async expression=>{
  const point=await evaluate(`(()=>{const element=${expression},r=element.getBoundingClientRect(),x=r.left+r.width/2,y=r.top+r.height/2,hit=document.elementFromPoint(x,y);return{x,y,clickable:(hit===element||element.contains(hit))&&x>=0&&x<innerWidth&&y>=0&&y<innerHeight};})()`);
  if(!point.clickable)throw Error('A screen click cannot reach '+expression);
  await clickAt(point.x,point.y);
 };
 const key=async(key,code,number)=>{await call('Input.dispatchKeyEvent',{type:'keyDown',key,code,windowsVirtualKeyCode:number},session);await call('Input.dispatchKeyEvent',{type:'keyUp',key,code,windowsVirtualKeyCode:number},session);};
 if(!evaluationOnly){
 await resize(1440);await navigate('login_page');
 check(await evaluate('document.querySelector("#studentLoginForm")?.method==="post" && !document.querySelector("input[type=password]")'),'Student login uses its own Google-only form.');
 check(await evaluate('document.getElementById("student-email").required && document.querySelector("input[name=csrf_token]").value.length===64'),'Student login requires an email and a CSRF token.');
 for(const width of[1440,768,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Student sign-in fits '+width+'px.');if(width===1440)await screenshot('student-login-1440.png');if(width===390)await screenshot('student-login-390.png');}
 await evaluate('document.getElementById("studentGoogleLogin").click()');await delay(200);check(requests.length===0,'An empty email does not start sign-in.');
 await evaluate('document.getElementById("student-email").value="qa@sksu.edu.ph";document.getElementById("studentGoogleLogin").click()');await until('document.getElementById("qa-google-redirect")');
 const post=new URLSearchParams(requests.at(-1).postData);check(post.get('email')==='qa@sksu.edu.ph'&&post.get('csrf_token').length===64,'The login form sends the entered email and bound token.');
 await navigate('login_error');check(await evaluate('document.querySelector("[role=alert]").textContent.includes("saved for Administrator review") && document.getElementById("student-email").value.startsWith("wrong-")'),'A failed sign-in retains the input and explains the correction path.');
 await resize(1440);await navigate('dashboard');await until('typeof Helpers!=="undefined" && document.querySelectorAll("[data-subject-card]").length===2');
 await until('Boolean(document.getElementById("studentPresenceConfig"))');
 check(requests.some(r=>r.url.includes('/student_presence.php')&&new URLSearchParams(r.postData).get('action')==='status'),'The actual Student page loads its authenticated session heartbeat.');
 check(await evaluate('document.querySelector("#student-subject-grid").textContent.includes("Introduction to Computing") && !document.body.textContent.includes("Other Student Only")'),'The dashboard renders only the signed-in student’s imported subjects.');
 check(await evaluate('!document.getElementById("studentSetupModal") && document.querySelectorAll(".student-summary-card").length===4 && document.getElementById("studentTotalUnits") && document.getElementById("studentWeeklyHours").textContent.includes("pending")'),'Four overview cards include units and hours, with missing curriculum details shown as pending.');
 check(await evaluate(`(()=>{const c=document.querySelector('[data-subject-card]'),p=c.querySelector('.student-professor-photo').getBoundingClientRect(),n=c.querySelector('.student-professor-name'),t=c.querySelector('h3');return p.left<n.getBoundingClientRect().left&&p.width===p.height&&getComputedStyle(n).color==='rgb(196, 71, 12)'&&parseFloat(getComputedStyle(t).fontSize)===19&&!c.querySelector('.student-subject-top');})()`),'Subject rows place the professor portrait on the left, the orange name first and a smaller subject title below it.');
 check(await evaluate(`(()=>{const lines=[...document.querySelector('[data-subject-card] .student-subject-schedule').querySelectorAll('.student-schedule-line')];return lines.length===2&&lines[0].textContent.startsWith('LEC')&&lines[1].textContent.startsWith('LAB')&&lines[1].getBoundingClientRect().top-lines[0].getBoundingClientRect().bottom<=3;})()`),'Lecture and laboratory schedules appear on separate closely spaced lines.');
 await screenshot('student-dashboard-1440.png');
 for(const width of[1440,1024,768,390,320]){
  await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'The subject dashboard fits '+width+'px.');
  check(await evaluate(`(()=>{const n=document.querySelector('.student-bottom-nav'),r=n.getBoundingClientRect(),links=[...n.querySelectorAll('a')],all=[...document.querySelectorAll('#student-mobile-more nav a')],sidebar=[...document.querySelectorAll('#layout-menu .student-menu-card')];return innerWidth<1200?getComputedStyle(document.getElementById('layout-menu')).display==='none'&&getComputedStyle(n).position==='fixed'&&Math.abs(r.bottom-innerHeight)<1&&r.left===0&&r.right===document.documentElement.clientWidth&&all.length===sidebar.length&&all.every((a,i)=>a.getAttribute('href')===sidebar[i].getAttribute('href'))&&links.every(a=>a.getBoundingClientRect().height>=44)&&n.querySelector('[aria-current=page]').textContent.includes('Home')&&document.getElementById('student-more-toggle'):getComputedStyle(n).display==='none'&&getComputedStyle(document.getElementById('layout-menu')).display!=='none';})()`),'The menu uses the desktop sidebar or an accessible footer with all pages under More at '+width+'px.');
  if(width===390)await screenshot('student-dashboard-390.png');
 }
 await evaluate('window.scrollTo({top:document.scrollingElement.scrollHeight,behavior:"instant"})');await delay(120);
 const footerFits=await evaluate(`document.querySelector('.content-footer').getBoundingClientRect().bottom<=document.querySelector('.student-bottom-nav').getBoundingClientRect().top`);
 if(!footerFits)console.log(await evaluate(`JSON.stringify({scroll:scrollY,height:document.scrollingElement.scrollHeight,footer:document.querySelector('.content-footer').getBoundingClientRect().bottom,nav:document.querySelector('.student-bottom-nav').getBoundingClientRect().top,padding:getComputedStyle(document.querySelector('.content-wrapper')).paddingBottom})`));
 check(footerFits,'The bottom menu leaves room for the final content and footer.');
 await evaluate('window.scrollTo({top:0,behavior:"instant"})');
 await evaluate('document.getElementById("subject-search").value="computing";document.getElementById("subject-search").dispatchEvent(new Event("input"))');
 check(await evaluate('document.querySelectorAll("[data-subject-card]:not([hidden])").length===1'),'Subject search filters the displayed cards.');
 await evaluate('document.getElementById("subject-search").value="no matching subject";document.getElementById("subject-search").dispatchEvent(new Event("input"))');
 check(await evaluate('!document.getElementById("subject-search-empty").hidden && document.getElementById("subject-search-result").textContent.includes("0 of 2")'),'Search provides an empty state and a result count.');
 await evaluate('document.getElementById("subject-search-clear").click()');check(await evaluate('document.querySelectorAll("[data-subject-card]:not([hidden])").length===2'),'Clear search restores all subjects.');
 check(await evaluate('!document.querySelector(".student-term-toggle")&&!document.getElementById("studentTermModal")'),'Students cannot select another academic year or semester.');
 await navigate('old_term');check(await evaluate('document.querySelectorAll("[data-subject-card]").length===2&&!document.querySelector("#student-subject-grid").textContent.includes("Prior Term Subject")'),'Old URLs retain the Administrator current period.');
 await resize(390);await evaluate('document.getElementById("student-more-toggle").click()');await until('document.getElementById("student-mobile-more").classList.contains("show")');
 check(await evaluate(`(()=>{const n=document.querySelector('#student-mobile-more nav'),r=n.getBoundingClientRect();return getComputedStyle(n).gridTemplateColumns.split(' ').length===3&&r.left>=0&&r.right<=innerWidth&&document.querySelector('#student-mobile-more a[href*=profile]')&&document.getElementById('student-more-toggle').getAttribute('aria-expanded')==='true'})()`),'More opens a multirow footer menu with profile access.');
 await evaluate('document.querySelector("#student-mobile-more [data-bs-dismiss]").click()');await until('!document.getElementById("student-mobile-more").classList.contains("show")');
 for(const [scenario,label] of [['profile_page','Profile'],['prospectus_page','Prospectus']]){
  await resize(390);await navigate(scenario);await until('document.querySelector("#student-mobile-more [aria-current=page]")');
  check(await evaluate(`document.body.classList.contains('student-portal')&&document.getElementById('student-more-toggle').classList.contains('active')&&Math.abs(document.querySelector('.student-bottom-nav').getBoundingClientRect().bottom-innerHeight)<1&&getComputedStyle(document.getElementById('layout-menu')).display==='none'&&document.documentElement.scrollWidth<=innerWidth`),'The shared mobile bottom bar is active and fits on '+label+'.');
 }
 await resize(1440);await navigate('profile_complete_page');await until('document.querySelectorAll("[data-subject-card]").length===2');
 check(await evaluate(`!document.getElementById('studentProfileDetailsTitle')&&!document.getElementById('studentSetupModal')&&document.querySelector('.content-wrapper>.container-xxl').firstElementChild.classList.contains('student-profile-workspace')`),'My Profile removes Student details and starts with the compact Student Workspace.');
 check(await evaluate(`document.querySelectorAll('.student-subject-item .student-professor-photo').length===2&&getComputedStyle(document.querySelector('.student-professor-name')).color==='rgb(196, 71, 12)'&&document.querySelectorAll('.student-subject-item .student-schedule-line').length===3&&!document.querySelector('.student-subject-entry')`),'My Profile uses the same professor portrait, orange name and compact schedule rows as My Subjects.');
 for(const width of[1440,768,390,320]){
  await resize(width);
  check(await evaluate(`(()=>{const w=document.querySelector('.student-profile-workspace').getBoundingClientRect(),s=document.querySelector('.student-subject-panel').getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&w.top>=document.getElementById('layout-navbar').getBoundingClientRect().bottom&&s.top-w.bottom<=25&&!document.getElementById('subject_ay_id');})()`),'The profile workspace and current-period subject panel fit neatly at '+width+'px.');
  if([1440,390].includes(width))await screenshot('student-profile-'+width+'.png');
 }
 await navigate('profile_term_tamper');check(await evaluate(`document.querySelectorAll('[data-subject-card]').length===2&&!document.querySelector('.student-subject-panel').textContent.includes('Prior Term Subject')`),'Profile URLs cannot override the Administrator semester.');
 await resize(1440);await navigate('finances_page');await until('document.querySelector("[data-finance-total=due]")');
 check(await evaluate(`document.querySelector('.student-bottom-nav [aria-current=page]').textContent.trim()==='Finances' && document.querySelector('#layout-menu [aria-current=page]').textContent.includes('My Finances') && document.querySelectorAll('.student-finance-stat').length===4`),'My Finances is missing its active menu state or summary cards.');
 check(await evaluate(`['student-contributions','student-events','student-violations','student-payments','student-account-total'].every(id=>document.getElementById(id)) && document.getElementById('student-events').textContent.includes('PRESENT EVENT') && document.getElementById('student-events').textContent.includes('ABSENT EVENT') && document.getElementById('student-events').textContent.includes('Attendance pending') && !document.querySelector('#student-contributions img[src=x]')`),'The financial page omitted financial/attendance sections or rendered unsafe titles.');
 for(const width of[1440,768,390,320]){
  await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Student finances overflow '+width+'px.');
  check(await evaluate(`(()=>{const n=document.querySelector('.student-bottom-nav');return innerWidth>=1200||[...n.querySelectorAll('a')].every(a=>a.getBoundingClientRect().left>=0&&a.getBoundingClientRect().right<=innerWidth&&a.getBoundingClientRect().height>=44)})()`),'The financial menu cannot be used at '+width+'px.');
  if([1440,390,320].includes(width))await screenshot('student-finances-'+width+'.png');
 }
 await evaluate('(()=>{const a=document.querySelector(".student-finance-links a[href=\\"#student-events\\"]");a.href=location.href.split("#")[0]+"#student-events";a.click()})()');await until('location.hash==="#student-events"');
 await until('document.getElementById("student-events").getBoundingClientRect().top>=0 && document.getElementById("student-events").getBoundingClientRect().top<100');
 check(await evaluate('document.getElementById("student-events").getBoundingClientRect().top>=0 && document.getElementById("student-events").getBoundingClientRect().top<100'),'The attendance shortcut did not reach the event list.');
 await resize(390);await navigate('photo_required_page');await until('document.getElementById("studentProfilePhotoModal")?.classList.contains("show")');
 check(await evaluate(`!document.getElementById('studentSetupModal') && document.getElementById('studentProfilePhotoModal').dataset.required==='1' && !document.querySelector('#studentProfilePhotoModal .btn-close') && !document.querySelector('input[name=student_number],select[name=program_id]') && document.querySelector('#studentProfilePhotoModal').textContent.includes('Add your profile photo to continue')`),'A missing student photo did not show the required photo-only profile prompt.');
 await evaluate('bootstrap.Modal.getInstance(document.getElementById("studentProfilePhotoModal")).hide()');await delay(200);
 check(await evaluate('document.getElementById("studentProfilePhotoModal").classList.contains("show")'),'The required photo prompt can be dismissed without a photo.');
 for(const width of[1440,390,320]){await resize(width);check(await evaluate(`(()=>{const m=document.querySelector('#studentProfilePhotoModal .modal-content').getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&m.left>=0&&m.right<=innerWidth&&m.top>=0&&m.bottom<=innerHeight+1})()`),'The required photo dialog overflows '+width+'px.');}
 await call('Emulation.setDeviceMetricsOverride',{width:320,height:640,deviceScaleFactor:1,mobile:false},session);await delay(150);
 check(await evaluate(`(()=>{const m=document.querySelector('#studentProfilePhotoModal .modal-content').getBoundingClientRect(),b=document.querySelector('#studentProfilePhotoModal .modal-body'),f=document.querySelector('#studentProfilePhotoModal .modal-footer').getBoundingClientRect();return m.bottom<=innerHeight+1&&f.bottom<=innerHeight+1&&b.scrollHeight>b.clientHeight&&b.clientHeight>0})()`),'A short phone screen cannot scroll the photo form while keeping its Save button visible.');
 await screenshot('student-required-photo-320.png');
 check(await evaluate('!document.querySelector("#student-photo-form input[type=file]") && document.getElementById("student-photo-save").disabled && document.getElementById("student-camera-open")'),'Students can select an old file or save without a checked camera capture.');
 await resize(1440);await navigate('dean_preview');await until('document.querySelectorAll("[data-subject-card]").length===2');
 check(await evaluate('document.querySelector(".student-preview-notice").textContent.includes("Dean preview") && document.querySelector(".student-preview-notice").textContent.includes("Read only")'),'The Show Account view identifies the Dean and its read-only scope.');
 check(await evaluate('document.querySelector(".student-header-cluster a").getAttribute("href")==="../college-dean/students.php" && !document.querySelector("a[href=\\\"../logout.php\\\"]")'),'The preview returns to the Dean directory and does not log out the Dean.');
 check(await evaluate('[...document.querySelectorAll("#layout-menu a")].filter(a=>a.href.includes("profile.php")).every(a=>a.href.includes("preview_student_id=")&&a.href.includes("student_term=")) && !document.querySelector("#layout-menu a[href*=class-program]")'),'Show Account retains the selected student and hides Faculty Evaluation while the college has closed collection.');
 for(const width of[1440,768,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector(".student-header-cluster a").getBoundingClientRect().bottom<=document.querySelector("#layout-navbar").getBoundingClientRect().bottom'),'Dean account preview fits '+width+'px.');if(width===390)await screenshot('dean-student-account-preview-390.png');}
 await resize(1440);await navigate('dean_evaluation');await until('document.querySelectorAll(".faculty-eval-modal").length===2');
 check(await evaluate('document.querySelector(".content-wrapper").textContent.includes("Dean preview") && document.querySelector(".content-wrapper").textContent.includes("A submitted comment for Dean review.")'),'The Dean sees saved and submitted evaluations in a read-only view.');
 check(await evaluate('[...document.querySelectorAll(".faculty-eval-modal select")].every(s=>s.disabled) && [...document.querySelectorAll(".faculty-eval-modal textarea")].every(t=>t.readOnly) && !document.querySelector("form[method=post] button[type=submit]")'),'Ratings, comments, draft saves and final submission are locked.');
 check(await evaluate('[...document.querySelectorAll(".faculty-eval-modal select[name=\\\"ratings[COM1]\\\"]")].map(s=>s.value).join(",")==="4,5"'),'Draft and submitted evaluation forms retain the saved ratings.');
 check(await evaluate(`!document.querySelector('form[method=get]')&&!document.getElementById('term_key')&&!document.getElementById('faculty_key')&&!document.getElementById('evaluation_college_id')&&document.querySelector('.student-header-cluster a').getAttribute('href')==='../college-dean/students.php'&&[...document.querySelectorAll('.faculty-eval-modal form')].every(f=>f.action.includes('preview_student_id=')&&f.action.includes('return_to='))`),'The evaluation overview has no redundant filters while preview forms retain identity and the return link.');
 await evaluate('document.querySelector("button[data-bs-target^=\\\"#facultyEvaluationModal_\\\"]").click()');await until('document.querySelector(".faculty-eval-modal.show")');await delay(250);
 for(const width of[1440,390,320]){await resize(width);const fits=await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector(".faculty-eval-modal.show .modal-content").scrollWidth<=document.querySelector(".faculty-eval-modal.show .modal-content").clientWidth+1');if(!fits){await screenshot('dean-evaluation-overflow.png');console.log(await evaluate('JSON.stringify({width:innerWidth,document:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll(".content-wrapper *, .faculty-eval-modal.show *")].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,12).map(e=>({tag:e.tagName,class:e.className,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width,scroll:e.scrollWidth}))})'));}check(fits,'Evaluation preview form fits '+width+'px.');if(width===390)await screenshot('dean-evaluation-form-390.png');}
 await evaluate('document.querySelector(".faculty-eval-modal.show [data-bs-dismiss]").click()');await until('!document.querySelector(".faculty-eval-modal.show")');await delay(250);
 await resize(1440);await screenshot('dean-evaluation-preview-1440.png');
 await navigate('dean_evaluation_historical');
 await until('document.querySelector(".content-wrapper")?.textContent.includes("Prior Term Subject")');
 check(await evaluate('document.querySelector(".content-wrapper").textContent.includes("Dean preview") && document.querySelectorAll(".faculty-eval-modal").length===1 && document.querySelector("#layout-menu a[href*=index]").href.includes("student_term=")'),'A historical review link keeps the Dean preview and its college faculty list without a term dropdown.');
 }
 await navigate('student_evaluation');await until('document.querySelectorAll(".faculty-eval-modal").length===2');
 await resize(390);
 check(await evaluate(`(()=>{const n=document.querySelector('.student-bottom-nav');return n.querySelector('[aria-current=page]').textContent.includes('Evaluation')&&n.querySelectorAll('a').length===4&&document.getElementById('student-more-toggle')&&[...n.querySelectorAll('a')].some(a=>a.textContent.trim()==='QR')&&[...n.querySelectorAll('a')].some(a=>a.textContent.trim()==='Finances')&&Math.abs(n.getBoundingClientRect().bottom-innerHeight)<1;})()`),'Faculty Evaluation is active and Finances/QR remain available in the mobile menu.');
 check(await evaluate('document.querySelectorAll(".faculty-eval-modal select").length===30 && [...document.querySelectorAll(".faculty-eval-modal select")].every(s=>s.options.length===6&&s.options[1].textContent.includes("Always manifested"))'),'Students receive the 15-question frequency-based SET form for each faculty.');
 check(await evaluate('document.querySelector(".faculty-eval-modal").textContent.includes("Management of Teaching and Learning refers to") && document.querySelector(".faculty-eval-modal").textContent.includes("January 14, 2026")'),'The form includes the category definitions and instrument revision date.');
 for(const width of[1440,1024,768,390,320]){
  await resize(width);
  check(await evaluate(`(()=>{const card=document.querySelector('[data-faculty-evaluation-card]'),subjects=card.querySelector('.faculty-evaluation-subjects').getBoundingClientRect(),button=card.querySelector('.faculty-evaluate-button').getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&button.height>=44&&(innerWidth>991?button.left>=subjects.right:button.top>=subjects.bottom&&Math.abs(button.width-card.querySelector('.faculty-evaluation-action').clientWidth+28)<2);})()`),'The emphasized Evaluate button sits beside subjects or spans the mobile action area at '+width+'px.');
  if([1440,390].includes(width)){
   if(width===390)await evaluate(`document.getElementById('facultyEvaluationOverview').scrollIntoView({block:'start',behavior:'instant'})`);
   await screenshot('student-evaluation-list-'+width+'.png');
  }
 }
 check(await evaluate(`document.querySelectorAll('[data-faculty-evaluation-card] .student-professor-photo').length===2&&getComputedStyle(document.querySelector('[data-faculty-evaluation-card] .student-professor-name')).color==='rgb(196, 71, 12)'&&!document.querySelector('form[method=get]')&&[...document.querySelectorAll('[data-faculty-evaluation-card] a')].every(a=>!a.textContent.includes('Select Faculty'))`),'Faculty Evaluation uses the shared subject design and opens the form directly from each professor.');
 await evaluate('document.querySelector("button[data-bs-target^=\\\"#facultyEvaluationModal_\\\"]").click()');await until('document.querySelector(".faculty-eval-modal.show")');await delay(250);
 for(const width of[1440,768,390,320]){
  await resize(width);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector(".faculty-eval-modal.show .modal-content").scrollWidth<=document.querySelector(".faculty-eval-modal.show .modal-content").clientWidth+1'),'The revised questionnaire fits '+width+'px.');
  if(width<992)check(await evaluate(`(()=>{const m=document.querySelector('.faculty-eval-modal.show .modal-content').getBoundingClientRect(),h=document.querySelector('.faculty-eval-modal.show .modal-header').getBoundingClientRect(),f=document.querySelector('.faculty-eval-modal.show .modal-footer').getBoundingClientRect(),c=document.querySelector('.faculty-eval-modal.show .modal-header .btn-close').getBoundingClientRect();return Math.abs(m.left)<1&&Math.abs(m.top)<1&&Math.abs(m.right-innerWidth)<1&&Math.abs(m.bottom-innerHeight)<1&&h.top>=0&&Math.abs(f.bottom-innerHeight)<1&&c.width>=44&&c.right<=innerWidth;})()`),'SET fills the screen and keeps header, Close and Save Draft available at '+width+'px.');
  if(width===390)await screenshot('student-revised-set-390.png');
 }
 await resize(844,390,true);
 check(await evaluate(`(()=>{const m=document.querySelector('.faculty-eval-modal.show .modal-content').getBoundingClientRect(),b=document.querySelector('.faculty-eval-modal.show .modal-body');return Math.abs(m.width-innerWidth)<1&&Math.abs(m.height-innerHeight)<1&&b.clientHeight>0&&b.scrollHeight>b.clientHeight;})()`),'The fullscreen SET adapts to a short landscape mobile viewport.');
 await evaluate(`const body=document.querySelector('.faculty-eval-modal.show .modal-body');body.scrollTop=body.scrollHeight;`);
 check(await evaluate(`(()=>{const b=document.querySelector('.faculty-eval-modal.show .modal-body'),f=document.querySelector('.faculty-eval-modal.show .modal-footer').getBoundingClientRect(),h=document.querySelector('.faculty-eval-modal.show .modal-header').getBoundingClientRect();return b.scrollTop>0&&h.top>=0&&Math.abs(f.bottom-innerHeight)<1&&b.getBoundingClientRect().bottom<=f.top+1;})()`),'Questions scroll independently while the SET title and action buttons stay on screen.');
 await screenshot('student-revised-set-landscape.png');
 await resize(390,844,true);
 check(await evaluate(`(()=>{const r=document.querySelector('.faculty-eval-modal.show .modal-content').getBoundingClientRect();return Math.abs(r.width-innerWidth)<1&&Math.abs(r.height-innerHeight)<1;})()`),'A mobile device returning to portrait still uses the full available viewport.');
 await resize(320);

 check(await evaluate(`(()=>{const field=document.querySelector('.faculty-eval-modal.show textarea[name="comment_text"]');field.value=String.fromCharCode(32,10,8203);field.dispatchEvent(new Event('input'));return field.required&&!field.checkValidity()&&field.validationMessage.includes('comment');})()`),'A blank or invisible-only comment passes the faculty form validation.');
 check(await evaluate(`(()=>{const field=document.querySelector('.faculty-eval-modal.show textarea[name="comment_text"]');field.value='Clear lessons and helpful explanations.';field.dispatchEvent(new Event('input'));return field.checkValidity();})()`),'A visible faculty comment remains invalid after correcting it.');
 await navigate('evaluation_access_prompt_review');await until('document.querySelectorAll(".faculty-review-item").length===2');
 check(await evaluate(`(()=>{const button=document.querySelector('.faculty-final-review-button');return !button.disabled&&button.classList.contains('is-ready')&&getComputedStyle(button).backgroundColor==='rgb(201, 82, 0)'&&getComputedStyle(button).animationName==='faculty-review-pulse'&&document.getElementById('faculty-final-review-note').textContent.includes('finalize and submit');})()`),'The final review button lacks its ready color, heartbeat emphasis or submission instruction.');
 await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]},session);
 check(await evaluate(`getComputedStyle(document.querySelector('.faculty-final-review-button')).animationName==='none'&&getComputedStyle(document.querySelector('.faculty-final-review-button .bx')).animationName==='none'`),'Reduced-motion preferences do not stop the final review heartbeat.');
 await call('Emulation.setEmulatedMedia',{features:[]},session);
 await resize(390,844,true);await evaluate(`document.getElementById('facultyEvaluationOverview').scrollIntoView({block:'start',behavior:'instant'})`);await screenshot('student-final-review-button-mobile.png');
 await evaluate(`new Promise(resolve=>{document.getElementById('finalEvaluationReviewModal').addEventListener('shown.bs.modal',()=>resolve(true),{once:true});document.querySelector('.faculty-final-review-button').click();})`);
 check(await evaluate(`document.querySelectorAll('#finalEvaluationReviewModal .faculty-review-name').length===2&&document.querySelectorAll('#finalEvaluationReviewModal .faculty-review-comment p').length===2&&!document.querySelector('#finalEvaluationReviewModal .faculty-review-subjects')&&document.querySelectorAll('#finalEvaluationReviewModal .faculty-review-item .badge').length===2`),'Final review retains subject details or omits names, averages or comments.');
 for(const [width,height] of [[1440,1000],[1024,800],[768,900],[390,844],[320,568],[844,390]]){
  await resize(width,height,width<992);
  const finalFits=await evaluate(`(()=>{const modal=document.getElementById('finalEvaluationReviewModal'),content=modal.querySelector('.modal-content').getBoundingClientRect(),body=modal.querySelector('.modal-body').getBoundingClientRect(),footer=modal.querySelector('.modal-footer').getBoundingClientRect(),buttons=[...modal.querySelectorAll('.modal-footer>.btn')].map(b=>b.getBoundingClientRect());return content.left>=-1&&content.right<=innerWidth+1&&content.top>=-1&&content.bottom<=innerHeight+1&&body.height>0&&body.bottom<=footer.top+1&&buttons.length===2&&buttons.every(b=>b.height>=48&&b.top>=0&&b.bottom<=innerHeight+1&&b.left>=0&&b.right<=innerWidth+1)&&modal.querySelector('.modal-content').scrollWidth<=modal.querySelector('.modal-content').clientWidth+1;})()`);
  if(!finalFits){await screenshot('student-final-review-overflow.png');console.log(await evaluate(`JSON.stringify({width:innerWidth,height:innerHeight,items:[...document.querySelectorAll('#finalEvaluationReviewModal .modal-dialog,#finalEvaluationReviewModal .modal-content,#finalEvaluationReviewModal form,#finalEvaluationReviewModal .modal-body,#finalEvaluationReviewModal .modal-footer')].map(el=>({class:el.className,rect:el.getBoundingClientRect().toJSON(),display:getComputedStyle(el).display,height:getComputedStyle(el).height}))})`));}
  check(finalFits,'Final review clips its Close or Submit Final Evaluation button at '+width+'x'+height+'.');
  if(width===390)await screenshot('student-final-review-390.png');
  if(width===320)await screenshot('student-final-review-320.png');
 }
 await evaluate(`(()=>{const body=document.querySelector('#finalEvaluationReviewModal .modal-body');body.scrollTop=body.scrollHeight;})()`);
 check(await evaluate(`(()=>{const modal=document.getElementById('finalEvaluationReviewModal'),body=modal.querySelector('.modal-body'),footer=modal.querySelector('.modal-footer').getBoundingClientRect();return body.scrollTop>0&&footer.bottom<=innerHeight+1&&body.getBoundingClientRect().bottom<=footer.top+1&&[...modal.querySelectorAll('.modal-footer>.btn')].every(b=>b.getBoundingClientRect().bottom<=innerHeight+1);})()`),'Scrolling final review hides its action buttons in landscape.');
 await screenshot('student-final-review-landscape.png');
 for(const [width,height] of [[1440,1000],[390,844],[320,568],[844,390]]){
  await resize(width,height,width<992);
  await clickElement(`document.querySelector('#finalEvaluationReviewModal button[type="submit"]')`);
  await until('Swal.isVisible()&&Swal.getPopup().getAnimations().every(animation=>animation.playState!=="running")');
  check(await evaluate(`Swal.getTitle().textContent==='Submit Final Evaluation?'&&document.querySelector('#finalFacultyEvaluationForm').dataset.confirmed!=='1'&&Swal.getContainer().parentElement.id==='finalEvaluationReviewModal'`),'The final confirmation is missing or outside the review focus boundary.');
  await screenshot('student-final-confirmation-current.png');
  const confirmationGeometry=await evaluate(`({viewport:[innerWidth,innerHeight],container:{z:getComputedStyle(Swal.getContainer()).zIndex,rect:Swal.getContainer().getBoundingClientRect().toJSON()},popup:Swal.getPopup().getBoundingClientRect().toJSON(),buttons:[Swal.getConfirmButton(),Swal.getCancelButton()].map(button=>{const r=button.getBoundingClientRect(),hit=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return{rect:r.toJSON(),hit:hit?.outerHTML.slice(0,220),self:hit===button,height:getComputedStyle(button).minHeight};})})`);
  if(!confirmationGeometry.buttons.every(button=>button.self&&button.rect.height>=48&&button.rect.top>=0&&button.rect.bottom<=height))console.log(JSON.stringify(confirmationGeometry));
  check(await evaluate(`[Swal.getConfirmButton(),Swal.getCancelButton()].every(button=>{const r=button.getBoundingClientRect(),hit=document.elementFromPoint(r.left+r.width/2,r.top+r.height/2);return r.height>=48&&r.top>=0&&r.bottom<=innerHeight&&r.left>=0&&r.right<=innerWidth&&(hit===button||button.contains(hit));})`),'The confirmation is covered or its buttons are clipped at '+width+'x'+height+'.');
  check(await evaluate(`document.activeElement===Swal.getCancelButton()&&document.querySelector('#finalEvaluationReviewModal .modal-dialog').inert`),'Bootstrap steals focus from the alert or leaves the review interactive.');
  await key('Tab','Tab',9);
  check(await evaluate('Swal.getPopup().contains(document.activeElement)'),'Tab leaves the visible confirmation.');
  await evaluate(`document.getElementById('finalFacultyEvaluationForm').requestSubmit();document.getElementById('finalFacultyEvaluationForm').requestSubmit()`);
  check(await evaluate('document.querySelectorAll(".student-final-confirmation").length===1'),'Repeated Submit clicks create multiple confirmations.');
  await clickAt(3,3);
  check(await evaluate('Swal.isVisible()&&document.getElementById("finalEvaluationReviewModal").classList.contains("show")'),'A background tap dismisses or submits the final confirmation.');
  await screenshot('student-final-confirmation-'+width+'.png');
  if(width===390||width===844)await key('Escape','Escape',27);else await clickElement('Swal.getCancelButton()');
  await until(`!Swal.getContainer()&&!document.querySelector('#finalEvaluationReviewModal .modal-dialog').inert&&document.activeElement===document.querySelector('#finalEvaluationReviewModal button[type="submit"]')`);
  check(await evaluate('document.getElementById("finalEvaluationReviewModal").classList.contains("show")&&!document.querySelector("#finalFacultyEvaluationForm button[type=submit]").disabled'),'Cancel or Escape does not restore a usable review modal.');
 }
 check(requests.filter(request=>request.method==='POST'&&request.url.includes('/student/class-program.php')).length===0,'Cancelling confirmation sent a final evaluation.');
 await evaluate(`new Promise(resolve=>{const modal=document.getElementById('finalEvaluationReviewModal');modal.addEventListener('hidden.bs.modal',()=>resolve(true),{once:true});modal.querySelector('.modal-footer [data-bs-dismiss]').click();})`);
 check(await evaluate(`!document.body.classList.contains('modal-open')&&getComputedStyle(document.getElementById('student-bottom-nav')).visibility==='visible'`),'The review Close button does not restore mobile navigation.');

 // Confirm with a real screen click, intercepting the POST before it can save anything.
 await resize(390,844,true);
 await evaluate(`new Promise(resolve=>{document.getElementById('finalEvaluationReviewModal').addEventListener('shown.bs.modal',()=>resolve(true),{once:true});document.querySelector('.faculty-final-review-button').click();})`);
 const finalScope=await evaluate(`Object.fromEntries(new FormData(document.getElementById('finalFacultyEvaluationForm')))`);
 await evaluate(`(()=>{const original=HTMLFormElement.prototype.submit;let calls=0;HTMLFormElement.prototype.submit=function(){if(++calls>1)throw Error('Repeated final form navigation');this.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));this.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));const button=this.querySelector('button[type="submit"]');console.log('QA_FINAL_SUBMIT_STATE',JSON.stringify({disabled:button.disabled,label:button.textContent,busy:this.getAttribute('aria-busy'),confirmed:this.dataset.confirmed,calls}));return original.call(this);};})()`);
 await clickElement(`document.querySelector('#finalEvaluationReviewModal button[type="submit"]')`);
 await until('Swal.isVisible()&&Swal.getPopup().getAnimations().every(animation=>animation.playState!=="running")');
 await clickElement('Swal.getConfirmButton()');
 for(let i=0;i<100&&(!pendingFinalSubmission||!finalSubmittingState);i++)await delay(50);
 check(Boolean(pendingFinalSubmission),'Confirm did not send the final evaluation request.');
 check(finalSubmittingState?.disabled&&finalSubmittingState.label.includes('Submitting')&&finalSubmittingState.busy==='true'&&finalSubmittingState.confirmed==='1'&&finalSubmittingState.calls===1,'Confirm leaves no visible submitting state or allows another submission.');
 const finalRequests=requests.filter(request=>request.method==='POST'&&request.url.includes('/student/class-program.php'));
 check(finalRequests.length===1,'Confirm sends more than one final evaluation request.');
 const finalPost=new URLSearchParams(finalRequests[0].postData);
 check(['submit_final_faculty_evaluation','csrf_token','term_key','evaluation_college_id'].every(field=>finalPost.get(field)===finalScope[field])&&Boolean(finalPost.get('csrf_token')),'The confirmed request loses its CSRF token or college/term scope.');
 await call('Fetch.fulfillRequest',{requestId:pendingFinalSubmission.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],body:Buffer.from('<main id="qa-final-evaluation-submitted">Final evaluation request received by the QA interceptor.</main>').toString('base64')},pendingFinalSubmission.sessionId);
 await until('document.getElementById("qa-final-evaluation-submitted")');
 check(await evaluate('document.getElementById("qa-final-evaluation-submitted").textContent.includes("request received")'),'The confirmed submission leaves the student on a blocked review screen.');
 pendingFinalSubmission=null;
 await navigate('evaluation_access_prompt_no_comment_page');await until('document.querySelectorAll(".faculty-eval-modal").length===2');
 check(await evaluate(`document.querySelector('.faculty-final-review-button').disabled&&document.querySelector('.faculty-evaluation-state').textContent.includes('required comment')`),'A previously saved draft without a comment unlocks final review.');
 if(evaluationOnly){check(errors.length===0,'No evaluation browser runtime errors: '+errors.join('; '));console.log(`Student evaluation mobile review: ${checks} browser checks passed.`);return;}

 await navigate('evaluation_access_student_multiple_subjects');await until(`document.querySelectorAll('[data-faculty-evaluation-card]').length===1`);
 check(await evaluate(`document.querySelectorAll('[data-faculty-evaluation-card] [data-subject-card]').length===2&&document.querySelectorAll('.faculty-evaluate-button').length===1&&document.querySelector('.faculty-evaluation-action').textContent.includes('covers all 2 subjects')`),'A professor teaching multiple subjects has one clearly explained evaluation action.');
 await navigate('evaluation_access_student_stale_filter');await until(`document.querySelectorAll('[data-faculty-evaluation-card]').length===2`);
 check(await evaluate(`document.querySelectorAll('.faculty-evaluate-button').length===2`),'An older faculty-selection URL still shows all assigned professors.');
 await navigate('student_legacy_draft');await until('document.querySelectorAll(".faculty-eval-modal").length===2');
 check(await evaluate('[...document.querySelectorAll(".faculty-eval-modal select")].every(s=>s.value==="") && document.querySelector(".content-wrapper").textContent.includes("Please answer and save the revised 15-question SET form") && !document.querySelector("button[data-bs-target=\\\"#finalEvaluationReviewModal\\\"]:not([disabled])")'),'Previous drafts have empty revised ratings and do not unlock final submission.');
 await resize(1440);await navigate('dean_faculty_evaluations_empty');await until('document.getElementById("deanFacultyEvaluations")');
 check(await evaluate('document.getElementById("deanEvaluationEmptyNotice") && document.querySelector("a[data-show-evaluation]") && document.querySelector("#layout-menu a[href=\\\"faculty-evaluations.php\\\"]")'),'The Dean can open the evaluation page and faculty list before any evaluations exist.');
 check(await evaluate('document.getElementById("deanEvaluationFacultyList").open'),'The faculty list is open before a faculty member is selected.');
 await navigate('dean_faculty_evaluations_detail');await until('document.querySelectorAll("article[data-evaluation-instrument]").length===2');
 check(await evaluate('document.body.textContent.includes("Current SET feedback") && document.body.textContent.includes("Previous instrument feedback") && !document.body.textContent.includes("PRIVATE_DRAFT_FEEDBACK") && !document.body.textContent.includes("FOREIGN_SUBMITTED_FEEDBACK") && !document.querySelector("#deanEvaluationDetail script")'),'The Dean sees scoped submitted feedback, escaped comments and separate instrument reports.');
 check(await evaluate('!document.getElementById("deanEvaluationFacultyList").open'),'Selecting a faculty collapses the long faculty list so results are easy to reach.');
 for(const width of[1440,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'The Dean evaluation page fits '+width+'px.');if(width===390){await evaluate('window.scrollTo({top:document.getElementById("deanEvaluationDetail").getBoundingClientRect().top+window.scrollY-100,behavior:"instant"})');await delay(150);await screenshot('dean-faculty-evaluations-390.png');}}
 await resize(1440);
 const deanEvaluationUrl=new URL(fixtureUrl('dean_faculty_evaluations_detail'));
 await evaluate(`const f=document.getElementById('deanEvaluationFilters');f.action=${JSON.stringify(deanEvaluationUrl.origin+deanEvaluationUrl.pathname)};for(const [name,value]of Object.entries({qa:${JSON.stringify(token)},scenario:'dean_faculty_evaluations_detail'})){const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;f.append(input);}document.getElementById('deanEvaluationInstrument').value='legacy-20';f.querySelector('button[type=submit]').click();`);
 await until('document.querySelectorAll("article[data-evaluation-instrument]").length===1 && document.querySelector("article[data-evaluation-instrument=\\\"legacy-20\\\"]")');
 check(await evaluate('document.getElementById("deanEvaluationInstrument").value==="legacy-20" && !document.querySelector("article[data-evaluation-instrument=\\\"sksu-set-2026-rev01\\\"]")'),'Changing the instrument filter reloads only the selected evaluation version.');
 await navigate('dean_faculty_evaluations_historical');await until('document.getElementById("deanEvaluationDetail")');
 check(await evaluate('document.getElementById("deanEvaluationDetail").textContent.includes("Historical submitted feedback") && !document.getElementById("deanEvaluationDetail").textContent.includes("Current SET feedback")'),'The evaluation page follows the Dean selected historical academic year and semester.');
 await resize(1440);await navigate('admin_page');await until('document.querySelectorAll(".student-issue-form").length===2');
 check(await evaluate('document.querySelector(".table").textContent.includes("Google verified") && !document.querySelector("img[src=x]")'),'Administrator diagnostics distinguish verified evidence and safely render entered emails.');
 for(const width of[1440,390]){await resize(width);const fits=await evaluate('document.documentElement.scrollWidth<=innerWidth');if(!fits){await screenshot('student-login-issues-overflow.png');console.log(await evaluate('JSON.stringify({doc:document.documentElement.scrollWidth,window:innerWidth,responsive:[...document.querySelectorAll(".table-responsive,.card,.card-body,.content-wrapper")].map(e=>({tag:e.tagName,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right,overflow:getComputedStyle(e).overflowX,position:getComputedStyle(e).position,scroll:e.scrollWidth}))})'));}check(fits,'Administrator review fits '+width+'px.');}
 await resize(1440);await screenshot('student-login-issues-1440.png');
 check(errors.length===0,'No browser runtime errors: '+errors.join('; '));console.log(`Student portal: ${checks} browser checks passed.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;}).finally(async()=>{if(call)try{await call('Browser.close');}catch{}if(ws)ws.close();if(browser&&browser.exitCode===null){browser.kill();await new Promise(resolve=>browser.once('exit',resolve));}if(fs.existsSync(route))fs.unlinkSync(route);if(fs.existsSync(photoFixture))fs.unlinkSync(photoFixture);const resolved=path.resolve(profile);if(resolved.startsWith(path.resolve(root,'.tmp')+path.sep)&&fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true});});
