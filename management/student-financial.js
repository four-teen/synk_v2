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
    const profile = `<aside class="management-financial-sidebar" tabindex="0" aria-label="Selected student profile">
      <div class="management-financial-avatar" role="img" aria-label="Student photo placeholder"><span>${escape(initials.toUpperCase())}</span></div>
      <h3 class="management-financial-student-name">${escape(student.student_name)}</h3>
      <span class="management-financial-student-id">${escape(student.student_number)}</span>
      <dl class="management-financial-profile-fields">${field('Course',programNames.join(' / ') || programs.join(' / '))}${field('Section & year level',[sections.join(' / '),years.join(' / ')].filter(Boolean).join(', '))}${field('Email',student.email_address || 'Email not provided')}</dl>
      <div class="management-financial-profile-period"><i class="bx bx-calendar" aria-hidden="true"></i><div><span>Selected period</span><strong>${escape(financial.period.text)}</strong></div></div>
    </aside>`;
    const stat = (icon,label,value,copy,accent=false) => `<article class="management-financial-stat${accent ? ' management-financial-stat-due' : ''}"><span class="management-financial-stat-label"><i class="bx ${icon}" aria-hidden="true"></i>${label}</span><strong>${value}</strong><small>${copy}</small></article>`;
    const stats = `<div class="management-financial-summary" aria-label="Student financial summary">
      ${stat('bx-wallet','Contribution total',money(summary.contribution_catalog_total),`${summary.contribution_count} active contribution${summary.contribution_count===1?'':'s'}`)}
      ${stat('bx-calendar-event','Events',String(summary.event_count),`${escape(summary.absence_count ?? 0)} absences${summary.attendance_pending_count ? ` · ${escape(summary.attendance_pending_count)} pending` : ''}`)}
      ${stat('bx-receipt','Amount paid',money(summary.amount_paid),'Posted payments')}
      ${stat('bx-credit-card','Total to pay',money(summary.total_to_pay),'Contributions + recorded fines',true)}
    </div>`;
    const contributionRows = contributions.map(row => `<tr role="row"><td role="cell"><strong>${escape(row.title)}</strong><small>All students</small></td><td role="cell" data-label="Event date">${date(row.contribution_date)}</td><td role="cell" data-label="Amount" class="text-end">${money(row.amount)}</td><td role="cell" data-label="Payment status" class="text-end">${pending('Unpaid')}</td></tr>`).join('');
    const contributionBody = contributions.length
      ? `<div class="management-financial-table-scroll"><table class="table management-financial-table" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Contribution</th><th role="columnheader" scope="col">Event date</th><th role="columnheader" scope="col" class="text-end">Amount</th><th role="columnheader" scope="col" class="text-end">Payment status</th></tr></thead><tbody role="rowgroup">${contributionRows}</tbody><tfoot role="rowgroup"><tr role="row"><th role="rowheader" scope="row" colspan="2">Contribution total</th><td role="cell" class="text-end">${money(summary.contribution_catalog_total)}</td><td role="cell"></td></tr></tfoot></table></div>`
      : empty('bx-wallet','No active contributions','No contributions are listed for this organization and selected period.');
    const contributionSection = section('financial-contributions','bx-wallet','Contributions',`<span class="management-financial-count">${summary.contribution_count} listed</span>`,contributionBody);
    const attendanceLabels={PRESENT:'Present',ABSENT:'Absent',PENDING:'Attendance pending',NOT_IN_ROSTER:'Not in event roster'};
    const eventRows = events.map(row => `<tr role="row"><td role="cell"><strong>${escape(row.title)}</strong></td><td role="cell" data-label="Event date">${date(row.event_date)}</td><td role="cell" data-label="Absence fine" class="text-end">${money(row.absence_fine ?? '0.00')}</td><td role="cell" data-label="Attendance" class="text-end"><span class="management-financial-pending${row.student_attendance==='PRESENT'?' management-financial-present':row.student_attendance==='ABSENT'?' management-financial-absent':''}">${attendanceLabels[row.student_attendance] || 'Attendance pending'}</span></td><td role="cell" data-label="Amount to pay" class="text-end">${money(row.amount_due ?? '0.00')}</td></tr>`).join('');
    const eventBody = (events.length
      ? `<div class="management-financial-table-scroll"><table class="table management-financial-table" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Event</th><th role="columnheader" scope="col">Event date</th><th role="columnheader" scope="col" class="text-end">Absence fine</th><th role="columnheader" scope="col" class="text-end">Attendance</th><th role="columnheader" scope="col" class="text-end">Amount to pay</th></tr></thead><tbody role="rowgroup">${eventRows}</tbody></table></div>`
      : empty('bx-calendar-event','No active events','No events are listed for this organization and selected period.'))
      + `<div class="management-financial-event-totals"><div><span>Total events</span><strong>${summary.event_count}</strong></div><div><span>Total absences</span><strong data-financial-absence-count>${summary.absence_count === null ? '—' : escape(summary.absence_count)}</strong></div><div><span>Absence amount to pay</span><strong>${money(summary.event_fine_total)}</strong></div></div>`;
    const eventSection = section('financial-events','bx-calendar-event','Events & attendance',`<span class="management-financial-count">${summary.event_count} event${summary.event_count===1?'':'s'}</span>`,eventBody);
    const violations = financial.violations || [];
    const violationBody = violations.length
      ? `<div class="management-financial-table-scroll"><table class="table management-financial-table" role="table"><thead role="rowgroup"><tr role="row"><th>Violation</th><th>Date</th><th class="text-end">Amount</th><th class="text-end">Status</th></tr></thead><tbody role="rowgroup">${violations.map(row=>`<tr role="row"><td role="cell"><strong>${escape(row.title)}</strong></td><td role="cell" data-label="Date">${date(row.date)}</td><td role="cell" data-label="Amount" class="text-end">${money(row.amount)}</td><td role="cell" data-label="Status" class="text-end">${escape(row.status)}</td></tr>`).join('')}</tbody></table></div>`
      : empty('bx-shield-quarter','No violation entries available','There are no student violation entries to display for this period.');
    const violationSection = section('financial-violations','bx-shield-quarter','Violations','',violationBody);
    const paymentSection = section('financial-payments','bx-receipt','Payment history','',empty('bx-receipt','No posted payments available','There are no posted payments to display for this period.'));
    const reconciliation = `<section class="management-financial-reconciliation" aria-labelledby="financial-total-title"><div><h3 id="financial-total-title">Account total</h3><p>Contributions apply automatically. Absence fines apply only after event attendance closes.</p></div><dl><div><dt>Contributions</dt><dd>${money(summary.contribution_catalog_total)}</dd></div><div><dt>Event absence charges</dt><dd>${money(summary.event_fine_total)}</dd></div><div><dt>Violation charges</dt><dd>${money(summary.violation_total)}</dd></div><div><dt>Less: payments received</dt><dd>${money(summary.amount_paid)}</dd></div><div class="management-financial-balance"><dt>Total to pay</dt><dd>${money(summary.total_to_pay)}</dd></div></dl></section>`;
    const statusLabel={UNPAID:'Unpaid',NO_CHARGES:'No charges',PAID:'Paid',PARTIALLY_PAID:'Partially paid'}[summary.payment_status] || 'Unpaid';
    return `<div class="management-financial-layout">${profile}<div class="management-financial-main" tabindex="0" role="region" aria-label="Student financial details">
      <div class="management-financial-period"><div><span class="management-financial-kicker">${escape(financial.organization.code)} · FINANCIAL OVERVIEW</span><h3>${escape(financial.period.text)}</h3></div><div class="management-financial-actions"><span class="management-financial-status"><span aria-hidden="true"></span>${statusLabel}</span><button type="button" class="btn btn-primary management-financial-pay" disabled title="Payment collection is not enabled."><i class="bx bx-wallet me-1" aria-hidden="true"></i>Pay</button></div></div>
      ${stats}
      ${contributionSection}${eventSection}${violationSection}${paymentSection}${reconciliation}
    </div></div>`;
  }
  window.SynkManagementFinancial = { render };
})();
