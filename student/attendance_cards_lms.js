(() => {
  'use strict';
  const root = document.getElementById('studentAssessments');
  const settings = document.getElementById('studentAttendanceCardConfig');
  if (!root || !settings) return;

  const config = JSON.parse(settings.textContent);
  const {escape, dateTime, request} = window.synkOnlineAttendanceLms;
  const cards = [...root.querySelectorAll('[data-attendance-card]')];
  const subjectPanel = document.getElementById('studentAttendanceSubject');
  const errorPanel = document.getElementById('studentAttendanceError');
  const pollInterval = 30000;
  const rendered = new WeakMap();
  let subjects = new Map();
  let clock = 0, timer, reading = false, saving = null, revision = 0, signedOut = false;
  let readError = '', checkInError = null;

  const now = () => performance.now() + clock;
  const live = () => !signedOut && !document.hidden && root.dataset.view !== 'assessment';

  function currentSessions(sessions) {
    const open = sessions.filter(row => row.state === 'open').sort((a, b) => a.closes - b.closes);
    if (open.length) return open;
    const [latest] = sessions.filter(row => row.state === 'closed')
      .sort((a, b) => b.closes - a.closes || b.session_id - a.session_id);
    if (latest) return [latest];
    return sessions.filter(row => row.state === 'scheduled').sort((a, b) => a.opens - b.opens).slice(0, 1);
  }

  function status(row, current) {
    if (row.result === 'present') return row.checked_in_at
      ? ['submitted', 'bx-check-circle', 'Submitted', 'Checked in '+dateTime(row.checked_in_at)]
      : ['submitted', 'bx-check-circle', 'Present', 'Marked present by your professor'];
    if (row.result === 'absent') return ['missed', 'bx-x-circle', row.checked_in_at ? 'Marked absent' : 'You missed attendance',
      'Closed '+dateTime(row.closes_at)];
    if (row.state === 'scheduled') return ['scheduled', 'bx-time-five', 'Attendance scheduled', 'Opens '+dateTime(row.opens_at)];
    if (row.state === 'open' && current < row.closes) return ['open', 'bxs-heart', 'Attendance open', 'Closes '+dateTime(row.closes_at)];
    return ['closed', 'bx-lock-alt', 'Attendance closed', 'Closed '+dateTime(row.closes_at)];
  }

  function rowMarkup(row, section, current) {
    const [state, icon, label, detail] = status(row, current);
    const attributes = `class="student-attendance-row is-${state}" data-attendance-session="${row.session_id}"`;
    const title = [row.title, section].filter(Boolean).join(' · ');
    const content = `<i class="bx ${icon} student-attendance-icon" aria-hidden="true"></i>
      <span class="student-attendance-content"><strong>${label}</strong><span>${escape(title)}</span><small>${escape(detail)}</small></span>`;
    if (state === 'open') {
      const action = saving === row.session_id ? 'Submitting…' : 'Check in';
      return `<button type="button" ${attributes} data-attendance-check-in="${row.session_id}" aria-disabled="${saving !== null}">
        ${content}<span class="student-attendance-action">${action}</span></button>`;
    }
    return `<div ${attributes} tabindex="-1">${content}</div>`;
  }

  function renderPanel(panel, key, current) {
    const sections = subjects.get(key) ?? [];
    const markup = sections.map(group => currentSessions(group.sessions)
      .map(row => rowMarkup(row, sections.length > 1 ? group.section : '', current)).join('')).join('');
    if (rendered.get(panel) === markup) return;
    const focused = panel.contains(document.activeElement) ? document.activeElement.dataset.attendanceSession : null;
    panel.innerHTML = markup;
    panel.hidden = !markup;
    rendered.set(panel, markup);
    if (focused) panel.querySelector(`[data-attendance-session="${focused}"]`)?.focus({preventScroll:true});
  }

  function render() {
    const current = now();
    cards.forEach(panel => renderPanel(panel, panel.dataset.attendanceCard, current));
    renderPanel(subjectPanel, root.dataset.attendanceSubject, current);
    const message = checkInError?.message || readError;
    if (errorPanel.textContent !== message) errorPanel.textContent = message;
    errorPanel.hidden = !message || root.dataset.view === 'assessment';
  }

  function schedule() {
    clearTimeout(timer);
    if (!live()) return;
    const current = now();
    let delay = pollInterval;
    for (const groups of subjects.values()) for (const group of groups) for (const row of group.sessions) {
      const boundary = row.state === 'scheduled' ? row.opens : row.state === 'open' ? row.closes : 0;
      if (boundary > current) delay = Math.min(delay, boundary - current);
    }
    timer = setTimeout(() => { render(); refresh(); schedule(); }, Math.ceil(delay));
  }

  function authError(error) {
    if (error.status !== 401 && error.status !== 403) return;
    signedOut = true;
    subjects.clear();
  }

  async function refresh() {
    if (!live() || reading || saving !== null) return;
    reading = true;
    const started = performance.now();
    const startedRevision = revision;
    try {
      const data = await request(config, 'cards');
      if (startedRevision !== revision) return;
      clock = Date.parse(data.server_time) - (started + performance.now()) / 2;
      const updated = new Map();
      for (const group of data.subjects) {
        group.sessions = group.sessions.map(row => ({...row, opens:Date.parse(row.opens_at), closes:Date.parse(row.closes_at)}));
        if (!updated.has(group.subject_key)) updated.set(group.subject_key, []);
        updated.get(group.subject_key).push(group);
      }
      subjects = updated;
      readError = '';
      if (checkInError && ![...subjects.values()].flat().some(group => currentSessions(group.sessions)
        .some(row => row.session_id === checkInError.sessionId))) checkInError = null;
    } catch (error) {
      if (startedRevision !== revision) return;
      authError(error);
      readError = error.message;
    } finally {
      reading = false;
      render();
      if (startedRevision !== revision && saving === null) refresh();
      else schedule();
    }
  }

  root.addEventListener('click', async event => {
    const button = event.target.closest('[data-attendance-check-in]');
    if (!button || saving !== null || !live()) return;
    const sessionId = Number(button.dataset.attendanceCheckIn);
    const key = button.closest('[data-attendance-card]')?.dataset.attendanceCard ?? root.dataset.attendanceSubject;
    const row = (subjects.get(key) ?? []).flatMap(group => group.sessions).find(row => row.session_id === sessionId);
    if (!row || status(row, now())[0] !== 'open') { render(); refresh(); return; }

    saving = sessionId;
    revision++;
    checkInError = null;
    render();
    try {
      const result = await request(config, 'check_in', {session_id:sessionId});
      Object.assign(row, {state:result.state, result:result.result, checked_in_at:result.checked_in_at});
      readError = '';
    } catch (error) {
      authError(error);
      checkInError = {sessionId, message:error.message};
    } finally {
      saving = null;
      render();
      refresh();
    }
  });

  let previousView = root.dataset.view;
  new MutationObserver(() => {
    if (root.dataset.view === 'assessment') clearTimeout(timer);
    render();
    if (previousView === 'assessment') refresh();
    previousView = root.dataset.view;
  }).observe(root, {attributes:true, attributeFilter:['data-view', 'data-attendance-subject']});
  document.getElementById('studentAssessmentRefresh').addEventListener('click', () => { checkInError = null; render(); refresh(); });
  document.addEventListener('visibilitychange', () => { render(); refresh(); schedule(); });
  window.addEventListener('focus', () => { render(); refresh(); });
  refresh();
})();
