(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const root = $('studentOnlineAttendance');
  if (!root) return;
  const config = JSON.parse($('studentOnlineAttendanceConfig').textContent);
  const {escape, labels, periods, dateTime, windowText, request} = window.synkOnlineAttendanceLms;
  const picker = $('studentOaSubject');
  const selectedFromLink = location.hash.slice(1);
  let subjects = [];
  let busy = false;
  let timer;
  let loaded = false;

  function activeSession(session) {
    return session.state === 'open' && !session.checked_in_at && session.result === 'pending';
  }
  function render() {
    const filtered = subjects.filter(subject => !picker.value || subject.subject_key === picker.value);
    $('studentOaList').innerHTML = filtered.length ? filtered.map(subject => `
      <section class="card student-oa-subject">
        <header><h2>${escape(subject.subject_code)} ${escape(subject.title)}</h2><p>${escape(subject.section)} · ${escape(subject.instructor)}</p></header>
        ${subject.sessions.length ? `<ul>${subject.sessions.map(session => {
          const result = session.state === 'scheduled' ? 'scheduled' : session.result;
          return `<li class="student-oa-session">
            <div><h3>${escape(session.title)}</h3><p>${periods[session.grading_period]} · ${windowText(session.opens_at,session.closes_at)}</p>
              ${session.checked_in_at ? `<p>Checked in ${dateTime(session.checked_in_at)}</p>` : ''}</div>
            <div class="student-oa-status"><span class="student-oa-pill" data-state="${result}">${labels[result]}</span>
              ${activeSession(session) ? `<button class="btn btn-primary" type="button" data-student-oa-checkin="${session.session_id}">Check in</button>` : ''}</div>
          </li>`;
        }).join('')}</ul>` : '<p class="student-oa-empty">No attendance sessions for this class.</p>'}
      </section>`).join('') : '<div class="card p-4">No online attendance sessions in your current subjects.</div>';
    updateControls();
  }
  function updateControls() {
    $('studentOaRefresh').disabled = busy;
    root.querySelectorAll('[data-student-oa-checkin]').forEach(button => { button.disabled = busy; });
  }
  function setBoundaryTimer(serverTime) {
    clearTimeout(timer);
    const now = Date.parse(serverTime);
    let next = Infinity;
    for (const subject of subjects) {
      for (const session of subject.sessions) {
        if (session.state !== 'scheduled' && session.state !== 'open') continue;
        const boundary = Date.parse(session.state === 'scheduled' ? session.opens_at : session.closes_at);
        if (boundary > now) next = Math.min(next,boundary);
      }
    }
    if (Number.isFinite(next)) timer = setTimeout(load,Math.min(2147483647,next-now+250));
  }
  async function task(action) {
    if (busy) return;
    busy = true;
    updateControls();
    $('studentOaError').hidden = true;
    try { await action(); }
    catch (error) {
      $('studentOaNotice').textContent = '';
      $('studentOaError').textContent = error.message;
      $('studentOaError').hidden = false;
    } finally {
      busy = false;
      updateControls();
    }
  }
  function load() {
    if (busy) {
      clearTimeout(timer);
      timer = setTimeout(load,1000);
      return;
    }
    task(async () => {
      $('studentOaNotice').textContent = 'Loading attendance…';
      const data = await request(config,'list');
      subjects = data.subjects;
      const old = loaded ? picker.value : selectedFromLink;
      const choices = new Map(subjects.map(subject => [subject.subject_key, `${subject.subject_code} · ${subject.instructor}`]));
      picker.replaceChildren(new Option('All subjects',''),...Array.from(choices,([key,label]) => new Option(label,key)));
      if (old) {
        const match = Array.from(choices.keys()).find(key => key === old || encodeURIComponent(key) === old);
        if (match) picker.value = match;
      }
      loaded = true;
      $('studentOaFilter').hidden = choices.size < 2;
      $('studentOaNotice').textContent = '';
      render();
      setBoundaryTimer(data.server_time);
    });
  }
  picker.addEventListener('change',render);
  $('studentOaRefresh').addEventListener('click',load);
  root.addEventListener('click',event => {
    const button = event.target.closest('[data-student-oa-checkin]');
    if (!button) return;
    task(async () => {
      const sessionId = Number(button.dataset.studentOaCheckin);
      const data = await request(config,'check_in',{session_id:sessionId});
      for (const subject of subjects) {
        const session = subject.sessions.find(session => session.session_id === sessionId);
        if (session) Object.assign(session,{state:data.state,result:data.result,checked_in_at:data.checked_in_at});
      }
      render();
      $('studentOaNotice').textContent = data.checked_in_at ? `Check-in recorded at ${dateTime(data.checked_in_at)}.` : '';
      setBoundaryTimer(data.server_time);
    });
  });
  load();
})();
