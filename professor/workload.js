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

(function () {
  'use strict';
  const modal = document.getElementById('workload-students-modal');
  if (!modal) return;
  const title = document.getElementById('workload-students-title');
  const classLabel = document.getElementById('workload-students-class');
  const status = document.getElementById('workload-students-status');
  const content = document.getElementById('workload-students-content');
  const retry = document.getElementById('workload-students-retry');
  const search = document.getElementById('workload-student-search');
  const count = document.getElementById('workload-roster-count');
  const normalize = value => String(value).toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const groups = {};
  ['active', 'inactive'].forEach(key => {
    groups[key] = {list: document.getElementById('workload-roster-' + key),
      count: document.getElementById('workload-roster-' + key + '-count'),
      empty: document.getElementById('workload-roster-' + key + '-empty'), entries: []};
  });
  let selection = null;
  let controller = null;
  let version = 0;

  function filterStudents() {
    const words = normalize(search.value).trim().split(/\s+/).filter(Boolean);
    Object.values(groups).forEach(group => {
      let shown = 0;
      group.entries.forEach(entry => {
        entry.node.hidden = !words.every(word => entry.search.includes(word));
        if (!entry.node.hidden) shown++;
      });
      group.count.textContent = words.length ? shown + ' / ' + group.entries.length : String(group.entries.length);
      group.empty.hidden = shown !== 0;
      group.empty.textContent = words.length ? 'No students match your search in this group.' : 'No students in this group.';
    });
  }

  function renderStudents(data) {
    Object.values(groups).forEach(group => { group.list.replaceChildren(); group.entries = []; });
    data.students.forEach(student => {
      const group = student.active ? groups.active : groups.inactive;
      const item = document.createElement('li');
      item.className = 'workload-student';
      const photo = document.createElement('img');
      photo.alt = '';
      photo.width = photo.height = 48;
      photo.loading = 'lazy';
      photo.addEventListener('error', () => { photo.src = '../student/profile-placeholder.svg'; }, {once: true});
      photo.src = student.photo_url;
      const details = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = student.full_name;
      const number = document.createElement('small');
      number.textContent = student.student_number || 'Student number not recorded';
      details.append(name, number);
      item.append(photo, details);
      group.list.append(item);
      group.entries.push({node: item, search: normalize(student.full_name + ' ' + student.student_number)});
    });
    count.textContent = data.counts.students + ' listed students · ' + data.class.enrolled + ' recorded enrollments';
    content.hidden = false;
    status.textContent = data.students.length ? '' : 'No students are listed for this class yet.';
    filterStudents();
  }

  async function loadStudents() {
    if (!selection) return;
    if (controller) controller.abort();
    controller = new AbortController();
    const request = controller;
    const currentVersion = ++version;
    content.hidden = true;
    retry.hidden = true;
    search.value = '';
    status.classList.remove('is-error');
    status.setAttribute('role', 'status');
    status.textContent = 'Loading class students…';
    modal.setAttribute('aria-busy', 'true');
    const url = new URL(modal.dataset.rosterUrl, window.location.href);
    url.search = new URLSearchParams(selection).toString();
    const timeout = setTimeout(() => request.abort(), 30000);
    try {
      const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: request.signal, headers: {'Accept': 'application/json'}});
      const data = await response.json();
      if (currentVersion !== version) return;
      if (!response.ok || !data.ok) throw new Error(data.message || 'The student list could not be loaded.');
      renderStudents(data);
    } catch (error) {
      if (currentVersion !== version) return;
      status.textContent = error.name === 'AbortError' ? 'The request took too long. Please try again.' : (error instanceof SyntaxError ? 'The student list could not be loaded. Reload the page and sign in again if needed.' : error.message);
      status.classList.add('is-error');
      status.setAttribute('role', 'alert');
      retry.hidden = false;
    } finally {
      clearTimeout(timeout);
      if (currentVersion === version) modal.removeAttribute('aria-busy');
    }
  }
  modal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    if (!button) return;
    title.replaceChildren();
    (button.dataset.classTitle || 'Class students').split('/').forEach(function (part, index) {
      if (index) title.append(document.createTextNode('/'), document.createElement('wbr'));
      title.append(document.createTextNode(part));
    });
    classLabel.textContent = button.dataset.classLabel || '';
    selection = {offering_id: button.dataset.offeringId, ay_id: button.dataset.ayId, semester: button.dataset.semester};
    loadStudents();
  });
  modal.addEventListener('hide.bs.modal', function () {
    version++;
    if (controller) controller.abort();
    modal.removeAttribute('aria-busy');
  });
  modal.addEventListener('hidden.bs.modal', function () {
    Object.values(groups).forEach(group => { group.list.replaceChildren(); group.entries = []; });
    content.hidden = true;
  });
  retry.addEventListener('click', loadStudents);
  search.addEventListener('input', filterStudents);
})();
