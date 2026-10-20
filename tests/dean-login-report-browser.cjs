// Actual PHP page and browser controller, with isolated API responses and a temporary profile.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..'), nonce = crypto.randomBytes(8).toString('hex'), token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `dean-login-report-browser-${nonce}.php`), profile = path.join(root, '.tmp', `dean-login-report-browser-${nonce}`);
const url = `http://localhost/synk/tmp/${path.basename(route)}?qa=${token}`;
let browser, socket, call, checks = 0;
const errors = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };

async function main() {
  fs.writeFileSync(route, `<?php define('SYNK_STUDENT_PORTAL_QA','${token}'); require '${root.replaceAll('\\', '/')}/tests/fixtures/student-portal-request.php';`);
  const response = await fetch(url + '&scenario=login_report_page');
  let html = await response.text();
  if (!response.ok || !html.includes('id="deanLoginReportConfig"')) throw Error('Unable to render the actual PHP report page.');
  const config = JSON.parse(html.match(/id="deanLoginReportConfig"[^>]*>([^<]+)<\/script>/)[1]);
  const mock = `<script>
  window.__reportRequests=[];window.__reportError=false;window.__reportInvalidJSON=false;window.__reportNetworkError=false;
  window.__reportHold=false;window.__reportHeld=null;window.__reportSnapshot=100;window.__xssExecuted=false;
  const cfg=JSON.parse(document.getElementById('deanLoginReportConfig').textContent);
  const students=[{student_id:11,student_number:'000123',student_name:'Peña, José Niño'},{student_id:12,student_number:'000124',student_name:'<img src=x onerror="window.__xssExecuted=true"> Carol Jan'}];
  const events=Array.from({length:33},(_,i)=>({...students[i<30?0:1],activity_id:String(100-i),occurred_at:cfg.today+'T00:00:'+String(59-i).padStart(2,'0')+'Z',outcome:i<30&&i%3!==0?'success':'failure',reason:i<30&&i%3!==0?'Google sign-in completed':'Google sign-in was cancelled',reason_code:i<30&&i%3!==0?'login_success':'google_cancelled',identity_verified:i<30&&i%3!==0,auth_method:'google',submitted_email:i<30?'jose.pena@sksu.edu.ph':'caroljan@sksu.edu.ph',ip_address:'192.0.2.20',browser:'Chrome',operating_system:'Windows',device_type:'Desktop',user_agent:i===0?'<img src=x onerror="window.__xssExecuted=true">':'Test browser '+i}));
  window.fetch=async function(url,options){
    const p=Object.fromEntries(options.body.entries());window.__reportRequests.push(p);
    if(window.__reportHold){window.__reportHold=false;await new Promise(resolve=>{window.__reportHeld=resolve;});}
    if(window.__reportNetworkError)throw Error('offline');
    if(window.__reportInvalidJSON)return {ok:false,json:async()=>{throw Error('Invalid JSON');}};
    if(window.__reportError)return {ok:false,json:async()=>({status:'error',message:'Your college access changed. Reload the page.'})};
    let rows=events.filter(r=>(!Number(p.student_id)||r.student_id===Number(p.student_id))&&(!Number(p.program_id)||Number(p.program_id)===1)&&(!p.search||(r.student_name+' '+r.student_number+' '+r.submitted_email+' '+r.ip_address).toLowerCase().includes(p.search.toLowerCase()))&&r.occurred_at.slice(0,10)>=p.date_from&&r.occurred_at.slice(0,10)<=p.date_to);
    const successful=rows.filter(r=>r.outcome==='success'),failed=rows.filter(r=>r.outcome==='failure');
    const summary={total_attempts:rows.length,successful_logins:successful.length,failed_attempts:failed.length,students_logged_in:new Set(successful.map(r=>r.student_id)).size,students_with_failures:new Set(failed.map(r=>r.student_id)).size,success_rate:rows.length?Math.round(successful.length/rows.length*1000)/10:0};
    const attention=students.map(s=>({...s,failed_attempts:failed.filter(r=>r.student_id===s.student_id).length,successful_logins:successful.filter(r=>r.student_id===s.student_id).length,last_failure_at:cfg.today+'T00:00:59Z'})).filter(s=>s.failed_attempts).sort((a,b)=>b.failed_attempts-a.failed_attempts);
    const daily=[];for(let d=new Date(p.date_from+'T00:00:00Z');d.toISOString().slice(0,10)<=p.date_to;d.setUTCDate(d.getUTCDate()+1)){const key=d.toISOString().slice(0,10);daily.push({day:key,successful_logins:key===cfg.today?successful.length:0,failed_attempts:key===cfg.today?failed.length:0});}
    if(p.outcome!=='all')rows=rows.filter(r=>r.outcome===p.outcome);
    const size=Number(p.page_size),total=rows.length,pages=Math.max(1,Math.ceil(total/size)),page=Math.min(Number(p.page),pages);
    return {ok:true,json:async()=>({status:'ok',summary,daily,attention,reasons:failed.length?[{reason:'Google sign-in was cancelled',attempts:failed.length}]:[],rows:rows.slice((page-1)*size,page*size),programs:[{program_id:1,label:'BSIS'}],filters:p,snapshot:Number(p.snapshot)||window.__reportSnapshot,timezone:'Asia/Manila',generated_at:cfg.today+'T01:00:00Z',pagination:{page,pages,page_size:size,total,from:total?(page-1)*size+1:0,to:Math.min(page*size,total)}})};
  };</script>`;
  html = html.replace('<script src="login-activity.js', mock + '<script src="login-activity.js');
  fs.writeFileSync(route, `<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals('${token}',$_GET['qa'])){http_response_code(404);exit;}header('Content-Type: text/html; charset=utf-8');echo base64_decode('${Buffer.from(html).toString('base64')}');`);
  const executable = [process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe', process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  if (!executable) throw Error('Chrome or Edge is required.');
  browser = spawn(executable, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const address = await new Promise((resolve, reject) => {
    let output = ''; const timeout = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => { output += data; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
    browser.on('error', error => { clearTimeout(timeout); reject(error); });
  });
  socket = new WebSocket(address); await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let sequence = 0; const pending = new Map();
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) { const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timeout); message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
  });
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => { const id = ++sequence, timeout = setTimeout(() => { pending.delete(id); reject(Error(method + ' timed out.')); }, 15000); pending.set(id, { resolve, reject, timeout }); socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) })); });
  const target = await call('Target.createTarget', { url: 'about:blank' });
  const session = (await call('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session); await call('Network.enable', {}, session); await call('Network.setBlockedURLs', { urls: ['https://*'] }, session);
  const evaluate = async expression => { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, session); if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text); return result.result.value; };
  const until = async expression => { for (let attempt = 0; attempt < 120; attempt++) { if (await evaluate(`Boolean(${expression})`)) return; await delay(100); } throw Error('Timed out: ' + expression); };
  const loaded = () => until('document.getElementById("deanLoginReport")?.getAttribute("aria-busy")==="false"');
  const click = id => evaluate(`document.getElementById(${JSON.stringify(id)}).click()`);
  const change = async (id, value) => { await evaluate(`document.getElementById(${JSON.stringify(id)}).value=${JSON.stringify(value)};document.getElementById(${JSON.stringify(id)}).dispatchEvent(new Event("change"))`); await loaded(); };
  const resize = async width => { await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false }, session); await delay(150); };
  const screenshot = async name => fs.writeFileSync(path.join(root, '.tmp', name), Buffer.from((await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }, session)).data, 'base64'));
  await resize(1440); await call('Page.navigate', { url }, session); await loaded();
  check(await evaluate('window.__reportRequests.length===1 && document.getElementById("deanLoginFrom").value===JSON.parse(document.getElementById("deanLoginReportConfig").textContent).date_from && document.querySelectorAll("#deanLoginDaily tbody tr").length===5'), 'The initial request and daily view default to five inclusive Philippine dates.');
  check(await evaluate('document.querySelectorAll("#deanLoginSummary .card").length===6 && document.getElementById("deanLoginSummary").textContent.includes("60.6%") && document.getElementById("deanLoginAttention").textContent.includes("No successful login in this range")'), 'Six statistics and failure-only student tracing are visible.');
  check(await evaluate('document.querySelectorAll("#deanLoginRows tr").length===25 && document.querySelector("#deanLoginRows time").textContent.includes("08:00:59")'), 'The newest history renders 25 rows and Philippine timestamps.');
  check(await evaluate('!window.__xssExecuted && !document.querySelector("#deanLoginRows img") && !document.querySelector("#deanLoginAttention img") && document.querySelector(".dean-login-agent").textContent.includes("<img")'), 'Student and browser metadata are escaped throughout the page.');
  for (const width of [1440, 1280, 1024, 768, 390, 320]) {
    await resize(width);
    if (!await evaluate('document.documentElement.scrollWidth<=innerWidth')) { await screenshot('dean-login-report-overflow.png'); console.log(await evaluate('[...document.querySelectorAll("main *")].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,10).map(e=>({tag:e.tagName,id:e.id,class:e.className,right:e.getBoundingClientRect().right}))')); }
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `The report fits ${width}px without page overflow.`);
    if (width === 1440 || width === 390) await screenshot(`dean-login-report-${width}.png`);
  }
  await resize(1440); await evaluate('document.getElementById("deanLoginSearch").value="unsaved search"'); await click('deanLoginNext'); await loaded();
  check(await evaluate('document.querySelectorAll("#deanLoginRows tr").length===8 && window.__reportRequests.at(-1).snapshot==="100" && window.__reportRequests.at(-1).search===""'), 'Pagination uses the loaded snapshot and applied filters, ignoring unsaved edits.');
  await evaluate('document.getElementById("deanLoginSearch").value=""'); await change('deanLoginOutcome', 'failure');
  check(await evaluate('document.querySelectorAll("#deanLoginRows tr").length===13 && [...document.querySelectorAll("#deanLoginRows .badge")].every(b=>b.textContent==="Failed") && document.getElementById("deanLoginSummary").textContent.includes("60.6%")'), 'Outcome filtering applies to history while preserving complete statistics.');
  await change('deanLoginOutcome', 'all'); await change('deanLoginPageSize', '50');
  check(await evaluate('document.querySelectorAll("#deanLoginRows tr").length===33 && document.getElementById("deanLoginNext").disabled'), 'Changing page size displays the complete small result set.');
  await evaluate('document.querySelector("#deanLoginAttention [data-login-student=\\"12\\"]").click()'); await loaded();
  check(await evaluate('!document.getElementById("deanLoginTrace").hidden && document.getElementById("deanLoginTraceLabel").textContent.includes("000124") && document.querySelectorAll("#deanLoginRows tr").length===3 && window.__reportRequests.at(-1).student_id==="12"'), 'Trace isolates the exact student and exposes a clear return action.');
  await click('deanLoginClearTrace'); await loaded();
  check(await evaluate('document.getElementById("deanLoginTrace").hidden && window.__reportRequests.at(-1).student_id==="0" && document.querySelectorAll("#deanLoginRows tr").length===33'), 'Clearing tracing restores all college students.');
  await evaluate('document.querySelector("[data-login-days=\\"30\\"]").click()'); await loaded();
  check(await evaluate('document.querySelectorAll("#deanLoginDaily tbody tr").length===30 && window.__reportRequests.at(-1).snapshot==="0"'), 'Quick ranges include the requested number of local calendar days.');
  await click('deanLoginReset'); await loaded();
  check(await evaluate('document.querySelectorAll("#deanLoginDaily tbody tr").length===5 && document.getElementById("deanLoginOutcome").value==="all" && document.getElementById("deanLoginProgram").value==="0"'), 'Reset restores the five-day range and clears report filters.');
  await evaluate('document.getElementById("deanLoginSearch").value="absent"'); await click('deanLoginApply'); await loaded();
  check(await evaluate('document.getElementById("deanLoginRows").textContent.includes("No login attempts match") && document.querySelector("#deanLoginRows td").colSpan===7 && document.getElementById("deanLoginPageInfo").textContent==="0 attempts"'), 'An empty search shows a complete empty state and no stale student records.');
  await evaluate('document.getElementById("deanLoginSearch").value="";window.__reportError=true'); await click('deanLoginRefresh'); await loaded();
  check(await evaluate('document.getElementById("deanLoginNotice").textContent.includes("college access changed") && !document.querySelector("#deanLoginRows time") && !document.getElementById("deanLoginRefresh").disabled && !document.getElementById("deanLoginSummary").textContent.includes("60.6%")'), 'Access errors clear all statistics/history and retain a working retry.');
  await evaluate('window.__reportError=false;window.__reportInvalidJSON=true'); await click('deanLoginRefresh'); await loaded();
  check(await evaluate('document.getElementById("deanLoginNotice").textContent.includes("Reload the page")'), 'Expired-session HTML and invalid JSON produce a useful message.');
  await evaluate('window.__reportInvalidJSON=false;window.__reportNetworkError=true'); await click('deanLoginRefresh'); await loaded();
  check(await evaluate('document.getElementById("deanLoginNotice").textContent.includes("Unable to connect")'), 'Connection errors are recoverable.');
  await evaluate('window.__reportNetworkError=false;window.__reportSnapshot=200'); await click('deanLoginRefresh'); await loaded();
  check(await evaluate('document.querySelectorAll("#deanLoginRows tr").length===33 && window.__reportRequests.at(-1).snapshot==="0"'), 'Refresh reloads the latest snapshot after an error.');
  await evaluate('window.__reportHold=true;document.getElementById("deanLoginSearch").value="slow";document.getElementById("deanLoginFilters").dispatchEvent(new Event("submit",{cancelable:true}))'); await until('Boolean(window.__reportHeld)');
  await evaluate('document.getElementById("deanLoginSearch").value="absent";document.getElementById("deanLoginFilters").dispatchEvent(new Event("submit",{cancelable:true}))'); await loaded();
  await evaluate('window.__reportHeld();window.__reportHeld=null'); await delay(150);
  check(await evaluate('document.getElementById("deanLoginRows").textContent.includes("No login attempts match") && document.getElementById("deanLoginPageInfo").textContent==="0 attempts"'), 'A slower superseded request cannot overwrite newer filters.');
  check(await evaluate(`window.__reportRequests.every(r=>r.csrf_token===${JSON.stringify(config.csrf_token)}&&r.college_id===${JSON.stringify(String(config.college_id))}&&r.campus_id===${JSON.stringify(String(config.campus_id))})`), 'Every request retains the server-provided college and CSRF token.');
  check(errors.length === 0, 'No browser JavaScript errors: ' + errors.join('; '));
  console.log(`Dean login report browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}

main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (call) try { await call('Browser.close'); } catch (_) {}
  if (socket?.readyState === WebSocket.OPEN) socket.close();
  if (browser && browser.exitCode === null) browser.kill();
  await delay(500); if (fs.existsSync(route)) fs.unlinkSync(route);
  if (fs.existsSync(profile)) {
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const cleanup = `$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root, '.tmp'))}).Path; $profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Profile cleanup is outside the workspace.' }; Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;
    const result = spawnSync('powershell.exe', ['-NoProfile', '-Command', cleanup], { windowsHide: true, encoding: 'utf8' });
    if (result.status !== 0) { console.error(result.stderr || 'Browser profile cleanup failed.'); process.exitCode = 1; }
  }
});
