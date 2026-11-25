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
  const collections = $('management-collections');
  if (collections) {
    const searchForm = collections.querySelector('[data-collection-search]');
    const searchInput = searchForm.elements.search;
    const matches = collections.querySelector('[data-collection-matches]');
    const body = collections.querySelector('.modal-body');
    const list = collections.querySelector('[data-collection-rows]');
    const summary = collections.querySelector('[data-collection-summary]');
    const state = collections.querySelector('[data-collection-state]');
    const failure = collections.querySelector('[data-collection-error]');
    const pagination = collections.querySelector('[data-collection-pagination]');
    const previous = pagination.querySelector('[data-collection-previous]');
    const next = pagination.querySelector('[data-collection-next]');
    const pesos = new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP'});
    const money = value => pesos.format(Number(value));
    const count = (number, label) => `${Number(number).toLocaleString('en-PH')} ${label}${Number(number) === 1 ? '' : 's'}`;
    const period = config.current_period;
    let generation = 0, page = 1;
    let searchTimer;

    async function loadPage(requestedPage) {
      clearTimeout(searchTimer);
      const run = ++generation;
      const search = searchInput.value.trim();
      page = requestedPage;
      list.replaceChildren();
      pagination.hidden = failure.hidden = true;
      body.scrollTop = 0;
      notice(matches,'');
      notice(state,'Loading student payments…');
      try {
        const data = await request('collection_students',{ collection_period:period, page:requestedPage, search });
        if (run !== generation) return;
        page = data.pagination.page;
        summary.querySelector('[data-collection-total]').textContent = money(data.total_collected);
        summary.querySelector('[data-collection-payers]').textContent = count(data.total_students,'student');
        summary.hidden = false;
        if (search && data.rows.length) notice(matches,`${count(data.pagination.total,'student')} found`);
        const emptyMessage = search ? 'No students with payments match your search.' : 'No student payments have been recorded for this period.';
        notice(state,data.rows.length ? '' : emptyMessage);
        list.innerHTML = data.rows.map(row => `
          <details class="management-collections-student" data-collection-student="${escape(row.student_id)}">
            <summary>
              <span><strong>${escape(row.student_name)}</strong><small>${escape(row.student_number)} · <span data-collection-receipt-count>${count(row.receipt_count,'receipt')}</span></small></span>
              <span class="management-collections-paid"><strong data-collection-student-total>${money(row.total_paid)}</strong><small>Total paid</small></span>
              <i class="bx bx-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="management-collections-detail" data-collection-detail></div>
          </details>`).join('');
        if (data.pagination.pages > 1) {
          pagination.querySelector('[data-collection-page]').textContent = `Page ${page} of ${data.pagination.pages}`;
          previous.disabled = page <= 1;
          next.disabled = page >= data.pagination.pages;
          pagination.hidden = false;
        }
      } catch (error) {
        if (run !== generation) return;
        notice(state,'');
        failure.querySelector('p').textContent = error.message;
        failure.hidden = false;
      }
    }

    async function loadPayments(student) {
      const detail = student.querySelector('[data-collection-detail]');
      if (student.dataset.loaded || detail.getAttribute('aria-busy') === 'true') return;
      detail.setAttribute('aria-busy','true');
      detail.innerHTML = '<p class="mb-0" role="status">Loading receipts…</p>';
      try {
        const data = await request('collection_student_payments',{
          collection_period:period, student_id:student.dataset.collectionStudent
        });
        if (!student.isConnected) return;
        const categories = data.categories.length ? `<dl class="management-collections-categories">${data.categories.map(category =>
          `<div><dt>${escape(category.label)}</dt><dd>${money(category.amount)}</dd></div>`).join('')}</dl>` : '';
        const receipts = data.receipts.map(receipt => `
          <li>
            <div class="management-collections-receipt-heading">
              <div><strong>Receipt ${escape(receipt.receipt_number)}</strong><small>${escape(receipt.recorded_at)}${receipt.recorded_by ? ` · ${escape(receipt.recorded_by)}` : ''}</small></div>
              <strong class="management-collections-paid">${money(receipt.amount)}</strong>
              <a href="payment-receipt.php?payment_id=${encodeURIComponent(receipt.payment_id)}" target="_blank" rel="noopener">View receipt</a>
            </div>
            ${receipt.items.length ? `<ul class="management-collections-items">${receipt.items.map(item =>
              `<li><span>${escape(item.title)}</span><strong>${money(item.amount)}</strong></li>`).join('')}</ul>`
              : '<small class="mt-2">No itemized allocations on this receipt.</small>'}
          </li>`).join('');
        detail.innerHTML = `${categories}<ul class="management-collections-receipts" aria-label="Payment receipts">${receipts}</ul>`;
        student.querySelector('[data-collection-student-total]').textContent = money(data.total_paid);
        student.querySelector('[data-collection-receipt-count]').textContent = count(data.receipts.length,'receipt');
        student.dataset.loaded = 'true';
      } catch (error) {
        if (student.isConnected) detail.innerHTML = `<p class="mb-2 text-danger" role="alert">${escape(error.message)}</p>
          <button type="button" class="btn btn-sm btn-outline-danger" data-collection-student-retry>Try again</button>`;
      } finally { detail.removeAttribute('aria-busy'); }
    }

    collections.addEventListener('show.bs.modal',() => {
      searchForm.reset();
      summary.hidden = true;
      loadPage(1);
    });
    collections.addEventListener('hide.bs.modal',() => {
      clearTimeout(searchTimer);
      generation++;
      list.replaceChildren();
    });
    function queueCollectionsSearch(event) {
      clearTimeout(searchTimer);
      if (event.isComposing) return;
      generation++;
      pagination.hidden = failure.hidden = true;
      notice(matches,'');
      searchTimer = setTimeout(() => loadPage(1), 350);
    }
    searchInput.addEventListener('input',queueCollectionsSearch);
    searchInput.addEventListener('compositionend',queueCollectionsSearch);
    searchForm.addEventListener('submit',event => {
      event.preventDefault();
      loadPage(1);
    });
    function navigateCollections(requestedPage) {
      body.focus({preventScroll:true});
      loadPage(requestedPage);
    }
    previous.addEventListener('click',() => navigateCollections(page - 1));
    next.addEventListener('click',() => navigateCollections(page + 1));
    failure.querySelector('[data-collection-retry]').addEventListener('click',() => navigateCollections(page));
    list.addEventListener('toggle',event => {
      if (event.target.matches('[data-collection-student]') && event.target.open) loadPayments(event.target);
    },true);
    list.addEventListener('click',event => {
      const retry = event.target.closest('[data-collection-student-retry]');
      if (retry) {
        const student = retry.closest('[data-collection-student]');
        student.querySelector('summary').focus({preventScroll:true});
        loadPayments(student);
      }
    });
  }
  const automaticFilters = config.page === 'students' ? $('management-student-filters') : $('management-catalog-filters');
  if (['contributions','events','flag_raising','students'].includes(config.page) && automaticFilters) {
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
    const kind = catalog.dataset.kind, flagRaising = kind === 'flag_raising';
    const isEvents = kind === 'events' || flagRaising, isViolation = kind === 'violations';
    const label = flagRaising ? 'Flag Raising Ceremony' : (isViolation ? 'Violation' : (isEvents ? 'Event' : 'Contribution'));
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
        if (flagRaising) form.elements.title.value = 'Flag Raising Ceremony';
        form.elements[isEvents ? 'event_date' : 'contribution_date'].value = config.today;
        form.elements.status.value = 'ACTIVE'; form.elements.audience_mode.value = 'ALL';
      }
    }
    async function openEditor(id, imported = false) {
      if (busy) return;
      editorOpener=document.activeElement;
      const current = ++generation;
      notice($('management-editor-error'),''); fill(null);
      if (isEvents) {
        form.elements.event_date.readOnly = imported;
        form.elements.status.disabled = imported;
      }
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
    const eventConfirmation = $('management-delete-event-confirmation');
    function syncDeleteButton() {
      const unavailable = deleteBusy || !deleteRecord || Boolean(deleteRecord.delete_block);
      if (eventConfirmation) eventConfirmation.disabled = unavailable;
      deleteButton.disabled = unavailable || Boolean(eventConfirmation && !eventConfirmation.checked);
    }
    function lockDelete(value) {
      deleteBusy = value;
      deleteElement.querySelectorAll('[data-bs-dismiss]').forEach(button => { button.disabled = value; });
      syncDeleteButton();
    }
    eventConfirmation?.addEventListener('change', syncDeleteButton);
    deleteElement.addEventListener('hide.bs.modal',event=>{ if (deleteBusy) event.preventDefault(); });
    async function openDelete(id) {
      if (deleteBusy) return;
      deleteOpener=document.activeElement;
      deleteRecord = null; notice($('management-delete-error'),'');
      if (eventConfirmation) eventConfirmation.checked = false;
      $('management-delete-explanation').textContent = '';
      $('management-delete-name').textContent = 'Loading…'; deletion.show(); lockDelete(true);
      try {
        const data = await request('get',{kind,record_id:id,for_delete:'1'}); deleteRecord=data.record;
        $('management-delete-name').textContent = isViolation ? deleteRecord.violation_type : deleteRecord.title;
        if (isEvents) $('management-delete-name').textContent += ` (${deleteRecord.event_date})`;
        const linked = isEvents ? Number(deleteRecord.linked_contributions) : 0;
        let explanation = `This permanently removes the ${label.toLowerCase()}.`;
        if (isEvents) {
          const subject = flagRaising ? 'ceremony' : 'event';
          explanation = `This permanently removes the ${subject}, its roster, attendance records, exemptions, and sessions.`;
          if (linked) explanation += ` It also removes ${linked} linked contribution${linked === 1 ? '' : 's'} and their exemptions.`;
        }
        $('management-delete-explanation').textContent = deleteRecord.delete_block || explanation;
      } catch (error) { notice($('management-delete-error'),error.message); }
      finally { lockDelete(false); }
    }
    catalog.addEventListener('click',event=>{
      const edit = event.target.closest('[data-edit]'), remove = event.target.closest('[data-delete]');
      if (edit) openEditor(edit.dataset.edit,edit.hasAttribute('data-edit-import'));
      if (remove) openDelete(remove.dataset.delete);
    });
    deleteButton.addEventListener('click',async()=>{
      if (deleteButton.disabled) return;
      const values = {kind,record_id:deleteRecord[recordKey],version:deleteRecord.version};
      if (eventConfirmation?.checked) values.confirm = '1';
      notice($('management-delete-error'),''); lockDelete(true);
      try { const result = await request('delete',values); complete(result.message); }
      catch (error) { notice($('management-delete-error'),error.message); }
      finally { lockDelete(false); }
    });
  }
  const studentForm = $('management-student-filters');
  if (studentForm) {
    const modalElement = $('management-student-detail'), modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const content = $('management-student-detail-content');
    const reminderElement = $('management-evaluation-reminder');
    const reminder = bootstrap.Modal.getOrCreateInstance(reminderElement);
    const continueButton = $('management-evaluation-reminder-open');
    const receiptKey = `synk-management-next-receipt-${config.organization_id}-${config.user_id}`;
    let nextReceipt = { paymentId: 0, number: '' };
    try {
      const stored = JSON.parse(sessionStorage.getItem(receiptKey));
      if (Number.isSafeInteger(stored?.paymentId) && typeof stored.number === 'string' && /^\d{0,40}$/.test(stored.number)) nextReceipt = stored;
    } catch (_) { /* The browser may restrict storage. */ }
    function rememberReceipt(receipt) {
      const paymentId = Number(receipt?.payment_id);
      const number = String(receipt?.receipt_number ?? '');
      if (!Number.isSafeInteger(paymentId) || paymentId <= nextReceipt.paymentId || !/^\d{1,40}$/.test(number)) return;
      const incremented = (BigInt(number) + 1n).toString().padStart(number.length, '0');
      nextReceipt = { paymentId, number: incremented.length <= 40 ? incremented : '' };
      try { sessionStorage.setItem(receiptKey,JSON.stringify(nextReceipt)); }
      catch (_) { /* Keep the suggestion in memory on this page. */ }
    }
    let generation = 0, detailOpener = null, evaluationReview = null;
    function returnToStudent() {
      detailOpener?.focus();
      detailOpener = null;
    }
    modalElement.addEventListener('hide.bs.modal',()=>{ generation++; });
    modalElement.addEventListener('hidden.bs.modal',returnToStudent);

    function fetchOverview(button) {
      return request('student_detail',{directory_term:config.directory_term,student_id:button.dataset.student});
    }
    function showOverviewError(error) {
      content.innerHTML = `<div class="alert alert-danger" role="alert">${escape(error.message)}</div>`;
      modal.show();
    }
    function openOverview(button, data) {
      const balance = button.closest('tr')?.querySelector('[data-student-balance]');
      const current = ++generation;
      function show(detail,saved=null) {
        if (current !== generation) return;
        if (balance) window.SynkManagementFinancial.updateDirectoryBalance(balance,detail.financial.summary);
        content.innerHTML=window.SynkManagementFinancial.render(detail,saved);
        window.SynkManagementFinancial.bind(detail,content,request,async receipt=>{
          rememberReceipt(receipt);
          show(await fetchOverview(button),receipt);
        },()=>nextReceipt.number);
        if (saved && !saved.receipt_number) {
          const notice = document.createElement('div');
          notice.className = 'alert alert-success';
          notice.setAttribute('role','status');
          notice.textContent = saved.message || 'Student account updated.';
          content.querySelector('.management-financial-main').prepend(notice);
        }
      }
      try {
        show(data);
        modal.show();
      } catch (error) { showOverviewError(error); }
    }
    reminderElement.addEventListener('shown.bs.modal',()=>{
      continueButton.disabled = false;
      $('management-evaluation-reminder-back').focus();
    });
    continueButton.addEventListener('click',()=>{
      if (!evaluationReview) return;
      evaluationReview.confirmed = true;
      continueButton.disabled = true;
      reminder.hide();
    });
    reminderElement.addEventListener('hidden.bs.modal',()=>{
      const review = evaluationReview;
      evaluationReview = null;
      if (review?.confirmed) openOverview(detailOpener,review.data);
      else returnToStudent();
    });
    document.addEventListener('click',async event=>{
      const button = event.target.closest('.management-financial-open');
      if (!button || detailOpener) return;
      detailOpener = button;
      const label = button.querySelector('span');
      const originalLabel = label.textContent;
      button.setAttribute('aria-busy','true');
      label.textContent = 'Checking evaluation…';
      try {
        const data = await fetchOverview(button);
        const progress = data.evaluation_progress;
        const badge = button.closest('tr')?.querySelector('[data-evaluation-status]');
        if (badge && progress) {
          badge.dataset.evaluationStatus = progress.status;
          badge.textContent = progress.label;
        }
        if (['PENDING','PARTIAL'].includes(progress?.status)) {
          evaluationReview = {data,confirmed:false};
          $('management-evaluation-reminder-student').textContent = data.student.student_name;
          $('management-evaluation-reminder-count').textContent = `${progress.submitted_count} of ${progress.faculty_count} submitted`;
          continueButton.disabled = true;
          reminder.show();
        } else {
          openOverview(button,data);
        }
      } catch (error) { showOverviewError(error); }
      finally {
        label.textContent = originalLabel;
        button.removeAttribute('aria-busy');
      }
    });
  }
})();
