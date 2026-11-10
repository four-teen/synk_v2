(function () {
  'use strict';
  const dialogs = [], swal = window.Swal;
  let dialogOpen = false, confirmationPending = false, pendingToast = '';
  const defaults = {
    heightAuto:false, scrollbarPadding:false, buttonsStyling:false,
    keydownListenerCapture:true, allowOutsideClick:false,
    customClass:{container:'lms-feedback-container',popup:'lms-feedback-popup',title:'lms-feedback-title',htmlContainer:'lms-feedback-text',actions:'lms-feedback-actions',confirmButton:'btn btn-primary',cancelButton:'btn btn-outline-secondary'}
  };
  function toast(text) {
    if (dialogOpen) { pendingToast = text; return; }
    swal.fire({...defaults,toast:true,position:'top-end',icon:'success',titleText:text,
      showConfirmButton:false,showCloseButton:true,returnFocus:false,timer:3500,timerProgressBar:true,
      customClass:{...defaults.customClass,popup:'lms-feedback-popup lms-feedback-toast'},
      didOpen:node => { node.addEventListener('mouseenter',swal.stopTimer); node.addEventListener('mouseleave',swal.resumeTimer); }
    });
  }
  async function nextDialog() {
    if (dialogOpen || !dialogs.length) return;
    dialogOpen = true;
    const {options,resolve} = dialogs.shift();
    let closed;
    const finished = new Promise(resolveClose => { closed = resolveClose; });
    // Keep the alert inside an open Bootstrap modal so its focus trap accepts
    // SweetAlert2 buttons; Escape is still captured by the alert first.
    const target = document.querySelector('.modal.show') || document.body;
    const result = await swal.fire({...defaults,...options,target,didClose:closed});
    await finished;
    dialogOpen = false; resolve(result);
    if (dialogs.length) nextDialog();
    else if (pendingToast) { const text = pendingToast; pendingToast = ''; toast(text); }
  }
  function dialog(options) { return new Promise(resolve => { dialogs.push({options,resolve}); nextDialog(); }); }
  async function confirm({title,text,confirmText = 'Continue',cancelText = 'Cancel',danger = false}) {
    if (confirmationPending) return false;
    confirmationPending = true;
    try {
      const result = await dialog({titleText:title,text,icon:'question',showCancelButton:true,
        confirmButtonText:confirmText,cancelButtonText:cancelText,focusCancel:true,
        customClass:{...defaults.customClass,confirmButton:danger ? 'btn btn-danger' : 'btn btn-primary'}});
      return result.isConfirmed;
    } finally { confirmationPending = false; }
  }
  function error(text,title = 'Please check and try again') { return dialog({icon:'error',titleText:title,text,confirmButtonText:'OK'}); }
  function info(text,title) { return dialog({icon:'info',titleText:title,text,confirmButtonText:'Got it'}); }
  function validation(form) {
    let showing = false;
    form.addEventListener('invalid',event => {
      event.preventDefault();
      if (showing) return;
      showing = true;
      const field = event.target, label = (field.labels?.[0]?.textContent || 'This field').replace(/\s*\*\s*$/, '').trim();
      field.classList.add('is-invalid');
      error(label + ': ' + field.validationMessage,'Check the required details').then(() => {
        showing = false;
        if (field.isConnected && !field.disabled) field.focus();
      });
    },true);
    form.addEventListener('input',event => event.target.classList.remove('is-invalid'));
  }
  function guardNavigation(hasChanges,discard,text) {
    document.addEventListener('click',async event => {
      const link = event.target.closest('a[href]');
      if (!link || event.defaultPrevented || event.button>0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
        || link.hasAttribute('download') || (link.target && link.target !== '_self') || !hasChanges()) return;
      const url = new URL(link.href,location.href);
      if (!['http:','https:'].includes(url.protocol) || (url.pathname===location.pathname && url.search===location.search && url.hash)) return;
      event.preventDefault();
      if (await confirm({title:'Leave this page?',text,confirmText:'Leave page',cancelText:'Stay here'})) {
        discard(); location.assign(link.href);
      }
    });
  }
  window.synkLmsFeedback = {toast,error,info,confirm,validation,guardNavigation,isPending:() => dialogOpen || confirmationPending};
})();
