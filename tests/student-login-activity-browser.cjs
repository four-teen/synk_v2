// Real directory and modal controllers, isolated API responses and temporary browser profile.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, spawnSync, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const nonce = crypto.randomBytes(8).toString('hex');
const token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `login-activity-browser-${nonce}.php`);
const profile = path.join(root, '.tmp', `login-activity-browser-${nonce}`);
let browser, socket, call, checks = 0;
const errors = [];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };

async function main() {
  const fixture = JSON.parse(execFileSync('php', ['tests/fixtures/college-dean-students-request.php', 'page'], { cwd: root, windowsHide: true, encoding: 'utf8' }));
  let html = fixture.response.replace('<head>', '<head><base href="http://localhost/synk/college-dean/">');
  const mock = `<script>
  window.__loginRequests=[]; window.__loginError=false; window.__holdLogin=false; window.__heldLogin=null; window.__loginSnapshot=100;
  window.__xssExecuted=false; window.__directoryEmpty=false;
  const students=[
    {student_id:11,student_number:'000123',student_name:'Peña, José Niño',email_address:'jose.pena@sksu.edu.ph',programs:'BSIS',sections:'BSIS 1A, BSIS 2C',subject_count:2,login_success_count:20,login_failure_count:11,login_tracking_available:true},
    {student_id:12,student_number:'000124',student_name:'Ambrocio, Carol Jan',email_address:'caroljan@sksu.edu.ph',programs:'BSIS',sections:'BSIS 2C',subject_count:1,login_success_count:0,login_failure_count:0,login_tracking_available:true}
  ];
  window.fetch=async function(url,options){
    const p=Object.fromEntries(options.body.entries()); window.__loginRequests.push(p); let data;
    if(p.action==='bootstrap')data={status:'ok',options:{programs:[{program_id:1,label:'BSIS'}],terms:[{key:'2:1',ay_label:'2026-2027',label:'2026-2027 - 1st Semester'}],sections:[]},current_term:{ay_id:2,semester:1}};
    else if(p.action==='list'){const rows=window.__directoryEmpty?[]:students;data={status:'ok',rows,summary:{students:rows.length,classes:rows.length?3:0,programs:rows.length?1:0},pagination:{page:1,pages:1,total:rows.length,from:rows.length?1:0,to:rows.length}};}
    else if(p.action==='login_activity'){
      const held=window.__holdLogin; if(held)await new Promise(resolve=>{window.__heldLogin=resolve;});
      if(window.__loginError)return {ok:false,json:async()=>({status:'error',message:'Your college access changed. Reload the page.'})};
      const empty=Number(p.student_id)===12;
      let rows=Array.from({length:31},(_,i)=>({activity_id:String(100-i),occurred_at:'2026-10-01T00:00:'+String(30-i).padStart(2,'0')+'Z',submitted_email:'jose.pena@sksu.edu.ph',outcome:i%3===0?'failure':'success',reason:i%3===0?'Google sign-in was cancelled':'Google sign-in completed',reason_code:i%3===0?'google_cancelled':'login_success',identity_verified:i%3!==0,auth_method:'google',ip_address:'192.0.2.20',user_agent:i===0?'<img src=x onerror="window.__xssExecuted=true">':'Mozilla/5.0 test browser '+i,browser:'Chrome',operating_system:'Windows',device_type:'Desktop'}));
      if(empty)rows=[];
      if(p.activity_outcome!=='all')rows=rows.filter(r=>r.outcome===p.activity_outcome);
      const total=rows.length,pages=Math.max(1,Math.ceil(total/25)),page=Math.min(Number(p.activity_page),pages);
      data={status:'ok',student:students.find(s=>s.student_id===Number(p.student_id)),summary:empty?{successful_logins:0,failed_attempts:0,total_attempts:0,success_rate:0,last_success_at:null}:{successful_logins:20,failed_attempts:11,total_attempts:31,success_rate:64.5,last_success_at:'2026-10-01T00:00:29Z'},rows:rows.slice((page-1)*25,page*25),snapshot:Number(p.activity_snapshot)||window.__loginSnapshot,timezone:'Asia/Manila',pagination:{page,pages,page_size:25,total,from:total?(page-1)*25+1:0,to:Math.min(page*25,total)}};
    }
    else throw Error('Unexpected browser fixture action '+p.action);
    return {ok:true,json:async()=>data};
  };</script>`;
  html = html.replace('<script src="students.js', mock + '<script src="students.js');
  fs.writeFileSync(route, `<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals('${token}',$_GET['qa'])){http_response_code(404);exit;} header('Content-Type: text/html; charset=utf-8');echo base64_decode('${Buffer.from(html).toString('base64')}');`);
  const executable = [process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe', process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe'].find(fs.existsSync);
  if (!executable) throw Error('Chrome or Edge is required.');
  browser = spawn(executable, ['--headless=new', '--no-sandbox', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const address = await new Promise((resolve, reject) => {
    let output = ''; const timeout = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => { output += data; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
    browser.on('error', error => { clearTimeout(timeout); reject(error); });
  });
  socket = new WebSocket(address);
  await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
  let sequence = 0; const pending = new Map();
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) { const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timeout); message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
  });
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence, timeout = setTimeout(() => { pending.delete(id); reject(Error(`${method} timed out.`)); }, 15000);
    pending.set(id, { resolve, reject, timeout }); socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  const target = await call('Target.createTarget', { url: 'about:blank' });
  const session = (await call('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
  await call('Network.enable', {}, session); await call('Network.setBlockedURLs', { urls: ['https://*'] }, session);
  const evaluate = async expression => { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, session); if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text); return result.result.value; };
  const until = async expression => { for (let attempt = 0; attempt < 120; attempt++) { if (await evaluate(`Boolean(${expression})`)) return; await delay(100); } throw Error('Timed out: ' + expression); };
  const resize = async width => { await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false }, session); await delay(150); };
  const screenshot = async name => fs.writeFileSync(path.join(root, '.tmp', name), Buffer.from((await call('Page.captureScreenshot', { format: 'png' }, session)).data, 'base64'));
  const click = id => evaluate(`document.getElementById(${JSON.stringify(id)}).click()`);
  const open = async studentId => { await evaluate(`document.querySelector('[data-student-login="${studentId}"]').click()`); await until('document.getElementById("deanStudentLoginModal").classList.contains("show") && document.getElementById("deanStudentLoginModal").getAttribute("aria-busy")==="false" && !bootstrap.Modal.getInstance(document.getElementById("deanStudentLoginModal"))._isTransitioning'); };
  const close = async () => { await until('!bootstrap.Modal.getInstance(document.getElementById("deanStudentLoginModal"))._isTransitioning'); await evaluate('bootstrap.Modal.getInstance(document.getElementById("deanStudentLoginModal")).hide()'); await until('!document.getElementById("deanStudentLoginModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.getElementById("deanStudentLoginModal"))._isTransitioning'); };
  await resize(1440);
  await call('Page.navigate', { url: `http://localhost/synk/tmp/${path.basename(route)}?qa=${token}` }, session);
  await until('document.querySelectorAll("[data-student-login]").length===2');
  check(await evaluate('document.querySelector("[data-student-login=\\"11\\"]").textContent.includes("20") && document.querySelector("[data-student-login=\\"12\\"]").textContent.includes("0")'), 'Each directory row has a clickable successful-login count, including zero.');
  for (const width of [1440, 1024, 768, 390, 320]) {
    await resize(width);
    if (!await evaluate('document.documentElement.scrollWidth<=innerWidth')) {
      await screenshot('student-login-directory-overflow.png');
      console.log(await evaluate('[...document.querySelectorAll("body *")].filter(e=>e.getBoundingClientRect().right>innerWidth+1 && getComputedStyle(e).position!=="fixed").map(e=>({tag:e.tagName,id:e.id,class:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})).slice(0,12)'));
    }
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `The student directory fits ${width}px.`);
  }
  await resize(1440); await screenshot('student-login-directory-1440.png'); await open(11);
  check(await evaluate('document.getElementById("deanStudentLoginIdentity").textContent.includes("000123") && document.getElementById("deanStudentLoginRows").children.length===25'), 'The clicked student opens a populated wide modal with 25 events per page.');
  check(await evaluate('document.querySelector(".dean-student-login-dialog").getBoundingClientRect().width>=1200'), 'The login-history modal uses the wider desktop layout.');
  check(await evaluate('document.getElementById("deanStudentLoginSummary").textContent.includes("64.5% success rate") && document.querySelector("#deanStudentLoginRows time").textContent.includes("08:00:30")'), 'Statistics and sign-in times use the expected Philippine timezone.');
  check(await evaluate('!document.querySelector("#deanStudentLoginRows img") && !window.__xssExecuted && document.querySelector(".dean-login-agent").textContent.includes("<img")'), 'Untrusted browser details are displayed as text and cannot execute HTML.');
  for (const width of [1440, 768, 390, 320]) {
    await resize(width);
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.querySelector("#deanStudentLoginModal .modal-content").getBoundingClientRect().right<=innerWidth'), `The history modal stays inside a ${width}px viewport.`);
    if (width === 1440 || width === 390) await screenshot(`student-login-history-${width}.png`);
  }
  await click('deanStudentLoginNext'); await until('document.getElementById("deanStudentLoginPageInfo").textContent.includes("Page 2 of 2")');
  check(await evaluate('document.getElementById("deanStudentLoginRows").children.length===6 && document.getElementById("deanStudentLoginNext").disabled'), 'The next page contains only the remaining events and disables Next.');
  await click('deanStudentLoginPrevious'); await until('document.getElementById("deanStudentLoginPageInfo").textContent.includes("Page 1 of 2")');
  check(await evaluate('window.__loginRequests.filter(r=>r.action==="login_activity").slice(-2).every(r=>r.activity_snapshot==="100")'), 'Pagination keeps the loaded history snapshot stable.');
  await evaluate('document.getElementById("deanStudentLoginOutcome").value="failure";document.getElementById("deanStudentLoginOutcome").dispatchEvent(new Event("change"))');
  await until('document.getElementById("deanStudentLoginRows").children.length===11');
  check(await evaluate('[...document.querySelectorAll("#deanStudentLoginRows .badge")].every(b=>b.textContent==="Failed") && document.getElementById("deanStudentLoginSummary").textContent.includes("20")'), 'Filtering failures preserves complete student statistics.');
  await evaluate('window.__loginSnapshot=200'); await click('deanStudentLoginRefresh');
  await until('window.__loginRequests.filter(r=>r.action==="login_activity").at(-1).activity_snapshot==="0" && !document.getElementById("deanStudentLoginRefresh").disabled');
  check(await evaluate('document.getElementById("deanStudentLoginOutcome").value==="failure"'), 'Refresh loads the latest history while retaining the selected outcome.');
  await close(); await open(12);
  check(await evaluate('document.getElementById("deanStudentLoginRows").textContent.includes("No login attempts") && document.getElementById("deanStudentLoginSummary").textContent.includes("Not yet recorded") && !document.getElementById("deanStudentLoginSummary").textContent.includes("64.5")'), 'Zero-login students show an empty state without retaining another student statistics.');
  await close(); await evaluate('window.__loginError=true'); await open(11);
  check(await evaluate('document.getElementById("deanStudentLoginNotice").textContent.includes("Your college access changed") && !document.getElementById("deanStudentLoginRows").querySelector("time") && !document.getElementById("deanStudentLoginRefresh").disabled'), 'An API failure clears previous data and leaves Refresh available.');
  await evaluate('window.__loginError=false'); await click('deanStudentLoginRefresh'); await until('document.getElementById("deanStudentLoginRows").children.length===25');
  await close(); await evaluate('window.__holdLogin=true');
  await evaluate('document.querySelector("[data-student-login=\\"11\\"]").click()'); await until('Boolean(window.__heldLogin)');
  await delay(300); await close();
  await evaluate('window.__holdLogin=false;window.__heldLogin();window.__heldLogin=null'); await delay(200); await open(12);
  check(await evaluate('document.getElementById("deanStudentLoginIdentity").textContent.includes("000124") && document.getElementById("deanStudentLoginRows").textContent.includes("No login attempts")'), 'A delayed closed request cannot overwrite a different student history.');
  await close(); await evaluate('window.__directoryEmpty=true;document.getElementById("deanStudentsRefresh").click()');
  await until('document.getElementById("deanStudentsRows").textContent.includes("No matching students")');
  check(await evaluate('document.querySelector("#deanStudentsRows td").colSpan===7'), 'The empty Dean directory spans all seven columns, including login activity.');
  check(errors.length === 0, 'No browser JavaScript errors: ' + errors.join('; '));
  console.log(`Student login activity browser: ${checks} checks passed. Screenshots saved in .tmp.`);
}

main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (call) try { await call('Browser.close'); } catch (_) {}
  if (socket?.readyState === WebSocket.OPEN) socket.close();
  if (browser && browser.exitCode === null) browser.kill();
  await delay(500);
  if (fs.existsSync(route)) fs.unlinkSync(route);
  if (fs.existsSync(profile)) {
    const quote = value => "'" + value.replaceAll("'", "''") + "'";
    const cleanup = `$profileRoot=(Resolve-Path -LiteralPath ${quote(path.join(root, '.tmp'))}).Path; $profileTarget=(Resolve-Path -LiteralPath ${quote(profile)}).Path; if (-not $profileTarget.StartsWith($profileRoot+'\\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Profile cleanup is outside the workspace.' }; Remove-Item -LiteralPath $profileTarget -Recurse -Force -ErrorAction Stop`;
    const result = spawnSync('powershell.exe', ['-NoProfile', '-Command', cleanup], { windowsHide: true, encoding: 'utf8' });
    if (result.status !== 0) { console.error(result.stderr || 'Browser profile cleanup failed.'); process.exitCode = 1; }
  }
});
