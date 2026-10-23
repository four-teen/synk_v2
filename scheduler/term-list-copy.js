(function () {
  'use strict';
  const configElement = document.getElementById('termListCopyConfig');
  if (!configElement) return;
  const config = JSON.parse(configElement.textContent);
  const $ = id => document.getElementById(id);
  const element = $('termListCopyModal');
  document.body.appendChild(element);
  const modal = new bootstrap.Modal(element);
  const state = {terms: [], rows: [], token: '', target: null, generation: 0, busy: false};
  const escape = value => String(value || '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  const selectedIds = () => Array.from(element.querySelectorAll('[data-copy-id]:checked')).map(input => Number(input.dataset.copyId));
  function notice(message = '', kind = 'info') {
    $('termListCopyNotice').hidden = !message;
    $('termListCopyNotice').className = 'alert alert-' + kind;
    $('termListCopyNotice').textContent = message;
  }
  function controls() {
    const available = state.rows.filter(row => row.eligible).length;
    const selected = selectedIds().length;
    $('termListCopySelected').textContent = `${selected} of ${available} available selected`;
    $('termListCopyConfirm').disabled = state.busy || !state.token || !selected;
    $('termListCopyConfirm').textContent = state.busy ? 'Copying…' : `Copy selected${selected ? ' (' + selected + ')' : ''}`;
    $('termListCopyAll').disabled = state.busy || !available;
    $('termListCopyAll').checked = available > 0 && selected === available;
    $('termListCopyAll').indeterminate = selected > 0 && selected < available;
    $('termListCopySource').disabled = state.busy || !state.terms.length;
    ['termListCopyCancel', 'termListCopyClose'].forEach(id => { $(id).disabled = state.busy; });
    element.querySelectorAll('[data-copy-id]').forEach(input => { input.disabled = state.busy || !state.rows.find(row => row.id === Number(input.dataset.copyId))?.eligible; });
  }
  async function request(action, params = {}) {
    const body = new FormData();
    Object.entries({...config, action, ...params}).forEach(([key, value]) => body.append(key, value));
    let response;
    try { response = await fetch('../backend/query_scheduler_term_lists.php', {method: 'POST', body, credentials: 'same-origin', cache: 'no-store'}); }
    catch (_) { throw Error('Unable to connect. Please try again.'); }
    let data;
    try { data = await response.json(); } catch (_) { throw Error('The list could not be loaded. Reload the page and try again.'); }
    if (!response.ok || data.status !== 'ok') {
      const error = Error(data.message || 'The list could not be loaded.');
      error.status = response.status;
      throw error;
    }
    return data;
  }
  async function preview() {
    const generation = ++state.generation;
    state.rows = []; state.token = ''; controls(); notice();
    const source = state.terms.find(term => term.key === $('termListCopySource').value);
    if (!source) return;
    $('termListCopyRows').innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Loading preview…</td></tr>';
    try {
      const data = await request('preview', {source_ay_id: source.ay_id, source_semester: source.semester});
      if (generation !== state.generation) return;
      state.rows = data.rows; state.token = data.preview_token; state.target = data.target;
      $('termListCopyTarget').textContent = data.target.term_text;
      $('termListCopyRows').innerHTML = data.rows.length ? data.rows.map(row => `<tr${row.eligible ? '' : ' class="is-unavailable"'}><td><input type="checkbox" class="form-check-input" data-copy-id="${row.id}" aria-label="${escape('Copy ' + row.label)}"${row.eligible ? ' checked' : ' disabled'}></td><td><strong>${escape(row.label)}</strong><small>${escape(row.detail)}</small></td><td>${row.eligible ? '<span class="text-success">Ready to copy</span>' : escape(row.reason)}</td></tr>`).join('') : '<tr><td colspan="3" class="text-center text-muted py-4">No entries in this source list.</td></tr>';
      if (!data.rows.some(row => row.eligible)) notice('There are no available entries to add from this term.');
    } catch (error) {
      if (generation !== state.generation) return;
      notice(error.message, 'danger');
      $('termListCopyRows').innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Preview unavailable. Choose another term or reopen this dialog.</td></tr>';
    } finally { if (generation === state.generation) controls(); }
  }
  $(config.trigger_id).addEventListener('click', async function () {
    const generation = ++state.generation;
    state.rows = []; state.terms = []; state.token = ''; state.busy = false;
    notice(); controls();
    $('termListCopyTarget').textContent = 'Loading selected term…';
    $('termListCopySource').innerHTML = '<option value="">Loading previous terms…</option>';
    $('termListCopyRows').innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Loading available lists…</td></tr>';
    modal.show();
    try {
      const data = await request('options');
      if (generation !== state.generation) return;
      state.terms = data.terms; state.target = data.target;
      $('termListCopyTarget').textContent = data.target.term_text;
      $('termListCopySource').innerHTML = data.terms.length ? data.terms.map(term => `<option value="${escape(term.key)}">${escape(term.label)}</option>`).join('') : '<option value="">No previous lists available</option>';
      controls();
      if (data.terms.length) await preview();
      else { notice('No previous list is available for this college.'); $('termListCopyRows').innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4">Add entries to the current list to get started.</td></tr>'; }
    } catch (error) { if (generation === state.generation) notice(error.message, 'danger'); }
  });
  $('termListCopySource').addEventListener('change', preview);
  $('termListCopyRows').addEventListener('change', controls);
  $('termListCopyAll').addEventListener('change', function () {
    element.querySelectorAll('[data-copy-id]:not(:disabled)').forEach(input => { input.checked = this.checked; });
    controls();
  });
  element.addEventListener('hide.bs.modal', function (event) {
    if (state.busy) event.preventDefault();
    else { state.generation++; state.token = ''; }
  });
  $('termListCopyConfirm').addEventListener('click', async function () {
    const ids = selectedIds();
    if (state.busy || !state.token || !ids.length) return;
    state.busy = true; controls(); notice();
    try {
      const data = await request('copy', {preview_token: state.token, selected_ids: JSON.stringify(ids)});
      state.busy = false; state.token = ''; controls(); modal.hide();
      if (config.kind === 'faculty') window.refreshFacultyData({preserveScroll: true});
      else {
        window.jQuery('#ay_id').val(String(data.target.ay_id)).trigger('change.select2');
        window.jQuery('#semester').val(String(data.target.semester)).trigger('change.select2');
        window.loadRooms();
      }
      Swal.fire({icon: 'success', title: 'List updated', text: `${data.copied} ${config.kind === 'faculty' ? 'faculty' : 'room'} entries added to ${data.target.term_text}.${data.skipped ? ' ' + data.skipped + ' entries were already listed.' : ''}`});
    } catch (error) { state.busy = false; if ([403,409].includes(error.status)) state.token = ''; notice(error.message, 'danger'); controls(); }
  });
})();
