(function(){
  'use strict';
  const $=id=>document.getElementById(id),root=$('classStudentsRoster');if(!root)return;
  const config=JSON.parse($('classStudentsConfig').textContent),feedback=window.synkLmsFeedback,modal=new bootstrap.Modal($('csrStatusModal'));
  const state={roster:config.roster,filter:'all',page:1,size:25,busy:false,pending:null,target:null,stale:false};
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fold=value=>String(value).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
  function render(){
    root.querySelectorAll('[data-csr-count]').forEach(el=>el.textContent=state.roster.counts[el.dataset.csrCount]);
    root.querySelectorAll('[data-csr-filter]').forEach(button=>{const active=state.filter===button.dataset.csrFilter;button.classList.toggle('is-selected',active);button.setAttribute('aria-pressed',String(active));});
    const tokens=fold($('csrSearch').value.trim()).split(/\s+/).filter(Boolean);
    const source=state.filter==='excluded'?(state.roster.excluded_students||[]):state.roster.students;
    const rows=source.filter(row=>(state.filter==='all'||row.class_status===state.filter)&&tokens.every(t=>fold(row.full_name+' '+row.student_number).includes(t)));
    $('csrExcludedCount').textContent=state.roster.excluded_count||0;
    $('class-lms-students-title').textContent=state.filter==='excluded'?'Excluded accounts':'Class roster';
    $('csrExcludedNotice').hidden=state.filter!=='excluded';
    const pages=Math.max(1,Math.ceil(rows.length/state.size));state.page=Math.min(pages,state.page);const start=(state.page-1)*state.size;
    $('csrStudents').innerHTML=rows.slice(start,start+state.size).map(row=>{
      const drop=row.class_status==='dropped',excluded=row.class_status==='excluded',restore=drop||excluded,disabled=!state.roster.status_ready||state.busy?' disabled':'';
      return `<li class="csr-student${drop?' is-dropped':''}${excluded?' is-excluded':''}" data-csr-student="${row.student_id}"><div class="csr-identity"><img src="${escape(row.photo_url)}" alt="" width="44" height="44" loading="lazy" decoding="async"><div><strong>${escape(row.full_name)}</strong><span>${escape(row.student_number)}</span></div></div><div class="csr-status"><span class="csr-badge is-${excluded?'excluded':drop?'dropped':'enrolled'}"><i class="bx ${restore?'bx-user-x':'bx-check-circle'}" aria-hidden="true"></i>${excluded?'Excluded':drop?'Dropped':'Enrolled'}</span>${row.status_reason?`<p class="csr-reason">${escape(row.status_reason)}</p>`:''}</div><div class="csr-row-actions"><button type="button" class="csr-status-action${restore?' is-restore':''}" data-csr-action="${row.student_id}" data-csr-target="${restore?'enrolled':'dropped'}" aria-label="${escape((restore?'Restore to enrolled: ':'Mark as dropped: ')+row.full_name)}"${disabled}><i class="bx ${restore?'bx-undo':'bx-user-minus'}" aria-hidden="true"></i>${restore?'Restore to enrolled':'Mark as dropped'}</button>${!excluded?`<button type="button" class="csr-exclude-action" data-csr-action="${row.student_id}" data-csr-target="excluded" aria-label="${escape('Exclude from roster: '+row.full_name)}"${disabled}><i class="bx bx-hide" aria-hidden="true"></i>Exclude</button>`:''}</div></li>`;
    }).join('');
    $('csrResult').textContent=`Showing ${rows.length?start+1:0}–${Math.min(start+state.size,rows.length)} of ${rows.length} students`;
    $('csrPage').textContent=`Page ${state.page} of ${pages}`;$('csrPrevious').disabled=state.busy||state.page===1;$('csrNext').disabled=state.busy||state.page===pages;
    $('csrEmpty').hidden=rows.length>0;
    const noExcluded=state.filter==='excluded'&&source.length===0;
    $('csrEmpty').querySelector('h3').textContent=noExcluded?'No excluded accounts':state.roster.students.length?'No matching students':'No students listed';
    $('csrEmpty').querySelector('p').textContent=noExcluded?'Accounts excluded from this class can be reviewed and restored here.':state.roster.students.length?'Try another name, student ID or status filter.':'Students will appear when your college updates the class roster.';
    $('csrClear').hidden=!state.roster.students.length;$('csrClear').textContent=noExcluded?'Back to all students':'Clear filters';
    $('csrStorageNotice').hidden=state.roster.status_ready;
  }
  function busy(value){state.busy=value;root.setAttribute('aria-busy',String(value));root.querySelectorAll('button,input,select,textarea').forEach(el=>{if(value){el.dataset.csrDisabled=String(el.disabled);el.disabled=true;}else if(el.hasAttribute('data-csr-disabled')){el.disabled=el.dataset.csrDisabled==='true';delete el.dataset.csrDisabled;}});if(!value){render();$('csrStatusSave').disabled=state.stale;}}
  async function api(action,payload={}){const response=await fetch('class_students_api_lms.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,payload,context:config.context,csrf_token:config.csrf_token})});const data=await response.json();if(!response.ok||!data.ok){const e=Error(data.message||'The roster request could not be completed.');e.status=response.status;throw e;}return data.roster;}
  $('csrSearch').addEventListener('input',()=>{state.page=1;render();});$('csrPageSize').addEventListener('change',()=>{state.size=Number($('csrPageSize').value);state.page=1;render();});
  root.addEventListener('click',event=>{
    if(state.busy)return;const filter=event.target.closest('[data-csr-filter]');
    if(filter){state.filter=filter.dataset.csrFilter;state.page=1;render();return;}
    const button=event.target.closest('[data-csr-action]');if(!button||button.disabled)return;
    const student=[...state.roster.students,...(state.roster.excluded_students||[])].find(row=>row.student_id===Number(button.dataset.csrAction));if(!student)return;
    state.pending=student;state.target=button.dataset.csrTarget;state.stale=false;
    const titles={dropped:'Mark as dropped',enrolled:'Restore to enrolled',excluded:'Exclude from roster'};
    const help={dropped:'This class status excludes the student from assessment reminders. They retain LMS access, submissions and grades.',enrolled:'This restores the student to the normal class roster and real-student counts. Outstanding work is included in reminders.',excluded:'This account will be hidden from the normal student list, real-student totals, gradebook roster and submission reports. Records are retained. Use Manage excluded to restore it.'};
    $('csrStatusName').textContent=student.full_name;$('csrStatusNumber').textContent=student.student_number;$('csrStatusTitle').textContent=titles[state.target];$('csrStatusSave').textContent=titles[state.target];$('csrStatusSave').className='btn '+(state.target==='enrolled'?'btn-success':state.target==='excluded'?'btn-secondary':'btn-danger');$('csrStatusSave').disabled=false;$('csrStatusReason').value='';$('csrStatusError').textContent='';$('csrStatusHelp').textContent=help[state.target];modal.show();
  });
  $('csrPrevious').addEventListener('click',()=>{state.page--;render();});$('csrNext').addEventListener('click',()=>{state.page++;render();});$('csrClear').addEventListener('click',()=>{state.filter='all';state.page=1;$('csrSearch').value='';render();$('csrSearch').focus();});
  $('csrRefresh').addEventListener('click',async()=>{if(state.busy)return;busy(true);try{state.roster=await api('list');}catch(error){feedback.error(error.message||'The roster could not be refreshed. Your last loaded list is shown.');}finally{busy(false);}});
  $('csrStatusForm').addEventListener('submit',async event=>{event.preventDefault();if(state.busy||state.stale||!state.pending)return;const student=state.pending,status=state.target;busy(true);$('csrStatusError').textContent='';try{state.roster=await api('set_status',{student_id:student.student_id,status,version:student.status_version,reason:$('csrStatusReason').value});modal.hide();feedback.toast(status==='dropped'?'Student marked as dropped.':status==='excluded'?'Account excluded from roster and counts.':'Student restored to enrolled.');}catch(error){state.stale=error.status===409;$('csrStatusError').textContent=state.stale?'This status changed. Close this dialog and refresh the roster before trying again.':error.message||'Status could not be saved. Try again.';}finally{busy(false);}});
  $('csrStatusModal').addEventListener('hidden.bs.modal',()=>{const button=root.querySelector(`[data-csr-action="${state.pending?.student_id}"]`);(button||$('csrSearch')).focus({preventScroll:true});state.pending=null;});
  render();
})();
