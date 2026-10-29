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

  function render(data) {
    const student = data.student, financial = data.financial;
    const subjects = data.subjects || [], summary = financial.summary;
    const contributions = financial.contributions || [], events = financial.events || [];
    const programs = unique(subjects.map(row => row.program_label));
    const programNames = unique(subjects.map(row => row.program_name));
    const years = unique(subjects.map(row => row.year_level ? `Year ${row.year_level}` : ''));
    const sections = student.home_section && student.home_section !== 'Not verified'
      ? [student.home_section] : unique(subjects.map(row => row.section_label));
    const initials = student.first_name || student.last_name
      ? `${Array.from(student.first_name || '')[0] || ''}${Array.from(student.last_name || '')[0] || ''}`
      : Array.from(student.student_name || 'S').slice(0,1).join('');
    const field = (label,value) => `<div><dt>${label}</dt><dd>${escape(value || 'Not available')}</dd></div>`;
    const photo = /^\.\.\/uploads\/student-profile\/student_[0-9]+\.jpg\?v=[a-f0-9]+$/.test(student.photo_url || '') ? `<img src="${escape(student.photo_url)}" alt="${escape(student.student_name)}">` : '';
    const profile = `<aside class="management-financial-sidebar" tabindex="0" aria-label="Selected student profile">
      <div class="management-financial-avatar" role="img" aria-label="Student photo"><span>${escape(initials.toUpperCase())}</span>${photo}</div>
      <h3 class="management-financial-student-name">${escape(student.student_name)}</h3>
      <span class="management-financial-student-id">${escape(student.student_number)}</span>
      <dl class="management-financial-profile-fields">${field('Course',programNames.join(' / ') || programs.join(' / '))}${field('Section & year level',[sections.join(' / '),years.join(' / ')].filter(Boolean).join(', '))}${field('Email',student.email_address || 'Email not provided')}</dl>
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
    const paymentRows=(financial.payments || []).map(row=>`<li><div><strong>${escape(row.reference)}</strong><small>${escape(row.date)} · ${escape(row.recorded_by_name)}</small></div><strong>${money(row.amount)}</strong><a href="payment-receipt.php?payment_id=${encodeURIComponent(row.payment_id)}" target="_blank" rel="noopener">View receipt</a></li>`).join('');
    const paymentSection = section('financial-payments','bx-receipt','Payment history','',paymentRows ? `<ul class="management-payment-history">${paymentRows}</ul>` : empty('bx-receipt','No posted payments available','There are no posted payments to display for this period.'));
    const reconciliation = `<section class="management-financial-reconciliation" aria-labelledby="financial-total-title"><div><h3 id="financial-total-title">Account total</h3><p>Contributions apply automatically, with saved exemptions applied. Absence fines apply after attendance closes to students without attendance or an exemption. Violation charges apply only when recorded for this student.</p></div><dl><div><dt>Contributions</dt><dd>${money(summary.contribution_catalog_total)}</dd></div><div><dt>Event absence charges</dt><dd>${money(summary.event_fine_total)}</dd></div><div><dt>Violation charges</dt><dd>${money(summary.violation_total)}</dd></div><div><dt>Less: payments received</dt><dd>${money(summary.amount_paid)}</dd></div><div class="management-financial-balance"><dt>Total to pay</dt><dd>${money(summary.total_to_pay)}</dd></div></dl></section>`;
    const statusLabel={UNPAID:'Unpaid',NO_CHARGES:'No charges',PAID:'Paid',PARTIALLY_PAID:'Partially paid'}[summary.payment_status] || 'Unpaid';
    const choices=(financial.charges || []).filter(row=>Number(row.amount_due)>0).map(row=>`<label class="management-payment-choice"><input type="checkbox" name="charge" value="${escape(row.key)}" data-amount="${escape(row.amount_due)}"><span>${escape(row.title)}</span><strong>${money(row.amount_due)}</strong></label>`).join('');
    const payForm=financial.can_pay ? `<section class="management-payment-panel" hidden><h3>Record payment</h3><form class="management-payment-form"><fieldset><legend>Payment allocation</legend><label><input type="radio" name="allocation_mode" value="DISTRIBUTE" checked> Distribute</label><label><input type="radio" name="allocation_mode" value="SELECTED"> Selected</label></fieldset><p class="management-payment-help">Distribute shares the payment across unpaid charges in whole pesos. Each charge is capped at its remaining balance.</p><div class="management-payment-choices" hidden>${choices}</div><label class="form-label" for="management-payment-amount">Amount received (₱)</label><input class="form-control" id="management-payment-amount" name="amount" type="number" min="1" step="1" inputmode="decimal" required><div class="management-payment-error alert alert-danger" role="alert" hidden></div><div class="management-payment-preview" aria-live="polite"></div><div class="management-payment-buttons"><button class="btn btn-outline-primary" type="submit">Review allocation</button><button class="btn btn-primary management-payment-record" type="button" disabled>Record payment</button><button class="btn btn-outline-secondary management-payment-reload" type="button">Reload balance</button></div></form></section>` : '';
    return `<div class="management-financial-layout">${profile}<div class="management-financial-main" tabindex="0" role="region" aria-label="Student financial details">
      <div class="management-financial-period"><div><span class="management-financial-kicker">${escape(financial.organization.code)} · FINANCIAL OVERVIEW</span><h3>${escape(financial.period.text)}</h3></div><div class="management-financial-actions"><span class="management-financial-status"><span aria-hidden="true"></span>${statusLabel}</span><button type="button" class="btn btn-primary management-financial-pay"${financial.can_pay ? '' : ' disabled'}><i class="bx bx-wallet me-1" aria-hidden="true"></i>Pay</button></div></div>
      ${stats}
      ${payForm}${contributionSection}${eventSection}${violationSection}${paymentSection}${reconciliation.replace('class="management-financial-balance"',`class="management-financial-balance${settled ? ' is-paid' : ''}"`)}
    </div></div>`;
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
  function bind(data,root,request,reload) {
    root.querySelector('.management-financial-avatar img')?.addEventListener('error',event=>event.target.remove(),{once:true});
    bindViolations(data,root,request,reload);
    const panel=root.querySelector('.management-payment-panel'),form=panel?.querySelector('form');
    if(!form)return;
    const amount=form.elements.amount,choices=form.querySelector('.management-payment-choices'),error=form.querySelector('.management-payment-error'),preview=form.querySelector('.management-payment-preview'),record=form.querySelector('.management-payment-record');
    let version=0,requestKey='',reviewed=false,saving=false;
    const newKey=()=>Array.from(crypto.getRandomValues(new Uint8Array(32)),b=>b.toString(16).padStart(2,'0')).join('');
    const cents=value=>Math.round(Number(value)*100);
    const showError=text=>{error.textContent=text;error.hidden=!text;};
    const mode=()=>form.querySelector('input[name=allocation_mode]:checked').value;
    const selected=()=>[...form.querySelectorAll('input[name=charge]:checked')].map(input=>input.value);
    function changed() {
      version++;reviewed=false;requestKey=newKey();record.disabled=true;preview.innerHTML='';showError('');
      const isSelected=mode()==='SELECTED';choices.hidden=!isSelected;amount.readOnly=isSelected;amount.step=isSelected?'.01':'1';amount.min=isSelected?'.01':'1';
      form.querySelector('.management-payment-help').textContent=isSelected?'Check the charges to settle. The amount must match their exact remaining balances.':'Distribute shares the payment across unpaid charges in whole pesos. Each charge is capped at its remaining balance.';
      if(isSelected){const total=[...form.querySelectorAll('input[name=charge]:checked')].reduce((sum,input)=>sum+cents(input.dataset.amount),0);amount.value=(total/100).toFixed(2);}
    }
    const values=()=>({student_id:String(data.student.student_id),allocation_mode:mode(),amount:amount.value,selected:JSON.stringify(mode()==='SELECTED'?selected():[]),account_version:data.financial.account_version,request_key:requestKey});
    root.querySelector('.management-financial-pay').addEventListener('click',()=>{panel.hidden=false;panel.scrollIntoView({block:'nearest',behavior:'smooth'});amount.focus();});
    form.addEventListener('input',changed);form.addEventListener('change',event=>{if(event.target.name==='allocation_mode'&&mode()==='DISTRIBUTE')amount.value='';changed();});
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(saving||!form.reportValidity())return;
      const current=++version;record.disabled=true;showError('');preview.textContent='Calculating allocation…';
      try {const result=await request('payment_preview',values());if(current!==version||!root.contains(form))return;
        preview.innerHTML=`<ul>${result.items.map(item=>`<li><span>${escape(item.title)}</span><strong>${money(item.amount)}</strong></li>`).join('')}</ul><p><strong>Payment: ${money(result.amount)}</strong> · Remaining balance: ${money(result.balance_after)}</p>`;
        reviewed=true;record.disabled=false;
      }catch(failure){if(current===version){preview.innerHTML='';showError(failure.message);}}
    });
    record.addEventListener('click',async()=>{
      if(!reviewed||saving)return;saving=true;const payload=values();
      form.querySelectorAll('input,button').forEach(input=>input.disabled=true);showError('');record.textContent='Recording…';
      try {const result=await request('payment_post',payload);await reload(result);}
      catch(failure){showError(failure.message);form.querySelectorAll('input,button').forEach(input=>input.disabled=false);record.textContent='Record payment';}
      finally{saving=false;}
    });
    form.querySelector('.management-payment-reload').addEventListener('click',async()=>{
      try{await reload();}catch(failure){showError(failure.message);}
    });
    changed();
  }
  window.SynkManagementFinancial = { render, bind };
})();
