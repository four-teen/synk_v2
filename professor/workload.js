(function () {
  'use strict';
  const term = document.getElementById('workload-term');
  term?.addEventListener('change', function () {
    document.getElementById('workload-term-form').requestSubmit();
  });
  const input = document.getElementById('workload-search');
  if (!input) return;
  const entries = Array.from(document.querySelectorAll('[data-workload-class]'));
  const groups = Array.from(document.querySelectorAll('[data-semester-group]'));
  const count = document.getElementById('workload-result-count');
  const empty = document.getElementById('workload-no-matches');
  const normalize = value => value.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const classes = new Map();
  entries.forEach(function (entry) {
    const key = entry.dataset.workloadKey;
    if (!classes.has(key)) classes.set(key, {search: normalize(entry.dataset.search), views: []});
    classes.get(key).views.push(entry);
  });
  function filterClasses() {
    const words = normalize(input.value).trim().split(/\s+/).filter(Boolean);
    let shown = 0;
    classes.forEach(function (item) {
      const matches = words.every(word => item.search.includes(word));
      item.views.forEach(entry => { entry.hidden = !matches; });
      if (matches) shown++;
    });
    groups.forEach(function (group) {
      group.hidden = !Array.from(group.querySelectorAll('[data-workload-class]')).some(card => !card.hidden);
    });
    count.textContent = (words.length ? shown + ' of ' + classes.size : shown) + (classes.size === 1 ? ' class shown' : ' classes shown');
    empty.hidden = shown !== 0;
  }
  document.getElementById('workload-search-ui').hidden = false;
  input.addEventListener('input', filterClasses);
  document.getElementById('workload-clear-search').addEventListener('click', function () {
    input.value = '';
    filterClasses();
    input.focus();
  });
  window.addEventListener('pageshow', filterClasses);
  filterClasses();
})();
