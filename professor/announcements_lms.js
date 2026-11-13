(function () {
  'use strict';
  const $=id=>document.getElementById(id),root=$('professorAnnouncements');if(!root||!$('announcementConfig'))return;
  const config=JSON.parse($('announcementConfig').textContent),feedback=window.synkLmsFeedback;
  let rows=[],editing=null,busy=false,dirty=false;
  const escape=value=>String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const date=value=>new Date(value.replace(' ','T')+'+08:00').toLocaleString(undefined,{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'short'});
  async function api(action,payload={}){
    const response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,payload,context:config.context,csrf_token:config.csrf_token})});
    const data=await response.json();if(!response.ok||!data.ok)throw Error(data.message||'Announcements could not be updated.');return data;
  }
  function render(){
    $('announcementList').innerHTML=rows.length?rows.map(row=>`<article class="announcement-notice"><h3>${escape(row.title)}</h3><time>${escape(date(row.updated_at))}${Number(row.version)>1?' · Updated':''}</time><p class="announcement-message">${escape(row.body)}</p><div class="announcement-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-edit="${Number(row.announcement_id)}">Edit</button><button type="button" class="btn btn-sm btn-outline-danger" data-archive="${Number(row.announcement_id)}">Remove</button></div></article>`).join(''):'<p class="text-muted">No class notices yet. Post your first announcement using the form.</p>';
  }
  function reset(){editing=null;dirty=false;$('announcementForm').reset();$('announcementEditorTitle').textContent='New announcement';$('announcementPost').textContent='Post announcement';$('announcementCancel').hidden=true;}
  async function task(callback){
    if(busy)return;busy=true;root.querySelectorAll('button,input,textarea').forEach(control=>control.disabled=true);
    try{await callback();}catch(error){$('announcementFormStatus').textContent=error.message||'The connection was interrupted. Try again.';feedback.error($('announcementFormStatus').textContent,'Announcements');}
    finally{busy=false;root.querySelectorAll('button,input,textarea').forEach(control=>control.disabled=false);}
  }
  async function discard(){return !dirty||await feedback.confirm({title:'Discard unsaved announcement?',text:'Your changes have not been posted.',confirmText:'Discard changes',cancelText:'Keep editing'});}
  $('announcementForm').addEventListener('input',()=>{dirty=true;$('announcementFormStatus').textContent='Unsaved announcement';});
  $('announcementForm').addEventListener('submit',event=>{event.preventDefault();if(!$('announcementForm').reportValidity())return;task(async()=>{
    const payload={title:$('announcementSubject').value,body:$('announcementBody').value};
    if(editing)Object.assign(payload,{announcement_id:Number(editing.announcement_id),version:Number(editing.version)});
    const data=await api('save',payload);rows=data.announcements;const updated=!!editing;reset();render();$('announcementFormStatus').textContent=updated?'Announcement updated. Students will see it as a new update.':'Announcement posted to your class.';
  });});
  $('announcementCancel').addEventListener('click',async()=>{if(!busy&&await discard()){reset();$('announcementFormStatus').textContent='';}});
  $('announcementRefresh').addEventListener('click',()=>task(async()=>{rows=(await api('list')).announcements;render();$('announcementFormStatus').textContent='Class notices refreshed.';}));
  root.addEventListener('click',async event=>{
    const button=event.target.closest('[data-edit],[data-archive]');if(!button||busy)return;
    const row=rows.find(item=>Number(item.announcement_id)===Number(button.dataset.edit||button.dataset.archive));if(!row)return;
    if(button.dataset.edit){if(!await discard())return;editing={...row};dirty=false;$('announcementSubject').value=row.title;$('announcementBody').value=row.body;$('announcementEditorTitle').textContent='Edit announcement';$('announcementPost').textContent='Save changes';$('announcementCancel').hidden=false;$('announcementFormStatus').textContent='';$('announcementSubject').focus();}
    else if(await feedback.confirm({title:'Remove announcement?',text:'This notice will disappear from students’ dashboards.',confirmText:'Remove announcement',cancelText:'Keep announcement'}))task(async()=>{
      rows=(await api('archive',{announcement_id:Number(row.announcement_id),version:Number(row.version)})).announcements;
      if(Number(editing?.announcement_id)===Number(row.announcement_id))reset();render();$('announcementFormStatus').textContent='Announcement removed.';
    });
  });
  feedback.guardNavigation(()=>dirty,()=>{dirty=false;},'Your announcement has unsaved changes.');
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  task(async()=>{rows=(await api('list')).announcements;render();});
})();
