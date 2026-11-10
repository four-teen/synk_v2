(function () {
  'use strict';
  const root = document.getElementById('gradingWorkspace'), configNode = document.getElementById('gradingConfig');
  if (!root || !configNode) return;
  const config = JSON.parse(configNode.textContent), labels = { midterm:'Midterm', final:'Final' };
  const feedback = window.synkLmsFeedback;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const percent = value => Number(value || 0).toLocaleString('en-PH', { maximumFractionDigits:2 }) + '%';
  const states = {}, forms = {};
  let sequence = 0, loading = false;
  function message(period, text = '', error = false) {
    if (text) { if (error) feedback.error(text,labels[period] + ' grading system'); else feedback.toast(text); }
  }
  function sync(period) {
    const current = states[period];
    forms[period].querySelectorAll('[data-component]').forEach((node,index) => {
      const item = current.components[index];
      for (const field of ['name','description','weight']) item[field] = node.querySelector(`[data-field="${field}"]`).value;
    });
  }
  function update(period) {
    const current = states[period], form = forms[period];
    const total = current.components.reduce((sum,item) => sum + Math.round((Number(item.weight) || 0) * 100), 0);
    const validWeights = current.components.every(item => /^(?:\d{1,3})(?:\.\d{1,2})?$/.test(String(item.weight)) && Number(item.weight)>0 && Number(item.weight)<=100);
    const balanced = current.components.length > 0 && total === 10000 && validWeights;
    form.classList.toggle('is-balanced', balanced);
    form.classList.toggle('is-unbalanced', !balanced && current.components.length > 0);
    form.querySelector('[data-total]').textContent = percent(total / 100);
    form.querySelector('[data-total-label]').textContent = percent(total / 100);
    form.querySelector('[data-balance]').textContent = !current.components.length ? 'Add components to reach 100%.' : !validWeights ? 'Each weight must be 0.01% to 100%, with up to two decimal places.' : balanced ? 'Balanced. Component weights total 100%.' : total < 10000 ? 'Add ' + percent((10000-total)/100) + ' to reach 100%.' : 'Reduce weights by ' + percent((total-10000)/100) + ' to reach 100%.';
    form.querySelector('[data-save]').disabled = current.busy || !balanced || !current.dirty;
    form.querySelector('[data-action="add"]').disabled = current.busy || current.components.length >= 30;
    form.querySelector('[data-save-state]').textContent = current.busy ? 'Please wait...' : current.dirty ? 'Unsaved changes' : current.record.version ? 'Saved / version ' + current.record.version : 'Not configured';
  }
  function dirty(period) { states[period].dirty = true; update(period); }
  function render(period, focus = null) {
    const current = states[period];
    forms[period].querySelector('[data-components]').innerHTML = current.components.length ? current.components.map((item,index) => {
      const id = 'gc' + item.uid;
      return `<article class="grading-component" data-component="${index}"><div class="grading-component-heading"><h4>Component ${index+1}</h4><div class="grading-component-controls"><button type="button" data-operation="up" aria-label="Move component ${index+1} up" ${index===0?'disabled':''}><i class="bx bx-up-arrow-alt" aria-hidden="true"></i></button><button type="button" data-operation="down" aria-label="Move component ${index+1} down" ${index===current.components.length-1?'disabled':''}><i class="bx bx-down-arrow-alt" aria-hidden="true"></i></button><button type="button" data-operation="remove" aria-label="Remove component ${index+1}"><i class="bx bx-trash" aria-hidden="true"></i></button></div></div>
        <div class="grading-component-fields"><div><label class="form-label" for="${id}name">Component <span aria-hidden="true">*</span></label><textarea class="form-control" data-field="name" id="${id}name" maxlength="160" rows="2" required placeholder="e.g. Written Assessments & Quizzes">${escape(item.name)}</textarea></div><div class="grading-component-weight"><label class="form-label" for="${id}weight">Weight <span aria-hidden="true">*</span></label><div class="input-group"><input type="number" class="form-control" data-field="weight" id="${id}weight" min="0.01" max="100" step="0.01" value="${escape(item.weight)}" required><span class="input-group-text">%</span></div></div><div class="grading-component-description"><label class="form-label" for="${id}description">Description <span class="text-muted">(optional)</span></label><textarea class="form-control" data-field="description" id="${id}description" maxlength="2000" rows="3" placeholder="Describe the activities or outputs included here.">${escape(item.description)}</textarea></div></div></article>`;
    }).join('') : '<div class="grading-empty">No components yet. Use Add component to create your grading breakdown for this subject.</div>';
    update(period);
    if (focus !== null) forms[period].querySelector(`[data-component="${focus}"] [data-field="name"]`)?.focus();
  }
  function setRecord(period, record) {
    states[period].record = record;
    states[period].components = record.components.map(item => ({...item,uid:++sequence}));
    states[period].dirty = false; render(period);
  }
  function busy(period, value) {
    const current = states[period]; current.busy = value;
    forms[period].setAttribute('aria-busy', String(value));
    if (value) forms[period].querySelectorAll('button,input,textarea').forEach(node => { node.dataset.wasDisabled = String(node.disabled); node.disabled = true; });
    else forms[period].querySelectorAll('[data-was-disabled]').forEach(node => { node.disabled = node.dataset.wasDisabled === 'true'; delete node.dataset.wasDisabled; });
    update(period);
  }
  async function api(action, payload = {}) {
    let response;
    try { response = await fetch(config.endpoint, {method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,payload,context:config.context,csrf_token:config.csrf_token})}); }
    catch { throw Error('The connection was interrupted. Your changes are still here; try again.'); }
    let data;
    try { data = await response.json(); } catch { throw Error('The workspace did not return a valid response. Your changes have been kept.'); }
    if (!response.ok || !data.ok) throw Error(data.message || 'The grading request could not be completed.');
    return data;
  }
  async function load() {
    if (loading) return; loading = true;
    const notice = document.getElementById('gradingLoadNotice'), retry = document.getElementById('gradingRetry');
    retry.hidden = true; notice.textContent = 'Loading grading components...';
    try {
      const data = await api('get');
      for (const period of Object.keys(labels)) setRecord(period, data.schemes[period]);
      document.getElementById('gradingPeriods').hidden = false; notice.hidden = true;
    } catch (error) { notice.textContent = error.message; retry.hidden = false; feedback.error(error.message,'Unable to load grading components'); }
    finally { loading = false; }
  }
  for (const period of Object.keys(labels)) {
    const form = root.querySelector(`[data-grading-period="${period}"]`); forms[period] = form;
    states[period] = {record:{version:0},components:[],dirty:false,busy:false};
    form.addEventListener('input', () => { if (states[period].busy) return; sync(period); dirty(period); });
    form.addEventListener('click', async event => {
      const button = event.target.closest('[data-action],[data-operation]');
      if (!button || states[period].busy) return;
      const current = states[period], action = button.dataset.action; sync(period);
      if (action === 'reload') {
        if (current.dirty && !await feedback.confirm({title:'Reload ' + labels[period] + '?',text:'Your unsaved changes in this period will be discarded.',confirmText:'Reload period',cancelText:'Keep editing'})) return;
        busy(period,true);
        try { const data = await api('get'); setRecord(period, data.schemes[period]); message(period,'Latest saved components loaded.'); }
        catch (error) { message(period,error.message,true); }
        finally { busy(period,false); }
        return;
      }
      if (action === 'add') {
        if (current.components.length>=30) return;
        current.components.unshift({component_id:0,name:'',description:'',weight:'',uid:++sequence});
        render(period,0); dirty(period); return;
      }
      const node = button.closest('[data-component]'); if (!node) return;
      const index = Number(node.dataset.component), operation = button.dataset.operation;
      if (operation === 'remove') {
        if (current.components[index].name && !await feedback.confirm({title:'Remove component?',text:'Remove this component from ' + labels[period] + '? Changes take effect when you save.',confirmText:'Remove component',danger:true})) return;
        current.components.splice(index,1);
      }
      if (operation === 'up' && index>0) [current.components[index-1],current.components[index]] = [current.components[index],current.components[index-1]];
      if (operation === 'down' && index<current.components.length-1) [current.components[index+1],current.components[index]] = [current.components[index],current.components[index+1]];
      render(period); dirty(period);
    });
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (states[period].busy) return;
      sync(period); const current = states[period];
      const payload = {grading_period:period,version:current.record.version,components:current.components.map(({uid,...item})=>item)};
      busy(period,true); message(period);
      try { const data = await api('save',payload); setRecord(period,data.scheme); message(period,labels[period] + ' grading system saved.'); }
      catch (error) { message(period,error.message,true); }
      finally { busy(period,false); }
    });
  }
  for (const form of Object.values(forms)) feedback.validation(form);
  document.getElementById('gradingRetry').addEventListener('click',load);
  const hasChanges = () => Object.values(states).some(period => period.dirty);
  feedback.guardNavigation(hasChanges,() => { for (const period of Object.values(states)) period.dirty = false; },'Your unsaved grading components will be discarded.');
  window.addEventListener('beforeunload',event => { if (hasChanges()) { event.preventDefault(); event.returnValue = ''; } });
  load();
})();
