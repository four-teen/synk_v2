(function () {
  'use strict';
  const configElement=document.getElementById('studentAnnouncementConfig'),element=document.getElementById('studentUpdatesModal');
  if(!configElement||!element||!window.bootstrap)return;
  const config=JSON.parse(configElement.textContent),button=document.getElementById('announcementMarkRead'),status=document.getElementById('announcementReadStatus');
  let keys=config.unread_keys.slice(),busy=false,shown=false,viewBoard=false;
  const modal=bootstrap.Modal.getOrCreateInstance(element);
  async function markRead(){
    if(busy||!keys.length)return;busy=true;button.disabled=true;
    try{
      const response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'read',payload:{event_keys:keys},csrf_token:config.csrf_token})});
      const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Read status could not be saved.');
      const read=new Set(data.read_keys);keys=keys.filter(key=>!read.has(key));
      document.querySelectorAll('[data-notice-key]').forEach(notice=>{if(read.has(notice.dataset.noticeKey)){notice.classList.remove('is-unread');notice.querySelector('.student-update-new').hidden=true;}});
      const count=document.getElementById('announcementUnreadCount');count.hidden=!keys.length;count.textContent=keys.length+' new';button.hidden=!keys.length;status.textContent='Updates marked as read.';
    }catch(error){status.textContent=(error.message||'Read status could not be saved.')+' Use “Mark all as read” to try again.';}
    finally{busy=false;button.disabled=false;}
  }
  function show(){
    if(shown||!keys.length||document.hidden||document.querySelector('.modal.show,.modal.showing,.offcanvas.show,.swal2-container'))return;
    shown=true;modal.show();
  }
  button.addEventListener('click',()=>{shown=true;markRead();});
  document.getElementById('announcementViewBoard').addEventListener('click',()=>{viewBoard=true;});
  element.addEventListener('hidden.bs.modal',()=>{markRead();if(viewBoard){document.getElementById('announcements-title').focus();document.getElementById('studentAnnouncements').scrollIntoView({behavior:'smooth',block:'start'});viewBoard=false;}});
  document.addEventListener('hidden.bs.modal',event=>{if(event.target!==element)show();});
  document.addEventListener('hidden.bs.offcanvas',show);document.addEventListener('visibilitychange',show);
  // Let required profile/evaluation prompts take priority during initial page setup.
  window.addEventListener('load',()=>setTimeout(show,700),{once:true});
})();
