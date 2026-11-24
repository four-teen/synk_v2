'use strict';
(() => {
  const escape = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[ch]);
  const money = value => value === null || value === undefined ? '—'
    : new Intl.NumberFormat('en-PH',{ style:'currency',currency:'PHP' }).format(Number(value));
  const date = value => {
    const parts = String(value || '').slice(0,10).split('-').map(Number);
    return parts.length === 3 && parts.every(Number.isFinite)
      ? new Intl.DateTimeFormat('en-PH',{ month:'short',day:'numeric',year:'numeric' }).format(new Date(parts[0],parts[1]-1,parts[2])) : '—';
  };
  const unique = values => [...new Set(values.filter(Boolean))];
  const empty = (icon,title,copy) => `<div class="management-financial-empty"><i class="bx ${icon}" aria-hidden="true"></i><div><strong>${escape(title)}</strong><p>${escape(copy)}</p></div></div>`;
  const pending = label => `<span class="management-financial-pending">${escape(label)}</span>`;
  const section = (id,icon,title,meta,body) => `<section class="management-financial-section" id="${id}" aria-labelledby="${id}-title"><div class="management-financial-section-heading"><div><span class="management-financial-section-icon"><i class="bx ${icon}" aria-hidden="true"></i></span><h3 id="${id}-title">${title}</h3></div>${meta}</div>${body}</section>`;
  const paymentCategories = { C:'Contributions', E:'Event fines', V:'Violations' };
  const paymentCategory = key => paymentCategories[String(key)[0]] || 'Other charges';
  const cents = value => Math.round(Number(value) * 100);
  const receiptUrl = paymentId => `payment-receipt.php?payment_id=${encodeURIComponent(paymentId)}`;
  const receiptReference = value => `Receipt No.: <strong class="management-payment-receipt-number">${escape(value)}</strong>`;

  function renderPaymentCompletion(receipt) {
    return `<section class="management-payment-completion" aria-labelledby="management-payment-completion-title" tabindex="-1">
      <span class="management-payment-completion-icon"><i class="bx bx-check" aria-hidden="true"></i></span>
      <div class="management-payment-completion-summary" role="status">
        <div><h3 id="management-payment-completion-title">Payment complete</h3><p>${receiptReference(receipt.receipt_number)}</p></div>
        <div class="management-payment-completion-amount"><span>Amount received</span><strong>${money(receipt.amount)}</strong></div>
      </div>
      <a class="btn management-payment-completion-receipt" href="${receiptUrl(receipt.payment_id)}" target="_blank" rel="noopener"><i class="bx bx-receipt" aria-hidden="true"></i>View receipt</a>
    </section>`;
  }

  function focusPaymentCompletion(root) {
    const confirmation = root.querySelector('.management-payment-completion');
    if (!confirmation) return;
    confirmation.focus({preventScroll:true});
    confirmation.scrollIntoView({block:'nearest'});
  }

  function renderPayment(student, financial) {
    const choices = (financial.charges || []).filter(row => Number(row.amount_due) > 0).map(row => `
      <label class="management-payment-choice">
        <input type="checkbox" name="charge" value="${escape(row.key)}" data-amount="${escape(row.amount_due)}">
        <span><span class="management-payment-charge-title">${escape(row.title)}</span><small>${paymentCategory(row.key)}</small></span>
        <strong>${money(row.amount_due)}</strong>
      </label>`).join('');
    return `<section class="management-payment-panel" aria-labelledby="management-payment-title" hidden>
      <form class="management-payment-form">
        <div class="management-payment-header"><div class="management-payment-inner">
          <div><h3 id="management-payment-title" tabindex="-1">Record payment</h3><p><strong>${escape(student.student_name)}</strong> · ${escape(student.student_number)}<br>${escape(financial.organization.code)} · ${escape(financial.period.text)}</p></div>
          <button class="btn btn-outline-secondary management-payment-back" type="button">Back to account</button>
        </div></div>
        <div class="management-payment-scroll" tabindex="0" role="region" aria-label="Payment details"><div class="management-payment-inner">
          <div class="management-payment-edit">
            <fieldset class="management-payment-modes"><legend>Payment allocation</legend>
              <div class="management-payment-mode-options">
                <label><input type="radio" name="allocation_mode" value="DISTRIBUTE" checked> Distribute payment</label>
                <label><input type="radio" name="allocation_mode" value="SELECTED"> Pay selected charges</label>
              </div>
            </fieldset>
            <p class="management-payment-help">Distribute shares a whole-peso payment across unpaid charges.</p>
            <fieldset class="management-payment-picker" hidden><legend>Unpaid charges</legend>
              <div class="management-payment-filters">
                <div><label class="form-label" for="management-payment-search">Search charges</label><input class="form-control" id="management-payment-search" type="search" name="search" placeholder="Charge name"></div>
                <div><label class="form-label" for="management-payment-category">Category</label><select class="form-select" id="management-payment-category" name="category"><option value="">All categories</option>${Object.entries(paymentCategories).map(([key,label]) => `<option value="${key}">${label}</option>`).join('')}</select></div>
              </div>
              <div class="management-payment-picker-tools">
                <label><input type="checkbox" name="select_matching"> <span class="management-payment-match-count">Select all matching</span></label>
                <button class="btn btn-outline-secondary management-payment-clear" type="button">Clear selection</button>
              </div>
              <div class="management-payment-choices">${choices}</div>
              <p class="management-payment-no-matches" role="status" hidden>No unpaid charges match your filters.</p>
            </fieldset>
          </div>
          <div class="management-payment-preview" hidden>
            <h3 tabindex="-1">Review allocation</h3>
            <dl class="management-payment-breakdown"></dl>
            <details class="management-payment-itemized"><summary>Itemized allocation</summary><ul></ul></details>
          </div>
        </div></div>
        <div class="management-payment-footer"><div class="management-payment-inner">
          <div class="management-payment-error alert alert-danger" role="alert" hidden></div>
          <div class="management-payment-footer-row">
            <div class="management-payment-fields">
              <div><label class="form-label" for="management-payment-receipt">Receipt number</label><input class="form-control" id="management-payment-receipt" name="receipt_number" maxlength="40" placeholder="Generated if blank" autocomplete="off"></div>
              <div><label class="form-label" for="management-payment-amount">Amount received (₱)</label><input class="form-control" id="management-payment-amount" name="amount" type="number" min="1" step="1" inputmode="decimal" required></div>
            </div>
            <div class="management-payment-footer-actions">
              <span class="management-payment-selected-count" role="status" hidden></span>
              <button class="btn btn-outline-secondary management-payment-reload" type="button">Reload balance</button>
              <button class="btn btn-outline-secondary management-payment-edit-back" type="button" hidden>Back to edit</button>
              <button class="btn btn-primary management-payment-review" type="submit">Review allocation</button>
              <button class="btn btn-primary management-payment-record" type="button" disabled hidden>Record payment</button>
            </div>
          </div>
        </div></div>
      </form>
    </section>`;
  }

  function render(data, saved = null) {
    const student = data.student, financial = data.financial;
    const subjects = data.subjects || [], summary = financial.summary;
    const contributions = financial.contributions || [], events = financial.events || [];
    const programs = unique(subjects.map(row => row.program_label));
    const programNames = unique(subjects.map(row => row.program_name));
    const originalPlacement = [student.original_section, student.original_year_level ? `Year ${student.original_year_level}` : ''].filter(Boolean).join(', ');
    const initials = student.first_name || student.last_name
      ? `${Array.from(student.first_name || '')[0] || ''}${Array.from(student.last_name || '')[0] || ''}`
      : Array.from(student.student_name || 'S').slice(0,1).join('');
    const field = (label,value) => `<div><dt>${label}</dt><dd>${escape(value || 'Not available')}</dd></div>`;
    const photo = /^\.\.\/uploads\/student-profile\/student_[0-9]+\.jpg\?v=[a-f0-9]+$/.test(student.photo_url || '') ? `<img src="${escape(student.photo_url)}" alt="${escape(student.student_name)}">` : '';
    const profile = `<aside class="management-financial-sidebar" tabindex="0" aria-label="Selected student profile">
      <div class="management-financial-avatar" role="img" aria-label="Student photo"><span>${escape(initials.toUpperCase())}</span>${photo}</div>
      <h3 class="management-financial-student-name">${escape(student.student_name)}</h3>
      <span class="management-financial-student-id">${escape(student.student_number)}</span>
      <dl class="management-financial-profile-fields">${field('Course',programNames.join(' / ') || programs.join(' / '))}${field('Section & year level',originalPlacement)}${field('Email',student.email_address || 'Email not provided')}</dl>
      <div class="management-financial-profile-period"><i class="bx bx-calendar" aria-hidden="true"></i><div><span>Selected period</span><strong>${escape(financial.period.text)}</strong></div></div>
    </aside>`;
    const settled = Number(summary.total_to_pay) === 0;
    const stat = (icon,label,value,copy,accent=false) => `<article class="management-financial-stat${accent ? ` management-financial-stat-due${settled ? ' is-paid' : ''}` : ''}"><span class="management-financial-stat-label"><i class="bx ${icon}" aria-hidden="true"></i>${label}</span><strong>${value}</strong><small>${copy}</small></article>`;
    const stats = `<div class="management-financial-summary" aria-label="Student financial summary">
      ${stat('bx-wallet','Contribution total',money(summary.contribution_catalog_total),`${summary.contribution_count} active contribution${summary.contribution_count===1?'':'s'}`)}
      ${stat('bx-calendar-event','Events',String(summary.event_count),`${escape(summary.absence_count ?? 0)} absences${summary.attendance_pending_count ? ` · ${escape(summary.attendance_pending_count)} pending` : ''}`)}
      ${stat('bx-receipt','Amount paid',money(summary.amount_paid),'Posted payments')}
      ${stat('bx-credit-card','Total to pay',money(summary.total_to_pay),'Contributions + recorded fines',true)}
    </div>`;
    const paymentStatus = value => ({PAID:'Paid',PARTIALLY_PAID:'Partially paid',UNPAID:'Unpaid',EXEMPTED:'Exempted'}[value] || 'Unpaid');
    const contributionRows = contributions.map(row => `<tr role="row"><td role="cell"><strong>${escape(row.title)}</strong><small>All students</small>${row.is_exempt?'<small>Exempted: '+escape(row.exemption_reason)+'</small>':''}</td><td role="cell" data-label="Event date">${date(row.contribution_date)}</td><td role="cell" data-label="Amount" class="text-end">${money(row.amount)}<small>Remaining: ${money(row.amount_due ?? row.amount)}</small></td><td role="cell" data-label="Payment status" class="text-end"><span class="management-financial-pending${row.payment_status==='PAID'?' management-financial-present':row.payment_status==='EXEMPTED'?' management-financial-exempted':''}">${paymentStatus(row.payment_status)}</span></td></tr>`).join('');
    const contributionBody = contributions.length
      ? `<div class="management-financial-table-scroll"><table class="table management-financial-table" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Contribution</th><th role="columnheader" scope="col">Event date</th><th role="columnheader" scope="col" class="text-end">Amount</th><th role="columnheader" scope="col" class="text-end">Payment status</th></tr></thead><tbody role="rowgroup">${contributionRows}</tbody><tfoot role="rowgroup"><tr role="row"><th role="rowheader" scope="row" colspan="2">Contribution total</th><td role="cell" class="text-end">${money(summary.contribution_catalog_total)}</td><td role="cell"></td></tr></tfoot></table></div>`
      : empty('bx-wallet','No active contributions','No contributions are listed for this organization and selected period.');
    const contributionSection = section('financial-contributions','bx-wallet','Contributions',`<span class="management-financial-count">${summary.contribution_count} listed</span>`,contributionBody);
    const attendanceLabels={PRESENT:'Present',ABSENT:'Absent',PENDING:'Attendance pending',NOT_IN_ROSTER:'Not in event roster',EXEMPTED:'Exempted'};
    const eventRows = events.map(row => `<tr role="row"><td role="cell"><strong>${escape(row.title)}</strong>${row.is_exempt?'<small>Exempted: '+escape(row.exemption_reason)+'</small>':''}</td><td role="cell" data-label="Event date">${date(row.event_date)}</td><td role="cell" data-label="Absence fine" class="text-end">${money(row.absence_fine ?? '0.00')}</td><td role="cell" data-label="Attendance" class="text-end"><span class="management-financial-pending${row.student_attendance==='PRESENT'?' management-financial-present':row.student_attendance==='ABSENT'?' management-financial-absent':row.student_attendance==='EXEMPTED'?' management-financial-exempted':''}">${attendanceLabels[row.student_attendance] || 'Attendance pending'}</span></td><td role="cell" data-label="Amount to pay" class="text-end">${money(row.amount_due ?? '0.00')}</td></tr>`).join('');
    const eventBody = (events.length
      ? `<div class="management-financial-table-scroll"><table class="table management-financial-table" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Event</th><th role="columnheader" scope="col">Event date</th><th role="columnheader" scope="col" class="text-end">Absence fine</th><th role="columnheader" scope="col" class="text-end">Attendance</th><th role="columnheader" scope="col" class="text-end">Amount to pay</th></tr></thead><tbody role="rowgroup">${eventRows}</tbody></table></div>`
      : empty('bx-calendar-event','No active events','No events are listed for this organization and selected period.'))
      + `<div class="management-financial-event-totals"><div><span>Total events</span><strong>${summary.event_count}</strong></div><div><span>Total absences</span><strong data-financial-absence-count>${summary.absence_count === null ? '—' : escape(summary.absence_count)}</strong></div><div><span>Absence amount to pay</span><strong>${money(summary.event_fine_total)}</strong></div></div>`;
    const eventSection = section('financial-events','bx-calendar-event','Events & attendance',`<span class="management-financial-count">${summary.event_count} event${summary.event_count===1?'':'s'}</span>`,eventBody);
    const violations = financial.violations || [];
    const violationBody = violations.length
      ? `<div class="management-financial-table-scroll"><table class="table management-financial-table" role="table"><thead role="rowgroup"><tr role="row"><th>Violation</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Payment status</th></tr></thead><tbody role="rowgroup">${violations.map(row=>`<tr role="row"><td role="cell"><strong>${escape(row.title)}</strong>${row.notes?`<small>${escape(row.notes)}</small>`:''}<small>Recorded by ${escape(row.recorded_by_name)}</small></td><td role="cell" data-label="Date">${date(row.date)}</td><td role="cell" data-label="Amount" class="text-end">${money(row.amount)}<small>Remaining: ${money(row.amount_due ?? row.amount)}</small></td><td role="cell" data-label="Payment status" class="text-end"><span class="management-financial-pending${row.payment_status==='PAID'?' management-financial-present':row.payment_status==='EXEMPTED'?' management-financial-exempted':''}">${paymentStatus(row.payment_status)}</span></td></tr>`).join('')}</tbody><tfoot><tr><th scope="row" colspan="2">Violation charges</th><td class="text-end">${money(summary.violation_total)}</td><td></td></tr></tfoot></table></div>`
      : empty('bx-shield-quarter','No violation entries available','There are no student violation entries to display for this period.');
    const violationOptions=financial.violation_options || [],periodParts=String(financial.period.key).split(':');
    const violationChoices=violationOptions.map(row=>`<label class="management-violation-choice"><input type="checkbox" name="violation" value="${escape(row.violation_id)}" data-version="${escape(row.version)}" data-amount="${escape(row.amount)}"><span>${escape(row.violation_type)}</span><strong>${money(row.amount)}</strong></label>`).join('');
    const violationForm=financial.can_add_violations ? `<div class="management-violation-panel" hidden><h4>Add violations to ${escape(student.student_name)}</h4>${violationOptions.length?`<form class="management-violation-form"><fieldset><legend>Select from the standard violation list</legend><div class="management-violation-choices">${violationChoices}</div></fieldset><p class="management-violation-help">Each selected violation adds its listed amount to this student's account in ${escape(financial.period.text)}.</p><div class="management-violation-fields"><div><label class="form-label" for="management-violation-date">Violation date</label><input class="form-control" id="management-violation-date" type="date" name="occurred_on" value="${escape(financial.violation_date_today)}" max="${escape(financial.violation_date_today)}" required></div><div><label class="form-label" for="management-violation-notes">Notes (optional)</label><textarea class="form-control" id="management-violation-notes" name="notes" maxlength="1000" rows="2"></textarea></div></div><p class="management-violation-total">Additional amount to pay: <strong>${money(0)}</strong></p><div class="management-violation-error alert alert-danger" role="alert" hidden></div><div class="management-payment-buttons"><button class="btn btn-primary" type="submit" disabled>Apply to this student</button><button class="btn btn-outline-secondary management-violation-cancel" type="button">Cancel</button></div></form>`:`<p>No violations are listed for this semester. Add them in <a href="violations.php?term=${encodeURIComponent(periodParts.slice(0,2).join(':'))}&amp;exam_term=${encodeURIComponent(periodParts[2] || '')}">Violations</a>, then reload this overview.</p>`}</div>` : '';
    const violationSection = section('financial-violations','bx-shield-quarter','Violations',financial.can_add_violations?'<button class="btn btn-sm btn-outline-primary management-violation-add" type="button"><i class="bx bx-plus me-1" aria-hidden="true"></i>Add violations</button>':'',violationForm+violationBody);
    const paymentRows = (financial.payments || []).map(row => `<li>
      <div><div class="management-payment-history-reference">${receiptReference(row.reference)}</div><small>${escape(row.date)} · ${escape(row.recorded_by_name)}</small></div>
      <strong>${money(row.amount)}</strong><a href="${receiptUrl(row.payment_id)}" target="_blank" rel="noopener">View receipt</a>
    </li>`).join('');
    const paymentSection = section('financial-payments','bx-receipt','Payment history','',paymentRows ? `<ul class="management-payment-history">${paymentRows}</ul>` : empty('bx-receipt','No posted payments available','There are no posted payments to display for this period.'));
    const reconciliation = `<section class="management-financial-reconciliation" aria-labelledby="financial-total-title"><div><h3 id="financial-total-title">Account total</h3><p>Contributions apply automatically, with saved exemptions applied. Absence fines apply after attendance closes to students without attendance or an exemption. Violation charges apply only when recorded for this student.</p></div><dl><div><dt>Contributions</dt><dd>${money(summary.contribution_catalog_total)}</dd></div><div><dt>Event absence charges</dt><dd>${money(summary.event_fine_total)}</dd></div><div><dt>Violation charges</dt><dd>${money(summary.violation_total)}</dd></div><div><dt>Less: payments received</dt><dd>${money(summary.amount_paid)}</dd></div><div class="management-financial-balance"><dt>Total to pay</dt><dd>${money(summary.total_to_pay)}</dd></div></dl></section>`;
    const statusLabel={UNPAID:'Unpaid',NO_CHARGES:'No charges',PAID:'Paid',PARTIALLY_PAID:'Partially paid'}[summary.payment_status] || 'Unpaid';
    return `<div class="management-financial-layout">${profile}<div class="management-financial-main" tabindex="0" role="region" aria-label="Student financial details">
      ${saved?.receipt_number ? renderPaymentCompletion(saved) : ''}
      <div class="management-financial-period"><div><span class="management-financial-kicker">${escape(financial.organization.code)} · FINANCIAL OVERVIEW</span><h3>${escape(financial.period.text)}</h3></div><div class="management-financial-actions"><span class="management-financial-status"><span aria-hidden="true"></span>${statusLabel}</span><button type="button" class="btn btn-primary management-financial-pay"${financial.can_pay ? '' : ' disabled'}><i class="bx bx-wallet me-1" aria-hidden="true"></i>Pay</button></div></div>
      ${stats}
      ${contributionSection}${eventSection}${violationSection}${paymentSection}${reconciliation.replace('class="management-financial-balance"',`class="management-financial-balance${settled ? ' is-paid' : ''}"`)}
    </div></div>${financial.can_pay ? renderPayment(student, financial) : ''}`;
  }
  function bindViolations(data,root,request,reload){
    const panel=root.querySelector('.management-violation-panel'),open=root.querySelector('.management-violation-add');if(!panel||!open)return;
    open.addEventListener('click',()=>{panel.hidden=!panel.hidden;if(!panel.hidden)panel.scrollIntoView({block:'nearest',behavior:'smooth'});});
    const form=panel.querySelector('form');if(!form)return;
    const error=form.querySelector('.management-violation-error'),submit=form.querySelector('[type=submit]');
    const key=()=>Array.from(crypto.getRandomValues(new Uint8Array(32)),b=>b.toString(16).padStart(2,'0')).join('');
    let requestKey=key(),saving=false;
    const selected=()=>[...form.querySelectorAll('input[name=violation]:checked')];
    const changed=()=>{requestKey=key();submit.disabled=!selected().length;form.querySelector('.management-violation-total strong').textContent=money(selected().reduce((sum,input)=>sum+Math.round(Number(input.dataset.amount)*100),0)/100);error.hidden=true;};
    form.addEventListener('input',changed);form.addEventListener('change',changed);
    form.querySelector('.management-violation-cancel').addEventListener('click',()=>{if(saving)return;form.reset();changed();panel.hidden=true;open.focus();});
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(saving||!selected().length||!form.reportValidity())return;
      const payload={student_id:String(data.student.student_id),selected_violations:JSON.stringify(selected().map(input=>({violation_id:input.value,version:input.dataset.version}))),occurred_on:form.elements.occurred_on.value,notes:form.elements.notes.value,account_version:data.financial.account_version,request_key:requestKey};
      saving=true;error.hidden=true;open.disabled=true;form.querySelectorAll('input,textarea,button').forEach(control=>control.disabled=true);submit.textContent='Applying…';
      try{const result=await request('student_violation_apply',payload);await reload({message:result.message});}
      catch(failure){if(root.contains(form)){error.textContent=failure.message;error.hidden=false;form.querySelectorAll('input,textarea,button').forEach(control=>control.disabled=false);submit.textContent='Apply to this student';}}
      finally{saving=false;open.disabled=false;}
    });
  }
  function showAllocation(preview, result) {
    const categories = new Map();
    for (const item of result.items) {
      const label = paymentCategory(item.key);
      const subtotal = categories.get(label) || { count: 0, amount: 0 };
      subtotal.count++;
      subtotal.amount += cents(item.amount);
      categories.set(label, subtotal);
    }
    preview.querySelector('.management-payment-breakdown').innerHTML = [...categories].map(([label,subtotal]) => `
      <div><dt>${label}<small>${subtotal.count} ${subtotal.count === 1 ? 'charge' : 'charges'}</small></dt><dd>${money(subtotal.amount / 100)}</dd></div>`).join('') + `
      <div class="management-payment-balance"><dt>Remaining balance</dt><dd>${money(result.balance_after)}</dd></div>`;
    const details = preview.querySelector('details');
    details.open = false;
    details.querySelector('summary').textContent = `Itemized allocation (${result.items.length})`;
    details.querySelector('ul').innerHTML = result.items.map(item => `<li><span>${escape(item.title)}</span><strong>${money(item.amount)}</strong></li>`).join('');
  }

  function bindPayment(data, root, request, reload, panel, nextReceipt) {
    const form = panel.querySelector('form');
    const { amount, receipt_number: receipt, search, category, select_matching: selectMatching } = form.elements;
    const overview = root.querySelector('.management-financial-layout');
    const accountDetails = overview.querySelector('.management-financial-main');
    const pay = overview.querySelector('.management-financial-pay');
    const back = form.querySelector('.management-payment-back');
    const scrolling = form.querySelector('.management-payment-scroll');
    const edit = form.querySelector('.management-payment-edit');
    const picker = form.querySelector('.management-payment-picker');
    const help = form.querySelector('.management-payment-help');
    const clear = form.querySelector('.management-payment-clear');
    const matchCount = form.querySelector('.management-payment-match-count');
    const noMatches = form.querySelector('.management-payment-no-matches');
    const selectedCount = form.querySelector('.management-payment-selected-count');
    const error = form.querySelector('.management-payment-error');
    const preview = form.querySelector('.management-payment-preview');
    const backEdit = form.querySelector('.management-payment-edit-back');
    const review = form.querySelector('.management-payment-review');
    const record = form.querySelector('.management-payment-record');
    const reloadBalance = form.querySelector('.management-payment-reload');
    const charges = [...form.querySelectorAll('input[name=charge]')].map(input => {
      const row = input.closest('label');
      return { input, row, title: row.querySelector('.management-payment-charge-title').textContent.toLowerCase() };
    });
    const matching = () => charges.filter(charge => !charge.row.hidden);
    const selected = () => charges.filter(charge => charge.input.checked);
    const isSelected = () => form.elements.allocation_mode.value === 'SELECTED';
    const totalToPay = cents(data.financial.summary.total_to_pay);
    const wholePesoCapacity = charges.reduce((total,{ input }) => total + Math.floor(cents(input.dataset.amount) / 100), 0);
    const distributeLimit = Math.min(Math.floor(totalToPay / 100), wholePesoCapacity);
    const distributeHelp = distributeLimit * 100 < totalToPay
      ? `Distribute can apply ${money(distributeLimit)} in whole pesos. Use Pay selected charges to settle the full ${money(totalToPay / 100)}.`
      : 'Distribute shares a whole-peso payment across unpaid charges.';
    let distributeAmount = distributeLimit > 0 ? distributeLimit.toFixed(2) : '';
    let receiptSuggested = false;
    let reviewed = null, draft = null, savedReceipt = null, busy = false;
    let overviewPosition = 0, detailsPosition = 0, editPosition = 0;

    function showError(message) {
      error.textContent = message && savedReceipt ? 'Reload the balance to update the account overview.' : message;
      error.classList.toggle('alert-warning', Boolean(savedReceipt));
      error.classList.toggle('alert-danger', !savedReceipt);
      error.hidden = !message;
    }

    function syncControls() {
      const isReview = Boolean(reviewed);
      edit.hidden = isReview;
      edit.inert = busy;
      preview.hidden = !isReview;
      amount.readOnly = busy || isReview || isSelected();
      receipt.readOnly = busy || isReview;
      review.hidden = isReview;
      review.disabled = busy || (isSelected() ? !selected().length : distributeLimit === 0);
      record.hidden = !isReview || Boolean(savedReceipt);
      record.disabled = record.hidden || busy;
      backEdit.hidden = record.hidden;
      backEdit.disabled = busy;
      back.disabled = busy || Boolean(savedReceipt);
      reloadBalance.hidden = isReview && error.hidden && !savedReceipt;
      reloadBalance.disabled = busy;
    }

    function syncSelection() {
      const checked = selected();
      const visible = matching();
      const visibleChecked = visible.filter(charge => charge.input.checked).length;
      selectMatching.disabled = !visible.length;
      selectMatching.checked = visible.length > 0 && visibleChecked === visible.length;
      selectMatching.indeterminate = visibleChecked > 0 && visibleChecked < visible.length;
      matchCount.textContent = `Select all matching (${visible.length})`;
      noMatches.hidden = Boolean(visible.length);
      clear.disabled = !checked.length;
      selectedCount.hidden = !isSelected();
      selectedCount.textContent = `${checked.length} selected`;
      if (isSelected()) amount.value = (checked.reduce((sum,charge) => sum + cents(charge.input.dataset.amount), 0) / 100).toFixed(2);
      syncControls();
    }

    function applyFilters() {
      const query = search.value.trim().toLowerCase();
      for (const charge of charges) {
        charge.row.hidden = !charge.title.includes(query) || (category.value !== '' && charge.input.value[0] !== category.value);
      }
      syncSelection();
    }

    function applyMode() {
      const selectedMode = isSelected();
      picker.hidden = !selectedMode;
      amount.min = amount.step = selectedMode ? '.01' : '1';
      if (!selectedMode) amount.value = distributeAmount;
      help.textContent = selectedMode ? 'Pay the full remaining balance of each selected charge.' : distributeHelp;
      showError('');
      syncSelection();
    }

    async function run(button, label, action) {
      if (busy) return;
      busy = true;
      const idleLabel = button.textContent;
      button.textContent = label;
      showError('');
      syncControls();
      try { await action(); }
      catch (failure) { if (root.contains(form)) showError(failure.message); }
      finally {
        busy = false;
        button.textContent = idleLabel;
        syncControls();
      }
    }

    pay.addEventListener('click', () => {
      overviewPosition = root.scrollTop;
      detailsPosition = accountDetails.scrollTop;
      overview.hidden = true;
      panel.hidden = false;
      if (!receiptSuggested) {
        receipt.value = nextReceipt();
        receiptSuggested = true;
      }
      const focusTarget = receipt.readOnly ? preview.querySelector('h3') : receipt;
      focusTarget.focus({preventScroll:true});
    });
    back.addEventListener('click', () => {
      panel.hidden = true;
      overview.hidden = false;
      root.scrollTop = overviewPosition;
      accountDetails.scrollTop = detailsPosition;
      pay.focus({preventScroll:true});
    });
    search.addEventListener('input', applyFilters);
    search.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
    category.addEventListener('change', applyFilters);
    for (const mode of form.elements.allocation_mode) mode.addEventListener('change', () => {
      applyMode();
      if (mode.value === 'DISTRIBUTE') receipt.focus({preventScroll:true});
    });
    amount.addEventListener('input', () => { distributeAmount = amount.value; showError(''); });
    receipt.addEventListener('input', () => showError(''));
    const selectionChanged = () => { showError(''); syncSelection(); };
    for (const { input } of charges) input.addEventListener('change', selectionChanged);
    selectMatching.addEventListener('change', () => {
      for (const { input } of matching()) input.checked = selectMatching.checked;
      selectionChanged();
    });
    clear.addEventListener('click', () => {
      for (const { input } of selected()) input.checked = false;
      selectionChanged();
      if (!selectMatching.disabled) selectMatching.focus();
      else search.focus();
    });

    form.addEventListener('submit', event => {
      event.preventDefault();
      if (busy || reviewed || review.disabled || !form.reportValidity()) return;
      const values = {
        student_id: String(data.student.student_id),
        allocation_mode: form.elements.allocation_mode.value,
        amount: amount.value,
        receipt_number: receipt.value.trim(),
        selected: JSON.stringify(isSelected() ? selected().map(charge => charge.input.value) : []),
        account_version: data.financial.account_version,
      };
      const signature = JSON.stringify(values);
      if (signature !== draft?.signature) {
        const key = Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2,'0')).join('');
        draft = { signature, payload: { ...values, request_key: key } };
      }
      run(review, 'Reviewing…', async () => {
        const result = await request('payment_preview', draft.payload);
        if (!root.contains(form)) return;
        editPosition = scrolling.scrollTop;
        showAllocation(preview, result);
        reviewed = draft.payload;
        receipt.value = reviewed.receipt_number;
        syncControls();
        scrolling.scrollTop = 0;
        preview.querySelector('h3').focus({preventScroll:true});
      });
    });
    backEdit.addEventListener('click', () => {
      reviewed = null;
      showError('');
      syncControls();
      scrolling.scrollTop = editPosition;
      review.focus({preventScroll:true});
    });
    record.addEventListener('click', () => {
      if (!reviewed || savedReceipt) return;
      run(record, 'Recording…', async () => {
        const result = await request('payment_post', reviewed);
        savedReceipt = { ...result, amount: reviewed.amount };
        if (root.contains(form)) {
          preview.innerHTML = renderPaymentCompletion(savedReceipt);
          syncControls();
          scrolling.scrollTop = 0;
          focusPaymentCompletion(root);
        }
        await reload(savedReceipt);
      });
    });
    reloadBalance.addEventListener('click', () => run(reloadBalance, 'Reloading…', () => reload(savedReceipt)));
    applyMode();
  }

  function bind(data, root, request, reload, nextReceipt = () => '') {
    root.querySelector('.management-financial-avatar img')?.addEventListener('error',event=>event.target.remove(),{once:true});
    bindViolations(data,root,request,reload);
    const panel = root.querySelector('.management-payment-panel');
    if (panel) bindPayment(data, root, request, reload, panel, nextReceipt);
    focusPaymentCompletion(root);
  }
  function updateDirectoryBalance(element, summary) {
    const { payment_status: status, total_to_pay: balance } = summary;
    element.dataset.balanceStatus = status;
    element.textContent = status === 'PAID' ? 'Paid' : (status === 'NO_CHARGES' ? 'No charges' : money(balance));
  }

  window.SynkManagementFinancial = { render, bind, updateDirectoryBalance };
})();
