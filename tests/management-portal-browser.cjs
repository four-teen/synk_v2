// node tests/management-portal-browser.cjs -- isolated browser, rolled-back HTTP fixtures.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const nonce = crypto.randomBytes(8).toString('hex');
const token = crypto.randomBytes(24).toString('hex');
const route = path.join(root, 'tmp', `management-browser-${nonce}.php`);
const profile = path.join(root, '.tmp', `management-browser-${nonce}`);
let browser, ws, checks = 0;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };
async function main() {
  fs.writeFileSync(route, `<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!hash_equals('${token}',(string)($_GET['qa']??''))){http_response_code(404);exit;}define('SYNK_MANAGEMENT_QA',true);define('SYNK_MANAGEMENT_QA_RENDER',true);require '${path.join(root, 'tests/fixtures/management-request.php').replaceAll('\\', '/')}';`);
  const exe = [process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  browser = spawn(exe, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const socket = await new Promise((resolve, reject) => {
    let logs = ''; const timer = setTimeout(() => reject(Error('Browser startup timed out.')), 20000);
    browser.stderr.on('data', data => { logs += data; const match = logs.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
    browser.on('error', reject);
  });
  ws = new WebSocket(socket);
  await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
  let sequence = 0; const pending = new Map(); const errors = []; const saves = [], catalogRequests = [], organizationRequests = [];
  let organizationContext = {}, organizationInUse = false;
  let catalogFailure = false, linkedContributions = 0, catalogTerm = '2:1';
  const call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence; const timer = setTimeout(() => { pending.delete(id); reject(Error(method + ' timed out.')); }, 20000);
    pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', event => {
    const msg = JSON.parse(event.data);
    if (msg.id && pending.has(msg.id)) { const p = pending.get(msg.id); pending.delete(msg.id); clearTimeout(p.timer); msg.error ? p.reject(Error(msg.error.message)) : p.resolve(msg.result); }
    if (msg.method === 'Runtime.exceptionThrown') errors.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text);
    if (msg.method === 'Fetch.requestPaused') {
      if (msg.params.request.url.includes('query_organizations.php')) {
        const data = Object.fromEntries(new URLSearchParams(msg.params.request.postData || '')); organizationRequests.push(data);
        const record = { organization_id:data.organization_id, college_id:organizationContext.college_id,campus_id:organizationContext.campus_id,organization_code:'BROWSER ORG',organization_name:'BROWSER ORGANIZATION',status:'ACTIVE',version:'B'.repeat(64),usage:{ accounts:organizationInUse?1:0,events:0,contributions:0,violations:0 } };
        const payload = data.action === 'get' ? { status:'ok',record } : { status:'ok',message:'Organization saved.',organization_id:1 };
        call('Fetch.fulfillRequest',{ requestId:msg.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(payload)).toString('base64') },msg.sessionId).catch(e=>errors.push(e.message));
        return;
      }
      if (msg.params.request.url.includes('query_management.php')) {
        const data = Object.fromEntries(new URLSearchParams(msg.params.request.postData || '')); catalogRequests.push(data);
        const [ay_id,semester] = catalogTerm.split(':');
        const common = { title:'BROWSER CATALOG RECORD', description:'RECORD DESCRIPTION', organization_id:'1', ay_id, semester, status:'DRAFT', audience_mode:'ALL', version:'A'.repeat(64) };
        const record = data.kind === 'violations' ? { ...common,violation_id:data.record_id,violation_type:'BROWSER VIOLATION',amount:'75.25',version:'A'.repeat(64) } : data.kind === 'events' ? { ...common,event_id:data.record_id,event_date:'2026-10-26',absence_fine:'25.25',latitude:null,longitude:null,attendance_radius_m:null,linked_contributions:linkedContributions,
          sessions:[{ session_id:'123',title:'MORNING SESSION',starts_at:'2026-10-26 08:00:00',ends_at:'2026-10-26 12:00:00',fine_per_minute:'0.1250',grace_minutes:'15',potential_fine:'28.12',status:'DRAFT' }] }
          : { ...common,contribution_id:data.record_id,contribution_date:'2026-10-26',amount:'123.45',event_id:null };
        let payload = data.action === 'get' ? { status:'ok',record } : { status:'ok',message:'Record saved.',record_id:1 };
        if (data.action === 'student_detail') payload = { status:'ok',student:{ student_name:'STUDENT <img src=x onerror=alert(1)>',student_number:'000123',email_address:'student@sksu.edu.ph' },subjects:[{ subject_code:'CS 101',descriptive_title:'PROGRAMMING',section_label:'BSCS 1A',program_label:'BSCS',term_label:'2026-2027 - 1st Semester',faculty_name:'ASSIGNED FACULTY' }] };
        if (data.action === 'organization_add') payload = { status:'ok',organization:{ organization_id:999999,organization_code:'NEW ORG',organization_name:'NEW ORGANIZATION' } };
        if (data.action === 'save' && catalogFailure) payload={ status:'error',message:'The record changed. Reload its details.' };
        call('Fetch.fulfillRequest', { requestId:msg.params.requestId,responseCode:catalogFailure && data.action==='save' ? 409 : 200,responseHeaders:[{ name:'Content-Type',value:'application/json' }],body:Buffer.from(JSON.stringify(payload)).toString('base64') },msg.sessionId).catch(e=>errors.push(e.message));
        return;
      }
      saves.push(msg.params.request);
      call('Fetch.fulfillRequest', { requestId: msg.params.requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'text/plain' }], body: Buffer.from('success').toString('base64') }, msg.sessionId).catch(e => errors.push(e.message));
    }
  });
  const { targetId } = await call('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await call('Target.attachToTarget', { targetId, flatten: true });
  await call('Page.enable', {}, sessionId); await call('Runtime.enable', {}, sessionId);
  const evaluate = async expression => { const r = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId); if (r.exceptionDetails) throw Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text); return r.result.value; };
  const until = async expression => { for (let i = 0; i < 120; i++) { if (await evaluate('Boolean(' + expression + ')')) return; await delay(100); } throw Error('Timed out: ' + expression + '; ' + errors.join('; ')); };
  const resize = async width => { await call('Emulation.setDeviceMetricsOverride', { width, height: 960, deviceScaleFactor: 1, mobile: false }, sessionId); await delay(120); };
  const navigate = async scenario => {
    await call('Page.navigate', { url: `http://localhost/synk/tmp/${path.basename(route)}?qa=${token}&scenario=${scenario}` }, sessionId);
    await until('document.readyState === "complete"'); await delay(150);
  };
  const screenshot = async name => { const r = await call('Page.captureScreenshot', { format: 'png' }, sessionId); fs.writeFileSync(path.join(root, '.tmp', name), Buffer.from(r.data, 'base64')); };
  await resize(1440); await navigate('login');
  await until('Number(getComputedStyle(document.querySelector("[data-synk-login-content]")).opacity)>0.99');
  check(await evaluate('document.querySelector("h1").textContent.includes("CCS Management.") && document.querySelector("h1").textContent.includes("One Place.")'), 'Management login branding is incorrect.');
  check(await evaluate('document.querySelectorAll("input[type=email], input[type=password]").length===0 && document.querySelector("#managementLoginForm input[name=csrf_token]").value.length===64 && !document.getElementById("managementGoogleLogin").disabled'), 'Login must expose only Google sign-in and a valid request token.');
  for (const width of [1440, 768, 390, 320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Management login overflows ${width}px.`); }
  await screenshot('management-login-320.png');
  await resize(1440); await navigate('dashboard');
  check(await evaluate('document.querySelectorAll("[data-stat]").length===4 && document.querySelectorAll(".management-nav a").length===5 && document.querySelector(".management-nav [aria-current=page]").textContent.includes("Dashboard")'), 'Dashboard analytics/navigation are missing.');
  check(await evaluate('document.documentElement.classList.contains("layout-menu-fixed") && document.getElementById("layout-menu").classList.contains("bg-menu-theme") && document.getElementById("layout-navbar").classList.contains("navbar-detached") && document.querySelectorAll(".college-dean-menu-card").length===5'), 'Management is not using the shared module layout/header/card navigation.');
  check(await evaluate('getComputedStyle(document.querySelector(".college-dean-menu-title")).fontSize==="13.92px" && getComputedStyle(document.querySelector(".college-dean-menu-icon")).width==="34px" && getComputedStyle(document.querySelector(".college-dean-menu-card")).borderTopWidth==="1px"'), 'Sidebar fonts, icon sizes, or card borders differ from the Dean template.');
  check(await evaluate('document.compatMode==="CSS1Compat" && document.querySelectorAll("script[src*=jquery]").length===0'), 'Management dashboard is not in standards mode or loads duplicate libraries.');
  await screenshot('management-dashboard-1440.png');
  await evaluate('document.getElementById("management-term-settings").click()');await until('document.getElementById("management-term-modal").classList.contains("show")');await delay(300);
  check(await evaluate('document.getElementById("management-term").value===JSON.parse(document.getElementById("management-config").textContent).term'),'Header term settings did not show the selected viewing term.');
  await evaluate('document.getElementById("management-term").selectedIndex=0;document.querySelector("#management-term-modal [data-bs-dismiss]").click()');await delay(300);
  await evaluate('document.getElementById("management-term-settings").click()');await until('document.getElementById("management-term-modal").classList.contains("show")');await delay(300);
  check(await evaluate('document.getElementById("management-term").value===JSON.parse(document.getElementById("management-config").textContent).term'),'Canceling term settings retained an unapplied selection.');
  await evaluate('document.getElementById("management-current-term").click()');
  check(await evaluate('document.getElementById("management-term").value===document.getElementById("management-current-term").dataset.term'),'Use current term did not select the current academic term.');
  await evaluate('document.querySelector("#management-term-modal [data-bs-dismiss]").click()');await delay(300);
  for (const width of [1440, 1024, 768, 390, 320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Dashboard overflows ${width}px.`); }
  await evaluate('document.getElementById("management-menu-toggle").click()');
  check(await evaluate('document.getElementById("management-menu-toggle").getAttribute("aria-expanded")==="true" && !document.getElementById("layout-menu").inert && !document.getElementById("management-menu-backdrop").hidden'), 'Mobile navigation did not open accessibly.');
  await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }, sessionId);
  check(await evaluate('document.getElementById("layout-menu").inert && document.getElementById("management-menu-toggle").getAttribute("aria-expanded")==="false"'), 'Escape did not close mobile navigation.');
  await resize(390); await screenshot('management-dashboard-390.png');
  await navigate('students');
  check(await evaluate('getComputedStyle(document.querySelector(".management-table")).fontSize==="14px" && getComputedStyle(document.querySelector(".management-table th")).fontSize==="12px" && getComputedStyle(document.querySelector(".management-table th")).backgroundColor==="rgb(242, 242, 255)" && getComputedStyle(document.querySelector(".dean-student-id")).color==="rgb(105, 108, 255)"'), 'Student list typography, colors, or header background differ from the Dean template.');
  check(await evaluate('document.querySelector(".management-record-title").firstElementChild.classList.contains("dean-student-id") && document.querySelector(".dean-student-contact em")'), 'Student ID/name/email order does not follow the Dean format.');
  check(await evaluate('document.querySelectorAll("tbody tr").length===25 && document.querySelector(".management-pagination").textContent.includes("28 students")'), 'Imported-student pagination/deduplication failed.');
  for (const width of [1440, 768, 390, 320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Student page overflows ${width}px.`); }
  await screenshot('management-students-320.png');
  await navigate('students_page'); check(await evaluate('document.querySelectorAll("tbody tr").length===3 && document.querySelector(".management-pagination").textContent.includes("Page 2 of 2")'), 'Student second page is incorrect.');
  await navigate('events'); check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Events") && document.getElementById("management-add") && document.getElementById("management-record-form")'), 'Events management page is missing.');
  await navigate('contributions'); check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Contributions")'), 'Contributions foundation page is missing.');
  await call('Fetch.enable',{ patterns:[{ urlPattern:'*query_management.php*' }] },sessionId);
  catalogTerm = await evaluate('JSON.parse(document.getElementById("management-config").textContent).term');
  for (const width of [1440,768,390,320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Contribution list overflows ${width}px.`); }
  await resize(1440); await screenshot('management-contributions-1440.png');
  await evaluate('document.getElementById("management-add").click()'); await until('document.getElementById("management-editor").classList.contains("show")'); await delay(350);
  check(await evaluate('document.getElementById("management-editor-title").textContent==="Add Contribution" && document.getElementById("management-record-form").elements.record_term.value===JSON.parse(document.getElementById("management-config").textContent).current_term && document.getElementById("record-term").readOnly'), 'Add contribution did not default to the selected term.');
  check(await evaluate('!document.getElementById("management-new-organization") && document.getElementById("record-organization").readOnly'),'Organization setup must be controlled by Administrator.');
  await evaluate('document.getElementById("record-title").value="Evening membership";document.getElementById("record-amount").value="-1";');
  let beforeRequests=catalogRequests.length;
  await evaluate('document.getElementById("management-record-form").requestSubmit()'); await delay(150);
  check(catalogRequests.length===beforeRequests,'Browser submitted a negative contribution amount.');
  catalogFailure=true;
  await evaluate('document.getElementById("record-amount").value="12.35";document.getElementById("management-record-form").requestSubmit()');
  await until('!document.getElementById("management-editor-error").hidden && !document.getElementById("management-record-save").disabled');
  check(await evaluate('document.getElementById("record-title").value==="Evening membership" && document.getElementById("management-editor-error").textContent.includes("record changed")'),'A failed save discarded the form or hid its error.');
  for (const width of [768,390,320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),'Contribution editor overflows '+width+'px.'); }
  await screenshot('management-contribution-editor-320.png');
  catalogFailure=false; beforeRequests=catalogRequests.filter(row=>row.action==='save').length;
  await evaluate('document.getElementById("management-record-form").requestSubmit()');
  for(let i=0;i<100&&catalogRequests.filter(row=>row.action==='save').length===beforeRequests;i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"'); await delay(150);
  const addedContribution=catalogRequests.filter(row=>row.action==='save').at(-1);
  check(addedContribution.amount==='12.35'&&addedContribution.record_id===''&&addedContribution.title==='Evening membership'&&addedContribution.csrf_token.length===64,'Add contribution request lost its values or request token.');
  await resize(1440);
  await evaluate('document.querySelector("[data-edit]").click()'); await until('document.getElementById("management-record-form").elements.record_id.value && !document.getElementById("management-editor-fields").disabled'); await delay(300);
  check(await evaluate('document.getElementById("record-amount").value==="123.45" && document.getElementById("management-record-form").elements.version.value.length===64'),'Contribution editing lost its amount or record version.');
  await evaluate('document.querySelector("#management-editor [data-bs-dismiss]").click()'); await delay(350);
  await evaluate('document.querySelector("[data-delete]").click()'); await until('document.getElementById("management-delete-name").textContent==="BROWSER CATALOG RECORD"'); await delay(300);
  beforeRequests=catalogRequests.filter(row=>row.action==='delete').length;
  await evaluate('document.querySelector("#management-delete-modal [data-bs-dismiss]").click()'); await delay(350);
  check(catalogRequests.filter(row=>row.action==='delete').length===beforeRequests,'Canceling a delete submitted a mutation.');
  await evaluate('document.querySelector("[data-delete]").click()'); await until('!document.getElementById("management-delete-confirm").disabled'); await delay(300);
  await evaluate('document.getElementById("management-delete-confirm").click()');
  for(let i=0;i<100&&catalogRequests.filter(row=>row.action==='delete').length===beforeRequests;i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"'); await delay(150);
  check(catalogRequests.filter(row=>row.action==='delete').at(-1).version.length===64,'Delete did not submit the confirmed record version.');
  await navigate('events');
  for(const width of [1440,768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Event list overflows ${width}px.`);}
  await resize(1440); await screenshot('management-events-1440.png');
  await evaluate('document.getElementById("management-add").click()'); await until('document.getElementById("management-editor").classList.contains("show")'); await delay(350);
  await evaluate('document.getElementById("record-title").value="New College Event";document.getElementById("management-session-add").click();document.getElementById("management-session-add").click();document.querySelector("[data-remove-session]").click();document.querySelector("[data-field=title]").value="Afternoon activity"');
  check(await evaluate('document.querySelectorAll(".management-session").length===1 && document.getElementById("management-session-empty").hidden'),'Adding/removing event sessions did not update the editor.');
  for(const width of [768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Event editor overflows ${width}px.`);}
  await screenshot('management-event-editor-320.png');
  beforeRequests=catalogRequests.filter(row=>row.action==='save').length;
  await evaluate('document.getElementById("management-record-form").requestSubmit()');
  for(let i=0;i<100&&catalogRequests.filter(row=>row.action==='save').length===beforeRequests;i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"'); await delay(150);
  const eventSave=catalogRequests.filter(row=>row.action==='save').at(-1), submittedSessions=JSON.parse(eventSave.sessions);
  check(eventSave.kind==='events'&&submittedSessions.length===1&&submittedSessions[0].title==='Afternoon activity'&&submittedSessions[0].session_id==='','Event form lost the new session details.');
  await resize(1440);await evaluate('document.querySelector("[data-edit]").click()');await until('document.querySelectorAll(".management-session").length===1 && !document.getElementById("management-editor-fields").disabled');await delay(300);
  check(await evaluate('document.querySelector("[data-field=fine_per_minute]").value==="0.1250" && document.querySelector(".management-session").dataset.sessionId==="123"'),'Editing lost session identity or exact fine precision.');
  await evaluate('document.querySelector("#management-editor [data-bs-dismiss]").click()');await delay(350);linkedContributions=1;
  await evaluate('document.querySelector("[data-delete]").click()');await until('document.getElementById("management-delete-explanation").textContent.includes("linked to 1")');
  check(await evaluate('document.getElementById("management-delete-confirm").disabled'),'A linked event can be deleted from the confirmation dialog.');
  await evaluate('document.querySelector("#management-delete-modal [data-bs-dismiss]").click()');await delay(350);linkedContributions=0;
  await navigate('students');
  check(await evaluate('document.getElementById("student-section") && document.getElementById("student-email-status") && document.getElementById("student-sort") && document.querySelectorAll("#student-page-size option").length===3'),'Dean-style student filters are missing.');
  await evaluate('document.querySelector("[data-student]").click()');await until('document.getElementById("management-student-detail-content").textContent.includes("CS 101")');await delay(300);
  check(await evaluate('document.getElementById("management-student-detail-content").querySelectorAll("img,script").length===0 && document.getElementById("management-student-detail-title").textContent.includes("<img")'),'Student details contain unsafe HTML.');
  for(const width of [1440,768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Student detail overflows ${width}px.`);}
  await screenshot('management-student-detail-320.png');
  await navigate('violations');await resize(1440);await screenshot('management-violations-1440.png');
  check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Violations") && document.querySelectorAll("#management-record-form [name]:not([type=hidden])").length===2'),'Violation menu/page must expose only type and amount fields.');
  for(const width of [1440,768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Violation page overflows ${width}px.`);}
  await evaluate('document.getElementById("management-add").click()');await until('document.getElementById("management-editor").classList.contains("show")');await delay(300);
  await evaluate('document.getElementById("record-title").value="Missing ID";document.getElementById("record-amount").value="25.50"');
  beforeRequests=catalogRequests.filter(row=>row.action==='save').length;
  await evaluate('document.getElementById("management-record-form").requestSubmit()');
  for(let i=0;i<100&&catalogRequests.filter(row=>row.action==='save').length===beforeRequests;i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"');await delay(150);
  const violationSave=catalogRequests.filter(row=>row.action==='save').at(-1);
  check(violationSave.kind==='violations'&&violationSave.violation_type==='Missing ID'&&violationSave.amount==='25.50','Violation add form lost its type or exact amount.');
  await evaluate('document.querySelector("[data-edit]").click()');await until('document.getElementById("management-record-form").elements.record_id.value && !document.getElementById("management-editor-fields").disabled');await delay(300);
  check(await evaluate('document.getElementById("record-title").value==="BROWSER VIOLATION" && document.getElementById("record-amount").value==="75.25"'),'Violation edit did not load its type and amount.');
  await screenshot('management-violation-editor-320.png');
  await evaluate('document.querySelector("#management-editor [data-bs-dismiss]").click()');await delay(300);
  await evaluate('document.querySelector("[data-delete]").click()');await until('document.getElementById("management-delete-name").textContent==="BROWSER VIOLATION"');await delay(300);
  beforeRequests=catalogRequests.filter(row=>row.action==='delete').length;
  await evaluate('document.getElementById("management-delete-confirm").click()');
  for(let i=0;i<100&&catalogRequests.filter(row=>row.action==='delete').length===beforeRequests;i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"');await delay(150);
  check(catalogRequests.filter(row=>row.action==='delete').at(-1).kind==='violations','Violation delete did not submit the confirmed record.');
  await resize(1440);
  await call('Fetch.enable', { patterns: [{ urlPattern: '*query_accounts.php*' }] }, sessionId);
  await navigate('account_page');
  await until('window.jQuery && document.getElementById("add_role_management")');
  await evaluate('bootstrap.Modal.getOrCreateInstance(document.getElementById("addAccountModal")).show()');
  await until('document.getElementById("addAccountModal").classList.contains("show")'); await delay(300);
  await evaluate('document.getElementById("add_role_management").click()');
  check(await evaluate('document.getElementById("add_management_college_id").required && getComputedStyle(document.getElementById("add_management_college_wrapper")).display!=="none" && document.getElementById("add_email_label").textContent==="Google Email" && document.getElementById("add_primary_role").value==="management"'), 'Selecting Management did not require its college or default role.');
  check(await evaluate('document.getElementById("add_management_organization_id").required && document.getElementById("add_management_campus_id").required && !document.getElementById("add_management_organization_id").disabled'),'Management must require a campus, college, and organization.');
  await evaluate('(()=>{const org=managementOrganizations.find(row=>row.status==="ACTIVE");const college=managementColleges.find(row=>String(row.college_id)===String(org.college_id));document.getElementById("add_management_campus_id").value=college.campus_id;document.getElementById("add_management_campus_id").dispatchEvent(new Event("change"));document.getElementById("add_management_college_id").value=college.college_id;document.getElementById("add_management_college_id").dispatchEvent(new Event("change"));document.getElementById("add_management_organization_id").value=org.organization_id})()');
  check(await evaluate('Boolean(document.getElementById("add_management_organization_id").value) && [...document.getElementById("add_management_organization_id").options].filter(o=>o.value).every(o=>managementOrganizations.some(row=>String(row.organization_id)===o.value&&String(row.college_id)===document.getElementById("add_management_college_id").value))'),'Account organization choices are not filtered by college.');
  check(await evaluate('buildAccountPayload("add").management_organization_id===document.getElementById("add_management_organization_id").value && buildAccountPayload("add").management_campus_id===document.getElementById("add_management_campus_id").value'),'Account payload lost its campus or organization assignment.');
  const chosenOrganization=await evaluate('document.getElementById("add_management_organization_id").value');
  await evaluate('document.getElementById("add_role_admin").click()'); check(await evaluate('document.getElementById("add_email_label").textContent==="SKSU Email"'), 'Shared staff roles did not retain SKSU email requirements.');
  check(await evaluate('document.getElementById("add_management_organization_id").value')===chosenOrganization,'Selecting another role cleared the Management organization.');
  await evaluate('document.getElementById("add_management_campus_id").value="";document.getElementById("add_management_campus_id").dispatchEvent(new Event("change"))');
  check(await evaluate('document.getElementById("add_management_college_id").value==="" && document.getElementById("add_management_organization_id").value===""'),'Changing campus retained a stale college or organization.');
  await evaluate('document.getElementById("add_role_management").click()'); check(await evaluate('!document.getElementById("add_management_college_id").required && getComputedStyle(document.getElementById("add_management_college_wrapper")).display==="none"'), 'Removing Management did not hide and clear its college.');
  check(saves.every(r => !/save_account|update_account|delete_account/.test(r.postData || '')), 'Browser inspection unexpectedly submitted an account mutation.');
  await call('Fetch.enable',{patterns:[{urlPattern:'*query_organizations.php*'}]},sessionId);
  await navigate('organizations_page'); await until('document.getElementById("organization-config") && window.bootstrap');
  organizationContext=await evaluate('JSON.parse(document.getElementById("organization-config").textContent).colleges.find(row=>row.status==="active"&&row.campus_status==="active")');
  check(await evaluate('[...document.querySelectorAll("#layout-menu a")].some(link=>link.getAttribute("href")==="manage-organizations.php")'),'Administrator Organizations navigation is missing.');
  for(const width of [1440,768,390,320]) { await resize(width); const fits=await evaluate('document.documentElement.scrollWidth<=innerWidth'); if (!fits) { await screenshot(`management-organizations-overflow-${width}.png`); console.log(await evaluate('JSON.stringify([...document.querySelectorAll("body *")].map(e=>({tag:e.tagName,cls:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right})).filter(e=>e.right>innerWidth+1).slice(0,14))')); } check(fits,`Organizations page overflows ${width}px.`); }
  await resize(1440); await screenshot('management-organizations-1440.png');
  await evaluate('document.getElementById("organization-add").click()'); await until('document.getElementById("organization-editor").classList.contains("show")'); await delay(300);
  let organizationBefore=organizationRequests.length;
  await evaluate('document.getElementById("organization-form").requestSubmit()');await delay(120);
  check(organizationRequests.length===organizationBefore,'Organization form accepted missing campus/college/name.');
  await evaluate(`document.getElementById("organization-campus").value=${JSON.stringify(String(organizationContext.campus_id))};document.getElementById("organization-campus").dispatchEvent(new Event("change"));document.getElementById("organization-college").value=${JSON.stringify(String(organizationContext.college_id))};document.getElementById("organization-code").value="new club";document.getElementById("organization-name").value="new organization"`);
  check(await evaluate('document.getElementById("organization-college").value!=="" && [...document.getElementById("organization-college").options].filter(o=>o.value).every(o=>JSON.parse(document.getElementById("organization-config").textContent).colleges.some(c=>String(c.college_id)===o.value&&String(c.campus_id)===document.getElementById("organization-campus").value))'),'Organization form did not restrict colleges to the chosen campus.');
  for(const width of [768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Organization editor overflows ${width}px.`);}
  await screenshot('management-organization-editor-320.png');
  await evaluate('document.getElementById("organization-form").requestSubmit()');
  for(let i=0;i<100&&!organizationRequests.some(r=>r.action==='save');i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"');await delay(150);
  const organizationSave=organizationRequests.find(r=>r.action==='save');
  check(organizationSave.organization_code==='new club'&&organizationSave.organization_name==='new organization'&&organizationSave.college_id===String(organizationContext.college_id)&&organizationSave.csrf_token.length===64,'Organization save request lost its scope, text, or token.');
  await resize(1440);await evaluate('document.querySelector("[data-edit]").click()');await until('document.getElementById("organization-form").elements.organization_id.value && !document.getElementById("organization-fields").disabled');await delay(300);
  check(await evaluate('document.getElementById("organization-name").value==="BROWSER ORGANIZATION"&&document.getElementById("organization-form").elements.version.value.length===64'),'Editing did not restore organization details or version.');
  await evaluate('document.querySelector("#organization-editor [data-bs-dismiss]").click()');await delay(300);organizationInUse=true;
  await evaluate('document.querySelector("[data-delete]").click()');await until('document.getElementById("organization-delete-copy").textContent.includes("assigned accounts")');await delay(300);
  check(await evaluate('document.getElementById("organization-delete-confirm").disabled'),'Used organizations can be deleted through the browser.');
  await evaluate('document.querySelector("#organization-delete-modal [data-bs-dismiss]").click()');await delay(300);organizationInUse=false;
  await evaluate('document.querySelector("[data-delete]").click()');await until('!document.getElementById("organization-delete-confirm").disabled');await delay(300);
  organizationBefore=organizationRequests.filter(r=>r.action==='delete').length;
  await evaluate('document.getElementById("organization-delete-confirm").click()');
  for(let i=0;i<100&&organizationRequests.filter(r=>r.action==='delete').length===organizationBefore;i++)await delay(50);
  check(organizationRequests.filter(r=>r.action==='delete').at(-1).version.length===64,'Organization delete did not submit the confirmed version.');
  check(errors.length === 0, 'Browser errors: ' + errors.join('; '));
  console.log(`Management browser: ${checks} checks passed at desktop and mobile sizes. Account requests intercepted; fixture records rolled back.`);
  await call('Browser.close');
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (ws) ws.close(); if (browser) { browser.kill(); await delay(600); }
  if (fs.existsSync(route)) fs.unlinkSync(route);
  const resolved = path.resolve(profile); const parent = path.resolve(root, '.tmp');
  if (!resolved.startsWith(parent + path.sep) || !/^management-browser-[a-f0-9]{16}$/.test(path.basename(resolved))) throw Error('Unexpected cleanup path.');
  if (fs.existsSync(resolved)) fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
});
