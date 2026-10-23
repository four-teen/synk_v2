(function () {
  'use strict';
  const configElement = document.getElementById('schedulerWorkingTermConfig');
  if (!configElement) return;
  const config = JSON.parse(configElement.textContent);
  const modal = document.getElementById('schedulerTermSettingsModal');
  document.body.appendChild(modal);
  const form = document.getElementById('scheduler-working-term-form');
  modal.addEventListener('show.bs.modal', function () { form.reset(); });
  modal.addEventListener('shown.bs.modal', function () { document.getElementById('scheduler-working-year').focus(); });

  // Each tab sends its own term and college. Changing another tab's session cannot redirect a save.
  if (window.jQuery) {
    window.jQuery.ajaxPrefilter(function (options) {
      const url = new URL(options.url, window.location.href);
      if (url.origin !== location.origin || !url.pathname.startsWith(config.backendPath)) return;
      if (options.data instanceof FormData) {
        options.data.set('scheduler_term_key', config.term.key);
        options.data.set('scheduler_college_id', config.collegeId);
        if (!options.data.has('csrf_token')) options.data.set('csrf_token', config.csrfToken);
      } else {
        const params = new URLSearchParams(typeof options.data === 'string' ? options.data : options.data || {});
        params.set('scheduler_term_key', config.term.key);
        params.set('scheduler_college_id', config.collegeId);
        if (options.type.toUpperCase() !== 'GET' && !params.has('csrf_token')) params.set('csrf_token', config.csrfToken);
        options.data = params.toString();
      }
    });
  }

  document.querySelectorAll('form[method="post" i]').forEach(function (postForm) {
    if (new URL(postForm.action, location.href).origin !== location.origin) return;
    for (const [name,value] of Object.entries({scheduler_term_key:config.term.key,scheduler_college_id:config.collegeId,csrf_token:config.csrfToken})) {
      if (postForm.elements.namedItem(name)) continue;
      const input=document.createElement('input'); input.type='hidden'; input.name=name; input.value=value; postForm.appendChild(input);
    }
  });
  const subjectTerm=document.getElementById('saTerm');
  if (subjectTerm) subjectTerm.addEventListener('change',function (event) {
    const match=/^([1-9][0-9]*):([1-3])$/.exec(subjectTerm.value);
    if (!match || subjectTerm.value===config.term.key) return;
    event.stopImmediatePropagation();
    const url=new URL(location.pathname,location.origin); url.searchParams.set('scheduler_ay_id',match[1]); url.searchParams.set('scheduler_semester',match[2]); location.assign(url.href);
  },true);
  const termControls = [
    ['ay_id', 'semester'], ['fw_ay', 'fw_semester'], ['cp_ay', 'cp_semester'],
    ['simulationAy', 'simulationSemester'], ['ru_ay', 'ru_semester'], ['iso_ay', 'iso_semester']
  ];
  termControls.forEach(function (ids) {
    const year = document.getElementById(ids[0]); const semester = document.getElementById(ids[1]);
    if (!year || !semester || year.tagName !== 'SELECT' || semester.tagName !== 'SELECT') return;
    function changeTerm(event) {
      const option = year.options[year.selectedIndex];
      const ayId = option && (option.dataset.ayId || config.years.find(item => item.ay === year.value)?.ay_id || (/^\d+$/.test(year.value) ? year.value : ''));
      const sem = ({'1st': '1', '2nd': '2', 'Midyear': '3'})[semester.value] || semester.value;
      if (!ayId || !/^[1-3]$/.test(String(sem)) || `${ayId}:${sem}` === config.term.key) return;
      if (event) event.stopImmediatePropagation();
      const url = new URL(location.pathname, location.origin);
      url.searchParams.set('scheduler_ay_id', ayId); url.searchParams.set('scheduler_semester', sem);
      location.assign(url.href);
    }
    // A user selection changes the whole workspace before page-specific loaders run.
    [year, semester].forEach(select => select.addEventListener('change', changeTerm, true));
    if (window.jQuery) window.jQuery(year).add(semester).on('change.schedulerWorkingTerm', changeTerm);
    else [year, semester].forEach(select => select.addEventListener('change', changeTerm));
  });
  // Keep ordinary navigation and print links in this tab's working term.
  document.addEventListener('click', function (event) {
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download') || /^\s*(#|javascript:)/i.test(link.getAttribute('href'))) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || !url.pathname.startsWith(config.schedulerPath) || !url.pathname.endsWith('.php')) return;
    if (!url.searchParams.has('ay_id') && !url.searchParams.has('scheduler_ay_id')) {
      url.searchParams.set('scheduler_ay_id', config.term.ay_id); url.searchParams.set('scheduler_semester', config.term.semester);
      link.href = url.href;
    }
  });
  if (!config.term.can_write) {
    function disableWriteControls(root) {
      const buttons = root.matches?.('button, input[type="submit"]') ? [root] : root.querySelectorAll('button, input[type="submit"]');
      buttons.forEach(function (button) {
        if (button.closest('#schedulerTermSettingsModal')) return;
        const label = button.textContent.trim() || button.value || '';
        if (/^(save|update|delete|remove|clear|apply (?:to workload|draft)|generate|copy previous|add |create |load.*live)/i.test(label)) {
          if (!button.disabled) button.disabled = true;
          button.title = 'This term is for viewing only.';
        }
      });
    }
    disableWriteControls(document);
    new MutationObserver(function (changes) {
      changes.forEach(function (change) { if (change.target.nodeType === 1) disableWriteControls(change.target); });
    }).observe(document.body, {subtree:true,childList:true,attributes:true,attributeFilter:['disabled']});
  }
})();
