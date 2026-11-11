// Real PHP pages and assets; controlled API responses isolate editor behavior.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const root=path.resolve(__dirname,'..'),tag=crypto.randomBytes(10).toString('hex'),token=crypto.randomBytes(24).toString('hex');
const route=path.join(root,'tmp',`grading-browser-${tag}_lms.php`),profile=path.join(root,'.tmp',`grading-browser-${tag}_lms`);
const pending=new Map(),errors=[],failures=[],requests=[];
let browser,ws,call,session,config,checks=0,nextId=100,failGet=true,conflict=false;
const records=Object.fromEntries(['midterm','final'].map(grading_period=>[grading_period,{scheme_id:0,grading_period,version:0,updated_at:null,components:[],total_weight:0}]));
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms)),clone=value=>JSON.parse(JSON.stringify(value));
const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
const phpString=value=>"'"+value.replace(/\\/g,'\\\\').replace(/'/g,"\\'")+"'";
async function evaluate(expression){const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;}
async function until(expression){for(let i=0;i<150;i++){try{if(await evaluate(expression))return;}catch{}await delay(100);}throw Error('Browser condition timed out: '+expression);}
async function click(selector){await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);}
async function answerDialog(confirmed=true){await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');await click(confirmed?'.swal2-confirm':'.swal2-cancel');await until('!document.querySelector(".swal2-popup:not(.swal2-toast)") && !synkLmsFeedback.isPending()');}
async function fill(selector,value){await evaluate(`(()=>{const node=document.querySelector(${JSON.stringify(selector)});node.value=${JSON.stringify(value)};node.dispatchEvent(new Event('input',{bubbles:true}));})()`);}
async function resize(width,height=1100){await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<600},session);await delay(120);}
const selector=(period,tail)=>`[data-grading-period="${period}"] ${tail}`;
async function field(period,index,name,value){await fill(selector(period,`[data-component="${index}"] [data-field="${name}"]`),value);}
function api(request){
  requests.push(request);check(JSON.stringify(request.context)===JSON.stringify(config.context)&&request.csrf_token===config.csrf_token,'Every grading request carries signed context and CSRF.');
  if(request.action==='get'){if(failGet){failGet=false;return {status:503,ok:false,message:'Temporary grading service error. <img src=x onerror=alert(1)> Try again.'};}return {ok:true,schemes:clone(records)};}
  if(request.action!=='save')throw Error('Unexpected grading request.');
  if(conflict){conflict=false;return {status:409,ok:false,message:'This grading system changed in another tab. Reload this period before saving.'};}
  const payload=request.payload,existing=records[payload.grading_period];
  check(payload.version===existing.version,'The editor submits the saved revision.');
  check(payload.components.every(item=>!Object.hasOwn(item,'uid')),'Editor-only identifiers are excluded from saves.');
  const record={scheme_id:existing.scheme_id||++nextId,grading_period:payload.grading_period,version:existing.version+1,updated_at:'2026-11-10 12:00:00',total_weight:100,
    components:payload.components.map(item=>({...item,component_id:item.component_id||++nextId,weight:Number(item.weight)}))};
  records[payload.grading_period]=record;return {ok:true,scheme:clone(record)};
}
async function main(){
  fs.writeFileSync(route,`<?php define('SYNK_PROFESSOR_LMS_QA','${token}'); require ${phpString(path.join(root,'tests/fixtures/professor-class-request_lms.php'))};`);
  const pages={};
  for(const name of ['class','grading']){const r=await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&case=${name}`);pages[name]=await r.text();check(r.status===200&&!/Parse error|Fatal error|xdebug-error/i.test(pages[name]),'Actual '+name+' page renders on PHP 7.1.');}
  const match=pages.grading.match(/<script id="gradingConfig" type="application\/json">(.*?)<\/script>/s);if(!match)throw Error('Missing grading configuration.');config=JSON.parse(match[1]);
  const executable=[process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);if(!executable)throw Error('Edge or Chrome is required.');
  browser=spawn(executable,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
  const socket=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{log+=data;const m=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timer);resolve(m[1]);}});browser.on('error',reject);});
  ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  let sequence=0;call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error('Browser timeout: '+method));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
  ws.addEventListener('message',event=>{
    const m=JSON.parse(event.data);
    if(pending.has(m.id)){const item=pending.get(m.id);pending.delete(m.id);clearTimeout(item.timer);m.error?item.reject(Error(m.error.message)):item.resolve(m.result);}
    if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description||m.params.exceptionDetails.text);
    if(m.method==='Network.responseReceived'&&m.params.response.status>=400&&m.params.response.url.startsWith('http://localhost/')&&!m.params.response.url.includes('grading_api_lms.php'))failures.push(m.params.response.url);
    if(m.method==='Page.javascriptDialogOpening'){errors.push('Unexpected native dialog: '+m.params.type);call('Page.handleJavaScriptDialog',{accept:true},session).catch(e=>errors.push(e.message));}
    if(m.method==='Fetch.requestPaused')(async()=>{const {requestId,request}=m.params,isApi=request.url.includes('grading_api_lms.php'),data=isApi?api(JSON.parse(request.postData)):pages[new URL(request.url).searchParams.get('view')==='grading'?'grading':'class'];const status=isApi?(data.status||200):200;if(isApi)delete data.status;await call('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:isApi?'application/json':'text/html; charset=utf-8'}],body:Buffer.from(isApi?JSON.stringify(data):data).toString('base64')},session);})().catch(e=>errors.push(e.stack));
  });
  const target=await call('Target.createTarget',{url:'about:blank'});session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
  for(const method of ['Page.enable','Runtime.enable','Network.enable'])await call(method,{},session);
  await resize(1440);await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost/synk/professor/class_lms.php*',resourceType:'Document'},{urlPattern:'http://localhost/synk/professor/grading_api_lms.php'}]},session);
  await call('Page.navigate',{url:'http://localhost/synk/professor/class_lms.php'},session);await until('document.readyState==="complete" && !!document.querySelector(".class-lms-tool-grid")');
  check(await evaluate('getComputedStyle(document.querySelector(".class-lms-tool-grid")).gridTemplateColumns.split(" ").length===3 && document.querySelectorAll(".class-lms-tool").length===6 && !!document.querySelector(".class-lms-tool-link[href*=grading]")'),'The overview has six tools in three desktop columns, including Manage Grading System and Grade Book.');
  check(await evaluate('document.querySelector("[data-class-tool=students] .class-lms-tool-footer").textContent.includes("260") && document.querySelector("[data-class-tool=activities] .class-lms-tool-footer").textContent.includes("0") && document.querySelectorAll("[data-class-tool=grading] .is-not-set").length===2'),'Overview footers show recorded enrollment, assessment count, and each grading period status.');
  check(await evaluate('["gradebook","materials","announcements"].every(key=>{const node=document.querySelector(`[data-class-tool=${key}]`);return !!node.querySelector(".class-lms-tool-purpose") && !node.querySelector(".class-lms-tool-metric");})'),'Unimplemented tools use purpose text without invented counts.');
  await evaluate('document.querySelector(".class-lms-tools").scrollIntoView({block:"center",behavior:"instant"})');
  const gradebook=await evaluate('(()=>{const r=document.querySelector("[data-class-tool=gradebook]").getBoundingClientRect();return {x:r.x+10,y:r.y+10};})()');
  await call('Input.dispatchMouseEvent',{type:'mouseMoved',...gradebook},session);
  await until('getComputedStyle(document.querySelector("[data-class-tool=gradebook]")).transform!=="none"');
  check(await evaluate('getComputedStyle(document.querySelector("[data-class-tool=gradebook] .class-lms-tool-link"),"::after").position==="absolute"'),'Cards have hover feedback and a full-card click target.');
  for(const key of ['materials','announcements']){
    const point=await evaluate(`(()=>{const r=document.querySelector('[data-class-tool=${key}]').getBoundingClientRect();return {x:r.x+10,y:r.y+10};})()`);
    await call('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point},session);await call('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point},session);
    await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
    check(await evaluate('Swal.getTitle().textContent.length>0 && Swal.getHtmlContainer().textContent.includes("not available yet")'),'Clicking the '+key+' card background explains its planned functionality.');
    await answerDialog();
  }
  for(const selector of ['[data-class-tool=students]','[data-class-tool=activities]','[data-class-tool=grading]','[data-class-tool=gradebook]']){
    check(await evaluate(`(()=>{const card=document.querySelector(${JSON.stringify(selector)}),r=card.getBoundingClientRect(),target=document.elementFromPoint(r.left+10,r.top+10);return target===card.querySelector('.class-lms-tool-link') && !!target.href && target.href.includes('signature=');})()`),'The available '+selector+' card preserves its signed navigation over the entire surface.');
  }
  await evaluate('document.querySelector("[data-class-tool=materials] button").focus()');
  check(await evaluate('document.activeElement===document.querySelector("[data-class-tool=materials] button")'),'The learning materials card is keyboard focusable.');
  await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',unmodifiedText:'\r',windowsVirtualKeyCode:13},session);await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13},session);
  await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  check(await evaluate('Swal.getTitle().textContent==="Learning materials"'),'The planned learning materials card can be opened with the keyboard.');await answerDialog();
  const overview=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','grading-tools_lms.png'),Buffer.from(overview.data,'base64'));
  await resize(900);check(await evaluate('getComputedStyle(document.querySelector(".class-lms-tool-grid")).gridTemplateColumns.split(" ").length===2'),'Tablet overview uses two columns.');
  await resize(390,844);check(await evaluate('getComputedStyle(document.querySelector(".class-lms-tool-grid")).gridTemplateColumns.split(" ").length===1 && document.documentElement.scrollWidth<=innerWidth'),'Phone overview uses one column without overflow.');
  await evaluate('document.querySelector("[data-class-tool=gradebook]").scrollIntoView({block:"center",behavior:"instant"})');
  const mobileTool=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','class-overview-mobile_lms.png'),Buffer.from(mobileTool.data,'base64'));
  await resize(1440);await call('Page.navigate',{url:'http://localhost/synk/professor/class_lms.php?'+new URLSearchParams(config.context)},session);
  await until('document.readyState==="complete" && !document.querySelector("#gradingRetry").hidden');
  check(await evaluate('document.querySelector("#gradingPeriods").hidden && document.querySelector("#gradingLoadNotice").textContent.includes("Temporary")'),'A load error has a retry action and does not expose empty editable forms.');
  await until('!!document.querySelector(".swal2-popup.swal2-icon-error")');
  const errorContent=await evaluate('({text:document.querySelector("#swal2-html-container").textContent,images:[...document.querySelectorAll("#swal2-html-container img,.swal2-title img")].map(node=>node.outerHTML)})');
  check(errorContent.text.includes('<img')&&!errorContent.images.length,'SweetAlert2 displays server errors safely as plain text: '+JSON.stringify(errorContent));
  await answerDialog();
  await click('#gradingRetry');await until('!document.querySelector("#gradingPeriods").hidden');
  check(await evaluate('document.querySelectorAll("[data-component]").length===0 && [...document.querySelectorAll("[data-save]")].every(b=>b.disabled)'),'Unconfigured periods remain empty until the professor adds components.');
  check(await evaluate('[...document.querySelectorAll("[data-grading-period]")].every(form=>{const add=form.querySelector("[data-action=add]"),save=form.querySelector("[data-save]");return add.nextElementSibling===save && save.closest(".grading-primary-actions") && form.querySelectorAll("[data-save]").length===1 && !form.querySelector(".grading-period-footer [data-save]");})'),'Each period has one Save button directly beside Add component above the components.');
  check(await evaluate('!document.querySelector("[data-action=sample]") && !document.querySelector("#gradingWorkspace").textContent.toLowerCase().includes("sample breakdown")'),'The grading workspace offers manual components without sample breakdown controls or instructions.');
  const components={midterm:[['Participation','Attendance and discussion',20],['Written work','Quizzes and exercises',30],['Project submissions','Programming outputs',30],['Term output','Individual project',20]],final:[['Final documentation','Project documentation',30],['Peer review','Peer evaluation',20],['Final presentation','Presentation and demonstration',40],['Reflection','Learning reflection',10]]};
  for(const [period,items] of Object.entries(components)){
    for(const [name,description,weight] of [...items].reverse()){
      await click(selector(period,'[data-action="add"]'));
      await field(period,0,'name',name);await field(period,0,'description',description);await field(period,0,'weight',String(weight));
    }
  }
  check(await evaluate('[...document.querySelectorAll("[data-grading-period=midterm] [data-field=weight]")].map(n=>Number(n.value)).join(",")==="20,30,30,20" && [...document.querySelectorAll("[data-grading-period=final] [data-field=weight]")].map(n=>Number(n.value)).join(",")==="30,20,40,10"'),'Professors can manually create independent balanced component breakdowns.');
  check(records.midterm.version===0&&records.final.version===0&&!requests.some(request=>request.action==='save'),'Reaching 100% does not save either period automatically.');
  await field('midterm',0,'name','');await click(selector('midterm','[data-save]'));await until('document.querySelector(".swal2-title")?.textContent==="Check the required details"');
  check(!requests.some(request=>request.action==='save'),'Required-field validation uses SweetAlert2 before any save request.');
  await answerDialog();await field('midterm',0,'name','Participation');
  await click(selector('final','[data-action="add"]'));
  check(await evaluate('document.querySelector("[data-grading-period=final] [data-component=\\"0\\"] [data-field=name]").value==="" && document.querySelector("[data-grading-period=final] [data-component=\\"1\\"] [data-field=name]").value==="Final documentation" && document.activeElement===document.querySelector("[data-grading-period=final] [data-component=\\"0\\"] [data-field=name]")'),'Final inserts and focuses the new component first, pushing the previous components below.');
  await click(selector('final','[data-component="0"] [data-operation="remove"]'));
  await field('midterm',0,'weight','10');check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-save]").disabled && document.querySelector("[data-grading-period=midterm] [data-balance]").textContent.includes("10%")'),'Underweight totals disable saving and show the deficit.');
  await field('midterm',0,'weight','30');check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-save]").disabled && document.querySelector("[data-grading-period=midterm] [data-balance]").textContent.includes("Reduce")'),'Overweight totals disable saving and show the excess.');
  await field('midterm',0,'weight','20');await click(selector('midterm','[data-action="add"]'));
  check(await evaluate('document.querySelectorAll("[data-grading-period=midterm] [data-component]").length===5 && document.querySelector("[data-grading-period=midterm] [data-save]").disabled'),'New components can be added, and empty weights cannot be saved.');
  check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-component=\\"0\\"] [data-field=name]").value==="" && document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=name]").value==="Participation" && document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=weight]").value==="20" && document.activeElement===document.querySelector("[data-grading-period=midterm] [data-component=\\"0\\"] [data-field=name]")'),'Midterm inserts and focuses the new component first while retaining existing names and edited weights.');
  await field('midterm',0,'name','Coding <img src=x onerror=alert(1)>');await field('midterm',0,'description','Literal <script>window.gradingExecuted=true</script>');await field('midterm',0,'weight','10');await field('midterm',4,'weight','10');
  await click(selector('midterm','[data-component="0"] [data-operation="down"]'));
  check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=name]").value.startsWith("Coding") && !document.querySelector(".grading-component img,.grading-component script") && !window.gradingExecuted'),'Reordering preserves values and renders HTML as text.');
  await click(selector('midterm','[data-component="1"] [data-operation="up"]'));
  await click(selector('midterm','[data-component="4"] [data-operation="remove"]'));await answerDialog(false);
  check(await evaluate('document.querySelectorAll("[data-grading-period=midterm] [data-component]").length===5'),'Cancel keeps a component and its values intact.');
  await click(selector('midterm','[data-component="4"] [data-operation="remove"]'));await answerDialog();await field('midterm',0,'weight','20');
  await click(selector('midterm','[data-save]'));await until('document.querySelector("[data-grading-period=midterm] [data-save-state]").textContent.includes("version 1")');
  check(records.midterm.components.length===4&&records.midterm.components[0].name==='Coding <img src=x onerror=alert(1)>'&&records.midterm.components[0].weight===20&&records.final.version===0,'Saving Midterm records the new component first without saving Final.');
  check(await evaluate('document.querySelector(".swal2-toast .swal2-title")?.textContent.includes("Midterm grading system saved")'),'Saving shows a SweetAlert2 success toast.');
  check(await evaluate('document.querySelector("[data-grading-period=final] [data-save-state]").textContent==="Unsaved changes" && !document.querySelector("[data-grading-period=final] [data-save]").disabled'),'Saving one period preserves unsaved work in the other.');
  const savedIds=records.midterm.components.map(item=>item.component_id);await field('midterm',1,'name','Updated participation');
  await click('.class-lms-tabs a[href*="view=assessments"]');await answerDialog(false);
  check(await evaluate('location.search.includes("view=grading") && document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=name]").value==="Updated participation"'),'Canceling navigation preserves the page and its unsaved edits.');
  conflict=true;await click(selector('midterm','[data-save]'));
  await until('document.querySelector("#swal2-html-container")?.textContent.includes("another tab")');
  check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=name]").value==="Updated participation" && !document.querySelector("[data-grading-period=midterm] [data-save]").disabled'),'Stale saves keep edited values and restore usable controls.');
  await answerDialog();
  await click(selector('midterm','[data-action="reload"]'));await answerDialog(false);
  check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=name]").value==="Updated participation"'),'Canceling Reload period keeps the unsaved component changes.');
  await click(selector('midterm','[data-action="reload"]'));await answerDialog();await until('document.querySelector("[data-grading-period=midterm] [data-save-state]").textContent.includes("version 1")');
  check(await evaluate('document.querySelector("[data-grading-period=midterm] [data-component=\\"0\\"] [data-field=name]").value.startsWith("Coding") && document.querySelector("[data-grading-period=midterm] [data-component=\\"1\\"] [data-field=name]").value==="Participation" && document.querySelector("[data-grading-period=final] [data-save-state]").textContent==="Unsaved changes"'),'Reload period preserves the saved order and leaves the other draft unchanged.');
  await field('midterm',1,'name','Updated participation');await click(selector('midterm','[data-save]'));await until('document.querySelector("[data-grading-period=midterm] [data-save-state]").textContent.includes("version 2")');
  check(records.midterm.components.map(item=>item.component_id).join(',')===savedIds.join(','),'Subsequent edits send the saved stable component IDs.');
  await click(selector('final','[data-save]'));await until('document.querySelector("[data-grading-period=final] [data-save-state]").textContent.includes("version 1")');
  check(records.final.components.map(item=>item.weight).join(',')==='30,20,40,10','Final saves its independent component weights.');
  await evaluate('document.querySelector("#gradingWorkspace").scrollIntoView({block:"start",behavior:"instant"})');
  const desktop=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','grading-desktop_lms.png'),Buffer.from(desktop.data,'base64'));
  await resize(390,844);await evaluate('document.querySelector("[data-grading-period=midterm]").scrollIntoView({block:"start",behavior:"instant"})');
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth && getComputedStyle(document.querySelector(".grading-period-grid")).gridTemplateColumns.split(" ").length===1 && document.querySelector(".grading-component [data-field=name]").getBoundingClientRect().width>140'),'Mobile grading forms fit a single column and retain usable component fields.');
  for(const width of [390,320]){
    await resize(width,844);
    const layout=await evaluate('({viewport:innerWidth,width:document.documentElement.scrollWidth,periods:[...document.querySelectorAll("[data-grading-period]")].map(form=>({add:form.querySelector("[data-action=add]").getBoundingClientRect().toJSON(),save:form.querySelector("[data-save]").getBoundingClientRect().toJSON()}))})');
    check(layout.width<=layout.viewport&&layout.periods.every(({add:a,save:s})=>Math.abs(a.top-s.top)<1&&s.left>=a.right&&s.right<=layout.viewport&&a.height>=44&&s.height>=44),'Add and Save stay beside each other without mobile overflow at '+width+'px: '+JSON.stringify(layout));
  }
  await resize(390,844);
  await click(selector('midterm','[data-component="0"] [data-operation="remove"]'));await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  await until('getComputedStyle(Swal.getPopup()).opacity==="1" && Swal.getPopup().getAnimations({subtree:true}).every(animation=>animation.playState==="finished")');
  const popupStyle=await evaluate('({background:getComputedStyle(Swal.getPopup()).backgroundColor,opacity:getComputedStyle(Swal.getPopup()).opacity})');
  check(popupStyle.background==='rgb(255, 255, 255)'&&popupStyle.opacity==='1','The SweetAlert2 popup has an opaque readable background: '+JSON.stringify(popupStyle));
  check(await evaluate('(()=>{const r=Swal.getPopup().getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && r.top>=0 && r.bottom<=innerHeight;})()'),'The SweetAlert2 confirmation fits on mobile.');
  const confirmation=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','lms-confirmation-mobile.png'),Buffer.from(confirmation.data,'base64'));
  await answerDialog(false);
  const mobile=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','grading-mobile_lms.png'),Buffer.from(mobile.data,'base64'));
  check(!errors.length,'No browser errors: '+errors.join('\n'));check(!failures.length,'Local grading assets load: '+failures.join('\n'));
  console.log(`LMS grading browser: ${checks} checks passed, including manual components, responsive cards, weights, persistence, revisions, and independent period edits.`);
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;}).finally(async()=>{
  if(call&&ws?.readyState===WebSocket.OPEN){try{await call('Browser.close');}catch{}}if(ws)ws.close();
  if(browser&&browser.exitCode===null){await delay(400);if(browser.exitCode===null)browser.kill();}
  for(const item of pending.values())clearTimeout(item.timer);if(fs.existsSync(route))fs.unlinkSync(route);
  const resolved=path.resolve(profile),allowed=path.resolve(root,'.tmp')+path.sep;
  if(!resolved.startsWith(allowed)||path.basename(resolved)!==`grading-browser-${tag}_lms`)throw Error('Unexpected browser profile path.');
  if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
});
