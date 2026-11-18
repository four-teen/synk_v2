(function () {
  'use strict';
  let fallbackTimer;

  function fallback(message) {
    document.querySelector('.profile-save-toast-fallback')?.remove();
    window.clearTimeout(fallbackTimer);
    const toast = document.createElement('div');
    toast.className = 'profile-save-toast-fallback';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    const icon = document.createElement('i');
    icon.className = 'bx bx-check-circle'; icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('span'); text.textContent = message;
    const close = document.createElement('button');
    close.type = 'button'; close.setAttribute('aria-label', 'Dismiss save confirmation'); close.textContent = '×';
    close.addEventListener('click', () => toast.remove());
    toast.append(icon, text, close); document.body.append(toast);
    const dismissLater = () => { window.clearTimeout(fallbackTimer); fallbackTimer = window.setTimeout(() => toast.remove(), 3500); };
    toast.addEventListener('mouseenter', () => window.clearTimeout(fallbackTimer));
    toast.addEventListener('mouseleave', dismissLater);
    toast.addEventListener('focusin', () => window.clearTimeout(fallbackTimer));
    toast.addEventListener('focusout', dismissLater);
    dismissLater();
  }

  function success(message) {
    if (!message) return;
    // Feedback must never turn a confirmed database save into a save-error message.
    try {
      const swal = window.Swal;
      if (!swal?.fire) { fallback(message); return; }
      const result = swal.fire({
        heightAuto: false, scrollbarPadding: false, buttonsStyling: false,
        toast: true, position: 'top-end', icon: 'success', titleText: message,
        showConfirmButton: false, showCloseButton: true, returnFocus: false,
        timer: 3500, timerProgressBar: true,
        customClass: {container: 'profile-feedback-container', popup: 'profile-feedback-toast', title: 'profile-feedback-title'},
        didOpen: node => {
          node.addEventListener('mouseenter', swal.stopTimer);
          node.addEventListener('mouseleave', swal.resumeTimer);
          node.addEventListener('focusin', swal.stopTimer);
          node.addEventListener('focusout', swal.resumeTimer);
        }
      });
      if (result?.catch) result.catch(() => fallback(message));
    } catch (error) {
      fallback(message);
    }
  }

  window.synkFacultyProfileFeedback = {success};
  const savedNotice = document.querySelector('[data-profile-saved-notice]');
  if (savedNotice?.textContent.trim()) success(savedNotice.textContent.trim());
})();
