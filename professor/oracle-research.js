/* Search and scrolling operate only on the portfolio authorized by PHP/Oracle.
 * There is no browser API call or client-supplied email/account identifier.
 */
(() => {
  'use strict';
  const root = document.getElementById('researchCollection');
  if (!root) return;
  const index = document.getElementById('researchIndex');
  let source;
  try { source = JSON.parse(index.textContent); } catch (_) { return; }
  if (!Array.isArray(source)) return;

  const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const records = source.map(record => ({ ...record, text: normalize(record.search), template: document.getElementById(`research-template-${record.id}`) }));
  if (records.some(record => !record.template || !Array.isArray(record.roles))) return;

  const controls = document.getElementById('researchCollectionControls');
  const form = document.getElementById('researchSearchForm');
  const search = document.getElementById('researchSearch');
  const clear = document.getElementById('researchSearchClear');
  const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
  const panel = document.getElementById('researchResults');
  const heading = document.getElementById('researchResultsTitle');
  const list = document.getElementById('researchList');
  const counter = document.getElementById('researchResultsCount');
  const empty = document.getElementById('researchFilterEmpty');
  const end = document.getElementById('researchScrollEnd');
  const status = document.getElementById('researchScrollStatus');
  const more = document.getElementById('researchLoadMore');
  const sentinel = document.getElementById('researchScrollSentinel');
  const batchSize = Number(root.dataset.batchSize) || 12;
  let selectedRole = '', matches = [], shown = 0, timer;

  const observer = typeof window.IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
    // A callback queued before a tab/search change may describe the old list.
    // Confirm the sentinel's current position before appending the next batch.
    const bounds = sentinel.getBoundingClientRect();
    if (entries.some(entry => entry.isIntersecting) && bounds.top < window.innerHeight + 360 && bounds.bottom >= 0) appendBatch();
  }, { rootMargin: '0px 0px 360px 0px' }) : null;

  function updateStatus() {
    const active = tabs.find(tab => tab.dataset.role === selectedRole);
    const label = active?.dataset.label || 'All researches';
    heading.textContent = label;
    panel.setAttribute('aria-labelledby', active.id);
    counter.textContent = matches.length
      ? `Showing ${shown.toLocaleString()} of ${matches.length.toLocaleString()} ${matches.length === 1 ? 'research' : 'researches'}${selectedRole ? ' in ' + label : ''}.`
      : 'No research matches your search and selected role.';
    empty.hidden = matches.length > 0;
    end.hidden = matches.length === 0;
    more.hidden = shown >= matches.length;
    status.textContent = shown < matches.length
      ? 'Scroll to see more of your matching researches.'
      : `All ${matches.length.toLocaleString()} matching ${matches.length === 1 ? 'research is' : 'researches are'} shown.`;
    if (observer) {
      observer.disconnect();
      if (shown < matches.length) observer.observe(sentinel);
    }
  }

  function appendBatch(focusNew = false) {
    if (shown >= matches.length) return;
    list.setAttribute('aria-busy', 'true');
    const fragment = document.createDocumentFragment();
    const previous = shown;
    const stop = Math.min(shown + batchSize, matches.length);
    for (; shown < stop; shown++) fragment.appendChild(matches[shown].template.content.cloneNode(true));
    list.appendChild(fragment);
    list.setAttribute('aria-busy', 'false');
    updateStatus();
    if (focusNew) list.children[previous]?.querySelector('.research-open')?.focus({ preventScroll: true });
  }

  function applyFilters() {
    clearTimeout(timer);
    observer?.disconnect();
    const restoreControls = controls.getBoundingClientRect().bottom < 0;
    const tokens = normalize(search.value.trim()).split(/\s+/).filter(Boolean);
    const searched = records.filter(record => tokens.every(token => record.text.includes(token)));
    matches = searched.filter(record => !selectedRole || record.roles.includes(selectedRole));
    tabs.forEach(tab => {
      const active = tab.dataset.role === selectedRole;
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      tab.querySelector('[data-role-count]').textContent = searched.filter(record => !tab.dataset.role || record.roles.includes(tab.dataset.role)).length.toLocaleString();
    });
    clear.hidden = search.value === '';
    shown = 0;
    list.replaceChildren();
    if (matches.length) appendBatch();
    else updateStatus();
    if (restoreControls) controls.scrollIntoView({ block: 'start', behavior: 'instant' });
  }

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => { selectedRole = tab.dataset.role; applyFilters(); });
    tab.addEventListener('keydown', event => {
      let target;
      if (event.key === 'ArrowRight') target = tabs[(index + 1) % tabs.length];
      if (event.key === 'ArrowLeft') target = tabs[(index - 1 + tabs.length) % tabs.length];
      if (event.key === 'Home') target = tabs[0];
      if (event.key === 'End') target = tabs[tabs.length - 1];
      if (!target) return;
      event.preventDefault();
      target.click();
      target.focus({ preventScroll: true });
      target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    });
  });
  form.addEventListener('submit', event => { event.preventDefault(); applyFilters(); });
  search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(applyFilters, 150); });
  search.addEventListener('search', applyFilters);
  clear.addEventListener('click', () => { search.value = ''; applyFilters(); search.focus(); });
  document.getElementById('researchResetFilters').addEventListener('click', () => {
    selectedRole = ''; search.value = ''; applyFilters(); search.focus();
  });
  more.addEventListener('click', event => appendBatch(event.detail === 0));
  controls.hidden = false;
  applyFilters();
  root.dataset.ready = 'true';
})();
