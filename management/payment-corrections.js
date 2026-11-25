'use strict';

(() => {
  const form = document.getElementById('correction-editor');
  const button = form?.querySelector('[data-correction-delete]');
  if (!button) return;

  const groups = [...form.querySelectorAll('[data-correction-receipt]')].map(receipt => ({
    all: receipt.querySelector('[data-correction-select-all]'),
    items: [...receipt.querySelectorAll('[data-correction-item]')]
  })).filter(group => group.all);
  const count = form.querySelector('[data-correction-count]');
  const total = form.querySelector('[data-correction-selected-total]');
  const pesos = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 });
  let selected = [];

  function syncSelection() {
    selected = [];
    for (const { all, items } of groups) {
      const checked = items.filter(item => item.checked);
      all.checked = checked.length === items.length;
      all.indeterminate = checked.length > 0 && !all.checked;
      for (const item of checked) selected.push(item);
    }
    const cents = selected.reduce((sum, item) => sum + BigInt(item.dataset.cents), 0n);
    count.textContent = `${selected.length} selected`;
    total.textContent = `${pesos.format(cents / 100n)}.${String(cents % 100n).padStart(2, '0')}`;
    button.disabled = !selected.length;
  }

  for (const { all, items } of groups) {
    all.addEventListener('change', () => {
      items.forEach(item => { item.checked = all.checked; });
      syncSelection();
    });
    items.forEach(item => item.addEventListener('change', syncSelection));
  }

  form.addEventListener('submit', event => {
    if (!selected.length) {
      event.preventDefault();
      return;
    }
    form.elements.selection.value = JSON.stringify(selected.map(item => item.value));
    button.disabled = true;
  });
  window.addEventListener('pageshow', syncSelection);
  syncSelection();
})();
