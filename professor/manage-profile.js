(function () {
  'use strict';
  const tabs = Array.from(document.querySelectorAll('[data-profile-tab]'));
  function activateTab(tab, focus = false) {
    tabs.forEach(item => {
      const active = item === tab;
      item.setAttribute('aria-selected', String(active)); item.tabIndex = active ? 0 : -1;
      document.getElementById(item.getAttribute('aria-controls')).hidden = !active;
    });
    if (focus) tab.focus();
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activateTab(tab));
    tab.addEventListener('keydown', event => {
      if (event.altKey || event.ctrlKey || event.metaKey) return;
      let target;
      if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') target = (index + tabs.length - 1) % tabs.length;
      if (event.key === 'Home') target = 0;
      if (event.key === 'End') target = tabs.length - 1;
      if (target !== undefined) { event.preventDefault(); activateTab(tabs[target], true); }
    });
  });
  function revealProfileTarget() {
    const id = location.hash.slice(1), target = id && document.getElementById(id);
    if (target) {
      const panel = target.closest('[role=tabpanel]');
      const tab = tabs.find(item => item.getAttribute('aria-controls') === panel?.id);
      if (tab) activateTab(tab);
    }
  }
  window.addEventListener('hashchange', revealProfileTarget); revealProfileTarget();

  const form = document.getElementById('faculty-profile-form'), template = document.getElementById('additional-degree-template');
  const addButtons = Array.from(document.querySelectorAll('[data-add-degree]'));
  if (!addButtons.length || !form || !template) return;
  let nextIndex = Array.from(form.querySelectorAll('[data-degree-index]')).reduce((max, row) => Math.max(max, Number(row.dataset.degreeIndex) + 1), 0);
  const rows = () => Array.from(form.querySelectorAll('[data-additional-degree]'));
  function updateRows() {
    addButtons.forEach(add => {
      add.disabled = rows().length >= 50;
      const list = form.querySelector('[data-degree-list="' + add.dataset.addDegree + '"]');
      list.querySelectorAll('[data-additional-degree]').forEach((row, index) => {
        const title = add.dataset.degreeLabel + ' ' + (index + 2);
        row.querySelector('.profile-additional-degree-title').textContent = title;
        row.querySelector('[data-remove-degree]').setAttribute('aria-label', 'Remove ' + title);
      });
    });
  }
  const markChanged = () => form.dispatchEvent(new Event('change', {bubbles:true}));
  addButtons.forEach(add => add.addEventListener('click', () => {
    if (rows().length >= 50) return;
    const level = add.dataset.addDegree, list = form.querySelector('[data-degree-list="' + level + '"]');
    const content = document.createElement('template');
    content.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++))
      .replaceAll('__NUMBER__', String(list.querySelectorAll('[data-additional-degree]').length + 2))
      .replaceAll('__LEVEL__', level).replaceAll('__LABEL__', add.dataset.degreeLabel);
    const row = content.content.firstElementChild; list.append(row);
    form.dispatchEvent(new CustomEvent('faculty-profile:degree-added', {detail:{row}}));
    updateRows(); markChanged(); row.scrollIntoView({block:'start', behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    row.querySelector('select').focus({preventScroll:true});
  }));
  form.addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-degree]');
    if (!remove) return;
    const row = remove.closest('[data-additional-degree]');
    const level = row.closest('[data-degree-list]').dataset.degreeList;
    if (window.jQuery?.fn?.select2) window.jQuery(row).find('.select2-hidden-accessible').each(function () { window.jQuery(this).select2('destroy'); });
    row.remove(); updateRows(); markChanged();
    addButtons.find(add => add.dataset.addDegree === level).focus({preventScroll:true});
  });
  updateRows();
})();
