(function () {
  'use strict';
  const root = document.getElementById('collegeStudentActivity');
  const configNode = document.getElementById('studentActivityConfig');
  if (!root || !configNode) return;
  const config = JSON.parse(configNode.textContent);
  const $ = id => document.getElementById(id);
  const map = $('studentActivityMap'), search = $('studentActivitySearch');
  const number = value => Number(value || 0).toLocaleString();
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
  const tooltip = document.createElement('div');
  tooltip.className = 'student-activity-tooltip'; tooltip.id = 'studentActivityTooltip'; tooltip.setAttribute('role', 'tooltip'); tooltip.hidden = true;
  document.body.append(tooltip);
  let students = [], receivedAt = 0, loading = false, permitted = true, timer, activeSquare;
  let summaryKey = '';
  let livePresence = config.live_presence === true;
  const online = student => livePresence && student.presence === 'online' && (Date.now() - receivedAt) / 1000 < student.online_seconds;
  const status = student => !livePresence ? 'Recorded activity for this term' : online(student) ? 'Online now' : (Date.now() - receivedAt) / 1000 < student.session_seconds ? 'Offline · no recent connection' : student.successful_logins || student.presence !== 'never' ? 'Logged out' : 'No login recorded for this term';
  function textNode(tag, text, className) {
    const node = document.createElement(tag); node.textContent = text; if (className) node.className = className; return node;
  }
  function color(student) { return 'level-' + student.level + (online(student) ? ' is-online' : ''); }
  function hideTooltip() {
    tooltip.hidden = true;
    if (activeSquare) activeSquare.removeAttribute('aria-describedby');
    activeSquare = null;
  }
  function showTooltip(square) {
    const student = students.find(row => row.student_id === Number(square.dataset.studentId));
    if (!student) return;
    if (activeSquare && activeSquare !== square) activeSquare.removeAttribute('aria-describedby');
    activeSquare = square;
    const detail = document.createElement('div');
    detail.append(textNode('strong', student.student_name), textNode('small', student.student_number),
      textNode('small', number(student.successful_logins) + ' successful ' + (student.successful_logins === 1 ? 'login' : 'logins') + ' this term'),
      textNode('small', status(student), 'activity-status'));
    const loggedOut = status(student) === 'Logged out';
    const timestamp = loggedOut ? student.last_logout_at : student.last_seen_at;
    if (timestamp) detail.append(textNode('small', (loggedOut ? 'Signed out ' : 'Last seen ') + new Date(timestamp).toLocaleString('en-PH', { timeZone:'Asia/Manila', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' })));
    const zoom = textNode('span', '', 'student-activity-swatch ' + color(student)); zoom.setAttribute('aria-hidden', 'true');
    tooltip.replaceChildren(zoom, detail); tooltip.hidden = false;
    square.setAttribute('aria-describedby', tooltip.id);
    const rect = square.getBoundingClientRect(), tip = tooltip.getBoundingClientRect();
    tooltip.style.left = Math.max(12, Math.min(innerWidth - tip.width - 12, rect.left + rect.width / 2 - tip.width / 2)) + 'px';
    tooltip.style.top = (rect.top >= tip.height + 16 ? rect.top - tip.height - 10 : Math.min(innerHeight - tip.height - 12, rect.bottom + 10)) + 'px';
  }
  function summary() {
    const total = students.length, used = students.filter(row => row.successful_logins > 0).length;
    const entries = [[total, 'students']];
    if (livePresence) entries.push([students.filter(online).length, 'online now']);
    entries.push([used, 'with term logins'], [total - used, 'no term login recorded']);
    const key = entries.map(([value]) => value).join(':');
    if (summaryKey === key) return;
    summaryKey = key;
    $('studentActivitySummary').replaceChildren(...entries.map(([value, label], index) => {
      const span = document.createElement('span'); if (livePresence && index === 1) span.className = 'online-summary';
      span.append(textNode('strong', number(value)), document.createTextNode(label)); return span;
    }));
  }
  function render() {
    const terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
    const rows = students.filter(row => terms.every(term => normalize(row.student_name + ' ' + row.student_number).includes(term)));
    const existing = [...map.querySelectorAll('.student-activity-square')];
    if (rows.length && existing.length === rows.length && existing.every((square, index) => Number(square.dataset.studentId) === rows[index].student_id)) {
      map.setAttribute('aria-busy', 'false');
      refreshPresence();
      return;
    }
    const focused = document.activeElement?.dataset?.studentId;
    hideTooltip();
    const fragment = document.createDocumentFragment();
    rows.forEach((student, index) => {
      const square = document.createElement('button'); square.type = 'button'; square.className = 'student-activity-square ' + color(student);
      square.dataset.studentId = student.student_id;
      square.tabIndex = focused ? (String(student.student_id) === focused ? 0 : -1) : (index === 0 ? 0 : -1);
      square.setAttribute('aria-label', student.student_name + ', ' + student.student_number + ', ' + number(student.successful_logins) + ' successful logins, ' + status(student));
      fragment.append(square);
    });
    if (!rows.length) fragment.append(textNode('p', students.length ? 'No students match your search.' : 'No students are recorded in this college directory.', 'student-activity-copy mb-0'));
    map.replaceChildren(fragment); map.setAttribute('aria-busy', 'false');
    if (focused) map.querySelector('[data-student-id="' + focused + '"]')?.focus({ preventScroll:true });
    summary();
  }
  async function load() {
    if (loading || !permitted) return;
    loading = true; $('studentActivityRefresh').disabled = true;
    const controller = new AbortController();
    const requestTimeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch('../backend/query_college_dean_student_activity.php', { method:'POST', credentials:'same-origin', cache:'no-store', signal:controller.signal,
        body:new URLSearchParams({ csrf_token:config.csrf_token, college_id:config.college_id, campus_id:config.campus_id, ay_id:config.ay_id, semester:config.semester }) });
      let data;
      try { data = await response.json(); }
      catch (_) { throw Error('Unable to load student activity. Reload the page or try again.'); }
      if (!response.ok || data.status !== 'ok') {
        if ([401,403].includes(response.status)) { students = []; permitted = false; hideTooltip(); }
        throw Error(data.message || 'Unable to load student activity.');
      }
      students = data.students; receivedAt = Date.now(); livePresence = data.live_presence === true;
      $('studentActivityTerm').textContent = data.term.term_text;
      $('studentActivityOnlineLegend').hidden = !livePresence;
      $('studentActivityPresenceNote').textContent = livePresence ? 'Orange means a recently connected student session. After 15 minutes without activity, the session signs out and the square returns to its login-count color.' : 'Historical term. Live online status is shown only for the current academic term.';
      $('studentActivityNotice').hidden = true;
      $('studentActivityUpdated').textContent = 'Updated ' + new Date(data.generated_at).toLocaleTimeString('en-PH', { timeZone:'Asia/Manila', hour:'numeric', minute:'2-digit' }) + ' · refreshes every 30 seconds';
      render();
    } catch (error) {
      $('studentActivityNotice').textContent = error.name === 'AbortError' ? 'Student activity took too long to load. Retrying shortly.' : error.name === 'TypeError' ? 'Unable to connect. Retrying shortly.' : error.message || 'Live status is unavailable. Retrying shortly.';
      $('studentActivityNotice').hidden = false; map.setAttribute('aria-busy', 'false');
      if (!students.length) {
        map.replaceChildren(textNode('p', 'Student activity could not be loaded. Use Refresh to retry.', 'student-activity-copy mb-0'));
        summaryKey = '';
        $('studentActivitySummary').replaceChildren(textNode('span', 'Student activity unavailable'));
      }
      if (students.length) refreshPresence();
    } finally {
      clearTimeout(requestTimeout);
      loading = false; $('studentActivityRefresh').disabled = !permitted;
      scheduleRefresh();
    }
  }
  function scheduleRefresh() {
    clearTimeout(timer);
    if (permitted) timer = setTimeout(() => { if (document.hidden) scheduleRefresh(); else load(); }, 30000);
  }
  function refreshPresence() {
    const byId = studentsById();
    map.querySelectorAll('.student-activity-square').forEach(square => {
      const student = byId.get(Number(square.dataset.studentId));
      if (!student) return;
      square.className = 'student-activity-square ' + color(student);
      square.setAttribute('aria-label', student.student_name + ', ' + student.student_number + ', ' + number(student.successful_logins) + ' successful logins, ' + status(student));
    });
    summary(); if (activeSquare) showTooltip(activeSquare);
  }
  function studentsById() { return new Map(students.map(row => [row.student_id, row])); }
  search.addEventListener('input', render);
  $('studentActivityRefresh').addEventListener('click', load);
  map.addEventListener('pointerover', event => { const square = event.target.closest('.student-activity-square'); if (square) showTooltip(square); });
  map.addEventListener('pointerleave', () => { if (!map.contains(document.activeElement)) hideTooltip(); });
  map.addEventListener('focusin', event => {
    if (!event.target.matches('.student-activity-square')) return;
    map.querySelectorAll('[tabindex="0"]').forEach(square => { square.tabIndex = -1; });
    event.target.tabIndex = 0;
    showTooltip(event.target);
  });
  map.addEventListener('focusout', hideTooltip);
  map.addEventListener('click', event => { const square = event.target.closest('.student-activity-square'); if (square) showTooltip(square); });
  map.addEventListener('scroll', () => {
    if (activeSquare && document.activeElement === activeSquare) {
      const square = activeSquare.getBoundingClientRect(), bounds = map.getBoundingClientRect();
      if (square.bottom >= bounds.top && square.top <= bounds.bottom) return showTooltip(activeSquare);
    }
    hideTooltip();
  });
  window.addEventListener('scroll', hideTooltip, { passive:true });
  window.addEventListener('resize', hideTooltip);
  map.addEventListener('keydown', event => {
    if (event.key === 'Escape') return hideTooltip();
    const squares = [...map.querySelectorAll('.student-activity-square')], index = squares.indexOf(event.target);
    if (index < 0) return;
    const columns = Math.max(1, getComputedStyle(map).gridTemplateColumns.split(' ').length);
    const next = { ArrowRight:index + 1, ArrowLeft:index - 1, ArrowDown:index + columns, ArrowUp:index - columns, Home:0, End:squares.length - 1 }[event.key];
    if (next === undefined) return;
    event.preventDefault(); const target = squares[Math.max(0, Math.min(squares.length - 1, next))];
    event.target.tabIndex = -1; target.tabIndex = 0; target.focus();
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { refreshPresence(); load(); } });
  setInterval(() => { if (!document.hidden && students.length) refreshPresence(); }, 5000);
  load();
})();
