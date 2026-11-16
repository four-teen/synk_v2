(function () {
  'use strict';
  const root = document.querySelector('[data-trainings]');
  const form = document.getElementById('faculty-training-form');
  if (!root || !form) return;
  const editor = document.getElementById('training-editor');
  const title = document.getElementById('training-editor-title');
  const status = document.getElementById('training-save-status');
  const save = document.getElementById('training-save-button');
  const dateFrom = document.getElementById('training_date_from');
  const dateTo = document.getElementById('training_date_to');
  function updateDates() {dateTo.min = dateFrom.value || '1900-01-01';}
  dateFrom.addEventListener('input', updateDates);
  dateFrom.addEventListener('change', updateDates);
  updateDates();
  let dirty = form.dataset.hasErrors === '1', submitting = false;
  function clearErrors() {
    root.querySelectorAll('#training-errors').forEach(node => node.remove());
    form.querySelectorAll('.is-invalid').forEach(field => {
      field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid');
      const descriptions = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && !id.endsWith('-error'));
      if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
      else field.removeAttribute('aria-describedby');
    });
    form.querySelectorAll('.invalid-feedback').forEach(node => node.remove());
  }
  function canDiscard() {return !dirty || window.confirm('Discard the unsaved changes to this training record?');}
  function openRecord(record) {
    clearErrors();
    for (const field of form.elements) {
      if (field.name === 'training_revision') field.value = record.revision || '0';
      else if (field.name === 'training_id') field.value = record.training_id || '0';
      else if (field.id.startsWith('training_')) field.value = record[field.name] || '';
    }
    updateDates();
    dirty = false;
    title.textContent = Number(record.training_id) > 0 ? 'Edit training record' : 'Add training record';
    status.textContent = 'Each training record is saved separately.';
    editor.hidden = false;
    editor.scrollIntoView({block:'start', behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
    document.getElementById('training_training_title').focus({preventScroll:true});
  }
  root.querySelector('[data-training-add]').addEventListener('click', event => {
    event.preventDefault(); if (canDiscard()) openRecord({});
  });
  root.querySelectorAll('[data-training-edit]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault(); if (canDiscard()) openRecord(JSON.parse(link.dataset.trainingRecord));
  }));
  root.querySelector('[data-training-cancel]').addEventListener('click', event => {
    event.preventDefault();
    if (!canDiscard()) return;
    dirty = false; editor.hidden = true; clearErrors(); root.querySelector('[data-training-add]').focus();
  });
  function changed(event) {
    dirty = true; status.textContent = 'Unsaved training changes';
    event.target.classList.remove('is-invalid'); event.target.removeAttribute('aria-invalid');
  }
  form.addEventListener('input', changed); form.addEventListener('change', changed);
  form.addEventListener('submit', () => {submitting = true; save.disabled = true; status.textContent = 'Saving training…';});
  root.querySelectorAll('[data-training-delete]').forEach(remove => remove.addEventListener('submit', event => {
    if (!canDiscard() || !window.confirm('Remove “' + remove.dataset.trainingTitle + '” from your training records?')) {event.preventDefault(); return;}
    submitting = true; remove.querySelector('button').disabled = true;
  }));
  window.addEventListener('beforeunload', event => {if (dirty && !submitting) {event.preventDefault(); event.returnValue = '';}});
  window.addEventListener('pageshow', () => {submitting = false; save.disabled = false; root.querySelectorAll('[data-training-delete] button').forEach(button => {button.disabled = false;});});
  if (form.dataset.hasErrors === '1') document.getElementById('training-errors')?.focus();
})();
