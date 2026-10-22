// Inspect the real PHP renderer with isolated records. Launches a hidden headless browser.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), nonce = crypto.randomBytes(6).toString('hex');
const preview = path.join(root, '.tmp', `oracle-list-${nonce}.html`), detail = path.join(root, '.tmp', `oracle-detail-${nonce}.html`);
const profile = path.join(root, '.tmp', `oracle-browser-${nonce}`);
let server, browser, socket, call, checks = 0;
const errors = [], requests = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (condition, message) => { if (!condition) throw Error(message); checks++; };

async function main() {
  const generated = spawnSync('C:\\xampp\\php\\php.exe', [path.join(root, 'tests', 'oracle-research.php'), '--render', preview, detail], { cwd: root, windowsHide: true, encoding: 'utf8' });
  if (generated.status !== 0) throw Error(generated.stderr + generated.stdout);
  server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost'); requests.push(url.pathname);
    let file;
    if (url.pathname === '/synk/professor/oracle-research.php') file = url.searchParams.has('id') ? detail : preview;
    else if (['/synk/professor/oracle-research.css', '/synk/professor/oracle-research.js', '/synk/professor/custom_css.css'].includes(url.pathname)) file = path.join(root, 'professor', path.basename(url.pathname));
    else if (url.pathname.startsWith('/synk/assets/')) {
      const candidate = path.resolve(root, url.pathname.slice('/synk/'.length));
      if (candidate.startsWith(path.join(root, 'assets') + path.sep)) file = candidate;
    }
    if (!file || !fs.existsSync(file)) { response.writeHead(404); response.end(); return; }
    const mime = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png', '.ttf': 'font/ttf' };
    response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }); fs.createReadStream(file).pipe(response);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const url = `http://127.0.0.1:${server.address().port}/synk/professor/oracle-research.php`;
  const executable = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  if (!executable) throw Error('Chrome or Edge is required.');
  browser = spawn(executable, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'],
    { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const address = await new Promise((resolve, reject) => {
    let output = ''; const timer = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => { output += data; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
    browser.on('error', reject);
  });
  socket = new WebSocket(address); await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let sequence = 0; const pending = new Map();
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) { const p = pending.get(message.id); pending.delete(message.id); clearTimeout(p.timer); message.error ? p.reject(Error(message.error.message)) : p.resolve(message.result); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.text);
  });
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence, timer = setTimeout(() => reject(Error(method + ' timed out.')), 15000);
    pending.set(id, { resolve, reject, timer }); socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const target = await call('Target.createTarget', { url: 'about:blank' });
  const attached = await call('Target.attachToTarget', { targetId: target.targetId, flatten: true }), sessionId = attached.sessionId;
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', { expression, returnByValue: true, userGesture: true }, sessionId);
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  await call('Page.enable', {}, sessionId); await call('Runtime.enable', {}, sessionId);
  const waitFor = async expression => {
    const until = Date.now() + 7000;
    while (Date.now() < until) { if (await evaluate(expression)) return; await delay(80); }
    throw Error('Browser condition timed out: ' + expression);
  };
  const setSearch = async text => {
    await evaluate(`document.getElementById('researchSearch').value=${JSON.stringify(text)};document.getElementById('researchSearch').dispatchEvent(new Event('input',{bubbles:true}))`);
    await delay(220);
  };
  for (const [width, height, label] of [[1200, 900, 'desktop'], [390, 844, 'mobile']]) {
    await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 500 }, sessionId);
    await call('Page.navigate', { url }, sessionId); await delay(400);
    await waitFor('document.getElementById("researchCollection")?.dataset.ready === "true"');
    check(await evaluate('document.querySelectorAll("#researchList .research-card").length === 12 && document.querySelector("#researchResultsCount").textContent.includes("of 25")'), label + ': the account portfolio starts with twelve unique records.');
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth'), label + ': no horizontal overflow.');
    check(await evaluate('!!document.querySelector("form[role=search]") && document.querySelectorAll(".research-tab").length === 5 && !document.querySelector("#research-tab-encoder, #research-tab-owner, #research-tab-co-author") && document.querySelector("#research-tab-all").getAttribute("aria-selected")==="true"'), label + ': search and only the four academic role tabs are available.');
    check(await evaluate('!document.querySelector(".research-grid img[onerror]") && Array.from(document.querySelectorAll(".research-grid img")).every(image => image.getAttribute("src").startsWith("../assets/img/sdgs/")) && document.body.textContent.includes("José Peña")'), label + ': remote HTML stays text, Unicode is readable, and images use local SDG assets.');
    check(await evaluate('document.querySelector(".professor-menu-card.active .professor-menu-title").textContent === "My Researches"'), label + ': the existing professor sidebar highlights My Researches.');
    await evaluate('document.querySelector("#research-tab-adviser").click()');
    check(await evaluate('document.querySelectorAll("#researchList .research-card").length === 2 && Array.from(document.querySelectorAll("#researchList .research-card")).every(card => ["12","4"].includes(card.dataset.researchId))'), label + ': Advisory shows only the professor\'s adviser assignments.');
    check(await evaluate('document.querySelector(".research-card .research-record-button").textContent === "View record" && !document.querySelector(".research-card .research-details-grid") && !document.querySelector(".research-card .research-expand")'), label + ': compact Oracle-style rows omit the crossed-out details.');
    check(await evaluate('parseFloat(getComputedStyle(document.querySelector(".research-card h3")).fontSize) <= 15 && document.querySelector(".research-record-id").textContent.includes("ORACLE-R-")'), label + ': the research title is small and the Oracle record ID is visible.');
    const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
    fs.writeFileSync(path.join(root, '.tmp', `oracle-research-${label}.png`), Buffer.from(screenshot.data, 'base64'));
    await evaluate('document.querySelector("#researchResults").scrollIntoView({block:"start"})'); await delay(300);
    check(await evaluate('Array.from(document.querySelector(".research-card").querySelectorAll("img")).every(image => image.complete && image.naturalWidth > 0)'), label + ': SDG badges load successfully.');
    const listScreenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
    fs.writeFileSync(path.join(root, '.tmp', `oracle-research-list-${label}.png`), Buffer.from(listScreenshot.data, 'base64'));
  }
  await evaluate('document.querySelector("#research-tab-all").click()');
  check(await evaluate('!Array.from(document.querySelectorAll("#researchList .research-card")).some(card=>card.dataset.researchId==="101")'), 'The final portfolio record is not part of the first visible batch.');
  await setSearch('Portfolio 101');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===1 && document.querySelector("#researchList .research-card").dataset.researchId==="101"'), 'Search reaches the complete authorized portfolio before those records have been scrolled into view.');
  await evaluate('document.querySelector("#researchSearchClear").click()');
  for (let turn = 0; turn < 4; turn++) {
    await evaluate('document.getElementById("researchScrollSentinel").scrollIntoView({block:"center"})'); await delay(300);
  }
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length === 25 && new Set(Array.from(document.querySelectorAll("#researchList .research-card")).map(card=>card.dataset.researchId)).size === 25 && document.querySelector("#researchLoadMore").hidden'), 'Infinite scrolling reaches all twenty-five account-related records with no duplicates.');
  await setSearch('Portfolio 101');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===1 && document.querySelector("#researchList .research-card").dataset.researchId==="101"'), 'Search finds a record beyond the initial scroll batch.');
  await evaluate('document.querySelector("#research-tab-adviser").click()');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===0 && !document.querySelector("#researchFilterEmpty").hidden'), 'Search and role filters intersect instead of including other roles.');
  await evaluate('document.querySelector("#researchSearchClear").click()');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===2 && document.querySelector("#research-tab-adviser").getAttribute("aria-selected")==="true"'), 'Clearing search keeps the chosen role.');
  for (const [tab, expected] of [['panelist',['13','12','9']],['english-critic',['12','11']]]) {
    await evaluate(`document.querySelector('#research-tab-${tab}').click()`);
    check(await evaluate(`JSON.stringify(Array.from(document.querySelectorAll('#researchList .research-card')).map(card=>card.dataset.researchId)) === ${JSON.stringify(JSON.stringify(expected))}`), `The ${tab} tab includes exactly its authorized records.`);
  }
  await evaluate('document.querySelector("#research-tab-statistician").click()');
  await setSearch('Portfolio');
  const filteredBatch = await evaluate('({count:document.querySelectorAll("#researchList .research-card").length,status:document.querySelector("#researchResultsCount").textContent,role:document.querySelector("[role=tab][aria-selected=true]").dataset.role,query:document.querySelector("#researchSearch").value,scroll:scrollY,controls:document.querySelector("#researchCollectionControls").getBoundingClientRect().top,sentinel:document.querySelector("#researchScrollSentinel").getBoundingClientRect().top,height:innerHeight,card:document.querySelector("#researchList .research-card").offsetHeight})');
  check(filteredBatch.count===12 && filteredBatch.status.includes('of 18'), 'A role and keyword search starts its own twelve-record batch: ' + JSON.stringify(filteredBatch));
  await evaluate('document.getElementById("researchScrollSentinel").scrollIntoView({block:"center"})');
  await waitFor('document.querySelectorAll("#researchList .research-card").length===18');
  check(await evaluate('Array.from(document.querySelectorAll("#researchList .research-card")).every(card=>Number(card.dataset.researchId)>=101 && Number(card.dataset.researchId)<=118)'), 'Infinite scroll preserves both the selected role and keyword filter.');
  await evaluate('document.querySelector("#research-tab-all").click()');
  await setSearch('jose pena');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length > 0'), 'Search handles Unicode names without requiring accents.');
  await setSearch('FOREIGN_RESEARCH_SECRET');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===0 && !document.querySelector("#researchFilterEmpty").hidden && !document.querySelector("#researchIndex").textContent.includes("FOREIGN_RESEARCH_SECRET")'), 'Searching cannot reveal a research belonging only to another account.');
  await setSearch('<img src=x onerror=alert(1)>');
  check(await evaluate('!document.querySelector("#researchList img[onerror]")'), 'Search input and matching titles stay inert text.');
  await setSearch('NO_MATCHING_RECORD');
  await evaluate('document.querySelector("#researchResetFilters").click()');
  await waitFor('document.querySelector("#research-tab-all").getAttribute("aria-selected")==="true" && document.querySelector("#researchSearch").value === ""');
  await setSearch('ORACLE-R-000009');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===1 && document.querySelector("#researchList .research-card").dataset.researchId==="9"'), 'Record ID search matches its account-authorized research.');
  await evaluate('document.querySelector("#researchSearchClear").click();document.querySelector("#research-tab-all").focus();document.querySelector("#research-tab-all").dispatchEvent(new KeyboardEvent("keydown",{key:"ArrowRight",bubbles:true}))');
  check(await evaluate('document.activeElement.id==="research-tab-adviser" && document.querySelector("#research-tab-adviser").getAttribute("aria-selected")==="true" && document.querySelector("#researchResults").getAttribute("aria-labelledby")==="research-tab-adviser"'), 'Tabs support keyboard navigation and label the active results panel.');
  await evaluate(`document.querySelector('.layout-navbar .layout-menu-toggle a').click()`); await delay(400);
  check(await evaluate('document.documentElement.classList.contains("layout-menu-expanded")'), 'The professor sidebar opens on mobile.');
  await evaluate(`document.querySelector('.layout-overlay.layout-menu-toggle').click()`); await delay(400);
  await evaluate('document.querySelector("#research-tab-panelist").click()');
  await evaluate(`document.querySelector('.research-record-button[href="oracle-research.php?id=9"]').click()`); await delay(400);
  const detailState = await evaluate('({url:location.href,title:document.querySelector(".research-full-detail h2")?.textContent,abstract:document.querySelector(".research-abstract")?.textContent})');
  check(detailState.title === 'Panel assignment' && detailState.abstract?.includes('Abstract & details'), 'A research opens into its detail page: ' + JSON.stringify(detailState));
  check(await evaluate(`document.querySelector('.research-full-detail a[href="oracle-research.php?download=9"]') !== null && !document.querySelector('.research-abstract script')`), 'Attachment links are scoped to the research and no remote script executes.');
  const fallbackScript = await call('Page.addScriptToEvaluateOnNewDocument', { source: 'window.IntersectionObserver = undefined;' }, sessionId);
  await call('Page.navigate', { url }, sessionId);
  await waitFor('document.getElementById("researchCollection")?.dataset.ready === "true"');
  await evaluate('document.querySelector("#researchLoadMore").click()');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===24'), 'Load more works when automatic intersection detection is unavailable.');
  await evaluate('document.querySelector("#researchLoadMore").click()');
  check(await evaluate('document.querySelectorAll("#researchList .research-card").length===25 && document.querySelector("#researchLoadMore").hidden'), 'The fallback stops at the final authorized record.');
  await call('Page.removeScriptToEvaluateOnNewDocument', { identifier: fallbackScript.identifier }, sessionId);
  check(errors.length === 0 && requests.every(value => !value.includes('synk-research-api')), 'The page has no JavaScript errors or browser calls to the Oracle API.');
  console.log(`Oracle research browser: ${checks} checks passed. Desktop and mobile screenshots saved in .tmp.`);
}

main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (call) try { await call('Browser.close'); } catch (_) {}
  if (socket?.readyState === WebSocket.OPEN) socket.close();
  if (browser && browser.exitCode === null) browser.kill();
  if (server) await new Promise(resolve => server.close(resolve));
  for (const file of [preview, detail]) if (fs.existsSync(file)) fs.unlinkSync(file);
  await delay(500);
  if (fs.existsSync(profile)) {
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const cleanup = `$workspaceRoot=(Resolve-Path -LiteralPath ${quote(path.join(root, '.tmp'))}).Path; $browserTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $browserTarget.StartsWith($workspaceRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Browser cleanup is outside the workspace.' }; Remove-Item -LiteralPath $browserTarget -Recurse -Force -ErrorAction Stop`;
    const result = spawnSync('powershell.exe', ['-NoProfile', '-Command', cleanup], { windowsHide: true, encoding: 'utf8' });
    if (result.status !== 0) { console.error(result.stderr || 'Browser profile cleanup failed.'); process.exitCode = 1; }
  }
});
