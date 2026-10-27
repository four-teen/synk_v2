'use strict';
(() => {
  const element = document.getElementById('studentProfilePhotoModal');
  if (!element) return;
  const scriptUrl = document.currentScript.src;
  const form = document.getElementById('student-photo-form');
  const preview = document.getElementById('student-photo-preview');
  const video = document.getElementById('student-photo-video');
  const guide = document.getElementById('student-camera-guide');
  const status = document.getElementById('student-camera-status');
  const error = document.getElementById('student-photo-error');
  const open = document.getElementById('student-camera-open');
  const capture = document.getElementById('student-camera-capture');
  const retake = document.getElementById('student-camera-retake');
  const save = document.getElementById('student-photo-save');
  const original = preview.src, required = element.dataset.required === '1';
  const modal = bootstrap.Modal.getOrCreateInstance(element, {backdrop: required ? 'static' : true, keyboard: !required});
  const saveLabel = save.textContent;
  let stream, worker, jobId = 0, generation = 0, busy = false, saving = false;
  let photo = null, report = null, token = '', photoUrl = '';
  const jobs = new Map();

  function message(text) { error.textContent = text; error.hidden = !text; }
  function stopCamera() {
    if (stream) stream.getTracks().forEach(track => track.stop());
    stream = null; video.srcObject = null;
    video.hidden = true; guide.hidden = true; preview.hidden = false;
    capture.disabled = true; capture.hidden = true;
  }
  function stopWorker() {
    if (worker) worker.terminate();
    worker = null;
    jobs.forEach(job => { clearTimeout(job.timeout); job.reject(new Error('Photo checks stopped. Reopen the camera and try again.')); });
    jobs.clear();
  }
  function discardPhoto() {
    photo = report = null; token = ''; save.disabled = true;
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = ''; preview.src = original; retake.hidden = true;
  }
  function detect(image) {
    if (!worker) {
      const url = new URL('profile-photo-worker.js', scriptUrl);
      url.search = new URL(scriptUrl).search;
      worker = new Worker(url);
      worker.onmessage = event => {
        const job = jobs.get(event.data.id);
        if (!job) return;
        clearTimeout(job.timeout); jobs.delete(event.data.id);
        if (event.data.error) job.reject(new Error(event.data.error));
        else job.resolve(event.data.result);
      };
      worker.onerror = () => stopWorker();
    }
    return new Promise((resolve, reject) => {
      const id = ++jobId;
      jobs.set(id, {resolve, reject, timeout: setTimeout(stopWorker, 45000)});
      try { worker.postMessage({id, image}, image ? [image] : []); }
      catch (failure) { if (image) image.close(); stopWorker(); }
    });
  }
  async function request(data) {
    const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', headers: {Accept: 'application/json'}});
    if (response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) {
      throw new Error('Your session may have expired. Reload this page and sign in again.');
    }
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Your photo could not be saved. Please try again.');
    return result;
  }
  function cameraError(failure) {
    if (['NotAllowedError', 'SecurityError'].includes(failure.name)) return 'Camera access was blocked. Allow camera access in your browser, then try again. You can also use your phone or ask your college office for assisted capture.';
    if (['NotFoundError', 'OverconstrainedError'].includes(failure.name)) return 'No suitable camera was found. Use a phone or a computer with a webcam, or ask your college office for assisted capture.';
    if (failure.name === 'NotReadableError') return 'Your camera is busy or unavailable. Close other camera apps and try again.';
    return failure.message || 'The camera could not start. Reopen it and try again.';
  }
  async function start() {
    if (busy || saving) return;
    const current = ++generation;
    busy = true; message(''); discardPhoto(); stopCamera();
    open.disabled = true; open.hidden = false;
    status.textContent = 'Allow camera access, then wait for the photo checks to load.';
    try {
      if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) throw new Error('Open synQ using HTTPS and a browser that supports camera access, or ask your college office for assisted capture.');
      const media = await navigator.mediaDevices.getUserMedia({audio: false, video: {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 720}}});
      if (current !== generation) { media.getTracks().forEach(track => track.stop()); return; }
      stream = media; video.srcObject = media; preview.hidden = true; video.hidden = false; guide.hidden = false;
      const ready = new Promise((resolve, reject) => {
        const timer = setTimeout(() => { video.removeEventListener('loadeddata', loaded); reject(new Error('The camera did not provide a picture. Reopen it and try again.')); }, 15000);
        function loaded() { clearTimeout(timer); resolve(); }
        if (video.readyState >= 2) loaded(); else video.addEventListener('loadeddata', loaded, {once: true});
      });
      await Promise.all([video.play(), ready]);
      const data = new FormData(form); data.set('photo_action', 'start');
      const challenge = await request(data);
      if (current !== generation) return;
      token = challenge.capture_token;
      await detect();
      if (current !== generation) return;
      media.getVideoTracks()[0].addEventListener('ended', () => {
        if (stream === media) { stopCamera(); open.hidden = false; status.textContent = 'Camera disconnected. Reopen your camera to take a photo.'; }
      }, {once: true});
      open.hidden = true; capture.hidden = false; capture.disabled = false;
      status.textContent = 'Center your whole face inside the guide, then take your photo. Any background is allowed.';
    } catch (failure) {
      if (current === generation) { stopCamera(); stopWorker(); message(cameraError(failure)); status.textContent = 'Camera capture is required to save a new photo.'; }
    } finally { if (current === generation) { busy = false; open.disabled = false; } }
  }
  function checkFace(result) {
    if (result.face_count !== 1) throw new Error(result.face_count ? 'Only one person should be visible. Remove other people or face pictures from the frame and retake.' : 'No clear face was found. Face the camera, improve the lighting and try again.');
    const f = result.face, p = f.keypoints;
    if (f.width < .20 || f.height < .20) throw new Error('Move closer so your face fills the guide.');
    if (f.width > .65 || f.height > .68 || f.x < .05 || f.y < .05 || f.x + f.width > .95 || f.y + f.height > .85 || Math.abs(f.x + f.width / 2 - .5) > .18) throw new Error('Center your whole face in the guide and leave space above your head.');
    const span = Math.abs(p[0].x - p[1].x);
    if (span < f.width * .18 || Math.abs(p[0].y - p[1].y) > f.width * .18 || Math.abs(p[2].x - (p[0].x + p[1].x) / 2) > span * .45) throw new Error('Look straight at the camera and keep your head level.');
  }
  function checkQuality(canvas, face) {
    const sample = document.createElement('canvas'); sample.width = sample.height = 160;
    const context = sample.getContext('2d', {willReadFrequently: true}); context.drawImage(canvas, 0, 0, 160, 160);
    const pixels = context.getImageData(0, 0, 160, 160).data, grey = [], lights = [], laps = [];
    for (let y = 0; y < 160; y++) for (let x = 0; x < 160; x++) {
      const i = (y * 160 + x) * 4; grey[y * 160 + x] = .299 * pixels[i] + .587 * pixels[i + 1] + .114 * pixels[i + 2];
    }
    const left = Math.max(1, Math.floor((face.x + face.width * .12) * 160)), right = Math.min(158, Math.floor((face.x + face.width * .88) * 160));
    const top = Math.max(1, Math.floor((face.y + face.height * .12) * 160)), bottom = Math.min(158, Math.floor((face.y + face.height * .88) * 160));
    for (let y = top; y <= bottom; y++) for (let x = left; x <= right; x++) {
      const i = y * 160 + x; lights.push(grey[i]); laps.push(grey[i - 160] + grey[i + 160] + grey[i - 1] + grey[i + 1] - 4 * grey[i]);
    }
    const mean = values => values.reduce((a, b) => a + b, 0) / values.length;
    const variance = values => { const m = mean(values); return mean(values.map(v => (v - m) ** 2)); };
    if (mean(lights) < 45 || mean(lights) > 235) throw new Error('Use even lighting so your face is clearly visible, then retake the photo.');
    if (variance(laps) < 22) throw new Error('Your photo looks blurred. Clean the camera, hold still and retake it.');
  }
  capture.addEventListener('click', async () => {
    if (busy || saving || !stream) return;
    const current = generation;
    busy = true; capture.disabled = true; message(''); status.textContent = 'Checking that your face is clear and inside the frame…';
    try {
      const size = Math.min(video.videoWidth, video.videoHeight);
      if (size < 480) throw new Error('This camera resolution is too low. Use a camera with at least 480 by 480 pixels.');
      const canvas = document.createElement('canvas'); canvas.width = canvas.height = Math.min(720, size);
      canvas.getContext('2d').drawImage(video, (video.videoWidth - size) / 2, (video.videoHeight - size) / 2, size, size, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .9));
      if (!blob) throw new Error('The camera picture could not be prepared. Please retake it.');
      // Check the exact encoded frame that will be uploaded, not a later video frame.
      const image = await createImageBitmap(blob);
      const result = await detect(image);
      if (current !== generation) return;
      checkFace(result);
      const encoded = await createImageBitmap(blob);
      try { canvas.getContext('2d').drawImage(encoded, 0, 0); } finally { encoded.close(); }
      checkQuality(canvas, result.face);
      const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
      if (current !== generation) return;
      photo = blob; report = {...result, sha256: Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('')};
      stopCamera(); photoUrl = URL.createObjectURL(blob); preview.src = photoUrl;
      retake.hidden = false; save.disabled = false; open.hidden = true;
      status.textContent = 'Photo checks passed. Review your photo, then save it or retake it.';
    } catch (failure) {
      if (current === generation) { message(failure.message); status.textContent = 'Keep your face clearly visible inside the guide, then take another photo.'; }
    } finally { if (current === generation) { busy = false; capture.disabled = !stream; } }
  });
  open.addEventListener('click', start); retake.addEventListener('click', start);
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (saving || busy || !photo || !report) return;
    saving = true; save.disabled = true; retake.disabled = true; message(''); save.textContent = 'Saving photo…';
    try {
      const data = new FormData(form); data.set('profile_photo', photo, 'camera-capture.jpg');
      data.set('capture_token', token); data.set('capture_report', JSON.stringify(report));
      const result = await request(data);
      status.textContent = 'Photo saved. Confirming that it displays correctly…';
      await new Promise((resolve, reject) => {
        const image = new Image(), timeout = setTimeout(() => reject(new Error('Your photo was saved, but could not be displayed. Reload this page or ask your college office for help.')), 15000);
        image.onload = () => { clearTimeout(timeout); resolve(); };
        image.onerror = () => { clearTimeout(timeout); reject(new Error('Your photo was saved, but could not be displayed. Reload this page or ask your college office for help.')); };
        image.src = result.photo_url;
      });
      window.location.assign(result.redirect);
    } catch (failure) { message(failure.message); save.disabled = false; }
    finally { saving = false; retake.disabled = false; save.textContent = saveLabel; }
  });
  element.addEventListener('hide.bs.modal', event => { if (required || saving) event.preventDefault(); });
  element.addEventListener('hidden.bs.modal', () => {
    generation++; busy = false; stopCamera(); stopWorker(); discardPhoto(); message(''); open.hidden = false; open.disabled = false;
    status.textContent = 'Open your camera to take a new photo.';
  });
  function leave() { generation++; busy = false; stopCamera(); stopWorker(); open.hidden = false; open.disabled = false; }
  window.addEventListener('pagehide', () => { leave(); if (photoUrl) URL.revokeObjectURL(photoUrl); });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && !saving) { leave(); if (!photo) status.textContent = 'Camera paused. Reopen it when you are ready.'; }
  });
  window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
  if (element.dataset.open === '1') modal.show();
})();
