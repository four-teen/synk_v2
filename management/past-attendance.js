'use strict';
(() => {
  const form = document.getElementById('past-attendance-form');
  if (!form) return;

  const modal = document.getElementById('management-past-attendance');
  const config = JSON.parse(document.getElementById('management-config').textContent);
  const eventTemplate = form.dataset.template === 'EVENT';
  const listPage = eventTemplate ? 'events.php' : 'flag-raising.php';
  const initialLabel = eventTemplate ? 'Preview attendance' : 'Import attendance';
  const fields = document.getElementById('past-attendance-fields');
  const error = document.getElementById('past-attendance-error');
  const preview = document.getElementById('past-attendance-preview');
  const summary = document.getElementById('past-attendance-summary');
  const exceptions = document.getElementById('past-attendance-exceptions');
  const issuesTitle = document.getElementById('past-attendance-issues-title');
  const issuesList = document.getElementById('past-attendance-issues');
  const duplicates = document.getElementById('past-attendance-duplicates');
  const duplicatesTitle = document.getElementById('past-attendance-duplicates-title');
  const duplicatesList = document.getElementById('past-attendance-duplicate-rows');
  const eventLink = document.getElementById('past-attendance-event-link');
  const save = document.getElementById('past-attendance-save');
  const examTerm = form.elements.import_exam_term;
  const number = new Intl.NumberFormat();
  const currency = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
  let busy = false;
  let reviewed = null;
  let refreshUrl = '';
  let termChosen = false;

  function clearResult() {
    error.hidden = true;
    preview.hidden = true;
    preview.classList.replace('alert-success', 'alert-info');
    exceptions.hidden = true;
    issuesList.replaceChildren();
    duplicates.hidden = true;
    duplicates.open = false;
    duplicatesList.replaceChildren();
    eventLink.hidden = true;
    save.hidden = false;
    save.textContent = initialLabel;
    reviewed = null;
  }

  function appendWorkbookRow(list, row, detail) {
    const item = document.createElement('li');
    item.className = 'text-break';
    const id = document.createElement('strong');
    id.textContent = row.student_number || row.cells.join(', ');
    item.append(id, `${row.student_number ? ` · ${row.cells.join(', ')}` : ''} · ${detail}`);
    list.append(item);
  }

  function showReview(result) {
    const groups = [
      {rows: result.unmatched, reason: 'No roster match'},
      {rows: result.ambiguous, reason: 'Multiple roster matches'},
    ];
    for (const {rows, reason} of groups) {
      for (const row of rows) appendWorkbookRow(issuesList, row, reason);
    }
    for (const row of result.problems ?? []) appendWorkbookRow(issuesList, row, row.reason);
    const count = issuesList.children.length;
    issuesTitle.textContent = `${number.format(count)} ${eventTemplate ? (count === 1 ? 'item' : 'items') : (count === 1 ? 'student ID' : 'student IDs')} to review`;
    exceptions.hidden = count === 0;
    for (const row of result.duplicates) {
      appendWorkbookRow(duplicatesList, row, `${number.format(row.occurrences ?? row.cells.length)} occurrences`);
    }
    duplicatesTitle.textContent = `Repeated student IDs (${number.format(result.duplicates.length)})`;
    duplicates.hidden = result.duplicates.length === 0;
  }

  function showEventLink(event, savedToEvents = false) {
    if (!event.event_id) return;
    const url = new URL(listPage, document.baseURI);
    url.search = new URLSearchParams({term: event.term, exam_term: event.exam_term});
    if (savedToEvents) {
      url.searchParams.set('saved_event_id', event.event_id);
      refreshUrl = url.href;
    } else {
      url.searchParams.set('event_id', event.event_id);
    }
    url.hash = `event-${event.event_id}`;
    eventLink.href = url.href;
    eventLink.hidden = false;
  }

  async function request(body, action, token = '') {
    body.set('action', action);
    body.set('preview_token', token);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(config.endpoint, {method: 'POST', credentials: 'same-origin', body, signal: controller.signal});
      const data = await response.json();
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Attendance could not be recorded.');
      return data;
    } finally {
      clearTimeout(timer);
    }
  }

  function showEventResult(result, saved) {
    const counts = saved ?? result;
    const closed = Boolean(saved) || result.attendance_status === 'CLOSED';
    const status = saved ? 'Attendance saved and closed.' : (closed ? 'Attendance is already closed.' : 'Review before closing attendance.');
    summary.textContent = `${result.title} · ${result.event_date} · ${result.period}. ${status} ` +
      `${number.format(counts.present)} present, ${number.format(counts.absent)} absent, ${number.format(counts.exempted)} exempted. ` +
      `${currency.format(Number(counts.absence_fine))} per absence.`;
    if (!closed) {
      const rows = result.workbook_counts;
      summary.textContent += ` The workbook has ${number.format(rows.complete)} complete, ${number.format(rows.one_entry)} one-sided, and ${number.format(rows.blank)} blank attendance rows.`;
    }
    if (!closed && result.existing_event) summary.textContent += ' Saving will use the existing event with this title and date.';
    if (!closed && !result.can_import) summary.textContent += ' There are no eligible students in this period.';
    showReview(counts.review ?? result.review);
    if (closed) showEventLink(counts, Boolean(saved));
  }

  function showError(failure) {
    clearResult();
    error.textContent = failure instanceof SyntaxError || failure instanceof TypeError || failure.name === 'AbortError'
      ? 'The request was interrupted. Import again to check which students are already recorded.'
      : failure.message;
    error.hidden = false;
  }

  form.addEventListener('input', clearResult);
  form.addEventListener('change', clearResult);
  examTerm.addEventListener('change', () => { termChosen = Boolean(examTerm.value); });
  form.elements.event_date.addEventListener('change', () => {
    if (termChosen) return;
    const month = Number(form.elements.event_date.value.slice(5, 7));
    examTerm.value = !month || month === 7 ? ''
      : (month >= 11 || (month >= 4 && month <= 6) ? 'FINAL' : 'MIDTERM');
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || save.hidden) return;
    const confirmation = reviewed;
    clearResult();
    const body = new FormData(form);
    for (const key of ['college_id', 'campus_id', 'organization_id', 'current_term', 'current_exam_term', 'current_period_version']) {
      body.set(key, config[key]);
    }
    body.set('csrf_token', config.csrf_token);
    busy = fields.disabled = save.disabled = true;
    save.textContent = confirmation ? 'Saving…' : (eventTemplate ? 'Checking…' : 'Importing…');
    try {
      const result = confirmation ?? (await request(body, 'attendance_past_preview')).preview;
      if (confirmation) body.set('confirm', '1');
      const saved = result.can_import && (!eventTemplate || confirmation) ? await request(body, 'attendance_past_save', result.token) : null;
      if (eventTemplate) {
        showEventResult(result, saved);
        reviewed = !saved && result.can_import ? result : null;
      } else {
        if (saved?.event_id || result.event_id) refreshUrl = new URL(listPage, document.baseURI).href;
        const closed = result.attendance_status === 'CLOSED';
        const outcome = saved
          ? `Saved ${number.format(saved.recorded)} as present.`
          : (closed && result.new_present
            ? 'Attendance is closed. Reopen the event to add the new matches.'
            : (result.already_present ? 'All matched students are already saved.' : 'No student IDs matched the roster.'));
        summary.textContent = `${result.title} · ${result.event_date} · ${result.period}. ${outcome} ` +
          `${number.format(result.already_present)} already marked present. ${number.format(result.duplicates)} repeated rows skipped.`;
        showReview(saved?.review ?? result.review);
        showEventLink(saved ?? result);
      }
      if (saved) preview.classList.replace('alert-info', 'alert-success');
      save.hidden = !reviewed;
      preview.hidden = false;
      preview.scrollIntoView({block: 'nearest'});
    } catch (failure) {
      showError(failure);
    } finally {
      busy = fields.disabled = save.disabled = false;
      save.textContent = reviewed ? 'Save closed attendance' : initialLabel;
    }
  });

  modal.addEventListener('hide.bs.modal', event => {
    if (busy) event.preventDefault();
  });
  modal.addEventListener('hidden.bs.modal', () => {
    if (refreshUrl) {
      window.location.assign(refreshUrl);
      return;
    }
    form.reset();
    termChosen = false;
    clearResult();
  });
})();
