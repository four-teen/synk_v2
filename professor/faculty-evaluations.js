(function () {
  'use strict';
  var page = document.getElementById('professorEvaluationResults');
  if (!page) return;
  page.querySelectorAll('[data-evaluation-subject]').forEach(function (card) {
    var panel = card.querySelector('.evaluation-card-panel');
    var content = card.querySelector('.evaluation-panel-content');
    var status = card.querySelector('.evaluation-panel-status');
    var retry = card.querySelector('.evaluation-retry');
    var exportButton = card.querySelector('.evaluation-export');
    var buttons = Array.from(card.querySelectorAll('[data-evaluation-view]'));
    var view = '', sequence = 0, controller, exportController, reloadRequired = false;
    function urlFor(requestView) {
      var url = new URL(card.dataset.detailsUrl, document.baseURI);
      ['offeringId', 'collegeId', 'ayId', 'semester'].forEach(function (key) {
        var parameter = {offeringId:'offering_id',collegeId:'college_id',ayId:'ay_id',semester:'semester'}[key];
        url.searchParams.set(parameter, card.dataset[key]);
      });
      url.searchParams.set('view', requestView);
      return url;
    }
    function cancel() {
      sequence++;
      if (controller) controller.abort();
      if (exportController) exportController.abort();
      exportButton.disabled = false;
    }
    function setExpanded() {
      buttons.forEach(function (button) { button.setAttribute('aria-expanded', String(button.dataset.evaluationView === view && !panel.hidden)); });
      card.classList.toggle('is-expanded', !panel.hidden);
    }
    function close() {
      var trigger = buttons.find(function (button) { return button.dataset.evaluationView === view; });
      cancel(); panel.hidden = true; content.replaceChildren(); content.hidden = true;
      view = ''; setExpanded(); if (trigger) trigger.focus();
    }
    function showError(message, requiresReload) {
      content.replaceChildren(); content.hidden = true; exportButton.hidden = true;
      status.textContent = message; status.classList.add('is-error');
      reloadRequired = Boolean(requiresReload);
      retry.textContent = reloadRequired ? 'Reload page' : 'Try again'; retry.hidden = false;
    }
    async function load(nextView) {
      cancel(); view = nextView; var request = sequence;
      panel.hidden = false; panel.setAttribute('aria-busy', 'true');
      content.replaceChildren(); content.hidden = true; retry.hidden = true; exportButton.hidden = true;
      status.classList.remove('is-error'); status.textContent = view === 'pending' ? 'Loading students not yet evaluated…' : 'Loading subject results…';
      setExpanded(); var activeController = new AbortController(); controller = activeController;
      var timer = setTimeout(function () { activeController.abort(); }, 20000);
      try {
        var response = await fetch(urlFor(view), {credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json'}});
        if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Your session may have expired. Reload the page and sign in again.');
        var data = await response.json();
        if (request !== sequence) return;
        if (!response.ok || !data.ok) { showError(data.message || 'Unable to load this view.', [401,403,404,409].includes(response.status)); return; }
        if (data.offering_id !== Number(card.dataset.offeringId) || data.view !== view || typeof data.html !== 'string') throw new Error('The response could not be verified. Please reload and try again.');
        content.innerHTML = data.html; content.hidden = false;
        card.querySelectorAll('[data-count]').forEach(function (element) {
          var count = data.counts?.[element.dataset.count];
          if (Number.isInteger(count) && count >= 0) element.textContent = String(count);
        });
        status.textContent = view === 'pending' ? data.counts.not_evaluated + ' students not yet evaluated' : 'Subject evaluation results';
        exportButton.hidden = view !== 'pending' || data.counts.not_evaluated === 0;
      } catch (error) {
        if (request === sequence) showError(error.name === 'AbortError' ? 'This request took too long. Please try again.' : error.message, false);
      } finally { clearTimeout(timer); if (request === sequence) panel.removeAttribute('aria-busy'); }
    }
    buttons.forEach(function (button) {
      button.addEventListener('click', function () { if (!panel.hidden && view === button.dataset.evaluationView) close(); else load(button.dataset.evaluationView); });
    });
    card.querySelector('.evaluation-close').addEventListener('click', close);
    retry.addEventListener('click', function () { if (reloadRequired) window.location.reload(); else load(view); });
    exportButton.addEventListener('click', async function () {
      if (exportButton.disabled) return;
      var request = sequence; exportButton.disabled = true; var activeExportController = new AbortController(); exportController = activeExportController;
      var timer = setTimeout(function () { activeExportController.abort(); }, 20000);
      status.textContent = 'Preparing the latest student list…';
      try {
        var response = await fetch(urlFor('export'), {credentials:'same-origin',cache:'no-store',signal:exportController.signal});
        if (request !== sequence) return;
        if (!response.ok) {
          var error = await response.json();
          if (request !== sequence) return;
          showError(error.message || 'The export could not be prepared.', [401,403,404,409].includes(response.status)); return;
        }
        if (!response.headers.get('Content-Type')?.includes('text/csv')) throw new Error('Your session may have expired. Reload the page and sign in again.');
        var csvText = await response.text(); if (request !== sequence) return;
        if (!csvText.replace(/^\uFEFF/, '').trim() || !csvText.includes('"Student number"') || !csvText.includes('"Student name"')) throw new Error('The server returned an empty or invalid student list. Please reload the list and try again.');
        var disposition = response.headers.get('Content-Disposition') || '';
        var encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        var plainName = disposition.match(/filename="([^"]+)"/i);
        var filename = encodedName ? decodeURIComponent(encodedName[1]) : (plainName ? plainName[1] : 'Not evaluated.csv');
        var blob = new Blob(['\uFEFF', csvText.replace(/^\uFEFF/, '')], {type:'text/csv;charset=utf-8'});
        var objectUrl = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = objectUrl; link.download = filename;
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
        status.textContent = 'Student list exported.';
      } catch (error) {
        if (request === sequence) status.textContent = error.name === 'AbortError' ? 'The export took too long. Please try again.' : error.message;
      } finally { clearTimeout(timer); if (request === sequence) exportButton.disabled = false; }
    });
  });
  var search = document.getElementById('evaluation-search');
  if (!search) return;
  var cards = Array.from(page.querySelectorAll('[data-evaluation-subject]'));
  var count = document.getElementById('evaluation-result-count');
  var originalCount = count.textContent;
  function normalize(value) { return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
  function filter() {
    var term = normalize(search.value), shown = 0;
    cards.forEach(function (card) { card.hidden = !normalize(card.dataset.search).includes(term); if (!card.hidden) shown++; });
    count.textContent = term ? shown + ' of ' + cards.length + ' assigned classes shown' : originalCount;
    document.getElementById('evaluation-no-matches').hidden = shown !== 0;
  }
  document.getElementById('evaluation-search-ui').hidden = false;
  search.addEventListener('input', filter);
  document.getElementById('evaluation-clear-search').addEventListener('click', function () { search.value = ''; filter(); search.focus(); });
}());
