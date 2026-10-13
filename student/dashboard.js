'use strict';
(function () {
  const search = document.getElementById('subject-search');
  const cards = Array.from(document.querySelectorAll('[data-subject-card]'));
  const fold = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  if (search) {
    const update = () => {
      const tokens = fold(search.value.trim()).split(/\s+/).filter(Boolean);
      let count = 0;
      cards.forEach(card => { card.hidden = !tokens.every(token => fold(card.dataset.search).includes(token)); if (!card.hidden) count++; });
      document.getElementById('subject-search-empty').hidden = count > 0;
      document.getElementById('subject-search-result').textContent = 'Showing ' + count + ' of ' + cards.length + ' subjects';
    };
    search.addEventListener('input', update);
    document.getElementById('subject-search-clear').addEventListener('click', () => { search.value = ''; update(); search.focus(); });
  }
  const modal = document.getElementById('studentTermModal');
  if (modal) modal.addEventListener('show.bs.modal', () => document.getElementById('student-term-form').reset());
})();
