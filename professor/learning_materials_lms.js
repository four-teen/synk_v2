(function () {
  'use strict';
  const configNode = document.getElementById('materialConfig');
  if (!configNode) return;
  const config = JSON.parse(configNode.textContent), form = document.getElementById('materialForm');
  const input = document.getElementById('materialFiles'), upload = document.getElementById('materialUpload');
  const list = document.getElementById('materialList'), count = document.getElementById('materialCount');
  const refresh = document.getElementById('materialRefresh'), status = document.getElementById('materialFormStatus');
  const selection = document.getElementById('materialSelection'), feedback = window.synkLmsFeedback;
  let materials = config.materials, busy = false, uncertain = false;
  const element = (tag, className, text) => {
    const node = document.createElement(tag); node.className = className; if (text !== undefined) node.textContent = text; return node;
  };
  const sizeLabel = size => size >= 1048576 ? (size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.ceil(size / 1024)) + ' KB';
  function controls() {
    input.disabled = busy || uncertain || materials.length >= 2;
    upload.disabled = input.disabled;
    refresh.disabled = busy;
    list.querySelectorAll('button').forEach(button => { button.disabled = busy || uncertain; });
    form.setAttribute('aria-busy', String(busy));
  }
  function render() {
    list.replaceChildren(); count.textContent = materials.length + ' / 2 files';
    if (!materials.length) {
      const empty = element('div', 'material-empty');
      const icon = element('i', 'bx bx-folder-open'); icon.setAttribute('aria-hidden', 'true');
      empty.append(icon, element('strong', '', 'No learning materials yet'), element('span', '', 'Upload your syllabus or presentation to get started.'));
      list.append(empty);
    }
    materials.forEach(file => {
      const pdf = file.mime_type === 'application/pdf', item = element('article', 'material-file' + (pdf ? '' : ' is-powerpoint'));
      const icon = element('span', 'material-file-icon'); icon.setAttribute('aria-hidden', 'true'); icon.append(element('i', 'bx ' + (pdf ? 'bxs-file-pdf' : 'bxs-slideshow')));
      const body = element('div', 'material-file-content'), actions = element('div', 'material-file-actions');
      body.append(element('h4', '', file.original_name), element('p', '', (pdf ? 'PDF' : 'PowerPoint') + ' · ' + sizeLabel(Number(file.size_bytes)) + ' · Uploaded ' + file.uploaded_at + ' (PHT)'));
      const link = element('a', 'btn btn-outline-primary', 'Download');
      link.href = config.download + '?' + new URLSearchParams({...config.context, material_id:file.material_id}); link.setAttribute('download', '');
      link.setAttribute('aria-label', 'Download ' + file.original_name);
      const remove = element('button', 'btn btn-outline-danger', 'Remove'); remove.type = 'button'; remove.setAttribute('aria-label', 'Remove ' + file.original_name);
      remove.addEventListener('click', () => removeFile(file)); actions.append(link, remove); body.append(actions); item.append(icon, body); list.append(item);
    });
    controls();
  }
  function message(text, error = false) { status.textContent = text; status.classList.toggle('is-error', error); }
  async function request(action, files = [], id) {
    const data = new FormData();
    data.append('csrf_token', config.csrf_token); data.append('context', JSON.stringify(config.context)); data.append('action', action);
    if (id) data.append('material_id', String(id));
    files.forEach(file => data.append('materials[]', file));
    const response = await fetch(config.endpoint, {method:'POST', body:data, credentials:'same-origin', cache:'no-store'});
    let result;
    try { result = await response.json(); } catch { throw new Error('The server could not confirm this request. Refresh the file list before retrying.'); }
    if (!response.ok || !result.ok || !Array.isArray(result.materials)) {
      const error = new Error(result.message || 'Learning materials could not be updated. Please try again.'); error.status = response.status; throw error;
    }
    materials = result.materials; uncertain = false;
  }
  async function sync() {
    await request('list'); render();
  }
  async function reconcile(error) {
    // The response may have been lost after saving, or another tab may have filled a slot.
    try { await sync(); } catch { uncertain = true; }
    message(error.message + (uncertain ? ' Refresh the file list to continue.' : ''), true);
    await feedback.error(status.textContent, 'Learning materials');
  }
  input.addEventListener('change', () => {
    selection.textContent = Array.from(input.files).map(file => file.name + ' (' + sizeLabel(file.size) + ')').join('; '); message('');
  });
  feedback.validation(form);
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy || uncertain) return;
    const files = Array.from(input.files), remaining = 2 - materials.length;
    const error = !files.length ? 'Choose a PDF or PowerPoint file.' : files.length > remaining ? 'Only ' + remaining + ' file slot(s) remain. Each subject allows two files.' : files.some(file => !/\.(pdf|ppt|pptx)$/i.test(file.name)) ? 'Only PDF and PowerPoint files (.pdf, .ppt, .pptx) are allowed.' : files.some(file => !file.size || file.size > config.max_bytes) ? 'Each file must be non-empty and at most ' + sizeLabel(config.max_bytes) + '.' : '';
    if (error) { message(error, true); await feedback.error(error, 'Check your files'); return; }
    busy = true; controls(); message('Uploading files…');
    try {
      await request('upload', files); form.reset(); selection.textContent = ''; render(); message('Files uploaded successfully.'); feedback.toast('Learning materials uploaded');
    } catch (error) { await reconcile(error); }
    finally { busy = false; controls(); }
  });
  async function removeFile(file) {
    if (busy || uncertain) return;
    if (!await feedback.confirm({title:'Remove this material?',text:file.original_name + ' will be removed from this subject. You can upload it again to restore it.',confirmText:'Remove file',danger:true})) return;
    busy = true; controls(); message('Removing file…');
    try { await request('remove', [], file.material_id); render(); message('File removed. A slot is available for a new upload.'); feedback.toast('Learning material removed'); }
    catch (error) { await reconcile(error); }
    finally { busy = false; controls(); }
  }
  refresh.addEventListener('click', async () => {
    if (busy) return; busy = true; controls(); message('Refreshing files…');
    try { await sync(); message('File list is up to date.'); }
    catch (error) { uncertain = true; message(error.message, true); await feedback.error(error.message, 'Could not refresh files'); }
    finally { busy = false; controls(); }
  });
  feedback.guardNavigation(() => busy || input.files.length > 0, () => { form.reset(); selection.textContent = ''; }, 'Selected files have not finished uploading. Leave this page?');
  window.addEventListener('beforeunload', event => { if (busy || input.files.length) { event.preventDefault(); event.returnValue = ''; } });
  render();
})();
