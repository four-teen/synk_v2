'use strict';
(() => {
  const element=document.getElementById('management-attendance');if(!element)return;
  const config=JSON.parse(document.getElementById('management-config').textContent),modal=bootstrap.Modal.getOrCreateInstance(element);
  const currentView=config.term===config.current_term && (config.page==='flag_raising' || config.exam_term===config.current_exam_term);
  const canScan=currentView && config.permissions?.attendance;
  const canManage = Boolean(config.permissions?.manage_events);
  let eventExamTerm=config.exam_term;
  element.querySelector('.management-attendance-reader-grid').classList.toggle('management-attendance-history',!currentView);
  const $=id=>document.getElementById(id),video=$('attendance-video'),canvas=document.createElement('canvas'),context=canvas.getContext('2d',{willReadFrequently:true});
  const dialog = element.querySelector('.modal-dialog');
  const reader = element.querySelector('.management-attendance-reader-grid');
  const stateControl = element.querySelector('.management-attendance-close');
  const roster = $('attendance-roster');
  const correction = $('attendance-correction');
  const rosterRows = $('attendance-roster-rows');
  const rosterFilter = $('attendance-roster-filter');
  const attendanceDate = new Intl.DateTimeFormat('en-PH', {dateStyle:'medium', timeStyle:'short'});
  const statusLabels = {PRESENT:'Present', ABSENT:'Absent', EXEMPTED:'Exempted'};
  let rosterPage = 1, rosterRequest = 0, rosterRevision = '', rosterStudents = new Map(), editing = null;
  let state=null,eventId=0,generation=0,cameraGeneration=0,stream=null,timer=null,scanning=false,busy=false,opener=null,lastCode='',lastAt=0,cameraCode='',cameraDevice='';
  const error=message=>{$('attendance-error').textContent=message;$('attendance-error').hidden=!message;};
  const cameraStatus=message=>{$('attendance-camera-status').textContent=message;};
  async function request(action,values={}) {
    const body=new URLSearchParams({action,event_id:eventId,csrf_token:config.csrf_token,college_id:config.college_id,campus_id:config.campus_id,organization_id:config.organization_id,
      term:config.term,exam_term:eventExamTerm,current_term:config.current_term,current_exam_term:config.current_exam_term,current_period_version:config.current_period_version,...values});
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
    try {
      const response=await fetch(config.endpoint,{method:'POST',body,credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok || data.status!=='ok')throw Error(data.message || 'Attendance request failed.');return data;
    }catch(e){if(e.name==='AbortError' || e instanceof TypeError)throw Error(action==='attendance_scan'
      ? 'Connection interrupted. Scan this student again to confirm attendance; a repeated scan will not create a duplicate.'
      : 'The connection was interrupted. Reload attendance before retrying.');throw e;}
    finally{clearTimeout(timeout);}
  }
  function stopCamera() {
    cameraGeneration++;
    clearTimeout(timer);scanning=false;cameraCode='';if(stream)stream.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null;video.hidden=true;
    $('attendance-camera-placeholder').hidden=false;$('attendance-camera-stop').disabled=true;
    cameraStatus(state?.attendance_status==='CLOSED'?'Attendance closed':'Camera stopped');
  }
  function formatTime(value) {
    return value ? attendanceDate.format(new Date(value.replace(' ', 'T'))) : '—';
  }
  function rosterCell(row, label, value) {
    const cell = row.insertCell();
    cell.setAttribute('role', 'cell');
    if (label) cell.dataset.label = label;
    cell.textContent = value;
    return cell;
  }
  function checkpointLabel(value) {
    return value === null ? 'Unknown' : (value ? 'Recorded' : 'Missing');
  }
  function rosterMessage(message) {
    const row = rosterRows.insertRow();
    row.setAttribute('role', 'row');
    const cell = rosterCell(row, '', message);
    cell.className = 'management-record-empty';
    cell.colSpan = 2 + (state?.two_checkpoints ? 2 : 0) + Number(canManage);
  }
  function renderRoster(data) {
    rosterPage = data.page;
    rosterRevision = data.revision;
    rosterStudents = new Map(data.rows.map(student => [student.student_id, student]));
    rosterRows.replaceChildren();
    for (const student of data.rows) {
      const row = rosterRows.insertRow();
      row.setAttribute('role', 'row');
      const identity = rosterCell(row, '', '');
      identity.className = 'management-record-title';
      const name = document.createElement('strong');
      name.textContent = student.student_name;
      const number = document.createElement('small');
      number.textContent = student.student_number;
      identity.append(name, number);
      if (state.two_checkpoints) {
        rosterCell(row, 'IN', checkpointLabel(student.checked_in));
        rosterCell(row, 'OUT', checkpointLabel(student.checked_out));
      }
      const status = rosterCell(row, 'Status', '');
      const badge = document.createElement('span');
      const color = {PRESENT:'success', ABSENT:'danger', EXEMPTED:'secondary'}[student.attendance_status];
      badge.className = `badge bg-label-${color}`;
      badge.textContent = statusLabels[student.attendance_status];
      status.append(badge);
      const detail = student.correction_reason || (state.two_checkpoints && student.checked_in && student.checked_out && student.attendance_status !== 'PRESENT'
        ? 'IN and OUT are on separate workbook rows.' : '');
      if (detail) {
        const note = document.createElement('small');
        note.textContent = detail;
        status.append(note);
      }
      if (canManage) {
        const actions = rosterCell(row, '', '');
        actions.className = 'management-record-actions';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary';
        button.textContent = 'Edit';
        button.dataset.correctAttendance = student.student_id;
        button.setAttribute('aria-label', `Edit attendance for ${student.student_name}`);
        actions.append(button);
      }
    }
    if (!data.rows.length) rosterMessage('No students match these filters.');
    $('attendance-roster-page').textContent = `Page ${data.page} of ${data.pages} · ${Number(data.total).toLocaleString()} students`;
    $('attendance-roster-previous').disabled = data.page <= 1;
    $('attendance-roster-next').disabled = data.page >= data.pages;
  }
  async function loadRoster(page) {
    const run = generation, requestId = ++rosterRequest;
    const current = () => run === generation && requestId === rosterRequest && state?.attendance_status === 'CLOSED';
    rosterRows.replaceChildren();
    rosterMessage('Loading students…');
    $('attendance-roster-previous').disabled = $('attendance-roster-next').disabled = true;
    try {
      const data = await request('attendance_roster', {roster_page:page, roster_search:$('attendance-roster-search').value,
        roster_status:$('attendance-roster-status').value});
      if (current()) renderRoster(data.roster);
    } catch (failure) {
      if (current()) {
        rosterRows.replaceChildren();
        rosterMessage(failure.message);
      }
    }
  }
  function render(next) {
    const previouslyClosed = state?.attendance_status === 'CLOSED';
    state=next;$('attendance-title').textContent=next.title;$('attendance-status').textContent=next.attendance_status==='OPEN'?'Open':next.attendance_status==='CLOSED'?'Closed':'Not started';
    const eventRow=document.querySelector(`[data-event-row="${Number(next.event_id)}"]`);
    if(eventRow){
      const exempted=eventRow.querySelector('[data-event-exempted]');if(exempted)exempted.textContent=String(next.exempted||0)+' exempted';
      eventRow.querySelector('[data-event-present]').textContent=Number(next.present).toLocaleString();
      eventRow.querySelector('[data-event-remaining]').textContent=Math.max(0,Number(next.eligible)-Number(next.present)-Number(next.exempted||0)).toLocaleString();
      eventRow.querySelector('[data-event-remaining-label]').textContent=next.attendance_status==='CLOSED'?'absent':'pending';
      const status=eventRow.querySelector('[data-event-attendance-status]');status.dataset.eventAttendanceStatus=next.attendance_status;
      const label=eventRow.querySelector('[data-event-attendance-label]');if(label)label.textContent=next.attendance_status==='CLOSED'?'Closed':next.attendance_status==='OPEN'?'Open':'Not started';
      const actionLabel=eventRow.querySelector('[data-event-attendance-action-label]');
      if(actionLabel){actionLabel.textContent=next.attendance_status==='CLOSED'?'View attendance':'Take attendance';actionLabel.previousElementSibling.classList.toggle('bx-list-check',next.attendance_status==='CLOSED');actionLabel.previousElementSibling.classList.toggle('bx-qr-scan',next.attendance_status!=='CLOSED');}
    }
    ['eligible','present','absent','exempted'].forEach(key=>$(('attendance-'+key)).textContent=next.attendance_status==='OPEN' && key==='absent'?'Pending':next[key]??0);
    $('attendance-absent').dataset.finalized=next.attendance_status==='CLOSED'?'true':'false';
    const closed=next.attendance_status==='CLOSED',scanDisabled=next.attendance_status!=='OPEN'||!canScan;$('attendance-camera-start').disabled=scanDisabled;$('attendance-upload').disabled=scanDisabled;$('attendance-camera-device').disabled=scanDisabled;
    $('attendance-upload-label').setAttribute('aria-disabled',String(scanDisabled));$('attendance-upload-label').tabIndex=scanDisabled?-1:0;
    $('attendance-code').disabled=scanDisabled;$('attendance-code-submit').disabled=scanDisabled;
    $('attendance-event-context').textContent=`${next.event_date} · ${config.term_labels[config.term] || config.current_term_text}` +
      (next.flag_raising ? '' : ` · ${eventExamTerm==='FINAL'?'Final':'Midterm'}`);
    $('attendance-confirm').checked=false;$('attendance-state-change').disabled=true;$('attendance-state-change').textContent=closed?'Reopen attendance':'Close attendance';
    stateControl.hidden=!canManage || !currentView || Boolean(next.two_checkpoints) || next.active===false;
    $('attendance-state-label').textContent=closed?'Reopen attendance':'Close attendance';
    $('attendance-times').hidden=false;
    $('attendance-opened-at').textContent=formatTime(next.opened_at);
    $('attendance-closed-at').textContent=formatTime(next.closed_at);
    reader.hidden=next.attendance_status!=='OPEN';
    roster.hidden=!closed;
    dialog.classList.toggle('modal-xl',closed);
    dialog.classList.toggle('modal-lg',!closed);
    roster.querySelectorAll('[data-attendance-checkpoint]').forEach(header => { header.hidden = !next.two_checkpoints; });
    roster.querySelector('[data-roster-edit]').hidden = !canManage;
    if (closed && !previouslyClosed) loadRoster(1);
    if (!closed) correction.hidden=true;
    const amount=new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(next.absence_fine));
    const pending=Math.max(0,Number(next.eligible)-Number(next.present)-Number(next.exempted||0));
    $('attendance-close-description').textContent=closed
      ? (next.flag_raising ? 'Reopening returns absences to pending.' : 'Reopening returns absences to pending and pauses absence fines.')
      : `Closing attendance marks ${pending.toLocaleString()} roster students absent.` + (next.flag_raising ? '' : ` The fine per absence is ${amount}.`);
    $('attendance-confirm-label').textContent=closed?'Reopen this event for attendance corrections.':'Confirm the remaining roster students are absent.';
    const list=$('attendance-recent');list.replaceChildren();
    if(!next.recent.length){const li=document.createElement('li');li.className='list-group-item small text-muted';li.textContent='No attendance recorded yet.';list.append(li);}
    for(const row of next.recent){const li=document.createElement('li');li.className='list-group-item';const name=document.createElement('strong');name.textContent=row.student_name;const details=document.createElement('small');details.className='d-block text-muted';details.textContent=`${row.student_number} · ${row.recorded_at}`;li.append(name,details);list.append(li);}
    if(closed)stopCamera();
  }
  async function record(code) {
    if(busy || !canScan || !state || state.attendance_status!=='OPEN')return false;
    if(code===lastCode && Date.now()-lastAt<2500)return;
    lastCode=code;lastAt=Date.now();busy=true;error('');$('attendance-result').hidden=true;$('attendance-ready-note').hidden=false;const run=generation;
    $('attendance-ready-note').textContent='Saving attendance…';
    try {
      const data=await request('attendance_scan',{qr_code:code});if(run!==generation)return false;
      render(data.attendance);$('attendance-result-status').textContent=data.duplicate?'Already recorded':'Attendance recorded';
      $('attendance-student-name').textContent=data.student.student_name;$('attendance-student-number').textContent=data.student.student_number;
      $('attendance-recorded-at').textContent=data.student.recorded_at?`Recorded: ${data.student.recorded_at}`:'';
      const photo=$('attendance-student-photo');photo.alt=data.student.student_name;photo.onerror=()=>{photo.onerror=null;photo.src='../student/profile-placeholder.svg';};photo.src=data.student.photo_url || '../student/profile-placeholder.svg';
      $('attendance-result').classList.toggle('is-duplicate',Boolean(data.duplicate));$('attendance-result').hidden=false;$('attendance-ready-note').hidden=true;
      $('attendance-code').value='';if($('attendance-hardware').open)$('attendance-code').focus();return true;
    }catch(e){if(run===generation){error(e.message);$('attendance-ready-note').textContent='Attendance was not confirmed. Scan again to retry.';}return false;}
    finally{if(run===generation)busy=false;}
  }
  async function frame(cameraRun) {
    if(!scanning || cameraRun!==cameraGeneration)return;
    try {
      if(video.readyState>=2 && video.videoWidth>0 && video.videoHeight>0 && !busy){const scale=Math.min(1,1000/Math.max(video.videoWidth,video.videoHeight));canvas.width=Math.round(video.videoWidth*scale);canvas.height=Math.round(video.videoHeight*scale);context.drawImage(video,0,0,canvas.width,canvas.height);const pixels=context.getImageData(0,0,canvas.width,canvas.height);const qr=jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'attemptBoth'});if(!qr)cameraCode='';else if(qr.data!==cameraCode){const saved=await record(qr.data);if(cameraRun!==cameraGeneration)return;if(saved)cameraCode=qr.data;}}
    }catch(e){if(cameraRun===cameraGeneration){stopCamera();error('The camera could not read this frame. Restart the camera or read a QR image.');}return;}
    if(scanning && cameraRun===cameraGeneration)timer=setTimeout(()=>frame(cameraRun),220);
  }
  async function startCamera() {
    stopCamera();if(!canScan || !state || state.attendance_status!=='OPEN')return;const run=generation,cameraRun=cameraGeneration;
    if(!element.classList.contains('show')) { element.addEventListener('shown.bs.modal',()=>{if(run===generation && cameraRun===cameraGeneration)startCamera();},{once:true});return; }
    error('');$('attendance-camera-stop').disabled=false;cameraStatus('Waiting for camera permission…');
    try {
      if(!navigator.mediaDevices?.getUserMedia)throw Error('Camera access requires HTTPS or localhost. Read a QR image or use a connected scanner instead.');
      const media=await navigator.mediaDevices.getUserMedia({video:cameraDevice?{deviceId:{exact:cameraDevice}}:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},audio:false});
      if(run!==generation || cameraRun!==cameraGeneration || !element.classList.contains('show')){media.getTracks().forEach(track=>track.stop());return;}
      stream=media;video.srcObject=stream;await video.play();
      if(run!==generation || cameraRun!==cameraGeneration || !element.classList.contains('show')){media.getTracks().forEach(track=>track.stop());return;}
      video.hidden=false;$('attendance-camera-placeholder').hidden=true;$('attendance-camera-stop').disabled=false;scanning=true;cameraStatus('Ready to scan · Show the entire student QR');frame(cameraRun);
      updateCameraChoices(media,cameraRun);
    }catch(e){if(run===generation && cameraRun===cameraGeneration){stopCamera();const messages={NotAllowedError:'Camera permission was denied. Allow camera access in your browser, then press Start camera.',NotFoundError:'No camera was found. Connect a camera, read a QR image, or use a connected scanner.',NotReadableError:'The camera is in use by another application. Close it there and press Start camera.',OverconstrainedError:'This camera is no longer available. Select another camera and try again.'};error(messages[e.name] || e.message || 'Allow camera access to scan attendance.');}}
  }
  async function updateCameraChoices(media,cameraRun) {
    if(!navigator.mediaDevices?.enumerateDevices)return;
    try {
      const devices=(await navigator.mediaDevices.enumerateDevices()).filter(device=>device.kind==='videoinput');if(cameraRun!==cameraGeneration)return;
      const selected=media.getVideoTracks()[0]?.getSettings?.().deviceId || cameraDevice;
      const picker=$('attendance-camera-device');picker.replaceChildren();
      devices.forEach((device,index)=>{const option=document.createElement('option');option.value=device.deviceId;option.textContent=device.label || `Camera ${index+1}`;picker.append(option);});
      if(devices.some(device=>device.deviceId===selected))picker.value=selected;
      $('attendance-camera-picker').hidden=devices.length<2;
    }catch(e){/* Scanning continues when device enumeration is unavailable. */}
  }
  function closeCorrection(restoreFocus = false) {
    if (restoreFocus && editing) rosterRows.querySelector(`[data-correct-attendance="${editing.studentId}"]`)?.focus();
    editing = null;
    correction.hidden = true;
  }
  function openCorrection(student) {
    editing = {studentId:student.student_id, revision:rosterRevision};
    correction.reset();
    $('attendance-roster-notice').hidden = true;
    $('attendance-correction-student').textContent = `${student.student_name} · ${student.student_number}`;
    $('attendance-correction-checkpoints').hidden = !state.two_checkpoints;
    $('attendance-correction-checkpoint-help').hidden = !state.two_checkpoints;
    $('attendance-correction-status-field').hidden = Boolean(state.two_checkpoints);
    $('attendance-correction-in').checked = Boolean(student.checked_in);
    $('attendance-correction-out').checked = Boolean(student.checked_out);
    $('attendance-correction-status').value = student.attendance_status === 'PRESENT' ? 'PRESENT' : 'ABSENT';
    correction.hidden = false;
    (state.two_checkpoints ? $('attendance-correction-in') : $('attendance-correction-status')).focus();
  }
  rosterFilter.addEventListener('submit', event => {
    event.preventDefault();
    if (busy) return;
    closeCorrection();
    loadRoster(1);
  });
  $('attendance-roster-status').addEventListener('change', () => rosterFilter.requestSubmit());
  for (const [id, step] of [['attendance-roster-previous', -1], ['attendance-roster-next', 1]]) {
    $(id).addEventListener('click', () => {
      if (busy) return;
      closeCorrection();
      loadRoster(rosterPage + step);
    });
  }
  rosterRows.addEventListener('click', event => {
    const button = event.target.closest('[data-correct-attendance]');
    if (!button || busy || !canManage) return;
    const student = rosterStudents.get(Number(button.dataset.correctAttendance));
    if (student) openCorrection(student);
  });
  $('attendance-correction-cancel').addEventListener('click', () => { if (!busy) closeCorrection(true); });
  correction.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !editing || state?.attendance_status !== 'CLOSED') return;
    const values = {student_id:editing.studentId, attendance_revision:editing.revision, reason:$('attendance-correction-reason').value};
    if (state.two_checkpoints) {
      values.checked_in = Number($('attendance-correction-in').checked);
      values.checked_out = Number($('attendance-correction-out').checked);
    } else {
      values.corrected_status = $('attendance-correction-status').value;
    }
    busy = true;
    $('attendance-correction-save').disabled = true;
    error('');
    const run = generation;
    try {
      const data = await request('attendance_correct', values);
      if (run !== generation) return;
      closeCorrection();
      render(data.attendance);
      $('attendance-roster-notice').textContent = data.corrected ? 'Attendance correction saved.' : 'Attendance already matches this selection.';
      $('attendance-roster-notice').hidden = false;
      await loadRoster(rosterPage);
      $('attendance-roster-search').focus();
    } catch (failure) {
      if (run === generation) {
        error(failure.message);
        $('attendance-error').scrollIntoView({block:'nearest'});
      }
    } finally {
      if (run === generation) {
        busy = false;
        $('attendance-correction-save').disabled = false;
      }
    }
  });
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-attendance]');if(!button || button.disabled)return;
    eventExamTerm=button.closest('[data-event-row]')?.dataset.eventExamTerm || config.exam_term;
    opener=button;stopCamera();const run=++generation;eventId=Number(button.dataset.attendance);state=null;busy=false;lastCode='';error('');$('attendance-result').hidden=true;$('attendance-ready-note').hidden=!currentView;$('attendance-ready-note').textContent='The scanned student’s photo and details will appear here after attendance is saved.';$('attendance-event-context').textContent='';$('attendance-title').textContent='Loading attendance…';$('attendance-recent').replaceChildren();
    $('attendance-status').textContent='Loading…';['eligible','present','absent','exempted'].forEach(key=>$('attendance-'+key).textContent='—');
    const closedRow = button.closest('[data-event-row]')?.querySelector('[data-event-attendance-status]')?.dataset.eventAttendanceStatus === 'CLOSED';
    dialog.classList.toggle('modal-xl',closedRow);
    dialog.classList.toggle('modal-lg',!closedRow);
    roster.hidden = reader.hidden = stateControl.hidden = $('attendance-times').hidden = true;
    $('attendance-roster-notice').hidden = true;
    $('attendance-roster-page').textContent = '';
    rosterFilter.reset();
    closeCorrection();
    $('attendance-correction-save').disabled = false;
    $('attendance-confirm').checked=false;$('attendance-state-change').disabled=true;$('attendance-camera-start').disabled=true;$('attendance-upload').disabled=true;$('attendance-code').disabled=true;$('attendance-code-submit').disabled=true;modal.show();
    stateControl.open=button.dataset.attendanceMode==='manage';
    try{let data=await request('attendance_get');if(run!==generation)return;if(data.attendance.attendance_status==='NOT_STARTED'){if(!canManage)throw Error('Your faculty manager or an authorized event manager must open attendance before you can scan.');data=await request('attendance_open');}if(run!==generation)return;render(data.attendance);if(canScan && !closedRow && state.attendance_status==='OPEN' && button.dataset.attendanceMode!=='manage')startCamera();}
    catch(e){if(run===generation)error(e.message);}
  });
  $('attendance-camera-start').addEventListener('click',startCamera);$('attendance-camera-stop').addEventListener('click',stopCamera);
  $('attendance-upload-label').addEventListener('keydown',event=>{if(event.key==='Enter' || event.key===' '){event.preventDefault();if(!$('attendance-upload').disabled)$('attendance-upload').click();}});
  $('attendance-camera-device').addEventListener('change',()=>{cameraDevice=$('attendance-camera-device').value;startCamera();});
  $('attendance-code-form').addEventListener('submit',event=>{event.preventDefault();record($('attendance-code').value.trim());});
  $('attendance-hardware').addEventListener('toggle',()=>{if($('attendance-hardware').open && !$('attendance-code').disabled)$('attendance-code').focus();});
  $('attendance-upload').addEventListener('change',async event=>{
    const file=event.target.files[0];if(!file)return;const run=generation;
    try{if(file.size>10*1024*1024)throw Error('Choose a QR image no larger than 10 MB.');const bitmap=await createImageBitmap(file);const imageCanvas=document.createElement('canvas'),scale=Math.min(1,1600/Math.max(bitmap.width,bitmap.height));imageCanvas.width=Math.round(bitmap.width*scale);imageCanvas.height=Math.round(bitmap.height*scale);const imageContext=imageCanvas.getContext('2d');imageContext.drawImage(bitmap,0,0,imageCanvas.width,imageCanvas.height);bitmap.close();const pixels=imageContext.getImageData(0,0,imageCanvas.width,imageCanvas.height),qr=jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'attemptBoth'});if(!qr)throw Error('No readable QR code was found. Try a clearer image.');if(run===generation)await record(qr.data);}
    catch(e){if(run===generation)error(e.message);}finally{event.target.value='';}
  });
  $('attendance-confirm').addEventListener('change',()=>{$('attendance-state-change').disabled=!$('attendance-confirm').checked || busy || !state;});
  $('attendance-state-change').addEventListener('click',async()=>{
    if(!state || busy || !$('attendance-confirm').checked)return;busy=true;$('attendance-state-change').disabled=true;error('');const run=generation;
    try{const data=await request(state.attendance_status==='CLOSED'?'attendance_reopen':'attendance_close',{attendance_revision:state.revision,confirm:'1'});if(run===generation)render(data.attendance);}
    catch(e){if(run===generation)error(e.message);}finally{if(run===generation){busy=false;$('attendance-state-change').disabled=!$('attendance-confirm').checked;}}
  });
  element.addEventListener('hide.bs.modal',()=>{generation++;stopCamera();});element.addEventListener('hidden.bs.modal',()=>opener?.focus());
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stopCamera();});
  window.addEventListener('pagehide',stopCamera);
})();
