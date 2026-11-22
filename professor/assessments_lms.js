(function () {
  'use strict';
  const root = document.getElementById('assessmentWorkspace');
  const configNode = document.getElementById('assessmentConfig');
  if (!root || !configNode) return;
  const config = JSON.parse(configNode.textContent);
  const feedback = window.synkLmsFeedback;
  const $ = id => document.getElementById(id);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[character]));
  const number = value => Number(value || 0).toLocaleString('en-PH', { maximumFractionDigits:2 });
  const labels = { quiz:'Quiz', exam:'Exam', assignment:'Assignment', multiple_choice:'Multiple choice', true_false:'True / False', short_answer:'Short answer', essay:'Essay' };
  const periods = { midterm:'Midterm', final:'Final' };
  const icons = { quiz:'bx-list-check', exam:'bx-edit-alt', assignment:'bx-task' };
  const questionLimit = 100;
  const requestBody = (action, payload) => JSON.stringify({ action, payload, context:config.context, csrf_token:config.csrf_token });
  const state = { rows:[], period:'midterm', draft:null, questions:[], dirty:false, busy:false, writeMessage:'', preview:null, duplicate:null, schedule:null, followup:null, sequence:0 };
  const previewModal = new bootstrap.Modal($('assessmentPreviewModal'));
  const duplicateModal = new bootstrap.Modal($('assessmentDuplicateModal'));
  const submissionsModal = new bootstrap.Modal($('assessmentSubmissionsModal'));
  const scheduleModal = new bootstrap.Modal($('assessmentScheduleModal'));
  const followupModal = new bootstrap.Modal($('assessmentFollowupModal'));
  const detailFields = { title:$('assessmentTitle'), instructions:$('assessmentInstructions') };
  const bulkQuestions = window.synkSetupBulkQuestions({ room:() => questionLimit - state.questions.length, detailFields, addQuestions:addBulkQuestions });
  const compactCards = window.matchMedia('(max-width:767.98px)');
  let cardMenu = null, cardActionOrigin = null;
  const stamp = value => value ? String(value).replace(' ', 'T').slice(0,16) : '';
  const formatQuestion = window.synkLmsContent.formatQuestion;
  function updateQuestionPreview(node) {
    const prompt = node.querySelector('[data-field="prompt"]').value;
    const preview = node.querySelector('.assessment-prompt-preview');
    preview.hidden = !prompt.trim();
    preview.querySelector('.assessment-formatted-prompt').innerHTML = formatQuestion(prompt);
  }
  function positionFloatingActions() {
    const bounds = root.getBoundingClientRect();
    const left = Math.max(12, bounds.left), right = Math.min(document.documentElement.clientWidth - 12, bounds.right);
    $('assessmentFloatingActions').style.left = left + 'px';
    $('assessmentFloatingActions').style.width = Math.max(0, right - left) + 'px';
    const navigationHeight = $('professor-bottom-nav')?.getBoundingClientRect().height || 0;
    $('assessmentFloatingActions').style.bottom = navigationHeight ? (navigationHeight + 12) + 'px' : '';
  }
  function updateFloatingActions() {
    const visible = !!state.draft && !$('assessmentEditor').hidden && (!state.draft.assessment_id || state.dirty || !!state.writeMessage);
    const actions = $('assessmentFloatingActions');
    actions.hidden = !visible;
    actions.classList.toggle('is-writing', !!state.writeMessage);
    $('assessmentEditor').classList.toggle('has-floating-actions', visible);
    $('assessmentFloatingState').textContent = state.writeMessage || (state.dirty ? 'Unsaved changes' : 'Not saved yet');
    if (visible) positionFloatingActions();
  }
  function dateLabel(value) {
    if (!value) return 'No due date';
    const date = new Date(String(value).replace(' ', 'T') + '+08:00');
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-PH', { timeZone:'Asia/Manila', month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' });
  }
  function notice(message = '', error = false) {
    if (message) { if (error) feedback.error(message,'Assessment workspace'); else feedback.toast(message); }
  }
  function dirty() {
    if (!state.draft || state.busy) return;
    state.dirty = true;
    $('assessmentSaveState').textContent = 'Unsaved changes';
    updateFloatingActions();
  }
  async function canLeave() { return !state.dirty || await feedback.confirm({title:'Discard unsaved changes?',text:'Your changes to this assessment will be discarded.',confirmText:'Discard changes',cancelText:'Keep editing'}); }
  function busy(value) {
    state.busy = value;
    root.setAttribute('aria-busy', String(value));
    if (value) {
      root.querySelectorAll('button,input,select,textarea').forEach(element => {
        element.dataset.assessmentWasDisabled = String(element.disabled);
        element.disabled = true;
      });
    } else {
      root.querySelectorAll('[data-assessment-was-disabled]').forEach(element => {
        element.disabled = element.dataset.assessmentWasDisabled === 'true';
        delete element.dataset.assessmentWasDisabled;
      });
      if (state.draft && !$('assessmentEditor').hidden) toggleType();
      updateQuestionActions();
      $('assessmentFollowupCopy').disabled=!state.followup?.message;
    }
    updateFloatingActions();
  }
  async function api(action, payload = {}) {
    let response;
    try {
      response = await fetch(config.endpoint, { method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
        body:requestBody(action, payload) });
    } catch { throw Error('The connection was interrupted. Your changes are still here; try again.'); }
    let data;
    try { data = await response.json(); } catch { throw Error('The workspace did not return a valid response. Your changes have been kept.'); }
    if (!response.ok || !data.ok) throw Error(data.message || 'This assessment request could not be completed.');
    return data;
  }
  async function writeAssessment(action, payload) {
    const status = $('assessmentSaveState');
    const previous = status.textContent;
    const show = message => {
      state.writeMessage = message;
      status.textContent = message || previous;
      $('assessmentWriteAnnouncement').textContent = message;
      status.classList.toggle('is-writing', !!message);
      updateFloatingActions();
    };
    show(action === 'publish' ? 'Publishing assessment…' : 'Saving assessment…');
    const timer = setTimeout(() => show('Still waiting for the server…'), 1800);
    try { return await api(action, payload); }
    finally { clearTimeout(timer); show(''); }
  }
  async function task(callback) {
    if (state.busy) return;
    busy(true);
    try { await callback(); } catch (error) { notice(error.message, true); }
    finally { busy(false); }
  }
  function participation(row) {
    const answered = Number.isInteger(row.answered_count) && row.answered_count >= 0 ? row.answered_count : null;
    const total = Number.isInteger(row.student_count) && row.student_count >= 0 ? row.student_count : null;
    return `<div class="assessment-participation" title="Submitted responses from enrolled students">
      <i class="bx bx-group" aria-hidden="true"></i><div><div class="assessment-participation-count">
        <span class="${answered === null ? 'assessment-count-unavailable' : 'assessment-count-answered'}">${answered === null ? '&mdash;' : number(answered)}<span class="visually-hidden">${answered === null ? ' answered count unavailable' : ' students answered'}</span></span>
        <span class="assessment-count-divider" aria-hidden="true">/</span>
        <span class="${total === null ? 'assessment-count-unavailable' : 'assessment-count-total'}">${total === null ? '&mdash;' : number(total)}<span class="visually-hidden">${total === null ? ' student count unavailable' : ' total students'}</span></span>
      </div><p aria-hidden="true">answered / students</p></div></div>`;
  }
  function closeCardMenu(returnFocus = false) {
    if (!cardMenu) return;
    const trigger = cardMenu.previousElementSibling;
    cardMenu.hidden = true; trigger.setAttribute('aria-expanded', 'false'); cardMenu = null;
    if (returnFocus) trigger.focus({ preventScroll:true });
  }
  function focusCardAction(origin) {
    if (!origin || $('assessmentLibrary').hidden) return;
    const list = $('assessmentList');
    const button = list.querySelector(`[data-action="${origin.action}"][data-id="${origin.id}"]`);
    const target = button && !button.closest('[hidden]') ? button : list.querySelector(`[data-assessment-more][data-id="${origin.id}"]`) || list.querySelector(`[data-action="edit"][data-id="${origin.id}"]`);
    target?.focus({ preventScroll:true });
  }
  function cardActions(row) {
    const transferLabel = 'Move to ' + periods[row.grading_period === 'midterm' ? 'final' : 'midterm'];
    if (!compactCards.matches) return `<div class="assessment-list-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-action="edit" data-id="${row.assessment_id}">${row.status === 'draft' ? 'Edit draft' : 'Edit assessment'}</button><button type="button" class="btn btn-sm btn-outline-primary" data-action="schedule" data-id="${row.assessment_id}">Set schedule</button><button type="button" class="btn btn-sm btn-outline-secondary" data-action="preview" data-id="${row.assessment_id}">Preview</button><button type="button" class="btn btn-sm btn-outline-secondary" data-action="transfer" data-id="${row.assessment_id}">${transferLabel}</button><button type="button" class="btn btn-sm btn-outline-secondary" data-action="duplicate" data-id="${row.assessment_id}">Duplicate</button>${row.status === 'draft' ? `<button type="button" class="btn btn-sm btn-outline-secondary" data-action="archive" data-id="${row.assessment_id}">Archive</button>` : `<button type="button" class="btn btn-sm btn-outline-primary" data-action="submissions" data-id="${row.assessment_id}">Student responses</button>`}${row.status === 'published' ? `<button type="button" class="btn btn-sm btn-outline-danger" data-action="close" data-id="${row.assessment_id}">Close assessment</button>` : ''}</div>`;
    const actionButton = (action, label, icon, caption, menu = false) => `<button type="button" class="${menu ? 'assessment-card-menu-item' : 'assessment-card-action'}${['close','archive'].includes(action) ? ' is-danger' : ''}" data-action="${action}" data-id="${row.assessment_id}" aria-label="${label}"${menu ? ' role="menuitem" tabindex="-1"' : ''}><i class="bx ${icon}" aria-hidden="true"></i><span>${caption || label}</span></button>`;
    const draft = row.status === 'draft', menuId = `assessmentCardMenu${row.assessment_id}`;
    return `<div class="assessment-mobile-actions" role="group" aria-label="Assessment actions">
      ${actionButton('edit', draft ? 'Edit draft' : 'Edit assessment', 'bx-edit-alt', 'Edit')}
      ${actionButton('schedule', 'Set assessment schedule', 'bx-calendar', 'Schedule')}
      ${draft ? actionButton('preview', 'Preview assessment', 'bx-show', 'Preview') : actionButton('submissions', 'Student responses', 'bx-group', 'Responses')}
      <div class="assessment-card-more"><button type="button" class="assessment-card-action" data-assessment-more data-id="${row.assessment_id}" id="${menuId}Toggle" aria-label="More assessment actions" aria-haspopup="menu" aria-expanded="false" aria-controls="${menuId}"><i class="bx bx-dots-horizontal-rounded" aria-hidden="true"></i><span>More</span></button>
        <div class="assessment-card-menu" id="${menuId}" role="menu" aria-labelledby="${menuId}Toggle" hidden>
          ${!draft ? actionButton('preview', 'Preview assessment', 'bx-show', null, true) : ''}
          ${actionButton('transfer', transferLabel, 'bx-transfer', null, true)}
          ${actionButton('duplicate', 'Duplicate assessment', 'bx-copy', null, true)}
          ${draft ? actionButton('archive', 'Archive draft', 'bx-archive', null, true) : row.status === 'published' ? actionButton('close', 'Close assessment', 'bx-x-circle', null, true) : ''}
        </div>
      </div>
    </div>`;
  }
  function renderFollowupStudents() {
    if(!state.followup)return;
    const search=$('assessmentFollowupSearch').value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const rows=state.followup.students.filter(row=>(row.student_name+' '+row.student_number+' '+row.assessments.map(a=>a.title).join(' ')).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(search));
    $('assessmentFollowupStudents').innerHTML=rows.map(row=>`<li class="assessment-followup-student is-grouped"><div class="assessment-followup-student-name"><strong>${escape(row.student_name)}</strong><span>${escape(row.student_number||'Student number unavailable')} · ${number(row.assessments.length)} outstanding</span></div><ul class="assessment-followup-items">${row.assessments.map(item=>`<li><div><strong>${escape(item.title)}</strong><small>${escape(periods[item.grading_period])} · ${escape(labels[item.assessment_type])}</small></div><span class="assessment-followup-status is-${escape(item.status)}">${escape(item.status_label)}</span><p>${item.deadline_at?'Due '+escape(item.deadline_label):'No due date'}${item.status==='upcoming'?' · Opens '+escape(item.opens_label):''}</p></li>`).join('')}</ul></li>`).join('');
    $('assessmentFollowupResult').textContent=`Showing ${number(rows.length)} of ${number(state.followup.students.length)} unsubmitted students`;
    $('assessmentFollowupEmpty').hidden=rows.length>0;
    $('assessmentFollowupEmpty').querySelector('p').textContent=search?'No students match your search.':!state.followup.counts.assessments?'No published assessments in this class yet.':!state.followup.counts.eligible?'No enrolled students need follow-up. Dropped students and excluded accounts are omitted.':'Everyone currently enrolled has submitted all listed assessments.';
  }
  async function loadFollowup() {
    const {report}=await api('class_followup');state.followup=report;
    $('assessmentFollowupName').textContent='Outstanding assessments by student';
    $('assessmentFollowupContext').textContent=[report.class.subject_code+': '+report.class.title,report.class.section,'Midterm & Final','All published and closed assessments'].filter(Boolean).join(' · ');
    $('assessmentFollowupMissing').textContent=number(report.counts.missing_students);$('assessmentFollowupSubmitted').textContent=number(report.counts.missing_assessments);$('assessmentFollowupTotal').textContent=number(report.counts.students);
    $('assessmentFollowupNotice').textContent=report.notice+` ${number(report.counts.dropped)} dropped · ${number(report.counts.excluded)} excluded.`;
    $('assessmentFollowupMessage').value=report.message;$('assessmentFollowupPreview').hidden=!report.students.length;
    $('assessmentFollowupAsOf').textContent='As of '+report.generated_label;
    $('assessmentFollowupCopyStatus').textContent='Copy includes the full list, even while searching.';
    $('assessmentFollowupCopy').querySelector('span').textContent='Copy full report ('+number(report.counts.missing_students)+')';
    renderFollowupStudents();
  }
  async function copyFollowup() {
    if(state.busy||!state.followup?.message)return;
    const text=$('assessmentFollowupMessage'),button=$('assessmentFollowupCopy');button.disabled=true;
    let copied=false;
    try {await navigator.clipboard.writeText(state.followup.message);copied=true;} catch (_) {
      $('assessmentFollowupPreview').open=true;text.focus();text.select();
      try {copied=document.execCommand('copy');} catch (_) {}
    }
    $('assessmentFollowupCopyStatus').textContent=copied?'Full reminder copied. You can paste it into your group chat.':'Automatic copying is unavailable. The full message is selected; use your device’s Copy command.';
    button.disabled=false;if(copied)button.focus();
  }
  function renderList() {
    closeCardMenu();
    for (const period of Object.keys(periods)) {
      const count = state.rows.filter(row => row.grading_period === period).length;
      $(period === 'midterm' ? 'assessmentMidtermCount' : 'assessmentFinalCount').textContent = number(count);
    }
    root.querySelectorAll('[data-period]').forEach(button => {
      const active = button.dataset.period === state.period;
      button.classList.toggle('is-active', active); button.setAttribute('aria-pressed', String(active));
    });
    const search = $('assessmentSearch').value.trim().toLocaleLowerCase();
    const type = $('assessmentTypeFilter').value;
    const rows = state.rows.filter(row => row.grading_period === state.period && (type === 'all' || row.assessment_type === type)
      && (row.title + ' ' + row.topic).toLocaleLowerCase().includes(search));
    $('assessmentList').setAttribute('aria-busy', 'false');
    if (!rows.length) {
      const filtered = search || type !== 'all';
      $('assessmentList').innerHTML = `<div class="card assessment-empty"><i class="bx bx-notepad" aria-hidden="true"></i><h3>${filtered ? 'No matching assessments' : `No ${periods[state.period]} assessments yet`}</h3><p class="mb-0">${filtered ? 'Try another title, topic, or type.' : 'Create a quiz, exam, or assignment to start preparing your classwork.'}</p></div>`;
      return;
    }
    $('assessmentList').innerHTML = rows.map(row => `<article class="card assessment-list-card">
      <span class="assessment-list-icon"><i class="bx ${icons[row.assessment_type] || 'bx-task'}" aria-hidden="true"></i></span>
      <div class="assessment-list-main"><div class="assessment-list-summary"><div class="assessment-list-details"><div class="assessment-list-labels"><span class="badge bg-label-primary">${escape(labels[row.assessment_type])}</span><span class="badge bg-label-secondary">${escape(row.status === 'draft' ? 'Draft' : row.status)}</span>${row.topic ? `<span class="text-muted">${escape(row.topic)}</span>` : ''}</div>
      <h3>${escape(row.title)}</h3><div class="assessment-list-meta"><span>${number(row.total_points)} points</span>${row.assessment_type !== 'assignment' ? `<span>${number(row.question_count)} questions</span>` : ''}<span>${row.opens_at ? 'Starts ' + escape(dateLabel(row.opens_at)) : 'No start date'}</span><span>${row.due_at ? 'Ends ' + escape(dateLabel(row.due_at)) : 'No end date'}</span></div></div>${participation(row)}</div>
      ${cardActions(row)}</div></article>`).join('');
  }
  async function refresh() {
    const data = await api('list'); state.rows = data.assessments; renderList();
  }
  function question(kind = 'multiple_choice', source = {}) {
    return { uid:++state.sequence, question_type:kind, prompt:'', points:1,
      options:kind === 'multiple_choice' ? ['','','',''] : [], answer_key:kind === 'short_answer' ? [] : null, ...source };
  }
  function syncQuestions() {
    $('assessmentQuestions').querySelectorAll('[data-question]').forEach((node,index) => {
      const item = state.questions[index];
      item.prompt = node.querySelector('[data-field="prompt"]').value;
      item.points = node.querySelector('[data-field="points"]').value;
      if (item.question_type === 'multiple_choice') {
        item.options = [...node.querySelectorAll('[data-choice]')].map(input => input.value);
        const selected = node.querySelector('input[data-answer]:checked');
        item.answer_key = selected ? Number(selected.value) : null;
      } else if (item.question_type === 'true_false') {
        const value = node.querySelector('[data-field="true_false"]').value;
        item.answer_key = value === '' ? null : value === 'true';
      } else if (item.question_type === 'short_answer') {
        item.answer_key = node.querySelector('[data-field="accepted"]').value.split('\n').map(value => value.trim()).filter(Boolean);
      } else item.answer_key = null;
    });
  }
  function totals() {
    const total = state.questions.reduce((sum,item) => sum + Math.round((Number(item.points) || 0) * 100), 0) / 100;
    $('assessmentQuestionTotal').textContent = `${state.questions.length} questions / ${number(total)} points`;
  }
  function updateQuestionActions() {
    const assignment = $('assessmentType').value === 'assignment';
    root.querySelectorAll('[data-assessment-question-add], [data-assessment-bulk-open]').forEach(button => {
      button.hidden = assignment;
      button.disabled = state.busy || state.questions.length >= questionLimit;
      button.parentElement.classList.toggle('has-question-action', !assignment);
    });
    $('assessmentQuestionsEmpty').hidden = assignment || state.questions.length > 0;
  }
  function multipleChoiceFields(item, id) {
    const choices = item.options.map((option, choice) => {
      const letter = String.fromCharCode(65 + choice);
      return `<div class="assessment-choice">
        <label class="assessment-choice-picker">
          <input type="radio" class="form-check-input" data-answer name="${id}answer" value="${choice}" aria-label="Mark choice ${letter} as correct" ${item.answer_key === choice ? 'checked' : ''} required>
          <span class="visually-hidden">Correct answer</span>
          <span data-choice-letter="${letter}" aria-hidden="true"></span>
        </label>
        <div class="assessment-choice-fields">
          <label class="visually-hidden" for="${id}choice${choice}">Choice ${letter}</label>
          <input type="text" class="form-control" id="${id}choice${choice}" data-choice maxlength="1000" placeholder="Enter choice ${letter}" value="${escape(option)}" required>
          <span class="assessment-choice-correct" aria-hidden="true"><i class="bx bx-check" aria-hidden="true"></i>Correct answer</span>
        </div>
        ${item.options.length > 2 ? `<button type="button" data-operation="remove-choice" data-choice-index="${choice}" aria-label="Remove choice ${letter}"><i class="bx bx-x" aria-hidden="true"></i></button>` : ''}
      </div>`;
    }).join('');
    const add = item.options.length < 6 ? '<button type="button" class="assessment-text-button" data-operation="add-choice"><i class="bx bx-plus" aria-hidden="true"></i>Add choice</button>' : '';
    return `<fieldset class="assessment-choice-set"><legend>Answer choices</legend><p class="assessment-help mb-2">Select the circle beside the correct answer.</p>${choices}${add}</fieldset>`;
  }
  function renderQuestions(focus = null) {
    $('assessmentQuestions').innerHTML = state.questions.map((item,index) => {
      const id = 'aq' + item.uid;
      const multipleChoice = item.question_type === 'multiple_choice';
      let key = '';
      if (multipleChoice) {
        key = multipleChoiceFields(item, id);
      } else if (item.question_type === 'true_false') {
        key = `<label class="form-label" for="${id}answer">Correct answer</label><select class="form-select" data-field="true_false" id="${id}answer" required><option value="">Choose an answer</option><option value="true"${item.answer_key === true ? ' selected' : ''}>True</option><option value="false"${item.answer_key === false ? ' selected' : ''}>False</option></select>`;
      } else if (item.question_type === 'short_answer') {
        key = `<label class="form-label" for="${id}answer">Accepted answers</label><textarea class="form-control" data-field="accepted" id="${id}answer" rows="3" maxlength="5010" required placeholder="One accepted answer per line">${escape((item.answer_key || []).join('\n'))}</textarea><p class="assessment-help mb-0 mt-2">Add up to 10 alternative answers, each at most 500 characters.</p>`;
      } else key = '<p class="assessment-help mb-0">An essay will be reviewed and graded by the professor.</p>';
      const questionNumber = index + 1;
      return `<article class="card assessment-question" data-question="${index}">
        <header class="assessment-question-top">
          <h5>Question ${questionNumber}</h5>
          <div class="assessment-question-controls" role="group" aria-label="Question ${questionNumber} actions">
            <div class="assessment-question-move" role="group" aria-label="Reorder question ${questionNumber}">
              <button type="button" data-operation="up" aria-label="Move question ${questionNumber} up" ${index === 0 ? 'disabled' : ''}><i class="bx bx-up-arrow-alt" aria-hidden="true"></i>Up</button>
              <button type="button" data-operation="down" aria-label="Move question ${questionNumber} down" ${index === state.questions.length - 1 ? 'disabled' : ''}><i class="bx bx-down-arrow-alt" aria-hidden="true"></i>Down</button>
            </div>
            <button type="button" data-operation="copy" aria-label="Copy question ${questionNumber}" ${state.questions.length >= questionLimit ? 'disabled' : ''}><i class="bx bx-copy" aria-hidden="true"></i>Copy</button>
            <button type="button" data-operation="remove" aria-label="Delete question ${questionNumber}"><i class="bx bx-trash" aria-hidden="true"></i>Delete</button>
          </div>
        </header>
        <div class="assessment-question-body">
          <div class="assessment-question-settings">
            <div><label class="form-label" for="${id}type">Question type</label><select class="form-select" data-field="type" id="${id}type">${['multiple_choice','true_false','short_answer','essay'].map(kind => `<option value="${kind}"${kind === item.question_type ? ' selected' : ''}>${labels[kind]}</option>`).join('')}</select></div>
            <div><label class="form-label" for="${id}points">Points</label><input type="number" class="form-control" data-field="points" id="${id}points" value="${escape(item.points)}" min="0.01" max="10000" step="0.01" placeholder="e.g. 1" required></div>
          </div>
          <label class="form-label" for="${id}prompt">Question</label>
          <div class="assessment-code-toolbar" role="group" aria-label="Code formatting for question ${questionNumber}">
            <button type="button" data-format="inline" aria-controls="${id}prompt"><i class="bx bx-code" aria-hidden="true"></i>Inline code</button>
            <button type="button" data-format="block" aria-controls="${id}prompt"><i class="bx bx-code-block" aria-hidden="true"></i>Code block</button>
          </div>
          <div class="assessment-prompt-field"><textarea class="form-control assessment-code-input" data-field="prompt" id="${id}prompt" rows="5" maxlength="10000" placeholder="Write your question or paste code" aria-describedby="${id}help" spellcheck="false" autocapitalize="off" autocorrect="off" required>${escape(item.prompt)}</textarea></div>
          <p class="assessment-help mt-2 mb-0" id="${id}help">${multipleChoice ? 'Paste one question with choices and its answer to fill this card. ' : ''}Use the buttons above to format code, or paste a code block between triple backticks.</p>
          ${multipleChoice ? '<p class="assessment-paste-status" data-paste-status role="status"></p>' : ''}
          <div class="assessment-prompt-preview" hidden><p class="assessment-eyebrow">QUESTION PREVIEW</p><div class="assessment-formatted-prompt"></div></div>
          <div class="assessment-answer-key">${key}</div>
        </div>
      </article>`;
    }).join('');
    $('assessmentQuestions').querySelectorAll('[data-question]').forEach(updateQuestionPreview);
    totals();
    updateQuestionActions();
    if (focus !== null) $('assessmentQuestions').querySelector(`[data-question="${focus}"] [data-field="prompt"]`)?.focus();
  }
  function showPasteScan(input) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const field = input.parentElement;
    field.querySelector('.assessment-paste-scan')?.remove();
    const scan = document.createElement('span');
    scan.className = 'assessment-paste-scan';
    scan.setAttribute('aria-hidden', 'true');
    const removeScan = () => scan.remove();
    scan.addEventListener('animationend', removeScan, { once:true });
    scan.addEventListener('animationcancel', removeScan, { once:true });
    field.append(scan);
  }
  async function pasteQuestion(event) {
    const input = event.target;
    if (!input.matches('textarea[data-field="prompt"]') || state.busy || event.defaultPrevented) return;
    const card = input.closest('[data-question]');
    const item = state.questions[Number(card.dataset.question)];
    if (item.question_type !== 'multiple_choice') return;

    const text = event.clipboardData?.getData('text/plain');
    if (!text) return;
    const parsed = window.synkAssessmentPaste?.(text);
    const status = card.querySelector('[data-paste-status]');
    if (!parsed || parsed.message) {
      status.dataset.tone = 'review';
      status.textContent = parsed?.message || '';
      return;
    }

    event.preventDefault();
    const filled = input.value.trim() || card.querySelector('[data-answer]:checked')
      || [...card.querySelectorAll('[data-choice]')].some(choice => choice.value.trim());
    if (filled && !await feedback.confirm({
      title:'Replace this question?',
      text:'Use the pasted question, choices, and correct answer in this card.',
      confirmText:'Replace question',
      cancelText:'Keep current question'
    })) return;
    if (!card.isConnected || state.busy) return;

    item.prompt = input.value = parsed.prompt;
    item.options = parsed.options;
    item.answer_key = parsed.answerKey;
    input.classList.remove('is-invalid');
    card.querySelector('.assessment-answer-key').innerHTML = multipleChoiceFields(item, 'aq' + item.uid);
    updateQuestionPreview(card);
    dirty();
    input.focus({ preventScroll:true });
    input.setSelectionRange(input.value.length, input.value.length);
    status.dataset.tone = 'success';
    status.textContent = `${parsed.options.length} choices filled. Choice ${String.fromCharCode(65 + parsed.answerKey)} selected as correct. Review before saving.`;
    showPasteScan(input);
  }
  function toggleType() {
    const assignment = $('assessmentType').value === 'assignment';
    $('assessmentQuestionsSection').hidden = assignment;
    $('assessmentAssignmentNote').hidden = !assignment;
    $('assessmentAssignmentPoints').hidden = !assignment;
    $('assessmentPoints').required = assignment;
    $('assessmentPoints').disabled = !assignment;
    $('assessmentTimer').hidden = assignment;
    $('assessmentTimeLimit').disabled = assignment;
    updateQuestionActions();
  }
  function editorLabels() {
    const record = state.draft, live = ['published','closed'].includes(record.status);
    $('assessmentEditorTitle').textContent = record.assessment_id ? (live ? 'Edit assessment' : 'Edit draft') : 'New assessment';
    $('assessmentSaveState').textContent = record.assessment_id ? `Saved ${live ? record.status : 'draft'} / version ${record.version}` : 'Not saved yet';
    for (const id of ['assessmentSave','assessmentSaveBottom']) $(id).textContent = live ? 'Save changes' : 'Save draft';
    for (const id of ['assessmentPublish','assessmentPublishBottom']) {
      $(id).hidden = record.status === 'published';
      $(id).textContent = record.status === 'closed' ? 'Reopen' : 'Publish';
      $(id).parentElement.classList.toggle('is-published', record.status === 'published');
    }
    $('assessmentLiveNote').hidden = !live;
    $('assessmentLiveNote').textContent = 'Changes apply only to students who have not started. Started attempts keep their original questions, instructions, answer keys, points, and deadline.' + (record.status === 'closed' ? ' Saving keeps this assessment closed. Reopen allows new attempts; submitted and time-ended attempts stay locked.' : ' Saving keeps this assessment published.');
    $('assessmentSettingsHelp').textContent = 'Dates use Philippine time (Asia/Manila). ' + (live ? 'Updated opening dates, deadlines, and time limits apply to new attempts. Existing timers do not change.' : 'Saving a draft does not publish it.');
  }
  function openEditor(record) {
    bulkQuestions.clear();
    state.draft = record;
    state.questions = (record.questions || []).map(item => question(item.question_type, { ...item, uid:++state.sequence }));
    state.dirty = false;
    $('assessmentTitle').value = record.title || '';
    $('assessmentInstructions').value = record.instructions || '';
    $('assessmentTopic').value = record.topic || '';
    $('assessmentPeriod').value = record.grading_period;
    $('assessmentType').value = record.assessment_type;
    $('assessmentPoints').value = record.assessment_type === 'assignment' ? record.total_points : 100;
    $('assessmentOpens').value = stamp(record.opens_at);
    $('assessmentDue').value = stamp(record.due_at);
    $('assessmentTimeLimit').value = record.time_limit_minutes || '';
    editorLabels();
    $('assessmentLibrary').hidden = true;
    $('assessmentEditor').hidden = false;
    $('assessmentNew').hidden = true;
    toggleType(); renderQuestions();
    updateFloatingActions();
    $('assessmentTitle').focus();
  }
  function readDraft() {
    syncQuestions();
    return { assessment_id:state.draft.assessment_id || 0, version:state.draft.version || 0,
      grading_period:$('assessmentPeriod').value, assessment_type:$('assessmentType').value,
      title:$('assessmentTitle').value.trim(), instructions:$('assessmentInstructions').value, topic:$('assessmentTopic').value,
      opens_at:$('assessmentOpens').value || null, due_at:$('assessmentDue').value || null,
      time_limit_minutes:$('assessmentTimeLimit').disabled ? null : $('assessmentTimeLimit').value || null,
      total_points:$('assessmentPoints').value,
      questions:state.questions.map(({ uid, question_id, ...item }) => item) };
  }
  function showPreview(record) {
    state.preview = record;
    $('assessmentShowAnswers').checked = false;
    $('assessmentShowAnswers').closest('.form-check').hidden = record.assessment_type === 'assignment';
    renderPreview(); previewModal.show();
  }
  function renderPreview() {
    const item = state.preview, showAnswers = $('assessmentShowAnswers').checked;
    const total = item.assessment_type === 'assignment' ? item.total_points : item.questions.reduce((sum,q) => sum + (Number(q.points) || 0), 0);
    $('assessmentPreviewTitle').textContent = item.title || 'Untitled assessment';
    $('assessmentPreviewBody').innerHTML = `<div class="assessment-preview-meta"><span>${escape(periods[item.grading_period])}</span><span>${escape(labels[item.assessment_type])}</span><span>${number(total)} points</span>${item.time_limit_minutes ? `<span>${number(item.time_limit_minutes)} minutes</span>` : ''}<span>${item.due_at ? 'Due ' + escape(dateLabel(item.due_at.length === 16 ? item.due_at + ':00' : item.due_at)) : 'No due date'}</span></div><p class="assessment-plain-text">${escape(item.instructions || 'No instructions added.')}</p>${item.questions.map((q,index) => {
      let answer = '', choices = '';
      if (q.question_type === 'multiple_choice') {
        choices = q.options.map(option => `<label class="assessment-preview-choice"><input type="radio" disabled>${escape(option)}</label>`).join('');
        answer = q.answer_key === null ? 'Answer key not set' : 'Correct answer: ' + (q.options[q.answer_key] || '');
      } else if (q.question_type === 'true_false') {
        choices = ['True','False'].map(value => `<label class="assessment-preview-choice"><input type="radio" disabled>${value}</label>`).join('');
        answer = q.answer_key === null ? 'Answer key not set' : 'Correct answer: ' + (q.answer_key ? 'True' : 'False');
      } else if (q.question_type === 'short_answer') {
        choices = '<input type="text" class="form-control" disabled aria-label="Short answer preview" placeholder="Student answer">';
        answer = 'Accepted answers: ' + (q.answer_key || []).join(' / ');
      } else { choices = '<textarea class="form-control" rows="4" disabled aria-label="Essay answer preview" placeholder="Student response"></textarea>'; answer = 'Manual grading'; }
      return `<section class="assessment-preview-question"><div class="assessment-preview-meta mb-2"><span>Question ${index+1}</span><span>${number(q.points)} points</span></div><div class="assessment-formatted-prompt">${formatQuestion(q.prompt || 'Question not added yet')}</div>${choices}${showAnswers ? `<div class="assessment-preview-answer">${escape(answer)}</div>` : ''}</section>`;
    }).join('')}${!item.questions.length && item.assessment_type !== 'assignment' ? '<p class="assessment-help">No questions have been added yet.</p>' : ''}`;
  }
  root.addEventListener('click', event => {
    const period = event.target.closest('[data-period]');
    if (period && !state.busy) { state.period = period.dataset.period; renderList(); }
  });
  $('assessmentSearch').addEventListener('input', renderList);
  $('assessmentTypeFilter').addEventListener('change', renderList);
  $('assessmentRefresh').addEventListener('click', () => task(refresh));
  $('assessmentGenerateFollowup').addEventListener('click',()=>{cardActionOrigin=null;task(async()=>{$('assessmentFollowupSearch').value='';$('assessmentFollowupPreview').open=false;await loadFollowup();followupModal.show();});});
  async function newDraft() {
    if (state.busy || !await canLeave()) return;
    const period = !$('assessmentEditor').hidden ? $('assessmentPeriod').value : state.period;
    notice(); openEditor({ assessment_id:0, version:0, grading_period:period, assessment_type:'quiz', questions:[] });
  }
  $('assessmentNew').addEventListener('click',newDraft);
  $('assessmentBack').addEventListener('click', async () => {
    if (!await canLeave()) return;
    state.dirty = false; state.period = $('assessmentPeriod').value; state.draft = null;
    $('assessmentEditor').hidden = true; $('assessmentLibrary').hidden = false; $('assessmentNew').hidden = false;
    updateFloatingActions();
    notice(); renderList(); $('assessmentNew').focus();
  });
  $('assessmentForm').addEventListener('input', event => {
    dirty();
    const card = event.target.closest('[data-question]');
    if (event.target.matches('[data-field="prompt"]')) updateQuestionPreview(card);
    if (event.target.matches('[data-answer]')) {
      card.querySelector('[data-answer].is-invalid')?.classList.remove('is-invalid');
    }
    const pasteStatus = card?.querySelector('[data-paste-status]');
    if (pasteStatus && event.inputType !== 'insertFromPaste') pasteStatus.textContent = '';
    if (event.target.matches('[data-field="points"]')) { syncQuestions(); totals(); }
  });
  $('assessmentForm').addEventListener('change', dirty);
  $('assessmentType').addEventListener('change', async () => {
    const selected = $('assessmentType').value;
    if (selected === 'assignment' && state.questions.length) {
      const wasDirty = state.dirty, saveState = $('assessmentSaveState').textContent;
      $('assessmentType').value = state.draft.assessment_type;
      if (!await feedback.confirm({title:'Switch to Assignment?',text:'The quiz questions will be removed from this assessment. Started attempts keep their original questions. Changes take effect when you save.',confirmText:'Switch type',cancelText:'Keep questions'})) {
        state.dirty = wasDirty; $('assessmentSaveState').textContent = saveState; updateFloatingActions(); return;
      }
      $('assessmentType').value = selected;
    }
    if (selected === 'assignment') { state.questions = []; renderQuestions(); }
    state.draft.assessment_type = selected; toggleType(); dirty();
  });
  function addQuestion() {
    if (state.busy || $('assessmentType').value === 'assignment' || state.questions.length >= questionLimit) return;
    syncQuestions();
    state.questions.unshift(question()); renderQuestions(0); dirty();
  }
  function addBulkQuestions(entries, details) {
    if (state.busy || !state.draft || $('assessmentEditor').hidden || $('assessmentType').value === 'assignment') {
      return { message:'Open a quiz or exam to add these questions.' };
    }
    const firstIndex = state.questions.length;
    if (!entries.length || firstIndex + entries.length > questionLimit) {
      return { message:'Select questions that fit the 100-question limit.' };
    }
    const draft = { ...readDraft(), ...details };
    const questions = [...draft.questions, ...entries];
    const total = questions.reduce((sum, item) => sum + Math.round((Number(item.points) || 0) * 100), 0);
    if (total > 1000000) return { message:'The assessment can have up to 10,000 points. Lower the points or uncheck some questions.' };
    const bytes = new TextEncoder().encode(requestBody('publish', { ...draft, questions })).length;
    if (bytes > 1048576) return { message:'This selection would make the assessment too large to save. Uncheck some questions or shorten their text.' };

    for (const [key, value] of Object.entries(details)) {
      detailFields[key].value = value;
      detailFields[key].classList.remove('is-invalid');
    }
    state.questions.push(...entries.map(item => question(item.question_type, item)));
    renderQuestions();
    dirty();
    return { focus:$('assessmentQuestions').querySelector(`[data-question="${firstIndex}"] [data-field="prompt"]`) };
  }
  $('assessmentAddQuestion').addEventListener('click', addQuestion);
  $('assessmentAddQuestionBottom').addEventListener('click', addQuestion);
  root.querySelectorAll('[data-assessment-bulk-open]').forEach(button => button.addEventListener('click', () => {
    if (!state.busy && state.draft && $('assessmentType').value !== 'assignment' && state.questions.length < questionLimit) bulkQuestions.open(button);
  }));
  $('assessmentQuestions').addEventListener('paste', pasteQuestion);
  $('assessmentQuestions').addEventListener('change', event => {
    if (!event.target.matches('[data-field="type"]')) return;
    const index = Number(event.target.closest('[data-question]').dataset.question);
    syncQuestions(); const previous = state.questions[index];
    state.questions[index] = question(event.target.value, { prompt:previous.prompt, points:previous.points });
    renderQuestions(index); dirty();
  });
  $('assessmentQuestions').addEventListener('click', async event => {
    const format = event.target.closest('[data-format]');
    if (format && !state.busy) {
      const input = $(format.getAttribute('aria-controls'));
      const start = input.selectionStart, end = input.selectionEnd, selected = input.value.slice(start, end);
      const block = format.dataset.format === 'block' || /[\n`]/.test(selected);
      const content = selected || (block ? '// Write or paste code here' : 'code');
      const runs = content.match(/`+/g) || [];
      const fence = '`'.repeat(Math.max(3, ...runs.map(run => run.length + 1)));
      const before = block ? (start > 0 && input.value[start-1] !== '\n' ? '\n' : '') + fence + '\n' : '`';
      const after = block ? (content.endsWith('\n') ? '' : '\n') + fence + (end < input.value.length && input.value[end] !== '\n' ? '\n' : '') : '`';
      const replacement = before + content + after;
      if (input.value.length - selected.length + replacement.length > input.maxLength) { notice('Code formatting would exceed the 10,000-character question limit.', true); return; }
      input.setRangeText(replacement, start, end, 'select');
      input.focus({ preventScroll:true }); input.setSelectionRange(start + before.length, start + before.length + content.length);
      input.dispatchEvent(new Event('input', { bubbles:true }));
      return;
    }
    const button = event.target.closest('[data-operation]'); if (!button || state.busy) return;
    const index = Number(button.closest('[data-question]').dataset.question), operation = button.dataset.operation;
    syncQuestions(); const item = state.questions[index];
    if (operation === 'remove') { if (item.prompt && !await feedback.confirm({title:'Delete question?',text:'This question will be removed when you save. Started attempts keep their original questions and answers.',confirmText:'Delete question',danger:true})) return; state.questions.splice(index,1); }
    if (operation === 'copy' && state.questions.length < questionLimit) state.questions.splice(index+1,0,question(item.question_type, { ...JSON.parse(JSON.stringify(item)), uid:++state.sequence }));
    if (operation === 'up' && index > 0) [state.questions[index-1],state.questions[index]] = [item,state.questions[index-1]];
    if (operation === 'down' && index < state.questions.length-1) [state.questions[index+1],state.questions[index]] = [item,state.questions[index+1]];
    if (operation === 'add-choice' && item.options.length < 6) item.options.push('');
    if (operation === 'remove-choice' && item.options.length > 2) {
      const choice = Number(button.dataset.choiceIndex); item.options.splice(choice,1);
      if (item.answer_key === choice) item.answer_key = null;
      else if (item.answer_key > choice) item.answer_key--;
    }
    renderQuestions(); dirty();
  });
  $('assessmentForm').addEventListener('submit', async event => {
    event.preventDefault(); if (state.busy) return;
    const publishing = ['assessmentPublish','assessmentPublishBottom'].includes(event.submitter?.id);
    const live = ['published','closed'].includes(state.draft.status), reopening = publishing && state.draft.status === 'closed';
    const fromBottom = event.submitter === $('assessmentSaveBottom');
    let saved = false;
    const payload = readDraft();
    if (payload.opens_at && payload.due_at && payload.due_at <= payload.opens_at) { notice('The due date must be after the opening date.', true); return; }
    if (publishing && !await feedback.confirm({title:reopening ? 'Reopen assessment?' : 'Publish assessment?',text:reopening ? 'Your changes will be saved and new students can start according to the saved dates. Started attempts keep their original version. Submitted and time-ended attempts stay locked.' : 'Your current work will be saved and published to enrolled students. Opening dates, deadlines, and time limits apply. You can edit it later; started attempts keep their original version.',confirmText:reopening ? 'Reopen' : 'Publish',cancelText:'Keep editing'})) return;
    if (!publishing && live && !await feedback.confirm({title:'Save assessment changes?',text:'Changes apply to new attempts. Students who have started keep their original questions, answers, results, and deadline.',confirmText:'Save changes',cancelText:'Keep editing'})) return;
    await task(async () => {
      notice(); const data = await writeAssessment(publishing ? 'publish' : 'save', payload);
      state.draft = data.assessment; state.dirty = false; saved = true;
      updateFloatingActions();
      editorLabels();
      if (publishing) {
        state.period=data.assessment.grading_period;state.draft=null;
        $('assessmentEditor').hidden=true;$('assessmentLibrary').hidden=false;$('assessmentNew').hidden=false;updateFloatingActions();
      }
      notice(publishing ? (reopening ? 'Assessment reopened. Existing attempts are preserved.' : 'Assessment published. Enrolled students can open it from their assessments page.') : live ? 'Assessment changes saved for new attempts.' : 'Draft saved. It is private to you.');
      try { await refresh(); } catch { notice('Assessment saved. Refresh the list to see the latest counts.'); }
    });
    if (saved && fromBottom) $('assessmentSave').focus({ preventScroll:true });
  });
  $('assessmentPreview').addEventListener('click', () => showPreview(readDraft()));
  $('assessmentPreviewBottom').addEventListener('click', () => showPreview(readDraft()));
  $('assessmentShowAnswers').addEventListener('change', renderPreview);
  $('assessmentList').addEventListener('click', async event => {
    const more = event.target.closest('[data-assessment-more]');
    if (more && !state.busy) {
      const menu = more.nextElementSibling, opening = menu.hidden;
      closeCardMenu();
      if (opening) { cardMenu = menu; menu.hidden = false; more.setAttribute('aria-expanded', 'true'); menu.querySelector('button:not(:disabled)')?.focus(); }
      return;
    }
    const button = event.target.closest('[data-action]'); if (!button || state.busy) return;
    const row = state.rows.find(item => item.assessment_id === Number(button.dataset.id)); if (!row) return;
    const action = button.dataset.action;
    cardActionOrigin = { id:row.assessment_id, action };
    closeCardMenu(true);
    if (action === 'schedule') {
      state.schedule = row;
      $('assessmentScheduleModal').dataset.assessmentId = row.assessment_id;
      $('assessmentScheduleName').textContent = row.title;
      $('assessmentScheduleStart').value = stamp(row.opens_at);
      $('assessmentScheduleEnd').value = stamp(row.due_at);
      $('assessmentScheduleNote').textContent = row.status === 'draft' ? 'Saving the schedule keeps this assessment a private draft.' : 'Changes apply to students who have not started. Started attempts keep their original deadline.' + (row.status === 'closed' ? ' This assessment stays closed; use Edit assessment to reopen it.' : ' This assessment stays published.');
      $('assessmentScheduleForm').querySelectorAll('.is-invalid').forEach(field => field.classList.remove('is-invalid'));
      scheduleModal.show(); return;
    }
    if (action === 'transfer') {
      const period = row.grading_period === 'midterm' ? 'final' : 'midterm';
      const origin = cardActionOrigin;
      if (!await feedback.confirm({title:'Move assessment to ' + periods[period] + '?',text:'Move “' + row.title + '” from ' + periods[row.grading_period] + ' to ' + periods[period] + '. Questions, schedule, status, and student responses are preserved. Existing gradebook entries stay in their current period.',confirmText:'Move to ' + periods[period]})) { focusCardAction(origin); return; }
      await task(async () => {
        const data = await api('transfer', { assessment_id:row.assessment_id, version:row.version, grading_period:period });
        state.rows = state.rows.map(item => item.assessment_id === row.assessment_id ? { ...item, ...data.assessment } : item);
        state.period = data.assessment.grading_period;
        $('assessmentSearch').value = ''; $('assessmentTypeFilter').value = 'all';
        renderList(); notice('Assessment moved to ' + periods[state.period] + '.');
      });
      focusCardAction(origin); return;
    }
    if (action === 'close') {
      if (!await feedback.confirm({title:'Close assessment?',text:'Students will no longer be able to start or change responses. Saved work will be retained.',confirmText:'Close assessment',danger:true})) return;
      task(async () => { await api('close',{assessment_id:row.assessment_id,version:row.version});await refresh();notice('Assessment closed.'); });return;
    }
    if (action === 'submissions') {
      task(async () => {
        const data=await api('submissions',{assessment_id:row.assessment_id});
        $('assessmentSubmissionsTitle').textContent=row.title+' / Student responses';
        $('assessmentSubmissionsBody').innerHTML=data.submissions.length ? data.submissions.map(attempt => `<details class="assessment-response"><summary>${escape(attempt.student_name || attempt.student_number)} <span>${escape(attempt.status.replace('_',' '))} / version ${number(attempt.assessment.version)}</span></summary>${attempt.assessment.assessment_type==='assignment' ? `<p class="assessment-plain-text">${escape(attempt.answers.assignment_response || 'No response saved.')}</p>` : attempt.assessment.questions.map((q,index) => {
          const value=attempt.answers[q.question_id];
          const answer=value===null || value===undefined || value==='' ? 'No response saved.' : q.question_type==='multiple_choice' ? q.options[value] : q.question_type==='true_false' ? (value?'True':'False') : value;
          return `<div class="assessment-preview-question"><strong>Question ${index+1}</strong><div class="assessment-formatted-prompt">${formatQuestion(q.prompt)}</div><p class="assessment-plain-text">${escape(answer)}</p></div>`;
        }).join('')}</details>`).join('') : '<p class="assessment-help">No student attempts yet.</p>';
        submissionsModal.show();
      });return;
    }
    if (action === 'duplicate') {
      state.duplicate = row; $('assessmentDuplicateName').textContent = row.title;
      $('assessmentDuplicatePeriod').value = row.grading_period; duplicateModal.show(); return;
    }
    if (action === 'archive') {
      if (!await feedback.confirm({title:'Archive this draft?',text:'It will be removed from the active draft list.',confirmText:'Archive draft',danger:true})) return;
      task(async () => { await api('archive', { assessment_id:row.assessment_id, version:row.version }); await refresh(); notice('Draft archived.'); });
      return;
    }
    task(async () => {
      const data = await api('detail', { assessment_id:row.assessment_id });
      if (action === 'preview') showPreview(data.assessment);
      else { notice(); openEditor(data.assessment); }
    });
  });
  $('assessmentList').addEventListener('keydown', event => {
    if (!cardMenu) {
      const more = event.target.closest('[data-assessment-more]');
      if (more && ['ArrowDown','ArrowUp'].includes(event.key) && !state.busy) {
        event.preventDefault(); more.click();
        if (event.key === 'ArrowUp') cardMenu?.querySelector('button:last-child')?.focus();
      }
      return;
    }
    if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeCardMenu(true); return; }
    if (event.key === 'Tab') { closeCardMenu(true); return; }
    const items = [...cardMenu.querySelectorAll('button:not(:disabled)')];
    const index = items.indexOf(document.activeElement);
    if (['ArrowDown','ArrowUp','Home','End'].includes(event.key)) {
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items[next]?.focus();
    }
  });
  document.addEventListener('click', event => { if (cardMenu && !cardMenu.parentElement.contains(event.target)) closeCardMenu(); });
  compactCards.addEventListener('change', () => {
    const focused = document.activeElement;
    const origin = $('assessmentList').contains(focused) ? { id:Number(focused.dataset.id), action:focused.dataset.action || 'more' } : null;
    renderList(); focusCardAction(origin);
  });
  for (const id of ['assessmentPreviewModal','assessmentDuplicateModal','assessmentSubmissionsModal','assessmentFollowupModal']) {
    $(id).addEventListener('hidden.bs.modal', () => {if(id==='assessmentFollowupModal')$('assessmentGenerateFollowup').focus({preventScroll:true});else focusCardAction(cardActionOrigin);});
  }
  $('assessmentFollowupSearch').addEventListener('input',renderFollowupStudents);
  $('assessmentFollowupCopy').addEventListener('click',copyFollowup);
  $('assessmentFollowupRefresh').addEventListener('click',()=>{if(state.followup)task(async()=>{try{await loadFollowup();}catch(error){$('assessmentFollowupCopyStatus').textContent='Refresh failed. The last loaded list is shown; check its timestamp before copying.';throw error;}});});
  $('assessmentDuplicateForm').addEventListener('submit', event => {
    event.preventDefault(); if (!state.duplicate || state.busy) return;
    const row = state.duplicate, period = $('assessmentDuplicatePeriod').value;
    task(async () => {
      const data = await api('duplicate', { assessment_id:row.assessment_id, version:row.version, grading_period:period });
      duplicateModal.hide(); state.period = period; await refresh(); openEditor(data.assessment); notice('Copy created as a private draft.');
    });
  });
  $('assessmentScheduleModal').addEventListener('shown.bs.modal', () => $('assessmentScheduleStart').focus());
  $('assessmentScheduleModal').addEventListener('hide.bs.modal', event => { if (state.busy && state.schedule) event.preventDefault(); });
  $('assessmentScheduleModal').addEventListener('hidden.bs.modal', () => {
    state.schedule = null;
    $('assessmentList').querySelector(`[data-action="schedule"][data-id="${$('assessmentScheduleModal').dataset.assessmentId}"]`)?.focus({ preventScroll:true });
  });
  $('assessmentScheduleForm').addEventListener('submit', event => {
    event.preventDefault(); if (!state.schedule || state.busy) return;
    const row = state.schedule, opens = $('assessmentScheduleStart').value || null, due = $('assessmentScheduleEnd').value || null;
    if (opens && due && due <= opens) { notice('The end must be after the start.', true); return; }
    task(async () => {
      const data = await api('schedule', { assessment_id:row.assessment_id, version:row.version, opens_at:opens, due_at:due });
      state.rows = state.rows.map(item => item.assessment_id === row.assessment_id ? { ...item, ...data.assessment } : item);
      renderList(); state.schedule = null; scheduleModal.hide(); notice('Assessment schedule saved.');
    });
  });
  feedback.validation($('assessmentForm')); feedback.validation($('assessmentDuplicateForm')); feedback.validation($('assessmentScheduleForm'));
  feedback.guardNavigation(() => state.dirty,() => { state.dirty = false; },'Your unsaved assessment changes will be discarded.');
  window.addEventListener('beforeunload', event => { if (state.dirty) { event.preventDefault(); event.returnValue = ''; } });
  window.addEventListener('resize', positionFloatingActions);
  if (window.ResizeObserver) {
    const observer = new ResizeObserver(positionFloatingActions);
    observer.observe(root);
    if ($('professor-bottom-nav')) observer.observe($('professor-bottom-nav'));
  }
  task(async () => {
    try { await refresh(); }
    catch (error) { $('assessmentList').innerHTML = '<div class="card assessment-empty">The draft list could not be loaded. Use Refresh to try again.</div>'; $('assessmentList').setAttribute('aria-busy','false'); throw error; }
  });
})();
