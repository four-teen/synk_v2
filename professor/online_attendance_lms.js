(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const root = $('onlineAttendanceWorkspace');
  const configNode = $('onlineAttendanceConfig');
  if (!root || !configNode) return;
  const config = JSON.parse(configNode.textContent);
  const {escape, labels, periods, dateTime, windowText, dateField, request} = window.synkOnlineAttendanceLms;
  const scheduleModal = bootstrap.Modal.getOrCreateInstance($('oaSessionModal'));
  const reviewModal = bootstrap.Modal.getOrCreateInstance($('oaReviewModal'));
  const state = {period:config.period, data:null, busy:false, edit:null, detail:null, reviewAction:null, key:''};
  const rowsHaveScores = () => (state.data?.totals.scored_count ?? 0) > 0;

  function errorBox(id, message = '') {
    $(id).textContent = message;
    $(id).hidden = !message;
  }
  function badge(value) {
    return `<span class="oa-pill" data-state="${value}">${labels[value]}</span>`;
  }
  function api(action, payload = {}) {
    return request(config,action,{context:config.context,payload:{grading_period:state.period,...payload}});
  }
  function updateControls() {
    $('oaNewSession').disabled = state.busy || !state.data;
    $('oaApply').disabled = state.busy || !state.data?.gradebook.can_apply || (rowsHaveScores() && !$('oaComponent').value);
    for (const id of ['oaRefresh','oaSaveSession','oaSaveReview']) $(id).disabled = state.busy;
    root.querySelectorAll('[data-oa-period]').forEach(button => { button.disabled = state.busy; });
  }
  async function task(callback, errorId = 'oaError') {
    if (state.busy) return;
    state.busy = true;
    updateControls();
    errorBox(errorId);
    try {
      await callback();
    } catch (error) {
      errorBox(errorId,error.message);
    } finally {
      state.busy = false;
      updateControls();
    }
  }
  function render(data) {
    state.period = data.grading_period;
    state.data = data;
    root.querySelectorAll('[data-oa-period]').forEach(button => {
      const selected = button.dataset.oaPeriod === state.period;
      button.setAttribute('aria-pressed',String(selected));
      button.classList.toggle('btn-primary',selected);
      button.classList.toggle('btn-outline-primary',!selected);
    });
    $('oaContent').hidden = false;
    $('oaSessions').innerHTML = data.sessions.length ? `<ul class="oa-session-list">${data.sessions.map(session => `
      <li class="oa-session"><div><strong>${escape(session.title)}</strong><span class="oa-session-time">${windowText(session.opens_at,session.closes_at)}</span></div>
        ${badge(session.state)}<div class="oa-actions">
          ${session.state === 'scheduled' ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-oa-edit="${session.session_id}">Edit window</button>` : ''}
          <button type="button" class="btn btn-sm btn-outline-primary" data-oa-review="${session.session_id}" aria-label="Review ${escape(session.title)}">Review</button>
        </div></li>`).join('')}</ul>` : '<p class="oa-empty">No attendance sessions scheduled for this period.</p>';
    const {closed_count:closedCount, rows} = data.totals;
    $('oaClosedCount').textContent = `${closedCount} closed session${closedCount === 1 ? '' : 's'}`;
    $('oaTotals').innerHTML = closedCount && rows.length ? `
      <div class="oa-table-scroll" tabindex="0" role="region" aria-label="Attendance totals by student">
        <table class="table oa-table"><thead><tr><th scope="col">Student</th><th scope="col" class="text-end">Present</th>
          <th scope="col" class="text-end">Absent</th><th scope="col" class="text-end">Attendance %</th></tr></thead><tbody>
          ${rows.map(row => `<tr><th scope="row">${escape(row.full_name)}<small>${escape(row.student_number)}</small></th>
            <td class="text-end">${row.present}</td><td class="text-end">${row.absent}</td>
            <td class="text-end">${row.score === null ? '—' : Number(row.score).toFixed(2) + '%'}</td></tr>`).join('')}
        </tbody></table></div>` : '<p class="oa-empty">Attendance totals appear after a session closes.</p>';
    const {gradebook} = data;
    const selected = $('oaComponent').value;
    const component = gradebook.components.find(component => component.component_id === gradebook.component_id);
    $('oaComponent').innerHTML = '<option value="">Choose grading component</option>' + gradebook.components.map(component =>
      `<option value="${component.component_id}">${escape(component.name)} · ${component.weight}%</option>`).join('');
    $('oaComponent').value = component?.component_id || (gradebook.components.some(component => String(component.component_id) === selected) ? selected : '');
    $('oaComponentField').hidden = !rowsHaveScores();
    $('oaComponent').required = rowsHaveScores();
    $('oaApply').textContent = rowsHaveScores() ? 'Apply attendance scores' : 'Update Grade Book';
    const stateMessages = {
      unassigned:'Attendance scores haven’t been applied.',
      changes:'Attendance changed. Apply the current totals to update Grade Book.',
      applied:component ? `Applied to ${component.name} on ${dateTime(gradebook.applied_at)}.` : 'Attendance scores are in Grade Book.',
      archived:'The online attendance entry is archived in Grade Book.',
      cleared:'No current attendance score is included in Grade Book.'
    };
    $('oaGradeState').textContent = stateMessages[gradebook.state];
    $('oaGradeHelp').textContent = !gradebook.components.length ? 'Save the grading system for this period before applying scores.'
      : gradebook.scheme_changed ? 'Grade Book is using its saved grading revision. Review Grading system changes in Grade Book.'
      : !rowsHaveScores() && gradebook.can_apply ? 'Updating will remove the earlier attendance total from this period’s calculation.'
      : '';
    updateControls();
  }

  async function load(period) {
    if (state.busy) return;
    const previous = state.period;
    const changed = period !== previous;
    if (changed) $('oaComponent').value = '';
    state.period = period;
    $('oaNotice').textContent = 'Loading attendance…';
    await task(async () => {
      render(await api('get'));
      $('oaNotice').textContent = '';
    });
    if ($('oaError').textContent) {
      state.period = previous;
      $('oaNotice').textContent = '';
    }
  }
  function schedule(session = null) {
    state.edit = session;
    state.key = Array.from(crypto.getRandomValues(new Uint8Array(32)), byte => byte.toString(16).padStart(2,'0')).join('');
    $('oaSessionTitle').textContent = session ? 'Edit attendance window' : 'Schedule attendance';
    $('oaSchedulePeriod').textContent = `${periods[state.period]} · Philippine time (UTC+8)`;
    $('oaSaveSession').textContent = session ? 'Save changes' : 'Schedule session';
    $('oaSessionName').value = session?.title ?? '';
    $('oaOpens').value = session ? dateField(session.opens_at) : '';
    $('oaCloses').value = session ? dateField(session.closes_at) : '';
    errorBox('oaScheduleError');
    scheduleModal.show();
  }
  function reviewForm(action, studentId = null) {
    state.reviewAction = {action,studentId};
    const cancelled = action === 'cancel';
    const student = state.detail.roster.find(student => student.student_id === studentId);
    $('oaReviewFormTitle').textContent = cancelled ? 'Cancel this session' : `Correct attendance for ${student.student_name}`;
    $('oaResultField').hidden = cancelled;
    $('oaResult').value = student?.override_status || (student?.result === 'present' ? 'present' : 'absent');
    $('oaReason').value = '';
    $('oaSaveReview').textContent = cancelled ? 'Cancel session' : 'Save correction';
    $('oaSaveReview').classList.toggle('btn-danger',cancelled);
    $('oaSaveReview').classList.toggle('btn-primary',!cancelled);
    $('oaReviewForm').hidden = false;
    $('oaReason').focus();
  }
  function renderReview(data) {
    state.detail = data;
    const {session, roster} = data;
    const canCorrect = session.state === 'closed';
    $('oaReviewTitle').textContent = session.title;
    $('oaReviewTime').textContent = windowText(session.opens_at,session.closes_at);
    $('oaReviewNotice').textContent = `${labels[session.state]} · ${roster.length} students`;
    $('oaCancelSession').hidden = session.state === 'cancelled';
    $('oaReviewForm').hidden = true;
    $('oaReviewRoster').innerHTML = roster.length ? `
      <div class="oa-table-scroll" tabindex="0" role="region" aria-label="Session attendance roster">
        <table class="table oa-table"><thead><tr><th scope="col">Student</th><th scope="col">Result</th>
          <th scope="col">Check-in</th>${canCorrect ? '<th scope="col"><span class="visually-hidden">Actions</span></th>' : ''}</tr></thead>
          <tbody>${roster.map(student => `<tr><th scope="row">${escape(student.student_name)}<small>${escape(student.student_number)}</small></th>
            <td>${badge(student.result)}${student.override_reason ? `<small>${escape(student.override_reason)}</small>` : ''}</td>
            <td>${student.checked_in_at ? dateTime(student.checked_in_at) : '—'}</td>${canCorrect ? `<td><button type="button" class="btn btn-sm btn-outline-secondary"
              data-oa-correct="${student.student_id}" aria-label="Correct attendance for ${escape(student.student_name)}">Correct</button></td>` : ''}</tr>`).join('')}
          </tbody></table></div>` : '<p>No students were recorded in this session.</p>';
  }
  function review(sessionId) {
    state.detail = null;
    $('oaReviewTitle').textContent = 'Session attendance';
    $('oaReviewTime').textContent = '';
    $('oaReviewNotice').textContent = 'Loading roster…';
    $('oaReviewRoster').replaceChildren();
    $('oaReviewForm').hidden = true;
    $('oaCancelSession').hidden = true;
    reviewModal.show();
    task(async () => { renderReview(await api('detail',{session_id:sessionId})); },'oaReviewError');
  }

  root.addEventListener('click', event => {
    if (state.busy) return;
    const button = event.target.closest('button');
    if (!button) return;
    if (button.dataset.oaPeriod) load(button.dataset.oaPeriod);
    if (button.dataset.oaEdit) schedule(state.data.sessions.find(session => session.session_id === Number(button.dataset.oaEdit)));
    if (button.dataset.oaReview) review(Number(button.dataset.oaReview));
    if (button.dataset.oaCorrect) reviewForm('correct',Number(button.dataset.oaCorrect));
  });
  $('oaRefresh').addEventListener('click',() => load(state.period));
  $('oaNewSession').addEventListener('click',() => schedule());
  $('oaSessionModal').addEventListener('shown.bs.modal',() => $('oaSessionName').focus());
  $('oaComponent').addEventListener('change',updateControls);
  $('oaCancelSession').addEventListener('click',() => { if (!state.busy && state.detail) reviewForm('cancel'); });
  $('oaCloseReviewForm').addEventListener('click',() => { $('oaReviewForm').hidden = true; });
  $('oaSessionForm').addEventListener('submit',event => {
    event.preventDefault();
    task(async () => {
      const payload = {title:$('oaSessionName').value, opens_at:$('oaOpens').value, closes_at:$('oaCloses').value};
      const action = state.edit ? 'edit' : 'schedule';
      const identifiers = state.edit ? {session_id:state.edit.session_id,version:state.edit.version} : {request_key:state.key};
      render(await api(action,{...payload,...identifiers}));
      scheduleModal.hide();
      $('oaNotice').textContent = state.edit ? 'Attendance window updated.' : 'Attendance session scheduled.';
    },'oaScheduleError');
  });
  $('oaReviewForm').addEventListener('submit',event => {
    event.preventDefault();
    if (!state.detail || !state.reviewAction) return;
    task(async () => {
      const {session_id,version} = state.detail.session;
      const {action,studentId} = state.reviewAction;
      const change = action === 'correct' ? {student_id:studentId,status:$('oaResult').value} : {};
      render(await api(action,{session_id,version,reason:$('oaReason').value,...change}));
      $('oaReviewForm').hidden = true;
      $('oaNotice').textContent = action === 'correct' ? 'Attendance correction saved.' : 'Attendance session cancelled.';
      renderReview(await api('detail',{session_id}));
    },'oaReviewError');
  });
  $('oaGradeForm').addEventListener('submit',event => {
    event.preventDefault();
    if (!state.data?.gradebook.can_apply) return;
    task(async () => {
      const {version,scheme_version} = state.data.gradebook;
      const component_id = Number($('oaComponent').value);
      render(await api('apply',{component_id,version,scheme_version,attendance_digest:state.data.totals.digest}));
      $('oaNotice').textContent = rowsHaveScores() ? 'Attendance scores applied to Grade Book.' : 'Grade Book attendance total updated.';
    });
  });
  load(state.period);
})();
