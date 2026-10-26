'use strict';
(() => {
  const $ = id => document.getElementById(id);
  const config = JSON.parse($('organization-config').textContent);
  const form = $('organization-form'), editorElement = $('organization-editor'), deleteElement = $('organization-delete-modal');
  const editor = bootstrap.Modal.getOrCreateInstance(editorElement), deletion = bootstrap.Modal.getOrCreateInstance(deleteElement);
  let busy = false, deleting = false, selected = null, opener = null;
  const notice = (id, message) => { $(id).textContent = message; $(id).hidden = !message; };
  async function request(action, values = {}) {
    const response = await fetch('../backend/query_organizations.php', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new URLSearchParams({ action, csrf_token: config.csrf_token, ...values }) });
    let result; try { result = await response.json(); } catch (_) { throw Error('Unable to read the response. Reload the page.'); }
    if (!response.ok || result.status !== 'ok') throw Error(result.message || 'Unable to complete the request.');
    return result;
  }
  function complete(message) { try { sessionStorage.setItem('synk-organization-notice', message); } catch (_) {} location.reload(); }
  try { const message = sessionStorage.getItem('synk-organization-notice'); if (message) { notice('organization-notice', message); sessionStorage.removeItem('synk-organization-notice'); } } catch (_) {}
  function colleges(selectedCollege = '') {
    const select = $('organization-college'); select.replaceChildren(new Option('Select college', ''));
    config.colleges.filter(row => String(row.campus_id) === $('organization-campus').value && row.status === 'active' && row.campus_status === 'active').forEach(row => select.add(new Option(`${row.college_code} - ${row.college_name}`, row.college_id)));
    select.value = String(selectedCollege);
  }
  $('organization-campus').addEventListener('change', () => colleges());
  function filterColleges() {
    for (const option of $('filter-college').options) if (option.value) {
      option.disabled = option.hidden = Boolean($('filter-campus').value && option.dataset.campus !== $('filter-campus').value);
      if (option.selected && option.disabled) $('filter-college').value = '';
    }
  }
  $('filter-campus').addEventListener('change', filterColleges); filterColleges();
  function lock(value) { busy = value; $('organization-fields').disabled = value; $('organization-save').disabled = value; editorElement.querySelectorAll('[data-bs-dismiss]').forEach(button => button.disabled = value); }
  editorElement.addEventListener('hide.bs.modal', event => { if (busy) event.preventDefault(); });
  deleteElement.addEventListener('hide.bs.modal', event => { if (deleting) event.preventDefault(); });
  [editorElement,deleteElement].forEach(element => element.addEventListener('hidden.bs.modal', () => opener?.focus()));
  async function openEditor(id) {
    if (busy) return; opener = document.activeElement; form.reset(); colleges(); notice('organization-error', ''); $('organization-usage').textContent = '';
    form.elements.organization_id.value = ''; form.elements.version.value = '';
    $('organization-editor-title').textContent = id ? 'Edit Organization' : 'Add Organization'; lock(false); editor.show();
    if (!id) { setTimeout(() => $('organization-campus').focus(), 250); return; }
    lock(true);
    try {
      const { record } = await request('get', { organization_id: id });
      $('organization-campus').value = record.campus_id; colleges(record.college_id);
      ['organization_id','version','organization_code','organization_name','status'].forEach(key => form.elements[key].value = record[key]);
      $('organization-usage').textContent = `${record.usage.accounts} assigned account(s). Organizations with accounts or records must stay in their original college.`;
    } catch (error) { notice('organization-error', error.message); }
    finally { lock(false); if (!form.elements.organization_id.value) { $('organization-fields').disabled = true; $('organization-save').disabled = true; } }
  }
  $('organization-add').addEventListener('click', () => openEditor(null));
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || !form.reportValidity()) return;
    const values = Object.fromEntries(new FormData(form)); lock(true); notice('organization-error','');
    try { const result = await request('save', values); complete(result.message); }
    catch (error) { notice('organization-error', error.message); }
    finally { lock(false); }
  });
  function lockDelete(value) { deleting = value; deleteElement.querySelectorAll('button').forEach(button => button.disabled = value); }
  document.addEventListener('click', async event => {
    const edit = event.target.closest('[data-edit]'), remove = event.target.closest('[data-delete]');
    if (edit) openEditor(edit.dataset.edit);
    if (!remove || deleting) return;
    opener = remove; selected = null; notice('organization-delete-error',''); $('organization-delete-copy').textContent = 'Loading organization…'; deletion.show(); lockDelete(true);
    try {
      const { record } = await request('get', { organization_id: remove.dataset.delete }); selected = record;
      const inUse = Object.values(record.usage).some(value => Number(value) > 0);
      $('organization-delete-copy').textContent = inUse ? `${record.organization_code} has assigned accounts or records. Set its status to INACTIVE instead.` : `Permanently delete ${record.organization_code} — ${record.organization_name}?`;
      lockDelete(false); $('organization-delete-confirm').disabled = inUse;
    } catch (error) { notice('organization-delete-error',error.message); lockDelete(false); $('organization-delete-confirm').disabled = true; }
  });
  $('organization-delete-confirm').addEventListener('click', async () => {
    if (!selected || deleting) return; lockDelete(true); notice('organization-delete-error','');
    try { const result = await request('delete',{ organization_id:selected.organization_id, version:selected.version }); complete(result.message); }
    catch (error) { notice('organization-delete-error',error.message); }
    finally { lockDelete(false); }
  });
})();
