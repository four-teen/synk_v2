(function () {
  'use strict';

  window.synkStudentEmailEditor = function (root, options) {
    const dialog = root.querySelector('#csrEmailModal');
    if (!dialog) return;
    const modal = new bootstrap.Modal(dialog);
    const address = dialog.querySelector('#csrEmailAddress');
    const notice = dialog.querySelector('#csrEmailError');
    const save = dialog.querySelector('#csrEmailSave');
    let selected = null;
    let version = null;
    let saving = false;

    async function request(action, payload) {
      const response = await fetch('class_students_api_lms.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action, payload, context: selected.context, csrf_token: options.csrfToken})
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'The school email request failed.');
      return data.student_email;
    }

    root.addEventListener('click', async event => {
      const button = event.target.closest('[data-csr-email]');
      if (!button || button.disabled || selected) return;
      const student = options.getStudent(button);
      if (!student) return;
      const opened = {...student, button};
      selected = opened;
      dialog.querySelector('#csrEmailName').textContent = student.name;
      dialog.querySelector('#csrEmailNumber').textContent = student.number;
      address.value = '';
      address.disabled = true;
      save.disabled = true;
      notice.textContent = 'Loading school email…';
      modal.show();
      try {
        const record = await request('get_email', {student_id: student.id});
        if (selected !== opened) return;
        version = record.version;
        address.value = record.email_address;
        address.disabled = false;
        save.disabled = false;
        notice.textContent = '';
        if (dialog.classList.contains('show')) address.focus();
      } catch (error) {
        if (selected === opened) notice.textContent = error.message || 'The school email could not be loaded.';
      }
    });

    dialog.addEventListener('shown.bs.modal', () => { if (version) address.focus(); });
    dialog.addEventListener('hide.bs.modal', event => { if (saving) event.preventDefault(); });
    dialog.addEventListener('hidden.bs.modal', () => {
      const button = selected?.button;
      selected = null;
      version = null;
      address.value = '';
      (button?.isConnected && !button.closest('[hidden]') ? button : options.fallbackFocus)?.focus({preventScroll: true});
    });
    dialog.querySelector('form').addEventListener('submit', async event => {
      event.preventDefault();
      if (saving || !selected || !version) return;
      saving = true;
      address.disabled = true;
      save.disabled = true;
      notice.textContent = '';
      let record;
      try {
        record = await request('set_email', {student_id: selected.id, email_address: address.value.trim(), version});
      } catch (error) {
        notice.textContent = error.message || 'The school email could not be saved.';
        return;
      } finally {
        saving = false;
        address.disabled = false;
        save.disabled = false;
      }
      options.onSaved(selected, record.email_address);
      modal.hide();
    });
  };
})();
