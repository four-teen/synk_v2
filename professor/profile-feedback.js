(function () {
  'use strict';
  let fallbackTimer;
  let confirming=false;
  const deferredSuccess=[];

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
    if (confirming) { deferredSuccess.push(message); return; }
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

  function fallbackConfirmation(title, message, proceed) {
    return new Promise(resolve => {
      const previous=document.activeElement;
      const overlay=document.createElement('div'); overlay.className='profile-confirm-overlay';
      const dialog=document.createElement('section'); dialog.className='profile-confirm-dialog';
      dialog.setAttribute('role','alertdialog'); dialog.setAttribute('aria-modal','true');
      dialog.setAttribute('aria-labelledby','profile-confirm-title'); dialog.setAttribute('aria-describedby','profile-confirm-message');
      const icon=document.createElement('i'); icon.className='bx bx-trash'; icon.setAttribute('aria-hidden','true');
      const heading=document.createElement('h2'); heading.id='profile-confirm-title'; heading.textContent=title;
      const text=document.createElement('p'); text.id='profile-confirm-message'; text.textContent=message;
      const actions=document.createElement('div'); actions.className='profile-confirm-actions';
      const cancel=document.createElement('button'); cancel.type='button'; cancel.className='btn btn-outline-secondary'; cancel.textContent='Cancel';
      const confirm=document.createElement('button'); confirm.type='button'; confirm.className='btn profile-confirm-proceed'; confirm.textContent=proceed;
      let finished=false;
      function finish(confirmed) {
        if (finished) return; finished=true; overlay.remove(); previous?.focus({preventScroll:true}); resolve(confirmed);
      }
      cancel.addEventListener('click',()=>finish(false)); confirm.addEventListener('click',()=>finish(true));
      overlay.addEventListener('click',event=>{if(event.target===overlay)finish(false);});
      overlay.addEventListener('keydown',event=>{
        if(event.key==='Escape'){event.preventDefault();finish(false);}
        if(event.key==='Tab'){event.preventDefault();(document.activeElement===cancel?confirm:cancel).focus();}
      });
      actions.append(cancel,confirm); dialog.append(icon,heading,text,actions); overlay.append(dialog); document.body.append(overlay); cancel.focus();
    });
  }

  async function confirmRemoval({title='Remove this entry?',text='This change takes effect when you save.',confirmText='Proceed'}={}) {
    if(confirming)return false;
    confirming=true;
    try {
    if (window.Swal?.fire) {
      try {
        const result=await window.Swal.fire({
          titleText:title,text,icon:'warning',showCancelButton:true,confirmButtonText:confirmText,cancelButtonText:'Cancel',
          reverseButtons:true,focusCancel:true,heightAuto:false,scrollbarPadding:false,buttonsStyling:false,
          customClass:{container:'profile-feedback-container',popup:'profile-confirm-popup',title:'profile-confirm-title',
            htmlContainer:'profile-confirm-message',actions:'profile-confirm-actions',confirmButton:'btn profile-confirm-proceed',cancelButton:'btn btn-outline-secondary'}
        });
        return result?.isConfirmed===true;
      } catch (error) { /* Keep removal confirmation available when the alert library fails. */ }
    }
    return await fallbackConfirmation(title,text,confirmText);
    } finally { confirming=false; deferredSuccess.splice(0).forEach(success); }
  }

  window.synkFacultyProfileFeedback = {success,confirmRemoval};
  const savedNotice = document.querySelector('[data-profile-saved-notice]');
  if (savedNotice?.textContent.trim()) success(savedNotice.textContent.trim());
})();
