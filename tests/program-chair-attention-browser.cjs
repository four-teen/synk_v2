// Run: node tests/program-chair-attention-browser.cjs (localhost Synk and Edge/Chrome).
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const tag = crypto.randomBytes(12).toString('hex');
const profile = path.join(root, '.tmp', `attention-browser-${tag}`);
const route = path.join(root, 'tmp', `attention-browser-${tag}.php`);
const token = crypto.randomBytes(24).toString('hex');
let browser, ws, call, session, checks = 0;
const errors = [], failures = [], navigations = [], pending = new Map();
const check = (condition, message) => { if (!condition) throw Error(message); checks++; };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const phpString = value => "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const html = new Map();
async function fixture(scenario) {
  if (!html.has(scenario)) {
    const url = new URL(`http://localhost/synk/tmp/${path.basename(route)}`);
    url.search = new URLSearchParams({ qa: token, scenario });
    const response = await fetch(url);
    const text = await response.text();
    let payload;
    try { payload = JSON.parse(text); } catch { throw Error('Invalid HTTP fixture response: ' + text.slice(0, 300)); }
    if (payload.http_status !== 200) throw Error('Fixture failed: ' + scenario);
    check(payload.php_version.startsWith('7.1.'), 'Browser HTML must come from the deployed PHP 7.1 runtime.');
    html.set(scenario, typeof payload.response === 'string' ? payload.response : JSON.stringify(payload.response));
  }
  return html.get(scenario);
}
async function evaluate(expression) {
  const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, session);
  if (result.exceptionDetails) throw Error(result.exceptionDetails.text);
  return result.result.value;
}
async function until(expression) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try { if (await evaluate(expression)) return; } catch { /* Navigation can briefly replace the execution context. */ }
    await delay(100);
  }
  throw Error('Browser condition timed out: ' + expression);
}
async function main() {
  fs.writeFileSync(route, `<?php if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !is_string($_GET['qa'] ?? null) || !hash_equals('${token}', $_GET['qa'])) { http_response_code(404); exit; } define('SYNK_PROGRAM_CHAIR_QA_HTTP',true); require ${phpString(path.join(root, 'tests/fixtures/program-chair-request.php'))};`);
  await fixture('page_need-attention');
  await fixture('attention_program');
  await fixture('attention_term');
  await fixture('page_index');
  await fixture('attention_year');
  const executable = [process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  if (!executable) throw Error('Edge or Chrome is required.');
  browser = spawn(executable, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore','ignore','pipe'] });
  const socket = await new Promise((resolve, reject) => {
    let log = ''; const timer = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => { log += data; const match = log.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
    browser.on('error', error => { clearTimeout(timer); reject(error); });
  });
  ws = new WebSocket(socket);
  await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
  let sequence = 0;
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence, timer = setTimeout(() => { pending.delete(id); reject(Error('CDP timeout: ' + method)); }, 15000);
    pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (pending.has(message.id)) {
      const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timer);
      message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (message.method === 'Network.responseReceived' && message.params.response.status >= 400 && message.params.response.url.startsWith('http://localhost/')) failures.push(message.params.response.status + ' ' + message.params.response.url);
    if (message.method === 'Fetch.requestPaused') (async () => {
      const { requestId, request } = message.params;
      const url = new URL(request.url); navigations.push(url);
      const isHeatmap = url.pathname.endsWith('/dashboard_program_chair_schedule_heatmap.php');
      const scenario = isHeatmap ? 'heatmap' : url.pathname.endsWith('/index.php') ? 'page_index'
        : !url.searchParams.get('program_id') ? 'page_need-attention'
        : url.searchParams.get('dean_semester') === '2' ? 'attention_term'
        : url.searchParams.get('year_level') === '3' ? 'attention_year' : 'attention_program';
      const body = await fixture(scenario);
      await call('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name:'Content-Type', value:isHeatmap ? 'application/json' : 'text/html; charset=utf-8' }], body:Buffer.from(body).toString('base64') }, session);
    })().catch(error => errors.push(error.stack));
  });
  const target = await call('Target.createTarget', { url:'about:blank' });
  session = (await call('Target.attachToTarget', { targetId:target.targetId, flatten:true })).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session); await call('Network.enable', {}, session);
  await call('Emulation.setDeviceMetricsOverride', { width:1440, height:1000, deviceScaleFactor:1, mobile:false }, session);
  await call('Fetch.enable', { patterns:[{ urlPattern:'http://localhost/synk/program-chair/need-attention.php*', resourceType:'Document' },
    { urlPattern:'http://localhost/synk/program-chair/index.php*', resourceType:'Document' },
    { urlPattern:'http://localhost/synk/backend/dashboard_program_chair_schedule_heatmap.php' }] }, session);
  await call('Page.navigate', { url:'http://localhost/synk/program-chair/index.php' }, session);
  await until('document.readyState === "complete" && !!document.querySelector("#chairNeedAttention")');
  check(await evaluate('document.querySelectorAll(".chair-attention-program").length > 0 && [...document.querySelectorAll(".chair-attention-program")].every(program => [1,2,3,4].every(year => program.querySelector(`[data-attention-year="${year}"]`)))'), 'Each program has first through fourth year count cards.');
  check(await evaluate('!document.querySelector("#collegeStudentWorkload") && !document.querySelector("#collegeStudentActivity") && !document.querySelector("script[src*=\\"student-workload\\"]")'), 'The old dashboard workload and login activity sections are absent.');
  const expected = await evaluate('(() => { const link=document.querySelector("[data-attention-program=\\"1\\"][data-attention-year=\\"3\\"]");return {count:Number(link.dataset.attentionCount),href:link.href}; })()');
  check(expected.count > 0 && new URL(expected.href).searchParams.get('year_level') === '3', 'A flagged year card links to the matching report.');
  await evaluate('document.fonts.ready.then(() => true)');
  await delay(300);
  const clip = await evaluate('(() => { const r=document.querySelector("#chairNeedAttention").getBoundingClientRect();return {x:r.x+scrollX,y:r.y+scrollY,width:r.width,height:r.height,scale:1}; })()');
  const screenshot = await call('Page.captureScreenshot', { format:'png',captureBeyondViewport:true,clip }, session);
  fs.writeFileSync(path.join(root,'.tmp','need-attention-dashboard.png'),Buffer.from(screenshot.data,'base64'));
  await call('Emulation.setDeviceMetricsOverride', { width:390, height:844, deviceScaleFactor:1, mobile:true }, session);
  const mobile = await evaluate('(() => { const grid=document.querySelector(".chair-attention-years"),card=document.querySelector("#chairNeedAttention"),rect=card.getBoundingClientRect();return {columns:getComputedStyle(grid).gridTemplateColumns,viewport:innerWidth,left:rect.left,right:rect.right,scroll:card.scrollWidth,width:card.clientWidth}; })()');
  check(mobile.columns.split(' ').length === 2 && mobile.left >= 0 && mobile.right <= mobile.viewport && mobile.scroll <= mobile.width,
    'Dashboard year cards fit two columns on mobile within the available card width: ' + JSON.stringify(mobile));
  await call('Emulation.setDeviceMetricsOverride', { width:1440, height:1000, deviceScaleFactor:1, mobile:false }, session);
  await evaluate('document.querySelector("[data-attention-program=\\"1\\"][data-attention-year=\\"3\\"]").click()');
  await until('document.readyState === "complete" && !!document.querySelector("#attentionResultsTitle") && document.querySelector("#attentionYear").value === "3"');
  check(await evaluate('document.querySelectorAll(".attention-table tbody tr").length') === expected.count, 'The clicked year card opens exactly its counted students.');
  check(await evaluate('document.querySelector("#attentionProgram").value === "1" && document.querySelector("#dean-view-term-form input[name=year_level]").value === "3"'), 'The detail page and term controls retain the program and year.');
  await call('Page.navigate', { url:'http://localhost/synk/program-chair/need-attention.php' }, session);
  await until('document.readyState === "complete" && !!document.querySelector("#attentionProgram")');
  check(await evaluate('!document.querySelector("#attentionResultsTitle") && document.querySelector("#attentionProgram").value === ""'), 'The initial page requires a program selection.');
  check(await evaluate('document.querySelector("a[href=\\"need-attention.php\\"]").getAttribute("aria-current") === "page"'), 'The new sidebar menu is active.');
  await evaluate('document.querySelector("#attentionProgram").value="1";document.querySelector("#attentionProgram").form.requestSubmit();');
  await until('document.readyState === "complete" && !!document.querySelector("#attentionResultsTitle")');
  check(navigations.at(-1).searchParams.get('program_id') === '1' && navigations.at(-1).searchParams.has('dean_ay_id') && navigations.at(-1).searchParams.has('dean_semester'), 'Program submission carries the program and viewing term.');
  check(await evaluate('document.querySelectorAll(".attention-table tbody tr").length > 0'), 'The real selected-program report renders flagged students.');
  await evaluate('document.querySelector(".attention-subjects summary").click()');
  check(await evaluate('document.querySelector(".attention-subjects").open && document.querySelector(".attention-subjects li").innerText.includes("units")'), 'The subject list opens and explains the counted credits.');
  await evaluate('document.querySelector("#dean-term-settings-toggle").click()');
  await until('document.querySelector("#deanTermSettingsModal").classList.contains("show")');
  check(await evaluate('document.querySelector("#dean-view-term-form input[name=program_id]").value === "1"'), 'The term dialog retains the selected program.');
  await evaluate('document.querySelector("#dean-view-semester").value="2";document.querySelector("#dean-view-term-form").requestSubmit();');
  await until('document.readyState === "complete" && location.search.includes("dean_semester=2") && !!document.querySelector("#attentionResultsTitle")');
  check(navigations.at(-1).searchParams.get('program_id') === '1' && navigations.at(-1).searchParams.get('dean_semester') === '2', 'Changing the semester preserves the selected program.');
  check(await evaluate('document.querySelector("#attentionProgram").form.querySelector("input[name=dean_semester]").value === "2"'), 'The results and program filter follow the changed semester.');
  check(await evaluate('!document.querySelector(".attention-table") && document.querySelector("main").innerText.includes("No active student subject records")'), 'An empty alternate term renders its own empty state.');
  await call('Emulation.setDeviceMetricsOverride', { width:390, height:844, deviceScaleFactor:1, mobile:true }, session);
  check(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'The page fits a mobile viewport without page-level horizontal overflow.');
  check(await evaluate('document.querySelector("#attentionProgram").getBoundingClientRect().width > 200'), 'The program selector remains usable on mobile.');
  await evaluate('document.querySelector(".layout-navbar .layout-menu-toggle a").click()');
  await until('document.documentElement.classList.contains("layout-menu-expanded")');
  check(await evaluate('!!document.querySelector("a[href=\\"need-attention.php\\"]")'), 'Need Attention is available in the mobile menu.');
  await delay(300);
  check(!errors.length, 'No browser script exceptions: ' + errors.join('\n'));
  check(!failures.length, 'Local page assets load without HTTP errors: ' + failures.join('\n'));
  console.log(`Need Attention browser: ${checks} checks passed using PHP 7.1 HTML, real local assets, desktop and mobile layouts.`);
}
main().catch(error => { console.error(error.stack); process.exitCode=1; }).finally(async () => {
  if (call && ws?.readyState === WebSocket.OPEN) { try { await call('Browser.close'); } catch {} }
  if (ws) ws.close();
  if (browser && browser.exitCode === null) { await delay(500); if (browser.exitCode === null) browser.kill(); }
  for (const item of pending.values()) clearTimeout(item.timer);
  if (fs.existsSync(route)) fs.unlinkSync(route);
  // Only remove the temporary profile created by this run, inside this workspace.
  const resolved = path.resolve(profile), allowed = path.resolve(root, '.tmp') + path.sep;
  if (!resolved.startsWith(allowed) || path.basename(resolved) !== `attention-browser-${tag}`) throw Error('Unexpected browser profile path.');
  if (fs.existsSync(resolved)) fs.rmSync(resolved, { recursive:true, force:true, maxRetries:5, retryDelay:200 });
});
