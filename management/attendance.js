'use strict';
(() => {
  const element=document.getElementById('management-attendance');if(!element)return;
  const config=JSON.parse(document.getElementById('management-config').textContent),modal=bootstrap.Modal.getOrCreateInstance(element);
  const $=id=>document.getElementById(id),video=$('attendance-video'),canvas=document.createElement('canvas'),context=canvas.getContext('2d',{willReadFrequently:true});
  let state=null,eventId=0,generation=0,cameraGeneration=0,stream=null,timer=null,scanning=false,busy=false,opener=null,lastCode='',lastAt=0,cameraCode='',cameraDevice='';
  const error=message=>{$('attendance-error').textContent=message;$('attendance-error').hidden=!message;};
  const cameraStatus=message=>{$('attendance-camera-status').textContent=message;};
  async function request(action,values={}) {
    const body=new URLSearchParams({action,event_id:eventId,csrf_token:config.csrf_token,college_id:config.college_id,campus_id:config.campus_id,organization_id:config.organization_id,
      term:config.term,exam_term:config.exam_term,current_term:config.current_term,current_exam_term:config.current_exam_term,current_period_version:config.current_period_version,...values});
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
    try {
      const response=await fetch(config.endpoint,{method:'POST',body,credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok || data.status!=='ok')throw Error(data.message || 'Attendance request failed.');return data;
    }catch(e){if(e.name==='AbortError' || e instanceof TypeError)throw Error('Connection interrupted. Scan this student again to confirm attendance; a repeated scan will not create a duplicate.');throw e;}
    finally{clearTimeout(timeout);}
  }
  function stopCamera() {
    cameraGeneration++;
    clearTimeout(timer);scanning=false;cameraCode='';if(stream)stream.getTracks().forEach(track=>track.stop());stream=null;video.srcObject=null;video.hidden=true;
    $('attendance-camera-placeholder').hidden=false;$('attendance-camera-stop').disabled=true;
    cameraStatus(state?.attendance_status==='CLOSED'?'Attendance closed':'Camera stopped');
  }
  function render(next) {
    state=next;$('attendance-title').textContent=next.title;$('attendance-status').textContent=next.attendance_status==='OPEN'?'Open':next.attendance_status==='CLOSED'?'Closed':'Not started';
    const eventRow=document.querySelector(`[data-event-row="${Number(next.event_id)}"]`);
    if(eventRow){
      const exempted=eventRow.querySelector('[data-event-exempted]');if(exempted)exempted.textContent=String(next.exempted||0)+' exempted';
      eventRow.querySelector('[data-event-present]').textContent=Number(next.present).toLocaleString();
      eventRow.querySelector('[data-event-remaining]').textContent=Math.max(0,Number(next.eligible)-Number(next.present)-Number(next.exempted||0)).toLocaleString();
      const status=eventRow.querySelector('[data-event-attendance-status]');status.dataset.eventAttendanceStatus=next.attendance_status;
      const label=eventRow.querySelector('[data-event-attendance-label]');if(label)label.textContent=next.attendance_status==='CLOSED'?'Closed':next.attendance_status==='OPEN'?'Open':'Not started';
      const actionLabel=eventRow.querySelector('[data-event-attendance-action-label]');if(actionLabel)actionLabel.textContent=next.attendance_status==='CLOSED'?'View attendance':'Take attendance';
    }
    ['eligible','present','absent','exempted'].forEach(key=>$(('attendance-'+key)).textContent=next.attendance_status==='OPEN' && key==='absent'?'Pending':next[key]??0);
    $('attendance-absent').dataset.finalized=next.attendance_status==='CLOSED'?'true':'false';
    const closed=next.attendance_status==='CLOSED',scanDisabled=next.attendance_status!=='OPEN'||!config.permissions?.attendance;$('attendance-camera-start').disabled=scanDisabled;$('attendance-upload').disabled=scanDisabled;$('attendance-camera-device').disabled=scanDisabled;
    $('attendance-upload-label').setAttribute('aria-disabled',String(scanDisabled));$('attendance-upload-label').tabIndex=scanDisabled?-1:0;
    $('attendance-code').disabled=scanDisabled;$('attendance-code-submit').disabled=scanDisabled;
    $('attendance-event-context').textContent=`${next.event_date} · ${config.current_term_text} · ${config.current_exam_term==='FINAL'?'Final':'Midterm'}`;
    $('attendance-confirm').checked=false;$('attendance-state-change').disabled=true;$('attendance-state-change').textContent=closed?'Reopen attendance':'Close attendance';
    const amount=new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(Number(next.absence_fine));
    $('attendance-close-description').textContent=closed?'Reopening pauses absence fines until attendance is closed again. The roster and existing scans are preserved.':`Closing attendance applies a ${amount} absence fine to each roster student without a scan or an exemption. Future scans will be blocked until attendance is reopened.`;
    $('attendance-confirm-label').textContent=closed?'Reopen this event for attendance corrections.':'Attendance is complete; apply absence fines to students without attendance or an exemption.';
    const list=$('attendance-recent');list.replaceChildren();
    if(!next.recent.length){const li=document.createElement('li');li.className='list-group-item small text-muted';li.textContent='No scans recorded yet.';list.append(li);}
    for(const row of next.recent){const li=document.createElement('li');li.className='list-group-item';const name=document.createElement('strong');name.textContent=row.student_name;const details=document.createElement('small');details.className='d-block text-muted';details.textContent=`${row.student_number} · ${row.recorded_at}`;li.append(name,details);list.append(li);}
    if(closed)stopCamera();
  }
  async function record(code) {
    if(busy || !config.permissions?.attendance || !state || state.attendance_status!=='OPEN')return false;
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
    stopCamera();if(!config.permissions?.attendance || !state || state.attendance_status!=='OPEN')return;const run=generation,cameraRun=cameraGeneration;
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
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-attendance]');if(!button || button.disabled)return;
    opener=button;stopCamera();const run=++generation;eventId=Number(button.dataset.attendance);state=null;busy=false;lastCode='';error('');$('attendance-result').hidden=true;$('attendance-ready-note').hidden=false;$('attendance-ready-note').textContent='The scanned student’s photo and details will appear here after attendance is saved.';$('attendance-event-context').textContent='';$('attendance-title').textContent='Loading attendance…';$('attendance-recent').replaceChildren();
    $('attendance-status').textContent='Loading…';['eligible','present','absent','exempted'].forEach(key=>$('attendance-'+key).textContent='—');
    $('attendance-confirm').checked=false;$('attendance-state-change').disabled=true;$('attendance-camera-start').disabled=true;$('attendance-upload').disabled=true;$('attendance-code').disabled=true;$('attendance-code-submit').disabled=true;modal.show();
    element.querySelector('.management-attendance-close').open=button.dataset.attendanceMode==='manage';
    try{element.querySelector('.management-attendance-close').hidden=!config.permissions?.manage_events;let data=await request('attendance_get');if(run!==generation)return;if(data.attendance.attendance_status==='NOT_STARTED'){if(!config.permissions?.manage_events)throw Error('Your faculty manager or an authorized event manager must open attendance before you can scan.');data=await request('attendance_open');}if(run!==generation)return;render(data.attendance);if(config.permissions?.attendance && state.attendance_status==='OPEN' && button.dataset.attendanceMode!=='manage')startCamera();}
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
