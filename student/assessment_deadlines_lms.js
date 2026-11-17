(function () {
  'use strict';
  const panel=document.getElementById('studentDeadlinePanel');if(!panel)return;
  const config=JSON.parse(document.getElementById('studentDeadlineConfig').textContent);
  const list=document.getElementById('studentDeadlineList'),error=document.getElementById('studentDeadlineError'),empty=document.getElementById('studentDeadlineEmpty'),button=document.getElementById('studentDeadlineRefresh');
  let busy=false;
  const node=(tag,className,text)=>{const el=document.createElement(tag);el.className=className;if(text!==undefined)el.textContent=text;return el;};
  function render(rows){
    const fragment=document.createDocumentFragment();
    rows.forEach(row=>{
      const item=node('li','student-deadline-item'+(row.is_complete?' is-complete':'')+(row.is_today?' is-today':''));
      item.dataset.deadlineAssessment=row.assessment_id;
      const content=node('div','student-deadline-content'),title=node('a','student-deadline-title',row.title);
      title.href='assessments_lms.php#assessment-'+Number(row.assessment_id);
      const notice=node('p','student-deadline-notice'),time=node('time','',row.deadline_label);
      time.dateTime=row.deadline_at.replace(' ','T')+'+08:00';
      notice.append(node('strong','',row.notice_label),document.createTextNode(' · '),time);
      content.append(title,node('p','student-deadline-subject',row.subject_label),notice);
      const status=node('span','student-deadline-status');const icon=node('i','bx '+(row.is_complete?'bx-check-circle':'bx-time-five'));icon.setAttribute('aria-hidden','true');
      status.append(icon,document.createTextNode(row.status_label));item.append(content,status);fragment.append(item);
    });
    list.replaceChildren(fragment);empty.hidden=rows.length>0;
  }
  async function refresh(){
    if(busy||document.hidden)return;busy=true;button.disabled=true;panel.setAttribute('aria-busy','true');
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
    try {
      const response=await fetch('assessments_api_lms.php',{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'deadlines',payload:{},csrf_token:config.csrf_token})});
      const data=await response.json();if(!response.ok||!data.ok||!Array.isArray(data.deadlines))throw Error('Unable to refresh deadlines.');
      render(data.deadlines);error.textContent='';
    } catch (_) {error.textContent='Deadlines could not be refreshed. The last loaded notices are shown; open Activities & assessments to check your work.';empty.hidden=true;}
    finally {clearTimeout(timeout);busy=false;button.disabled=false;panel.removeAttribute('aria-busy');}
  }
  button.addEventListener('click',refresh);
  window.addEventListener('focus',refresh);window.addEventListener('pageshow',event=>{if(event.persisted)refresh();});
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  setInterval(refresh,60000);
})();
