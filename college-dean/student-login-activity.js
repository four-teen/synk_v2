(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('deanStudentsConfig').textContent);
  if (!config.login_activity_enabled || !$('deanStudentLoginModal')) return;
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const number = value => Number(value || 0).toLocaleString();
  const formatter = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const date = value => value && Number.isFinite(new Date(value).getTime()) ? formatter.format(new Date(value)) : 'Not yet recorded';
  const state = { studentId: 0, page: 1, pages: 1, snapshot: 0, generation: 0 };
  const modal = new bootstrap.Modal($('deanStudentLoginModal'));

  function summary(data = {}) {
    const pending = !Object.prototype.hasOwnProperty.call(data, 'total_attempts');
    const metrics = [
      ['Successful logins', pending ? '—' : number(data.successful_logins), 'Completed Google sign-ins'],
      ['Failed attempts', pending ? '—' : number(data.failed_attempts), 'Includes cancelled sign-ins'],
      ['Total attempts', pending ? '—' : number(data.total_attempts), pending ? 'All recorded terms' : `${number(data.success_rate)}% success rate`],
      ['Last successful login', pending ? '—' : date(data.last_success_at), 'Philippine Time']
    ];
    $('deanStudentLoginSummary').innerHTML = metrics.map(([label, value, note], index) => `<div class="dean-student-login-stat"><span class="dean-login-stat-label">${escape(label)}</span><strong class="dean-login-stat-value${index === 3 ? ' dean-login-stat-date' : ''}">${escape(value)}</strong><small class="text-muted">${escape(note)}</small></div>`).join('');
  }

  async function load(fresh = false) {
    if (!state.studentId) return;
    if (fresh) { state.page = 1; state.snapshot = 0; }
    const generation = ++state.generation;
    const studentId = state.studentId;
    const freshSnapshot = state.snapshot === 0;
    const outcome = $('deanStudentLoginOutcome').value;
    $('deanStudentLoginNotice').className = 'alert alert-danger d-none';
    $('deanStudentLoginRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading login activity...</td></tr>';
    $('deanStudentLoginPageInfo').textContent = '';
    $('deanStudentLoginPrevious').disabled = true;
    $('deanStudentLoginNext').disabled = true;
    $('deanStudentLoginRefresh').disabled = true;
    $('deanStudentLoginModal').setAttribute('aria-busy', 'true');
    summary();
    try {
      const body = new FormData();
      Object.entries({ csrf_token: config.csrf_token, college_id: config.college_id, campus_id: config.campus_id,
        action: 'login_activity', student_id: studentId, activity_page: state.page, activity_outcome: outcome, activity_snapshot: state.snapshot
      }).forEach(([key, value]) => body.append(key, value));
      let response, data;
      try { response = await fetch('../backend/query_college_dean_students.php', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' }); }
      catch (_) { throw new Error('Unable to connect. Please try again.'); }
      try { data = await response.json(); }
      catch (_) { throw new Error('Unable to load login activity. Reload the page and try again.'); }
      if (generation !== state.generation || studentId !== state.studentId) return;
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to load login activity.');
      state.page = data.pagination.page; state.pages = data.pagination.pages; state.snapshot = data.snapshot;
      $('deanStudentLoginIdentity').textContent = `${data.student.student_number} · ${data.student.student_name}`;
      summary(data.summary);
      $('deanStudentLoginRows').innerHTML = data.rows.length ? data.rows.map(row => `<tr>
        <td class="dean-login-date"><time datetime="${escape(row.occurred_at)}">${escape(date(row.occurred_at))}</time></td>
        <td><span class="badge ${row.outcome === 'success' ? 'bg-label-success' : 'bg-label-danger'}">${row.outcome === 'success' ? 'Successful' : 'Failed'}</span></td>
        <td class="dean-login-reason">${escape(row.reason)}<small class="d-block text-muted mt-1">${escape(row.auth_method === 'google' ? 'Google sign-in' : row.auth_method)}</small></td>
        <td class="dean-login-email">${escape(row.submitted_email || 'Not supplied')}<small class="d-block text-muted mt-1">${row.identity_verified ? 'Google identity verified' : 'Identity not verified'}</small></td>
        <td><span>${escape(row.browser)} · ${escape(row.operating_system)}</span><small class="d-block text-muted">${escape(row.device_type)}</small>${row.user_agent ? `<details class="small mt-1"><summary>Browser details</summary><div class="dean-login-agent mt-1">${escape(row.user_agent)}</div></details>` : ''}</td>
        <td class="dean-login-ip"><code>${escape(row.ip_address || 'Not available')}</code></td>
      </tr>`).join('') : `<tr><td colspan="6" class="text-center text-muted py-4">${outcome === 'all' ? 'No login attempts have been recorded for this student yet.' : `No ${outcome === 'success' ? 'successful logins' : 'failed attempts'} are recorded for this student.`}</td></tr>`;
      const page = data.pagination;
      $('deanStudentLoginPageInfo').textContent = page.total ? `${number(page.from)}–${number(page.to)} of ${number(page.total)} attempts · Page ${page.page} of ${page.pages}` : '0 attempts';
      $('deanStudentLoginPrevious').disabled = page.page <= 1;
      $('deanStudentLoginNext').disabled = page.page >= page.pages;
      if (freshSnapshot) {
        const button = document.querySelector(`#deanStudentsRows [data-student-login="${studentId}"]`);
        const count = button?.querySelector('[data-login-count]');
        const failures = button?.closest('td')?.querySelector('[data-login-failures]');
        if (count) count.textContent = number(data.summary.successful_logins);
        if (failures) failures.textContent = `${number(data.summary.failed_attempts)} failed`;
      }
    } catch (error) {
      if (generation !== state.generation || studentId !== state.studentId) return;
      $('deanStudentLoginNotice').textContent = error.message || 'Unable to connect. Please try again.';
      $('deanStudentLoginNotice').className = 'alert alert-danger';
      $('deanStudentLoginRows').innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Login activity could not be loaded. Use Refresh history to try again.</td></tr>';
    } finally {
      if (generation === state.generation) {
        $('deanStudentLoginRefresh').disabled = false;
        $('deanStudentLoginModal').setAttribute('aria-busy', 'false');
      }
    }
  }

  $('deanStudentsRows').addEventListener('click', event => {
    const button = event.target.closest('[data-student-login]');
    if (!button || button.disabled) return;
    state.studentId = Number(button.dataset.studentLogin); state.page = 1; state.snapshot = 0;
    $('deanStudentLoginTitle').textContent = 'Student login activity';
    $('deanStudentLoginIdentity').textContent = 'All recorded sign-in attempts';
    $('deanStudentLoginOutcome').value = 'all';
    modal.show(); load(true);
  });
  $('deanStudentLoginOutcome').addEventListener('change', () => { state.page = 1; load(); });
  $('deanStudentLoginRefresh').addEventListener('click', () => load(true));
  $('deanStudentLoginPrevious').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
  $('deanStudentLoginNext').addEventListener('click', () => { if (state.page < state.pages) { state.page++; load(); } });
  $('deanStudentLoginModal').addEventListener('hide.bs.modal', () => { state.generation++; state.studentId = 0; });
})();
