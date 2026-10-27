'use strict';
(() => {
  const configElement = document.getElementById('management-config');
  if (!configElement) return;
  const config = JSON.parse(configElement.textContent);
  const $ = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[ch]);
  async function request(action, values = {}) {
    const body = new URLSearchParams({ action, csrf_token:config.csrf_token, college_id:config.college_id,
      campus_id:config.campus_id, organization_id:config.organization_id, term:config.term, exam_term:config.exam_term,
      current_term:config.current_term, current_exam_term:config.current_exam_term,
      current_period_version:config.current_period_version, ...values });
    const response = await fetch(config.endpoint, { method:'POST', body, credentials:'same-origin', headers:{ Accept:'application/json' } });
    let data;
    try { data = await response.json(); } catch (_) { throw Error('Unable to read the server response. Reload the page and try again.'); }
    if (!response.ok || data.status !== 'ok') throw Error(data.message || 'Unable to complete the request.');
    return data;
  }
  function notice(element, message) { element.textContent = message; element.hidden = !message; }
  $('management-current-period-form')?.addEventListener('submit', async event => {
    event.preventDefault();
    const button=$('management-current-period-save'), select=$('management-current-exam-term');
    if (button.disabled) return;
    button.disabled=true; select.disabled=true;
    notice($('management-current-period-error'),'');
    try {
      const data=await request('save_management_term',{exam_term:select.value});
      const url=new URL(location.href); url.searchParams.set('term',config.current_term); url.searchParams.set('exam_term',data.current_exam_term);
      ['view_ay_id','view_semester','page'].forEach(key=>url.searchParams.delete(key));
      location.assign(url.href);
    } catch (error) { notice($('management-current-period-error'),error.message); }
    finally { button.disabled=false; select.disabled=false; }
  });
  const flashKey = `synk-management-${config.organization_id}-${config.page}`;
  function complete(message, firstPage = false) {
    try { sessionStorage.setItem(flashKey,message); } catch (_) { /* Storage may be disabled. */ }
    if (firstPage) { const url=new URL(location.href); url.searchParams.delete('page'); location.assign(url.href); }
    else location.reload();
  }
  const pageNotice = $('management-page-notice');
  if (pageNotice) {
    try { const message = sessionStorage.getItem(flashKey); if (message) { notice(pageNotice,message); sessionStorage.removeItem(flashKey); } } catch (_) { /* Optional feedback storage. */ }
  }
  const automaticFilters = config.page === 'students' ? $('management-student-filters') : $('management-catalog-filters');
  if (['contributions','events','students'].includes(config.page) && automaticFilters) {
    let searchTimer;
    const applyFilters = () => { clearTimeout(searchTimer); automaticFilters.requestSubmit(); };
    automaticFilters.querySelectorAll('select').forEach(select => select.addEventListener('change',applyFilters));
    const search = $(config.page === 'students' ? 'student-search' : 'catalog-search');
    const queueSearch = event => {
      clearTimeout(searchTimer);
      if (!event.isComposing) searchTimer = setTimeout(applyFilters,400);
    };
    search.addEventListener('input',queueSearch);
    search.addEventListener('compositionend',queueSearch);
    automaticFilters.addEventListener('submit',() => clearTimeout(searchTimer));
  }
  const catalog = $('management-catalog');
  if (catalog) {
    const isEvents = catalog.dataset.kind === 'events', isViolation = catalog.dataset.kind === 'violations';
    const kind = catalog.dataset.kind, label = isViolation ? 'Violation' : (isEvents ? 'Event' : 'Contribution');
    const recordKey = isViolation ? 'violation_id' : (isEvents ? 'event_id' : 'contribution_id');
    const form = $('management-record-form'), fields = $('management-editor-fields');
    const editorElement = $('management-editor'), deleteElement = $('management-delete-modal');
    const editor = bootstrap.Modal.getOrCreateInstance(editorElement), deletion = bootstrap.Modal.getOrCreateInstance(deleteElement);
    const saveButton = $('management-record-save'), deleteButton = $('management-delete-confirm');
    let busy = false, generation = 0, deleteRecord = null, editorOpener = null, deleteOpener = null;
    function lock(value) {
      busy = value; fields.disabled = value; saveButton.disabled = value;
      editorElement.querySelectorAll('[data-bs-dismiss]').forEach(button => { button.disabled = value; });
      editorElement.setAttribute('aria-busy', String(value));
    }
    editorElement.addEventListener('hide.bs.modal', event => { if (busy) event.preventDefault(); else generation++; });
    editorElement.addEventListener('hidden.bs.modal',()=>editorOpener?.focus());
    deleteElement.addEventListener('hidden.bs.modal',()=>deleteOpener?.focus());
    function fill(record) {
      form.reset();
      form.elements.record_id.value = record?.[recordKey] || '';
      form.elements.version.value = record?.version || '';
      for (const element of form.elements) {
        if (!element.name || ['record_id','version','record_term'].includes(element.name)) continue;
        if (record && Object.hasOwn(record,element.name)) element.value = record[element.name] ?? '';
      }
      form.elements.record_term.value = record ? (record.ay_id && record.semester ? `${record.ay_id}:${record.semester}` : '') : config.current_term;
      if (isViolation) return;
      form.elements.organization_id.value = config.organization_id;
      if (!isEvents) for (const option of form.elements.event_id.options) {
        if (option.value) option.hidden = option.disabled = option.dataset.term !== form.elements.record_term.value;
      }
      if (!record) {
        form.elements[isEvents ? 'event_date' : 'contribution_date'].value = config.today;
        form.elements.status.value = 'ACTIVE'; form.elements.audience_mode.value = 'ALL';
      }
    }
    async function openEditor(id) {
      if (busy) return;
      editorOpener=document.activeElement;
      const current = ++generation;
      notice($('management-editor-error'),''); fill(null);
      $('management-editor-title').textContent = `${id ? 'Edit' : 'Add'} ${label}`;
      editor.show();
      if (!id) { setTimeout(() => $('record-title').focus(),250); return; }
      lock(true);
      try {
        const data = await request('get',{ kind, record_id:id });
        if (current === generation) fill(data.record);
      } catch (error) { notice($('management-editor-error'),error.message); }
      finally {
        lock(false);
        // A failed load must never turn Edit into an accidental Add.
        if (id && !form.elements.record_id.value) { fields.disabled = true; saveButton.disabled = true; }
      }
    }
    $('management-add').addEventListener('click',()=>{ fields.disabled=false; saveButton.disabled=false; openEditor(null); });
    form.addEventListener('submit',async event=>{
      event.preventDefault(); if (busy || !form.reportValidity()) return;
      const values = Object.fromEntries(new FormData(form));
      notice($('management-editor-error'),''); lock(true); saveButton.textContent = 'Saving…';
      try { const result = await request('save',{ kind, ...values }); complete(result.message,!isViolation && !values.record_id); }
      catch (error) { notice($('management-editor-error'),error.message); $('management-editor-error').scrollIntoView({ block:'nearest' }); }
      finally { lock(false); saveButton.textContent = `Save ${label}`; }
    });
    let deleteBusy = false;
    function lockDelete(value) { deleteBusy=value; deleteElement.querySelectorAll('button').forEach(button=>{ button.disabled=value; }); }
    deleteElement.addEventListener('hide.bs.modal',event=>{ if (deleteBusy) event.preventDefault(); });
    async function openDelete(id) {
      if (deleteBusy) return;
      deleteOpener=document.activeElement;
      deleteRecord = null; notice($('management-delete-error'),'');
      $('management-delete-name').textContent = 'Loading…'; deleteButton.disabled = true; deletion.show(); lockDelete(true);
      try {
        const data = await request('get',{ kind,record_id:id }); deleteRecord=data.record;
        $('management-delete-name').textContent = isViolation ? deleteRecord.violation_type : deleteRecord.title;
        const linked = isEvents ? Number(deleteRecord.linked_contributions) : 0;
        $('management-delete-explanation').textContent = linked
          ? `This event is linked to ${linked} contribution(s). Remove those event links first, or set the event status to Inactive.`
          : `This permanently removes the ${label.toLowerCase()}${isEvents ? ` and its ${deleteRecord.sessions.length} session(s)` : ''}.`;
        lockDelete(false); deleteButton.disabled = linked > 0;
      } catch (error) { notice($('management-delete-error'),error.message); lockDelete(false); deleteButton.disabled=true; }
    }
    catalog.addEventListener('click',event=>{
      const edit = event.target.closest('[data-edit]'), remove = event.target.closest('[data-delete]');
      if (edit) openEditor(edit.dataset.edit);
      if (remove) openDelete(remove.dataset.delete);
    });
    deleteButton.addEventListener('click',async()=>{
      if (!deleteRecord || deleteBusy) return;
      notice($('management-delete-error'),''); lockDelete(true);
      try { const result = await request('delete',{ kind,record_id:deleteRecord[recordKey],version:deleteRecord.version }); complete(result.message); }
      catch (error) { notice($('management-delete-error'),error.message); }
      finally { lockDelete(false); }
    });
  }
  const studentForm = $('management-student-filters');
  if (studentForm) {
    const modalElement = $('management-student-detail'), modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    let generation = 0, detailOpener = null;
    modalElement.addEventListener('hide.bs.modal',()=>{ generation++; });
    modalElement.addEventListener('hidden.bs.modal',()=>detailOpener?.focus());
    document.addEventListener('click',async event=>{
      const button = event.target.closest('[data-student]'); if (!button) return;
      detailOpener=button;
      const current = ++generation;
      $('management-student-detail-title').textContent = 'Financial overview';
      $('management-student-detail-content').textContent = 'Loading financial overview…'; modal.show();
      try {
        const data = await request('student_detail',{ directory_term:config.directory_term,student_id:button.dataset.student });
        if (current !== generation) return;
        $('management-student-detail-content').innerHTML = window.SynkManagementFinancial.render(data);
      } catch (error) { if (current === generation) $('management-student-detail-content').innerHTML = `<div class="alert alert-danger" role="alert">${escape(error.message)}</div>`; }
    });
  }
})();
