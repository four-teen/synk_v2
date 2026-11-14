(function () {
  'use strict';
  const root = document.querySelector('[data-researches]');
  const form = document.getElementById('faculty-research-form');
  if (!root || !form) return;
  const editor = document.getElementById('research-editor');
  const title = document.getElementById('research-editor-title');
  const status = document.getElementById('research-save-status');
  const save = document.getElementById('research-save-button');
  let dirty = form.dataset.hasErrors === '1', submitting = false;
  function clearErrors() {
    root.querySelectorAll('#research-errors').forEach(node => node.remove());
    form.querySelectorAll('.is-invalid').forEach(field => {
      field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid');
      const descriptions = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && !id.endsWith('-error'));
      if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
      else field.removeAttribute('aria-describedby');
    });
    form.querySelectorAll('.invalid-feedback').forEach(node => node.remove());
  }
  function canDiscard() {return !dirty || window.confirm('Discard the unsaved changes to this research record?');}
  function openRecord(record) {
    clearErrors();
    for (const field of form.elements) {
      if (field.name === 'research_revision') field.value = record.revision || '0';
      else if (field.name === 'research_id') field.value = record.research_id || '0';
      else if (field.id.startsWith('research_')) field.value = record[field.name] || '';
    }
    dirty = false;
    title.textContent = Number(record.research_id) > 0 ? 'Edit research record' : 'Add research record';
    status.textContent = 'Each research record is saved separately.';
    editor.hidden = false;
    editor.scrollIntoView({block:'start', behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
    document.getElementById('research_research_title').focus({preventScroll:true});
  }
  root.querySelector('[data-research-add]').addEventListener('click', event => {
    event.preventDefault(); if (canDiscard()) openRecord({});
  });
  root.querySelectorAll('[data-research-edit]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault(); if (canDiscard()) openRecord(JSON.parse(link.dataset.researchRecord));
  }));
  root.querySelector('[data-research-cancel]').addEventListener('click', event => {
    event.preventDefault();
    if (!canDiscard()) return;
    dirty = false; editor.hidden = true; clearErrors(); root.querySelector('[data-research-add]').focus();
  });
  function changed(event) {
    dirty = true; status.textContent = 'Unsaved research changes';
    event.target.classList.remove('is-invalid'); event.target.removeAttribute('aria-invalid');
  }
  form.addEventListener('input', changed); form.addEventListener('change', changed);
  form.addEventListener('submit', () => {submitting = true; save.disabled = true; status.textContent = 'Saving research…';});
  root.querySelectorAll('[data-research-delete]').forEach(remove => remove.addEventListener('submit', event => {
    if (!canDiscard() || !window.confirm('Remove “' + remove.dataset.researchTitle + '” from your research records?')) {event.preventDefault(); return;}
    submitting = true; remove.querySelector('button').disabled = true;
  }));
  window.addEventListener('beforeunload', event => {if (dirty && !submitting) {event.preventDefault(); event.returnValue = '';}});
  window.addEventListener('pageshow', () => {submitting = false; save.disabled = false; root.querySelectorAll('[data-research-delete] button').forEach(button => {button.disabled = false;});});
  if (form.dataset.hasErrors === '1') document.getElementById('research-errors')?.focus();
})();
