(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('deanLoginReportConfig').textContent);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(value || 0).toLocaleString();
  const timeFormat = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const dayFormat = new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', month: 'short', day: '2-digit' });
  const time = value => value && Number.isFinite(new Date(value).getTime()) ? timeFormat.format(new Date(value)) : 'Not recorded';
  const day = value => dayFormat.format(new Date(value + 'T00:00:00Z'));
  const state = { page: 1, pages: 1, snapshot: 0, generation: 0, applied: null, studentId: 0, controller: null };
  const filters = () => ({ date_from: $('deanLoginFrom').value, date_to: $('deanLoginTo').value, program_id: $('deanLoginProgram').value,
    search: $('deanLoginSearch').value.trim(), outcome: $('deanLoginOutcome').value, page_size: $('deanLoginPageSize').value, student_id: state.studentId });
  const traceAttributes = row => `data-login-student="${row.student_id}" data-login-name="${escape(row.student_name)}" data-login-number="${escape(row.student_number)}"`;

  function summary(data = {}) {
    const pending = !Object.prototype.hasOwnProperty.call(data, 'total_attempts');
    const metrics = [
      ['Total attempts', data.total_attempts, 'All recorded sign-in outcomes'],
      ['Successful logins', data.successful_logins, 'Completed Google sign-ins'],
      ['Failed attempts', data.failed_attempts, 'Rejected or cancelled sign-ins'],
      ['Students who logged in', data.students_logged_in, 'Unique students with a successful login'],
      ['Students with failures', data.students_with_failures, 'Unique students with a failed attempt'],
      ['Success rate', data.success_rate, 'Successful logins / total attempts']
    ];
    $('deanLoginSummary').innerHTML = metrics.map(([label, value, note], index) => `<div class="card dean-login-kpi"><span class="dean-login-kpi-label">${escape(label)}</span><strong>${pending ? '—' : number(value) + (index === 5 ? '%' : '')}</strong><small>${escape(note)}</small></div>`).join('');
  }

  function insights(data) {
    const maximum = Math.max(1, ...data.daily.map(row => row.successful_logins + row.failed_attempts));
    $('deanLoginDaily').innerHTML = `<table class="dean-login-daily-table"><caption class="visually-hidden">Daily successful and failed sign-ins</caption><thead><tr><th scope="col">Day</th><th scope="col"><span class="visually-hidden">Comparison</span></th><th scope="col">Success</th><th scope="col">Failed</th></tr></thead><tbody>${data.daily.map(row => `<tr><th scope="row"><time datetime="${escape(row.day)}" title="${escape(row.day)}">${escape(day(row.day))}</time></th><td><div class="dean-login-bar" aria-hidden="true"><span class="dean-login-bar-success" style="width:${row.successful_logins * 100 / maximum}%"></span><span class="dean-login-bar-failure" style="width:${row.failed_attempts * 100 / maximum}%"></span></div></td><td>${number(row.successful_logins)}</td><td>${number(row.failed_attempts)}</td></tr>`).join('')}</tbody></table>`;
    $('deanLoginReasons').innerHTML = data.reasons.length ? data.reasons.map(row => `<div class="dean-login-reason-row"><span>${escape(row.reason)}</span><strong>${number(row.attempts)}</strong></div>`).join('') : '<p class="dean-login-placeholder mb-0">No failed attempts in this range.</p>';
    $('deanLoginAttention').innerHTML = data.attention.length ? data.attention.map(row => `<div class="dean-login-attention-row"><div><strong>${escape(row.student_name)}</strong><small class="text-muted">${escape(row.student_number)}</small><small><span class="text-danger">${number(row.failed_attempts)} failed</span> · ${number(row.successful_logins)} successful</small>${row.successful_logins === 0 ? '<small class="text-warning">No successful login in this range</small>' : ''}<small class="text-muted">Last failure: ${escape(time(row.last_failure_at))}</small></div><button type="button" class="btn btn-sm btn-outline-primary" ${traceAttributes(row)} aria-label="${escape('Trace login activity for ' + row.student_name)}">Trace</button></div>`).join('') : '<p class="dean-login-placeholder mb-0">No students with failed attempts in this range.</p>';
  }

  function rows(data) {
    $('deanLoginRows').innerHTML = data.rows.length ? data.rows.map(row => `<tr>
      <td class="dean-login-time"><time datetime="${escape(row.occurred_at)}">${escape(time(row.occurred_at))}</time><small class="text-muted">Philippine Time</small></td>
      <td class="dean-login-person"><button type="button" ${traceAttributes(row)} title="Trace this student's activity">${escape(row.student_name)}</button><small class="text-muted">${escape(row.student_number)}</small></td>
      <td><span class="badge ${row.outcome === 'success' ? 'bg-label-success' : 'bg-label-danger'}">${row.outcome === 'success' ? 'Successful' : 'Failed'}</span></td>
      <td class="dean-login-reason">${escape(row.reason)}<small class="text-muted">${escape(row.auth_method === 'google' ? 'Google sign-in' : row.auth_method)}</small></td>
      <td class="dean-login-email">${escape(row.submitted_email || 'Not supplied')}<small class="text-muted">${row.identity_verified ? 'Google identity verified' : 'Identity not verified'}</small></td>
      <td class="dean-login-device">${escape(row.browser)} · ${escape(row.operating_system)}<small class="text-muted">${escape(row.device_type)}</small>${row.user_agent ? `<details class="small mt-1"><summary>Browser details</summary><div class="dean-login-agent mt-1">${escape(row.user_agent)}</div></details>` : ''}</td>
      <td><code>${escape(row.ip_address || 'Not available')}</code></td>
    </tr>`).join('') : '<tr><td colspan="7" class="text-center text-muted py-4">No login attempts match these filters. Try another date range or clear the filters.</td></tr>';
    const page = data.pagination;
    $('deanLoginPageInfo').textContent = page.total ? `${number(page.from)}–${number(page.to)} of ${number(page.total)} attempts · Page ${page.page} of ${page.pages}` : '0 attempts';
    $('deanLoginPrevious').disabled = page.page <= 1;
    $('deanLoginNext').disabled = page.page >= page.pages;
  }

  async function load(fresh = true) {
    state.controller?.abort(); state.controller = new AbortController();
    if (fresh || !state.applied) { state.page = 1; state.snapshot = 0; state.applied = filters(); }
    const generation = ++state.generation;
    $('deanLoginReport').setAttribute('aria-busy', 'true');
    $('deanLoginNotice').classList.add('d-none');
    $('deanLoginRows').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Loading login activity...</td></tr>';
    $('deanLoginRange').textContent = 'Loading activity...'; $('deanLoginUpdated').textContent = ''; $('deanLoginPageInfo').textContent = '';
    ['deanLoginPrevious', 'deanLoginNext', 'deanLoginRefresh', 'deanLoginApply'].forEach(id => { $(id).disabled = true; });
    ['deanLoginDaily', 'deanLoginReasons', 'deanLoginAttention'].forEach(id => { $(id).innerHTML = '<p class="dean-login-placeholder">Loading...</p>'; });
    summary();
    try {
      const body = new FormData();
      Object.entries({ ...state.applied, page: state.page, snapshot: state.snapshot, csrf_token: config.csrf_token,
        college_id: config.college_id, campus_id: config.campus_id }).forEach(([key, value]) => body.append(key, value));
      let response, data;
      try { response = await fetch('../backend/query_college_dean_login_activity.php', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', signal: state.controller.signal }); }
      catch (error) { if (error.name === 'AbortError') return; throw new Error('Unable to connect. Please try again.'); }
      try { data = await response.json(); } catch (_) { throw new Error('Unable to load login activity. Reload the page and try again.'); }
      if (generation !== state.generation) return;
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to load login activity.');
      state.page = data.pagination.page; state.pages = data.pagination.pages; state.snapshot = data.snapshot;
      const selectedProgram = $('deanLoginProgram').value;
      $('deanLoginProgram').innerHTML = '<option value="0">All college programs</option>' + data.programs.map(row => `<option value="${row.program_id}">${escape(row.label)}</option>`).join('');
      $('deanLoginProgram').value = data.programs.some(row => String(row.program_id) === selectedProgram) ? selectedProgram : '0';
      summary(data.summary); insights(data); rows(data);
      $('deanLoginRange').textContent = `${data.filters.date_from} through ${data.filters.date_to} · Philippine Time · ${number(data.summary.total_attempts)} attempts`;
      $('deanLoginUpdated').textContent = `Loaded ${time(data.generated_at)}`;
    } catch (error) {
      if (generation !== state.generation) return;
      $('deanLoginNotice').textContent = error.message; $('deanLoginNotice').classList.remove('d-none');
      $('deanLoginRows').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Login activity could not be loaded. Use Refresh to try again.</td></tr>';
      $('deanLoginRange').textContent = 'Report not loaded';
      ['deanLoginDaily', 'deanLoginReasons', 'deanLoginAttention'].forEach(id => { $(id).innerHTML = '<p class="dean-login-placeholder">Statistics are unavailable.</p>'; });
    } finally {
      if (generation === state.generation) {
        $('deanLoginReport').setAttribute('aria-busy', 'false'); $('deanLoginRefresh').disabled = false; $('deanLoginApply').disabled = false;
      }
    }
  }

  function clearTrace() { state.studentId = 0; $('deanLoginTrace').hidden = true; $('deanLoginTraceLabel').textContent = ''; }
  $('deanLoginFilters').addEventListener('submit', event => { event.preventDefault(); load(true); });
  $('deanLoginRefresh').addEventListener('click', () => load(true));
  $('deanLoginReset').addEventListener('click', () => {
    $('deanLoginFrom').value = config.date_from; $('deanLoginTo').value = config.date_to; $('deanLoginSearch').value = '';
    $('deanLoginProgram').value = '0'; $('deanLoginOutcome').value = 'all'; clearTrace(); load(true);
  });
  ['deanLoginOutcome', 'deanLoginPageSize'].forEach(id => $(id).addEventListener('change', () => load(true)));
  $('deanLoginPrevious').addEventListener('click', () => { if (state.page > 1) { state.page--; load(false); } });
  $('deanLoginNext').addEventListener('click', () => { if (state.page < state.pages) { state.page++; load(false); } });
  $('deanLoginClearTrace').addEventListener('click', () => { clearTrace(); load(true); });
  $('deanLoginReport').addEventListener('click', event => {
    const shortcut = event.target.closest('[data-login-days]');
    if (shortcut) {
      const first = new Date(config.today + 'T00:00:00Z'); first.setUTCDate(first.getUTCDate() - Number(shortcut.dataset.loginDays) + 1);
      $('deanLoginFrom').value = first.toISOString().slice(0, 10); $('deanLoginTo').value = config.today; load(true); return;
    }
    const trace = event.target.closest('[data-login-student]');
    if (trace) {
      state.studentId = Number(trace.dataset.loginStudent); $('deanLoginTrace').hidden = false;
      $('deanLoginTraceLabel').textContent = `Tracing ${trace.dataset.loginNumber} · ${trace.dataset.loginName}`;
      $('deanLoginSearch').value = ''; $('deanLoginOutcome').value = 'all'; load(true);
    }
  });
  load();
})();
