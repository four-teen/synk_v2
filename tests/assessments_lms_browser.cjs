// node tests/assessments_lms_browser.cjs. Real PHP page/assets; API responses are controlled for UI checks.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..'), tag = crypto.randomBytes(10).toString('hex'), token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `assessment-browser-${tag}_lms.php`), profile = path.join(root, '.tmp', `assessment-browser-${tag}_lms`);
const pending = new Map(), errors = [], failures = [], requests = [];
let browser, ws, call, session, pageConfig, checks = 0, nextId = 100, conflict = false, duplicateFailure = false;
const records = [], submissionRows = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };
const clone = value => JSON.parse(JSON.stringify(value));
const phpString = value => "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
async function evaluate(expression) {
  const response = await call('Runtime.evaluate', { expression, returnByValue:true, awaitPromise:true }, session);
  if (response.exceptionDetails) throw Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
  return response.result.value;
}
async function until(expression) {
  for (let attempt=0; attempt<150; attempt++) {
    try { if (await evaluate(expression)) return; } catch {}
    await delay(100);
  }
  throw Error('Browser condition timed out: ' + expression);
}
async function click(selector) { await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`); }
async function answerDialog(confirmed = true) {
  await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  await click(confirmed ? '.swal2-confirm' : '.swal2-cancel');
  await until('!document.querySelector(".swal2-popup:not(.swal2-toast)") && !synkLmsFeedback.isPending()');
}
async function fill(selector, value, type = 'input') {
  await evaluate(`(() => { const input=document.querySelector(${JSON.stringify(selector)});input.value=${JSON.stringify(value)};input.dispatchEvent(new Event(${JSON.stringify(type)},{bubbles:true})); })()`);
}
function api(request) {
  requests.push(request);
  check(JSON.stringify(request.context) === JSON.stringify(pageConfig.context) && request.csrf_token === pageConfig.csrf_token,
    'The editor carries its signed class context and CSRF token for every request.');
  const payload = request.payload || {}, found = records.find(item => item.assessment_id === payload.assessment_id);
  if (request.action === 'list') return { ok:true, assessments:records.filter(item=>item.status!=='archived').map(item=>({ ...clone(item), question_count:item.questions.length, student_count:28, answered_count:item.status==='draft'?0:12 })) };
  if (request.action === 'detail') return { ok:true, assessment:clone(found) };
  if (request.action === 'save' || request.action === 'publish') {
    if (conflict) { conflict=false; return { status:409, ok:false, message:'This draft changed in another tab. Reopen it before saving.' }; }
    const record = { ...clone(payload), assessment_id:payload.assessment_id || ++nextId, version:found ? found.version+1 : 1,
      status:request.action==='publish'?'published':found?.status||'draft', total_points:payload.assessment_type==='assignment' ? Number(payload.total_points) : payload.questions.reduce((sum,q)=>sum+Number(q.points),0), updated_at:'2026-11-10 12:00:00' };
    record.questions.forEach(item=>{ item.points=Number(item.points); });
    if (found) records.splice(records.indexOf(found),1,record); else records.push(record);
    return { ok:true, assessment:clone(record) };
  }
  if (request.action === 'duplicate') {
    if (duplicateFailure) { duplicateFailure=false; return { status:409,ok:false,message:'This draft changed. Refresh before duplicating it.' }; }
    const record = { ...clone(found), assessment_id:++nextId, version:1, grading_period:payload.grading_period,
      title:'Copy of ' + found.title, opens_at:null, due_at:null, status:'draft' };
    records.push(record); return { ok:true, assessment:clone(record) };
  }
  if (request.action === 'archive') { found.status='archived';found.version++;return { ok:true }; }
  if (request.action === 'close') { found.status='closed';found.version++;return { ok:true,assessment:clone(found) }; }
  if (request.action === 'submissions') return {ok:true,submissions:clone(submissionRows)};
  throw Error('Unexpected assessment action: ' + request.action);
}
async function main() {
  fs.writeFileSync(route, `<?php define('SYNK_PROFESSOR_LMS_QA','${token}'); require ${phpString(path.join(root,'tests/fixtures/professor-class-request_lms.php'))};`);
  const response = await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&case=assessments`);
  const html = await response.text();
  if (response.status!==200 || /Parse error|Fatal error|xdebug-error/i.test(html)) throw Error('The actual assessment page failed to render: ' + html.slice(0,300));
  const match=html.match(/<script id="assessmentConfig" type="application\/json">(.*?)<\/script>/s);
  if (!match) throw Error('Assessment page configuration is missing.');pageConfig=JSON.parse(match[1]);
  const executable = [process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe',process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  if (!executable) throw Error('Edge or Chrome is required.');
  browser=spawn(executable,['--headless=new','--disable-gpu','--disable-extensions','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
  const socket=await new Promise((resolve,reject)=>{let log='';const timer=setTimeout(()=>reject(Error('Browser startup timed out.')),15000);browser.stderr.on('data',data=>{log+=data;const match=log.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timer);resolve(match[1]);}});browser.on('error',reject);});
  ws=new WebSocket(socket);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
  let sequence=0;
  call=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>{pending.delete(id);reject(Error('Browser timeout: '+method));},15000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
  ws.addEventListener('message',event=>{
    const message=JSON.parse(event.data);
    if(pending.has(message.id)){const item=pending.get(message.id);pending.delete(message.id);clearTimeout(item.timer);message.error?item.reject(Error(message.error.message)):item.resolve(message.result);}
    if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);
    if(message.method==='Network.responseReceived'&&message.params.response.status>=400&&message.params.response.url.startsWith('http://localhost/')&&!message.params.response.url.includes('assessments_api_lms.php'))failures.push(message.params.response.url);
    if(message.method==='Page.javascriptDialogOpening'){errors.push('Unexpected native dialog: '+message.params.type);call('Page.handleJavaScriptDialog',{accept:true},session).catch(error=>errors.push(error.message));}
    if(message.method==='Fetch.requestPaused')(async()=>{
      const { requestId,request }=message.params,isApi=request.url.includes('assessments_api_lms.php');
      const data=isApi?api(JSON.parse(request.postData)):html;
      const status=isApi?(data.status||200):200;if(isApi)delete data.status;
      await call('Fetch.fulfillRequest',{requestId,responseCode:status,responseHeaders:[{name:'Content-Type',value:isApi?'application/json':'text/html; charset=utf-8'}],body:Buffer.from(isApi?JSON.stringify(data):data).toString('base64')},session);
    })().catch(error=>errors.push(error.stack));
  });
  const target=await call('Target.createTarget',{url:'about:blank'});session=(await call('Target.attachToTarget',{targetId:target.targetId,flatten:true})).sessionId;
  await call('Page.enable',{},session);await call('Runtime.enable',{},session);await call('Network.enable',{},session);
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
  await call('Fetch.enable',{patterns:[{urlPattern:'http://localhost/synk/professor/class_lms.php*',resourceType:'Document'},{urlPattern:'http://localhost/synk/professor/assessments_api_lms.php'}]},session);
  await call('Page.navigate',{url:'http://localhost/synk/professor/class_lms.php?'+new URLSearchParams(pageConfig.context)},session);
  await until('document.readyState==="complete" && document.querySelector("#assessmentList").getAttribute("aria-busy")==="false" && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelector("#assessmentList").innerText.includes("No Midterm assessments")'),'The first visit displays an honest empty Midterm state.');
  check(await evaluate('(()=>{const m=document.querySelector("[data-period=midterm]"),f=document.querySelector("[data-period=final]");return getComputedStyle(m).backgroundColor!==getComputedStyle(f).backgroundColor && getComputedStyle(m).getPropertyValue("--period-color")!==getComputedStyle(f).getPropertyValue("--period-color") && m.getAttribute("aria-pressed")==="true";})()'),'Midterm and Final have distinct colors and an accessible selected state.');
  check(await evaluate('document.querySelector("#assessmentFloatingActions").hidden'),'The draft library has no floating save bar.');
  await click('[data-period="final"]');await click('#assessmentNew');
  check(await evaluate('document.querySelector("[data-period=final]").getAttribute("aria-pressed")==="true" && document.querySelector("[data-period=midterm]").getAttribute("aria-pressed")==="false"'),'Changing periods moves the selected state to Final.');
  check(await evaluate('document.querySelector("#assessmentPeriod").value==="final"'),'A new draft inherits the selected grading period.');
  check(await evaluate('!document.querySelector("#assessmentFloatingActions").hidden && getComputedStyle(document.querySelector("#assessmentFloatingActions")).position==="fixed" && document.querySelector(".assessment-editor-heading #assessmentSave") && document.querySelector(".assessment-editor-heading #assessmentPreview")'),'New drafts expose bottom actions while preserving both top buttons.');
  check(await evaluate('document.querySelector("#assessmentSave").nextElementSibling.id==="assessmentPublish" && document.querySelector("#assessmentSaveBottom").nextElementSibling.id==="assessmentPublishBottom" && !document.querySelector("#assessmentNewEditor,#assessmentNewBottom")'),'Publish replaces the mistakenly added New draft buttons in both action bars.');
  check(await evaluate('document.querySelector("#assessmentAddQuestion").nextElementSibling.id==="assessmentPreview" && document.querySelector("#assessmentAddQuestionBottom").nextElementSibling.id==="assessmentPreviewBottom" && !document.querySelector("#assessmentQuestionsEmpty").hidden && document.querySelector("#assessmentQuestionsEmpty").textContent.includes("No questions yet.")'),'Both action bars place Add question before Preview, and an empty question list explains how to start.');
  await click('#assessmentAddQuestionBottom');check(await evaluate('document.querySelectorAll("[data-question]").length===1&&document.querySelector("#assessmentQuestionsEmpty").hidden'),'The floating Add question button creates a question and hides the empty prompt.');await click('[data-question="0"] [data-operation="remove"]');check(await evaluate('!document.querySelector("#assessmentQuestionsEmpty").hidden'),'Removing the last blank question restores the prompt.');
  for(const width of [390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true},session);await delay(150);
    check(await evaluate('[...document.querySelectorAll(".assessment-editor-heading .assessment-actions,.assessment-floating-actions .assessment-actions")].every(bar=>{const buttons=[...bar.querySelectorAll("button:not([hidden])")].map(button=>button.getBoundingClientRect());return buttons.length===4&&Math.abs(buttons[0].top-buttons[1].top)<1&&Math.abs(buttons[2].top-buttons[3].top)<1&&buttons[2].top>buttons[0].top&&buttons.every(r=>r.width>100&&r.height>=44&&r.left>=0&&r.right<=innerWidth);})&&document.documentElement.scrollWidth<=innerWidth'),'The four quiz actions form two usable rows on '+width+'px mobile screens.');
    if(width===390){await evaluate('document.querySelector(".assessment-editor-heading").scrollIntoView({block:"start",behavior:"instant"})');fs.writeFileSync(path.join(root,'.tmp','assessment-quiz-actions-mobile_lms.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session)).data,'base64'));}
  }
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
  await fill('#assessmentTitle','Quiz <img src=x onerror=alert(1)>');
  await fill('#assessmentTitle','');await click('#assessmentSave');await until('document.querySelector(".swal2-title")?.textContent==="Check the required details"');
  check(!requests.some(request=>request.action==='save'),'Assessment required-field validation uses SweetAlert2 before saving.');
  await answerDialog();await fill('#assessmentTitle','Quiz <img src=x onerror=alert(1)>');
  await fill('#assessmentInstructions','Read each item.\nUse the concepts from our class.');
  await fill('#assessmentTopic','Variables');
  await fill('#assessmentTimeLimit','30');
  await click('#assessmentAddQuestion');
  check(await evaluate('!!document.querySelector(".assessment-editor-heading #assessmentAddQuestion")&&!document.querySelector("#assessmentQuestionsSection #assessmentAddQuestion")&&document.querySelector("#assessmentQuestionsEmpty").hidden'),'Add question lives in the action bar and the prompt hides when questions exist.');
  await fill('[data-question="0"] [data-field="prompt"]','What is 2 + 2?');await fill('[data-question="0"] [data-field="points"]','2.5');
  await evaluate('document.querySelector("[data-question=\\"0\\"] [data-field=\\"prompt\\"]").setSelectionRange(8,13)');
  await click('[data-question="0"] [data-format="inline"]');
  check(await evaluate('document.querySelector("[data-question=\\"0\\"] .assessment-inline-code").textContent==="2 + 2"'),'Inline code wraps the selected question text and updates the live preview.');
  const codeSample='<?php\n    echo "<script>window.assessmentCodeExecuted = true;</script>";\n    // ``` stays inside this example\n    $label = "'+'Programming '.repeat(20)+'";\n?>';
  const codePrompt='What is `2 + 2`? <img src=x onerror=alert(1)>\n'+codeSample;
  await fill('[data-question="0"] [data-field="prompt"]',codePrompt);
  await evaluate(`document.querySelector('[data-question="0"] [data-field="prompt"]').setSelectionRange(${codePrompt.indexOf('<?php')},${codePrompt.length})`);
  await click('[data-question="0"] [data-format="block"]');
  check(await evaluate(`document.querySelector('[data-question="0"] pre code').textContent===${JSON.stringify(codeSample)} && document.querySelector('[data-question="0"] [data-field="prompt"]').value.includes(${JSON.stringify('\n````\n')})`),'Code blocks preserve indentation and use a longer fence when the code contains backticks.');
  check(await evaluate('!document.querySelector(".assessment-prompt-preview img,.assessment-prompt-preview script") && !window.assessmentCodeExecuted'),'Live question previews escape HTML and never execute code.');
  const formattedPrompt=await evaluate('document.querySelector("[data-question=\\"0\\"] [data-field=\\"prompt\\"]").value');
  await fill('[data-question="0"] [data-field="prompt"]',formattedPrompt.replace('\n````\n','\n````php\n'));
  check(await evaluate('document.querySelector("[data-question=\\"0\\"] figcaption").textContent==="php"'),'Pasted code fences support an optional language label.');
  await fill('[data-question="0"] [data-field="prompt"]','```python\n    print("still typing")');
  check(await evaluate('document.querySelector("[data-question=\\"0\\"] pre code").textContent===\'    print("still typing")\''),'An unfinished code fence stays readable while a question is being written.');
  await fill('[data-question="0"] [data-field="prompt"]','x'.repeat(10000));
  await evaluate('document.querySelector("[data-question=\\"0\\"] [data-field=\\"prompt\\"]").setSelectionRange(0,1)');
  await click('[data-question="0"] [data-format="inline"]');
  await until('document.querySelector("#swal2-html-container")?.textContent.includes("10,000-character")');
  check(await evaluate('document.querySelector("[data-question=\\"0\\"] [data-field=\\"prompt\\"]").value.length===10000'),'Formatting respects the existing question-length limit without dropping text.');
  await answerDialog();
  await fill('[data-question="0"] [data-field="prompt"]',formattedPrompt);
  await evaluate('document.querySelector("[data-question=\\"0\\"]").scrollIntoView({block:"start",behavior:"instant"})');
  const editorShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-code-editor_lms.png'),Buffer.from(editorShot.data,'base64'));
  for(const [index,value] of ['3','4','5','6'].entries())await fill(`[data-question="0"] [data-choice][id$="choice${index}"]`,value);
  await click('[data-question="0"] [data-answer][value="1"]');
  await click('#assessmentAddQuestion');
  check(await evaluate(`document.querySelector('[data-question="0"] [data-field="prompt"]').value==='' && document.querySelector('[data-question="1"] [data-field="prompt"]').value===${JSON.stringify(formattedPrompt)} && document.activeElement===document.querySelector('[data-question="0"] [data-field="prompt"]')`),'A new question is inserted and focused first, preserving the existing formatted prompt.');
  await fill('[data-question="0"] [data-field="type"]','true_false','change');
  await fill('[data-question="0"] [data-field="prompt"]','The expression 2 > 3 is true.');await fill('[data-question="0"] [data-field="true_false"]','false','change');
  await click('#assessmentAddQuestion');await fill('[data-question="0"] [data-field="type"]','short_answer','change');
  await fill('[data-question="0"] [data-field="prompt"]','Name a programming language.');await fill('[data-question="0"] [data-field="accepted"]','PHP\nphp');
  await click('#assessmentAddQuestion');await fill('[data-question="0"] [data-field="type"]','essay','change');
  await fill('[data-question="0"] [data-field="prompt"]','Explain your answer.');await fill('[data-question="0"] [data-field="points"]','5');
  await click('[data-question="0"] [data-operation="down"]');
  check(await evaluate('document.querySelector("[data-question=\\"1\\"] [data-field=\\"type\\"]").value==="essay"'),'Question reordering preserves the selected question type.');
  await click('[data-question="1"] [data-operation="copy"]');
  check(await evaluate('document.querySelectorAll("[data-question]").length===5'),'Questions can be duplicated.');
  await click('[data-question="2"] [data-operation="remove"]');
  await answerDialog(false);
  check(await evaluate('document.querySelectorAll("[data-question]").length===5'),'Canceling question removal preserves every question.');
  await click('[data-question="2"] [data-operation="remove"]');await answerDialog();
  check(await evaluate('document.querySelectorAll("[data-question]").length===4'),'Questions can be removed without dropping the other items.');
  await click('#assessmentPreviewBottom');await until('document.querySelector("#assessmentPreviewModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentPreviewModal"))._isTransitioning');
  check(await evaluate(`document.querySelector('#assessmentPreviewBody pre code').textContent===${JSON.stringify(codeSample)} && document.querySelector('#assessmentPreviewBody .assessment-inline-code').textContent==='2 + 2' && !document.querySelector('#assessmentPreviewBody img,#assessmentPreviewBody script')`),'Bottom Preview renders inline and block code safely in the assessment preview.');
  check(await evaluate('!document.querySelector(".assessment-preview-answer")'),'Answer keys are hidden by default in preview.');
  await click('#assessmentShowAnswers');
  check(await evaluate('document.querySelectorAll(".assessment-preview-answer").length===4 && document.querySelector("#assessmentPreviewBody").innerText.includes("Correct answer: False")'),'Professor preview shows all answer-key types, including False.');
  check(await evaluate('!document.querySelector("#assessmentPreviewModal img") && document.querySelector("#assessmentPreviewTitle").textContent.includes("<img")'),'HTML-like titles render as plain text in preview.');
  await click('#assessmentPreviewModal .btn-close');await until('!document.querySelector("#assessmentPreviewModal").classList.contains("show")');
  await click('#assessmentSaveBottom');await until('document.querySelector("#assessmentSaveState").textContent.includes("version 1") && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(records.length===1&&records[0].grading_period==='final'&&records[0].total_points===9.5&&records[0].questions[2].answer_key===false&&records[0].questions[3].prompt===formattedPrompt,'The editor saves the displayed order, formatted source, answer keys, and correct point total.');
  check(await evaluate('document.querySelector("#assessmentFloatingActions").hidden && !document.querySelector("#assessmentSave").disabled && !document.querySelector("#assessmentPreview").disabled'),'A successful save hides the bottom bar and leaves both top buttons usable.');
  check(await evaluate('document.activeElement===document.querySelector("#assessmentSave")'),'Saving from the bottom bar moves keyboard focus to the retained top Save button.');
  check(await evaluate('document.querySelector(".swal2-toast .swal2-title")?.textContent.includes("Draft saved")'),'Draft saves display a SweetAlert2 success toast.');
  await fill('#assessmentTitle','Edited title');conflict=true;await click('#assessmentSave');
  await until('document.querySelector("#swal2-html-container")?.textContent.includes("another tab")');
  check(await evaluate('document.querySelector("#assessmentTitle").value==="Edited title" && document.querySelector("#assessmentSaveState").textContent==="Unsaved changes"'),'A stale-save error preserves edits and the unsaved state.');
  check(await evaluate('!document.querySelector("#assessmentFloatingActions").hidden && !document.querySelector("#assessmentSaveBottom").disabled'),'Edits restore the bottom bar and a failed save keeps it available.');
  await answerDialog();
  await click('#assessmentSave');await until('document.querySelector("#assessmentSaveState").textContent.includes("version 2")');
  await click('#assessmentBack');
  check(await evaluate('document.querySelector("#assessmentFinalCount").textContent==="1" && !document.querySelector("#assessmentList img")'),'The Final list count updates and titles remain escaped.');
  check(await evaluate('document.querySelector(".assessment-count-answered").textContent.trim().startsWith("0") && document.querySelector(".assessment-count-total").textContent.trim().startsWith("28")'),'Draft cards show zero answered and the actual roster total.');
  await click('[data-action="duplicate"]');await until('document.querySelector("#assessmentDuplicateModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentDuplicateModal"))._isTransitioning');
  await fill('#assessmentDuplicatePeriod','midterm','change');duplicateFailure=true;await click('#assessmentDuplicateForm [type="submit"]');
  await until('document.querySelector("#swal2-html-container")?.textContent.includes("Refresh before duplicating")');
  check(await evaluate('parseInt(getComputedStyle(Swal.getContainer()).zIndex)>parseInt(getComputedStyle(document.querySelector("#assessmentDuplicateModal")).zIndex)'),'SweetAlert2 errors appear above the Bootstrap duplicate window.');
  check(await evaluate('Swal.getPopup().contains(document.activeElement)'),'An alert above a Bootstrap modal receives keyboard focus.');
  await call('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27},session);await call('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27},session);
  await until('!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  check(await evaluate('document.querySelector("#assessmentDuplicateModal").classList.contains("show") && document.querySelector("#assessmentDuplicatePeriod").value==="midterm"'),'Escape dismisses the alert while preserving the open duplicate window and selected period.');
  await click('#assessmentDuplicateForm [type="submit"]');
  await until('document.querySelector("#assessmentTitle").value.startsWith("Copy of") && document.querySelector("#assessmentPeriod").value==="midterm"');
  check(records.length===2&&records[1].grading_period==='midterm'&&records[1].questions.length===4,'Duplicate creates a separate draft in the chosen grading period.');
  await click('#assessmentBack');await click('[data-action="edit"]');
  await until('!document.querySelector("#assessmentEditor").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelectorAll("[data-question]").length===4 && !document.querySelector("#assessmentSave").disabled'),'Saved questions reopen and the editor controls are restored.');
  check(await evaluate(`document.querySelector('#assessmentFloatingActions').hidden && document.querySelector('[data-question="3"] [data-field="prompt"]').value===${JSON.stringify(formattedPrompt)} && document.querySelector('[data-question="3"] pre code').textContent===${JSON.stringify(codeSample)}`),'Saved and duplicated questions reopen with code formatting and no unsaved action bar.');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);await delay(150);
  await evaluate('document.querySelector("[data-question=\\"3\\"] .assessment-prompt-preview").scrollIntoView({block:"center",behavior:"instant"})');
  check(await evaluate('(()=>{const code=document.querySelector("[data-question=\\"3\\"] pre");return document.documentElement.scrollWidth<=innerWidth && code.scrollWidth>code.clientWidth && getComputedStyle(code).overflowX==="auto";})()'),'Long programming lines scroll inside the code block without widening the mobile page.');
  const mobileShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-code-mobile_lms.png'),Buffer.from(mobileShot.data,'base64'));
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
  await fill('#assessmentType','assignment','change');await answerDialog(false);
  check(await evaluate('document.querySelector("#assessmentType").value==="quiz" && document.querySelectorAll("[data-question]").length===4 && document.querySelector("#assessmentFloatingActions").hidden'),'Canceling the assessment type change keeps questions, type, and saved state.');
  await fill('#assessmentType','assignment','change');await answerDialog();
  check(await evaluate('document.querySelector("#assessmentQuestionsSection").hidden && document.querySelectorAll("[data-question]").length===0'),'Confirming the type change removes draft questions and exposes assignment settings.');
  await click('#assessmentBack');await answerDialog(false);
  check(await evaluate('!document.querySelector("#assessmentEditor").hidden && !document.querySelector("#assessmentFloatingActions").hidden'),'Canceling the discard dialog preserves the current assessment edits.');
  await click('#assessmentBack');await answerDialog();await click('[data-action="archive"]');await answerDialog(false);
  check(records[1].status==='draft','Canceling Archive keeps the saved draft active.');
  await click('[data-action="archive"]');await answerDialog();
  await until('document.querySelector("#assessmentMidtermCount").textContent==="0"');
  check(records[1].status==='archived','Archiving removes only the chosen copy from the active list.');
  await click('#assessmentNew');await fill('#assessmentType','assignment','change');await fill('#assessmentTitle','Programming project');await fill('#assessmentPoints','100');
  check(await evaluate('document.querySelector("#assessmentQuestionsSection").hidden && document.querySelector("#assessmentAddQuestion").hidden && document.querySelector("#assessmentAddQuestionBottom").hidden && !document.querySelector("#assessmentPoints").disabled && document.querySelector("#assessmentTimeLimit").disabled'),'Assignment type exposes total points and hides question actions and quiz-only controls.');
  await click('#assessmentSave');await until('document.querySelector("#assessmentSaveState").textContent.includes("version 1")');
  await click('#assessmentBack');await click('[data-action="edit"]');await until('document.querySelector("#assessmentTitle").value==="Programming project" && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('!document.querySelector("#assessmentPoints").disabled && document.querySelector("#assessmentPoints").value==="100"'),'Assignment settings reopen with the correct enabled state.');
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);await delay(150);
  check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector("#assessmentTitle").getBoundingClientRect().width>200'),'The editor fits a mobile viewport and keeps its fields usable.');
  await fill('#assessmentTopic','Mobile edit');
  check(await evaluate('(()=>{const bar=document.querySelector("#assessmentFloatingActions"),r=bar.getBoundingClientRect(),nav=document.querySelector("#professor-bottom-nav").getBoundingClientRect();return !bar.hidden && r.left>=0 && r.right<=innerWidth && r.bottom<=nav.top-8 && r.bottom>nav.top-30 && !document.querySelector("#assessmentSaveBottom").disabled;})()'),'The floating actions stay above the mobile navigation without covering it.');
  for(const width of [390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true},session);await delay(100);
    check(await evaluate('[...document.querySelectorAll(".assessment-editor-heading .assessment-actions,.assessment-floating-actions .assessment-actions")].every(bar=>{const buttons=[...bar.querySelectorAll("button:not([hidden])")].map(button=>button.getBoundingClientRect());return buttons.length===3 && Math.max(...buttons.map(r=>r.top))-Math.min(...buttons.map(r=>r.top))<1 && buttons.every(r=>r.width>60&&r.height>=44&&r.left>=0&&r.right<=innerWidth);}) && document.documentElement.scrollWidth<=innerWidth'),'All three assignment action buttons fit on one row at '+width+'px.');
  }
  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true},session);
  await evaluate('document.querySelector(".assessment-editor-heading").scrollIntoView({block:"start",behavior:"instant"})');
  const actionsShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-draft-actions-mobile_lms.png'),Buffer.from(actionsShot.data,'base64'));
  await click('#assessmentSaveBottom');await until('document.querySelector("#assessmentFloatingActions").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  await fill('#assessmentInstructions','Write your project explanation.');
  await click('#assessmentPublish');await answerDialog(false);
  check(records[2].status==='draft'&&await evaluate('document.querySelector("#assessmentInstructions").value==="Write your project explanation."'),'Canceling Publish preserves the draft and unsaved instructions.');
  await click('#assessmentPublishBottom');await answerDialog();await until('!document.querySelector("#assessmentLibrary").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(records[2].status==='published'&&records[2].instructions==='Write your project explanation.','Publish saves the current contents and exposes the published state.');
  const publishedVersion=records[2].version;
  check(await evaluate('!!document.querySelector("#assessmentList [data-action=edit]") && !!document.querySelector("#assessmentList [data-action=submissions]") && !!document.querySelector("#assessmentList [data-action=close]")'),'Published assessments offer editing, response review and closing.');
  check(await evaluate('document.querySelector(".assessment-count-answered").textContent.trim().startsWith("12") && document.querySelector(".assessment-count-total").textContent.trim().startsWith("28") && getComputedStyle(document.querySelector(".assessment-count-answered")).color==="rgb(21, 128, 61)" && getComputedStyle(document.querySelector(".assessment-count-total")).color==="rgb(185, 28, 28)"'),'Published cards display answered students in green and total students in red.');
  for(const width of [390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true},session);await delay(100);
    await evaluate('document.querySelector(".assessment-list-card").scrollIntoView({block:"center",behavior:"instant"})');
    check(await evaluate('(()=>{const r=document.querySelector(".assessment-participation").getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth && r.left>=0 && r.right<=innerWidth && getComputedStyle(document.querySelector(".assessment-list-summary")).flexDirection==="column";})()'),'The participation count and assessment card fit at '+width+'px.');
  }
  const participationMobile=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-participation-mobile_lms.png'),Buffer.from(participationMobile.data,'base64'));
  await click('[data-action=edit]');await until('!document.querySelector("#assessmentEditor").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelector("#assessmentEditorTitle").textContent==="Edit assessment" && document.querySelector("#assessmentSave").textContent==="Save changes" && document.querySelector("#assessmentPublish").hidden && !document.querySelector("#assessmentLiveNote").hidden'),'The published editor explains original attempts and offers Save changes.');
  await fill('#assessmentOpens','2026-11-15T08:00');await fill('#assessmentDue','2026-11-16T08:00');await fill('#assessmentInstructions','Corrected project instructions.');
  await fill('#assessmentType','quiz','change');await click('#assessmentAddQuestion');await fill('[data-question="0"] [data-field="type"]','true_false','change');
  await fill('[data-question="0"] [data-field="prompt"]','Corrected published question');await fill('[data-question="0"] [data-field="true_false"]','false','change');
  for(const width of [390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true},session);await delay(150);
    check(await evaluate('[...document.querySelectorAll(".assessment-actions.is-published")].every(bar=>{const buttons=[...bar.querySelectorAll("button:not([hidden])")].map(button=>button.getBoundingClientRect());return buttons.length===3 && Math.abs(buttons[0].top-buttons[2].top)<1 && buttons.every(r=>r.height>=44&&r.width>80&&r.right<=innerWidth);}) && document.documentElement.scrollWidth<=innerWidth'),'Published edit actions, including Add question, fit at '+width+'px.');
  }
  await click('#assessmentSaveBottom');await answerDialog(false);
  check(records[2].instructions==='Write your project explanation.'&&await evaluate('document.querySelector("#assessmentInstructions").value==="Corrected project instructions." && !document.querySelector("#assessmentFloatingActions").hidden'),'Canceling Save changes keeps the unsaved correction.');
  await click('#assessmentSaveBottom');await answerDialog();await until(`document.querySelector("#assessmentSaveState").textContent.includes("version ${publishedVersion+1}") && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"`);
  check(records[2].status==='published'&&records[2].opens_at==='2026-11-15T08:00'&&records[2].instructions==='Corrected project instructions.','Saving published edits updates opening dates and instructions without creating a draft.');
  check(records[2].assessment_type==='quiz'&&records[2].questions[0].prompt==='Corrected published question'&&records[2].questions[0].answer_key===false,'Published questions, types and answer keys remain editable through the browser.');
  check(await evaluate('document.querySelector("#assessmentFloatingActions").hidden && document.querySelector("#assessmentPublish").hidden && !document.querySelector("#assessmentSave").disabled'),'Published saves restore usable controls and hide the unsaved bar.');
  await click('#assessmentBack');
  await click('[data-action=submissions]');await until('document.querySelector("#assessmentSubmissionsModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentSubmissionsModal"))._isTransitioning');
  check(await evaluate('document.querySelector("#assessmentSubmissionsBody").textContent.includes("No student attempts yet")'),'The response viewer explains an empty submission list.');
  await click('#assessmentSubmissionsModal .btn-close');await until('!document.querySelector("#assessmentSubmissionsModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentSubmissionsModal"))._isTransitioning');
  submissionRows.push({student_name:'Original version student',status:'submitted',answers:{41:1},assessment:{assessment_type:'quiz',version:1,questions:[{question_id:41,question_type:'multiple_choice',prompt:'Original question before correction',options:['Old choice A','Old choice B']}]}});
  await click('[data-action=submissions]');await until('document.querySelector("#assessmentSubmissionsModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentSubmissionsModal"))._isTransitioning');
  check(await evaluate('document.querySelector("#assessmentSubmissionsBody").textContent.includes("Original question before correction") && document.querySelector("#assessmentSubmissionsBody").textContent.includes("Old choice B") && document.querySelector("#assessmentSubmissionsBody").textContent.includes("version 1")'),'Professor review displays each attempt original question type, choice and version after edits.');
  await click('#assessmentSubmissionsModal .btn-close');await until('!document.querySelector("#assessmentSubmissionsModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentSubmissionsModal"))._isTransitioning');
  await click('[data-action=close]');await answerDialog(false);check(records[2].status==='published','Canceling Close leaves the assessment published.');
  await click('[data-action=close]');await answerDialog();await until('document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');check(records[2].status==='closed','The professor can close a published assessment.');
  await click('[data-action=edit]');await until('!document.querySelector("#assessmentEditor").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelector("#assessmentPublish").textContent==="Reopen" && !document.querySelector("#assessmentPublish").hidden && document.querySelector("#assessmentSave").textContent==="Save changes"'),'Closed assessments remain editable and provide an explicit Reopen action.');
  await fill('#assessmentTopic','Closed assessment correction');await click('#assessmentSave');await answerDialog();await until(`document.querySelector("#assessmentSaveState").textContent.includes("version ${publishedVersion+3}") && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"`);
  check(records[2].status==='closed','Saving edits to a closed assessment keeps it closed.');
  await click('#assessmentPublish');await answerDialog(false);check(records[2].status==='closed','Canceling Reopen keeps the assessment closed.');
  await click('#assessmentPublish');await answerDialog();await until('!document.querySelector("#assessmentLibrary").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(records[2].status==='published','Reopen explicitly publishes the updated assessment for new attempts.');
  await fill('#assessmentSearch','no-such-assessment');
  check(await evaluate('document.querySelector("#assessmentList").innerText.includes("No matching assessments")'),'Search distinguishes no matches from an empty grading period.');
  await fill('#assessmentSearch','');await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
  await evaluate('document.fonts.ready.then(()=>true)');
  const clip=await evaluate('(()=>{const r=document.querySelector("#assessmentWorkspace").getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()');
  const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip},session);fs.writeFileSync(path.join(root,'.tmp','assessment-drafts_lms.png'),Buffer.from(shot.data,'base64'));
  check(!errors.length,'No browser errors: '+errors.join('\n'));check(!failures.length,'Local assessment assets load: '+failures.join('\n'));
  check(requests.some(request=>request.action==='save')&&requests.some(request=>request.action==='archive'),'The editor exercises both save and archive actions.');
  console.log(`LMS assessment browser: ${checks} checks passed, including authoring, preview, revisions, duplication, archiving, and mobile layout.`);
}
main().catch(async error=>{
  console.error(error.stack);
  if(call&&ws?.readyState===WebSocket.OPEN){try{console.error(JSON.stringify(await evaluate('({busy:document.querySelector("#assessmentWorkspace")?.getAttribute("aria-busy"),libraryHidden:document.querySelector("#assessmentLibrary")?.hidden,editorHidden:document.querySelector("#assessmentEditor")?.hidden,dialog:document.querySelector(".swal2-popup:not(.swal2-toast)")?.textContent,submissions:document.querySelector("#assessmentSubmissionsBody")?.textContent,modalClass:document.querySelector("#assessmentSubmissionsModal")?.className})')));}catch{}}
  console.error(JSON.stringify({errors,requests:requests.slice(-5).map(request=>({action:request.action,payload:request.payload}))}));process.exitCode=1;
}).finally(async()=>{
  if(call&&ws?.readyState===WebSocket.OPEN){try{await call('Browser.close');}catch{}}if(ws)ws.close();
  if(browser&&browser.exitCode===null){await delay(400);if(browser.exitCode===null)browser.kill();}
  for(const item of pending.values())clearTimeout(item.timer);if(fs.existsSync(route))fs.unlinkSync(route);
  const resolved=path.resolve(profile),allowed=path.resolve(root,'.tmp')+path.sep;
  if(!resolved.startsWith(allowed)||path.basename(resolved)!==`assessment-browser-${tag}_lms`)throw Error('Unexpected browser profile path.');
  if(fs.existsSync(resolved))fs.rmSync(resolved,{recursive:true,force:true,maxRetries:5,retryDelay:200});
});
