/* Oracle credentials and the account lookup stay on the server. */
(() => {
  'use strict';
  const root = document.getElementById('dashboardResearch');
  if (!root) return;
  const byId = id => document.getElementById(id);
  const sections = ['Loading', 'Content', 'Empty', 'Error'];
  const total = document.querySelector('[data-research-total]');
  const published = document.querySelector('[data-research-published]');
  const status = byId('dashboardResearchStatus');
  let loading = false;
  const node = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const icon = name => {
    const element = node('i', `bx ${name}`); element.setAttribute('aria-hidden', 'true'); return element;
  };
  function show(section) {
    sections.forEach(name => { byId(`dashboardResearch${name}`).hidden = name !== section; });
    root.setAttribute('aria-busy', String(section === 'Loading'));
    root.dataset.state = section.toLowerCase();
  }
  function count(element, value, label) {
    element.textContent = typeof value === 'number' ? value.toLocaleString() : value;
    element.setAttribute('aria-label', label);
  }
  function render(data) {
    const { summary } = data;
    count(total, summary.total, `${summary.total} related researches`);
    count(published, summary.published, `${summary.published} published researches`);
    document.querySelector('[data-research-total-note]').textContent = 'Linked to your Oracle account';
    document.querySelector('[data-research-published-note]').textContent = 'Within your research portfolio';
    const roles = byId('dashboardResearchRoles'); roles.replaceChildren();
    summary.roles.forEach(role => {
      const pill = node('span', 'dashboard-role-pill');
      pill.append(node('span', '', role.label), node('strong', '', role.count.toLocaleString())); roles.append(pill);
    });
    const list = byId('dashboardResearchList'); list.replaceChildren();
    summary.recent.forEach(record => {
      const article = node('article', 'dashboard-research-record'); article.dataset.researchId = record.id;
      const illustration = node('span', 'dashboard-record-icon'); illustration.append(icon('bx-book-open'));
      const content = node('div', 'dashboard-record-content');
      const recordId = `ORACLE-R-${String(record.id).padStart(5, '0')}`;
      const title = node('h3'); const link = node('a', '', record.title || 'Untitled research');
      link.href = `oracle-research.php?id=${record.id}`; title.append(link);
      content.append(node('span', 'dashboard-record-id', recordId), title, node('p', 'dashboard-record-authors', record.authors || 'Authors not specified'));
      const meta = node('div', 'dashboard-record-meta');
      meta.append(node('span', 'dashboard-record-role', record.roles.join(' · ')));
      if (record.status) meta.append(node('span', '', record.status));
      meta.append(node('span', `dashboard-record-status${record.is_published ? ' is-published' : ''}`, record.is_published ? 'Published' : 'Internal'));
      content.append(meta);
      const open = node('a', 'dashboard-record-open'); open.href = link.href;
      open.setAttribute('aria-label', `View research: ${record.title || recordId}`); open.append(icon('bx-chevron-right'));
      article.append(illustration, content, open); list.append(article);
    });
    byId('dashboardResearchAccount').textContent = `Matched to ${data.email}`;
    byId('dashboardResearchContent').querySelector('.dashboard-recent-heading > span').textContent = `${summary.recent.length} of ${summary.total.toLocaleString()} records`;
    show(summary.total ? 'Content' : 'Empty');
    if (summary.total) window.SynkProfessorCharts?.research(summary.trend);
    status.textContent = `${summary.total} researches loaded from Oracle for your account.`;
  }
  async function load() {
    if (loading) return;
    loading = true;
    window.SynkProfessorCharts?.resetResearch();
    show('Loading'); status.textContent = 'Loading your Oracle research.';
    // A failed refresh must not leave old figures looking current.
    count(total, '—', 'Research count is loading'); count(published, '—', 'Published count is loading');
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 70000);
    try {
      const response = await fetch('dashboard-research.php', { credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { Accept: 'application/json' } });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Research is temporarily unavailable. Please refresh or sign in again.');
      const data = await response.json();
      if (!response.ok || data.ok !== true) throw new Error(data.message || 'Research could not be loaded. Please try again.');
      if (!data.summary || !Number.isInteger(data.summary.total) || !Number.isInteger(data.summary.published)
        || !Array.isArray(data.summary.roles) || !Array.isArray(data.summary.recent)
        || data.summary.recent.some(record => !Number.isInteger(record.id) || record.id < 1 || !Array.isArray(record.roles))) {
        throw new Error('The research summary could not be read. Please try again.');
      }
      render(data);
    } catch (error) {
      byId('dashboardResearchErrorMessage').textContent = error.name === 'AbortError' ? 'Oracle is taking longer than expected. Please try again in a moment.' : error.message;
      count(total, '—', 'Research count is unavailable'); count(published, '—', 'Published count is unavailable');
      document.querySelector('[data-research-total-note]').textContent = 'Temporarily unavailable';
      document.querySelector('[data-research-published-note]').textContent = 'Temporarily unavailable';
      show('Error'); status.textContent = 'Research could not be loaded. Your teaching dashboard is still available.';
    } finally {
      clearTimeout(timer); loading = false;
    }
  }
  byId('dashboardResearchRefresh').addEventListener('click', load);
  byId('dashboardResearchRetry').addEventListener('click', load);
  load();
})();
