'use strict';
(() => {
  const element=document.getElementById('studentProfilePhotoModal');if(!element)return;
  const form=document.getElementById('student-photo-form'),input=document.getElementById('student-profile-photo'),preview=document.getElementById('student-photo-preview'),error=document.getElementById('student-photo-error'),save=document.getElementById('student-photo-save');
  const original=preview.src,required=element.dataset.required==='1';
  const modal=bootstrap.Modal.getOrCreateInstance(element,{backdrop:required?'static':true,keyboard:!required});
  let imageUrl='',loading=false;
  function clearPreview(){if(imageUrl)URL.revokeObjectURL(imageUrl);imageUrl='';preview.src=original;}
  input.addEventListener('change',()=>{
    clearPreview();error.hidden=true;input.setCustomValidity('');
    const file=input.files[0];if(!file)return;
    let message='';
    if(file.size>5*1024*1024)message='Choose a photo no larger than 5 MB.';
    else if(!['image/jpeg','image/png','image/webp'].includes(file.type))message='Choose a JPG, PNG, or WebP photo.';
    if(message){error.textContent=message;error.hidden=false;input.setCustomValidity(message);return;}
    imageUrl=URL.createObjectURL(file);preview.src=imageUrl;
  });
  form.addEventListener('submit',event=>{
    if(loading || !form.reportValidity()){event.preventDefault();return;}
    loading=true;save.disabled=true;save.textContent='Saving photo…';
  });
  element.addEventListener('hide.bs.modal',event=>{if(required || loading)event.preventDefault();});
  element.addEventListener('hidden.bs.modal',()=>{form.reset();input.setCustomValidity('');error.hidden=true;clearPreview();});
  window.addEventListener('pageshow',event=>{if(event.persisted){window.location.reload();return;}loading=false;save.disabled=false;save.textContent=required?'Save photo and continue':'Save photo';});
  window.addEventListener('pagehide',()=>{if(imageUrl)URL.revokeObjectURL(imageUrl);});
  if(element.dataset.open==='1')modal.show();
})();
