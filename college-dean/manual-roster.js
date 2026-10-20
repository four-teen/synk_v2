(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('deanManualRosterModal')) return;
  const config = JSON.parse($('deanWorkloadConfig').textContent), modal = new bootstrap.Modal($('deanManualRosterModal'));
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const state = { context: null, mode: '', number: '', version: '', token: '', transfer: false, busy: false, generation: 0, result: null };
  function notice(message = '') { $('deanManualNotice').textContent = message; $('deanManualNotice').classList.toggle('d-none', !message); }
  function busy(value) {
    state.busy = value;
    ['deanManualLookup', 'deanManualNumber', 'deanManualCancel', 'deanManualClose', 'deanManualBack'].forEach(id => { $(id).disabled = value; });
    $('deanManualDetails').disabled = value || !state.mode; $('deanManualNewFields').disabled = state.mode !== 'new';
    $('deanManualReviewButton').disabled = value || !state.mode;
    $('deanManualTransferConfirmed').disabled = value;
    $('deanManualConfirm').disabled = value || !state.token || (state.transfer && !$('deanManualTransferConfirmed').checked);
    $('deanManualRosterModal').setAttribute('aria-busy', String(value));
  }
  async function request(action, values = {}) {
    const body = new FormData();
    Object.entries({ csrf_token: config.csrf_token, college_id: config.college_id, campus_id: config.campus_id, ay_id: config.ay_id, semester: config.semester,
      faculty_id: state.context.class.faculty_id, offering_id: state.context.class.offering_id, action, ...values }).forEach(([key, value]) => body.append(key, value));
    let response, data;
    try { response = await fetch('../backend/query_college_dean_manual_roster.php', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' }); }
    catch (_) { throw new Error('Unable to connect. Please try again.'); }
    try { data = await response.json(); } catch (_) { throw new Error('Unable to read the response. Reload the page and check the class list before retrying.'); }
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to complete the class-list request.');
    return data;
  }
  function cancelPreview() { const token = state.token; state.token = ''; if (token && state.context) request('cancel', { preview_token: token }).catch(() => {}); }
  function entry() {
    cancelPreview(); state.transfer = false;
    $('deanManualEntry').hidden = false; $('deanManualReview').hidden = true; $('deanManualBack').hidden = true;
    $('deanManualConfirm').hidden = true; $('deanManualReviewButton').hidden = false; $('deanManualTransferConfirmed').checked = false; busy(false);
  }
  $('deanWorkload').addEventListener('manual-roster-open', event => {
    state.generation++; state.context = event.detail; state.mode = ''; state.number = ''; state.version = ''; state.result = null;
    $('deanManualLookupForm').reset(); $('deanManualDetailsForm').reset(); notice(); entry();
    $('deanManualNewFields').hidden = true; $('deanManualIdentity').classList.add('d-none');
    const row = state.context.class;
    $('deanManualClass').textContent = `${row.subject_code} · ${row.section_display} · ${row.faculty_name} · ${row.term_text}`; modal.show();
  });
  $('deanManualNumber').addEventListener('input', () => { state.generation++; state.mode = ''; state.number = ''; state.version = ''; entry(); $('deanManualNewFields').hidden = true; $('deanManualIdentity').classList.add('d-none'); notice(); });
  $('deanManualLookupForm').addEventListener('submit', async event => {
    event.preventDefault(); if (state.busy || !state.context) return;
    const generation = ++state.generation; state.mode = ''; cancelPreview(); busy(true); notice();
    $('deanManualIdentity').classList.add('d-none'); $('deanManualNewFields').hidden = true;
    try {
      const number = $('deanManualNumber').value.trim(), data = await request('lookup', { student_number: number });
      if (generation !== state.generation) return;
      state.number = number; state.mode = data.found ? 'existing' : 'new'; state.version = data.student?.version || '';
      $('deanManualIdentity').textContent = data.found ? `${data.student.student_number} · ${data.student.student_name}. Existing profile will be reused.` : `No saved profile for ${number}. Complete the new student details below.`;
      $('deanManualIdentity').classList.remove('d-none'); $('deanManualNewFields').hidden = data.found;
      $('deanManualProgram').innerHTML = data.programs.map(row => `<option value="${row.program_id}">${escape(row.label)}</option>`).join('');
      $('deanManualProgram').value = String(state.context.class.program_id); $('deanManualYear').value = String(state.context.class.year_level);
    } catch (error) { if (generation === state.generation) notice(error.message); }
    finally { if (generation === state.generation) busy(false); }
  });
  $('deanManualDetailsForm').addEventListener('submit', async event => {
    event.preventDefault(); if (state.busy || !state.mode) return;
    const generation = ++state.generation; cancelPreview(); notice(); busy(true);
    try {
      const data = await request('preview', { student_number: state.number, student_mode: state.mode, profile_version: state.version,
        first_name: $('deanManualFirst').value, last_name: $('deanManualLast').value, middle_name: $('deanManualMiddle').value, suffix_name: $('deanManualSuffix').value,
        email_address: $('deanManualEmail').value, student_program_id: $('deanManualProgram').value, student_year_level: $('deanManualYear').value,
        reason_code: $('deanManualReason').value, reason_note: $('deanManualNote').value });
      if (generation !== state.generation) return;
      state.token = data.preview_token; const review = data.review; state.transfer = review.action === 'transfer';
      $('deanManualReviewContent').innerHTML = `<dl class="row mb-0"><dt class="col-sm-3">Student</dt><dd class="col-sm-9">${escape(review.student_number)} · ${escape(review.student_name)}</dd><dt class="col-sm-3">Profile</dt><dd class="col-sm-9">${review.new_profile ? 'Create new student profile' : 'Reuse existing profile'}</dd><dt class="col-sm-3">Class</dt><dd class="col-sm-9">${escape(review.target.subject_code)} · ${escape(review.target.descriptive_title)}<br>${escape(review.target.section_display)} · ${escape(review.target.faculty_name)}</dd><dt class="col-sm-3">Reason</dt><dd class="col-sm-9">${escape(review.reason)}</dd>${review.note ? `<dt class="col-sm-3">Note</dt><dd class="col-sm-9 dean-manual-note">${escape(review.note)}</dd>` : ''}${review.new_profile ? `<dt class="col-sm-3">Student program</dt><dd class="col-sm-9">${escape(review.student_program)} · Year ${escape(review.student_year_level)}</dd><dt class="col-sm-3">School email</dt><dd class="col-sm-9">${escape(review.email_address || 'Pending verification')}</dd>` : ''}</dl>`;
      $('deanManualPrevious').innerHTML = `<ul class="mb-0">${review.previous_classes.map(row => `<li>${escape(row.subject_code)} · ${escape(row.section)} · ${escape(row.faculty_name)}</li>`).join('')}</ul>`;
      $('deanManualTransfer').hidden = !state.transfer; $('deanManualTransferConfirmed').checked = false;
      $('deanManualEntry').hidden = true; $('deanManualReview').hidden = false; $('deanManualBack').hidden = false; $('deanManualConfirm').hidden = false; $('deanManualReviewButton').hidden = true;
      $('deanManualConfirm').textContent = state.transfer ? 'Confirm Transfer' : 'Confirm Addition';
    } catch (error) { if (generation === state.generation) notice(error.message); }
    finally { if (generation === state.generation) busy(false); }
  });
  $('deanManualBack').addEventListener('click', () => { notice(); entry(); });
  $('deanManualTransferConfirmed').addEventListener('change', () => busy(state.busy));
  $('deanManualConfirm').addEventListener('click', async () => {
    if (state.busy || !state.token || (state.transfer && !$('deanManualTransferConfirmed').checked)) return;
    notice(); busy(true);
    try { const data = await request('confirm', { preview_token: state.token, confirm_transfer: $('deanManualTransferConfirmed').checked ? '1' : '0' }); state.token = ''; state.result = data.result; busy(false); modal.hide(); }
    catch (error) { notice(error.message); busy(false); }
  });
  $('deanManualRosterModal').addEventListener('hide.bs.modal', event => { if (state.busy) event.preventDefault(); else { state.generation++; cancelPreview(); } });
  $('deanManualRosterModal').addEventListener('hidden.bs.modal', () => {
    const context = state.context, result = state.result; state.context = null;
    $('deanWorkload').dispatchEvent(new CustomEvent('manual-roster-closed', { detail: { context, result } }));
  });
})();
