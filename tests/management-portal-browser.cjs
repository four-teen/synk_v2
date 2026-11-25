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
const qrFixture = path.join(root, '.tmp', `management-qr-${nonce}.png`);
let browser, ws, checks = 0;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };
function requestFields(request) {
  const contentType = Object.entries(request.headers).find(([key]) => key.toLowerCase() === 'content-type')?.[1] || '';
  const boundary = /multipart\/form-data;\s*boundary=([^;]+)/i.exec(contentType)?.[1];
  if (!boundary) return Object.fromEntries(new URLSearchParams(request.postData || ''));
  const body = request.postData ?? Buffer.concat((request.postDataEntries || []).map(entry => Buffer.from(entry.bytes || '', 'base64'))).toString();
  const fields = {};
  for (const part of body.split('--' + boundary)) {
    const [header, value] = part.split('\r\n\r\n');
    const field = /Content-Disposition: form-data; name="([^"]+)"(?:; filename="([^"]*)")?/i.exec(header);
    if (field) fields[field[1]] = field[2] ?? (value || '').trimEnd();
  }
  return fields;
}
async function main() {
  fs.writeFileSync(route, `<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!hash_equals('${token}',(string)($_GET['qa']??''))){http_response_code(404);exit;}define('SYNK_MANAGEMENT_QA',true);define('SYNK_MANAGEMENT_QA_RENDER',true);require '${path.join(root, 'tests/fixtures/management-request.php').replaceAll('\\', '/')}';`);
  const exe = [process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe'].find(fs.existsSync);
  browser = spawn(exe, ['--headless=new', '--disable-gpu', '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
  const portFile = path.join(profile, 'DevToolsActivePort');
  let socket, startupError;
  browser.stderr.resume();
  browser.on('error', error => { startupError = error; });
  for (let attempt = 0; attempt < 200 && !socket && !startupError; attempt++) {
    if (fs.existsSync(portFile)) {
      const [port, socketPath] = fs.readFileSync(portFile, 'utf8').trim().split(/\r?\n/);
      if (Number(port) > 0 && socketPath?.startsWith('/devtools/browser/')) socket = 'ws://127.0.0.1:' + port + socketPath;
    }
    if (!socket) await delay(100);
  }
  if (!socket) throw startupError || Error('Browser startup timed out.');
  ws = new WebSocket(socket);
  await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
  let sequence = 0; const pending = new Map(); const errors = []; const saves = [], catalogRequests = [], organizationRequests = [];
  let organizationContext = {}, organizationInUse = false;
  let catalogFailure = false, linkedContributions = 0, eventDeleteBlock = '', catalogTerm = '2:1';
  let attendanceStatus='NOT_STARTED',attendancePresent=0,attendanceRevision=1;
  let cleanupEventId = 0, cleanupPresent = false, cleanupRevision = 2, cleanupComment = null;
  let attendanceScanFailures=1;
  let collectionDetailAttempts=0;
  let evaluationProgress={status:'SUBMITTED',faculty_count:1,submitted_count:1,label:'Submitted'};
  let importedEventId=0;
  const call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence; const timer = setTimeout(() => { pending.delete(id); reject(Error(method + ' timed out.')); }, 20000);
    pending.set(id, { resolve, reject, timer }); ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  });
  ws.addEventListener('message', event => {
    const msg = JSON.parse(event.data);
    if (msg.id && pending.has(msg.id)) { const p = pending.get(msg.id); pending.delete(msg.id); clearTimeout(p.timer); msg.error ? p.reject(Error(msg.error.message)) : p.resolve(msg.result); }
    if (msg.method === 'Runtime.exceptionThrown') errors.push(msg.params.exceptionDetails.exception?.description || msg.params.exceptionDetails.text);
    if (msg.method === 'Fetch.requestPaused') {
      if (new URL(msg.params.request.url).pathname.endsWith('/management/events.php')) {
        call('Fetch.fulfillRequest',{requestId:msg.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'text/html'}],
          body:Buffer.from('<!doctype html><title>Events list</title>').toString('base64')},msg.sessionId).catch(e=>errors.push(e.message));
        return;
      }
      if (msg.params.request.url.includes('query_organizations.php')) {
        const data = Object.fromEntries(new URLSearchParams(msg.params.request.postData || '')); organizationRequests.push(data);
        const record = { organization_id:data.organization_id, college_id:organizationContext.college_id,campus_id:organizationContext.campus_id,organization_code:'BROWSER ORG',organization_name:'BROWSER ORGANIZATION',status:'ACTIVE',version:'B'.repeat(64),usage:{ accounts:organizationInUse?1:0,events:0,contributions:0,violations:0 } };
        const payload = data.action === 'get' ? { status:'ok',record } : { status:'ok',message:'Organization saved.',organization_id:1 };
        call('Fetch.fulfillRequest',{ requestId:msg.params.requestId,responseCode:200,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify(payload)).toString('base64') },msg.sessionId).catch(e=>errors.push(e.message));
        return;
      }
      if (msg.params.request.url.includes('query_management.php')) {
        const data = requestFields(msg.params.request); catalogRequests.push(data);
        const [ay_id,semester] = catalogTerm.split(':');
        const common = { title:'BROWSER CATALOG RECORD', description:'RECORD DESCRIPTION', organization_id:'1', ay_id, semester, status:'ACTIVE', audience_mode:'ALL', version:'A'.repeat(64) };
        const record = data.kind === 'violations' ? { ...common,violation_id:data.record_id,violation_type:'BROWSER VIOLATION',amount:'75.25',version:'A'.repeat(64) } : data.kind === 'events' ? { ...common,event_id:data.record_id,event_date:'2026-10-26',absence_fine:'25.25',latitude:null,longitude:null,attendance_radius_m:null,linked_contributions:linkedContributions,delete_block:eventDeleteBlock,
          sessions:[{ session_id:'123',title:'MORNING SESSION',starts_at:'2026-10-26 08:00:00',ends_at:'2026-10-26 12:00:00',fine_per_minute:'0.1250',grace_minutes:'15',potential_fine:'28.12',status:'ACTIVE' }] }
          : data.kind === 'flag_raising' ? { ...common,event_id:data.record_id,title:'FLAG RAISING CEREMONY QA',event_date:'2026-10-10',linked_contributions:0,sessions:[] }
          : { ...common,status:'ACTIVE',contribution_id:data.record_id,contribution_date:'2026-10-26',amount:'123.45',event_id:null };
        let payload = data.action === 'get' ? { status:'ok',record } : { status:'ok',message:'Record saved.',record_id:1 };
        if (data.action === 'collection_students') {
          const otherStudents = Array.from({length:25}, (_,index) => ({
            student_id:100 + index, student_name:'Payer ' + (index + 1), student_number:'QA-' + (index + 1), receipt_count:1, total_paid:'2.00'
          }));
          const students = [{student_id:99, student_name:'Alex Payer', student_number:'2026-00123', receipt_count:2, total_paid:'175.50'}, ...otherStudents];
          const words = (data.search || '').toLowerCase().split(/[\s,]+/).filter(Boolean);
          const matches = students.filter(student => words.every(word =>
            student.student_name.toLowerCase().includes(word) || student.student_number.toLowerCase().includes(word)));
          const pages = Math.max(1, Math.ceil(matches.length / 25));
          const page = Math.min(Number(data.page), pages);
          payload = {status:'ok', total_collected:'225.50', total_students:students.length,
            rows:matches.slice((page - 1) * 25, page * 25), pagination:{page, pages, total:matches.length}};
        }
        if (data.action === 'collection_student_payments') {
          payload = ++collectionDetailAttempts === 1 ? {status:'error', message:'Temporary receipt connection failure.'} : {
            status:'ok', total_paid:'175.50', categories:[{label:'Contributions', amount:'150.00'},{label:'Event absence fines', amount:'25.50'}],
            receipts:[
              {payment_id:'901', receipt_number:'QA-002', amount:'50.00', recorded_at:'Nov 25, 2026 8:30 AM', recorded_by:'QA cashier', items:[{title:'Student organization contribution', amount:'50.00'}]},
              {payment_id:'900', receipt_number:'QA-001', amount:'125.50', recorded_at:'Nov 24, 2026 8:30 AM', recorded_by:'QA cashier', items:[{title:'Student organization contribution', amount:'100.00'},{title:'Event absence fine', amount:'25.50'}]}
            ]
          };
        }
        if (data.action === 'student_detail') payload = {
          status:'ok',student:{ first_name:'JUAN MIGUEL',last_name:'DELA CRUZ',student_name:'DELA CRUZ, JUAN MIGUEL',student_number:'2026-00123',email_address:'juan.delacruz@sksu.edu.ph',home_section:'BSCS 3A',original_section:'BSCS 1A',original_year_level:1 },
          evaluation_progress:evaluationProgress,
          subjects:[{ subject_code:'CS 101',descriptive_title:'PROGRAMMING',section_label:'BSCS 2B',program_label:'BSCS',program_name:'Bachelor of Science in Computer Science',year_level:2,term_label:'2026-2027 - 1st Semester',faculty_name:'ASSIGNED FACULTY' }],
          financial:{ period:{key:'2:1:FINAL',text:'2026-2027 - 1st Semester - Final'},organization:{code:'CSSO',name:'CSSO',college:'COLLEGE OF COMPUTER STUDIES',campus:'ISULAN CAMPUS'},
            contributions:[{contribution_id:2,title:'CCS TEAM JERSEY (INTRAMURALS 2026)',contribution_date:'2026-10-27',amount:'350.00',audience_mode:'ALL'},{contribution_id:1,title:'PAISULABAN 2026',contribution_date:'2026-10-27',amount:'150.00',audience_mode:'ALL'}],
            events:[{event_id:3,title:'ATTENDED EVENT',event_date:'2026-11-01',absence_fine:'25.00',student_attendance:'PRESENT',amount_due:'0.00'},{event_id:2,title:'PAISULABAN 2026',event_date:'2026-11-06',absence_fine:'50.00',student_attendance:'PENDING',amount_due:'0.00'},{event_id:1,title:'CCS GENERAL ASSEMBLY',event_date:'2026-11-03',absence_fine:'25.00',student_attendance:'ABSENT',amount_due:'25.00'}],violations:[],payments:[],
            summary:{contribution_count:2,contribution_catalog_total:'500.00',event_count:3,absence_count:1,attendance_pending_count:1,event_fine_total:'25.00',violation_total:'0.00',amount_paid:'0.00',total_to_pay:'525.00',payment_status:'UNPAID'},
            availability:{student_charges:true,payments:false,attendance:true,student_violations:false}}
        };
        if (data.template === 'EVENT' && data.action === 'attendance_past_preview') {
          const examLabel = data.import_exam_term === 'FINAL' ? 'Final' : 'Midterm';
          payload={status:'ok',preview:{token:'C'.repeat(64),title:'CLEAN UP DRIVE',event_date:data.event_date,period:`2026-2027 - 1st Semester - ${examLabel}`,
            term:data.current_term,exam_term:data.import_exam_term,can_import:true,attendance_status:'NOT_STARTED',present:1,absent:2,exempted:0,
            absence_fine:data.absence_fine,workbook_counts:{complete:1,one_entry:1,blank:1},
            review:{unmatched:[],ambiguous:[],duplicates:[],problems:[{cells:['E9'],reason:'Enter a student ID in the attendance cell.'}]}}};
        } else if (data.template === 'EVENT' && data.action === 'attendance_past_save') {
          payload={status:'ok',recorded:1,event_id:321,term:data.current_term,exam_term:data.import_exam_term,attendance_status:'CLOSED',
            present:1,absent:2,exempted:0,absence_fine:data.absence_fine};
        } else if (data.action === 'attendance_past_preview') {
          const corrected = data.workbook === 'FRC corrected.xlsx';
          const closed = data.workbook === 'FRC saved.xlsx';
          payload = {status:'ok',preview:{token:'P'.repeat(64),title:'FLAG RAISING CEREMONY',event_date:'2026-10-10',
            period:'2026-2027 - 1st Semester - Midterm',term:catalogTerm,exam_term:'MIDTERM',worksheet:'FRC sample sheet',existing_event:corrected||closed,
            event_id:corrected||closed ? 321 : null,attendance_status:closed ? 'CLOSED' : 'OPEN',can_import:!closed,new_present:closed ? 0 : 1,already_present:corrected||closed ? 1 : 0,
            duplicates:1,unmatched:corrected ? [] : ['99999'],ambiguous:[],
            review:{unmatched:corrected ? [] : [{student_number:'99999',cells:['A3','A5']}],ambiguous:[],
              duplicates:[{student_number:'11111',cells:['A2','A4']}]}}};
        } else if (data.action === 'attendance_past_save') {
          payload = {status:'ok',recorded:1,title:'FLAG RAISING CEREMONY',term:catalogTerm,exam_term:'MIDTERM',event_id:321,
            review:{unmatched:data.workbook==='FRC corrected.xlsx' ? [] : [{student_number:'99999',cells:['A3','A5']}],
              ambiguous:[],duplicates:[{student_number:'11111',cells:['A2','A4']}]}};
        } else if (data.action === 'exemption_data' && importedEventId) {
          payload = {status:'ok',lists:[],contributions:[],editable_imports:[importedEventId],
            events:[{event_id:importedEventId,title:'FLAG RAISING CEREMONY QA',event_date:'2026-10-10',contribution_count:0}]};
        } else if (data.action === 'attendance_roster') {
          const cleanup = Number(data.event_id) === cleanupEventId;
          const present = !cleanup || cleanupPresent;
          payload={status:'ok',roster:{page:1,pages:1,total:1,revision:String(cleanup ? cleanupRevision : attendanceRevision),
            rows:[{student_id:1234,student_number:'20261123',student_name:'JUAN DELA CRUZ',checked_in:cleanup ? true : null,
              checked_out:cleanup ? false : null,attendance_status:present ? 'PRESENT' : 'ABSENT',correction_reason:cleanup ? cleanupComment : null}]}};
        } else if (Number(data.event_id) === cleanupEventId && ['attendance_get','attendance_correct'].includes(data.action)) {
          if (data.action === 'attendance_correct') {
            cleanupPresent = data.corrected_status === 'PRESENT';
            cleanupComment = data.reason;
            cleanupRevision++;
          }
          payload={status:'ok',corrected:data.action==='attendance_correct',attendance:{event_id:cleanupEventId,title:'CLEAN UP DRIVE QA',event_date:'2026-11-12',absence_fine:'25.00',
            attendance_status:'CLOSED',two_checkpoints:true,opened_at:'2026-11-24 14:56:00',closed_at:'2026-11-24 14:56:31',
            revision:String(cleanupRevision),eligible:2,present:cleanupPresent?2:1,absent:cleanupPresent?0:1,exempted:0,recent:[]}};
        } else if(data.action.startsWith('attendance_')){
          if(data.action==='attendance_scan' && attendanceScanFailures-- > 0){
            call('Fetch.fulfillRequest',{requestId:msg.params.requestId,responseCode:503,responseHeaders:[{name:'Content-Type',value:'application/json'}],body:Buffer.from(JSON.stringify({status:'error',message:'Temporary attendance connection failure. Scan again.'})).toString('base64')},msg.sessionId).catch(e=>errors.push(e.message));return;
          }
          if(data.action==='attendance_open')attendanceStatus='OPEN';
          const duplicate=attendancePresent>0;
          if(data.action==='attendance_scan')attendancePresent=1;
          if(data.action==='attendance_close'){attendanceStatus='CLOSED';attendanceRevision++;}
          if(data.action==='attendance_reopen'){attendanceStatus='OPEN';attendanceRevision++;}
          payload={status:'ok',attendance:{event_id:Number(data.event_id),title:'CCS GENERAL ASSEMBLY',event_date:'2026-10-26',absence_fine:'25.00',attendance_status:attendanceStatus,revision:String(attendanceRevision),eligible:2,present:attendancePresent,absent:attendanceStatus==='CLOSED'?2-attendancePresent:0,recent:attendancePresent?[{student_name:'JUAN DELA CRUZ',student_number:'2026-00123',recorded_at:'2026-10-27 09:30:00'}]:[]},student:{student_name:'JUAN DELA CRUZ',student_number:'2026-00123',recorded_at:'2026-10-27 09:30:00',photo_url:'../student/profile-placeholder.svg'},duplicate};
        }
        if (data.action === 'save_management_term') payload={status:'error',message:'The current Management term changed. Reload this page.'};
        if (data.action === 'organization_add') payload = { status:'ok',organization:{ organization_id:999999,organization_code:'NEW ORG',organization_name:'NEW ORGANIZATION' } };
        if (data.action === 'save' && catalogFailure) payload={ status:'error',message:'The record changed. Reload its details.' };
        if (data.kind === 'flag_raising' && data.action === 'delete') payload={status:'ok',message:'Flag Raising Ceremony deleted.',record_id:data.record_id};
        call('Fetch.fulfillRequest', { requestId:msg.params.requestId,responseCode:(catalogFailure && data.action==='save') || data.action==='save_management_term' ? 409 : 200,responseHeaders:[{ name:'Content-Type',value:'application/json' }],body:Buffer.from(JSON.stringify(payload)).toString('base64') },msg.sessionId).catch(e=>errors.push(e.message));
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
  const resize = async (width,height=960) => { await call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false }, sessionId); await call('Emulation.setTouchEmulationEnabled',{enabled:width<1200},sessionId); await delay(120); };
  const navigate = async (scenario,params={}) => {
    const query = new URLSearchParams({qa:token,scenario,...params});
    await call('Page.navigate', { url: `http://localhost/synk/tmp/${path.basename(route)}?${query}` }, sessionId);
    await until('document.readyState === "complete" && location.search==='+JSON.stringify('?'+query)); await delay(150);
  };
  const screenshot = async name => { const r = await call('Page.captureScreenshot', { format: 'png' }, sessionId); fs.writeFileSync(path.join(root, '.tmp', name), Buffer.from(r.data, 'base64')); };
  const tap = async selector => {
    const point=await evaluate(`(()=>{const element=document.querySelector(${JSON.stringify(selector)});element.scrollIntoView({block:'center'});const r=element.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2}})()`);
    await call('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[point]},sessionId);
    await call('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]},sessionId);
  };
  const checkMobileRecords = async label => {
    check(await evaluate('(()=>{const table=document.querySelector(".management-mobile-records"),region=table.parentElement;return table.scrollWidth<=table.clientWidth+1&&region.scrollWidth<=region.clientWidth+1&&getComputedStyle(table.querySelector("tbody tr")).display==="grid"&&[...table.querySelectorAll("tbody button:not(:disabled)")].every(button=>button.getBoundingClientRect().height>=44)&&[...table.querySelectorAll("tbody td")].every(cell=>cell.classList.contains("management-record-title")||cell.classList.contains("management-record-actions")||cell.classList.contains("management-record-empty")||cell.dataset.label)})()'),`${label} cards overflow, lack labels, or have small touch targets.`);
    check(await evaluate('(()=>{const config=JSON.parse(document.getElementById("management-config").textContent);return [...document.querySelectorAll(".management-mobile-nav a,#management-mobile-more nav a")].every(a=>{const url=new URL(a.href);return url.searchParams.get("term")===config.term&&url.searchParams.get("exam_term")===config.exam_term})})()'),`${label} mobile links lost the viewing period.`);
  };
  const checkEventActions = async (label, attendanceLabel) => {
    const layout = await evaluate(`(() => {
      const actions = document.querySelector('.management-event-actions');
      const attendance = actions.querySelector('[data-attendance]');
      const primary = attendance.getBoundingClientRect();
      const table = actions.closest('.management-table-scroll').getBoundingClientRect();
      const [viewExemptions, exemptStudents, edit, remove] = ['a', '[data-exempt-open]', '[data-edit]', '[data-delete]']
        .map(selector => actions.querySelector(selector).getBoundingClientRect());
      return attendance.textContent.trim() === ${JSON.stringify(attendanceLabel)} &&
        primary.left > Math.max(viewExemptions.right, exemptStudents.right, edit.right, remove.right) &&
        Math.abs(primary.top - viewExemptions.top) < 1 && Math.abs(primary.top - exemptStudents.top) < 1 &&
        Math.abs(primary.bottom - edit.bottom) < 1 && Math.abs(primary.bottom - remove.bottom) < 1 &&
        edit.top > viewExemptions.bottom && primary.right < table.right;
    })()`);
    check(layout, `${label} attendance button does not span both action rows on the right.`);
  };
  async function checkPastAttendance() {
    await evaluate('document.querySelector(\'[data-bs-target="#management-past-attendance"]\').click()');
    await until('document.querySelector("#management-past-attendance.show") && getComputedStyle(document.getElementById("management-past-attendance")).opacity==="1"');
    check(await evaluate('(()=>{const date=document.getElementById("past-attendance-date"),year=document.getElementById("past-attendance-year"),semester=document.getElementById("past-attendance-semester");return date.required&&year.readOnly&&/^\\d{4}-\\d{4}$/.test(year.value)&&semester.readOnly&&semester.value.includes("Semester")})()'),
      'The import modal lacks the required date, academic year, or semester.');
    await evaluate('(()=>{const date=document.getElementById("past-attendance-date");date.value="2026-10-10";date.dispatchEvent(new Event("change",{bubbles:true}));document.getElementById("past-event-title").value="Flag Raising Ceremony";document.getElementById("past-attendance-template").value="FRC";const selected=new DataTransfer();selected.items.add(new File(["Sample workbook contents"],"FRC sample.xlsx"));document.getElementById("past-attendance-workbook").files=selected.files;document.getElementById("past-attendance-form").requestSubmit()})()');
    await until('!document.getElementById("past-attendance-event-link").hidden');
    check(await evaluate('document.getElementById("past-attendance-summary").textContent.includes("Saved 1 as present") && document.querySelector("#past-attendance-issues li").textContent.includes("99999 · A3, A5")'),
      'One import did not save its match and list the unmatched ID with its workbook cells.');
    check(await evaluate('!document.getElementById("past-attendance-duplicates").hidden && document.querySelector("#past-attendance-duplicate-rows li").textContent.includes("11111 · A2, A4")'),
      'The preview did not list repeated IDs with their workbook cells.');
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth && !document.getElementById("past-attendance-review") && document.getElementById("past-attendance-save").hidden'),
      'The import results overflow or leave an extra confirmation on the phone.');
    const pastAttendance = catalogRequests.find(row=>row.template==='FRC' && row.action==='attendance_past_save');
    check(pastAttendance.title==='Flag Raising Ceremony' && pastAttendance.template==='FRC' && pastAttendance.preview_token.length===64
      && pastAttendance.workbook==='FRC sample.xlsx','Past attendance confirmation lost the title, template, workbook, or preview token.');
    const [academicYear, semester] = catalogTerm.split(':');
    for (const action of ['attendance_past_preview', 'attendance_past_save']) {
      const payload = catalogRequests.find(row=>row.template==='FRC' && row.action===action);
      check(payload.event_date==='2026-10-10' && payload.import_ay_id===academicYear && payload.import_semester===semester && payload.import_exam_term==='MIDTERM',
        'The import request lost its date or academic period.');
    }
    check(await evaluate('(()=>{const url=new URL(document.getElementById("past-attendance-event-link").href);return url.pathname.endsWith("/management/flag-raising.php") && url.searchParams.get("exam_term")==="MIDTERM" && url.searchParams.get("event_id")==="321"})()'),
      'The saved event link did not open its Management period.');
    check(await evaluate('!document.getElementById("past-attendance-exceptions").hidden && document.querySelector("#past-attendance-issues li").textContent.includes("99999 · A3, A5")'),
      'Saving matched students hid the remaining unmatched ID.');
    await evaluate('(()=>{const selected=new DataTransfer();selected.items.add(new File(["Corrected workbook contents"],"FRC corrected.xlsx"));const input=document.getElementById("past-attendance-workbook");input.files=selected.files;input.dispatchEvent(new Event("change",{bubbles:true}));document.getElementById("past-attendance-form").requestSubmit()})()');
    await until('!document.getElementById("past-attendance-event-link").hidden');
    check(await evaluate('document.getElementById("past-attendance-exceptions").hidden && document.getElementById("past-attendance-summary").textContent.includes("1 already marked present")'),
      'A corrected upload did not clear the old unmatched ID or show previously recorded attendance.');
    await evaluate('(()=>{const selected=new DataTransfer();selected.items.add(new File(["Saved workbook contents"],"FRC saved.xlsx"));const input=document.getElementById("past-attendance-workbook");input.files=selected.files;input.dispatchEvent(new Event("change",{bubbles:true}));document.getElementById("past-attendance-form").requestSubmit()})()');
    await until('!document.getElementById("past-attendance-event-link").hidden');
    check(await evaluate('document.getElementById("past-attendance-save").hidden && document.getElementById("past-attendance-summary").textContent.includes("All matched students are already saved") && new URL(document.getElementById("past-attendance-event-link").href).hash==="#event-321"'),
      'A repeated import did not explain that attendance was already saved.');
    check(!catalogRequests.some(row=>row.action==='attendance_past_save' && row.workbook==='FRC saved.xlsx'),'An already-saved workbook posted another save.');
  }
  if (process.argv.includes('--past-attendance')) {
    await resize(320,568);
    await call('Fetch.enable',{patterns:[{urlPattern:'*query_management.php*'},{urlPattern:'*/management/events.php*',resourceType:'Document'}]},sessionId);
    await navigate('events');
    check(await evaluate('document.querySelector(\'[data-bs-target="#management-past-attendance"]\') && document.getElementById("record-absence") && document.querySelector(\'#layout-menu a[href^="events.php"]\').closest("li").nextElementSibling.querySelector("a").href.includes("flag-raising.php")'),
      'Regular Events is missing its import button, event fine, or the link to Flag Raising Ceremony.');
    await checkEventActions('Events on mobile', 'Take attendance');
    await evaluate('document.querySelector(\'[data-bs-target="#management-past-attendance"]\').click()');
    await until('document.querySelector("#management-past-attendance.show") && getComputedStyle(document.getElementById("management-past-attendance")).opacity==="1"');
    check(await evaluate('document.querySelector("#past-attendance-template option[value=EVENT]").textContent.trim()==="EVENT" && !document.querySelector("#past-attendance-template option[value=CUD],#past-attendance-template option[value=FRC]") && document.getElementById("past-attendance-fine").required && document.documentElement.scrollWidth<=innerWidth'),
      'Events import exposed the wrong template, lacked the required fine, or overflowed on mobile.');
    await evaluate(`(() => {
      const form = document.getElementById('past-attendance-form');
      form.elements.title.value = 'Clean Up Drive';
      form.elements.import_exam_term.value = 'FINAL';
      form.elements.import_exam_term.dispatchEvent(new Event('change', {bubbles:true}));
      form.elements.event_date.value = '2026-10-24';
      form.elements.event_date.dispatchEvent(new Event('change', {bubbles:true}));
      form.elements.template.value = 'EVENT';
      form.elements.absence_fine.value = '25.50';
      const workbook = new DataTransfer();
      workbook.items.add(new File(['Fixture workbook'], 'CUD.xlsx'));
      form.elements.workbook.files = workbook.files;
      form.requestSubmit();
    })()`);
    await until('document.getElementById("past-attendance-save").textContent==="Save closed attendance"');
    const eventPreview=catalogRequests.find(row=>row.template==='EVENT' && row.action==='attendance_past_preview');
    check(eventPreview.import_exam_term==='FINAL' && !catalogRequests.some(row=>row.template==='EVENT' && row.action==='attendance_past_save')
      && await evaluate('document.getElementById("past-attendance-summary").textContent.includes("Final") && document.getElementById("past-attendance-summary").textContent.includes("1 present, 2 absent") && document.getElementById("past-attendance-summary").textContent.includes("25.50") && document.querySelector("#past-attendance-issues li").textContent.includes("E9")'),
      'The preview ignored the selected Final term, saved automatically, or omitted import details.');
    await evaluate('document.getElementById("past-attendance-form").requestSubmit()');
    await until('!document.getElementById("past-attendance-event-link").hidden');
    const cudSaved=catalogRequests.find(row=>row.template==='EVENT' && row.action==='attendance_past_save');
    check(cudSaved.confirm==='1' && cudSaved.preview_token==='C'.repeat(64) && cudSaved.event_date==='2026-10-24' && cudSaved.import_exam_term==='FINAL'
      && cudSaved.current_term===`${cudSaved.import_ay_id}:${cudSaved.import_semester}` && cudSaved.absence_fine==='25.50'
      && await evaluate('document.getElementById("past-attendance-summary").textContent.includes("saved and closed") && new URL(document.getElementById("past-attendance-event-link").href).pathname.endsWith("/management/events.php")'),
      'Confirmed CUD import lost the date, period or fine, or linked to the wrong list.');
    await evaluate('document.querySelector("#management-past-attendance .modal-footer [data-bs-dismiss]").click()');
    await until('location.pathname.endsWith("/management/events.php") && document.readyState==="complete"');
    check(await evaluate('(()=>{const url=new URL(location.href);return url.searchParams.get("saved_event_id")==="321" && url.searchParams.get("exam_term")==="FINAL" && !["event_id","search","status","page"].some(key=>url.searchParams.has(key))})()'),
      'Closing a saved import did not reopen the complete selected-period list with the newly saved event first.');
    await navigate('imported_event_default');
    catalogTerm = await evaluate('JSON.parse(document.getElementById("management-config").textContent).term');
    await checkEventActions('Flag Raising Ceremony on mobile', 'View attendance');
    await checkMobileRecords('Flag Raising Ceremony');
    await checkPastAttendance();
    await resize(1440);
    await navigate('closed_cleanup');
    cleanupEventId = await evaluate('Number(document.querySelector("[data-event-row]").dataset.eventRow)');
    await evaluate('document.querySelector("[data-event-attendance-action-label]").click()');
    await until('document.querySelector("#attendance-roster-rows [data-correct-attendance]") && getComputedStyle(document.getElementById("management-attendance")).opacity==="1"');
    check(await evaluate(`(() => {
      const roster = document.getElementById('attendance-roster'), scanner = document.querySelector('.management-attendance-reader-grid');
      const times = document.getElementById('attendance-times'), columns = document.querySelectorAll('[data-attendance-checkpoint]:not([hidden])');
      return document.querySelector('#management-attendance .modal-xl .modal-content').getBoundingClientRect().width > 900 && !roster.hidden && scanner.hidden &&
        getComputedStyle(document.querySelector('.management-attendance-camera-column')).display === 'none' &&
        columns.length === 2 && times.getBoundingClientRect().bottom < roster.getBoundingClientRect().top &&
        !times.textContent.includes('—') && roster.textContent.includes('Missing');
    })()`), 'Closed CUD attendance did not show the wide roster and top timestamps without the scanner.');
    await screenshot('management-closed-attendance-review.png');
    await evaluate('document.querySelector("[data-correct-attendance]").click()');
    check(await evaluate('document.getElementById("attendance-correction-status").value==="ABSENT" && document.getElementById("attendance-correction-in").checked && !document.getElementById("attendance-correction-out").checked && document.getElementById("attendance-correction-reason").required'), 'The correction editor did not display the status and checkpoints or require a comment.');
    await resize(390,780);
    check(await evaluate('document.documentElement.scrollWidth<=innerWidth && document.getElementById("attendance-correction").getBoundingClientRect().right<=innerWidth'), 'The correction form overflows on mobile.');
    await evaluate('document.getElementById("attendance-correction-status").value="PRESENT";document.getElementById("attendance-correction-reason").value="Paper OUT log verified";document.getElementById("attendance-correction").requestSubmit()');
    await until('document.querySelector("#attendance-roster-rows .badge")?.textContent==="Present"');
    const correction = catalogRequests.find(row=>row.action==='attendance_correct');
    check(correction.event_id===String(cleanupEventId) && correction.corrected_status==='PRESENT' && !('checked_in' in correction) && !('checked_out' in correction) && correction.attendance_revision==='2'
      && correction.reason==='Paper OUT log verified' && await evaluate('document.getElementById("attendance-present").textContent==="2" && document.getElementById("attendance-status").textContent==="Closed" && document.getElementById("attendance-roster-rows").textContent.includes("Paper OUT log verified")'),
      'The correction lost its evidence, changed the event state, or missed updated totals.');
    await evaluate('document.querySelector("[data-correct-attendance]").click();document.getElementById("attendance-correction-status").value="ABSENT";document.getElementById("attendance-correction-reason").value="Wrong event";document.getElementById("attendance-correction").requestSubmit()');
    await until('document.querySelector("#attendance-roster-rows .badge")?.textContent==="Absent"');
    check(catalogRequests.some(row=>row.action==='attendance_correct' && row.corrected_status==='ABSENT' && row.attendance_revision==='3' && row.reason==='Wrong event')
      && await evaluate('document.getElementById("attendance-present").textContent==="1" && document.getElementById("attendance-roster-rows").textContent.includes("Wrong event")'),
      'Changing Present to Absent did not send or display the new correction comment.');
    await resize(1440);
    await navigate('imported_event_default');
    check(await evaluate('document.querySelector("[data-event-row]").textContent.includes("FLAG RAISING CEREMONY QA") && document.querySelector("[data-event-row]").dataset.eventExamTerm==="MIDTERM" && document.querySelector("[data-event-attendance-status]").textContent.trim()==="Closed" && document.querySelectorAll("[data-event-row]").length===1 && JSON.parse(document.getElementById("management-config").textContent).exam_term==="FINAL"'),
      'The ceremony page did not show the imported record across the semester.');
    check(await evaluate('document.querySelectorAll(".management-record-actions a,.management-record-actions button").length===5 && document.querySelector("#ceremony-absences tbody").rows.length===2 && document.getElementById("management-add").textContent.trim()==="Add attendance" && !document.getElementById("record-absence")'),
      'The ceremony page is missing action buttons or student counts, or offered an absence fine.');
    await checkEventActions('Flag Raising Ceremony on desktop', 'View attendance');
    await resize(1280);
    await checkEventActions('Flag Raising Ceremony on a narrow desktop', 'View attendance');
    await resize(1440);
    await screenshot('management-flag-raising.png');
    await evaluate('document.querySelector(\'[data-bs-target="#management-past-attendance"]\').click()');
    await until('document.querySelector("#management-past-attendance.show") && getComputedStyle(document.getElementById("management-past-attendance")).opacity==="1"');
    check(await evaluate('document.querySelector("#management-past-attendance .modal-content").getBoundingClientRect().width>500 && document.documentElement.scrollWidth<=innerWidth'), 'Past attendance does not fit desktop.');
    await screenshot('management-past-attendance-period.png');
    await navigate('imported_event_staff');
    check(await evaluate('document.querySelector("[data-edit]") && !document.querySelector("[data-delete]")'), 'Unprivileged staff were offered ceremony deletion.');
    await navigate('imported_event_default');
    const ceremonyId = await evaluate('document.querySelector("[data-delete]").dataset.delete');
    await evaluate('new Promise(resolve=>{document.getElementById("management-delete-modal").addEventListener("shown.bs.modal",()=>resolve(true),{once:true});document.querySelector("[data-delete]").click()})');
    await until('document.querySelector("#management-delete-modal.show") && !document.getElementById("management-delete-event-confirmation").disabled');
    check(await evaluate('document.getElementById("management-delete-confirm").disabled && document.getElementById("management-delete-explanation").textContent.includes("roster, attendance records, exemptions, and sessions") && document.getElementById("management-delete-name").textContent.includes("2026-10-10")'),
      'Ceremony deletion did not identify the record and require confirmation.');
    await evaluate('document.getElementById("management-delete-event-confirmation").click();document.querySelector("#management-delete-modal [data-bs-dismiss]").click()');
    await until('document.getElementById("management-delete-modal").getAttribute("aria-hidden")==="true"');
    check(!catalogRequests.some(row=>row.action==='delete' && row.kind==='flag_raising'), 'Cancelling ceremony deletion submitted a request.');
    await evaluate('new Promise(resolve=>{document.getElementById("management-delete-modal").addEventListener("shown.bs.modal",()=>resolve(true),{once:true});document.querySelector("[data-delete]").click()})');
    await until('document.querySelector("#management-delete-modal.show") && !document.getElementById("management-delete-event-confirmation").disabled');
    check(await evaluate('document.getElementById("management-delete-confirm").disabled && !document.getElementById("management-delete-event-confirmation").checked'), 'Confirmation carried over after cancellation.');
    await evaluate('document.getElementById("management-delete-event-confirmation").click();document.getElementById("management-delete-confirm").click()');
    await until('performance.getEntriesByType("navigation")[0]?.type==="reload" && document.readyState==="complete"');
    check(catalogRequests.some(row=>row.action==='delete' && row.kind==='flag_raising' && row.record_id===ceremonyId && row.version.length===64 && row.confirm==='1'),
      'Confirmed deletion did not send the selected record and its version.');
    await navigate('imported_event');
    importedEventId=await evaluate('Number(document.querySelector("[data-event-row]").dataset.eventRow)');
    check(await evaluate('document.querySelector("[data-event-present]").textContent==="1" && document.querySelector("[data-event-remaining]").textContent==="1" && document.querySelector("[data-event-remaining-label]").textContent==="absent" && document.querySelector("[data-event-attendance-label]").textContent==="Closed"'),
      'The Midterm event list did not show the closed event and actual counts.');
    check(await evaluate('document.querySelector("[data-exempt-event]").textContent==="Exempt students" && !document.querySelector(".management-record-actions").textContent.includes("View only")'),
      'The closed event row did not show that exemptions are editable.');
    await evaluate('document.querySelector("[data-exempt-event]").click()');
    await until('document.querySelector("#management-exemption-modal.show") && document.querySelector("#exemption-event-choices input:checked") && !document.getElementById("exemption-fields").disabled');
    check(await evaluate('document.querySelector("#management-exemption-modal.show") && document.getElementById("exemption-contribution-fields").hidden'), 'A closed Midterm import could not open its exemption editor.');
    check(errors.length === 0, 'Browser errors: ' + errors.join(', '));
    console.log('Past attendance browser checks passed: ' + checks);
    await call('Browser.close');
    return;
  }
  await resize(1440); await navigate('login');
  await until('Number(getComputedStyle(document.querySelector("[data-synk-login-content]")).opacity)>0.99');
  check(await evaluate('document.querySelector("h1").textContent.includes("CCS Management.") && document.querySelector("h1").textContent.includes("One Place.")'), 'Management login branding is incorrect.');
  check(await evaluate('document.querySelectorAll("input[type=email], input[type=password]").length===0 && document.querySelector("#managementLoginForm input[name=csrf_token]").value.length===64 && !document.getElementById("managementGoogleLogin").disabled'), 'Login must expose only Google sign-in and a valid request token.');
  for (const width of [1440, 768, 390, 320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Management login overflows ${width}px.`); }
  await screenshot('management-login-320.png');
  await resize(1440); await navigate('dashboard');
  check(await evaluate('[...document.querySelectorAll("[data-stat]")].map(node=>node.dataset.stat).join("|")==="students|expected|collected|outstanding" && document.querySelector("[data-stat=expected]").parentElement.textContent.includes("After exemptions") && document.querySelectorAll(".management-nav a").length===10 && document.querySelector(".management-nav [aria-current=page]").textContent.includes("Dashboard")'), 'Dashboard collection totals or navigation are missing.');
  check(await evaluate('document.documentElement.classList.contains("layout-menu-fixed") && document.getElementById("layout-menu").classList.contains("bg-menu-theme") && document.getElementById("layout-navbar").classList.contains("navbar-detached") && !document.querySelector(".management-nav a:not(.college-dean-menu-card)")'), 'Management is not using the shared module layout/header/card navigation.');
  check(await evaluate('getComputedStyle(document.querySelector(".college-dean-menu-title")).fontSize==="13.92px" && getComputedStyle(document.querySelector(".college-dean-menu-icon")).width==="34px" && getComputedStyle(document.querySelector(".college-dean-menu-card")).borderTopWidth==="1px"'), 'Sidebar fonts, icon sizes, or card borders differ from the Dean template.');
  check(await evaluate('document.compatMode==="CSS1Compat" && document.querySelectorAll("script[src*=jquery]").length===0'), 'Management dashboard is not in standards mode or loads duplicate libraries.');
  await screenshot('management-dashboard-1440.png');
  await call('Fetch.enable',{patterns:[{urlPattern:'*query_management.php*'}]},sessionId);
  await navigate('dashboard_collections');
  const collectionRequests = () => catalogRequests.filter(row => row.action?.startsWith('collection_'));
  check(collectionRequests().length===0,'Dashboard fetched student payments before opening Total Collected.');
  await evaluate('document.getElementById("management-collected-stat").click()');
  await until('document.getElementById("management-collections").classList.contains("show") && document.querySelectorAll("[data-collection-student]").length===25');
  check(collectionRequests().length===1 && collectionRequests()[0].collection_period.endsWith(':FINAL') && collectionRequests()[0].exam_term==='MIDTERM',
    'Collection request used the dashboard viewing term or preloaded individual receipts.');
  const collectionsSummary = await evaluate(`({
    period: document.querySelector('#management-collections-period').textContent,
    total: document.querySelector('[data-collection-total]').textContent,
    cardTotal: document.querySelector('[data-stat=collected]').textContent,
    width: document.querySelector('.management-collections-modal .modal-dialog').offsetWidth,
    paid: document.querySelector('[data-collection-student-total]').textContent
  })`);
  check(collectionsSummary.period.includes('Final') && collectionsSummary.total===collectionsSummary.cardTotal &&
    collectionsSummary.width>1000 && collectionsSummary.paid.includes('175.50'),
    'The wide collections panel shows the wrong period, total, or student payment amount. ' + JSON.stringify(collectionsSummary));
  await evaluate('document.querySelector("[data-collection-student] summary").click()');
  await until('document.querySelector("[data-collection-student-retry]")');
  await evaluate('document.querySelector("[data-collection-student-retry]").click()');
  await until('document.querySelectorAll(".management-collections-receipt-heading").length===2');
  check(collectionDetailAttempts===2 && collectionRequests().at(-1).student_id==='99' && await evaluate('document.querySelector("[data-collection-detail]").textContent.includes("Event absence fine") && document.querySelector(".management-collections-receipt-heading a").href.endsWith("payment_id=901")'),
    'The expanded row did not retry and show the correct student receipts and allocations.');
  await screenshot('management-collections-1440.png');
  await evaluate('const summary=document.querySelector("[data-collection-student] summary"); summary.click(); summary.click()');
  await delay(150);
  check(collectionDetailAttempts===2,'An already loaded payment row fetched the receipts again.');
  for (const [width,height] of [[768,960],[390,780],[320,640]]) {
    await resize(width,height);
    const fit = await evaluate(`(() => {
      const modal = document.getElementById('management-collections');
      const body = modal.querySelector('.modal-body');
      const pager = modal.querySelector('[data-collection-pagination]');
      const search = document.getElementById('management-collection-search-input').getBoundingClientRect();
      return {
        scrollWidth: body.scrollWidth, bodyWidth: body.clientWidth, viewportWidth: innerWidth, viewportHeight: innerHeight,
        dialogRight: modal.querySelector('.modal-dialog').getBoundingClientRect().right,
        closeTop: modal.querySelector('.btn-close').getBoundingClientRect().top,
        searchFits: search.left >= 0 && search.right <= innerWidth && search.height >= 44,
        paginationVisible: !pager.hidden, paginationBottom: pager.getBoundingClientRect().bottom
      };
    })()`);
    check(fit.scrollWidth<=fit.bodyWidth+1 && fit.dialogRight<=fit.viewportWidth && fit.closeTop>=0 &&
      fit.searchFits && fit.paginationVisible && fit.paginationBottom<=fit.viewportHeight,
      `Expanded student payments overflow or block controls at ${width}px. ${JSON.stringify(fit)}`);
  }
  await screenshot('management-collections-320.png');
  await evaluate('document.querySelector("[data-collection-next]").click()');
  await until('document.querySelector("[data-collection-page]").textContent==="Page 2 of 2" && document.querySelector("[data-collection-student]").textContent.includes("Payer 25")');
  check(collectionRequests().at(-1).page==='2' && await evaluate('document.querySelector("[data-collection-next]").disabled && !document.querySelector("[data-collection-previous]").disabled'),
    'Payer pagination failed to request or render the second page.');
  const searchRequestCount = collectionRequests().length;
  await evaluate(`(() => {
    const input = document.getElementById('management-collection-search-input');
    input.focus();
    for (const value of ['2026', '2026-00123']) {
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }
  })()`);
  await until('document.querySelector("[data-collection-matches]").textContent==="1 student found" && document.querySelector("[data-collection-student]").textContent.includes("Alex Payer")');
  check(collectionRequests().length===searchRequestCount+1 && collectionRequests().at(-1).page==='1' &&
    collectionRequests().at(-1).search==='2026-00123' && collectionRequests().at(-1).collection_period.endsWith(':FINAL'),
    'Typing an ID did not filter the full period, debounce the request, or reset pagination.');
  check(await evaluate('document.querySelector("[data-collection-total]").textContent.includes("225.50") && document.querySelector("[data-collection-payers]").textContent==="26 students" && document.querySelectorAll("[data-collection-student]").length===1 && document.activeElement.id==="management-collection-search-input"'),
    'Search altered the period totals, showed extra rows, or moved keyboard focus.');
  await evaluate('document.getElementById("management-collection-search-input").value="Payer 25";document.querySelector("[data-collection-search]").requestSubmit()');
  await until('document.querySelector("[data-collection-student]")?.textContent.includes("QA-25")');
  check(collectionRequests().at(-1).page==='1' && collectionRequests().at(-1).search==='Payer 25',
    'Submitting a name did not find a student outside the first unfiltered page.');
  await evaluate('document.getElementById("management-collection-search-input").value="No matching payer";document.querySelector("[data-collection-search]").requestSubmit()');
  await until('document.querySelector("[data-collection-state]").textContent==="No students with payments match your search."');
  await evaluate('const search=document.getElementById("management-collection-search-input");search.value="";search.dispatchEvent(new Event("input"))');
  await until('document.querySelectorAll("[data-collection-student]").length===25 && document.querySelector("[data-collection-page]").textContent==="Page 1 of 2"');
  check(collectionRequests().at(-1).search==='' && await evaluate('document.querySelector("[data-collection-matches]").hidden'),
    'Clearing search did not restore the full list and its pagination.');
  await evaluate('document.querySelector("#management-collections .btn-close").click()');
  await until('document.activeElement===document.getElementById("management-collected-stat")');
  await resize(1440);
  await navigate('permits');
  check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Print Student Permit") && document.getElementById("permit-exam-term").value==="FINAL" && document.getElementById("management-permit-print").disabled'), 'The permit menu, Final default, or initial print state is wrong.');
  check(await evaluate(`(() => {
    const course = document.getElementById('permit-course'), year = document.getElementById('permit-year-level'), section = document.getElementById('permit-section');
    if (!year.disabled || !section.disabled) return false;
    for (const option of [...course.options].slice(1)) {
      course.value = option.value; course.dispatchEvent(new Event('change', {bubbles:true}));
      if (![...year.options].some(option => option.value === '2')) continue;
      year.value = '2'; year.dispatchEvent(new Event('change', {bubbles:true}));
      const fixture = [...section.options].find(option => option.textContent.startsWith('Permit QA '));
      if (!fixture) continue;
      if (![...section.options].slice(1).every(option => option.dataset.program === course.value && option.dataset.year === year.value)) return false;
      section.value = fixture.value; section.dispatchEvent(new Event('change', {bubbles:true}));
      return !document.getElementById('management-permit-generate').disabled;
    }
    return false;
  })()`), 'Course and year level did not filter the permit sections.');
  await navigate('permits_final');
  check(await evaluate('document.querySelector("#management-permit-preview").textContent.includes("QA-ELECTIVE") && [...document.querySelectorAll(".management-permit-sheet")].map(sheet=>sheet.querySelectorAll("article").length).join() === "4,1" && [...document.querySelectorAll(".management-permit-card h3")].every(h=>h.textContent==="FINAL EXAMINATION PERMIT")'), 'Permits omitted an enrolled elective, duplicated students, or used the wrong term or pagination.');
  check(await evaluate(`(() => {
    const section = document.getElementById('permit-section').selectedOptions[0].textContent.trim();
    const program = document.getElementById('permit-course').selectedOptions[0].textContent.trim().split(' - ')[0];
    const cards = [...document.querySelectorAll('.management-permit-card')];
    const name = cards[0].querySelector('.management-permit-name');
    const [lastName, givenNames] = name.children;
    if (lastName.textContent !== 'DE LA CRUZ' || givenNames.textContent !== 'JUAN MIGUEL SANTOS') return false;
    const originalSize = parseFloat(getComputedStyle(lastName).fontSize);
    if (Math.abs(originalSize - 40 / 3) > 0.1 || parseFloat(getComputedStyle(givenNames).fontSize) >= originalSize || lastName.getBoundingClientRect().bottom > givenNames.getBoundingClientRect().top) return false;
    const tables = cards.map(card => card.querySelector('.management-permit-subjects').getBoundingClientRect());
    const tableOffset = tables[0].top - cards[0].getBoundingClientRect().top;
    return cards.every((card, index) => {
      const fields = card.querySelectorAll('.management-permit-student dd');
      const table = card.querySelector('.management-permit-subjects');
      const offset = tables[index].top - card.getBoundingClientRect().top;
      return fields[0].children.length === 2 && fields[1].textContent === section && fields[2].textContent === program
        && table.tBodies[0].rows.length === 10 && [...table.rows].every(row => row.cells.length === 2)
        && Math.abs(offset - tableOffset) <= 1 && Math.abs(tables[index].height - tables[0].height) <= 1;
    });
  })()`), 'Permit names, section and program labels, or subject table sizes are inconsistent.');
  for (const width of [1440, 768, 390, 320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Permit filters or preview overflow ${width}px.`); }
  await evaluate('window.printCalls=0;window.print=()=>window.printCalls++;document.getElementById("management-permit-print").click()');
  check(await evaluate('printCalls===1'), 'Print permits did not invoke the print dialog.');
  const pdfFolder = path.join(root,'tmp','pdfs');
  fs.mkdirSync(pdfFolder,{recursive:true});
  const permitPrint = await call('Page.printToPDF',{printBackground:true,preferCSSPageSize:true,displayHeaderFooter:false},sessionId);
  const permitPdf = Buffer.from(permitPrint.data,'base64');
  fs.writeFileSync(path.join(pdfFolder,'management-student-permits-qa.pdf'),permitPdf);
  const pdfStructure = permitPdf.toString('latin1');
  check((pdfStructure.match(/\/Type\s*\/Page\b/g) || []).length===2 && /\/MediaBox\s*\[0 0 612 936\]/.test(pdfStructure), 'The print PDF did not fit five permits on two 8.5 × 13-inch sheets.');
  await evaluate('document.getElementById("permit-exam-term").value="MIDTERM";document.getElementById("permit-exam-term").dispatchEvent(new Event("change",{bubbles:true}))');
  check(await evaluate('document.getElementById("management-permit-print").disabled&&document.getElementById("management-permit-preview").hidden&&!document.getElementById("management-permit-stale").hidden'), 'Changing the term left the old permit preview printable.');
  await navigate('permits_midterm');
  check(await evaluate('document.querySelector(".management-permit-card h3").textContent==="MIDTERM EXAMINATION PERMIT"&&JSON.parse(document.getElementById("management-config").textContent).current_exam_term==="FINAL"'), 'Printing Midterm changed the saved Final period.');
  await navigate('permits_wrong_year');
  check(await evaluate('!document.getElementById("management-permit-preview")&&document.getElementById("management-permit-form").querySelector("[role=alert]").textContent.includes("Choose a course")'), 'A mismatched year still produced permits for a section.');
  await resize(1440); await navigate('dashboard');
  await evaluate('document.getElementById("management-term-settings").click()');await until('document.getElementById("management-term-modal").classList.contains("show")');await delay(300);
  check(await evaluate('document.getElementById("management-term").value===JSON.parse(document.getElementById("management-config").textContent).term'),'Header term settings did not show the selected viewing term.');
  check(await evaluate('JSON.stringify([...document.getElementById("management-view-exam-term").options].map(o=>o.value))===JSON.stringify(["MIDTERM","FINAL"])'),'Management must offer exactly Midterm and Final terms.');
  await evaluate('document.getElementById("management-view-year").selectedIndex=0;document.getElementById("management-view-year").dispatchEvent(new Event("change"));document.getElementById("management-view-exam-term").value="FINAL";document.querySelector("#management-term-modal [data-bs-dismiss]").click()');await delay(300);
  await evaluate('document.getElementById("management-term-settings").click()');await until('document.getElementById("management-term-modal").classList.contains("show")');await delay(300);
  check(await evaluate('document.getElementById("management-term").value===JSON.parse(document.getElementById("management-config").textContent).term'),'Canceling term settings retained an unapplied selection.');
  check(await evaluate('document.getElementById("management-view-exam-term").value===JSON.parse(document.getElementById("management-config").textContent).exam_term'),'Canceling period settings retained the unapplied exam term.');
  await evaluate('document.getElementById("management-current-term").click()');
  check(await evaluate('document.getElementById("management-term").value===document.getElementById("management-current-term").dataset.term'),'Use current term did not select the current academic term.');
  check(await evaluate('document.getElementById("management-view-exam-term").value===document.getElementById("management-current-term").dataset.examTerm'),'Use current period did not restore Midterm / Final.');
  for (const width of [1440,390,320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Period selector overflows ${width}px.`); }
  await screenshot('management-period-selector-320.png');
  await evaluate('document.querySelector("#management-term-modal [data-bs-dismiss]").click()');await delay(300);
  await navigate('view_final');
  check(await evaluate('document.querySelector(".dean-view-label").textContent.includes("Final") && [...document.querySelectorAll(".management-nav a")].every(a=>new URL(a.href).searchParams.get("exam_term")==="FINAL")'),'Final period did not persist in the header and Management navigation.');
  await navigate('dashboard');
  await call('Fetch.enable',{patterns:[{urlPattern:'*query_management.php*'}]},sessionId);
  await evaluate('document.getElementById("management-term-settings").click()'); await until('document.getElementById("management-term-modal").classList.contains("show")'); await delay(300);
  await evaluate('document.getElementById("management-current-period-settings").open=true;document.getElementById("management-current-exam-term").value="FINAL"');
  check(await evaluate('JSON.stringify([...document.getElementById("management-current-exam-term").options].map(o=>o.value))===JSON.stringify(["MIDTERM","FINAL"])'),'Current term must be set inside Management using its two terms.');
  const termRequestsBefore=catalogRequests.length;
  await evaluate('document.getElementById("management-current-period-form").requestSubmit()');
  await until('!document.getElementById("management-current-period-error").hidden && !document.getElementById("management-current-period-save").disabled');
  const termRequest=catalogRequests.slice(termRequestsBefore).find(row=>row.action==='save_management_term');
  check(termRequest && termRequest.exam_term==='FINAL' && termRequest.current_period_version==='0' && termRequest.csrf_token.length===64,'Management term save lost its scope, revision, or CSRF token.');
  for(const width of [1440,390,320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Management current-term settings overflow ${width}px.`); }
  await screenshot('management-current-term-320.png');
  await evaluate('document.querySelector("#management-term-modal [data-bs-dismiss]").click()'); await delay(300);
  for (const width of [1440, 1024, 768, 390, 320]) {
    await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Dashboard overflows ${width}px.`);
    check(await evaluate(`(()=>{const nav=document.getElementById('management-mobile-nav'),menu=document.getElementById('layout-menu');return ${width}<1200?getComputedStyle(menu).display==='none'&&menu.inert&&getComputedStyle(nav).display==='grid'&&!nav.inert&&[...nav.children].every(e=>e.getBoundingClientRect().height>=44&&e.getBoundingClientRect().width>=44)&&Math.abs(nav.getBoundingClientRect().bottom-innerHeight)<1&&parseFloat(getComputedStyle(document.querySelector('.content-wrapper')).paddingBottom)>=nav.getBoundingClientRect().height:getComputedStyle(nav).display==='none'&&nav.inert&&!menu.inert})()`),`Navigation visibility, touch targets, or footer clearance failed at ${width}px. ${JSON.stringify(await evaluate('(()=>{const nav=document.getElementById("management-mobile-nav"),menu=document.getElementById("layout-menu");return {menuDisplay:getComputedStyle(menu).display,menuInert:menu.inert,navDisplay:getComputedStyle(nav).display,navInert:nav.inert,navBottom:nav.getBoundingClientRect().bottom,innerHeight,navHeight:nav.getBoundingClientRect().height,padding:getComputedStyle(document.querySelector(".content-wrapper")).paddingBottom,children:[...nav.children].map(e=>({width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height})),bodyClass:document.body.className}})()'))}`);
  }
  await tap('#management-more-toggle');await until('document.getElementById("management-mobile-more").classList.contains("show")');await delay(350);
  check(await evaluate('document.getElementById("management-more-toggle").getAttribute("aria-expanded")==="true" && document.querySelector(\'#management-mobile-more nav a[href^="flag-raising.php"]\') && document.getElementById("management-mobile-more").getBoundingClientRect().bottom<=innerHeight+1'), 'More options did not show the ceremony link within the phone viewport.');
  await screenshot('management-mobile-more-320.png');
  await call('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }, sessionId);
  await until('document.getElementById("management-more-toggle").getAttribute("aria-expanded")==="false"');
  check(await evaluate('document.activeElement===document.getElementById("management-more-toggle") && !document.querySelector(".offcanvas-backdrop")'), 'Escape did not dismiss More options and restore focus.');
  await resize(390); await screenshot('management-dashboard-390.png');
  await resize(1440); await navigate('students');
  check(await evaluate('!document.querySelector(".management-heading") && document.querySelector("#main-content > section").classList.contains("management-student-stats") && document.querySelectorAll(".management-student-stats article").length===3'),'Student summary cards did not replace the page title card below the header.');
  check(await evaluate('document.querySelector(".management-sidebar-college strong").textContent.trim().length>0 && document.querySelector("#layout-menu").lastElementChild.classList.contains("management-sidebar-college")'),'The assigned college is missing from the bottom of the sidebar.');
  check(await evaluate('Math.abs(document.getElementById("student-search").getBoundingClientRect().bottom-document.getElementById("student-page-size").getBoundingClientRect().bottom)<2 && document.getElementById("student-search").getBoundingClientRect().width>document.getElementById("student-page-size").getBoundingClientRect().width*2'),'Student search is not wider and aligned with its page-size selector.');
  check(await evaluate('document.getElementById("directory-filters-title").textContent.trim()==="DIRECTORY|Student listing" && !document.getElementById("directory-title") && !document.querySelector(".management-student-listing .management-panel-heading p") && [...document.querySelectorAll(".management-student-table th")].map(th=>th.textContent.trim()).join("|")==="Photo|Student Name|Course / Section|Total subjects|Remaining balance|Faculty evaluation|Actions"'),'Student heading or directory columns are incorrect.');
  check(await evaluate(`(() => {
    const table = document.querySelector('.management-student-table');
    const breaks = [...table.querySelectorAll('thead th')].map(th => th.querySelectorAll('br').length);
    const columns = {course:'left', subjects:'center', balance:'center', evaluation:'center'};
    return breaks.join(',') === '0,1,1,1,1,1,0' && Object.entries(columns).every(([column, align]) =>
      [...table.querySelectorAll('.management-student-' + column)].every(cell => getComputedStyle(cell).textAlign === align));
  })()`), 'Student column headers did not split across two lines or align with their cells.');
  await screenshot('management-students-1440.png');
  check(await evaluate('getComputedStyle(document.querySelector(".management-table")).fontSize==="14px" && getComputedStyle(document.querySelector(".management-table th")).fontSize==="12px" && getComputedStyle(document.querySelector(".management-table th")).backgroundColor==="rgb(242, 242, 255)" && getComputedStyle(document.querySelector(".dean-student-id")).color==="rgb(105, 108, 255)"'), 'Student list typography, colors, or header background differ from the Dean template.');
  check(await evaluate('document.querySelector(".management-record-title").firstElementChild.classList.contains("dean-student-id") && document.querySelector(".dean-student-contact em")'), 'Student ID/name/email order does not follow the Dean format.');
  check(await evaluate('document.querySelectorAll("tbody tr").length===25 && document.querySelector(".management-pagination").textContent.includes("28 students")'), 'Imported-student pagination/deduplication failed.');
  check(await evaluate('document.querySelector(".management-student-course strong").textContent.trim() && document.querySelectorAll(".management-student-subject-count")[1].textContent.trim()==="1"'),'The directory is missing course or subject information.');
  check(await evaluate('(()=>{const photo=document.querySelector(".management-student-avatar"),[finance,exempt]=document.querySelector(".management-student-actions").children;return photo.getBoundingClientRect().width===80 && getComputedStyle(photo).borderRadius==="50%" && photo.loading==="eager" && document.querySelectorAll(".management-student-avatar")[3].loading==="lazy" && exempt.textContent.trim()==="Exemptions" && finance.querySelector(".bx") && exempt.querySelector(".bx") && finance.offsetWidth===exempt.offsetWidth && finance.getBoundingClientRect().bottom<exempt.getBoundingClientRect().top})()'),'Student photos or stacked actions are styled incorrectly.');
  for (const width of [1440, 1280, 1200, 1024, 768, 390, 320]) { await resize(width); check(await evaluate('(()=>{const region=document.querySelector(".management-student-table").parentElement;return document.documentElement.scrollWidth<=innerWidth && region.scrollWidth<=region.clientWidth+1})()'), `Student page overflows ${width}px.`); }
  check(await evaluate(`(() => {
    const balance = document.querySelector('.management-student-balance-value');
    const evaluation = document.querySelector('.management-student-evaluation-value');
    const label = evaluation.parentElement.querySelector('.management-student-field-label');
    return getComputedStyle(label).display === 'block' && evaluation.textContent === 'Submitted' &&
      Math.abs(balance.getBoundingClientRect().bottom - evaluation.getBoundingClientRect().bottom) < 1;
  })()`), 'The mobile student card is missing the evaluation status or has misaligned badges.');
  await screenshot('management-students-320.png');
  await navigate('students_page'); check(await evaluate('document.querySelectorAll("tbody tr").length===3 && document.querySelector(".management-pagination").textContent.includes("Page 2 of 2")'), 'Student second page is incorrect.');
  await evaluate('window.studentFilterSubmissions=[];window.captureStudentFilter=event=>{event.preventDefault();studentFilterSubmissions.push(Object.fromEntries(new FormData(event.target)))};document.getElementById("management-student-filters").addEventListener("submit",captureStudentFilter);document.getElementById("student-search").value="new name";document.getElementById("student-search").dispatchEvent(new Event("input"))');
  await until('studentFilterSubmissions.length===1');
  await evaluate('document.getElementById("student-page-size").value="50";document.getElementById("student-page-size").dispatchEvent(new Event("change"))');
  check(await evaluate('studentFilterSubmissions.length===2 && studentFilterSubmissions[0].search==="new name" && studentFilterSubmissions[1].page_size==="50" && studentFilterSubmissions.every(row=>row.term===JSON.parse(document.getElementById("management-config").textContent).term && row.exam_term===JSON.parse(document.getElementById("management-config").textContent).exam_term && !("page" in row))'),'Typing or page-size changes did not apply student filters with the header period and reset pagination.');
  await evaluate('document.getElementById("management-student-filters").removeEventListener("submit",captureStudentFilter);document.getElementById("management-student-filters").reset()');
  await resize(1440); await navigate('financial');
  check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Financial") && document.getElementById("management-payment-register") && document.body.textContent.includes("No receipts recorded for this period")'),
    'Financial did not render the empty receipt register.');
  await navigate('financial_register');
  check(await evaluate(`(() => {
    const rows = [...document.querySelectorAll('.management-register-table tbody tr')];
    return document.querySelector('[data-register-today-collected]').textContent==='₱175.50' &&
      document.querySelector('[data-register-today-receipts]').textContent==='2' &&
      rows.length===25 && rows[0].textContent.includes('Next Day Payer') &&
      rows.every(row => row.querySelector('a[target="_blank"][href^="payment-receipt.php?payment_id="]')) &&
      document.getElementById('management-register-find-student').href.includes('exam_term=FINAL');
  })()`), 'Financial did not show current daily totals, chronologically sorted receipts, or the current student link.');
  await screenshot('management-financial-register-1440.png');
  for (const width of [1440,1200,1024,768,390,320]) {
    await resize(width);
    const layout = await evaluate(`(() => {
      const table = document.querySelector('.management-register-table');
      const region = table.parentElement;
      const search = document.getElementById('management-register-search').getBoundingClientRect();
      return {
        documentFits: document.documentElement.scrollWidth<=innerWidth,
        tableFits: region.scrollWidth<=region.clientWidth+1,
        searchFits: search.left>=0 && search.right<=innerWidth,
        rowDisplay: getComputedStyle(table.querySelector('tbody tr')).display,
        actionHeight: table.querySelector('tbody a').getBoundingClientRect().height
      };
    })()`);
    check(layout.documentFits && layout.tableFits && layout.searchFits &&
      (width>=768 || (layout.rowDisplay==='grid' && layout.actionHeight>=44)),
      `Receipt register overflows or has a small receipt action at ${width}px. ${JSON.stringify(layout)}`);
  }
  await screenshot('management-financial-register-320.png');
  check(await evaluate(`(() => {
    const form = document.getElementById('management-register-filters');
    const day = document.querySelector('#management-register-today-title time').dateTime;
    form.elements.search.value = 'payer';
    form.elements.date_from.value = day;
    form.elements.date_to.value = day;
    form.elements.cashier_id.selectedIndex = 1;
    let filters;
    form.addEventListener('submit',event => {
      event.preventDefault();
      filters = Object.fromEntries(new FormData(form));
    },{once:true});
    form.requestSubmit();
    return filters.search==='payer' && filters.date_from===day && filters.date_to===day &&
      Boolean(filters.cashier_id) && filters.exam_term==='FINAL' && !('page' in filters);
  })()`), 'Applying payment filters lost the date, cashier, or selected period.');
  await navigate('financial_register',{search:'payer',page:'2'});
  check(await evaluate(`(() => {
    const previous = document.querySelector('nav[aria-label="Receipt pages"] a');
    const clear = document.querySelector('.management-register-filters a');
    const params = new URL(previous.href).searchParams;
    return document.querySelectorAll('.management-register-table tbody tr').length===3 &&
      document.querySelector('[data-register-total]').textContent==='₱345.25' && params.get('search')==='payer' &&
      params.get('page')==='1' && params.get('exam_term')==='FINAL' && !new URL(clear.href).searchParams.has('search');
  })()`), 'Receipt pagination or Clear filters lost context or changed the matching total.');
  await resize(1440);
  await navigate('events'); check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Events") && document.getElementById("management-add") && document.getElementById("management-record-form")'), 'Events management page is missing.');
  await navigate('contributions'); check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Contributions")'), 'Contributions foundation page is missing.');
  check(await evaluate('!document.getElementById("catalog-scope") && !document.querySelector("#management-catalog-filters button, #management-catalog-filters a") && [...document.querySelectorAll(".management-catalog-table th")].map(th=>th.textContent.trim()).join("|")==="Contribution|Event date|Amount|Status|Actions"'),'Contributions retained removed controls or lost its Event date column.');
  await evaluate('window.catalogFilterSubmissions=[];window.captureCatalogFilter=event=>{event.preventDefault();catalogFilterSubmissions.push(Object.fromEntries(new FormData(event.target)))};document.getElementById("management-catalog-filters").addEventListener("submit",captureCatalogFilter);document.getElementById("catalog-status").value="ACTIVE";document.getElementById("catalog-status").dispatchEvent(new Event("change"));document.getElementById("catalog-page-size").value="50";document.getElementById("catalog-page-size").dispatchEvent(new Event("change"));document.getElementById("catalog-search").value="contribution";document.getElementById("catalog-search").dispatchEvent(new Event("input"))');
  await until('catalogFilterSubmissions.length===3');
  check(await evaluate('catalogFilterSubmissions[0].status==="ACTIVE" && catalogFilterSubmissions[1].page_size==="50" && catalogFilterSubmissions[2].search==="contribution" && catalogFilterSubmissions.every(row=>row.term===JSON.parse(document.getElementById("management-config").textContent).term && row.exam_term===JSON.parse(document.getElementById("management-config").textContent).exam_term && !("page" in row) && !("catalog_scope" in row))'),'Automatic contribution filters lost their values, selected period, or first-page reset.');
  await evaluate('document.getElementById("management-catalog-filters").removeEventListener("submit",captureCatalogFilter);document.getElementById("management-catalog-filters").reset()');
  await call('Fetch.enable',{ patterns:[{ urlPattern:'*query_management.php*' }] },sessionId);
  catalogTerm = await evaluate('JSON.parse(document.getElementById("management-config").textContent).term');
  for (const width of [1440,768,390,320]) { await resize(width); check(await evaluate('document.documentElement.scrollWidth<=innerWidth'), `Contribution list overflows ${width}px.`); }
  await checkMobileRecords('Contribution');await screenshot('management-contributions-320.png');
  check(await evaluate('document.getElementById("management-more-toggle").classList.contains("is-current") && document.querySelector("#management-mobile-more [aria-current=page]").textContent.includes("Contributions")'),'More options lost the active Contributions page.');
  await resize(1440); await screenshot('management-contributions-1440.png');
  await evaluate('document.getElementById("management-add").click()'); await until('document.getElementById("management-editor").classList.contains("show")'); await delay(350);
  check(await evaluate('document.getElementById("management-editor-title").textContent==="Add Contribution" && document.getElementById("management-record-form").elements.record_term.value===JSON.parse(document.getElementById("management-config").textContent).current_term && !document.getElementById("record-term")'), 'Add contribution did not retain its automatic term without a visible term field.');
  check(await evaluate('!document.getElementById("management-new-organization") && !document.getElementById("record-organization") && !document.getElementById("record-audience") && document.getElementById("management-record-form").elements.organization_id.type==="hidden" && document.getElementById("management-record-form").elements.audience_mode.type==="hidden"'),'Contribution editor retained removed controls or lost its automatic defaults.');
  check(await evaluate('JSON.stringify([...document.getElementById("record-status").options].map(o=>o.value))===JSON.stringify(["ACTIVE","INACTIVE"]) && document.getElementById("record-status").value==="ACTIVE" && JSON.stringify([...document.getElementById("catalog-status").options].map(o=>o.value))===JSON.stringify(["","ACTIVE","INACTIVE"])'),'Contribution editor/filter must offer only Active and Inactive and default new records to Active.');
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
  check(await evaluate('!document.querySelector(".management-heading") && !document.getElementById("catalog-scope") && !document.querySelector("#management-catalog-filters button, #management-catalog-filters a") && [...document.querySelectorAll(".management-catalog-table th")].map(th=>th.textContent.trim()).join("|")==="Event|Attendance count|Attendance status|Event status|Actions"'),'Events did not show the new attendance columns.');
  check(await evaluate('(()=>{const date=document.querySelector(".management-event-date"),title=document.querySelector(".management-record-title");return date.textContent===date.textContent.toLowerCase()&&getComputedStyle(date).fontStyle==="italic"&&!title.textContent.includes("ALL STUDENTS")&&getComputedStyle(document.querySelector("[data-event-present]")).color==="rgb(40, 121, 76)"&&getComputedStyle(document.querySelector("[data-event-remaining]")).color==="rgb(180, 69, 69)"})()'),'Event dates or present/remaining colors are incorrect.');
  await evaluate('window.eventFilterSubmissions=[];window.captureEventFilter=event=>{event.preventDefault();eventFilterSubmissions.push(Object.fromEntries(new FormData(event.target)))};document.getElementById("management-catalog-filters").addEventListener("submit",captureEventFilter);document.getElementById("catalog-status").value="INACTIVE";document.getElementById("catalog-status").dispatchEvent(new Event("change"));document.getElementById("catalog-page-size").value="50";document.getElementById("catalog-page-size").dispatchEvent(new Event("change"));document.getElementById("catalog-search").value="event";document.getElementById("catalog-search").dispatchEvent(new Event("input"))');
  await until('eventFilterSubmissions.length===3');
  check(await evaluate('eventFilterSubmissions[0].status==="INACTIVE" && eventFilterSubmissions[1].page_size==="50" && eventFilterSubmissions[2].search==="event" && eventFilterSubmissions.every(row=>row.term===JSON.parse(document.getElementById("management-config").textContent).term && row.exam_term===JSON.parse(document.getElementById("management-config").textContent).exam_term && !("catalog_scope" in row) && !("page" in row))'),'Automatic event filters lost their selected Management period or filter values.');
  await evaluate('document.getElementById("management-catalog-filters").removeEventListener("submit",captureEventFilter);document.getElementById("management-catalog-filters").reset()');
  for(const width of [1440,768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Event list overflows ${width}px.`);}
  await checkMobileRecords('Event');await screenshot('management-events-320.png');
  check(await evaluate('document.querySelector(".management-mobile-nav [aria-current=page]").textContent.trim()==="Events"'),'Mobile Events tab is not active.');
  await resize(1440); await screenshot('management-events-1440.png');
  await evaluate('(0,eval)('+JSON.stringify(fs.readFileSync(path.join(root,'assets/vendor/libs/management-qr/qrcode.js'),'utf8'))+')');
  await evaluate('(()=>{const code=qrcode(0,"M");code.addData("SYNK-ATTENDANCE:"+"a7".repeat(32));code.make();const canvas=document.createElement("canvas"),scale=6,n=code.getModuleCount();canvas.width=canvas.height=(n+8)*scale;const ctx=canvas.getContext("2d");ctx.fillStyle="white";ctx.fillRect(0,0,canvas.width,canvas.height);ctx.fillStyle="black";for(let y=0;y<n;y++)for(let x=0;x<n;x++)if(code.isDark(y,x))ctx.fillRect((x+4)*scale,(y+4)*scale,scale,scale);window.attendanceTestCanvas=canvas;Object.defineProperty(navigator.mediaDevices,"enumerateDevices",{configurable:true,value:async()=>[{kind:"videoinput",deviceId:"front",label:"Front camera"},{kind:"videoinput",deviceId:"back",label:"Rear camera"}]});Object.defineProperty(navigator.mediaDevices,"getUserMedia",{configurable:true,value:async()=>{window.attendanceTestStreams=window.attendanceTestStreams||[];window.attendanceTestStream=canvas.captureStream(5);attendanceTestStreams.push(attendanceTestStream);window.attendanceTestFrames=setInterval(()=>{ctx.fillStyle="white";ctx.fillRect(0,0,1,1)},100);return attendanceTestStream}})})()');
  await evaluate('document.querySelector("[data-attendance]:not([data-attendance-mode])").click()');await until('document.getElementById("attendance-result").textContent.includes("Attendance recorded")');
  check(catalogRequests.some(row=>row.action==='attendance_scan'&&row.qr_code==='SYNK-ATTENDANCE:'+'a7'.repeat(32)),'Camera did not decode and submit the student QR.');
  check(catalogRequests.filter(row=>row.action==='attendance_scan').length===2,'The camera did not retry the same QR after a failed save.');
  check(await evaluate('document.getElementById("attendance-recorded-at").textContent.includes("2026-10-27 09:30:00") && document.getElementById("attendance-student-photo").getAttribute("src")==="../student/profile-placeholder.svg" && document.getElementById("attendance-student-name").textContent==="JUAN DELA CRUZ" && document.getElementById("attendance-camera-status").textContent.includes("Ready to scan") && document.getElementById("attendance-camera-status").getClientRects().length>0'),'Saved attendance did not show photo, name, time and live camera status.');
  await until('!document.getElementById("attendance-camera-picker").hidden && document.getElementById("attendance-camera-device").options.length===2');
  await evaluate('window.oldAttendanceCamera=attendanceTestStream;document.getElementById("attendance-camera-device").value="back";document.getElementById("attendance-camera-device").dispatchEvent(new Event("change"))');
  await until('attendanceTestStream!==oldAttendanceCamera && document.getElementById("attendance-camera-status").textContent.includes("Ready to scan")');
  check(await evaluate('oldAttendanceCamera.getTracks().every(track=>track.readyState==="ended")'),'Switching camera left the old camera running.');
  await until('document.getElementById("attendance-result-status").textContent==="Already recorded"');
  check(await evaluate('document.getElementById("attendance-present").textContent==="1" && document.getElementById("attendance-absent").textContent==="Pending" && document.getElementById("attendance-result").textContent.includes("JUAN DELA CRUZ")'),'Attendance result/counts are incorrect before closing.');
  check(await evaluate('document.querySelector("[data-event-present]").textContent==="1" && document.querySelector("[data-event-remaining]").textContent==="1" && document.querySelector("[data-event-attendance-label]").textContent==="Open"'),'Scanning did not update the event table counts/status.');
  for(const width of [1440,390,320]){await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Attendance reader overflows ${width}px.`);}
  check(await evaluate('getComputedStyle(document.getElementById("management-mobile-nav")).visibility==="hidden" && document.getElementById("management-mobile-nav").inert'),'Bottom navigation interferes with QR attendance.');
  check(await evaluate('document.getElementById("attendance-result").getBoundingClientRect().top<document.querySelector(".management-attendance-camera").getBoundingClientRect().top'),'The phone reader hides the scanned student details below the camera controls.');
  for(const [width,height] of [[320,568],[390,844],[844,390]]){
    await resize(width,height);
    check(await evaluate('(()=>{const dialog=document.querySelector("#management-attendance .modal-content").getBoundingClientRect(),done=document.querySelector("#management-attendance .modal-footer button").getBoundingClientRect();return Math.abs(dialog.top)<2&&Math.abs(dialog.bottom-innerHeight)<2&&Math.abs(dialog.left)<2&&Math.abs(dialog.right-innerWidth)<2&&done.top>=0&&done.bottom<=innerHeight&&done.height>=44&&document.documentElement.scrollWidth<=innerWidth})()'),`QR attendance or its Done button does not fit ${width}x${height}. ${JSON.stringify(await evaluate('(()=>{const modal=document.getElementById("management-attendance"),content=modal.querySelector(".modal-content"),dialog=modal.querySelector(".modal-dialog");return {content:content.getBoundingClientRect().toJSON(),dialog:dialog.getBoundingClientRect().toJSON(),modal:modal.getBoundingClientRect().toJSON(),done:modal.querySelector(".modal-footer button").getBoundingClientRect().toJSON(),modalPadding:getComputedStyle(modal).padding,inline:modal.getAttribute("style"),innerWidth,innerHeight,viewportHeight:visualViewport.height,viewportWidth:visualViewport.width,scroll:document.documentElement.scrollWidth}})()'))}`);
    check(await evaluate('attendanceTestStream.getTracks().some(track=>track.readyState==="live")'),'Rotating the phone interrupted the active camera.');
    check(await evaluate('(()=>{const camera=document.querySelector(".management-attendance-camera").getBoundingClientRect(),video=document.getElementById("attendance-video").getBoundingClientRect();return video.width>0&&video.height>0&&video.top>=camera.top-1&&video.left>=camera.left-1&&video.bottom<=camera.bottom+1&&video.right<=camera.right+1&&getComputedStyle(document.getElementById("attendance-video")).objectFit==="contain"})()'),`The camera preview is cropped at ${width}x${height}.`);
  }
  await resize(390,844);
  await evaluate('document.querySelector("#management-attendance .modal-body").scrollTop=200');
  check(await evaluate('(()=>{const body=document.querySelector("#management-attendance .modal-body").getBoundingClientRect(),result=document.getElementById("attendance-result").getBoundingClientRect();return result.top>=body.top-2&&result.bottom<body.bottom})()'),'The latest scanned student scrolls out of view while using the phone reader.');
  await evaluate('document.querySelector("#management-attendance .modal-body").scrollTop=0');
  await screenshot('management-attendance-reader-320.png');
  await evaluate('document.getElementById("attendance-camera-stop").click();document.getElementById("attendance-hardware").open=true;document.getElementById("attendance-code").value="SYNK-ATTENDANCE:"+"a7".repeat(32)');await delay(2600);
  const beforeHardware=catalogRequests.filter(row=>row.action==='attendance_scan').length;
  await evaluate('document.getElementById("attendance-code-form").requestSubmit()');
  for(let i=0;i<80&&catalogRequests.filter(row=>row.action==='attendance_scan').length===beforeHardware;i++)await delay(50);
  await until('document.getElementById("attendance-result-status").textContent==="Already recorded" && document.getElementById("attendance-code").value===""');
  check(catalogRequests.filter(row=>row.action==='attendance_scan').length===beforeHardware+1,'A connected scanner did not submit the student QR.');
  fs.writeFileSync(qrFixture,Buffer.from(await evaluate('attendanceTestCanvas.toDataURL("image/png").split(",")[1]'),'base64'));await delay(2600);
  const documentNode=(await call('DOM.getDocument',{},sessionId)).root.nodeId,uploadNode=(await call('DOM.querySelector',{nodeId:documentNode,selector:'#attendance-upload'},sessionId)).nodeId;
  const beforeImage=catalogRequests.filter(row=>row.action==='attendance_scan').length;
  await call('DOM.setFileInputFiles',{nodeId:uploadNode,files:[qrFixture]},sessionId);
  for(let i=0;i<80&&catalogRequests.filter(row=>row.action==='attendance_scan').length===beforeImage;i++)await delay(50);
  check(catalogRequests.filter(row=>row.action==='attendance_scan').length===beforeImage+1,'Reading a saved QR image did not submit attendance.');
  await until('!document.getElementById("attendance-result").hidden');
  await evaluate('document.querySelector(".management-attendance-close summary").click();document.getElementById("attendance-confirm").checked=true;document.getElementById("attendance-confirm").dispatchEvent(new Event("change"));document.getElementById("attendance-state-change").click()');await until('document.getElementById("attendance-status").textContent==="Closed"');
  check(await evaluate('attendanceTestStream.getTracks().every(track=>track.readyState==="ended") && document.getElementById("attendance-absent").textContent==="1"'),'Closing attendance did not stop the camera or finalize absences.');
  check(await evaluate('document.querySelector("[data-event-attendance-label]").textContent==="Closed" && document.querySelector("[data-event-attendance-status]").dataset.eventAttendanceStatus==="CLOSED"'),'Closing attendance did not update the table status.');
  check(await evaluate('document.querySelector("[data-event-attendance-action-label]").textContent==="View attendance"'),'A closed event still invites users to take attendance.');
  check(await evaluate('(()=>{const button=document.querySelector("[data-event-attendance-status]"),status=document.createElement("span");status.className="management-event-attendance-state";status.dataset.eventAttendanceStatus="CLOSED";button.after(status);const red=getComputedStyle(status).color==="rgb(180, 69, 69)";status.dataset.eventAttendanceStatus="OPEN";const green=getComputedStyle(status).color==="rgb(40, 121, 76)";status.remove();return red&&green})()'),'View-only attendance status lost its open/closed colors.');
  check(await evaluate('document.getElementById("attendance-code").disabled && document.getElementById("attendance-code-submit").disabled && document.getElementById("attendance-camera-device").disabled && document.getElementById("attendance-camera-status").getClientRects().length===0'),'Closed attendance still exposes the scanner or readiness message.');
  await evaluate('document.getElementById("attendance-confirm").checked=true;document.getElementById("attendance-confirm").dispatchEvent(new Event("change"));document.getElementById("attendance-state-change").click()');await until('document.getElementById("attendance-status").textContent==="Open"');
  await evaluate('document.querySelector("#management-attendance [data-bs-dismiss]").click()');await delay(350);
  await evaluate('window.attendanceStreamsBeforeManage=attendanceTestStreams.length;document.querySelector("[data-attendance-mode=manage]").click()');
  await until('document.querySelector("#management-attendance.show") && document.getElementById("attendance-status").textContent==="Open"');await delay(400);
  check(await evaluate('document.querySelector(".management-attendance-close").open'),'Attendance status management did not expose its open/close controls.');
  check(await evaluate('attendanceTestStreams.length===attendanceStreamsBeforeManage && document.getElementById("attendance-video").srcObject===null && document.querySelector("[data-event-attendance-action-label]").textContent==="Take attendance"'),'Managing attendance status started the camera or failed to restore the open action.');
  await evaluate('Object.defineProperty(navigator.mediaDevices,"getUserMedia",{configurable:true,value:()=>new Promise(resolve=>window.resolveAttendanceCamera=resolve)});document.getElementById("attendance-camera-start").click()');
  await until('typeof resolveAttendanceCamera==="function"');
  await evaluate('document.getElementById("attendance-camera-stop").click();window.lateAttendanceStream=attendanceTestCanvas.captureStream(5);resolveAttendanceCamera(lateAttendanceStream)');
  await until('lateAttendanceStream.getTracks().every(track=>track.readyState==="ended")');
  check(await evaluate('document.getElementById("attendance-video").hidden && document.getElementById("attendance-video").srcObject===null'),'A permission response restarted the camera after Stop.');
  await evaluate('window.resolveAttendanceCamera=null;document.getElementById("attendance-camera-start").click()');await until('typeof resolveAttendanceCamera==="function"');
  await evaluate('document.querySelector("#management-attendance [data-bs-dismiss]").click();window.hiddenAttendanceStream=attendanceTestCanvas.captureStream(5);resolveAttendanceCamera(hiddenAttendanceStream)');
  await until('hiddenAttendanceStream.getTracks().every(track=>track.readyState==="ended")');
  check(await evaluate('document.getElementById("attendance-video").srcObject===null'),'A late permission response kept the camera running after dismissal.');
  await delay(300);await resize(1440);
  await evaluate('document.getElementById("management-add").click()'); await until('document.getElementById("management-editor").classList.contains("show")'); await delay(350);
  await evaluate('document.getElementById("record-title").value="New College Event";document.getElementById("record-absence").value="25.50"');
  check(await evaluate('!document.getElementById("record-organization") && !document.getElementById("record-term") && !document.getElementById("record-audience") && !document.getElementById("management-location") && !document.getElementById("record-radius") && !document.getElementById("record-latitude") && !document.getElementById("record-longitude") && !document.getElementById("management-session-add") && !document.getElementById("management-session-list") && document.getElementById("record-status").value==="ACTIVE" && JSON.stringify([...document.getElementById("record-status").options].map(o=>o.value))===JSON.stringify(["ACTIVE","INACTIVE"])'),'Simplified event form retained removed controls or old statuses.');
  check(await evaluate('(()=>{const tops=["record-date","record-status","record-absence"].map(id=>document.getElementById(id).getBoundingClientRect().top);return Math.max(...tops)-Math.min(...tops)<2})()'),'Event date, status, and absence fine are not aligned on desktop.');
  for(const width of [768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Event editor overflows ${width}px.`);}
  await screenshot('management-event-editor-320.png');
  beforeRequests=catalogRequests.filter(row=>row.action==='save').length;
  await evaluate('document.getElementById("management-record-form").requestSubmit()');
  for(let i=0;i<100&&catalogRequests.filter(row=>row.action==='save').length===beforeRequests;i++)await delay(50);
  await until('!document.querySelector(".modal.show") && document.readyState==="complete"'); await delay(150);
  const eventSave=catalogRequests.filter(row=>row.action==='save').at(-1);
  check(eventSave.kind==='events'&&eventSave.status==='ACTIVE'&&eventSave.current_exam_term==='MIDTERM'&&eventSave.absence_fine==='25.50'&&!('sessions' in eventSave)&&!('latitude' in eventSave)&&!('longitude' in eventSave)&&!('attendance_radius_m' in eventSave),'Event form lost its absence fine/current period or submitted removed settings.');
  await resize(1440);await evaluate('document.querySelector("[data-edit]").click()');await until('document.getElementById("management-record-form").elements.record_id.value && !document.getElementById("management-editor-fields").disabled');await delay(300);
  check(await evaluate('document.getElementById("record-absence").value==="25.25" && document.getElementById("management-record-form").elements.version.value.length===64'),'Editing lost the saved absence fine or record version.');
  await evaluate('document.querySelector("#management-editor [data-bs-dismiss]").click()');await delay(350);linkedContributions=1;
  await evaluate('document.querySelector("[data-delete]").click()');await until('document.getElementById("management-delete-explanation").textContent.includes("1 linked contribution")');await delay(300);
  check(await evaluate('document.getElementById("management-delete-confirm").disabled && !document.getElementById("management-delete-event-confirmation").disabled'),'Linked records were not disclosed or an event could be deleted without confirmation.');
  const deletes=catalogRequests.filter(row=>row.action==='delete').length;
  await evaluate('document.getElementById("management-delete-event-confirmation").click();document.getElementById("management-delete-confirm").click()');
  for(let i=0;i<100 && catalogRequests.filter(row=>row.action==='delete').length===deletes;i++)await delay(50);
  check(catalogRequests.some(row=>row.action==='delete' && row.kind==='events' && row.confirm==='1' && row.version.length===64),'Event deletion lost its confirmation or record version.');
  await until('document.readyState==="complete" && !document.querySelector(".modal.show")');
  eventDeleteBlock='This event or a linked contribution has recorded payments.';
  await evaluate('document.querySelector("[data-delete]").click()');await until('document.getElementById("management-delete-explanation").textContent.includes("recorded payments")');await delay(300);
  check(await evaluate('document.getElementById("management-delete-confirm").disabled && document.getElementById("management-delete-event-confirmation").disabled'),'The paid event showed an enabled delete action.');
  await evaluate('document.querySelector("#management-delete-modal [data-bs-dismiss]").click()');await delay(350);linkedContributions=0;eventDeleteBlock='';
  await navigate('students');
  check(await evaluate('!document.getElementById("student-directory-term") && !document.getElementById("student-program") && !document.getElementById("student-section") && !document.getElementById("student-email-status") && !document.getElementById("student-sort") && document.querySelectorAll("#management-student-filters select").length===1 && document.querySelectorAll("#student-page-size option").length===3 && !document.querySelector("#management-student-filters button, #management-student-filters a")'),'Simplified student search retained removed filters or actions.');
  check(await evaluate('document.querySelector("[data-student]").textContent.trim()==="Financial overview"'),'Student action does not explain the financial overview.');
  const detailRequests = () => catalogRequests.filter(row => row.action==='student_detail').length;
  const overviewRequestsBefore = detailRequests();
  evaluationProgress={status:'PENDING',faculty_count:2,submitted_count:0,label:'Not submitted'};
  await resize(320,640);
  await evaluate('document.querySelector("[data-student]").click()');
  await until('document.activeElement.id==="management-evaluation-reminder-back"');
  check(detailRequests()===overviewRequestsBefore+1 && await evaluate(`(() => {
    const reminder = document.getElementById('management-evaluation-reminder');
    const panel = reminder.querySelector('.modal-content').getBoundingClientRect();
    return !document.querySelector('#management-student-detail.show, .management-financial-layout') &&
      reminder.textContent.includes('0 of 2 submitted') && reminder.textContent.includes('DELA CRUZ, JUAN MIGUEL') &&
      document.querySelector('[data-evaluation-status]').dataset.evaluationStatus === 'PENDING' &&
      panel.left >= 0 && panel.right <= innerWidth && panel.top >= 0 && panel.bottom <= innerHeight;
  })()`), 'A draft did not show a fresh, readable reminder before opening the financial overview.');
  await evaluate('document.getElementById("management-evaluation-reminder-back").click()');
  await until('document.activeElement===document.querySelector("[data-student]") && !document.querySelector(".modal.show")');
  check(detailRequests()===overviewRequestsBefore+1,'Going back opened the overview or requested the account again.');
  evaluationProgress={status:'PARTIAL',faculty_count:2,submitted_count:1,label:'1 of 2 submitted'};
  await resize(1440);
  await evaluate('document.querySelector("[data-student]").click()');
  await until('document.activeElement.id==="management-evaluation-reminder-back"');
  check(await evaluate('document.getElementById("management-evaluation-reminder-count").textContent==="1 of 2 submitted" && !document.querySelector("#management-student-detail.show")'),
    'Partial submissions did not require acknowledgment before opening the overview.');
  await evaluate('document.getElementById("management-evaluation-reminder-open").click()');
  await until('document.querySelector("#management-student-detail.show .management-financial-layout") && !document.querySelector("#management-evaluation-reminder.show")');
  check(detailRequests()===overviewRequestsBefore+2,'Opening after acknowledgment fetched the student overview twice.');
  await delay(300);
  await evaluate('document.querySelector("#management-student-detail .btn-close").click()');
  await until('document.activeElement===document.querySelector("[data-student]") && !document.querySelector(".modal.show")');
  evaluationProgress={status:'SUBMITTED',faculty_count:2,submitted_count:2,label:'Submitted'};
  await resize(1440);
  await evaluate(`new Promise(resolve => {
    document.getElementById('management-student-detail').addEventListener('shown.bs.modal', () => resolve(true), {once:true});
    document.querySelector('[data-student]').click();
  })`);
  await until('document.getElementById("management-student-detail-content").textContent.includes("CCS TEAM JERSEY")');
  check(detailRequests()===overviewRequestsBefore+3 && await evaluate('!!document.querySelector("#management-student-detail.show") && !document.querySelector("#management-evaluation-reminder.show") && document.querySelector("[data-evaluation-status]").dataset.evaluationStatus==="SUBMITTED"'),
    'Submitted evaluations still displayed a reminder or left the directory badge stale.');
  check(await evaluate('document.querySelector(".management-financial-sidebar").getBoundingClientRect().left<document.querySelector(".management-financial-main").getBoundingClientRect().left && document.querySelector("#management-student-detail .modal-dialog").getBoundingClientRect().width>1250 && document.querySelector(".management-financial-avatar").textContent.trim()==="JD" && document.querySelectorAll(".management-financial-stat").length===4'),'Financial overview lacks its wide layout, profile sidebar, avatar or summary cards.');
  check(await evaluate('document.getElementById("financial-contributions").textContent.includes("500.00") && document.getElementById("financial-events").textContent.includes("Attendance pending") && document.getElementById("financial-violations") && document.getElementById("financial-payments") && document.querySelector(".management-financial-stat-due > strong").textContent.includes("525.00") && document.querySelector(".management-financial-status").textContent.includes("Unpaid") && !document.getElementById("management-student-detail").textContent.includes("Not assessed")'),'Financial overview did not show automatic totals, unpaid status or pending attendance.');
  check(await evaluate('getComputedStyle(document.querySelector(".management-financial-present")).color==="rgb(40, 121, 76)" && getComputedStyle(document.querySelector(".management-financial-absent")).color==="rgb(180, 69, 69)" && getComputedStyle(document.querySelector("[data-financial-absence-count]")).color==="rgb(180, 69, 69)"'),'Financial attendance is not consistently green for Present and red for Absent.');
  check(await evaluate('!document.querySelector(".management-financial-draft,.management-financial-preview-note,.management-financial-course") && !document.querySelector("#management-student-detail").textContent.includes("Design preview") && !document.querySelector(".management-financial-sidebar").textContent.includes("STUDENT PROFILE") && !document.querySelector(".management-financial-sidebar").textContent.includes("COLLEGE OF COMPUTER STUDIES") && !document.querySelector(".management-financial-sidebar").textContent.includes("ISULAN CAMPUS") && document.querySelector(".management-financial-profile-fields").textContent.includes("BSCS 1A, Year 1")'),'Financial overview retained removed labels or did not combine section/year.');
  check(await evaluate('document.querySelector(".management-financial-pay").disabled && document.querySelector(".management-financial-pay").textContent.trim()==="Pay" && document.querySelector(".management-financial-pay").getBoundingClientRect().left>document.querySelector(".management-financial-status").getBoundingClientRect().right'),'Pay must follow the status; collection remains a separate workflow.');
  await evaluate('window.profileTop=document.querySelector(".management-financial-sidebar").getBoundingClientRect().top;document.querySelector(".management-financial-main").scrollTop=400');
  check(await evaluate('document.querySelector(".management-financial-main").scrollTop===400 && document.querySelector(".management-financial-sidebar").getBoundingClientRect().top===profileTop && document.querySelector(".management-financial-sidebar").scrollTop===0 && getComputedStyle(document.querySelector(".management-financial-sidebar")).overflowY==="auto" && getComputedStyle(document.getElementById("management-student-detail-content")).overflowY==="hidden"'),'Financial content scroll moved the profile sidebar or entire modal.');
  await evaluate('document.querySelector(".management-financial-main").scrollTop=0');
  await screenshot('management-student-financial-overview-1440.png');
  check(await evaluate('(()=>{const unsafe={student:{student_name:"<img src=x onerror=alert(1)>",student_number:"000123"},subjects:[],financial:{period:{text:"FINAL"},organization:{code:"<script>alert(1)</script>"},summary:{contribution_count:0,event_count:0,contribution_catalog_total:"0.00",absence_count:null},contributions:[],events:[]}};const box=document.createElement("div");box.innerHTML=SynkManagementFinancial.render(unsafe);return box.querySelectorAll("img,script").length===0 && box.textContent.includes("<img")})()'),'Financial profile contains unsafe HTML.');
  for(const width of [1440,768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Student detail overflows ${width}px.`);}
  check(await evaluate('getComputedStyle(document.getElementById("management-student-detail-content")).overflowY==="auto" && getComputedStyle(document.querySelector(".management-financial-main")).overflowY==="visible"'),'Phone financial overview traps scrolling in nested panels.');
  check(await evaluate('[...document.querySelectorAll(".management-financial-table-scroll")].every(region=>region.scrollWidth<=region.clientWidth+1) && [...document.querySelectorAll(".management-financial-table tbody td:not(:first-child)")].every(cell=>cell.dataset.label)'),'Phone financial records require sideways scrolling or lack field labels. '+JSON.stringify(await evaluate('[...document.querySelectorAll(".management-financial-table-scroll")].map(region=>({width:region.clientWidth,scroll:region.scrollWidth,table:region.querySelector("table").getBoundingClientRect().toJSON(),cells:[...region.querySelectorAll("tbody td")].map(cell=>({label:cell.dataset.label,text:cell.textContent,width:cell.clientWidth,scroll:cell.scrollWidth})),footer:region.querySelector("tfoot")?.getBoundingClientRect().toJSON()}))')));
  await screenshot('management-student-detail-320.png');
  await navigate('violations');await resize(1440);await screenshot('management-violations-1440.png');
  check(await evaluate('document.querySelector(".management-nav [aria-current=page]").textContent.includes("Violations") && document.querySelectorAll("#management-record-form [name]:not([type=hidden])").length===2'),'Violation menu/page must expose only type and amount fields.');
  for(const width of [1440,768,390,320]) {await resize(width);check(await evaluate('document.documentElement.scrollWidth<=innerWidth'),`Violation page overflows ${width}px.`);}
  await checkMobileRecords('Violation');await screenshot('management-violations-320.png');
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
  if (ws?.readyState === WebSocket.OPEN) {
    ws.send(JSON.stringify({ id:1000000, method:'Browser.close' }));
    await delay(500);
  }
  if (ws) ws.close(); if (browser) { browser.kill(); await delay(600); }
  if (fs.existsSync(route)) fs.unlinkSync(route);
  if (fs.existsSync(qrFixture)) fs.unlinkSync(qrFixture);
  const resolved = path.resolve(profile); const parent = path.resolve(root, '.tmp');
  if (!resolved.startsWith(parent + path.sep) || !/^management-browser-[a-f0-9]{16}$/.test(path.basename(resolved))) throw Error('Unexpected cleanup path.');
  if (fs.existsSync(resolved)) fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
});
