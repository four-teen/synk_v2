(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  if (!$('deanRosterReviewRefresh')) return;
  const root = $('deanWorkload'), config = JSON.parse($('deanWorkloadConfig').textContent);
  const escape = value => String(value == null ? '' : value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const labels = { missing_assignment: 'No faculty assignment', faculty_mismatch: 'Faculty differs from student records', class_unavailable: 'Class assignment needs review', ambiguous_class: 'Class offering needs verification' };
  let rows = [], busy = false;
  async function check() {
    if (busy) return;
    busy = true; $('deanRosterReviewRefresh').disabled = true; $('deanRosterReviewStatus').textContent = 'Checking student lists...';
    // Hide prior results while checking so a failed request cannot look like a current clean report.
    $('deanRosterReviewDetails').hidden = true;
    try {
      const body = new FormData();
      Object.entries({...config,action:'roster_review'}).forEach(([key,value]) => body.append(key,value));
      let response, data;
      try { response = await fetch('../backend/query_college_dean_rosters.php',{method:'POST',body,credentials:'same-origin',cache:'no-store'}); }
      catch (_) { throw new Error('Unable to connect. Check student lists again shortly.'); }
      try { data = await response.json(); }
      catch (_) { throw new Error('Unable to read the response. Reload the page and check student lists again.'); }
      if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to check student lists.');
      rows = data.rows;
      const checked = new Date(data.checked_at);
      const time = Number.isFinite(checked.getTime()) ? new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',dateStyle:'medium',timeStyle:'short'}).format(checked)+' (PH)' : '';
      $('deanRosterReviewStatus').textContent = (rows.length ? `${data.summary.classes} ${data.summary.classes===1?'class needs':'classes need'} review, affecting ${data.summary.affected_students} ${data.summary.affected_students===1?'student':'students'}.` : 'No missing or mismatched faculty assignments found in active student lists.')+(time?' Checked '+time+'.':'');
      $('deanRosterReviewStatus').className = 'small mb-0 '+(rows.length?'dean-roster-review-warning':'text-success');
      $('deanRosterReviewRows').innerHTML = `<table class="table dean-workload-table"><thead><tr><th>Class</th><th>Students</th><th>Faculty in student records</th><th>Current assignment</th><th>Next step</th></tr></thead><tbody>${rows.map((row,index)=>`<tr><td><strong>${escape(row.subject_code)}</strong><br>${escape(row.descriptive_title)}<br><small>${escape(row.program_label)} · ${escape(row.section_label)}<br>Class offering: ${row.offering_id>0?Number(row.offering_id):'Needs verification'}</small></td><td>${Number(row.student_count)} total<br><small>${Number(row.affected_student_count)} affected</small></td><td>${row.saved_faculty.map(escape).join('<br>')}</td><td><span class="badge bg-label-warning">${escape(labels[row.status] || 'Needs review')}</span><div class="mt-1">${row.assigned_faculty.length?row.assigned_faculty.map(escape).join('<br>'):'Unassigned'}</div></td><td>${row.review_faculty_id?`<button type="button" class="btn btn-sm btn-outline-primary" data-roster-review="${index}">Review Student List</button>`:escape(row.status==='missing_assignment'?'Assign this same class offering in Scheduler first.':'Ask Scheduler to verify the class offering and faculty assignment first.')}</td></tr>`).join('')}</tbody></table>`;
      $('deanRosterReviewDetails').hidden = !rows.length;
      if (rows.length) $('deanRosterReviewDetails').open = true;
    } catch (error) {
      rows = []; $('deanRosterReviewStatus').className = 'small mb-0 text-danger';
      $('deanRosterReviewStatus').textContent = (error.message || 'Unable to check student lists.')+' Use Check Student Lists to try again.';
    } finally { busy = false; $('deanRosterReviewRefresh').disabled = false; }
  }
  $('deanRosterReviewRefresh').addEventListener('click',check);
  $('deanRosterReviewRows').addEventListener('click',event => {
    const button = event.target.closest('[data-roster-review]');
    if (!button || busy) return;
    const row = rows[Number(button.dataset.rosterReview)];
    if (row?.review_faculty_id) root.dispatchEvent(new CustomEvent('roster-review-open',{detail:row}));
  });
  ['manual-roster-closed','clear-roster-closed'].forEach(name=>root.addEventListener(name,event=>{if(event.detail?.result || event.detail?.context?.needsRefresh)check();}));
  $('deanRefresh').addEventListener('click',check);
  check();
})();
