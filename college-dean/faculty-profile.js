(function () {
  'use strict';
  const root = document.getElementById('dean-profile-directory');
  if (!root) return;
  const search = document.getElementById('dean-profile-search');
  const designation = document.getElementById('dean-profile-designation');
  const completion = document.getElementById('dean-profile-completion');
  const status = document.getElementById('dean-profile-status');
  const cards = Array.from(root.querySelectorAll('[data-faculty-card]'));
  const sections = Array.from(root.querySelectorAll('[data-profile-section]'));
  const result = document.getElementById('dean-profile-result');
  const empty = document.getElementById('dean-profiles-empty');
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase();
  function filter() {
    const terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
    let shown = 0;
    cards.forEach(card => {
      const matchesSearch = terms.every(term => normalize(card.dataset.search).includes(term));
      const matchesDesignation = designation.value === 'all' || designation.value === card.dataset.designation;
      const matchesProfile = completion.value === 'all' || completion.value === card.dataset.profile
        || (completion.value === 'needs_attention' && card.dataset.profile !== 'complete');
      const matchesStatus = status.value === 'all' || status.value === card.dataset.status
        || (status.value === 'current' && ['active','on_leave'].includes(card.dataset.status));
      card.hidden = !(matchesSearch && matchesDesignation && matchesProfile && matchesStatus);
      if (!card.hidden) shown++;
    });
    const filtered = terms.length || designation.value !== 'all' || completion.value !== 'all' || status.value !== 'current';
    sections.forEach(section => {
      const count = Array.from(section.querySelectorAll('[data-faculty-card]')).filter(card => !card.hidden).length;
      section.querySelector('[data-profile-count]').textContent = String(count);
      section.hidden = !count && (filtered || section.querySelector('[data-faculty-card]') !== null);
    });
    result.textContent = shown + ' of ' + cards.length + ' faculty profiles shown. Select an available profile to manage its details.';
    empty.hidden = shown > 0 || cards.length === 0;
  }
  function reset() {
    search.value = ''; designation.value = completion.value = 'all'; status.value = 'current'; filter(); search.focus();
  }
  search.addEventListener('input', filter);
  [designation,completion,status].forEach(input => input.addEventListener('change', filter));
  ['dean-profile-reset','dean-profile-clear'].forEach(id => document.getElementById(id).addEventListener('click', reset));
  root.querySelectorAll('img[data-placeholder]').forEach(image => {
    function fallback() { if (image.getAttribute('src') !== image.dataset.placeholder) image.src = image.dataset.placeholder; }
    image.addEventListener('error', fallback);
    if (image.complete && !image.naturalWidth) fallback();
  });
  filter();
})();
