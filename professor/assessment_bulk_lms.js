(function () {
  'use strict';
  const { escape, formatQuestion } = window.synkLmsContent;
  const parseQuestion = window.synkAssessmentPaste;
  const splitQuiz = window.synkSplitAssessmentPaste;
  const number = value => value.toLocaleString('en-PH', { maximumFractionDigits:2 });
  const noun = (count, word) => count === 1 ? word : word + 's';
  const nextPaint = () => new Promise(resolve => requestAnimationFrame(() => setTimeout(resolve, 0)));
  const checkStates = {
    checking:['bx-loader-alt', 'Checking'],
    ready:['bx-check', 'Ready'],
    review:['bx-error-circle', 'Needs review']
  };

  function checkQuestion(source) {
    return parseQuestion(source) || { message:'Enter the question, its choices, and one correct answer.' };
  }

  function setupBulkQuestions({ room, detailFields, addQuestions }) {
    const $ = name => document.getElementById('assessmentBulk' + name);
    const modal = new bootstrap.Modal($('Modal'));
    const source = $('Source');
    const list = $('List');
    const points = $('Points');
    let step = 'paste';
    let run = 0;
    let items = [];
    let batchDetails = null;
    let opener = null;
    let addedQuestion = null;

    function showMessage(message = '') {
      $('Message').textContent = message;
      $('Message').hidden = !message;
    }

    function showStep(next) {
      step = next;
      $('Paste').hidden = next !== 'paste';
      $('Progress').hidden = next === 'paste';
      $('Progress').dataset.phase = next;
      $('Review').hidden = next !== 'review';
      $('Selection').hidden = next !== 'review';
      $('Process').hidden = next !== 'paste';
      $('Edit').hidden = next !== 'review';
      $('Add').hidden = next !== 'review';
      $('Body').scrollTop = 0;
      showMessage();
    }

    function clear() {
      run++;
      items = [];
      batchDetails = null;
      source.value = '';
      list.replaceChildren();
      $('Checked').replaceChildren();
      $('NotesBody').replaceChildren();
      $('Notes').open = false;
      showStep('paste');
    }

    function showRoom() {
      const available = room();
      $('Room').textContent = `Room for ${available} more ${noun(available, 'question')} in this assessment.`;
    }

    function selectedItems() {
      return items.filter(item => item.included);
    }

    function updateSelection() {
      const count = selectedItems().length;
      const value = Number(points.value);
      const available = room();
      const validPoints = points.validity.valid;
      const ready = items.filter(item => !item.result.message).length;
      const problems = items.length - ready;
      const expectedCount = batchDetails?.expectedCount ?? null;
      const mismatch = expectedCount !== null && expectedCount !== items.length;
      $('Summary').textContent = `${ready} ready${problems ? ' · ' + problems + (problems === 1 ? ' needs review' : ' need review') : ''}`
        + (mismatch ? `. The paste lists ${expectedCount} items, and ${items.length} numbered questions were found.` : '');
      $('Selection').textContent = count > available
        ? `Room for ${available} more ${noun(available, 'question')}. Uncheck ${count - available} to fit.`
        : `${count} selected${validPoints ? ' · ' + number(count * value) + ' ' + noun(count * value, 'point') : ' · Enter valid points per question'}`;
      $('Add').disabled = count === 0 || count > available || !validPoints;
      $('Add').textContent = count ? `Add ${count} ${noun(count, 'question')}` : 'Add questions';
      list.querySelectorAll('[data-bulk-points]').forEach(label => {
        label.textContent = validPoints ? number(value) + ' ' + noun(value, 'pt') : '—';
      });
    }

    function updateDetailsLabel() {
      const checks = Array.from($('NotesBody').querySelectorAll('[data-bulk-field]'));
      const selected = checks.filter(check => check.checked).length;
      const hasReplacements = checks.some(check => check.dataset.replacement === 'true' && !check.checked);
      const notes = batchDetails.notes.length;
      $('NotesLabel').textContent = [
        'Detected quiz details',
        selected ? `${selected} ${noun(selected, 'field')} to add` : '',
        hasReplacements ? 'Review replacements' : '',
        notes ? `${notes} ${noun(notes, 'note')}` : ''
      ].filter(Boolean).join(' · ');
    }

    function detailOption(key, value) {
      const field = detailFields[key];
      const label = key[0].toUpperCase() + key.slice(1);
      const current = field.value.trim();
      const tooLong = value.length > field.maxLength;
      let option;
      if (tooLong) {
        option = `<p class="assessment-bulk-help">Shorten the pasted ${key} to ${number(field.maxLength)} characters to add it.</p>`;
      } else if (current === value) {
        option = '<p class="assessment-bulk-help">Already in the editor.</p>';
      } else {
        option = `<label class="assessment-bulk-detail-option"><input type="checkbox" class="form-check-input" data-bulk-field="${key}" data-replacement="${!!current}"${current ? '' : ' checked'}>
          ${current ? 'Replace current ' : 'Add pasted '}${key}</label>`;
      }
      const preview = tooLong && value.length > 240 ? value.slice(0, 240) + '…' : value;
      return `<div><dt>${label}</dt><dd><p>${escape(preview)}</p>${option}</dd></div>`;
    }

    function renderDetails() {
      const details = batchDetails;
      const fields = Object.keys(detailFields).filter(key => details[key]);
      const labels = [
        ['Listed questions', details.expectedCount], ['Listed total', details.declaredTotal]
      ].filter(([, value]) => value !== '' && value !== null);
      const notes = details.notes;
      $('Notes').hidden = !fields.length && !labels.length && !notes.length;
      $('Notes').open = false;
      const definition = fields.map(key => detailOption(key, details[key])).join('') + labels.map(([label, value]) =>
        `<div><dt>${label}</dt><dd>${escape(value)}</dd></div>`).join('');
      $('NotesBody').innerHTML = (definition ? '<dl>' + definition + '</dl>' : '')
        + (notes.length ? '<ul>' + notes.map(note => `<li>${note.number === undefined ? '' : '<strong>After question ' + note.number + '</strong>'}<p>${escape(note.text)}</p></li>`).join('') + '</ul>' : '');
      updateDetailsLabel();
    }

    function renderItem(item, index, expanded = false) {
      const { result } = item;
      const needsReview = !!result.message;
      const tag = `Question ${item.number}`;
      const prompt = (result.prompt || item.source).split('\n')[0];
      const snippet = prompt.length > 180 ? prompt.slice(0, 179) + '…' : prompt;
      const answer = needsReview ? '' : `<span class="assessment-bulk-answer">Correct answer · ${String.fromCharCode(65 + result.answerKey)}. ${escape(result.options[result.answerKey])}</span>`;
      const content = needsReview ? `<p class="assessment-bulk-issue" id="assessmentBulkIssue${index}">${escape(result.message)}</p>
        <label class="form-label" for="assessmentBulkRepair${index}">Edit question ${item.number}</label>
        <textarea class="form-control assessment-code-input" id="assessmentBulkRepair${index}" rows="7" data-bulk-repair aria-describedby="assessmentBulkIssue${index}" spellcheck="false">${escape(item.source)}</textarea>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-bulk-recheck>Recheck question</button>`
        : `<div class="assessment-formatted-prompt">${formatQuestion(result.prompt)}</div><ol class="assessment-bulk-choices" type="A">${result.options.map((choice, choiceIndex) =>
          `<li${choiceIndex === result.answerKey ? ' class="is-correct"' : ''}>${escape(choice)}${choiceIndex === result.answerKey ? ' <span>Correct</span>' : ''}</li>`).join('')}</ol>`;
      return `<li class="assessment-bulk-item" data-bulk-row="${index}">
        <label class="assessment-bulk-include"><input type="checkbox" class="form-check-input" aria-label="Include ${tag.toLowerCase()}" data-bulk-include${item.included ? ' checked' : ''}${needsReview ? ' disabled' : ''}></label>
        <details${expanded ? ' open' : ''}>
          <summary>
            <span class="assessment-bulk-item-title"><span>${tag}</span><strong>${escape(snippet || 'Question text missing')}</strong>${answer}</span>
            ${needsReview ? '' : '<span class="assessment-bulk-points" data-bulk-points></span>'}
            <span class="assessment-bulk-tag ${needsReview ? 'is-review' : 'is-ready'}"><i class="bx ${needsReview ? 'bx-error-circle' : 'bx-check'}" aria-hidden="true"></i>${needsReview ? 'Needs review' : 'Ready'}</span>
            <i class="bx bx-chevron-down assessment-bulk-chevron" aria-hidden="true"></i>
          </summary>
          <div class="assessment-bulk-item-body">${content}</div>
        </details>
      </li>`;
    }

    function renderChecks(sources) {
      const stagger = Math.min(40, 900 / Math.max(1, sources.length - 1));
      $('Checked').innerHTML = sources.map((item, index) => `<li class="assessment-bulk-check" data-number="${item.number}" data-state="queued"
        title="Question ${item.number} · Queued" style="--bulk-check-delay:${Math.round(index * stagger)}ms">
        <span class="visually-hidden">Question </span>${item.number}
        <span class="assessment-bulk-check-icon" aria-hidden="true"><i class="bx bx-time-five"></i></span>
        <span class="visually-hidden" data-bulk-check-label> · Queued</span>
      </li>`).join('');
    }

    function markCheck(index, state) {
      const tile = $('Checked').children[index];
      const [icon, label] = checkStates[state];
      tile.dataset.state = state;
      tile.title = `Question ${tile.dataset.number} · ${label}`;
      tile.querySelector('i').className = 'bx ' + icon;
      tile.querySelector('[data-bulk-check-label]').textContent = ' · ' + label;
    }

    async function process() {
      if (step !== 'paste') return;
      const text = source.value;
      if (!text.trim()) { showMessage('Paste your questions to continue.'); source.focus(); return; }
      const current = ++run;
      const progress = $('Bar');
      progress.max = 1;
      progress.value = 0;
      $('ProgressTitle').textContent = 'Checking questions';
      $('ProgressIcon').className = 'bx bx-loader-alt';
      $('Current').textContent = 'Finding numbered questions…';
      $('Checked').replaceChildren();
      showStep('checking');
      $('Cancel').focus();
      await nextPaint();
      if (current !== run) return;

      const batch = splitQuiz(text);
      if (batch.message) {
        showStep('paste');
        showMessage(batch.message);
        source.focus();
        return;
      }
      progress.max = batch.items.length;
      renderChecks(batch.items);
      markCheck(0, 'checking');
      $('Current').textContent = `Checking question 1 of ${batch.items.length}`;
      await nextPaint();
      if (current !== run) return;
      const checked = [];
      let sliceStart = performance.now();
      for (const [index, item] of batch.items.entries()) {
        const result = checkQuestion(item.source);
        const entry = { ...item, result, included:!result.message };
        checked.push(entry);
        markCheck(index, result.message ? 'review' : 'ready');
        progress.value = index + 1;
        if (index + 1 < batch.items.length) {
          markCheck(index + 1, 'checking');
          $('Current').textContent = `Checking question ${index + 2} of ${batch.items.length}`;
        }
        // Let the browser paint between work slices when a larger paste needs it.
        if (index + 1 < batch.items.length && performance.now() - sliceStart >= 8) {
          await nextPaint();
          if (current !== run) return;
          sliceStart = performance.now();
        }
      }

      items = checked;
      batchDetails = batch.details;
      points.value = batchDetails.suggestedPoints;
      renderDetails();
      list.innerHTML = items.map((item, index) => renderItem(item, index)).join('');
      $('ProgressTitle').textContent = 'Question checks complete';
      $('ProgressIcon').className = 'bx bx-check';
      $('Current').textContent = `${items.length} ${noun(items.length, 'question')} checked in this browser.`;
      showStep('review');
      updateSelection();
      $('ReviewTitle').focus({ preventScroll:true });
    }

    $('Modal').addEventListener('shown.bs.modal', () => (step === 'review' ? $('ReviewTitle') : source).focus({ preventScroll:true }));
    $('Modal').addEventListener('hide.bs.modal', () => {
      run++;
      if (step === 'checking') showStep('paste');
    });
    $('Modal').addEventListener('hidden.bs.modal', () => {
      if (addedQuestion) addedQuestion.focus();
      else opener?.focus({ preventScroll:true });
      addedQuestion = null;
    });
    $('Process').addEventListener('click', process);
    $('Edit').addEventListener('click', () => { showStep('paste'); showRoom(); source.focus(); });
    source.addEventListener('input', () => showMessage());
    $('NotesBody').addEventListener('change', updateDetailsLabel);
    points.addEventListener('input', () => { showMessage(); updateSelection(); });
    list.addEventListener('change', event => {
      if (!event.target.matches('[data-bulk-include]')) return;
      const index = Number(event.target.closest('[data-bulk-row]').dataset.bulkRow);
      items[index].included = event.target.checked;
      showMessage();
      updateSelection();
    });
    list.addEventListener('click', event => {
      if (!event.target.closest('[data-bulk-recheck]')) return;
      const row = event.target.closest('[data-bulk-row]');
      const index = Number(row.dataset.bulkRow);
      const item = items[index];
      item.source = row.querySelector('[data-bulk-repair]').value;
      item.result = checkQuestion(item.source);
      item.included = !item.result.message;
      markCheck(index, item.included ? 'ready' : 'review');
      row.outerHTML = renderItem(item, index, true);
      showMessage();
      updateSelection();
      list.children[index].querySelector(item.included ? 'summary' : '[data-bulk-repair]').focus({ preventScroll:true });
    });
    $('Add').addEventListener('click', () => {
      if (step !== 'review' || $('Add').disabled) return;
      const value = Number(points.value);
      const selected = selectedItems().map(({ result }) => ({
        question_type:'multiple_choice', prompt:result.prompt, options:result.options, answer_key:result.answerKey, points:value
      }));
      const details = Object.fromEntries(Array.from($('NotesBody').querySelectorAll('[data-bulk-field]:checked'), input =>
        [input.dataset.bulkField, batchDetails[input.dataset.bulkField]]));
      const result = addQuestions(selected, details);
      if (result.message) {
        showMessage(result.message);
        $('Body').scrollTop = 0;
        return;
      }
      addedQuestion = result.focus;
      modal.hide();
      clear();
    });
    return {
      clear,
      open(button) {
        opener = button;
        showRoom();
        if (step === 'review') {
          updateSelection();
          renderDetails();
        }
        if (window.Swal?.getPopup()?.classList.contains('swal2-toast')) window.Swal.close();
        modal.show(button);
      }
    };
  }

  window.synkSetupBulkQuestions = setupBulkQuestions;
})();
