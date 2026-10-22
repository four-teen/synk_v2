// Preview the real dashboard view with isolated records; no application databases are used.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), nonce = crypto.randomBytes(6).toString('hex');
const preview = path.join(root, '.tmp', `dashboard-${nonce}.html`), summaryFile = path.join(root, '.tmp', `dashboard-${nonce}.json`);
const profile = path.join(root, '.tmp', `professor-dashboard-browser-${nonce}`);
let server, browser, socket, call, checks = 0, mode = 'success';
const errors = [], requests = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (condition, message) => { if (!condition) throw Error(message); checks++; };

async function main() {
  const generated = spawnSync('C:\\xampp\\php\\php.exe', [path.join(root, 'tests/professor-dashboard.php'), '--render', preview, summaryFile], { cwd: root, windowsHide: true, encoding: 'utf8' });
  if (generated.status !== 0) throw Error(generated.stderr + generated.stdout);
  const summary = JSON.parse(fs.readFileSync(summaryFile, 'utf8'));
  server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost'); requests.push(url.pathname + url.search);
    if (url.pathname === '/synk/professor/dashboard-research.php') {
      const current = mode;
      const responseSummary = JSON.parse(JSON.stringify(summary));
      if (current === 'unsafe') responseSummary.summary.trend.series[0].name = '<img src=x onerror=alert(1)> BSCS';
      if (current === 'missing-years') responseSummary.summary.trend = { years: [], series: [], without_year: 5 };
      await delay(current === 'loading' ? 1500 : 80);
      response.writeHead(current === 'error' ? 502 : 200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store, private' });
      response.end(JSON.stringify(current === 'error' ? { ok: false, message: 'Oracle is unavailable for this isolated test.' }
        : current === 'empty' ? { ok: true, email: 'faculty@sksu.edu.ph', summary: { total: 0, published: 0, roles: [], recent: [] } } : responseSummary));
      return;
    }
    let file;
    if (url.pathname === '/synk/professor/index.php') file = preview;
    else if (['dashboard.css', 'dashboard.js', 'dashboard-charts.js', 'custom_css.css'].includes(path.basename(url.pathname))) file = path.join(root, 'professor', path.basename(url.pathname));
    else if (url.pathname.startsWith('/synk/assets/')) {
      const candidate = path.resolve(root, url.pathname.slice('/synk/'.length));
      if (candidate.startsWith(path.join(root, 'assets') + path.sep)) file = candidate;
    }
    if (!file || !fs.existsSync(file)) { response.writeHead(404); response.end(); return; }
    const mime = { '.html': 'text/html; charset=utf-8', '.js': 'application/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png', '.ttf': 'font/ttf' };
    response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream' }); fs.createReadStream(file).pipe(response);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const url = `http://127.0.0.1:${server.address().port}/synk/professor/index.php`;
  const executable = ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  if (!executable) throw Error('Chrome or Edge is required.');
  browser = spawn(executable, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
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
    while (Date.now() < until) { if (await evaluate(expression)) return; await delay(70); }
    throw Error('Browser condition timed out: ' + expression);
  };
  for (const [width, height, label] of [[1440, 1000, 'desktop'], [1024, 900, 'tablet'], [390, 844, 'mobile']]) {
    await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 500 }, sessionId);
    await call('Page.navigate', { url }, sessionId);
    await waitFor('document.getElementById("dashboardResearch")?.dataset.state === "content"');
    await waitFor('document.getElementById("dashboardResearchChart")?.dataset.ready === "true" && document.getElementById("dashboardWorkloadChart")?.dataset.ready === "true"');
    await delay(450);
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth'), label + ': no horizontal overflow.');
    check(await evaluate('document.querySelectorAll(".dashboard-research-record").length === 4 && document.querySelector("[data-research-total]").textContent === "5" && document.querySelector("[data-research-published]").textContent === "3"'), label + ': render the actual research counts and four recent records.');
    check(await evaluate('document.querySelectorAll(".dashboard-role-pill").length === 2 && document.querySelector(".dashboard-role-pill").textContent.includes("Advisory")'), label + ': show only assigned research roles.');
    check(await evaluate('document.querySelector(".dashboard-research-record h3").textContent.includes("<img src=x onerror=alert(1)>") && !document.querySelector(".dashboard-research-record img")'), label + ': remote text cannot become executable HTML.');
    check(await evaluate('document.querySelector(".professor-profile-ring").getAttribute("aria-valuenow") === "100" && parseFloat(getComputedStyle(document.querySelector(".professor-profile-ring")).width)===62 && !document.querySelector("main [role=progressbar]")'), label + ': compact profile ring belongs to the sidebar.');
    check(await evaluate('document.querySelector(".dashboard-research-record h3 a").getAttribute("href") === "oracle-research.php?id=5"'), label + ': records link to authenticated research details.');
    check(await evaluate('document.querySelectorAll("#dashboardResearchChart .apexcharts-area-series .apexcharts-series").length === 3 && document.querySelectorAll(".dashboard-chart-key").length === 3'), label + ': three colorful program series render in the Apex research chart.');
    check(await evaluate('document.querySelectorAll("#dashboardWorkloadChart .apexcharts-bar-area").length === 5'), label + ': Apex workload chart renders the five current-term classes.');
    check(await evaluate('document.querySelector("#dashboardResearchChart").textContent.includes("Academic year") && document.querySelector("#dashboardResearchChart").textContent.includes("Researches")'), label + ': research axes are labeled for academic year and research counts.');
    if (width >= 1200) check(await evaluate('document.querySelector(".professor-profile-card").getBoundingClientRect().top > document.querySelector(".professor-menu-card.active").getBoundingClientRect().bottom && document.querySelector(".professor-profile-card").getBoundingClientRect().bottom <= innerHeight'), 'Desktop profile card remains near the bottom of the visible sidebar.');
    const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
    fs.writeFileSync(path.join(root, '.tmp', `professor-dashboard-${label}.png`), Buffer.from(screenshot.data, 'base64'));
    await evaluate('window.scrollTo({top:scrollY+document.querySelector(".dashboard-chart-figure").getBoundingClientRect().top-25,behavior:"instant"})');
    await delay(200);
    const graphScreenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
    fs.writeFileSync(path.join(root, '.tmp', `professor-dashboard-${label}-charts.png`), Buffer.from(graphScreenshot.data, 'base64'));
    check(await evaluate('document.querySelector("#dashboardWorkloadChart .apexcharts-canvas").getBoundingClientRect().width <= document.querySelector(".dashboard-workload-scroll").clientWidth + 1'), label + ': workload chart fits without clipping its bars or numeric axis.');
    await evaluate('document.getElementById("dashboardResearchProgram").value="2";document.getElementById("dashboardResearchProgram").dispatchEvent(new Event("change"))');
    await waitFor('document.querySelector("#dashboardResearchChart")?.dataset.ready==="true"');
    check(await evaluate('document.querySelectorAll("#dashboardResearchChart .apexcharts-area-series .apexcharts-series").length === 1 && document.querySelector("#dashboardResearchLegend").textContent === "BSIT" && JSON.stringify(Array.from(document.querySelectorAll("#dashboardResearchChartTable td")).map(cell=>cell.textContent))===JSON.stringify(["1","1","1"])'), label + ': selecting a program updates the chart and exact counts.');
    if (width < 500) {
      await evaluate('window.scrollTo({top:0,behavior:"instant"})');
      await evaluate('document.querySelector(".layout-navbar .layout-menu-toggle a").click()'); await delay(350);
      check(await evaluate('document.documentElement.classList.contains("layout-menu-expanded")'), 'Mobile professor menu can be opened.');
      const menuScreenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
      fs.writeFileSync(path.join(root, '.tmp', 'professor-dashboard-mobile-sidebar.png'), Buffer.from(menuScreenshot.data, 'base64'));
    }
  }
  mode = 'error'; await call('Page.navigate', { url }, sessionId);
  await waitFor('document.getElementById("dashboardResearch")?.dataset.state === "error"');
  check(await evaluate('document.querySelector("[data-research-total]").textContent === "—" && document.querySelector("[data-research-published]").textContent === "—" && !document.getElementById("dashboardResearchError").hidden'), 'Oracle errors show unavailable counts instead of false zeroes.');
  check(await evaluate('document.querySelectorAll(".dashboard-metric")[0].querySelector("strong").textContent === "5" && !!document.querySelector(".dashboard-workload-link")'), 'Teaching stays usable when Oracle is unavailable.');
  mode = 'empty'; await evaluate('document.getElementById("dashboardResearchRetry").click()');
  await waitFor('document.getElementById("dashboardResearch").dataset.state === "empty"');
  check(await evaluate('document.querySelector("[data-research-total]").textContent === "0" && document.querySelector("#dashboardResearchList").children.length === 0'), 'Retry can recover to a valid empty portfolio.');
  mode = 'loading'; await call('Page.navigate', { url }, sessionId);
  await waitFor('document.getElementById("dashboardResearch")?.dataset.state === "loading"');
  check(await evaluate('document.querySelector("[data-research-total]").textContent === "—" && document.querySelector(".dashboard-welcome").textContent.includes("Professor Alex Reyes")'), 'A slow Oracle request does not block the local dashboard.');
  await waitFor('document.getElementById("dashboardResearch").dataset.state === "content"');
  mode = 'error'; await evaluate('document.getElementById("dashboardResearchRefresh").click()');
  await waitFor('document.getElementById("dashboardResearch").dataset.state === "error"');
  check(await evaluate('document.querySelector("[data-research-total]").textContent === "—" && document.getElementById("dashboardResearchContent").hidden'), 'Failed refresh cannot leave stale research figures presented as current.');
  mode = 'unsafe'; await call('Page.navigate', { url }, sessionId);
  await waitFor('document.getElementById("dashboardResearchChart")?.dataset.ready === "true"');
  check(await evaluate('!document.querySelector("#dashboardResearchLegend img, #dashboardResearchChartTable img") && document.querySelector("#dashboardResearchLegend").textContent.includes("<img src=x onerror=alert(1)>")'), 'Oracle program names stay inert text in chart legends and data tables.');
  mode = 'missing-years'; await call('Page.navigate', { url }, sessionId);
  await waitFor('document.getElementById("dashboardResearch")?.dataset.state === "content"');
  check(await evaluate('document.querySelector("[data-research-total]").textContent === "5" && document.getElementById("dashboardResearchChart").hidden && !document.getElementById("dashboardResearchChartMessage").hidden && document.getElementById("dashboardResearchChartNote").textContent.includes("5 records")'), 'Missing academic years keep real portfolio totals without inventing a trend.');
  const unavailableLibrary = await call('Page.addScriptToEvaluateOnNewDocument', { source: 'Object.defineProperty(window,"ApexCharts",{configurable:true,get(){return undefined},set(value){}});' }, sessionId);
  mode = 'success'; await call('Page.navigate', { url }, sessionId);
  await waitFor('document.getElementById("dashboardResearch")?.dataset.state === "content"');
  check(await evaluate('document.getElementById("dashboardResearchChartTable").closest("details").open && document.getElementById("dashboardWorkloadData").open && document.querySelectorAll("#dashboardResearchChartTable tbody tr").length === 3'), 'When ApexCharts is unavailable, both charts reveal the exact tabular data.');
  await call('Page.removeScriptToEvaluateOnNewDocument', { identifier: unavailableLibrary.identifier }, sessionId);
  check(errors.length === 0 && requests.every(value => !value.includes('synk-research-api') && !value.includes('email=')), 'The browser makes no direct Oracle requests, sends no email override, and has no JavaScript errors.');
  console.log(`Professor dashboard browser: ${checks} checks passed. Desktop, tablet, mobile, and sidebar screenshots saved in .tmp.`);
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (call) try { await call('Browser.close'); } catch (_) {}
  if (socket?.readyState === WebSocket.OPEN) socket.close();
  if (browser && browser.exitCode === null) browser.kill();
  if (server) await new Promise(resolve => server.close(resolve));
  for (const file of [preview, summaryFile]) if (fs.existsSync(file)) fs.unlinkSync(file);
  await delay(500);
  if (fs.existsSync(profile)) {
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const cleanup = `$workspaceRoot=(Resolve-Path -LiteralPath ${quote(path.join(root, '.tmp'))}).Path; $browserTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $browserTarget.StartsWith($workspaceRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Browser cleanup is outside the workspace.' }; Remove-Item -LiteralPath $browserTarget -Recurse -Force -ErrorAction Stop`;
    const result = spawnSync('powershell.exe', ['-NoProfile', '-Command', cleanup], { windowsHide: true, encoding: 'utf8' });
    if (result.status !== 0) { console.error(result.stderr || 'Browser profile cleanup failed.'); process.exitCode = 1; }
  }
});
