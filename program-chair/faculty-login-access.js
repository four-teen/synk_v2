(() => {
  'use strict';
  const get = id => document.getElementById(id);
  const element = get('facultyLoginModal');
  if (!element) return;
  const modal = new bootstrap.Modal(element, {backdrop:'static'});
  const form = get('facultyLoginForm'), fields = get('facultyLoginFields'), save = get('facultyLoginSave');
  const email = get('facultyLoginEmail'), primary = get('facultyLoginPrimary');
  const roleInputs = [get('facultyLoginProfessor'), get('facultyLoginScheduler')];
  let facultyId = 0, revision = '', canManage = false, saving = false, generation = 0;
  let primaryLocked = false, statusLocked = false, accountExists = false;
  const notice = (message = '', type = 'info') => {
    get('facultyLoginNotice').textContent = message;
    get('facultyLoginNotice').className = 'alert alert-' + type;
    get('facultyLoginNotice').hidden = !message;
  };
  const selectedRoles = () => roleInputs.filter(input => input.checked).map(input => input.value);
  function setPrimary(preferred = primary.value) {
    primary.replaceChildren();
    if (primaryLocked) {
      return;
    }
    const roles = selectedRoles();
    for (const role of roles) primary.add(new Option(role === 'professor' ? 'Faculty' : 'Scheduler', role));
    primary.value = roles.includes(preferred) ? preferred : (roles[0] || '');
  }
  async function request(action, values = {}) {
    const data = {action, faculty_id:String(facultyId), college_id:String(get('college_id').value),
      ay_id:String(facultyListState.termAyId), semester:String(facultyListState.termSemester), csrf_token:CSRF_TOKEN, ...values};
    let response;
    try { response = await fetch('../backend/query_program_chair_faculty_account.php', {method:'POST',body:new URLSearchParams(data),credentials:'same-origin',cache:'no-store'}); }
    catch (_) { throw Error('Unable to connect. Please try again.'); }
    let result;
    try { result = await response.json(); } catch (_) { throw Error('Login access could not be loaded. Reload this page and try again.'); }
    if (!response.ok || !['ok','saved'].includes(result.status)) throw Error(result.message || 'Unable to manage login access.');
    return result;
  }
  document.addEventListener('click', async event => {
    const button = event.target.closest('.btn-manage-login');
    if (!button || button.disabled || saving) return;
    const nextId = Number(button.dataset.facultyId || 0);
    if (!nextId) return;
    const current = ++generation;
    facultyId = nextId; revision = ''; canManage = false;
    primaryLocked = false; statusLocked = false;
    primary.disabled = false; get('facultyLoginStatus').disabled = false;
    accountExists = false;
    email.value = ''; email.disabled = true; get('facultyLoginEmailField').hidden = true;
    get('facultyLoginPrimaryField').hidden = false;
    get('facultyLoginStatusNote').textContent = '';
    form.reset(); primary.replaceChildren(); fields.disabled = true; save.disabled = true;
    get('facultyLoginName').textContent = 'Loading faculty...'; notice('Loading login access...'); modal.show();
    try {
      const result = await request('load');
      if (current !== generation) return;
      const account = result.account;
      revision = account.revision; canManage = account.can_manage;
      get('facultyLoginName').textContent = account.faculty_name;
      accountExists = account.exists;
      email.value = ''; email.disabled = accountExists; get('facultyLoginEmailField').hidden = accountExists;
      email.maxLength = Number(account.email_max_length || 150);
      email.placeholder = 'faculty@' + account.allowed_domain;
      get('facultyLoginEmailNote').textContent = 'Use the faculty member\'s ' + account.allowed_domain + ' email to create the login.';
      get('facultyLoginStatus').value = account.status;
      primaryLocked = account.can_manage_primary === false;
      statusLocked = account.can_manage_status === false;
      get('facultyLoginStatus').disabled = statusLocked;
      primary.disabled = primaryLocked;
      get('facultyLoginPrimaryField').hidden = primaryLocked;
      get('facultyLoginStatusNote').textContent = statusLocked ? 'Login status is managed separately.' : 'Inactive accounts cannot sign in.';
      roleInputs.forEach(input => { input.checked = account.exists ? account.roles.includes(input.value) : input.value === 'professor'; });
      setPrimary(account.primary_role); fields.disabled = !canManage; save.disabled = !canManage;
      notice(canManage ? '' : account.reason, canManage ? 'info' : 'warning');
    } catch (error) { if (current === generation) notice(error.message, 'danger'); }
  });
  roleInputs.forEach(input => input.addEventListener('change', () => setPrimary()));
  email.addEventListener('blur', () => { email.value = email.value.trim().toLowerCase(); });
  element.addEventListener('hide.bs.modal', event => { if (saving) event.preventDefault(); else generation++; });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!canManage || saving || save.disabled) return;
    if (!selectedRoles().length) { notice('Select Faculty, Scheduler, or both roles.','warning'); return; }
    if (!form.reportValidity()) return;
    saving = true; save.disabled = true; fields.disabled = true; save.textContent = 'Saving...'; notice();
    try {
      const values = {account_revision:revision,status:get('facultyLoginStatus').value,roles_json:JSON.stringify(selectedRoles())};
      if (!accountExists) values.email = email.value.trim().toLowerCase();
      if (!primaryLocked) values.primary_role = primary.value;
      const result = await request('save', values);
      saving = false; modal.hide(); refreshFacultyData({preserveScroll:true});
      Swal.fire({icon:'success',title:'Login Access Saved',text:result.message,timer:1500,showConfirmButton:false});
    } catch (error) { notice(error.message,'danger'); }
    finally { saving = false; fields.disabled = !canManage; save.disabled = !canManage; save.textContent = 'Save Login Access'; }
  });
})();
