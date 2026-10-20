(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('deanClearRosterModal')) return;
  const config = JSON.parse($('deanWorkloadConfig').textContent), modal = new bootstrap.Modal($('deanClearRosterModal'));
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const state = { context: null, token: '', busy: false, result: null, generation: 0 };
  function notice(message = '') { $('deanClearNotice').textContent = message; $('deanClearNotice').classList.toggle('d-none', !message); }
  function busy(value) {
    state.busy = value;
    ['deanClearClose','deanClearCancel','deanClearRetry','deanClearConfirmed'].forEach(id => { $(id).disabled = value; });
    $('deanClearConfirm').disabled = value || !state.token || !$('deanClearConfirmed').checked;
    $('deanClearRosterModal').setAttribute('aria-busy', String(value));
  }
  async function request(action, values = {}) {
    const body = new FormData(), row = state.context.class;
    Object.entries({ csrf_token: config.csrf_token, college_id: config.college_id, campus_id: config.campus_id, ay_id: config.ay_id, semester: config.semester,
      faculty_id: row.faculty_id, offering_id: row.offering_id, action, ...values }).forEach(([key,value]) => body.append(key,value));
    let response, data;
    try { response = await fetch('../backend/query_college_dean_clear_roster.php', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' }); }
    catch (_) { throw new Error('Unable to connect. Refresh the class list before retrying.'); }
    try { data = await response.json(); } catch (_) { throw new Error('Unable to read the response. Refresh the class list before retrying.'); }
    if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to clear the class list.');
    return data;
  }
  function cancelPreview() { const token = state.token; state.token = ''; if (token && state.context) request('cancel',{ preview_token: token }).catch(() => {}); }
  async function preview() {
    if (state.busy || !state.context) return;
    const generation = ++state.generation;
    cancelPreview(); notice(); busy(true); $('deanClearConfirmed').checked = false; $('deanClearAcknowledgement').hidden = true; $('deanClearRetry').hidden = true;
    $('deanClearPreview').textContent = 'Loading the students to clear...';
    try {
      const data = await request('preview');
      if (generation !== state.generation) return;
      state.token = data.preview_token;
      $('deanClearPreview').innerHTML = `<p class="fw-semibold">${Number(data.summary.students)} students will be cleared from this class.</p><div class="table-responsive"><table class="table dean-workload-table"><thead><tr><th>Student ID</th><th>Student Name</th><th>Entry</th></tr></thead><tbody>${data.students.map(row=>`<tr><td>${escape(row.student_number)}</td><td>${escape(row.student_name)}</td><td>${row.entry_source === 'manual' ? 'Manual' : 'Imported'}</td></tr>`).join('')}</tbody></table></div>`;
      $('deanClearAcknowledgement').hidden = false;
    } catch (error) { if (generation === state.generation) { $('deanClearPreview').textContent = ''; notice(error.message); $('deanClearRetry').hidden = false; } }
    finally { if (generation === state.generation) busy(false); }
  }
  $('deanWorkload').addEventListener('clear-roster-open', event => {
    state.context = event.detail; state.result = null;
    const row = state.context.class;
    $('deanClearClass').textContent = `${row.subject_code} · ${row.descriptive_title} · ${row.section_display} · ${row.faculty_name} · ${row.term_text}`;
    modal.show(); preview();
  });
  $('deanClearRetry').addEventListener('click', preview);
  $('deanClearConfirmed').addEventListener('change', () => busy(state.busy));
  $('deanClearConfirm').addEventListener('click', async () => {
    if (state.busy || !state.token || !$('deanClearConfirmed').checked) return;
    state.context.needsRefresh = true; notice(); busy(true);
    try {
      const data = await request('confirm',{preview_token: state.token, confirm_clear: '1'});
      state.token = ''; state.result = data.result; busy(false); modal.hide();
    } catch (error) {
      cancelPreview(); $('deanClearAcknowledgement').hidden = true; $('deanClearRetry').hidden = false; notice(error.message); busy(false);
    }
  });
  $('deanClearRosterModal').addEventListener('hide.bs.modal', event => { if (state.busy) event.preventDefault(); else { state.generation++; cancelPreview(); } });
  $('deanClearRosterModal').addEventListener('hidden.bs.modal', () => {
    const context = state.context, result = state.result; state.context = null;
    $('deanWorkload').dispatchEvent(new CustomEvent('clear-roster-closed',{detail:{context,result}}));
  });
})();
