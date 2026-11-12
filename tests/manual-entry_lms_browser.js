// Run with the connected browser's run-code tool after rendering gradebook_lms.php
// to .tmp/manual-entry_lms.html and .json. All mutations are intercepted in one
// isolated test tab; the PHP fixture generator rolls back its database changes.
async (page) => {
  const origin='http://localhost/synk/',html=await (await page.request.get(origin+'.tmp/manual-entry_lms.html')).text();
  const data=await (await page.request.get(origin+'.tmp/manual-entry_lms.json')).json();
  const config=JSON.parse(html.match(/<script id="gradebookConfig" type="application\/json">(.*?)<\/script>/s)[1]);
  const qa=await page.context().newPage(),requests=[],errors=[];let checks=0,nextId=900000,conflict=true;
  const check=(ok,message)=>{if(!ok)throw Error(message);checks++;};
  const wait=async expression=>{try{await qa.waitForFunction(expression,null,{timeout:10000,polling:100});}catch(error){throw Error('Timed out: '+expression+'; '+JSON.stringify(await qa.evaluate(()=>({state:document.getElementById('gbSaveState')?.textContent,busy:document.getElementById('gradebookWorkspace')?.getAttribute('aria-busy'),modal:document.querySelector('.modal.show')?.id,message:document.querySelector('.swal2-html-container')?.textContent,saveDisabled:document.getElementById('gbSaveScores')?.disabled,inputs:[...document.querySelectorAll('[data-gb-input]')].map(el=>({value:el.value,invalid:el.getAttribute('aria-invalid'),bad:el.validity.badInput})),pasteHidden:document.getElementById('gbPastePanel')?.hidden})))+'; actions: '+requests.map(r=>r.action).join(',')+'; errors: '+errors.join('; '));}};
  const click=selector=>qa.locator(selector).evaluate(node=>node.click());
  const fill=async(selector,value)=>{await qa.locator(selector).fill(String(value));};
  const submit=()=>qa.locator('#gbItemForm').evaluate(form=>form.requestSubmit());
  const idle=()=>wait('!document.getElementById("gradebookWorkspace").hasAttribute("aria-busy")');
  const closeWork=async()=>{await wait('document.getElementById("gbWorkModal").dataset.qaShown==="true"');await click('#gbWorkModal .modal-footer [data-bs-dismiss]');await wait('!document.body.classList.contains("modal-open")');};
  qa.on('pageerror',error=>errors.push(error.message));
  await qa.addInitScript(()=>{document.addEventListener('shown.bs.modal',event=>{event.target.dataset.qaShown='true';});document.addEventListener('hidden.bs.modal',event=>{delete event.target.dataset.qaShown;});});
  await qa.route('**/class_lms.php?manual_entry_qa=1',route=>route.fulfill({status:200,contentType:'text/html',body:html}));
  await qa.route('**/gradebook_api_lms.php',async route=>{
    const input=route.request().postDataJSON();requests.push(input);
    check(input.csrf_token===config.csrf_token&&JSON.stringify(input.context)===JSON.stringify(config.context),'Signed class context or CSRF changed.');
    const p=input.payload,b=data.periods[p.grading_period];let mutation={};
    if(input.action!=='get'){
      check(p.version===b.version&&p.scheme_version===b.scheme.version,'Mutation used a stale book or grading revision.');
      if(input.action==='save_item'){
        const item={item_id:p.item_id||++nextId,title:p.title,entry_kind:p.entry_kind,component_id:p.component_id,max_points:Number(p.max_points),recorded_date:p.recorded_date,assessment_id:p.assessment_id};
        check(b.scheme.components.some(c=>c.component_id===item.component_id),'Entry used a component from another period.');
        if(p.item_id)b.items[b.items.findIndex(i=>i.item_id===p.item_id)]=item;else b.items.push(item);
        mutation.saved_item_id=item.item_id;
      }else if(input.action==='save_scores'){
        if(conflict){conflict=false;await route.fulfill({status:409,json:{ok:false,message:'This gradebook changed in another tab. Your entries are kept.'}});return;}
        b.scores[p.item_id]??={};
        for(const entry of p.scores){const automatic=b.scores[p.item_id][entry.student_id]?.automatic_points;if(entry.points===null){if(automatic===undefined)delete b.scores[p.item_id][entry.student_id];else b.scores[p.item_id][entry.student_id]={points:automatic,automatic_points:automatic,source:'lms',remark:''};}else b.scores[p.item_id][entry.student_id]={points:Number(entry.points),remark:entry.remark,source:'manual',...(automatic===undefined?{}:{automatic_points:automatic})};}
      }else throw Error('Unexpected mutation in manual-entry QA: '+input.action);
      b.version++;
    }
    await route.fulfill({status:200,json:{ok:true,...data,...mutation}});
  });
  try {
    await qa.goto(origin+'professor/class_lms.php?manual_entry_qa=1',{waitUntil:'domcontentloaded'});
    await wait('!document.getElementById("gbContent").hidden');await idle();
    await click('#gbAddManual');await wait('document.getElementById("gbItemModal").classList.contains("show")');
    check(await qa.locator('#gbItemKindField').isHidden(),'Direct manual entry should use a short form.');
    check(await qa.locator('#gbItemPeriod').inputValue()==='midterm','The selected grading period must be preselected.');
    await qa.locator('#gbItemPeriod').selectOption('final');
    check(await qa.locator('#gbItemComponent option').count()===2,'Changing period must use only that period\'s components.');
    await qa.locator('#gbItemPeriod').selectOption('midterm');
    check(await qa.locator('#gbItemComponent option').count()===4,'Every configured component must support manual work.');
    const component=data.periods.midterm.scheme.components.find(c=>c.name==='Classwork'),title='Face-to-face activity <b>test</b>';
    await qa.locator('#gbItemComponent').selectOption(String(component.component_id));await fill('#gbItemName',title);await fill('#gbItemMax','20');await submit();
    await wait('!document.getElementById("gbScoreEditor").hidden&&!document.body.classList.contains("modal-open")');await idle();
    const created=data.periods.midterm.items.at(-1),ids=data.roster.map(s=>s.student_id),score=i=>`[data-gb-input="${ids[i]}"]`;
    check(created.title===title&&created.max_points===20&&created.component_id===component.component_id,'Manual setup details changed.');
    check(await qa.locator('#gbScoreTitle').innerText()===title&&await qa.locator('#gbScoreTitle b').count()===0,'Entry titles must be escaped.');
    check(await qa.locator('[data-gb-input]').count()===3&&await qa.locator('#gbScoreProgress').innerText()==='0 of 3 recorded · 3 unrecorded','New entries must open the full, unrecorded roster.');
    check(await qa.locator(score(0)).evaluate(el=>document.activeElement===el),'New entries should focus the first score.');
    check(await qa.locator('.gb-score-note[open]').count()===0,'Empty optional notes should stay collapsed.');
    await fill(score(0),'18');await qa.locator(score(0)).press('Enter');
    check(await qa.locator(score(1)).evaluate(el=>document.activeElement===el),'Enter must advance to the next visible student.');
    await qa.locator(score(1)).press('Shift+Enter');check(await qa.locator(score(0)).evaluate(el=>document.activeElement===el),'Shift+Enter must move back.');
    await fill(score(1),'0');
    check(await qa.locator(`[data-gb-status="${ids[1]}"]`).innerText()==='Recorded zero'&&await qa.locator(`[data-gb-status="${ids[2]}"]`).innerText()==='Unrecorded','Recorded zeros and unrecorded students must be distinct.');
    await fill(score(0),'21');check(await qa.locator(score(0)).getAttribute('aria-invalid')==='true'&&await qa.locator('#gbSaveScores').isDisabled(),'Scores over the maximum must immediately block saving.');
    await fill(score(0),'18');await fill('#gbScoreSearch',data.roster[2].student_number);
    check(await qa.locator('[data-gb-student]:visible').count()===1&&(await qa.locator('#gbScoreProgress').innerText()).includes('2 of 3 recorded'),'Search must not change class progress.');
    await click('#gbPasteScores');await qa.locator('#gbPasteStart').selectOption(String(ids[0]));
    await fill('#gbPasteText','16\n\n0\n');await click('#gbPastePreview');
    check(await qa.locator('#gbPasteTable tbody tr').count()===3&&await qa.locator(score(0)).inputValue()==='18','Paste preview must not apply scores.');
    check((await qa.locator('#gbPasteTable').innerText()).includes(data.roster[0].full_name),'Paste mapping must use the full roster despite search.');
    await fill('#gbPasteText','16\n25\n0');check(await qa.locator('#gbPasteApply').isDisabled(),'Editing pasted text must invalidate the previous preview.');
    await click('#gbPastePreview');check(await qa.locator('#gbPasteApply').isDisabled()&&(await qa.locator('#gbPasteState').innerText()).includes('invalid'),'Invalid pasted scores must block the entire application.');
    await fill('#gbPasteText','10\t11');await click('#gbPastePreview');check(await qa.locator('#gbPasteApply').isDisabled()&&(await qa.locator('#gbPasteTable').innerText()).includes('one score column'),'Extra spreadsheet columns must be rejected.');
    await fill('#gbPasteText','1\n2\n3\n4');await click('#gbPastePreview');check(await qa.locator('#gbPasteApply').isDisabled()&&(await qa.locator('#gbPasteState').innerText()).includes('more pasted rows'),'Extra pasted rows must not silently truncate.');
    await fill('#gbPasteText','<img src=x onerror=alert(1)>');await click('#gbPastePreview');check(await qa.locator('#gbPasteTable img').count()===0&&await qa.locator('#gbPasteApply').isDisabled(),'Pasted content must be escaped and validated.');
    await fill('#gbPasteText','16\n\n0');await click('#gbPastePreview');await fill(score(2),'1');
    check(await qa.locator('#gbPasteApply').isDisabled(),'Editing a draft after preview must invalidate its current-score comparison.');
    await click('#gbPastePreview');const beforeApply=requests.length;await click('#gbPasteApply');
    check(requests.length===beforeApply&&await qa.locator(score(0)).inputValue()==='16'&&await qa.locator(score(1)).inputValue()==='0'&&await qa.locator(score(2)).inputValue()==='0','Applying a paste must keep blank positions, preserve zero, and remain unsaved.');
    await fill('#gbScoreSearch','');
    await qa.locator(score(1)).evaluate(el=>{const clipboard=new DataTransfer();clipboard.setData('text/plain','12\n0');el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:clipboard,bubbles:true,cancelable:true}));});
    check(await qa.locator('#gbPasteStart').inputValue()===String(ids[1])&&await qa.locator('#gbPasteTable tbody tr').count()===2,'Multiline paste into a score must preview from that student.');
    await click('#gbPasteClose');
    await qa.locator(`[data-gb-note="${ids[0]}"]`).evaluate(el=>el.closest('details').open=true);await fill(`[data-gb-note="${ids[0]}"]`,'Checked paper');
    await click('#gbSaveScores');await wait('document.querySelector(".swal2-popup:not(.swal2-toast)")');
    check(await qa.locator(score(0)).inputValue()==='16'&&(await qa.locator('#gbSaveState').innerText()).includes('unsaved'),'A save conflict must preserve the typed and pasted scores.');
    await click('.swal2-confirm');await idle();await click('#gbSaveScores');await wait('document.getElementById("gbSaveState").textContent==="All changes saved"');await idle();
    check(data.periods.midterm.scores[created.item_id][ids[0]].points===16&&data.periods.midterm.scores[created.item_id][ids[0]].remark==='Checked paper'&&data.periods.midterm.scores[created.item_id][ids[1]].points===0,'Saving must retain manual points, explicit zero and optional notes.');
    check((await qa.locator('#gbScoreProgress').innerText()).includes('3 of 3 recorded'),'Saved scores must update recording progress.');
    await fill(score(1),'');check((await qa.locator('#gbScoreProgress').innerText()).includes('2 of 3 recorded'),'Clearing a zero must become unrecorded.');
    await fill(score(1),'0');check(await qa.locator('#gbSaveScores').isDisabled(),'Reverting to the saved zero must clear the unsaved change.');
    await click('#gbScoreBack');await wait('document.getElementById("gbWorkModal").classList.contains("show")');
    await click(`[data-score-work="item-${created.item_id}"]`);await wait('!document.getElementById("gbScoreEditor").hidden&&!document.body.classList.contains("modal-open")');
    check(await qa.locator(score(0)).inputValue()==='16'&&await qa.locator(score(1)).inputValue()==='0','Saved entries must reopen for correction.');
    for(const width of [1440,390,320]){await qa.setViewportSize({width,height:1000});check(await qa.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Score entry overflows at '+width+'px.');}
    await qa.setViewportSize({width:1440,height:1000});
    await qa.screenshot({path:'C:/VertrigoServ/www/synk/.tmp/manual-entry-score_lms.png',fullPage:true});
    await click('#gbScoreBack');await wait('document.getElementById("gbWorkModal").classList.contains("show")');await closeWork();
    await click('[data-gb-period="final"]');await idle();await click('#gbAddManual');
    check(await qa.locator('#gbItemPeriod').inputValue()==='final','The Final period must also preselect correctly.');
    await qa.locator('#gbItemComponent').selectOption(String(data.periods.final.scheme.components[0].component_id));await fill('#gbItemName','Face-to-face final examination');await fill('#gbItemMax','50');await submit();
    await wait('!document.getElementById("gbScoreEditor").hidden&&!document.body.classList.contains("modal-open")');await idle();
    check((await qa.locator('#gbScoreHelp').innerText()).includes('Final · Final exam')&&data.periods.final.items.at(-1).max_points===50,'Manual entries must work in Final and use its selected component.');
    await click('#gbScoreBack');await wait('document.getElementById("gbWorkModal").classList.contains("show")');await closeWork();
    await click('[data-gb-period="summary"]');check(await qa.locator('#gbAddManual').isHidden(),'The semester summary must not create entries without a chosen period.');
    await click('[data-gb-period="midterm"]');await idle();await click('#gbOpenWork');await wait('document.getElementById("gbWorkModal").classList.contains("show")');
    const attendance=data.periods.midterm.items.find(i=>i.entry_kind==='attendance');
    await click(`[data-score-work="item-${attendance.item_id}"]`);await wait('!document.getElementById("gbScoreEditor").hidden&&!document.body.classList.contains("modal-open")');
    check(await qa.locator('#gbPasteScores').isHidden()&&await qa.locator('#gbMarkPresent').isVisible(),'Attendance must retain its present/absent workflow.');
    await click('#gbMarkPresent');await wait('document.querySelector(".swal2-popup:not(.swal2-toast)")');await click('.swal2-confirm');
    await wait('!synkLmsFeedback.isPending()&&!document.querySelector(".swal2-popup:not(.swal2-toast)")');
    check(await qa.locator('[data-gb-input]').evaluateAll(inputs=>inputs.every(el=>el.value==='1')),'Bulk attendance should mark only the unrecorded students present.');
    await click('#gbSaveScores');await idle();
    await click('#gbScoreBack');await wait('document.getElementById("gbWorkModal").classList.contains("show")');
    const linked=data.periods.midterm.items.find(i=>i.entry_kind==='lms');
    await click(`[data-score-work="item-${linked.item_id}"]`);await wait('!document.getElementById("gbScoreEditor").hidden&&!document.body.classList.contains("modal-open")');
    check(await qa.locator('#gbPasteScores').isHidden(),'Linked LMS overrides must retain their required-note workflow.');
    const linkedStudent=Number(Object.keys(data.periods.midterm.scores[linked.item_id])[0]),linkedInput=`[data-gb-input="${linkedStudent}"]`;
    await fill(linkedInput,'1');await fill(`[data-gb-note="${linkedStudent}"]`,'');await click('#gbSaveScores');await wait('document.querySelector(".swal2-popup:not(.swal2-toast)")');
    check((await qa.locator('.swal2-html-container').innerText()).includes('note'),'An LMS override must still require a note.');await click('.swal2-confirm');
    await fill(`[data-gb-note="${linkedStudent}"]`,'Reviewed answer');await click('#gbSaveScores');await idle();
    check(data.periods.midterm.scores[linked.item_id][linkedStudent].remark==='Reviewed answer','An LMS manual review note must persist.');
    check(!errors.length,'Browser errors: '+errors.join('; '));
    return {checks,mutations:requests.filter(r=>r.action!=='get').length,productionWrites:0};
  } finally {await qa.close({runBeforeUnload:false});}
}
