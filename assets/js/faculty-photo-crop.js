(function () {
  'use strict';
  const scriptUrl = document.currentScript.src;
  const form = document.getElementById('faculty-profile-form');
  const input = document.getElementById('profile_image');
  const template = document.getElementById('faculty-photo-crop-template');
  if (!form || !input || !template) return;

  const editor = template.content.firstElementChild.cloneNode(true);
  input.closest('.profile-photo-editor').after(editor);
  const cropInput = editor.querySelector('input[type="hidden"]');
  const canvas = editor.querySelector('canvas');
  const context = canvas.getContext('2d');
  if (!context) return;
  const thumbnail = document.createElement('canvas');
  thumbnail.width = thumbnail.height = 112;
  const thumbnailContext = thumbnail.getContext('2d');
  const zoom = editor.querySelector('#faculty-photo-zoom');
  const horizontal = editor.querySelector('#faculty-photo-horizontal');
  const vertical = editor.querySelector('#faculty-photo-vertical');
  const original = editor.querySelector('#faculty-photo-original');
  const auto = editor.querySelector('#faculty-photo-auto');
  const reset = editor.querySelector('#faculty-photo-reset');
  const status = editor.querySelector('#faculty-photo-crop-status');
  const selection = document.getElementById('profile-image-selection');
  const previews = [document.getElementById('profile-image-preview'), document.querySelector('#profile-summary-image img')].filter(Boolean);
  const initials = [document.getElementById('profile-image-initials'), document.querySelector('#profile-summary-image span')].filter(Boolean);
  let image = null;
  let photoUrl = null;
  let crop = null;
  let fileVersion = 0;
  let editVersion = 0;
  let drag = null;
  let worker = null;
  let jobId = 0;
  const jobs = new Map();

  function clamp(value, minimum, maximum) { return Math.max(minimum, Math.min(maximum, value)); }

  function centerCrop() {
    const size = Math.min(image.naturalWidth, image.naturalHeight);
    return {x: (image.naturalWidth - size) / 2, y: (image.naturalHeight - size) / 2, size: size};
  }

  function render() {
    if (!image || !crop) return;
    const width = image.naturalWidth;
    const height = image.naturalHeight;
    crop.size = clamp(crop.size, 1, Math.min(width, height));
    crop.x = clamp(crop.x, 0, width - crop.size);
    crop.y = clamp(crop.y, 0, height - crop.size);
    zoom.max = String(Math.max(Number(zoom.max), 8, Math.ceil(Math.min(width, height) / crop.size)));
    zoom.value = String(Math.min(width, height) / crop.size);
    horizontal.value = String(width === crop.size ? 50 : 100 * crop.x / (width - crop.size));
    vertical.value = String(height === crop.size ? 50 : 100 * crop.y / (height - crop.size));
    [zoom, horizontal, vertical, reset].forEach(control => { control.disabled = original.checked; });
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    if (original.checked) {
      cropInput.value = '';
      const scale = canvas.width / Math.max(width, height);
      context.drawImage(image, (canvas.width - width * scale) / 2, (canvas.height - height * scale) / 2, width * scale, height * scale);
    } else {
      cropInput.value = JSON.stringify({x: crop.x / width, y: crop.y / height, width: crop.size / width, height: crop.size / height});
      context.drawImage(image, crop.x, crop.y, crop.size, crop.size, 0, 0, canvas.width, canvas.height);
    }
    thumbnailContext.drawImage(canvas, 0, 0, thumbnail.width, thumbnail.height);
    const previewUrl = original.checked ? photoUrl : thumbnail.toDataURL('image/jpeg', .85);
    previews.forEach(preview => { preview.src = previewUrl; preview.hidden = false; });
    initials.forEach(initial => { initial.hidden = true; });
    selection.textContent = original.checked ? 'Full photo selected. Save your profile to apply it.' : 'Photo crop selected. Save your profile to apply it.';
  }

  function adjusted() {
    editVersion++;
    input.setCustomValidity('');
    status.textContent = original.checked ? 'The full photo will be saved.' : 'Crop adjusted. Review the preview before saving.';
    render();
  }

  function stopWorker() {
    if (worker) worker.terminate();
    worker = null;
    jobs.forEach(job => { clearTimeout(job.timer); job.reject(new Error('Detection unavailable')); });
    jobs.clear();
  }

  function detect(bitmap) {
    if (!worker) {
      try {
        const workerUrl = new URL('faculty-photo-detection-worker.js', scriptUrl);
        workerUrl.search = new URL(scriptUrl).search;
        worker = new Worker(workerUrl);
        worker.onmessage = function (event) {
          const job = jobs.get(event.data.id);
          if (!job) return;
          clearTimeout(job.timer);
          jobs.delete(event.data.id);
          if (event.data.error) { job.reject(new Error(event.data.error)); stopWorker(); }
          else job.resolve(event.data.result);
        };
        worker.onerror = stopWorker;
      } catch (error) { bitmap.close(); return Promise.reject(error); }
    }
    return new Promise(function (resolve, reject) {
      const id = ++jobId;
      jobs.set(id, {resolve: resolve, reject: reject, timer: setTimeout(stopWorker, 20000)});
      try { worker.postMessage({id: id, image: bitmap}, [bitmap]); }
      catch (error) { bitmap.close(); stopWorker(); }
    });
  }

  function suggestedCrop(result) {
    const width = image.naturalWidth;
    const height = image.naturalHeight;
    const face = result.face;
    const faceX = face.x * width;
    const faceY = face.y * height;
    const faceWidth = face.width * width;
    const faceHeight = face.height * height;
    const top = faceY - faceHeight * .55;
    let centerX = faceX + faceWidth / 2;
    let size = Math.max(faceWidth * 2.8, faceHeight * 3.1);
    if (result.shoulders) {
      centerX = centerX * .6 + (result.shoulders.left.x + result.shoulders.right.x) * width / 2 * .4;
      const shoulderWidth = Math.abs(result.shoulders.left.x - result.shoulders.right.x) * width;
      const bottom = Math.max(result.shoulders.left.y, result.shoulders.right.y) * height + faceHeight * .25;
      size = Math.max(faceWidth * 2.4, shoulderWidth * 1.15, bottom - top);
    }
    size = Math.min(size, width, height);
    return {x: clamp(centerX - size / 2, 0, width - size), y: clamp(top, 0, height - size), size: size};
  }

  async function autoCrop() {
    if (!image) return;
    const currentFile = fileVersion;
    const currentEdit = editVersion;
    auto.disabled = true;
    input.setCustomValidity('Wait for automatic cropping to finish, or adjust the crop manually.');
    status.textContent = 'Finding the face and shoulders… You can adjust the preview while we check.';
    try {
      const frame = document.createElement('canvas');
      const scale = Math.min(1, 960 / Math.max(image.naturalWidth, image.naturalHeight));
      frame.width = Math.max(1, Math.round(image.naturalWidth * scale));
      frame.height = Math.max(1, Math.round(image.naturalHeight * scale));
      frame.getContext('2d').drawImage(image, 0, 0, frame.width, frame.height);
      const bitmap = await createImageBitmap(frame);
      if (currentFile !== fileVersion || currentEdit !== editVersion) { bitmap.close(); return; }
      const result = await detect(bitmap);
      if (currentFile !== fileVersion || currentEdit !== editVersion) return;
      if (result.kind === 'multiple') {
        status.textContent = 'Several faces were found. Use the controls to frame the correct faculty member.';
      } else if (result.kind === 'none') {
        status.textContent = 'No clear face was found. Use the controls to frame the head and shoulders.';
      } else {
        original.checked = false;
        crop = suggestedCrop(result);
        render();
        status.textContent = result.shoulders
          ? 'Head-and-shoulders crop suggested. Review and adjust it before saving.'
          : 'Face found. Shoulder framing is estimated; review and adjust the crop.';
      }
    } catch (error) {
      if (currentFile === fileVersion && currentEdit === editVersion) status.textContent = 'Automatic cropping is unavailable. You can still adjust and save the crop manually.';
    } finally {
      if (currentFile === fileVersion) { auto.disabled = false; input.setCustomValidity(''); }
    }
  }

  input.addEventListener('change', async function () {
    const currentFile = ++fileVersion;
    editVersion++;
    drag = null;
    image = crop = null;
    cropInput.value = '';
    editor.hidden = true;
    original.checked = false;
    zoom.max = '8';
    auto.disabled = false;
    if (photoUrl) URL.revokeObjectURL(photoUrl);
    photoUrl = null;
    const file = input.files[0];
    if (!file || !input.validity.valid) return;
    input.setCustomValidity('Wait for the photo preview to finish loading.');
    photoUrl = URL.createObjectURL(file);
    const candidate = new Image();
    try {
      await new Promise(function (resolve, reject) {
        candidate.onload = resolve;
        candidate.onerror = reject;
        candidate.src = photoUrl;
      });
      if (currentFile !== fileVersion) return;
      if (candidate.naturalWidth > 6000 || candidate.naturalHeight > 6000 || candidate.naturalWidth * candidate.naturalHeight > 16000000) {
        throw new Error('dimensions');
      }
      input.setCustomValidity('');
      image = candidate;
      crop = centerCrop();
      editor.hidden = false;
      render();
      autoCrop();
    } catch (error) {
      if (currentFile !== fileVersion) return;
      const message = error.message === 'dimensions' ? 'Choose an image up to 6,000 pixels per side and 16 megapixels.' : 'The photo could not be read. Choose another JPG, PNG, or WebP image.';
      input.setCustomValidity(message);
      selection.textContent = message;
    }
  });

  zoom.addEventListener('input', function () {
    if (!crop) return;
    const size = Math.min(image.naturalWidth, image.naturalHeight) / Number(zoom.value);
    crop.x += (crop.size - size) / 2;
    crop.y += (crop.size - size) / 2;
    crop.size = size;
    adjusted();
  });
  horizontal.addEventListener('input', function () { if (crop) { crop.x = (image.naturalWidth - crop.size) * Number(horizontal.value) / 100; adjusted(); } });
  vertical.addEventListener('input', function () { if (crop) { crop.y = (image.naturalHeight - crop.size) * Number(vertical.value) / 100; adjusted(); } });
  original.addEventListener('change', adjusted);
  reset.addEventListener('click', function () { crop = centerCrop(); original.checked = false; adjusted(); });
  auto.addEventListener('click', function () { editVersion++; autoCrop(); });
  canvas.addEventListener('pointerdown', function (event) {
    if (!crop || original.checked) return;
    drag = {id: event.pointerId, x: event.clientX, y: event.clientY, cropX: crop.x, cropY: crop.y};
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', function (event) {
    if (!drag || drag.id !== event.pointerId) return;
    const bounds = canvas.getBoundingClientRect();
    crop.x = drag.cropX - (event.clientX - drag.x) * crop.size / bounds.width;
    crop.y = drag.cropY - (event.clientY - drag.y) * crop.size / bounds.height;
    adjusted();
    cropInput.dispatchEvent(new Event('input', {bubbles: true}));
  });
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(name => canvas.addEventListener(name, function () { drag = null; }));
  canvas.addEventListener('keydown', function (event) {
    if (!crop || original.checked || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    const step = crop.size * (event.shiftKey ? .05 : .01);
    if (event.key === 'ArrowLeft') crop.x -= step;
    if (event.key === 'ArrowRight') crop.x += step;
    if (event.key === 'ArrowUp') crop.y -= step;
    if (event.key === 'ArrowDown') crop.y += step;
    adjusted();
    cropInput.dispatchEvent(new Event('input', {bubbles: true}));
  });
  form.addEventListener('submit', function () { editVersion++; }, true);
  window.addEventListener('pagehide', function () { stopWorker(); if (photoUrl) URL.revokeObjectURL(photoUrl); });
})();
