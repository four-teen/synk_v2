(function () {
  'use strict';
  const root = document.getElementById('professorStudentRoster');
  const config = JSON.parse(root.querySelector('#student-roster-config').textContent);
  const rows = Array.from(root.querySelectorAll('[data-roster-row]'));
  const placeholder = '../student/profile-placeholder.svg';
  root.querySelectorAll('.student-roster-avatar img').forEach(image => {
    const usePlaceholder = () => { image.src = placeholder; };
    if (image.complete && image.naturalWidth === 0) usePlaceholder();
    else image.addEventListener('error', usePlaceholder, {once: true});
  });
  const photoDialog = root.querySelector('#student-roster-photo-modal');
  const photoModal = new bootstrap.Modal(photoDialog);
  const largePhoto = photoDialog.querySelector('#student-roster-photo-image');
  let photoTrigger = null;
  largePhoto.addEventListener('error', () => {
    if (largePhoto.getAttribute('src') !== placeholder) largePhoto.src = placeholder;
  });
  root.addEventListener('click', event => {
    const button = event.target.closest('[data-roster-photo]');
    if (!button) return;
    const row = button.closest('[data-roster-row]');
    for (const field of ['name', 'number', 'email', 'classes']) {
      photoDialog.querySelector('#student-roster-photo-' + field).textContent =
        row.querySelector('[data-roster-' + field + ']').textContent;
    }
    largePhoto.src = button.querySelector('img').src;
    photoTrigger = button;
    photoModal.show(button);
  });
  photoDialog.addEventListener('hidden.bs.modal', () => {
    largePhoto.removeAttribute('src');
    photoTrigger?.focus({preventScroll: true});
    photoTrigger = null;
  });
  const search = root.querySelector('#student-roster-query');
  const count = root.querySelector('#student-roster-count');
  const pagination = root.querySelector('#student-roster-pages');
  const previous = root.querySelector('#student-roster-previous');
  const next = root.querySelector('#student-roster-next');
  const pageLabel = root.querySelector('#student-roster-page');
  const empty = root.querySelector('#student-roster-empty');
  const pageSize = 25;
  let page = 1;
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

  function render() {
    const words = normalize(search.value.trim()).split(/\s+/).filter(Boolean);
    const matching = rows.filter(row => {
      const text = normalize(Array.from(row.querySelectorAll('[data-roster-name], [data-roster-number], [data-roster-email]'),
        cell => cell.textContent).join(' '));
      return words.every(word => text.includes(word));
    });
    const pages = Math.max(1, Math.ceil(matching.length / pageSize));
    page = Math.min(page, pages);
    const first = (page - 1) * pageSize;
    const visible = new Set(matching.slice(first, first + pageSize));
    rows.forEach(row => { row.hidden = !visible.has(row); });
    count.textContent = words.length ? `${matching.length} of ${rows.length} students` : `${rows.length} ${rows.length === 1 ? 'student' : 'students'}`;
    empty.hidden = matching.length > 0;
    pagination.hidden = pages === 1;
    previous.disabled = page === 1;
    next.disabled = page === pages;
    pageLabel.textContent = `Page ${page} of ${pages}`;
  }
  search.addEventListener('input', () => { page = 1; render(); });
  root.querySelector('#student-roster-clear').addEventListener('click', () => {
    search.value = '';
    page = 1;
    render();
    search.focus();
  });
  previous.addEventListener('click', () => { page--; render(); });
  next.addEventListener('click', () => { page++; render(); });
  root.querySelector('#student-roster-search').hidden = false;

  window.synkStudentEmailEditor(root, {
    csrfToken: config.csrf_token,
    fallbackFocus: search,
    getStudent(button) {
      const row = button.closest('[data-roster-row]');
      return {
        id: Number(button.dataset.csrEmail),
        name: row.querySelector('[data-roster-name]').textContent,
        number: row.querySelector('[data-roster-number]').textContent,
        context: config.contexts[button.dataset.rosterOffering]
      };
    },
    onSaved(student, email) {
      const emailCell = student.button.closest('[data-roster-row]').querySelector('[data-roster-email]');
      emailCell.textContent = email;
      emailCell.classList.remove('student-roster-missing');
      const saved = root.querySelector('#student-roster-saved');
      saved.hidden = false;
      saved.textContent = `School email saved for ${student.name}.`;
      render();
    }
  });
  render();
})();
