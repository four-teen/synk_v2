// Browser checks against the real professor editor, rendered with rollback-only fixtures.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temp = path.join(root, '.tmp');
const nonce = crypto.randomBytes(8).toString('hex');
const html = path.join(temp, `faculty-degrees-${nonce}.html`);
const profile = path.join(temp, `faculty-degrees-browser-${nonce}`);
let server, browser, ws, call, checks = 0;
const errors = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => {if (!ok) throw Error(message); checks++;};

async function main() {
  fs.mkdirSync(temp, {recursive:true});
  const render = spawnSync('php', [path.join(__dirname, 'faculty-profile-degrees-http.php'), '--render', html], {cwd:root, encoding:'utf8', windowsHide:true});
  if (render.status !== 0 || !fs.existsSync(html)) throw Error(render.stdout + render.stderr);
  const markup = fs.readFileSync(html, 'utf8').replace(/<base\b[^>]*>/i, '');
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/synk/professor/manage-profile.php') {
      res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); res.end(markup); return;
    }
    if (url.pathname === '/synk/professor/education-options.php') {
      res.writeHead(200, {'Content-Type':'application/json'}); res.end(JSON.stringify({results:[], pagination:{more:false}})); return;
    }
    const file = path.resolve(root, url.pathname.replace(/^\/synk\//, ''));
    const mime = {'.js':'application/javascript', '.css':'text/css', '.svg':'image/svg+xml', '.png':'image/png', '.jpg':'image/jpeg', '.gif':'image/gif', '.woff2':'font/woff2', '.woff':'font/woff', '.ttf':'font/ttf'};
    if (!url.pathname.startsWith('/synk/') || !file.startsWith(root + path.sep) || !mime[path.extname(file)] || !fs.existsSync(file)) {res.writeHead(404); res.end(); return;}
    res.writeHead(200, {'Content-Type':mime[path.extname(file)]}); fs.createReadStream(file).pipe(res);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const exe = [process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe', process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  if (!exe) throw Error('Chrome or Edge is required for browser QA.');
  browser = spawn(exe, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {windowsHide:true, stdio:['ignore','ignore','pipe']});
  const address = await new Promise((resolve, reject) => {
    let logs = '';
    const timer = setTimeout(() => reject(Error('Browser start timeout.')), 15000);
    browser.stderr.on('data', chunk => {logs += chunk; const match = logs.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) {clearTimeout(timer); resolve(match[1]);}});
    browser.on('error', reject);
  });
  ws = new WebSocket(address);
  await new Promise((resolve, reject) => {ws.addEventListener('open', resolve, {once:true}); ws.addEventListener('error', reject, {once:true});});
  let sequence = 0;
  const pending = new Map();
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
      const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timer);
      message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result);
    }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (message.method === 'Page.javascriptDialogOpening') call('Page.handleJavaScriptDialog', {accept:true}, message.sessionId).catch(() => {});
  });
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence;
    const timer = setTimeout(() => {pending.delete(id); reject(Error(method + ' timeout.'));}, 15000);
    pending.set(id, {resolve,reject,timer}); ws.send(JSON.stringify({id, method, params, ...(sessionId ? {sessionId} : {})}));
  });
  const target = await call('Target.createTarget', {url:'about:blank'});
  const session = (await call('Target.attachToTarget', {targetId:target.targetId, flatten:true})).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', {expression,returnByValue:true,awaitPromise:true,userGesture:true}, session);
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  const until = async expression => {
    for (let i = 0; i < 120; i++) {if (await evaluate('Boolean(' + expression + ')')) return; await delay(100);}
    throw Error('Condition timed out: ' + expression + '; ' + errors.join('; '));
  };
  const navigate = async () => {
    await call('Page.navigate', {url:`http://127.0.0.1:${server.address().port}/synk/professor/manage-profile.php`}, session);
    await until('document.readyState === "complete" && document.querySelector("[data-add-degree]")');
  };
  const resize = async width => {await call('Emulation.setDeviceMetricsOverride', {width,height:900,deviceScaleFactor:1,mobile:false}, session); await delay(100);};
  await resize(1440); await navigate();
  await until('window.jQuery?.fn?.select2 && document.querySelector("#additional_degree_0_degree.select2-hidden-accessible")');
  check(await evaluate('document.querySelectorAll("[data-add-degree]").length === 3 && document.querySelector("[data-degree-list=bachelor]").textContent.includes("Bachelor\u0027s degree 2") && document.getElementById("additional_degree_0_degree").value === "Previously saved second degree"'), 'The saved second Bachelor degree appears under Bachelor with one Add button per level.');
  await evaluate('document.querySelector("[data-add-degree=bachelor]").click(); document.querySelector("[data-add-degree=doctoral]").click()');
  check(await evaluate('document.querySelectorAll("[data-degree-list=bachelor] [data-additional-degree]").length === 2 && document.querySelectorAll("[data-degree-list=doctoral] [data-additional-degree]").length === 1 && document.querySelectorAll("[data-degree-list=master] [data-additional-degree]").length === 0'), 'Each Add button appends only inside its own degree level.');
  check(await evaluate('[...document.querySelectorAll("[data-additional-degree]")].every(row => row.querySelectorAll(".select2-hidden-accessible").length === 4 && row.querySelector("input[type=hidden]").value === row.closest("[data-degree-list]").dataset.degreeList)'), 'New rows initialize all searchable education fields and inherit their section level.');
  // Enter the new Doctoral program through the searchable control, including commit on blur.
  await evaluate('window.jQuery("[data-degree-list=doctoral] [data-education-type=degree]").select2("open"); true');
  await until('document.querySelector(".select2-container--open .select2-search__field")');
  await evaluate('const search = document.querySelector(".select2-container--open .select2-search__field"); search.value = "Doctor of Education"; search.dispatchEvent(new Event("input", {bubbles:true})); window.jQuery("[data-degree-list=doctoral] [data-education-type=degree]").select2("close"); true');
  check(await evaluate('document.querySelector("[data-degree-list=doctoral] [data-education-type=degree]").value === "Doctor of Education"'), 'A newly typed program in a dynamically added Doctoral row is retained.');
  await evaluate('document.querySelector("[data-degree-list=bachelor] [data-remove-degree]").click()');
  check(await evaluate('document.querySelector("[data-degree-list=bachelor] .profile-additional-degree-title").textContent === "Bachelor\u0027s degree 2" && document.querySelector("[data-degree-list=doctoral] .profile-additional-degree-title").textContent === "Doctoral degree 2" && document.querySelector("[data-degree-list=doctoral] [data-education-type=degree]").value === "Doctor of Education"'), 'Removing a Bachelor degree renumbers its section and preserves Doctoral entries.');
  await evaluate('document.querySelector("[data-add-degree=master]").click(); document.querySelector("[data-degree-list=master] [data-remove-degree]").click()');
  await evaluate(`(() => {
    const set = (id, value) => {const field = document.getElementById(id); if (field.matches('select') && ![...field.options].some(option => option.value === value)) field.add(new Option(value, value)); field.value = value; window.jQuery(field).trigger('change');};
    set('bachelor_status', 'Completed'); set('bachelor_degree', 'BS Computer Science');
    set('doctoral_status', 'Completed'); set('doctoral_degree', 'PhD Computer Science');
    for (const row of document.querySelectorAll('[data-additional-degree]')) {
      set(row.querySelector('select[name$="[status]"]').id, 'Completed');
      if (row.closest('[data-degree-list]').dataset.degreeList === 'bachelor') set(row.querySelector('[data-education-type=degree]').id, 'BS Nursing');
    }
  })()`);
  check(await evaluate(`(() => {const data = new FormData(document.getElementById('faculty-profile-form')); const levels = [...data].filter(([key]) => key.endsWith('[level]')).map(([,value]) => value); return levels.join(',') === 'bachelor,doctoral' && data.get('bachelor_degree') === 'BS Computer Science' && data.get('doctoral_degree') === 'PhD Computer Science' && [...data.values()].includes('BS Nursing') && [...data.values()].includes('Doctor of Education') && ![...data.keys()].some(key => key.includes('__INDEX__'));})()`), 'The submitted form contains exactly two Bachelor degrees and two Doctoral degrees with unique row names.');
  check(await evaluate('document.getElementById("profile-save-status").textContent === "Unsaved changes" && document.activeElement.matches("[data-add-degree=master]")'), 'Adding and removing degrees marks the profile unsaved and restores focus.');
  const duplicateIds = await evaluate('(()=>{const ids=[...document.querySelectorAll("#education [id]")].map(node=>node.id); return ids.filter((id,index)=>ids.indexOf(id)!==index);})()');
  check(duplicateIds.length === 0, 'Added and removed rows never create duplicate control IDs: ' + duplicateIds.join(', '));
  await evaluate('document.querySelector("[data-profile-tab=researches]").click(); document.querySelector("[data-profile-tab=profile]").click()');
  check(await evaluate('document.querySelector("[data-degree-list=doctoral] [data-education-type=degree]").value === "Doctor of Education" && !document.getElementById("faculty-panel-profile").hidden'), 'Switching profile tabs preserves additional degree entries.');
  for (const width of [1440, 768, 390, 320]) {
    await resize(width);
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth && [...document.querySelectorAll("[data-add-degree], [data-remove-degree]")].every(button => {const box = button.getBoundingClientRect(); return box.height >= 44 && box.left >= 0 && box.right <= innerWidth;})'), 'Degree sections and their touch targets fit at ' + width + 'px.');
    if (width === 1440 || width === 390) {
      await evaluate('document.getElementById("education").scrollIntoView({block:"start",behavior:"instant"})');
      fs.writeFileSync(path.join(temp, `faculty-degrees-${width === 1440 ? 'desktop' : 'mobile'}.png`), Buffer.from((await call('Page.captureScreenshot', {format:'png'}, session)).data, 'base64'));
    }
  }
  // The same Add/Remove controls must work with plain text inputs if the CDN is unavailable.
  await call('Network.enable', {}, session);
  await call('Network.setBlockedURLs', {urls:['*cdn.jsdelivr.net/npm/select2@*']}, session);
  await navigate();
  check(await evaluate('!window.jQuery.fn.select2 && document.getElementById("bachelor_degree").matches("input")'), 'Unavailable Select2 leaves usable plain education inputs.');
  await evaluate('document.querySelector("[data-add-degree=doctoral]").click()');
  check(await evaluate('document.querySelector("[data-degree-list=doctoral] input[data-education-type=degree]") && document.querySelector("[data-degree-list=doctoral] input[type=hidden]").value === "doctoral"'), 'Adding a Doctoral degree also works without Select2.');
  check(errors.length === 0, 'Browser errors: ' + errors.join('; '));
  console.log('Faculty profile degree browser: ' + checks + ' checks passed. Desktop/mobile screenshots saved in .tmp.');
}

main().catch(error => {console.error(error); process.exitCode = 1;}).finally(async () => {
  if (call) try {await call('Browser.close');} catch {}
  if (ws) ws.close();
  if (browser && browser.exitCode === null) {browser.kill(); await new Promise(resolve => browser.once('exit', resolve));}
  if (server) await new Promise(resolve => server.close(resolve));
  if (fs.existsSync(html)) fs.unlinkSync(html);
  const relative = path.relative(temp, profile);
  if (relative && !relative.startsWith('..') && !path.isAbsolute(relative) && fs.existsSync(profile)) fs.rmSync(profile, {recursive:true,force:true});
});
