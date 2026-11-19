(function () {
  'use strict';
  const root = document.getElementById('pds-preview');
  if (!root) return;
  const sections = Array.from(root.querySelectorAll('[data-pds-section]'));
  const steps = Array.from(root.querySelectorAll('[data-pds-step]'));
  const editor = document.getElementById('pds-editor');
  const review = document.getElementById('pds-review');
  const previous = root.querySelector('[data-pds-previous]');
  const next = root.querySelector('[data-pds-next]');
  const status = document.getElementById('pds-preview-status');
  const save = root.querySelector('[data-pds-save]');
  const dirtySections = new Set();
  const savingSections = new Set();
  const baselines = new Map();
  const notices = new Map();
  const listUpdates = new Map();
  let current = 0;

  // Object key order may differ between the DOM and JSON; record order must be preserved.
  function snapshot(value) {
    if (Array.isArray(value)) return '[' + value.map(snapshot).join(',') + ']';
    if (value && typeof value === 'object') return '{' + Object.keys(value).sort().map(key => JSON.stringify(key) + ':' + snapshot(value[key])).join(',') + '}';
    return JSON.stringify(value);
  }

  function readControls(scope, scalarsOnly = false) {
    const values = {};
    scope.querySelectorAll('[data-pds-control]').forEach(control => {
      if (scalarsOnly && control.closest('[data-pds-record]')) return;
      if (control.type === 'file') return;
      if (control.type === 'radio') { if (!(control.dataset.pdsKey in values)) values[control.dataset.pdsKey]=''; if (control.checked) values[control.dataset.pdsKey]=control.value; return; }
      values[control.dataset.pdsKey] = control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value;
    });
    return values;
  }

  function readSection(section) {
    const values = readControls(section, true);
    section.querySelectorAll('[data-pds-list]').forEach(list => {
      values[list.dataset.pdsList] = Array.from(list.querySelector('[data-pds-records]').children).map(record => readControls(record));
    });
    return values;
  }

  function applyValues(section, values) {
    function write(scope, data, scalarsOnly = false) {
      scope.querySelectorAll('[data-pds-control]').forEach(control => {
        if (scalarsOnly && control.closest('[data-pds-record]')) return;
        if (control.type === 'file') return;
        if (control.type === 'radio') control.checked = data[control.dataset.pdsKey] === control.value;
        else if (control.type === 'checkbox') control.checked = data[control.dataset.pdsKey] === '1';
        else control.value = data[control.dataset.pdsKey] ?? '';
      });
    }
    write(section, values, true);
    section.querySelectorAll('[data-pds-list]').forEach(list => {
      const rows = values[list.dataset.pdsList] || [];
      Array.from(list.querySelector('[data-pds-records]').children).forEach((record, index) => {
        if (!rows[index]) record.remove();
        else { write(record, rows[index]); updateRecord(record); record.querySelector('[data-pds-record-note]').textContent = 'Saved in PDS'; }
      });
      listUpdates.get(list)?.();
    });
  }

  function hasFileChanges(section) {
    return Array.from(section.querySelectorAll('input[type=file]')).some(control => control.files.length)
      || Boolean(section.querySelector('[data-pds-attachment-remove]:checked'));
  }

  function updateAttachments(section, files, submittedFiles) {
    section.querySelectorAll('[data-pds-file-slot]').forEach(container => {
      const slot=container.dataset.pdsFileSlot;
      const control=container.querySelector('input[type=file]');
      const remove=container.querySelector('[data-pds-attachment-remove]');
      const submitted=submittedFiles.get(slot);
      if (submitted && control.files[0] === submitted.file) control.value='';
      if (submitted && remove.checked === submitted.remove) remove.checked=false;
      const saved=container.querySelector('[data-pds-attachment-saved]');
      saved.hidden=!files[slot];
      container.querySelector('[data-pds-attachment-link]').textContent=files[slot]?.name || '';
    });
  }

  function updateStatus() {
    const section = sections[current];
    const key = section.dataset.pdsKey;
    const persistent = section.dataset.pdsPersistent === '1' && review.hidden;
    if (save) {
      save.hidden = !persistent;
      save.disabled = root.dataset.pdsSavingEnabled !== '1' || savingSections.has(key);
      save.textContent = savingSections.has(key) ? 'Saving…' : 'Save section';
    }
    if (!review.hidden) status.textContent = 'Review includes saved details and any unsaved edits';
    else if (!persistent) status.textContent = 'Preview only · Changes in this section are not saved';
    else if (root.dataset.pdsSavingEnabled !== '1') status.textContent = 'Saving unavailable · Reload before entering details';
    else if (savingSections.has(key)) status.textContent = 'Saving ' + section.dataset.pdsTitle + '…';
    else if (dirtySections.has(key)) status.textContent = notices.get(key) || 'Unsaved changes · Save this section when ready';
    else if (section.dataset.pdsSavedAt) status.textContent = 'Saved · ' + section.dataset.pdsSavedAt;
    else status.textContent = 'Ready to save · You can complete this section gradually';
  }

  function showSection(index, focus = true) {
    current = Math.max(0, Math.min(sections.length - 1, index));
    editor.hidden = false;
    review.hidden = true;
    sections.forEach((section, i) => { section.hidden = i !== current; });
    steps.forEach((step, i) => {
      if (i === current) step.setAttribute('aria-current', 'step');
      else step.removeAttribute('aria-current');
    });
    previous.disabled = current === 0;
    next.hidden = false;
    next.firstChild.textContent = current === sections.length - 1 ? 'Review PDS' : 'Next section';
    updateStatus();
    if (focus) {
      const heading = sections[current].querySelector('h3');
      heading.focus({preventScroll: true});
      sections[current].scrollIntoView({block: 'start', behavior: 'instant'});
    }
  }

  function updateConditions() {
    root.querySelectorAll('[data-pds-when]').forEach(container => {
      const [key, expected] = JSON.parse(container.dataset.pdsWhen);
      const scope = container.closest('[data-pds-record]') || root;
      const controls = Array.from(scope.querySelectorAll('[data-pds-key]')).filter(control => control.dataset.pdsKey === key);
      const control = controls.find(item => item.type !== 'radio' || item.checked);
      const matches = expected === 'unchecked' ? Boolean(control && !control.checked)
        : Boolean(control && !control.disabled && (control.type === 'checkbox' ? control.checked : control.value === expected));
      container.hidden = !matches;
      container.querySelectorAll('[data-pds-control]').forEach(input => { input.disabled = !matches; });
    });
  }

  function copyAddress() {
    const same = document.getElementById('pds-contact-same_address');
    if (!same) return;
    ['house', 'street', 'village', 'barangay', 'city', 'province', 'zip'].forEach(key => {
      const source = document.getElementById('pds-contact-residential_' + key);
      const target = document.getElementById('pds-contact-permanent_' + key);
      if (same.checked) target.value = source.value;
      target.readOnly = same.checked;
    });
  }

  function markChanged(section = sections[current]) {
    const key = section.dataset.pdsKey;
    if (section.dataset.pdsPersistent === '1' && snapshot(readSection(section)) === baselines.get(key) && !hasFileChanges(section)) dirtySections.delete(key);
    else dirtySections.add(key);
    notices.delete(key);
    const marker = root.querySelector('[data-pds-step-state="' + key + '"]');
    if (marker && section.dataset.pdsPersistent === '1') marker.textContent = dirtySections.has(key) ? 'Unsaved' : (section.dataset.pdsSavedAt ? 'Saved' : 'Draft');
    updateStatus();
  }

  function updateRecord(record) {
    const candidates = ['training_title', 'degree', 'institution', 'name', 'full_name', 'organization', 'position', 'surname'];
    const controls = Array.from(record.querySelectorAll('[data-pds-control]'));
    const title = candidates.map(key => controls.find(input => input.dataset.pdsKey === key)?.value.trim()).find(Boolean);
    record.querySelector('[data-pds-record-title]').textContent = title || record.closest('[data-pds-list]').dataset.pdsSingular;
  }

  root.querySelectorAll('[data-pds-list]').forEach(list => {
    const records = list.querySelector('[data-pds-records]');
    const template = list.querySelector('[data-pds-template]');
    let nextIndex = records.children.length;
    function updateCount() {
      const count = records.children.length;
      list.querySelector('[data-pds-list-count]').textContent = count;
      list.querySelector('[data-pds-empty]').hidden = count > 0;
      list.querySelector('[data-pds-add]').disabled = count >= Number(list.dataset.pdsLimit || Infinity);
    }
    listUpdates.set(list, updateCount);
    updateCount();
    list.querySelector('[data-pds-add]').addEventListener('click', () => {
      if (records.children.length >= Number(list.dataset.pdsLimit || Infinity)) return;
      const content = document.createElement('template');
      content.innerHTML = template.innerHTML.replaceAll('__PDS_INDEX__', String(nextIndex++));
      const record = content.content.firstElementChild;
      records.querySelectorAll('[data-pds-record]').forEach(item => { item.open = false; });
      records.append(record);
      updateCount();
      updateConditions();
      markChanged(list.closest('[data-pds-section]'));
      record.querySelector('[data-pds-control]')?.focus();
    });
    records.addEventListener('click', async event => {
      const button = event.target.closest('[data-pds-remove]');
      if (!button || button.disabled) return;
      const record=button.closest('[data-pds-record]');
      const title=record.querySelector('[data-pds-record-title]').textContent.trim();
      button.disabled=true;
      let confirmed=false;
      try { confirmed=await window.synkFacultyProfileFeedback?.confirmRemoval({title:'Remove this '+list.dataset.pdsSingular+'?',text:'Remove “'+title+'” from this section? Saved data changes only after Save section.'}); }
      finally { if(button.isConnected)button.disabled=false; }
      if(!confirmed || !record.isConnected){if(button.isConnected)button.focus({preventScroll:true});return;}
      record.remove();
      updateCount();
      clearErrors(list.closest('[data-pds-section]'));
      markChanged(list.closest('[data-pds-section]'));
      list.querySelector('[data-pds-add]').focus({preventScroll: true});
    });
  });

  root.addEventListener('input', event => {
    const control = event.target.closest('[data-pds-control]');
    if (!control) return;
    if (control.type === 'file' && control.files.length) control.closest('[data-pds-file-slot]').querySelector('[data-pds-attachment-remove]').checked=false;
    copyAddress();
    markChanged(control.closest('[data-pds-section]'));
    const record = control.closest('[data-pds-record]');
    if (record) {
      updateRecord(record);
      record.querySelector('[data-pds-record-note]').textContent = control.closest('[data-pds-section]').dataset.pdsPersistent === '1' ? 'Unsaved changes' : 'Edited in preview';
    }
  });
  root.addEventListener('change', async event => {
    if (event.target.matches('[data-pds-attachment-remove]')) {
      const remove=event.target;
      if(remove.checked){
        const container=remove.closest('[data-pds-file-slot]');
        const file=container.querySelector('input[type=file]'); const originalFile=file.files[0];
        const name=container.querySelector('[data-pds-attachment-link]').textContent.trim();
        remove.checked=false; remove.disabled=true;
        let confirmed=false;
        try { confirmed=await window.synkFacultyProfileFeedback?.confirmRemoval({title:'Remove this saved file?',text:'Mark “'+name+'” for removal? It will be deleted when you save this section.'}); }
        finally { if(remove.isConnected)remove.disabled=false; }
        if(!confirmed || !remove.isConnected || file.files[0]!==originalFile){if(remove.isConnected)remove.focus({preventScroll:true});return;}
        remove.checked=true; file.value='';
      }
      markChanged(event.target.closest('[data-pds-section]')); return;
    }
    const control = event.target.closest('[data-pds-control]');
    if (!control) return;
    updateConditions();
    copyAddress();
    markChanged(control.closest('[data-pds-section]'));
  });

  function clearErrors(section) {
    const summary = section.querySelector('.pds-section-errors');
    if (summary) { summary.hidden = true; summary.replaceChildren(); }
    section.querySelectorAll('[data-pds-error]').forEach(error => error.remove());
    section.querySelectorAll('[aria-invalid]').forEach(control => {
      control.removeAttribute('aria-invalid');
      control.classList.remove('is-invalid');
      const description = (control.getAttribute('aria-describedby') || '').split(' ').filter(id => !id.endsWith('-error'));
      if (description.length) control.setAttribute('aria-describedby', description.join(' '));
      else control.removeAttribute('aria-describedby');
    });
  }

  function showErrors(section, message, errors = {}) {
    const summary = section.querySelector('.pds-section-errors');
    summary.textContent = message;
    summary.hidden = false;
    Object.entries(errors).forEach(([key, message]) => {
      const parts = key.split('.');
      const list = parts.length > 1 ? Array.from(section.querySelectorAll('[data-pds-list]')).find(item => item.dataset.pdsList === parts[0]) : null;
      const scope = list ? list.querySelector('[data-pds-records]').children[Number(parts[1])] : section;
      const control = scope && Array.from(scope.querySelectorAll('[data-pds-control]')).find(field => field.dataset.pdsKey === (parts[2] || key));
      if (!control || control.disabled) {
        const detail = document.createElement('p'); detail.textContent = message; summary.append(detail);
        return;
      }
      let disclosure = control.closest('details');
      while (disclosure && section.contains(disclosure)) { disclosure.open = true; disclosure = disclosure.parentElement.closest('details'); }
      const error = document.createElement('small');
      error.id = control.id + '-error'; error.dataset.pdsError = '1'; error.className = 'invalid-feedback'; error.textContent = message;
      control.classList.add('is-invalid'); control.setAttribute('aria-invalid', 'true');
      control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
      control.after(error);
    });
    if (section === sections[current] && review.hidden) {
      summary.focus({preventScroll: true}); summary.scrollIntoView({block: 'center', behavior: 'instant'});
    }
  }

  async function saveSection() {
    const section = sections[current]; const key = section.dataset.pdsKey;
    if (section.dataset.pdsPersistent !== '1' || root.dataset.pdsSavingEnabled !== '1' || savingSections.has(key)) return;
    clearErrors(section);
    copyAddress();
    const submitted = readSection(section);
    const submittedFiles = new Map();
    const removed=[]; let totalBytes=0;
    section.querySelectorAll('[data-pds-file-slot]').forEach(container => {
      const slot=container.dataset.pdsFileSlot; const file=container.querySelector('input[type=file]').files[0];
      const remove=container.querySelector('[data-pds-attachment-remove]').checked;
      submittedFiles.set(slot,{file,remove});
      if (file) totalBytes+=file.size;
      if (remove) removed.push(slot);
    });
    const oversized=Array.from(submittedFiles).filter(([,item])=>item.file?.size>2097152);
    if (oversized.length || totalBytes>6291456) {
      showErrors(section,'Choose files up to 2 MB each and upload up to 6 MB per save.',Object.fromEntries(oversized.map(([slot])=>[slot,'Choose a file up to 2 MB.']))); return;
    }
    const payload={csrf_token:root.dataset.pdsCsrf,owner:root.dataset.pdsOwner,section:key,revision:Number(section.dataset.pdsRevision),values:submitted};
    if (key==='certification') payload.removed_attachments=removed;
    const headers={'Accept':'application/json'};
    let body;
    if (Array.from(submittedFiles.values()).some(item=>item.file)) {
      body=new FormData(); body.append('payload',JSON.stringify(payload));
      submittedFiles.forEach((item,slot)=>{if (item.file) body.append(slot,item.file);});
    } else { headers['Content-Type']='application/json'; body=JSON.stringify(payload); }
    savingSections.add(key); updateStatus();
    try {
      const response = await fetch(root.dataset.pdsSaveUrl, {
        method:'POST',credentials:'same-origin',headers,body
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        notices.set(key, 'Not saved · Check the section message');
        const unchanged = snapshot(readSection(section)) === snapshot(submitted);
        showErrors(section, (result.message || 'The section was not saved. Your entries are still here.')
          + (unchanged ? '' : ' You have made newer edits. Save again to check them.'), unchanged ? (result.errors || {}) : {});
        return;
      }
      section.dataset.pdsRevision = String(result.revision);
      section.dataset.pdsSavedAt = result.updated_at;
      // Preserve any edits made while the save request was in flight.
      if (snapshot(readSection(section)) === snapshot(submitted)) applyValues(section, result.values);
      if (key==='certification') updateAttachments(section,result.attachments || {},submittedFiles);
      updateConditions(); copyAddress();
      const changed = snapshot(readSection(section)) !== snapshot(result.values) || hasFileChanges(section);
      if (changed) dirtySections.add(key); else dirtySections.delete(key);
      notices.delete(key);
      const marker = root.querySelector('[data-pds-step-state="' + key + '"]');
      if (marker) marker.textContent = changed ? 'Unsaved' : 'Saved';
      baselines.set(key, snapshot(result.values));
      window.synkFacultyProfileFeedback?.success(section.dataset.pdsTitle + ' saved successfully.'
        + (changed ? ' Newer edits still need saving.' : ''));
    } catch (error) {
      notices.set(key, 'Save not confirmed · Your entries are still here');
      showErrors(section, 'We could not confirm the save. Check your connection and try again. Your entries are still here.');
    } finally {
      savingSections.delete(key); updateStatus();
    }
  }
  if (save) save.addEventListener('click', saveSection);

  function showReview() {
    const content = root.querySelector('[data-pds-review-content]');
    content.replaceChildren();
    sections.forEach((section, index) => {
      const group = document.createElement('section');
      group.className = 'pds-review-section';
      const header = document.createElement('div');
      const heading = document.createElement('h4');
      heading.textContent = section.dataset.pdsTitle;
      const edit = document.createElement('button');
      edit.type = 'button'; edit.className = 'pds-review-edit'; edit.textContent = 'Edit';
      edit.setAttribute('aria-label', 'Edit ' + section.dataset.pdsTitle);
      edit.addEventListener('click', () => showSection(index));
      header.append(heading, edit); group.append(header);
      const photoGuidance=section.querySelector('[data-pds-photo-guidance]');
      if(photoGuidance)group.append(photoGuidance.cloneNode(true));
      const buckets = new Map();
      const radioNames = new Set();
      section.querySelectorAll('[data-pds-control]').forEach(control => {
        if (control.disabled || control.closest('fieldset[disabled]')) return;
        let value = control.value.trim();
        if (control.type === 'radio') {
          if (radioNames.has(control.name)) return;
          radioNames.add(control.name);
          value = Array.from(section.querySelectorAll('input[type=radio]')).find(input => input.name === control.name && input.checked)?.value || 'Not answered';
        } else if (control.type === 'checkbox') {
          if (!control.checked) return;
          value = 'Selected';
        } else if (control.type === 'file') {
          value = Array.from(control.files).map(file => file.name).join(', ');
          const container=control.closest('[data-pds-file-slot]');
          const saved=container?.querySelector('[data-pds-attachment-saved]');
          if (!value && saved && !saved.hidden && !container.querySelector('[data-pds-attachment-remove]').checked) value=container.querySelector('[data-pds-attachment-link]').textContent;
        } else if (control.type === 'date' && value) {
          value = value.split('-').reverse().join('/');
        }
        if (!value) return;
        const term = document.createElement('dt');
        const entry = document.createElement('dd');
        const record = control.closest('[data-pds-record]');
        const scope = record || control.closest('.pds-group') || section;
        if (!buckets.has(scope)) {
          const subgroup = document.createElement('div');
          subgroup.className = 'pds-review-subgroup';
          const title = record ? record.querySelector('[data-pds-record-title]')
            : scope.querySelector(':scope > legend, :scope > summary');
          if (title) {
            const label = document.createElement('h5');
            label.textContent = title.textContent;
            subgroup.append(label);
          }
          const details = document.createElement('dl');
          subgroup.append(details); group.append(subgroup);
          buckets.set(scope, details);
        }
        term.textContent = control.dataset.pdsLabel;
        entry.textContent = value;
        buckets.get(scope).append(term, entry);
      });
      if (!buckets.size) {
        const empty = document.createElement('p');
        empty.textContent = 'No information entered in this section yet.';
        group.append(empty);
      }
      content.append(group);
    });
    editor.hidden = true;
    review.hidden = false;
    steps.forEach(step => step.removeAttribute('aria-current'));
    previous.disabled = false;
    next.hidden = true;
    updateStatus();
    document.getElementById('pds-review-title').focus({preventScroll: true});
    review.scrollIntoView({block: 'start', behavior: 'instant'});
  }

  steps.forEach(step => step.addEventListener('click', () => showSection(Number(step.dataset.pdsStep))));
  previous.addEventListener('click', () => showSection(review.hidden ? current - 1 : current));
  next.addEventListener('click', () => current === sections.length - 1 ? showReview() : showSection(current + 1));
  root.querySelectorAll('[data-pds-review]').forEach(button => button.addEventListener('click', showReview));
  root.querySelector('[data-pds-edit]').addEventListener('click', () => showSection(current));
  root.querySelector('[data-pds-profile]').addEventListener('click', () => document.querySelector('[data-profile-tab="profile"]').click());
  window.addEventListener('beforeunload', event => {
    if (!dirtySections.size && !savingSections.size) return;
    event.preventDefault(); event.returnValue = '';
  });
  function revealHash() {
    const index = sections.findIndex(section => '#' + section.id === location.hash);
    if (index >= 0) showSection(index, false);
  }
  window.addEventListener('hashchange', revealHash);
  updateConditions();
  copyAddress();
  sections.filter(section => section.dataset.pdsPersistent === '1').forEach(section => {
    baselines.set(section.dataset.pdsKey, snapshot(readSection(section)));
  });
  showSection(0, false);
  revealHash();
})();
