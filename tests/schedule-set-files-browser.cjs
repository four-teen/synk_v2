// Headless browser tests. All file-set requests are intercepted; fixtures roll back.
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const temp = path.join(root, '.tmp');
const nonce = crypto.randomBytes(8).toString('hex');
const token = crypto.randomBytes(24).toString('hex');
const quote = value => "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const apiPath = path.join(root, 'tmp', `file-set-api-${nonce}.php`);
const pagePath = path.join(root, 'tmp', `file-set-page-${nonce}.php`);
const profile = path.join(temp, `file-set-browser-${nonce}`);
let browser, ws, checks = 0;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => { if (!ok) throw new Error(message); checks++; };
async function main() {
    const guard = `if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !hash_equals(${quote(token)}, (string)($_GET['qa'] ?? ''))) { http_response_code(404);exit; }`;
    fs.writeFileSync(apiPath, `<?php ${guard} define('SYNK_SCHEDULER_TBA_QA',true); require ${quote(path.join(root, 'tests', 'fixtures', 'scheduler-tba-request.php'))};`);
    const fixture = await (await fetch(`http://localhost/synk/tmp/${path.basename(apiPath)}?qa=${token}&scenario=page`, { method: 'POST' })).json();
    check(typeof fixture.response === 'string' && fixture.response.includes('btnExportScheduleFile'), 'Updated scheduling page did not render.');
    fs.writeFileSync(pagePath, `<?php ${guard} header('Content-Type: text/html; charset=utf-8'); echo base64_decode(${quote(Buffer.from(fixture.response).toString('base64'))});`);
    const executable = [process.env['ProgramFiles(x86)'] + '\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles + '\\Google\\Chrome\\Application\\chrome.exe'].find(file => fs.existsSync(file));
    if (!executable) throw new Error('A Chromium browser is required.');
    browser = spawn(executable, ['--headless=new', '--disable-gpu', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    const socket = await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('Browser startup timed out.')), 15000);
        let output = '';
        browser.stderr.on('data', chunk => { output += chunk; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
        browser.on('error', reject);
    });
    ws = new WebSocket(socket);
    await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, { once: true }); ws.addEventListener('error', reject, { once: true }); });
    let sequence = 0;
    const pending = new Map();
    ws.addEventListener('message', event => {
        const message = JSON.parse(event.data), entry = pending.get(message.id);
        if (entry) { pending.delete(message.id); clearTimeout(entry.timeout); message.error ? entry.reject(new Error(message.error.message)) : entry.resolve(message.result); }
    });
    const call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(method + ' timed out.')); }, 15000);
        pending.set(id, { resolve, reject, timeout });
        ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    });
    const target = await call('Target.createTarget', { url: 'about:blank' });
    const attached = await call('Target.attachToTarget', { targetId: target.targetId, flatten: true });
    const session = attached.sessionId;
    await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, session);
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const until = async expression => { for (let attempt = 0; attempt < 100; attempt++) { if (await evaluate(expression)) return; await delay(100); } throw new Error('Timed out: ' + expression); };
    await call('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false }, session);
    await call('Page.navigate', { url: `http://localhost/synk/tmp/${path.basename(pagePath)}?qa=${token}` }, session);
    await until('window.jQuery && window.SynkScheduleSetFiles && document.readyState === "complete"');
    await evaluate(`
        window.__setRequests=[]; window.__setDialogs=[]; window.__confirmImport=true; window.__setError=false; window.__setBrowserErrors=[];
        addEventListener('error',e=>window.__setBrowserErrors.push(e.message));
        window.__setSummary={set_name:'Set A <img src=x onerror=alert(1)>',college:'QA College',term:'2026-2027 / 1st Semester',program_count:3,offering_count:12,meeting_count:20,workload_count:18,changed_meeting_count:20,changed_class_workload_count:2};
        window.__actualSwal=Swal.fire.bind(Swal);
        Swal.fire=async options=>{window.__setDialogs.push(options); if(options.title==='Export schedule set')return window.__actualSwal(options);return {isConfirmed:options.title==='Import this set?'?window.__confirmImport:true};};
        window.fetch=async (url,options)=>{
            const entries=Object.fromEntries(options.body.entries());
            const action=['export_schedule_file','preview_schedule_file','apply_schedule_file'].find(key=>entries[key]);
            window.__setRequests.push({...entries,action});
            if(window.__setError)return {ok:false,json:async()=>({status:'error',message:'Schedules changed since preview. Nothing was changed.'})};
            return {ok:true,json:async()=>action==='export_schedule_file'?{status:'ok',csv:'example,csv',filename:entries.set_name+'.csv',set_name:entries.set_name,program_count:3,meeting_count:20,workload_count:18,college:'QA College',term:'2026-2027 / 1st Semester'}:{status:'ok',summary:window.__setSummary,preview_token:'a'.repeat(64),message:'Schedule and workload restored together.'}};
        };
        URL.createObjectURL=blob=>{window.__setDownload=blob;return 'blob:qa-set';}; URL.revokeObjectURL=()=>{};
        HTMLAnchorElement.prototype.click=function(){window.__setDownloadName=this.download;};
        window.__selectSet=()=>{const transfer=new DataTransfer();transfer.items.add(new File(['csv'], 'Set-A.csv',{type:'text/csv'}));const input=document.getElementById('scheduleSetFileInput');input.files=transfer.files;input.dispatchEvent(new Event('change'));};
    `);
    check(await evaluate('!document.querySelector(".schedule-set-panel details") && !document.getElementById("scheduleFileSetName") && !document.getElementById("scheduleSetSelect") && !document.getElementById("scheduleSetManagerModal") && !document.getElementById("scheduleSetActiveState")'), 'Database set controls or the inline name input are still present.');
    check(!/load_schedule_sets|save_schedule_set|load_schedule_set_into_live|refreshScheduleSetLiveState/.test(fixture.response), 'The page still loads or saves database sets.');
    await evaluate('document.getElementById("btnExportScheduleFile").click()');
    await until('Swal.getInput() && document.activeElement===Swal.getInput()');
    check(await evaluate('Swal.getInput().value === document.querySelector("#ay_id option:checked").textContent.trim()+" - "+document.querySelector("#semester option:checked").textContent.trim()'), 'The naming default does not match the selected academic year and semester.');
    check(await evaluate('Swal.getInput().selectionStart===Swal.getInput().value.length && window.__setRequests.length===0'), 'The default name is not ready for appending, or export started before confirmation.');
    await evaluate('Swal.clickCancel()');
    await until('!document.getElementById("btnExportScheduleFile").disabled');
    check(await evaluate('window.__setRequests.length===0'), 'Cancelling the name dialog sent an export request.');
    await evaluate('document.getElementById("btnExportScheduleFile").click()');
    await until('Swal.getInput() && document.activeElement===Swal.getInput()');
    await evaluate('Swal.getInput().value="   ";Swal.clickConfirm()');
    await until('Swal.getValidationMessage()?.textContent.includes("Enter a name")');
    check(await evaluate('window.__setRequests.length===0'), 'A blank name triggered export.');
    await evaluate('window.__expectedSetName=window.__setDialogs.at(-1).inputValue+" - Set A";Swal.getInput().value=window.__expectedSetName+".csv";Swal.clickConfirm()');
    await until('window.__setDownloadName===window.__expectedSetName+".csv" && !document.getElementById("btnExportScheduleFile").disabled');
    check(await evaluate('window.__setRequests[0].set_name===window.__expectedSetName && !window.__setDownloadName.endsWith(".csv.csv")'), 'An appended name or CSV extension was handled incorrectly.');
    check(await evaluate('window.__setRequests[0].action==="export_schedule_file" && !window.__setRequests[0].program_id && window.__setRequests[0].csrf_token && window.__setRequests[0].scheduler_college_id && window.__setRequests[0].scheduler_term_key'), 'Export lost college scope or request protection.');
    check(await evaluate('document.getElementById("scheduleFileSetStatus").textContent.includes("Keep the file") && window.__setDownload instanceof Blob'), 'Export did not create a file or explain retention.');
    await evaluate('window.__originalSemester=document.getElementById("semester").value;document.getElementById("semester").value="2";document.getElementById("btnExportScheduleFile").click()');
    await until('Swal.getInput() && document.activeElement===Swal.getInput()');
    check(await evaluate('Swal.getInput().value.endsWith(" - Second Semester")'), 'The export default used the official term instead of the currently selected term.');
    await evaluate('Swal.clickCancel()');
    await until('!document.getElementById("btnExportScheduleFile").disabled');
    await evaluate('document.getElementById("semester").value=window.__originalSemester');
    await evaluate('window.__confirmImport=false;window.__selectSet()');
    await until('document.getElementById("scheduleFileSetStatus").textContent.includes("cancelled")');
    check(await evaluate('window.__setRequests.filter(r=>r.action==="apply_schedule_file").length===0'), 'Cancelling import sent an apply request.');
    check(await evaluate('window.__setDialogs.find(d=>d.title==="Import this set?").html.includes("&lt;img")'), 'Imported display text was not escaped.');
    await evaluate('window.__confirmImport=true;window.__selectSet()');
    await until('document.getElementById("scheduleFileSetStatus").textContent.startsWith("Imported")');
    check(await evaluate('window.__setRequests.at(-1).action==="apply_schedule_file" && window.__setRequests.at(-1).preview_token==="a".repeat(64) && window.__setRequests.at(-1).schedule_set_file instanceof File'), 'Import did not submit the previewed file and token.');
    await evaluate('window.__setError=true;window.__selectSet()');
    await until('window.__setDialogs.at(-1).title==="Set could not be processed"');
    check(await evaluate('!document.getElementById("btnImportScheduleFile").disabled && window.__setDialogs.at(-1).text.includes("Nothing was changed")'), 'A rejected import did not recover the controls.');
    await evaluate('window.__setError=false;document.getElementById("scheduleFileSetStatus").textContent="Export includes schedules and workload for this college term. Keep the downloaded file for later use.";document.querySelector(".schedule-set-panel").scrollIntoView({block:"start"})');
    await delay(400);
    check(await evaluate('document.querySelector(".schedule-set-panel").scrollWidth<=document.querySelector(".schedule-set-panel").clientWidth+2'), 'Desktop set controls overflow.');
    if (process.argv.includes('--screenshot')) {
        const shot = await call('Page.captureScreenshot', { format: 'png' }, session);
        fs.writeFileSync(path.join(temp, 'schedule-set-files-desktop.png'), Buffer.from(shot.data, 'base64'));
    }
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: false }, session);
    check(await evaluate('document.querySelector(".schedule-set-panel").scrollWidth<=document.querySelector(".schedule-set-panel").clientWidth+2'), 'Mobile set controls overflow.');
    await evaluate('document.getElementById("btnExportScheduleFile").click()');
    await until('Swal.getInput() && document.activeElement===Swal.getInput()');
    await delay(400);
    check(await evaluate('document.querySelector(".swal2-popup").getBoundingClientRect().width<=window.innerWidth && Swal.getInput().getBoundingClientRect().right<=window.innerWidth'), 'The naming dialog overflowed mobile width.');
    if (process.argv.includes('--screenshot')) {
        const shot = await call('Page.captureScreenshot', { format: 'png' }, session);
        fs.writeFileSync(path.join(temp, 'schedule-set-files-export-mobile.png'), Buffer.from(shot.data, 'base64'));
    }
    await evaluate('Swal.clickCancel()');
    await until('!document.getElementById("btnExportScheduleFile").disabled');
    await evaluate('window.__actualSwal({title:"Import this set?",html:window.__setDialogs.find(d=>d.title==="Import this set?").html,showCancelButton:true,confirmButtonText:"Import Schedule & Workload",allowOutsideClick:false});void 0');
    await until('document.querySelector(".swal2-popup")');
    await delay(400);
    check(await evaluate('document.querySelector(".swal2-popup").getBoundingClientRect().width<=window.innerWidth && !window.__setBrowserErrors.length'), 'Import confirmation overflowed or raised a browser error.');
    if (process.argv.includes('--screenshot')) {
        const shot = await call('Page.captureScreenshot', { format: 'png' }, session);
        fs.writeFileSync(path.join(temp, 'schedule-set-files-mobile.png'), Buffer.from(shot.data, 'base64'));
    }
    console.log(`Schedule set files browser: ${checks} checks passed at 1440px and 390px. All file-set writes intercepted.`);
    await call('Browser.close');
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
    if (ws) ws.close(); if (browser) { browser.kill(); await delay(800); }
    for (const file of [apiPath, pagePath]) if (fs.existsSync(file)) fs.unlinkSync(file);
    const resolved = path.resolve(profile);
    if (!resolved.startsWith(path.resolve(temp) + path.sep) || !/^file-set-browser-[0-9a-f]{16}$/.test(path.basename(resolved))) throw new Error('Unexpected browser profile cleanup target.');
    if (fs.existsSync(resolved)) fs.rmSync(resolved, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
});
