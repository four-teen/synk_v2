// Headless browser verification against token-protected, rollback-only fixtures.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const nonce = crypto.randomBytes(8).toString('hex');
const token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `evaluation-browser-${nonce}.php`);
const profile = path.join(root, '.tmp', `evaluation-browser-${nonce}`);
let browser, socket, call, checks = 0;
const errors = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };

async function main() {
  fs.writeFileSync(route, `<?php define('SYNK_STUDENT_PORTAL_QA','${token}');require '${path.join(root, 'tests/fixtures/student-portal-request.php').replaceAll('\\', '/')}';`);
  const executable = [
    process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe',
    process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe'
  ].find(fs.existsSync);
  if (!executable) throw Error('Chrome or Edge is required.');
  browser = spawn(executable, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const address = await new Promise((resolve, reject) => {
    let output = '';
    const timeout = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => {
      output += data;
      const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
      if (match) { clearTimeout(timeout); resolve(match[1]); }
    });
    browser.on('error', error => { clearTimeout(timeout); reject(error); });
  });
  socket = new WebSocket(address);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let sequence = 0;
  const pending = new Map();
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
      const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timeout);
      message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
  });
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence;
    const timeout = setTimeout(() => { pending.delete(id); reject(Error(`${method} timed out.`)); }, 15000);
    pending.set(id, { resolve, reject, timeout }); socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const target = await call('Target.createTarget', { url: 'about:blank' });
  const session = (await call('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
  await call('Network.enable', {}, session);
  await call('Network.setBlockedURLs', { urls: ['https://*'] }, session);
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, session);
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  const until = async expression => {
    for (let attempt = 0; attempt < 120; attempt++) { if (await evaluate(`Boolean(${expression})`)) return; await delay(100); }
    throw Error('Timed out: ' + expression);
  };
  const navigate = async scenario => {
    const url = `http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=evaluation_access_${scenario}`;
    await call('Page.navigate', { url }, session);
    await until(`location.href===${JSON.stringify(url)} && document.readyState==='complete'`);
    await delay(150);
  };
  for (const [scenario, page] of [['dean_get', 'dean'], ['student_open', 'student'], ['student_closed', 'closed'], ['professor_released', 'professor'], ['professor_hidden', 'hidden'], ['student_archived_without_roster', 'archive'], ['preview_nav_closed', 'preview-closed'], ['preview_nav_open', 'preview-open']]) {
    await navigate(scenario);
    check(await evaluate('!document.body.innerText.match(/Fatal error|Parse error|Warning:/)'), `${page} renders without PHP errors.`);
    for (const width of [1440, 768, 390, 320]) {
      await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false }, session);
      await delay(100);
      check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `${page} fits ${width}px.`);
      if ((page === 'dean' || page === 'professor') && (width === 1440 || width === 390)) {
        const shot = await call('Page.captureScreenshot', { format: 'png' }, session);
        fs.writeFileSync(path.join(root, '.tmp', `evaluation-access-${page}-${width}.png`), Buffer.from(shot.data, 'base64'));
      }
    }
    if (page === 'dean') check(await evaluate('document.querySelectorAll("#evaluationAccessControls form").length===2'), 'The Dean has two independent controls.');
    if (page === 'closed') check(await evaluate('!document.querySelector("select[name^=ratings]")'), 'Closed periods expose no answer forms.');
    if (page === 'closed' || page === 'preview-closed') check(await evaluate('!document.querySelector("#layout-menu a[href*=class-program]")'), 'The evaluation menu is hidden when collection is closed, including Show Account.');
    if (page === 'preview-open') check(await evaluate('document.querySelector("#layout-menu a[href*=class-program]")?.href.includes("preview_student_id=") && document.querySelector("#layout-menu a[href*=class-program]")?.href.includes("evaluation_college_id=")'), 'Show Account restores the college-scoped evaluation menu when collection opens.');
    if (page === 'student') {
      check(await evaluate('Boolean(document.querySelector("#layout-menu a[href*=class-program]"))'), 'The student evaluation menu is visible during open collection.');
      await evaluate('document.querySelector("[data-bs-target^=\\"#facultyEvaluationModal_\\"]").click()');
      await until('document.querySelector(".faculty-eval-modal.show")');
      check(await evaluate('document.querySelectorAll(".faculty-eval-modal.show select[name^=ratings]:enabled").length===15'), 'Current open evaluations have 15 enabled ratings.');
    }
    if (page === 'archive') {
      await evaluate('document.querySelector("[data-bs-target^=\\"#facultyEvaluationModal_\\"]").click()');
      await until('document.querySelector(".faculty-eval-modal.show")');
      check(await evaluate('document.querySelectorAll(".faculty-eval-modal.show select:enabled,.faculty-eval-modal.show button[type=submit]").length===0'), 'Archived evaluation modals are read only.');
    }
  }
  check(errors.length === 0, 'No browser JavaScript errors: ' + errors.join('; '));
  console.log(`Evaluation access browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}

main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (socket?.readyState === WebSocket.OPEN) socket.close();
  if (browser) { browser.kill(); await delay(700); }
  if (fs.existsSync(route)) fs.unlinkSync(route);
  if (fs.existsSync(profile)) {
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const cleanup = `$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root, '.tmp'))}).Path; $profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Profile cleanup is outside the workspace.' }; Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;
    const result = spawnSync('powershell.exe', ['-NoProfile', '-Command', cleanup], { windowsHide: true, encoding: 'utf8' });
    if (result.status !== 0) { console.error(result.stderr || 'Browser profile cleanup failed.'); process.exitCode = 1; }
  }
});
