// Run: node tests/faculty-appointments-browser.cjs (localhost Synk and Edge/Chrome).
// HTTP fixtures roll back; browser form submissions are intercepted.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..'), tag = crypto.randomBytes(12).toString('hex');
const route = path.join(root, 'tmp', `appointment-browser-${tag}.php`);
const profile = path.join(root, '.tmp', `appointment-browser-${tag}`);
const pageUrl = 'http://localhost/synk/administrator/faculty-appointments.php';
let browser, ws, call, session, pageHtml, checks = 0;
const errors = [], requests = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };
async function main() {
  fs.writeFileSync(route, `<?php define('SYNK_COLLEGE_APPOINTMENT_QA','${tag}'); require __DIR__.'/../tests/fixtures/faculty-college-appointments-request.php';`);
  const fixture = async (scenario, post) => {
    const response = await fetch(`http://localhost/synk/tmp/${path.basename(route)}?qa=${tag}&scenario=${scenario}`, post ? { method: 'POST', body: new URLSearchParams({ csrf_token: tag }) } : {});
    check(response.status === (post ? 422 : 200), `Fixture rendered: ${scenario}`);
    return response.text();
  };
  const currentHtml = await fixture('current'), editHtml = await fixture('edit');
  const failedHtml = await fixture('multi_bad_faculty', true);
  pageHtml = currentHtml;
  const exe = [process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  if (!exe) throw Error('Edge or Chrome is required.');
  browser = spawn(exe, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const socket = await new Promise((resolve, reject) => {
    let log = ''; const timeout = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => { log += data; const match = log.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
    browser.on('error', reject);
  });
  ws = new WebSocket(socket); await new Promise((resolve, reject) => { ws.onopen = resolve; ws.onerror = reject; });
  let sequence = 0; const pending = new Map();
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence, timeout = setTimeout(() => reject(Error('CDP timeout: ' + method)), 15000);
    pending.set(id, { resolve, reject, timeout }); ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (pending.has(message.id)) { const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timeout); message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (message.method === 'Fetch.requestPaused') {
      requests.push(message.params.request);
      const html = pageHtml.replace('<head>', `<head><meta name="appointment-browser-document" content="${requests.length}">`);
      call('Fetch.fulfillRequest', { requestId: message.params.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }], body: Buffer.from(html).toString('base64') }, session).catch(error => errors.push(error.message));
    }
  });
  const target = await call('Target.createTarget', { url: 'about:blank' });
  session = (await call('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
  await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, session);
  await call('Fetch.enable', { patterns: [{ urlPattern: '*administrator/faculty-appointments.php*', resourceType: 'Document' }] }, session);
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, session);
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  const act = expression => evaluate('(() => {' + expression + '})()');
  const until = async expression => {
    for (let i = 0; i < 100; i++) { if (await evaluate(`Boolean(${expression})`)) return; await delay(100); }
    const details = await evaluate('JSON.stringify({role:document.getElementById("appointment-role")?.value,programDisabled:document.getElementById("appointment-program")?.disabled,programOptions:document.getElementById("appointment-program")?.options.length,results:document.querySelector(".select2-results")?.innerHTML})');
    throw Error('Browser condition timed out: ' + expression + ' ' + details);
  };
  const ready = () => until(`document.readyState === "complete" && document.querySelector('meta[name=appointment-browser-document]')?.content === '${requests.length}' && !!window.jQuery?.fn.select2 && !!jQuery("#appointment-program").data("select2")`);
  const navigate = async () => { await call('Page.navigate', { url: pageUrl }, session); await ready(); };
  await navigate();
  check(await evaluate('document.getElementById("appointment-view").hidden && document.getElementById("appointment-program-field").hidden && document.getElementById("appointment-program").disabled'), 'Dean forms hide programs and enable automatic filters.');
  for (const id of ['appointment-college', 'appointment-year', 'appointment-semester']) {
    const before = requests.length;
    const selected = await act(`const field = document.getElementById(${JSON.stringify(id)}); field.selectedIndex = (field.selectedIndex + 1) % field.options.length; const expected = Object.fromEntries(new FormData(field.form)); field.dispatchEvent(new Event('change', {bubbles:true})); return expected;`);
    for (let i = 0; i < 100 && requests.length === before; i++) await delay(50);
    check(requests.length === before + 1, `Changing ${id} automatically sends one request.`);
    const request = requests.at(-1), query = Object.fromEntries(new URL(request.url).searchParams);
    check(request.method === 'GET' && JSON.stringify(query) === JSON.stringify(selected), `Changing ${id} submits all three selected filters.`);
    await ready();
  }
  await act('document.getElementById("appointment-role").value="chair";document.getElementById("appointment-role").dispatchEvent(new Event("change"))');
  check(await evaluate('!document.getElementById("appointment-program-field").hidden && document.getElementById("appointment-program").multiple && document.getElementById("appointment-program").required && !document.getElementById("appointment-program").validity.valid'), 'Chair forms require one or more programs.');
  await act('jQuery("#appointment-program").select2("open")');
  await until('document.querySelectorAll(".select2-results__option--selectable:not(.select2-results__option--selected)").length>=2');
  await act('document.querySelector(".select2-results__option--selectable:not(.select2-results__option--selected)").dispatchEvent(new MouseEvent("mouseup",{bubbles:true}))');
  await act('document.querySelector(".select2-results__option--selectable:not(.select2-results__option--selected)").dispatchEvent(new MouseEvent("mouseup",{bubbles:true}));jQuery("#appointment-program").select2("close");document.getElementById("appointment-faculty").selectedIndex=1;jQuery("#appointment-faculty").trigger("change")');
  check(await evaluate('document.querySelectorAll("#appointment-program option:checked").length===2 && document.querySelectorAll("#appointment-program-field .select2-selection__choice").length===2'), 'Users can select two programs without closing the dropdown.');
  const formValues = await evaluate('Object.fromEntries([...new FormData(document.getElementById("faculty-appointment-form"))].filter(([key])=>key!=="program_ids[]"))');
  const programIds = await evaluate('new FormData(document.getElementById("faculty-appointment-form")).getAll("program_ids[]")');
  await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: false }, session); await delay(250);
  check(await evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'Multiple selected programs fit a mobile viewport.');
  const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }, session);
  fs.writeFileSync(path.join(root, 'tmp', 'faculty-appointments-mobile.png'), Buffer.from(screenshot.data, 'base64'));
  await act('document.getElementById("faculty-appointment-form").requestSubmit()');
  await until('document.readyState==="complete" && document.getElementById("appointment-role").value==="dean"');
  const submitted = new URLSearchParams(requests.at(-1).postData);
  check(requests.at(-1).method === 'POST' && JSON.stringify(submitted.getAll('program_ids[]')) === JSON.stringify(programIds) && submitted.get('faculty_id') === formValues.faculty_id, 'Saving submits both programs for one faculty member.');
  pageHtml = failedHtml; await navigate();
  check(await evaluate('document.getElementById("appointment-role").value==="chair" && document.querySelectorAll("#appointment-program option:checked").length===2 && document.querySelectorAll("#appointment-program-field .select2-selection__choice").length===2'), 'A rejected save retains both selected programs in the visible control.');
  await act('document.getElementById("appointment-role").value="dean";document.getElementById("appointment-role").dispatchEvent(new Event("change"))');
  check(await evaluate('!new FormData(document.getElementById("faculty-appointment-form")).has("program_ids[]")'), 'Switching to Dean excludes retained program selections from submission.');
  pageHtml = editHtml; await navigate();
  check(await evaluate('document.getElementById("appointment-role").disabled && document.getElementById("appointment-program").disabled'), 'Replacement forms keep the original post fixed.');
  check(errors.length === 0, 'No browser runtime errors: ' + errors.join('; '));
  console.log(`Faculty appointments: ${checks} browser checks passed.`);
}
main().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (call) try { await call('Browser.close'); } catch (_) {}
  if (ws) ws.close();
  if (browser && browser.exitCode === null) { browser.kill(); await Promise.race([new Promise(resolve => browser.once('exit', resolve)), delay(3000)]); }
  if (fs.existsSync(route)) fs.unlinkSync(route);
  const resolved = path.resolve(profile);
  if (resolved.startsWith(path.resolve(root, '.tmp') + path.sep) && fs.existsSync(resolved)) fs.rmSync(resolved, { recursive: true, force: true });
});
