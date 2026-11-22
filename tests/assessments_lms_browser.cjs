// node tests/assessments_lms_browser.cjs. Real PHP page/assets; API responses are controlled for UI checks.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..'), tag = crypto.randomBytes(10).toString('hex'), token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `assessment-browser-${tag}_lms.php`), profile = path.join(root, '.tmp', `assessment-browser-${tag}_lms`);
const pending = new Map(), errors = [], failures = [], requests = [];
let browser, ws, call, session, pageConfig, checks = 0, nextId = 100, conflict = false, duplicateFailure = false, followupEmpty=false, followupFailure=false;
let bulkSaveGate = null;
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
async function click(selector) {
  await evaluate(`(() => { const button=document.querySelector(${JSON.stringify(selector)}),menu=button.closest('.assessment-card-menu[hidden]');if(menu)menu.previousElementSibling.click();button.click(); })()`);
}
async function mobileCardActions(status) {
  const viewport=await evaluate('({width:innerWidth,height:innerHeight})');
  const expected=status==='draft'?['transfer','duplicate','archive']:status==='published'?['preview','transfer','duplicate','close']:['preview','transfer','duplicate'];
  for(const width of [767,575,390,320]) {
    await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600},session);await delay(100);
    await until('!!document.querySelector(".assessment-mobile-actions")');
    await evaluate('document.querySelector(".assessment-list-card").scrollIntoView({block:"center",behavior:"instant"});if(document.querySelector(".swal2-toast"))Swal.close();');
    const layout=await evaluate('(()=>{const toolbar=document.querySelector(".assessment-mobile-actions"),buttons=[...toolbar.querySelectorAll(".assessment-card-action")],rects=buttons.map(button=>button.getBoundingClientRect()),card=toolbar.closest("article").getBoundingClientRect();return {labels:buttons.map(button=>button.textContent.trim()),sameRow:rects.every(r=>Math.abs(r.top-rects[0].top)<1),equalWidths:rects.every(r=>Math.abs(r.width-rects[0].width)<1),touchSized:rects.every(r=>r.width>=44&&r.height>=44),fullWidth:toolbar.getBoundingClientRect().width>card.width-50,fits:document.documentElement.scrollWidth<=innerWidth,icons:buttons.every(button=>!!button.querySelector(".bx")&&!!button.getAttribute("aria-label"))};})()');
    check(JSON.stringify(layout.labels)===JSON.stringify(['Edit','Schedule',status==='draft'?'Preview':'Responses','More'])&&layout.sameRow&&layout.equalWidths&&layout.touchSized&&layout.fullWidth&&layout.fits&&layout.icons,'Compact '+status+' actions use one full-width labeled row at '+width+'px: '+JSON.stringify(layout));
    await click('[data-assessment-more]');
    const menu=await evaluate('(()=>{const more=document.querySelector("[data-assessment-more]"),menu=document.getElementById(more.getAttribute("aria-controls")),r=menu.getBoundingClientRect();return {actions:[...menu.querySelectorAll("button")].map(button=>button.dataset.action),expanded:more.getAttribute("aria-expanded"),visible:!menu.hidden,fits:r.left>=0&&r.right<=innerWidth,focused:document.activeElement===menu.querySelector("button"),danger:!menu.querySelector(".is-danger")||getComputedStyle(menu.querySelector(".is-danger")).color==="rgb(181, 57, 62)"};})()');
    check(JSON.stringify(menu.actions)===JSON.stringify(expected)&&menu.expanded==='true'&&menu.visible&&menu.fits&&menu.focused&&menu.danger,'The '+status+' More menu exposes the correct actions and fits '+width+'px.');
    await evaluate('document.activeElement.dispatchEvent(new KeyboardEvent("keydown",{key:"End",bubbles:true,cancelable:true}))');
    check(await evaluate('document.activeElement===document.querySelector(".assessment-card-menu:not([hidden]) button:last-child")'),'End moves to the final menu action.');
    await evaluate('document.activeElement.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true,cancelable:true}))');
    check(await evaluate('document.querySelector(".assessment-card-menu").hidden&&document.activeElement.hasAttribute("data-assessment-more")&&document.activeElement.getAttribute("aria-expanded")==="false"'),'Escape closes More and restores focus.');
    await click('[data-assessment-more]');await click('#assessmentSearch');
    check(await evaluate('document.querySelector(".assessment-card-menu").hidden'),'Tapping outside dismisses the card menu.');
    if(status==='published'&&(width===390||width===320)) {
      const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-compact-actions-'+width+'_lms.png'),Buffer.from(shot.data,'base64'));
      await click('[data-assessment-more]');
      const menuShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-compact-menu-'+width+'_lms.png'),Buffer.from(menuShot.data,'base64'));
      await click('#assessmentSearch');
    }
  }
  await click('[data-assessment-more]');
  await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);await delay(100);
  check(await evaluate('!document.querySelector(".assessment-mobile-actions,.assessment-card-menu")&&!!document.querySelector(".assessment-list-actions")&&!document.querySelector(".assessment-list-actions .bx")'),'Resizing to desktop restores the original text controls without retaining a popup.');
  await call('Emulation.setDeviceMetricsOverride',{...viewport,deviceScaleFactor:1,mobile:viewport.width<600},session);await delay(100);
}
async function answerDialog(confirmed = true) {
  await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  await click(confirmed ? '.swal2-confirm' : '.swal2-cancel');
  await until('!document.querySelector(".swal2-popup:not(.swal2-toast)") && !synkLmsFeedback.isPending()');
}
async function fill(selector, value, type = 'input') {
  await evaluate(`(() => { const input=document.querySelector(${JSON.stringify(selector)});input.value=${JSON.stringify(value)};input.dispatchEvent(new Event(${JSON.stringify(type)},{bubbles:true})); })()`);
}
async function pasteText(selector, text) {
  return evaluate(`(() => {
    const input = document.querySelector(${JSON.stringify(selector)});
    const text = ${JSON.stringify(text)};
    const clipboard = new DataTransfer();
    clipboard.setData('text/plain', text);
    input.focus();
    input.select();
    const useNativePaste = input.dispatchEvent(new ClipboardEvent('paste', { clipboardData:clipboard, bubbles:true, cancelable:true }));
    // Synthetic clipboard events run the handler but do not insert text by default.
    if (useNativePaste) {
      input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end');
      input.dispatchEvent(new InputEvent('input', { bubbles:true, inputType:'insertFromPaste' }));
    }
    return !useNativePaste;
  })()`);
}
async function checkQuestionPaste() {
  const card = '[data-question="0"]';
  const prompt = card + ' [data-field="prompt"]';
  const sample = [
    '### 1. What is CEDAW primarily concerned with?', '',
    'A. Computer education standards\\', 'B. Eliminating discrimination against women\\',
    'C. Internet regulation\\', 'D. University budgeting &#x20;', '',
    '**Answer: B. Eliminating discrimination against women**'
  ].join('\r\n');
  const snapshot = () => evaluate(`(() => {
    const card = document.querySelector(${JSON.stringify(card)});
    return { prompt:card.querySelector('[data-field=prompt]').value, points:card.querySelector('[data-field=points]').value,
      choices:[...card.querySelectorAll('[data-choice]')].map(field => field.value), answer:card.querySelector('[data-answer]:checked')?.value,
      feedback:card.querySelector('[data-paste-status]').textContent };
  })()`);
  const visibleAnswerLabels = () => evaluate(`[...document.querySelectorAll('${card} .assessment-choice-correct')]
    .filter(label => label.getClientRects().length)
    .map(label => label.closest('.assessment-choice').querySelector('[data-answer]').value)`);
  await click('#assessmentAddQuestion');
  await fill(prompt, 'Keep this other question.');
  await click('#assessmentAddQuestion');
  const blankChoiceColor = await evaluate(`getComputedStyle(document.querySelector('${card} [data-choice]')).backgroundColor`);
  await fill(card + ' [data-field="points"]', '2.5');
  check(await pasteText(prompt, sample), 'The CEDAW template fills the selected multiple choice card on paste.');
  const original = await snapshot();
  check(original.prompt === 'What is CEDAW primarily concerned with?' && original.points === '2.5' && original.answer === '1'
    && JSON.stringify(original.choices) === JSON.stringify(['Computer education standards','Eliminating discrimination against women','Internet regulation','University budgeting'])
    && original.feedback.includes('Choice B selected'), 'The paste strips template markers, fills all choices, and selects B without replacing the points.');
  check(await evaluate('document.querySelectorAll("[data-question]").length===2 && document.querySelector(\'[data-question="1"] [data-field=prompt]\').value==="Keep this other question."'),'Import affects exactly one existing question card.');
  check(await evaluate(`getComputedStyle(document.querySelector('${card} [data-choice]')).backgroundColor !== ${JSON.stringify(blankChoiceColor)}`),
    'The choice field uses a distinct background while it is empty.');
  check(JSON.stringify(await visibleAnswerLabels()) === '["1"]', 'The correct-answer label appears on the pasted answer only.');
  await evaluate(`document.querySelector('${card}').scrollIntoView({block:'start',behavior:'instant'})`);
  const editorShot = await call('Page.captureScreenshot', {format:'png',captureBeyondViewport:false}, session);
  fs.writeFileSync(path.join(root,'.tmp','assessment-question-editor_lms.png'), Buffer.from(editorShot.data,'base64'));
  for (const width of [390,320]) {
    await call('Emulation.setDeviceMetricsOverride', {width,height:844,deviceScaleFactor:1,mobile:true}, session);
    check(await evaluate(`(() => {
      const buttons = [...document.querySelectorAll('${card} .assessment-question-controls button')];
      const rects = buttons.map(button => button.getBoundingClientRect());
      return buttons.map(button => button.textContent.trim()).join(' ') === 'Up Down Copy Delete'
        && rects.every(rect => rect.width >= 44 && rect.height >= 44 && Math.abs(rect.top - rects[0].top) < 1
          && rect.left >= 0 && rect.right <= innerWidth)
        && document.documentElement.scrollWidth <= innerWidth;
    })()`), 'The four labeled question actions stay in one touch-friendly row at ' + width + 'px.');
    check(await evaluate(`(() => {
      const selected = document.querySelector('${card} .assessment-choice:has([data-answer]:checked)');
      const field = selected.querySelector('[data-choice]').getBoundingClientRect();
      const label = selected.querySelector('.assessment-choice-correct').getBoundingClientRect();
      const choice = selected.getBoundingClientRect();
      return label.top >= field.bottom && label.left >= choice.left && label.right <= choice.right;
    })()`), 'The selected answer label wraps beneath the choice field at ' + width + 'px.');
    if (width === 390) {
      await evaluate(`document.querySelector('${card} .assessment-answer-key').scrollIntoView({block:'start',behavior:'instant'})`);
      const mobileShot = await call('Page.captureScreenshot', {format:'png',captureBeyondViewport:false}, session);
      fs.writeFileSync(path.join(root,'.tmp','assessment-question-editor-mobile_lms.png'), Buffer.from(mobileShot.data,'base64'));
    }
  }
  await call('Emulation.setDeviceMetricsOverride', {width:1440,height:1100,deviceScaleFactor:1,mobile:false}, session);

  const replacement = 'Question 2: Pick an output.\n* First result\n* Second result\nCorrect answer: Second result';
  await pasteText(prompt, replacement);
  await until('document.querySelector(".swal2-title")?.textContent==="Replace this question?"');
  await answerDialog(false);
  check(JSON.stringify(await snapshot()) === JSON.stringify(original), 'Canceling a smart replacement keeps the question, choices and key.');
  await pasteText(prompt, replacement);
  await answerDialog();
  const replaced = await snapshot();
  check(replaced.prompt === 'Pick an output.' && replaced.choices.length === 2 && replaced.answer === '1' && replaced.points === '2.5',
    'Bullet choices and a full-text answer can replace a filled card after confirmation.');

  for (const [text, hint] of [
    ['Which fruit?\nA) Apple\nB) Banana\nAnswer: B. Apple', 'correct answer is unclear'],
    ['Which fruit?\nA) Apple\nB) Banana', 'Add an answer'],
    [replacement + '\n\n' + replacement, 'one question at a time']
  ]) {
    check(!await pasteText(prompt, text), 'Incomplete, conflicting or multi-question pastes are kept as ordinary text.');
    const after = await snapshot();
    check(after.prompt === text && after.feedback.includes(hint) && after.answer === '1' && JSON.stringify(after.choices) === JSON.stringify(replaced.choices),
      'The card explains the ambiguity and leaves the choice fields alone.');
  }

  const codeOnly = ['```text','A. Literal code','B. More code','Answer: B','```'].join('\n');
  check(!await pasteText(prompt, codeOnly) && (await snapshot()).feedback === '', 'A plain fenced example never triggers question import.');
  await evaluate(`(() => {
    const question = document.querySelector('${card}');
    question.querySelector('[data-answer]:checked').checked = false;
    question.querySelector('[data-answer]').reportValidity();
  })()`);
  await until('document.querySelector("#swal2-html-container")?.textContent.startsWith("Correct answer:")');
  await answerDialog();
  await click(card + ' .assessment-choice + .assessment-choice .assessment-choice-picker');
  check(await evaluate(`!document.querySelector('${card} [data-answer].is-invalid')`),
    'Selecting any answer clears the radio group validation error.');
  await click(card + ' .assessment-choice-picker');
  check(JSON.stringify(await visibleAnswerLabels()) === '["0"]', 'Clicking the choice letter moves the correct-answer label to that choice.');
  await fill(card + ' [data-field="type"]', 'essay', 'change');
  check(!await pasteText(prompt, sample) && await evaluate('!document.querySelector(\'[data-question="0"] [data-paste-status]\')'), 'Pasting remains ordinary text for essay questions.');
  for (let index = 0; index < 2; index++) {
    await click(card + ' [data-operation="remove"]');
    await answerDialog();
  }
  check(await evaluate('document.querySelectorAll("[data-question]").length===0'), 'The imported and manual question cards still use the normal editor controls.');
}
async function checkBulkQuestions() {
  const quizItems = Array.from({length:25}, (_, index) => {
    const n = index + 1;
    const stem = n === 3 ? ['3. Which value matches the example?', '```text', '  99. A numbered line inside code', '  Answer: source text', '```'].join('\n')
      : n + '. Select the result for example ' + n + '.';
    const choices = ['A. A first choice', 'B. A second choice.', 'C. A third choice', 'D. A fourth choice'];
    const answer = n === 7 ? 'Answer: B. A first choice' : n === 8 ? '' : 'Answer: B. A second choice';
    const note = n === 10 ? 'Questions 1–10 come from the first lesson.' : n === 21 ? 'This explanation belongs with the source, after the answer.' : '';
    return [stem, ...choices, answer, note].filter(Boolean).join('\n');
  });
  const text = ['Bulk import QA', 'Instructions: Choose one answer.', '', 'Number of Items: 25', 'Suggested Points: 1 point each', 'Total: 25 points', '', ...quizItems].join('\n');
  const initialRequests = requests.length;
  await click('#assessmentNew');
  await click('#assessmentAddQuestion');
  await fill('[data-question="0"] [data-field=prompt]','Existing question that stays first.');
  for (const [i, value] of ['One','Two','Three','Four'].entries()) {
    await fill(`[data-question="0"] [data-choice][id$="choice${i}"]`, value);
  }
  await click('[data-question="0"] [data-answer][value="0"]');
  await evaluate('synkLmsFeedback.toast("Previous editor action")');
  await click('#assessmentAddBulkBottom');
  await until('document.querySelector("#assessmentBulkModal").classList.contains("show") && document.activeElement.id==="assessmentBulkSource"');
  check(await evaluate('document.querySelector("#assessmentBulkModal .modal-content").getBoundingClientRect().width>=900 && document.querySelector("#assessmentBulkRoom").textContent.includes("99 more") && !document.querySelector(".swal2-toast")'),
    'The wide modal accounts for existing questions and opens without an obstructing toast.');
  await call('Emulation.setEmulatedMedia', { features:[{ name:'prefers-reduced-motion', value:'no-preference' }] }, session);
  await evaluate(`(() => {
    window.bulkCheckTimeline = [];
    const observer = new MutationObserver(changes => {
      for (const change of changes) {
        if (change.oldValue === 'checking') window.bulkCheckTimeline.push(Number(change.target.dataset.number));
      }
      if (window.bulkCheckTimeline.length === 25) observer.disconnect();
    });
    observer.observe(document.getElementById('assessmentBulkChecked'), {
      subtree:true, attributes:true, attributeOldValue:true, attributeFilter:['data-state']
    });
  })()`);
  await fill('#assessmentBulkSource',text);
  check(await evaluate('document.querySelector("#assessmentBulkProcess").click(); !document.querySelector("#assessmentBulkProgress").hidden && document.querySelector("#assessmentBulkBar").value===0'),
    'A real progress step opens before question validation.');
  await until('!document.querySelector("#assessmentBulkReview").hidden && document.activeElement.id==="assessmentBulkReviewTitle"');
  check(await evaluate('document.querySelector("#assessmentBulkBar").value===25 && document.querySelector("#assessmentBulkReview").textContent.includes("23 ready · 2 need review") && document.querySelector("#assessmentBulkList").children.length===25'),
    'Every item advances progress, including questions with missing or conflicting answers.');
  check(await evaluate('document.querySelectorAll("#assessmentBulkChecked [data-state=ready]").length===23 && document.querySelectorAll("#assessmentBulkChecked [data-state=review]").length===2 && window.bulkCheckTimeline.every((question,index)=>question===index+1) && window.bulkCheckTimeline.length===25 && document.querySelector("#assessmentBulkCurrent").textContent.includes("checked in this browser")'),
    'The per-question indicators show every checked item and distinguish local checking from saving.');
  check(await evaluate('getComputedStyle(document.querySelector("#assessmentBulkChecked .assessment-bulk-check-icon"),"::after").animationName==="assessment-check-ring" && !document.querySelector("#assessmentBulkAdd").disabled'),
    'Per-question loader animations run independently of the Add button.');
  check(await evaluate('document.querySelector("#assessmentBulkPoints").value==="1" && document.querySelector("#assessmentBulkAdd").textContent==="Add 23 questions" && document.querySelector("#assessmentBulkNotesBody").textContent.includes("Choose one answer.") && document.querySelector("#assessmentBulkNotesBody").textContent.includes("first lesson") && document.querySelector("#assessmentBulkNotesBody").textContent.includes("This explanation")'),
    'The preview shows detected metadata and the batch defaults to the suggested points.');
  check(await evaluate('document.querySelectorAll("#assessmentBulkNotesBody [data-bulk-field]:checked").length===2 && !document.querySelector("#assessmentTitle").value && !document.querySelector("#assessmentInstructions").value'),
    'Detected fields are selected automatically for the empty editor, and wait for Add questions.');
  await click('[data-bulk-row="2"] summary');
  const expectedCode = '  99. A numbered line inside code\n  Answer: source text';
  check(await evaluate(`document.querySelector('[data-bulk-row="2"] pre code').textContent===${JSON.stringify(expectedCode)}`),
    'Numbered lines and answer labels inside fenced code remain part of the prompt.');
  await click('#assessmentBulkEdit');
  check(await evaluate(`document.querySelector('#assessmentBulkSource').value===${JSON.stringify(text)}`), 'Returning to paste keeps the original text.');
  await click('#assessmentBulkProcess');
  await until('!document.querySelector("#assessmentBulkReview").hidden');
  await click('[data-bulk-row="6"] summary');
  await fill('[data-bulk-row="6"] [data-bulk-repair]',quizItems[6].replace('B. A first choice','B. A second choice'));
  await click('[data-bulk-row="6"] [data-bulk-recheck]');
  check(await evaluate('document.querySelector("#assessmentBulkAdd").textContent==="Add 24 questions" && document.querySelector(\'[data-bulk-row="7"] [data-bulk-include]\').disabled'),
    'Rechecking selects the corrected item and leaves unresolved work out of the batch.');
  await click('[data-bulk-row="24"] [data-bulk-include]');
  await fill('#assessmentBulkPoints','500');
  await click('#assessmentBulkAdd');
  check(await evaluate('document.querySelector("#assessmentBulkMessage").textContent.includes("10,000 points") && document.querySelectorAll("#assessmentQuestions [data-question]").length===1'),
    'The point limit is checked before the staged import changes the editor.');
  await fill('#assessmentBulkPoints','1.5');
  check(await evaluate('document.querySelector("#assessmentBulkSelection").textContent.includes("23 selected · 34.5 points")'),
    'Changing the batch points updates the selected total.');
  for (const width of [1440, 390, 320]) {
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<500},session);
    await delay(100);
    check(await evaluate('(()=>{const modal=document.querySelector("#assessmentBulkModal .modal-content"),button=document.querySelector("#assessmentBulkAdd").getBoundingClientRect();return modal.getBoundingClientRect().width<=innerWidth&&document.documentElement.scrollWidth<=innerWidth&&button.width>=44&&button.height>=44&&button.right<=innerWidth;})()'),
      'Bulk review and its Add button fit at ' + width + 'px.');
    if(width!==320) {
      const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);
      fs.writeFileSync(path.join(root,'.tmp','assessment-bulk-review-'+width+'_lms.png'),Buffer.from(shot.data,'base64'));
    }
  }
  await click('#assessmentBulkAdd');
  await until('!document.querySelector("#assessmentBulkModal").classList.contains("show") && document.activeElement.closest("[data-question]")?.dataset.question==="1"');
  check(requests.length===initialRequests && await evaluate('document.querySelectorAll("#assessmentQuestions [data-question]").length===24 && document.querySelector("#assessmentQuestionTotal").textContent.includes("35.5 points")'),
    'Adding appends only the selected questions to the unsaved editor, with no network request.');
  check(await evaluate('document.querySelector("#assessmentTitle").value==="Bulk import QA" && document.querySelector("#assessmentInstructions").value==="Choose one answer."'),
    'Adding questions fills the detected title and instructions in the same operation.');
  let releaseSave;
  bulkSaveGate = new Promise(resolve => { releaseSave = resolve; });
  try {
    await click('#assessmentSaveBottom');
    check(await evaluate('document.querySelector("#assessmentSaveState").textContent==="Saving assessment…"'),
      'The save progress begins with the actual save request.');
    await until('document.querySelector("#assessmentSaveState").textContent.includes("Still waiting for the server")');
    check(await evaluate('(()=>{const notice=document.querySelector("#assessmentFloatingState"),live=document.querySelector("#assessmentWriteAnnouncement");return getComputedStyle(notice).display!=="none" && notice.textContent.includes("Still waiting for the server") && document.querySelector("#assessmentSaveBottom").disabled && live.textContent===notice.textContent && !live.closest("[aria-busy=true]");})()'),
      'A slow server response shows the waiting state beside the save buttons on mobile.');
  } finally {
    releaseSave();
    bulkSaveGate = null;
  }
  await until('document.querySelector("#assessmentSaveState").textContent.includes("version 1") && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  const saved = records.find(record => record.title === 'Bulk import QA');
  check(saved?.questions.length===24 && saved.questions[0].prompt==='Existing question that stays first.' && saved.questions[1].prompt.endsWith('example 1.')
    && saved.questions[7].prompt.endsWith('example 7.') && saved.questions[8].prompt.endsWith('example 9.') && saved.questions[23].prompt.endsWith('example 24.')
    && saved.questions.slice(1).every(item => item.answer_key === 1 && item.points === 1.5) && saved.instructions==='Choose one answer.',
    'Normal saving keeps the imported title and instructions, selected questions, keys, order, and points.');

  await click('#assessmentAddBulk');
  await until('document.querySelector("#assessmentBulkModal").classList.contains("show") && document.activeElement.id==="assessmentBulkSource"');
  await fill('#assessmentBulkSource',['Title: Revised quiz title','Instructions: New instructions.', '', quizItems[0]].join('\n'));
  await click('#assessmentBulkProcess');
  await until('!document.querySelector("#assessmentBulkReview").hidden');
  await click('#assessmentBulkNotesLabel');
  check(await evaluate('document.querySelectorAll("#assessmentBulkNotesBody [data-bulk-field]:checked").length===0 && document.querySelector("#assessmentBulkNotesLabel").textContent.includes("Review replacements")'),
    'Existing quiz details require an explicit choice to replace them.');
  await click('[data-bulk-field="title"]');
  await click('#assessmentBulkAdd');
  await until('!document.querySelector("#assessmentBulkModal").classList.contains("show")');
  check(await evaluate('document.querySelector("#assessmentTitle").value==="Revised quiz title" && document.querySelector("#assessmentInstructions").value==="Choose one answer."'),
    'The editor applies the chosen replacement and keeps the other field.');
}
async function transferAssessment(record, width) {
  const original=clone(record), count=records.length, selector=`[data-action=transfer][data-id="${record.assessment_id}"]`;
  const destination=record.grading_period==='midterm'?'final':'midterm';
  await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600},session);await delay(100);
  check(await evaluate(`document.querySelector(${JSON.stringify(selector)}).textContent.trim()==='Move to ${destination==='final'?'Final':'Midterm'}'`),'Transfer labels name the opposite grading period.');
  await click(selector);await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');
  check(await evaluate('document.querySelector("#swal2-html-container").textContent.includes("student responses are preserved") && document.querySelector("#swal2-html-container").textContent.includes("gradebook entries stay") && !document.querySelector("#swal2-html-container img")'),'The confirmation explains preservation and gradebook placement, and renders the title safely.');
  await answerDialog(false);
  check(JSON.stringify(record)===JSON.stringify(original),'Canceling a transfer preserves the saved assessment.');
  await click(selector);conflict=true;await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');await click('.swal2-confirm');
  await until('document.querySelector("#swal2-html-container")?.textContent.includes("another tab")');await answerDialog();
  check(JSON.stringify(record)===JSON.stringify(original)&&await evaluate('document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"'),'A failed transfer keeps the assessment in its source period and permits retry.');
  await click(selector);await answerDialog();
  await until(`document.querySelector('[data-period=${destination}]').getAttribute('aria-pressed')==='true' && document.querySelector('#assessmentWorkspace').getAttribute('aria-busy')==='false'`);
  check(records.length===count&&record.grading_period===destination&&record.version===original.version+1&&JSON.stringify({...record,grading_period:original.grading_period,version:original.version})===JSON.stringify(original),'Transfer moves the same '+record.status+' assessment without duplicating or changing its content, schedule or status.');
  const counts={midterm:records.filter(item=>item.status!=='archived'&&item.grading_period==='midterm').length,final:records.filter(item=>item.status!=='archived'&&item.grading_period==='final').length};
  check(await evaluate(`document.querySelector('#assessmentMidtermCount').textContent==='${counts.midterm}' && document.querySelector('#assessmentFinalCount').textContent==='${counts.final}'`),'Both grading-period counts update after a transfer.');
  check(await evaluate(`document.querySelector(${JSON.stringify(selector)})!==null && (document.activeElement.dataset.action==='transfer' || document.activeElement.hasAttribute('data-assessment-more')) && document.documentElement.scrollWidth<=innerWidth`),'The destination card is visible, focused and fits at '+width+'px.');
  await click(selector);await answerDialog();
  await until(`document.querySelector('[data-period=${original.grading_period}]').getAttribute('aria-pressed')==='true' && document.querySelector('#assessmentWorkspace').getAttribute('aria-busy')==='false'`);
  check(record.grading_period===original.grading_period&&record.version===original.version+2,'The assessment can move back to its original period.');
}
function api(request) {
  requests.push(request);
  check(JSON.stringify(request.context) === JSON.stringify(pageConfig.context) && request.csrf_token === pageConfig.csrf_token,
    'The editor carries its signed class context and CSRF token for every request.');
  const payload = request.payload || {}, found = records.find(item => item.assessment_id === payload.assessment_id);
  if (request.action === 'list') return { ok:true, assessments:records.filter(item=>item.status!=='archived').map(item=>({ ...clone(item), question_count:item.questions.length, student_count:28, answered_count:item.status==='draft'?0:12 })) };
  if (request.action === 'detail') return { ok:true, assessment:clone(found) };
  if (request.action === 'class_followup') {
    if(followupFailure)return {ok:false,status:503,message:'Follow-up temporarily unavailable.'};
    check(Object.keys(payload).length===0,'Class generation has no assessment selector.');
    const students=followupEmpty?[]:Array.from({length:16},(_,i)=>({student_name:i===0?'Álvarez, Ana':i===1?'Bea <img src=x onerror=alert(1)>':i===2?'Cruz, Carlo':'Learner, Student '+(i+1),student_number:'QA-'+(i+1),assessments:[{assessment_id:103,title:'Programming project',assessment_type:'assignment',grading_period:'midterm',status:i===1?'in_progress':i===2?'expired':'not_started',status_label:i===1?'In progress':i===2?'Attempt ended':'Not started',deadline_at:'2026-11-20 17:00:00',deadline_label:'Nov 20, 2026 at 5:00 PM PHT'},{assessment_id:104,title:'Final review quiz',assessment_type:'quiz',grading_period:'final',status:'not_started',status_label:'Not started',deadline_at:null,deadline_label:'No due date'}]}));
    return {ok:true,report:{class:{subject_code:'CS 111',title:'Fundamentals of Programming',section:'BSCS 1A'},counts:{students:28,eligible:27,dropped:1,excluded:2,assessments:2,missing_students:students.length,missing_assessments:students.length*2},students,notice:'Published and closed work across both periods; dropped and excluded accounts omitted.',generated_label:'Nov 17, 2026 at 8:00 AM PHT',message:students.length?'CLASS SUBMISSION FOLLOW-UP\n'+students.map((s,i)=>(i+1)+'. '+s.student_name+'\n'+s.assessments.map(a=>'   - '+a.title+' ('+a.status_label+')').join('\n')).join('\n\n'):''}};
  }
  if (request.action === 'transfer') {
    if (conflict) { conflict=false;return {status:409,ok:false,message:'This assessment changed in another tab. Refresh the list before moving it.'}; }
    check(payload.version===found.version && payload.grading_period!==found.grading_period,'Transfer sends the current revision and opposite period.');
    found.grading_period=payload.grading_period;found.version++;
    return {ok:true,assessment:clone(found)};
  }
  if (request.action === 'schedule') {
    if (conflict) { conflict=false; return { status:409, ok:false, message:'This assessment changed in another tab. Close the schedule and refresh the list before saving.' }; }
    found.opens_at=payload.opens_at;found.due_at=payload.due_at;found.version++;
    return {ok:true,assessment:clone(found)};
  }
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
      const apiRequest=isApi ? JSON.parse(request.postData) : null;
      const data=isApi ? api(apiRequest) : html;
      if (apiRequest?.action === 'save' && bulkSaveGate) await bulkSaveGate;
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
  check(await evaluate('document.querySelector("#assessmentAddQuestion").nextElementSibling.id==="assessmentAddBulk" && document.querySelector("#assessmentAddQuestionBottom").nextElementSibling.id==="assessmentAddBulkBottom" && !document.querySelector("#assessmentQuestionsEmpty").hidden && document.querySelector("#assessmentQuestionsEmpty").textContent.includes("No questions yet.")'),'Both action bars place Add bulk questions beside Add question.');
  await click('#assessmentAddQuestionBottom');check(await evaluate('document.querySelectorAll("[data-question]").length===1&&document.querySelector("#assessmentQuestionsEmpty").hidden'),'The floating Add question button creates a question and hides the empty prompt.');await click('[data-question="0"] [data-operation="remove"]');check(await evaluate('!document.querySelector("#assessmentQuestionsEmpty").hidden'),'Removing the last blank question restores the prompt.');
  await checkQuestionPaste();
  for(const width of [390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:true},session);await delay(150);
    check(await evaluate('[...document.querySelectorAll(".assessment-editor-heading .assessment-actions,.assessment-floating-actions .assessment-actions")].every(bar=>{const buttons=[...bar.querySelectorAll("button:not([hidden])")].map(button=>button.getBoundingClientRect());return buttons.length===5&&Math.abs(buttons[0].top-buttons[1].top)<1&&Math.abs(buttons[2].top-buttons[4].top)<1&&buttons[2].top>buttons[0].top&&buttons.every(r=>r.width>75&&r.height>=44&&r.left>=0&&r.right<=innerWidth);})&&document.documentElement.scrollWidth<=innerWidth'),'The two question actions and three draft actions fit on separate rows at '+width+'px.');
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
  const codeSample='<?php\n    echo "<script>window.assessmentCodeExecuted = true;</script>";\n    // ``` stays inside this example\n    $label = "'+'Programming '.repeat(20)+'";\n?>\nA. Example text\nB. Another line\nAnswer: B';
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
  const codeQuestion = formattedPrompt + '\n\n(A) 3\n(B) 4\n(C) 5\n(D) 6\nAnswer key: (B) 4';
  check(await pasteText('[data-question="0"] [data-field="prompt"]', codeQuestion), 'The complete programming question is recognized around the fenced code.');
  await answerDialog();
  check(await evaluate(`document.querySelector('[data-question="0"] [data-field="prompt"]').value===${JSON.stringify(formattedPrompt)}
    && document.querySelector('[data-question="0"] pre code').textContent===${JSON.stringify(codeSample)}
    && document.querySelector('[data-question="0"] [data-answer]:checked')?.value==='1'`), 'The programming question preserves the original code and uses the answer outside the fence.');
  await evaluate('document.querySelector("[data-question=\\"0\\"]").scrollIntoView({block:"start",behavior:"instant"})');
  const editorShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-code-editor_lms.png'),Buffer.from(editorShot.data,'base64'));
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
  const savedMultipleChoice = records[0]?.questions[3];
  check(records.length===1&&records[0].grading_period==='final'&&records[0].total_points===9.5&&records[0].questions[2].answer_key===false
    &&savedMultipleChoice.prompt===formattedPrompt&&savedMultipleChoice.answer_key===1&&savedMultipleChoice.options.join(',')==='3,4,5,6',
    'The editor saves the pasted choices and answer, formatted source, displayed order, and correct point total.');
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
  await mobileCardActions('draft');
  await transferAssessment(records[0],1440);
  check(await evaluate('!document.querySelector("[data-action=missing_submissions]")&&!!document.querySelector("#assessmentGenerateFollowup")'),'Generation is available once for the class, outside individual draft cards.');
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
  await transferAssessment(records[2],390);
  const publishedVersion=records[2].version;
  await mobileCardActions('published');
  check(await evaluate('!!document.querySelector("#assessmentList [data-action=edit]") && !!document.querySelector("#assessmentList [data-action=submissions]") && !!document.querySelector("#assessmentList [data-action=close]")'),'Published assessments offer editing, response review and closing.');
  check(await evaluate('document.querySelector(".assessment-count-answered").textContent.trim().startsWith("12") && document.querySelector(".assessment-count-total").textContent.trim().startsWith("28") && getComputedStyle(document.querySelector(".assessment-count-answered")).color==="rgb(21, 128, 61)" && getComputedStyle(document.querySelector(".assessment-count-total")).color==="rgb(185, 28, 28)"'),'Published cards display answered students in green and total students in red.');
  check(await evaluate('!document.querySelector("[data-action=missing_submissions]")&&!document.querySelector("#assessmentGenerateFollowup").disabled'),'Published cards use the single class-wide generator.');
  await click('#assessmentGenerateFollowup');await until('document.querySelector("#assessmentFollowupModal").classList.contains("show")&&!bootstrap.Modal.getInstance(document.querySelector("#assessmentFollowupModal"))._isTransitioning&&document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelectorAll(".assessment-followup-student").length===16&&document.querySelectorAll(".assessment-followup-items>li").length===32&&document.querySelector("#assessmentFollowupMissing").textContent==="16"&&document.querySelector("#assessmentFollowupSubmitted").textContent==="32"&&!document.querySelector("#assessmentFollowupStudents img")&&document.querySelector("#assessmentFollowupStudents").textContent.includes("Attempt ended")'),'The report groups every outstanding assessment and its status beneath the student name.');
  await fill('#assessmentFollowupSearch','alvarez');check(await evaluate('document.querySelectorAll(".assessment-followup-student").length===1&&document.querySelector("#assessmentFollowupResult").textContent.includes("1 of 16")'),'Student search matches accented names.');
  await evaluate('Object.defineProperty(navigator,"clipboard",{configurable:true,value:{writeText:async text=>{window.qaFollowupClipboard=text;}}})');
  await click('#assessmentFollowupCopy');await until('document.querySelector("#assessmentFollowupCopyStatus").textContent.includes("Full reminder copied")');
  check(await evaluate('window.qaFollowupClipboard===document.querySelector("#assessmentFollowupMessage").value&&window.qaFollowupClipboard.includes("16. Learner, Student 16")&&!window.qaFollowupClipboard.includes("QA-")'),'Copy always includes the whole list and exactly matches the preview, even during search.');
  await fill('#assessmentFollowupSearch','nothing matches');check(await evaluate('!document.querySelector("#assessmentFollowupEmpty").hidden&&document.querySelector("#assessmentFollowupEmpty").textContent.includes("match your search")'),'No-match searches have a clear empty state.');await fill('#assessmentFollowupSearch','');
  for(const width of [1440,768,390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:950,deviceScaleFactor:1,mobile:width<600},session);await delay(100);
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth&&document.querySelector("#assessmentFollowupModal .modal-content").getBoundingClientRect().right<=innerWidth&&document.querySelector("#assessmentFollowupCopy").getBoundingClientRect().height>=44&&document.querySelector("#assessmentFollowupModal .modal-body").scrollHeight>document.querySelector("#assessmentFollowupModal .modal-body").clientHeight'),'The long student list scrolls inside the dialog and Copy stays reachable at '+width+'px.');
    if([1440,390].includes(width)){fs.writeFileSync(path.join(root,'.tmp','assessment-followup-'+width+'_lms.png'),Buffer.from((await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session)).data,'base64'));}
  }
  await evaluate('navigator.clipboard.writeText=async()=>{throw Error("Denied")};document.execCommand=()=>false');await click('#assessmentFollowupCopy');await until('document.querySelector("#assessmentFollowupCopyStatus").textContent.includes("Automatic copying is unavailable")');
  check(await evaluate('document.querySelector("#assessmentFollowupPreview").open&&document.activeElement.id==="assessmentFollowupMessage"&&document.activeElement.selectionEnd===document.activeElement.value.length'),'Clipboard denial opens and selects the full reminder for manual copying.');
  followupFailure=true;await click('#assessmentFollowupRefresh');await until('!!document.querySelector(".swal2-popup:not(.swal2-toast)")');await answerDialog();
  check(await evaluate('document.querySelectorAll(".assessment-followup-student").length===16&&document.querySelector("#assessmentFollowupCopyStatus").textContent.includes("Refresh failed")'),'A failed refresh retains the timestamped list and reports stale data.');followupFailure=false;
  followupEmpty=true;await click('#assessmentFollowupRefresh');await until('document.querySelector("#assessmentFollowupMissing").textContent==="0"&&document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelector("#assessmentFollowupCopy").disabled&&document.querySelector("#assessmentFollowupPreview").hidden&&document.querySelector("#assessmentFollowupEmpty").textContent.includes("Everyone currently enrolled has submitted")'),'An all-submitted class gets a positive empty state and no copyable reminder.');
  followupEmpty=false;await click('#assessmentFollowupRefresh');await until('document.querySelector("#assessmentFollowupMissing").textContent==="16"&&document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  await click('#assessmentFollowupModal .modal-header [data-bs-dismiss]');await until('!document.body.classList.contains("modal-open")&&!document.querySelector("#assessmentFollowupModal").classList.contains("show")&&!bootstrap.Modal.getInstance(document.querySelector("#assessmentFollowupModal"))._isTransitioning');
  check(await evaluate('document.activeElement.id==="assessmentGenerateFollowup"'),'Closing returns keyboard focus to the class report generator.');
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
    check(await evaluate('[...document.querySelectorAll(".assessment-actions.is-published")].every(bar=>{const buttons=[...bar.querySelectorAll("button:not([hidden])")].map(button=>button.getBoundingClientRect());return buttons.length===4&&Math.abs(buttons[0].top-buttons[1].top)<1&&Math.abs(buttons[2].top-buttons[3].top)<1&&buttons[2].top>buttons[0].top&&buttons.every(r=>r.height>=44&&r.width>80&&r.right<=innerWidth);}) && document.documentElement.scrollWidth<=innerWidth'),'Published edit actions, including bulk import, fit at '+width+'px.');
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
  await mobileCardActions('closed');
  await transferAssessment(records[2],320);
  await click('[data-action=edit]');await until('!document.querySelector("#assessmentEditor").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(await evaluate('document.querySelector("#assessmentPublish").textContent==="Reopen" && !document.querySelector("#assessmentPublish").hidden && document.querySelector("#assessmentSave").textContent==="Save changes"'),'Closed assessments remain editable and provide an explicit Reopen action.');
  await fill('#assessmentTopic','Closed assessment correction');await click('#assessmentSave');await answerDialog();await until(`document.querySelector("#assessmentSaveState").textContent.includes("version ${publishedVersion+5}") && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"`);
  check(records[2].status==='closed','Saving edits to a closed assessment keeps it closed.');
  await click('#assessmentPublish');await answerDialog(false);check(records[2].status==='closed','Canceling Reopen keeps the assessment closed.');
  await click('#assessmentPublish');await answerDialog();await until('!document.querySelector("#assessmentLibrary").hidden && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(records[2].status==='published','Reopen explicitly publishes the updated assessment for new attempts.');
  await click('[data-action=schedule]');
  await until('document.querySelector("#assessmentScheduleModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentScheduleModal"))._isTransitioning');
  check(await evaluate('document.querySelector("#assessmentScheduleStart").value==="2026-11-15T08:00" && document.querySelector("#assessmentScheduleEnd").value==="2026-11-16T08:00" && document.querySelector("#assessmentScheduleNote").textContent.includes("Started attempts keep their original deadline") && document.activeElement.id==="assessmentScheduleStart"'),'The shortcut opens the current dates with the original-attempt rule and keyboard focus.');
  const scheduleCount=requests.filter(request=>request.action==='schedule').length, scheduleVersion=records[2].version;
  await fill('#assessmentScheduleStart','2026-12-01T08:00');await fill('#assessmentScheduleEnd','2026-12-01T07:00');
  await click('#assessmentScheduleForm [type=submit]');await until('document.querySelector("#swal2-html-container")?.textContent.includes("end must be after the start")');
  check(requests.filter(request=>request.action==='schedule').length===scheduleCount,'Invalid schedule ordering is caught before the request.');await answerDialog();
  await fill('#assessmentScheduleEnd','2026-12-01T09:00');conflict=true;await click('#assessmentScheduleForm [type=submit]');
  await until('document.querySelector("#swal2-html-container")?.textContent.includes("another tab")');await answerDialog();
  check(await evaluate('document.querySelector("#assessmentScheduleModal").classList.contains("show") && document.querySelector("#assessmentScheduleStart").value==="2026-12-01T08:00" && !document.querySelector("#assessmentScheduleForm [type=submit]").disabled'),'A failed schedule keeps both entered dates and allows retry.');
  for(const width of [1440,390,320]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<500},session);await delay(100);
    check(await evaluate('(()=>{const r=document.querySelector("#assessmentScheduleModal .modal-content").getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && document.querySelector("#assessmentScheduleStart").getBoundingClientRect().width>200 && document.documentElement.scrollWidth<=innerWidth;})()'),'The schedule form fits at '+width+'px.');
  }
  const scheduleShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-schedule-mobile_lms.png'),Buffer.from(scheduleShot.data,'base64'));
  await click('#assessmentScheduleForm [type=submit]');
  await until('!document.querySelector("#assessmentScheduleModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentScheduleModal"))._isTransitioning && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(records[2].status==='published'&&records[2].version===scheduleVersion+1&&records[2].due_at==='2026-12-01T09:00'&&records[2].questions[0].prompt==='Corrected published question','The schedule shortcut saves dates while retaining status and questions.');
  await until('document.activeElement.dataset.action==="schedule"');
  check(await evaluate('document.querySelector(".assessment-list-meta").textContent.includes("Starts Dec 1") && document.querySelector(".assessment-list-meta").textContent.includes("Ends Dec 1") && document.activeElement.dataset.action==="schedule"'),'Saved start/end dates appear immediately on the card and focus returns to its shortcut.');
  await click('[data-action=schedule]');await until('!bootstrap.Modal.getInstance(document.querySelector("#assessmentScheduleModal"))._isTransitioning');
  check(await evaluate('document.querySelector("#assessmentScheduleStart").value==="2026-12-01T08:00" && document.querySelector("#assessmentScheduleEnd").value==="2026-12-01T09:00"'),'Reopening the shortcut loads saved dates.');
  await fill('#assessmentScheduleEnd','2026-12-02T09:00');await click('#assessmentScheduleModal [data-bs-dismiss=modal]');
  await until('!document.querySelector("#assessmentScheduleModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentScheduleModal"))._isTransitioning');
  check(records[2].due_at==='2026-12-01T09:00','Canceling the shortcut leaves the saved schedule unchanged.');
  await until('document.activeElement.dataset.action==="schedule"');
  await click('[data-action=schedule]');await until('!bootstrap.Modal.getInstance(document.querySelector("#assessmentScheduleModal"))._isTransitioning');
  await fill('#assessmentScheduleStart','');await fill('#assessmentScheduleEnd','');await click('#assessmentScheduleForm [type=submit]');
  await until('!document.querySelector("#assessmentScheduleModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.querySelector("#assessmentScheduleModal"))._isTransitioning && document.querySelector("#assessmentWorkspace").getAttribute("aria-busy")==="false"');
  check(records[2].opens_at===null&&records[2].due_at===null&&await evaluate('document.querySelector(".assessment-list-meta").textContent.includes("No start date") && document.querySelector(".assessment-list-meta").textContent.includes("No end date")'),'Clearing the shortcut removes both dates and updates the card.');
  await fill('#assessmentSearch','no-such-assessment');
  check(await evaluate('document.querySelector("#assessmentList").innerText.includes("No matching assessments")'),'Search distinguishes no matches from an empty grading period.');
  await fill('#assessmentSearch','');await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false},session);
  await evaluate('document.fonts.ready.then(()=>true)');
  const clip=await evaluate('(()=>{const r=document.querySelector("#assessmentWorkspace").getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1};})()');
  const shot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:true,clip},session);fs.writeFileSync(path.join(root,'.tmp','assessment-drafts_lms.png'),Buffer.from(shot.data,'base64'));
  await checkBulkQuestions();
  check(!errors.length,'No browser errors: '+errors.join('\n'));check(!failures.length,'Local assessment assets load: '+failures.join('\n'));
  check(requests.some(request=>request.action==='save')&&requests.some(request=>request.action==='archive'),'The editor exercises both save and archive actions.');
  console.log(`LMS assessment browser: ${checks} checks passed, including bulk import, authoring, preview, revisions, duplication, archiving, and mobile layout.`);
}
main().catch(async error=>{
  console.error(error.stack);
  if(call&&ws?.readyState===WebSocket.OPEN){try{
    console.error(JSON.stringify(await evaluate('(()=>{const modal=document.querySelector("#assessmentScheduleModal"),input=document.querySelector("#assessmentScheduleStart");return {scheduleClass:modal?.className,modalWidth:modal?.querySelector(".modal-content").getBoundingClientRect().width,inputWidth:input?.getBoundingClientRect().width,viewport:innerWidth,scrollWidth:document.documentElement.scrollWidth,cardDates:document.querySelector(".assessment-list-meta")?.textContent,focus:document.activeElement.outerHTML};})()')));
    const failedShot=await call('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},session);fs.writeFileSync(path.join(root,'.tmp','assessment-browser-failure_lms.png'),Buffer.from(failedShot.data,'base64'));
  }catch{}}
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
