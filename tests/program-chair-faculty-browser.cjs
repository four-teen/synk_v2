// Run: node tests/program-chair-faculty-browser.cjs (localhost Synk and Edge/Chrome).
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const profile = path.join(root, '.tmp', `chair-browser-${crypto.randomBytes(8).toString('hex')}`);
const fixture = scenario => JSON.parse(execFileSync('php', [path.join(root, 'tests/fixtures/program-chair-request.php'), scenario], { encoding: 'utf8' })).response;
const pageHtml = fixture('page_manage-college-faculty'), historicalHtml = fixture('historical_page');
const pageConfig = JSON.parse(pageHtml.match(/<script id="chairFacultyConfig" type="application\/json">([^<]+)<\/script>/)[1]);
const historicalConfig = JSON.parse(historicalHtml.match(/<script id="chairFacultyConfig" type="application\/json">([^<]+)<\/script>/)[1]);
const term = () => ({ay_id:(historical ? historicalConfig : pageConfig).ay_id,semester:(historical ? historicalConfig : pageConfig).semester,term_text:'Selected academic term',is_current:!historical});
const faculties = [
  { faculty_id: 101, faculty_name: 'Alpha, Faculty', faculty_status: 'active', employment_classification: 'permanent', classification_label: 'Permanent', can_manage_profile: true,faculty_email:'FACULTY.ALPHA@SKSU.EDU.PH',login_status:'active',login_roles:['professor'],login_can_manage:true },
  { faculty_id: 202, faculty_name: 'Beta, <img src=x onerror=alert(1)>', faculty_status: 'active', employment_classification: 'part_time', classification_label: 'Part Time', can_manage_profile: false,faculty_email:'faculty.beta@sksu.edu.ph',login_status:'active',login_roles:['professor','admin'],login_can_manage:true,login_admin_managed:true,login_reason:'' },
];
let browser, ws, call, session, checks = 0, historical = false, assigned = [], mode = 'success';
let loginMode='success';
const errors = [], requests = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (condition, message) => { if (!condition) throw Error(message); checks++; };
const asRow = faculty => ({...faculty,full_name:faculty.faculty_name,college_faculty_id:faculty.faculty_id+1000,designation_id:7,designation_name:'Dean',designation_style:'',employment_classification_label:faculty.classification_label,assigned_count:2});
const payload = request => {
  const result = {};
  if ((request.postData || '').includes('Content-Disposition: form-data')) {
    for (const match of request.postData.matchAll(/name="([^"]+)"\r\n\r\n([^\r\n]*)/g)) result[match[1]]=match[2];
  } else Object.assign(result,Object.fromEntries(new URLSearchParams(request.postData || '')));
  return result;
};
async function main() {
  const exe = [process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  if (!exe) throw Error('Edge or Chrome is required.');
  browser = spawn(exe, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const socket = await new Promise((resolve, reject) => {
    let log = ''; const timeout = setTimeout(() => reject(Error('Browser startup timed out.')), 15000);
    browser.stderr.on('data', data => { log += data; const match = log.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
    browser.on('error', reject);
  });
  ws = new WebSocket(socket);
  await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
  let sequence = 0; const pending = new Map();
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence, timeout = setTimeout(() => reject(Error('CDP timeout: ' + method)), 15000);
    pending.set(id, { resolve, reject, timeout }); ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (pending.has(message.id)) { const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timeout); message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result); }
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (message.method === 'Fetch.requestPaused') (async () => {
      const { requestId, request } = message.params;
      const data = payload(request);
      requests.push({ url: request.url, data });
      let body, status = 200, type = 'application/json';
      if (request.url.includes('/program-chair/manage-college-faculty.php')) { body = historical ? historicalHtml : pageHtml; type = 'text/html'; }
      else if (request.url.includes('query_program_chair_faculty_account.php')) {
        const faculty=assigned.find(f=>String(f.faculty_id)===data.faculty_id);
        if (data.action==='load') body={status:'ok',account:{faculty_id:faculty.faculty_id,faculty_name:faculty.full_name,exists:!!faculty.faculty_email,status:faculty.login_status||'active',roles:faculty.login_roles.filter(role=>['professor','scheduler'].includes(role)),primary_role:faculty.login_admin_managed?'':'professor',can_manage_status:!faculty.login_admin_managed,can_manage_primary:!faculty.login_admin_managed,can_manage:faculty.login_can_manage,reason:faculty.login_reason||'',revision:'chair-account-1',allowed_domain:'sksu.edu.ph',email_max_length:150}};
        else if (data.action==='save') {
          await delay(200);
          if (loginMode==='error') { status=409; body={status:'error',message:'This account changed since you opened it. Reopen Login Access before saving.'}; }
          else { assigned=assigned.map(f=>String(f.faculty_id)===data.faculty_id?{...f,faculty_email:data.email||f.faculty_email,login_status:data.status,login_roles:[...JSON.parse(data.roles_json),...f.login_roles.filter(role=>!['professor','scheduler'].includes(role))]}:f); body={status:'saved',message:'Faculty login access saved.'}; }
        } else throw Error('Unexpected faculty login action.');
      }
      else if (request.url.includes('query_program_chair_term_lists.php')) {
        if (data.action==='options') body={status:'ok',target:term(),terms:[{key:'1:1',ay_id:1,semester:1,label:'Previous year - 1st Semester'}]};
        else if (data.action==='preview') body={status:'ok',target:term(),preview_token:'chair-preview',rows:[{id:101,label:'Alpha, Faculty',detail:'Dean',eligible:false,reason:'Already in the current list'},{id:202,label:faculties[1].faculty_name,detail:'Program Chair',eligible:true,reason:''}]};
        else if (data.action==='copy') { assigned.push({...asRow(faculties[1]),designation_id:8,designation_name:'Program Chair'}); body={status:'ok',copied:1,skipped:0,target:term()}; }
        else throw Error('Unexpected copy action.');
      }
      else if (data.load_college_faculty) {
        const matches=assigned.filter(f=>`${f.full_name} ${f.designation_name} ${f.employment_classification_label}`.toLowerCase().includes((data.search || '').toLowerCase()));
        body={status:'success',data:matches,pagination:{total:matches.length,has_more:false},term:term()};
      }
      else if (data.load_faculty_dropdown) body=faculties.filter(f=>!assigned.some(a=>a.faculty_id===f.faculty_id)).map(f=>({id:f.faculty_id,text:f.faculty_name}));
      else if (data.load_designation_options) body={status:'success',data:[{id:7,text:'Dean'},{id:8,text:'Program Chair'}]};
      else if (data.update_faculty_designation) {
        const id=Number(data.designation_id),name=id===8?'Program Chair':id===7?'Dean':'';
        assigned=assigned.map(f=>f.faculty_id===Number(data.faculty_id)?{...f,designation_id:id,designation_name:name}:f);
        body={status:'updated',designation_id:id,designation_name:name,designation_style:''};
      }
      else if (data.update_employment_classification) {
        assigned=assigned.map(f=>f.faculty_id===Number(data.faculty_id)?{...f,employment_classification:data.employment_classification,employment_classification_label:'Part Time'}:f);
        body={status:'updated',employment_classification:data.employment_classification,employment_classification_label:'Part Time'};
      }
      else if (data.remove_assignment) { assigned=assigned.filter(f=>f.college_faculty_id!==Number(data.college_faculty_id)); body={status:'removed'}; }
      else if (data.save_assignment) {
        await delay(200);
        if (mode === 'error') { body = { status: 'error', message: 'Your college assignment changed. Reload the page.' }; status = 403; }
        else if (mode === 'duplicate') body = { status: 'duplicate', message: 'This faculty member is already assigned to your college.' };
        else { assigned = [...assigned, { ...asRow(faculties.find(f => String(f.faculty_id) === data.faculty_id)), employment_classification: data.employment_classification }]; body = { status: 'inserted', message: 'Faculty added to your college for the current term.' }; }
      } else throw Error('Unexpected intercepted request: ' + request.url);
      await call('Fetch.fulfillRequest', { requestId, responseCode: status, responseHeaders: [{ name: 'Content-Type', value: type }], body: Buffer.from(typeof body === 'string' ? body : JSON.stringify(body)).toString('base64') }, session);
    })().catch(error => errors.push(error.stack));
  });
  const target = await call('Target.createTarget', { url: 'about:blank' });
  session = (await call('Target.attachToTarget', { targetId: target.targetId, flatten: true })).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
  await call('Fetch.enable', { patterns: [{ urlPattern: '*program-chair/manage-college-faculty.php*' }, { urlPattern: '*backend/query_program_chair_faculty.php*' },{urlPattern:'*backend/query_program_chair_term_lists.php*'},{urlPattern:'*backend/query_program_chair_faculty_account.php*'}] }, session);
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, session);
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  const act = expression => evaluate('(() => {' + expression + '})()');
  const until = async expression => {
    for (let i = 0; i < 100; i++) { if (await evaluate(`Boolean(${expression})`)) return; await delay(100); }
    const details=await evaluate('JSON.stringify({url:location.href,ready:document.readyState,jquery:!!window.jQuery,select2:!!window.jQuery?.fn.select2,swal:!!window.Swal,summary:document.getElementById("facultyResultsSummary")?.textContent,state:typeof facultyListState!=="undefined"?{loading:facultyListState.loading,loaded:facultyListState.loadedCount}:null,scripts:[...document.scripts].map(s=>s.src).filter(Boolean)})');
    throw Error('Condition timed out: ' + expression + '; ' + errors.join('; ') + '; ' + details + '; requests: ' + JSON.stringify(requests.slice(-6)));
  };
  const navigate = async () => { await call('Page.navigate', { url: 'http://localhost/synk/program-chair/manage-college-faculty.php' }, session); await until('window.Helpers?.mainMenu && window.jQuery?.fn.select2 && window.Swal && typeof facultyListState!=="undefined" && facultyListState.requestToken>0 && !facultyListState.loading && !facultyListState.hasMore'); };
  await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, session);
  await navigate();
  check(await evaluate('!document.getElementById("facultyEmptyState").classList.contains("d-none")'), 'The empty college list explains its state.');
  check(await evaluate('![...document.querySelectorAll("#collegeFacultyTable th")].some(th=>th.textContent.trim()==="Status")'), 'The table follows Scheduler columns and actions.');
  await act('document.getElementById("btnAddFaculty").click()'); await until('document.getElementById("addFacultyModal").classList.contains("show")');
  check(await evaluate('document.getElementById("faculty_id").options.length===3 && !document.querySelector("#faculty_id img") && !!document.querySelector("#addFacultyModal .select2-container")'), 'Adding uses the same searchable faculty selector as Scheduler.');
  await act('jQuery("#faculty_id").val("101").trigger("change");document.getElementById("add_employment_classification").value="permanent"');
  mode = 'duplicate';
  await act('jQuery("#addFacultyForm").trigger("submit")');
  await until('document.getElementById("swal2-title")?.textContent==="Already Assigned"');
  check(await evaluate('document.getElementById("addFacultyModal").classList.contains("show") && document.getElementById("faculty_id").value==="101"'), 'Duplicate feedback keeps the selected faculty visible.');
  await act('Swal.close()');
  mode = 'error';
  await act('jQuery("#addFacultyForm").trigger("submit")');
  await until('document.getElementById("swal2-html-container")?.textContent.includes("assignment changed")');
  check(await evaluate('document.getElementById("faculty_id").value==="101" && document.getElementById("addFacultyModal").classList.contains("show")'), 'A failed request preserves the form and shows the scope error.');
  await act('Swal.close()');
  mode = 'success'; const before = requests.filter(r => r.data.save_assignment).length;
  await act('jQuery("#addFacultyForm").trigger("submit");jQuery("#addFacultyForm").trigger("submit")');
  await until('document.querySelectorAll("#collegeFacultyTableBody tr").length===1 && !document.body.classList.contains("modal-open")');
  check(requests.filter(r => r.data.save_assignment).length === before + 1, 'Repeated submit cannot add the same faculty twice.');
  const saved = requests.filter(r => r.data.save_assignment).at(-1).data;
  check(saved.faculty_id === '101' && saved.employment_classification === 'permanent' && saved.csrf_token === 'chair-qa' && saved.college_id && saved.ay_id && saved.semester, 'Saving includes faculty, classification, assigned college, current term, and CSRF.');
  check(await evaluate('document.querySelectorAll("#collegeFacultyMobileList .faculty-mobile-card").length===1 && document.getElementById("facultyResultsSummary").textContent.includes("1 of 1")'), 'Success refreshes desktop and mobile faculty lists.');
  check(await evaluate('!document.querySelector(".faculty-email,.faculty-email-empty") && [...document.querySelectorAll(".faculty-login-summary")].every(e=>e.querySelectorAll(".faculty-login-state,.btn-manage-login").length<=4 && !e.textContent.includes("@"))'),'Faculty identities show at most four login details without credentials in both layouts.');
  check(await evaluate('(()=>{const b=[...document.querySelectorAll("#collegeFacultyTableBody .faculty-action-group .btn")].map(e=>e.getBoundingClientRect());return b.length===3 && b[0].top<b[1].top && b[1].top<b[2].top && Math.abs(b[0].left-b[2].left)<1})()'),'The three clearly labelled action buttons occupy separate vertical rows.');
  await act('Swal.close();document.querySelector("#collegeFacultyTableBody .btn-manage-login").click()');
  await until('document.getElementById("facultyLoginModal").classList.contains("show") && !document.getElementById("facultyLoginFields").disabled && !bootstrap.Modal.getInstance(document.getElementById("facultyLoginModal"))._isTransitioning');
  check(await evaluate('document.getElementById("facultyLoginEmailField").hidden && document.getElementById("facultyLoginEmail").disabled && document.getElementById("facultyLoginEmail").value==="" && [...document.querySelectorAll("#facultyLoginFields input[type=checkbox]")].map(e=>e.value).join(",")==="professor,scheduler"'),'Existing Login Access offers only Faculty/Scheduler without loading account credentials.');
  await act('document.getElementById("facultyLoginProfessor").checked=false;document.getElementById("facultyLoginProfessor").dispatchEvent(new Event("change"));document.getElementById("facultyLoginForm").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');
  check(await evaluate('document.getElementById("facultyLoginNotice").textContent.includes("Select Faculty")'),'Login Access requires at least one permitted role.');
  await act('document.getElementById("facultyLoginProfessor").checked=true;document.getElementById("facultyLoginScheduler").checked=true;document.getElementById("facultyLoginScheduler").dispatchEvent(new Event("change"));document.getElementById("facultyLoginPrimary").value="scheduler";document.getElementById("facultyLoginStatus").value="inactive"');
  loginMode='error';
  await act('document.getElementById("facultyLoginForm").requestSubmit()');
  await until('document.getElementById("facultyLoginNotice").textContent.includes("changed since") && !document.getElementById("facultyLoginSave").disabled');
  check(await evaluate('document.getElementById("facultyLoginStatus").value==="inactive" && document.getElementById("facultyLoginScheduler").checked && document.getElementById("facultyLoginModal").classList.contains("show")'),'A stale login edit keeps the error and selected values in the dialog.');
  loginMode='success';const accountSaves=requests.filter(r=>r.url.includes('faculty_account.php') && r.data.action==='save').length;
  await act('document.getElementById("facultyLoginForm").requestSubmit();document.getElementById("facultyLoginForm").requestSubmit()');
  await until('!document.getElementById("facultyLoginModal").classList.contains("show") && document.querySelectorAll(".faculty-login-state.is-inactive").length===2');
  const accountRequest=requests.filter(r=>r.url.includes('faculty_account.php') && r.data.action==='save').at(-1).data;
  check(requests.filter(r=>r.url.includes('faculty_account.php') && r.data.action==='save').length===accountSaves+1 && accountRequest.roles_json==='["professor","scheduler"]' && accountRequest.primary_role==='scheduler' && accountRequest.status==='inactive' && accountRequest.account_revision==='chair-account-1' && accountRequest.college_id && accountRequest.csrf_token,'Login saving submits one request with only the selected roles, default workspace, college, revision, and CSRF.');
  check(true,'Saved login status refreshes desktop and mobile faculty details.');
  assigned=assigned.map(f=>({...f,faculty_email:'',login_status:'',login_roles:[]}));
  await act('Swal.close();refreshFacultyData({preserveScroll:true})');
  await until('document.querySelector("#collegeFacultyTableBody .btn-manage-login")?.textContent.includes("Login Access") && !document.querySelector("#collegeFacultyTableBody .faculty-login-state")');
  await act('document.querySelector("#collegeFacultyTableBody .btn-manage-login").click()');
  await until('!document.getElementById("facultyLoginFields").disabled && !bootstrap.Modal.getInstance(document.getElementById("facultyLoginModal"))._isTransitioning');
  check(await evaluate('!document.getElementById("facultyLoginEmailField").hidden && !document.getElementById("facultyLoginEmail").disabled && document.getElementById("facultyLoginEmail").value==="" && document.getElementById("facultyLoginEmail").maxLength===150 && document.getElementById("facultyLoginProfessor").checked && !document.getElementById("facultyLoginScheduler").checked'),'Creating a login requests an institutional email and starts with Faculty only.');
  await act('document.getElementById("facultyLoginEmail").value="NEW.FACULTY@SKSU.EDU.PH";document.getElementById("facultyLoginEmail").dispatchEvent(new Event("blur"));document.getElementById("facultyLoginForm").requestSubmit()');
  await until('!document.getElementById("facultyLoginModal").classList.contains("show") && document.querySelector("#collegeFacultyTableBody .faculty-login-state.is-active")');
  check(requests.filter(r=>r.url.includes('faculty_account.php') && r.data.action==='save').at(-1).data.roles_json==='["professor"]','Creating a login saves the normalized email and chosen permitted role.');
  await act('Swal.close();document.querySelector("#collegeFacultyTableBody .btn-edit-designation").click()');
  await until('document.getElementById("designation_id").value==="7" && !document.querySelector("button[form=designationForm]").disabled && !bootstrap.Modal.getInstance(document.getElementById("designationModal"))._isTransitioning');
  check(await evaluate('document.getElementById("designationTermNote").textContent.includes("Previous semesters") && [...document.getElementById("designation_id").options].some(o=>o.value==="0")'),'Designation editing includes the current-term note and No designation.');
  await act('jQuery("#designation_id").val("0").trigger("change");jQuery("#designationForm").trigger("submit")');
  await until('[...document.querySelectorAll(".faculty-designation-slot")].every(e=>e.textContent.trim()==="No designation") && !document.getElementById("designationModal").classList.contains("show")');
  check(true,'Clearing designation updates both faculty views.');
  await act('Swal.close();document.querySelector("#collegeFacultyTableBody .btn-edit-classification").click()');
  await until('document.getElementById("classificationModal").classList.contains("show") && !bootstrap.Modal.getInstance(document.getElementById("classificationModal"))._isTransitioning');
  await act('document.getElementById("employment_classification").value="part_time";jQuery("#classificationForm").trigger("submit")');
  await until('[...document.querySelectorAll(".faculty-classification-slot")].every(e=>e.textContent==="Part Time") && !document.getElementById("classificationModal").classList.contains("show")');
  check(true,'Classification editing updates desktop and mobile faculty rows.');
  await act('Swal.close();document.getElementById("facultySearch").value="unmatched";document.getElementById("facultySearch").dispatchEvent(new Event("input"))');
  await until('!document.getElementById("facultyEmptyState").classList.contains("d-none")');
  check(await evaluate('document.getElementById("facultyEmptyState").textContent.includes("matched your search")'), 'The assigned-faculty search has an empty result state.');
  await act('document.getElementById("facultySearch").value="";document.getElementById("facultySearch").dispatchEvent(new Event("input"))');
  await until('document.querySelectorAll("#collegeFacultyTableBody tr").length===1');
  await act('document.getElementById("btnCopyPreviousFaculty").click()');
  await until('document.querySelectorAll("#termListCopyRows [data-copy-id]").length===2 && !bootstrap.Modal.getInstance(document.getElementById("termListCopyModal"))._isTransitioning');
  check(await evaluate(`document.querySelector('[data-copy-id="101"]').disabled && document.querySelector('[data-copy-id="202"]').checked && !document.querySelector('#termListCopyRows img')`),'Copy preview skips duplicates and escapes faculty names.');
  await act('document.getElementById("termListCopyConfirm").click()');
  await until('document.querySelectorAll("#collegeFacultyTableBody tr").length===2 && !document.getElementById("termListCopyModal").classList.contains("show")');
  check(requests.filter(r=>r.data.action==='copy').at(-1).data.selected_ids==='[202]','Copy submits only selected faculty from the preview.');
  check(await evaluate(`!!document.querySelector('#collegeFacultyTableBody [data-faculty-id="202"] .btn-manage-login') && !document.querySelector('#collegeFacultyTableBody [data-faculty-id="202"] .faculty-login-summary').textContent.includes('administrator') && !document.querySelector('#collegeFacultyTableBody [data-faculty-id="202"] .faculty-login-summary').textContent.includes('Administrator')`),'Accounts show Faculty/Scheduler details without unrelated role labels.');
  await act('Swal.close();document.querySelector(\'#collegeFacultyTableBody [data-faculty-id="202"] .btn-manage-login\').click()');
  await until('!document.getElementById("facultyLoginFields").disabled && !bootstrap.Modal.getInstance(document.getElementById("facultyLoginModal"))._isTransitioning');
  check(await evaluate('!document.getElementById("facultyLoginAdminRoleLabels") && document.getElementById("facultyLoginEmailField").hidden && document.getElementById("facultyLoginPrimaryField").hidden && document.getElementById("facultyLoginStatus").disabled && !document.getElementById("facultyLoginScheduler").disabled'),'Other roles and credentials are omitted while Faculty/Scheduler remain editable.');
  await act('document.getElementById("facultyLoginScheduler").checked=true;document.getElementById("facultyLoginScheduler").dispatchEvent(new Event("change"));document.getElementById("facultyLoginForm").requestSubmit()');
  await until('!document.getElementById("facultyLoginModal").classList.contains("show") && document.querySelector(\'#collegeFacultyTableBody [data-faculty-id="202"] .faculty-login-summary\').textContent.includes("Scheduler")');
  const protectedSave=requests.filter(r=>r.url.includes('faculty_account.php') && r.data.action==='save').at(-1).data;
  check(!('primary_role' in protectedSave) && !('email' in protectedSave) && protectedSave.status==='active' && protectedSave.roles_json==='["professor","scheduler"]','Saving Faculty/Scheduler leaves protected settings to the server without sending account credentials.');
  await act('Swal.close();document.querySelector("#collegeFacultyTableBody .btn-remove-assignment").click()');
  await until('document.getElementById("swal2-html-container")?.textContent.includes("scheduled workloads will be kept")');
  check(true,'Removing confirms that the faculty record and prepared workloads are retained.');
  await act('Swal.clickConfirm()');
  await until('document.querySelectorAll("#collegeFacultyTableBody tr").length===1');
  check(requests.some(r=>r.data.remove_assignment && r.data.ay_id && r.data.semester),'Removing refreshes the current list and includes the expected term.');
  await act('Swal.close()');
  await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: false }, session); await delay(300);
  check(await evaluate('document.documentElement.scrollWidth <= window.innerWidth+1'), 'The college faculty page fits a mobile viewport.');
  historical = true; await navigate(); await delay(250);
  check(await evaluate('document.getElementById("btnAddFaculty").disabled && document.getElementById("btnCopyPreviousFaculty").disabled && !document.querySelector(".btn-edit-designation") && !document.querySelector(".btn-manage-login") && document.getElementById("chair-college-faculty").dataset.canAssign==="0"'), 'Earlier terms show the faculty list with all editing controls disabled.');
  check(errors.length === 0, 'No browser runtime errors: ' + errors.join('; '));
  console.log(`Program Chair faculty: ${checks} browser checks passed.`);
}
main().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (call) try { await call('Browser.close'); } catch (_) {}
  if (ws) ws.close();
  if (browser && browser.exitCode === null) { browser.kill(); await Promise.race([new Promise(resolve => browser.once('exit', resolve)), delay(3000)]); }
  const resolved = path.resolve(profile);
  if (resolved.startsWith(path.resolve(root, '.tmp') + path.sep) && fs.existsSync(resolved)) fs.rmSync(resolved, { recursive: true, force: true });
});
