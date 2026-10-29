'use strict';
(() => {
  const configNode=document.getElementById('management-config');if(!configNode)return;
  const config=JSON.parse(configNode.textContent),$=id=>document.getElementById(id);
  const page=$('management-exemptions-page'),element=$('management-exemption-modal'),form=$('exemption-form');
  const canWrite=!!config.permissions?.manage_exemptions && config.term===config.current_term && config.exam_term===config.current_exam_term;
  let data=null,selected=new Map(),listMode=false,busy=false,loading=false,opening=0,searching=0,sourceRun=0,opener=null,revokeRow=null,pageRun=0;
  let pageNumber=1,pagination=null;
  const modal=element?bootstrap.Modal.getOrCreateInstance(element):null,revoke=$('management-exemption-revoke');
  const revokeModal=revoke?bootstrap.Modal.getOrCreateInstance(revoke):null;
  const show=(id,message)=>{const node=$(id);if(node){node.textContent=message;node.hidden=!message;}};
  const button=(label,handler,style='btn-outline-primary')=>{const node=document.createElement('button');node.type='button';node.className='btn btn-sm '+style;node.textContent=label;node.addEventListener('click',handler);return node;};
  const text=(tag,value,className='')=>{const node=document.createElement(tag);node.textContent=value;if(className)node.className=className;return node;};
  const listSummary=(body,row)=>{
    body.replaceChildren(text('h4',row.title,'management-exemption-card-title'),text('p',row.reason,'management-exemption-description'),text('span',`${row.member_count} student${Number(row.member_count)===1?'':'s'}`,'management-exemption-member-count'));
  };
  async function request(action,values={}) {
    const response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new URLSearchParams({action,csrf_token:config.csrf_token,college_id:config.college_id,campus_id:config.campus_id,organization_id:config.organization_id,term:config.term,exam_term:config.exam_term,current_term:config.current_term,current_exam_term:config.current_exam_term,current_period_version:config.current_period_version,...values})});
    let result;try{result=await response.json();}catch(_){throw Error('The response could not be read. Reload and try again.');}
    if(!response.ok||result.status!=='ok')throw Error(result.message||'Unable to update exemptions.');return result;
  }
  function selection() {
    $('exemption-selected-count').textContent=`${selected.size} student${selected.size===1?'':'s'} selected`;
    const root=$('exemption-selected-students');root.replaceChildren();
    for(const [id,row] of selected){const line=document.createElement('div');line.append(text('span',`${row.student_name} · ${row.student_number}`));if(listMode||!$('exemption-source').value)line.append(button('Remove',()=>{selected.delete(id);selection();}));root.append(line);}
    $('exemption-submit').disabled=busy||loading||(!listMode&&selected.size===0);
  }
  function choices(root,rows,key,preselected) {
    root.replaceChildren();if(!rows.length){root.append(text('p','No active records in this period.'));return;}
    for(const row of rows){const label=document.createElement('label'),input=document.createElement('input');input.type='checkbox';input.name=key;input.value=row[key];input.checked=String(row[key])===String(preselected);label.append(input,text('span',row.title),text('small',key==='event_id'?`${row.event_date} · ${row.contribution_count} linked contribution(s)`:`₱${Number(row.amount).toFixed(2)}`));root.append(label);}
  }
  async function open(options={}) {
    if(!canWrite||busy||!modal)return;
    const run=++opening;loading=true;searching++;sourceRun++;opener=options.opener||document.activeElement;listMode=!!options.list;
    form.reset();selected=new Map();show('exemption-modal-error','');$('exemption-student-results').replaceChildren();$('exemption-fields').disabled=true;$('exemption-submit').disabled=true;
    $('exemption-list-fields').hidden=!listMode;$('exemption-mode-field').hidden=listMode;$('exemption-charge-fields').hidden=listMode;
    $('exemption-list-title').required=listMode;$('exemption-reason').readOnly=false;$('exemption-student-picker').hidden=false;
    $('exemption-modal-title').textContent=listMode?(options.listId?'Edit exemption list':'New exemption list'):'Grant exemptions';
    $('exemption-submit').textContent=listMode?'Save list':'Save exemptions';$('exemption-source-note').textContent='';selection();modal.show();
    try {
      data=await request('exemption_data');if(run!==opening)return;
      const source=$('exemption-source');source.replaceChildren(new Option('Select individual students',''));
      for(const row of data.lists){const option=new Option(`${row.title} (${row.member_count} students)`,row.list_id);source.append(option);}
      choices($('exemption-event-choices'),data.events,'event_id',options.eventId);
      choices($('exemption-contribution-choices'),data.contributions,'contribution_id',options.contributionId);
      if(options.student){selected.set(String(options.student.student_id),options.student);}
      if(options.listId){const result=await request('exemption_list_get',{list_id:options.listId});if(run!==opening)return;form.elements.list_id.value=result.list.list_id;form.elements.revision.value=result.list.revision;form.elements.title.value=result.list.title;form.elements.reason.value=result.list.reason;for(const student of result.list.students)selected.set(String(student.student_id),student);}
      loading=false;$('exemption-fields').disabled=false;selection();
    }catch(error){if(run===opening){loading=false;show('exemption-modal-error',error.message);}}
  }
  async function loadPage() {
    if(!page)return;const run=++pageRun;show('exemption-page-error','');
    const url=new URL(location.href);
    const values={page:String(pageNumber),search:$('exemption-filter-search').value,event_id:$('exemption-filter-event').value||url.searchParams.get('event_id')||'',student_id:url.searchParams.get('student_id')||'',contribution_id:url.searchParams.get('contribution_id')||''};
    try {
      const result=await request('exemption_data',values);if(run!==pageRun)return;data=result;pagination=result.pagination;pageNumber=pagination.page;
      const filter=$('exemption-filter-event'),filterValue=values.event_id;filter.replaceChildren(new Option('All events and contributions',''));for(const row of result.events)filter.append(new Option(row.title,row.event_id));filter.value=filterValue;
      const lists=$('exemption-lists');lists.replaceChildren();if(!result.lists.length)lists.append(text('p','No reusable lists yet.'));
      for(const row of result.lists){
        const card=document.createElement('article'),body=document.createElement('div');listSummary(body,row);card.append(body);
        if(canWrite)card.append(button('Edit list',()=>open({list:true,listId:row.list_id})));
        else card.append(button('View members',async()=>{
          try{
            const result=await request('exemption_list_get',{list_id:row.list_id});listSummary(body,row);
            const members=document.createElement('ul');members.className='management-exemption-members';members.setAttribute('aria-label','List members');
            for(const student of result.list.students){const member=document.createElement('li');member.append(text('span',student.student_name),text('small',student.student_number));members.append(member);}
            body.append(members);
          }catch(error){show('exemption-page-error',error.message);}
        }));
        lists.append(card);
      }
      const grants=$('exemption-grants');grants.replaceChildren();if(!result.grants.length)grants.append(text('p','No matching saved exemptions.'));
      for(const row of result.grants){
        const card=document.createElement('article'),body=document.createElement('div'),identity=document.createElement('div'),target=document.createElement('p'),meta=document.createElement('div');
        identity.className='management-exemption-identity';identity.append(text('h4',row.student_name,'management-exemption-card-title'),text('small',row.student_number));
        target.className='management-exemption-target';target.append(text('span',row.event_id?'Event':'Contribution','management-exemption-kind'),text('span',row.title));
        meta.className='management-exemption-meta';meta.append(text('span',row.status==='ACTIVE'?'Exempted':'Revoked',`management-exemption-status ${row.status==='ACTIVE'?'is-active':'is-revoked'}`),text('small',row.list_title||'Individual selection'));
        if(row.source_event_id)meta.append(text('small','Inherited from event'));
        body.append(identity,target,text('p',row.reason,'management-exemption-description'),meta);card.append(body);
        if(canWrite&&row.status==='ACTIVE')card.append(button('Revoke',()=>{revokeRow=row;$('exemption-revoke-form').reset();show('exemption-revoke-error','');$('exemption-revoke-description').textContent=`${row.student_name} · ${row.title}`;revokeModal.show();},'btn-outline-danger'));
        grants.append(card);
      }
      $('exemption-page-summary').textContent=`Page ${pagination.page} of ${pagination.pages} · ${pagination.total} saved exemption(s)`;$('exemption-previous').disabled=pageNumber<=1;$('exemption-next').disabled=pageNumber>=pagination.pages;
      const audit=$('exemption-audit');audit.replaceChildren();if(!result.audit.length)audit.append(text('li','No activity recorded yet.'));for(const row of result.audit){const li=document.createElement('li');li.append(text('strong',`${row.action.replaceAll('_',' ')} · ${row.actor_name||'Management'}`),text('small',`${row.recorded_at}`));const details=JSON.parse(row.details);li.append(text('span',details.title||details.reason||(details.student_ids?`${details.student_ids.length} students`:'Saved exemption change')));audit.append(li);}
    }catch(error){if(run===pageRun)show('exemption-page-error',error.message);}
  }
  document.addEventListener('click',event=>{const trigger=event.target.closest('[data-exempt-open]');if(!trigger||trigger.disabled)return;open({opener:trigger,eventId:trigger.dataset.exemptEvent,contributionId:trigger.dataset.exemptContribution,student:trigger.dataset.exemptStudent?{student_id:trigger.dataset.exemptStudent,student_name:trigger.dataset.exemptName,student_number:trigger.dataset.exemptNumber}:null});});
  if(page){$('exemption-list-new')?.addEventListener('click',()=>open({list:true}));$('exemption-filter-form').addEventListener('submit',event=>{event.preventDefault();const url=new URL(location.href);url.searchParams.delete('event_id');history.replaceState(null,'',url);pageNumber=1;loadPage();});$('exemption-previous').addEventListener('click',()=>{if(pageNumber>1){pageNumber--;loadPage();}});$('exemption-next').addEventListener('click',()=>{if(pageNumber<pagination?.pages){pageNumber++;loadPage();}});loadPage();}
  if(!form)return;
  $('exemption-source').addEventListener('change',async()=>{const run=++sourceRun,id=$('exemption-source').value;selected.clear();selection();$('exemption-student-results').replaceChildren();$('exemption-student-picker').hidden=!!id;$('exemption-reason').readOnly=!!id;form.elements.reason.value='';form.elements.list_id.value='';form.elements.revision.value='';$('exemption-source-note').textContent='';if(!id)return;try{const result=await request('exemption_list_get',{list_id:id});if(run!==sourceRun)return;form.elements.list_id.value=id;form.elements.revision.value=result.list.revision;form.elements.reason.value=result.list.reason;for(const student of result.list.students)selected.set(String(student.student_id),student);$('exemption-source-note').textContent='These current members will be saved to the selected events and contributions. Earlier grants stay unchanged.';selection();}catch(error){if(run===sourceRun)show('exemption-modal-error',error.message);}});
  async function find(){if(busy)return;const run=++searching,openRun=opening,query=$('exemption-student-search').value.trim();show('exemption-modal-error','');$('exemption-student-results').textContent='Searching…';try{const result=await request('exemption_search',{search:query});if(run!==searching||openRun!==opening)return;const root=$('exemption-student-results');root.replaceChildren();if(!result.students.length)root.append(text('p','No matching students.'));for(const row of result.students){const line=document.createElement('div');line.append(text('span',`${row.student_name} · ${row.student_number}`));const add=button(selected.has(String(row.student_id))?'Selected':'Add',()=>{selected.set(String(row.student_id),row);selection();add.textContent='Selected';});line.append(add);root.append(line);}}catch(error){if(run===searching&&openRun===opening){$('exemption-student-results').replaceChildren();show('exemption-modal-error',error.message);}}}
  $('exemption-student-find').addEventListener('click',find);$('exemption-student-search').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();find();}});
  form.addEventListener('submit',async event=>{event.preventDefault();if(busy||loading||!form.reportValidity())return;busy=true;show('exemption-modal-error','');const values={list_id:form.elements.list_id.value,revision:form.elements.revision.value,list_revision:form.elements.revision.value,title:form.elements.title.value,reason:form.elements.reason.value,student_ids:JSON.stringify([...selected.keys()]),event_ids:JSON.stringify([...form.querySelectorAll('[name=event_id]:checked')].map(node=>node.value)),contribution_ids:JSON.stringify([...form.querySelectorAll('[name=contribution_id]:checked')].map(node=>node.value))};$('exemption-fields').disabled=true;$('exemption-submit').disabled=true;try{const result=await request(listMode?'exemption_list_save':'exemption_apply',values);busy=false;modal.hide();if(page){show('exemption-page-notice',result.message);await loadPage();}else location.reload();}catch(error){show('exemption-modal-error',error.message);}finally{busy=false;$('exemption-fields').disabled=false;selection();}});
  element.addEventListener('hide.bs.modal',event=>{if(busy){event.preventDefault();return;}opening++;searching++;sourceRun++;});element.addEventListener('hidden.bs.modal',()=>opener?.focus());
  $('exemption-revoke-form').addEventListener('submit',async event=>{event.preventDefault();if(busy||!revokeRow||!event.target.reportValidity())return;busy=true;const controls=event.target.querySelectorAll('textarea,button');controls.forEach(node=>node.disabled=true);show('exemption-revoke-error','');try{const result=await request('exemption_revoke',{exemption_id:revokeRow.exemption_id,revision:revokeRow.revision,reason:$('exemption-revoke-reason').value});busy=false;revokeModal.hide();show('exemption-page-notice',result.message);await loadPage();}catch(error){show('exemption-revoke-error',error.message);}finally{busy=false;controls.forEach(node=>node.disabled=false);}});
  revoke.addEventListener('hide.bs.modal',event=>{if(busy)event.preventDefault();});
})();
