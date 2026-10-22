(() => {
  'use strict';
  const root=document.getElementById('collegeStudentWorkload'),configElement=document.getElementById('studentWorkloadConfig');
  if(!root||!configElement)return;
  const config=JSON.parse(configElement.textContent),byId=id=>document.getElementById('studentWorkload'+id);
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=value=>new Intl.NumberFormat(undefined,{maximumFractionDigits:2}).format(Number(value)||0);
  let page=1,pages=1,generation=0,searchTimer,controller;
  function render(data){
    const s=data.summary,p=data.pagination;
    byId('Summary').innerHTML=`<span><strong>${number(s.students)}</strong>Students with 6+ subjects</span><span class="is-review"><strong>${number(s.needs_review)}</strong>With review signals</span><span class="is-review"><strong>${number(s.schedule_conflicts)}</strong>With schedule overlaps</span><span><strong>${number(s.highest_subjects)}</strong>Highest subject count</span>`;
    byId('List').innerHTML=data.rows.length?`<div class="student-workload-row is-heading" aria-hidden="true"><span>Student</span><span>Subjects</span><span>Units / hours</span><span>Review signals</span><span>Account</span></div>`+data.rows.map(row=>{
      const flags=row.flags.length?row.flags.map(flag=>`<span class="student-workload-flag is-${escape(flag.kind)}">${escape(flag.label)}</span>`).join(''):'<span class="student-workload-flag is-normal">No review signals</span>';
      const conflicts=row.conflicts.length?`<details class="student-workload-details"><summary>View overlapping subjects</summary><ul>${row.conflicts.map(c=>`<li>${escape(c.subject_a)} (${escape(c.section_a)}) / ${escape(c.subject_b)} (${escape(c.section_b)})<span>${escape(c.times.join('; '))}</span></li>`).join('')}</ul></details>`:'';
      const term=`${data.term.ay_id}:${data.term.semester}`;
      const url='../student/index.php?'+new URLSearchParams({preview_student_id:String(row.student_id),student_term:term,return_to:'../college-dean/index.php#collegeStudentWorkload'});
      const pending=row.pending_subjects>0,allPending=pending&&row.pending_subjects===row.subject_count;
      return `<article class="student-workload-row" data-workload-student="${Number(row.student_id)}"><div class="student-workload-identity"><strong>${escape(row.student_name)}</strong><small>${escape(row.student_number)} &middot; ${escape(row.programs.join(', '))}</small><small>${escape(row.sections.join(' / '))}</small></div><div class="student-workload-subjects">${number(row.subject_count)}</div><div class="student-workload-load"><strong>${allPending?'Units pending':number(row.units)+' units'+(pending?' confirmed':'')}</strong><small>${allPending?'Hours pending':number(row.weekly_hours)+' contact hrs / week'}</small>${pending?`<small>${number(row.pending_subjects)} subject(s) pending</small>`:''}</div><div class="student-workload-review"><div class="student-workload-flags">${flags}</div>${conflicts}</div><a class="btn btn-sm btn-outline-primary" href="${escape(url)}" aria-label="${escape('Show account for '+row.student_name)}"><i class="bx bx-show-alt me-1" aria-hidden="true"></i>Show account</a></article>`;
    }).join(''):'<div class="student-workload-empty"><i class="bx bx-check-shield" aria-hidden="true"></i>No students match these workload filters.</div>';
    page=p.page;pages=p.pages;byId('PageInfo').textContent=`Showing ${number(p.from)}–${number(p.to)} of ${number(p.total)} students`;
    byId('Page').textContent=`Page ${page} of ${pages}`;
  }
  async function load(reset=false){
    if(reset)page=1;
    const current=++generation;
    if(controller)controller.abort();controller=new AbortController();
    byId('List').setAttribute('aria-busy','true');byId('Notice').hidden=true;
    byId('Previous').disabled=true;byId('Next').disabled=true;byId('Refresh').disabled=true;
    try{
      const response=await fetch('../backend/query_college_dean_student_workload.php',{method:'POST',credentials:'same-origin',signal:controller.signal,body:new URLSearchParams({...config,search:byId('Search').value,signal:byId('Signal').value,sort:byId('Sort').value,page:String(page)})});
      const data=await response.json();if(current!==generation)return;
      if(!response.ok||data.status!=='ok')throw Error(data.message||'Unable to load student workloads.');
      render(data);
    }catch(error){if(error.name==='AbortError'||current!==generation)return;byId('Notice').textContent=error.message||'Unable to load student workloads. Please retry.';byId('Notice').hidden=false;byId('List').innerHTML='<div class="student-workload-empty">Workload results could not be loaded. Select Refresh to retry.</div>';byId('PageInfo').textContent='';page=1;pages=1;}
    finally{if(current===generation){byId('List').setAttribute('aria-busy','false');byId('Refresh').disabled=false;byId('Previous').disabled=page<=1;byId('Next').disabled=page>=pages;}}
  }
  byId('Filters').addEventListener('submit',event=>{event.preventDefault();clearTimeout(searchTimer);load(true);});
  byId('Search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>load(true),300);});
  for(const name of ['Signal','Sort'])byId(name).addEventListener('change',()=>{clearTimeout(searchTimer);load(true);});
  byId('Refresh').addEventListener('click',()=>load());
  byId('Previous').addEventListener('click',()=>{if(page>1){page--;load();}});
  byId('Next').addEventListener('click',()=>{if(page<pages){page++;load();}});
  load();
})();
