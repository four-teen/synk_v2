// node tests/subject-assignments-browser.cjs [--screenshot]
// Render production pages using rolled-back fixtures, then intercept data requests.
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const temp = path.join(root, '.tmp');
const nonce = crypto.randomBytes(8).toString('hex');
const token = crypto.randomBytes(24).toString('hex');
const quote = text => "'" + text.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
const apiPath = path.join(root, 'tmp', `subject-browser-api-${nonce}.php`);
const pagePath = path.join(root, 'tmp', `subject-browser-page-${nonce}.php`);
const profile = path.join(temp, `subject-browser-${nonce}`);
let browser, socket;
let checks = 0;
const assert = (condition, message) => { if (!condition) throw new Error(message); checks++; };
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

async function main() {
    const guard = `if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !hash_equals(${quote(token)}, (string)($_GET['qa'] ?? ''))) {http_response_code(404);exit;}`;
    fs.writeFileSync(apiPath, `<?php ${guard} define('SYNK_SUBJECT_ASSIGNMENTS_QA',true);require ${quote(path.join(root, 'tests', 'fixtures', 'subject-assignments-request.php'))};`);
    const fixture = async scenario => {
        const response = await fetch(`http://localhost/synk/tmp/${path.basename(apiPath)}?qa=${token}&scenario=${scenario}`, {method:'POST'});
        const data = await response.json();
        if (data.fixture_error || !data.unchanged) throw new Error(data.fixture_error || 'Fixture changed data.');
        return data;
    };
    const rendered = await fixture('page');
    const list = await fixture('list');
    const detail = await fixture('detail');
    const options = await fixture('options');
    const pagination = await fixture('pagination');
    const workload = await fixture('workload_page');
    const college = rendered.known.college_id;
    const program = list.known.program;
    const term = list.known.term;
    const mainRow = list.response.rows.find(row => row.subject_id === list.known.subject);
    const supplied = {
        options:{status:'ok', college_id:college, options:{...options.response.options, current_term:term,
            programs:[{program_id:program, label:mainRow.program}]}},
        list:{...list.response, college_id:college}, detail:{...detail.response, college_id:college},
        pagination:{...pagination.response, college_id:college}
    };
    supplied.detail.detail.subject_id = mainRow.subject_id;
    supplied.detail.detail.program_id = mainRow.program_id;
    const html = rendered.response.replace('<head>', '<head><base href="/synk/scheduler/">');
    fs.writeFileSync(pagePath, `<?php ${guard} header('Content-Type: text/html; charset=utf-8');echo base64_decode(${quote(Buffer.from(html).toString('base64'))});`);
    const executable = [process.env['ProgramFiles(x86)']+'\\Microsoft\\Edge\\Application\\msedge.exe', process.env.ProgramFiles+'\\Google\\Chrome\\Application\\chrome.exe'].find(file => fs.existsSync(file));
    if (!executable) throw new Error('Chromium browser is required.');
    browser = spawn(executable, ['--headless=new','--disable-gpu','--remote-debugging-port=0',`--user-data-dir=${profile}`,'about:blank'], {windowsHide:true, stdio:['ignore','ignore','pipe']});
    const wsUrl = await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('Browser startup timed out.')), 15000);
        let logs = '';
        browser.stderr.on('data', data => { logs += data; const match=logs.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) {clearTimeout(timeout);resolve(match[1]);} });
        browser.on('error', reject);
    });
    socket = new WebSocket(wsUrl);
    await new Promise((resolve, reject) => {socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
    let sequence = 0; const pending = new Map();
    socket.addEventListener('message', event => {
        const message = JSON.parse(event.data);
        if (!pending.has(message.id)) return;
        const entry=pending.get(message.id); pending.delete(message.id); clearTimeout(entry.timeout);
        message.error ? entry.reject(new Error(message.error.message)) : entry.resolve(message.result);
    });
    const call = (method, params={}, sessionId) => new Promise((resolve,reject) => {
        const id=++sequence; const timeout=setTimeout(() => {pending.delete(id);reject(new Error(method+' timed out.'));},15000);
        pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params,...(sessionId ? {sessionId} : {})}));
    });
    const target=await call('Target.createTarget',{url:'about:blank'});
    const attached=await call('Target.attachToTarget',{targetId:target.targetId,flatten:true}); const session=attached.sessionId;
    await call('Page.enable',{},session); await call('Runtime.enable',{},session);
    const evaluate=async expression => {
        const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},session);
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const until=async (expression,message) => {for (let i=0;i<100;i++){if(await evaluate(expression))return;await delay(100);}throw new Error(message + ': ' + JSON.stringify(await evaluate('({ready:document.readyState,errors:window.__saErrors,notice:document.getElementById("saNotice")?.textContent,rows:document.getElementById("saRows")?.textContent,requests:window.__saRequests})')));};
    const injection = `
        window.__saData=${JSON.stringify(supplied)};window.__saRequests=[];window.__saMode='normal';window.__saErrors=[];
        window.addEventListener('error',event=>window.__saErrors.push(event.message));
        window.addEventListener('unhandledrejection',event=>window.__saErrors.push(String(event.reason)));
        window.fetch=async function(url,options={}){
            const query=new URL(url,location.href).searchParams;const params=Object.fromEntries(query);window.__saRequests.push(params);
            let body=JSON.parse(JSON.stringify(window.__saData[params.action==='options'?'options':params.action==='detail'?'detail':window.__saMode==='pagination'?'pagination':'list']));
            let code=200;let timeout=5;
            if(window.__saMode==='failure' && params.action==='list'){body={status:'error',message:'Read failed <script>danger</script>'};code=500;}
            if(params.action==='list'){
                if(window.__saMode==='pagination'){
                    body.total=28;body.pages=2;body.page=Number(params.page || 1);body.rows=body.page===1?body.rows:body.rows.slice(0,3);
                }else if(code===200){
                    const search=String(params.search||'').toLowerCase();
                    body.rows=body.rows.filter(row=>[row.subject_code,row.description,...row.faculty_names].join(' ').toLowerCase().includes(search));
                    if(params.assignment==='assigned')body.rows=body.rows.filter(row=>row.faculty_count>0);
                    if(params.assignment==='unassigned')body.rows=body.rows.filter(row=>row.faculty_count===0);
                    body.total=body.rows.length;body.page=1;body.pages=1;
                    if(window.__saMode==='race')timeout=params.search==='Different'?500:5;
                }
            }
            await new Promise(resolve=>setTimeout(resolve,timeout));
            return new Response(JSON.stringify(body),{status:code,headers:{'Content-Type':'application/json'}});
        };
    `;
    await call('Page.addScriptToEvaluateOnNewDocument',{source:injection},session);
    await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
    await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(pagePath)}?qa=${token}`},session);
    await until('document.readyState==="complete" && document.querySelectorAll(".sa-view").length===3','Subject list did not load.');
    assert(await evaluate(`document.querySelector('#saTerm').value===${JSON.stringify(term)}`),'Current term was not selected.');
    assert(await evaluate('document.querySelector("a[href=\\"subject-assignments.php\\"]").closest(".menu-item").classList.contains("active")'),'Subject Assignments was not active in the sidebar.');
    assert(await evaluate('document.querySelector("a[href=\\"subject-assignments.php\\"]").closest(".menu-sub").parentElement.textContent.includes("Monitoring")'),'Subject Assignments was outside Monitoring.');
    assert(await evaluate('document.getElementById("saRows").textContent.includes("Peña, José Niño") && document.getElementById("saRows").textContent.includes("Muñoz, Ana")'),'Unicode names were corrupted in the browser.');
    assert(await evaluate('!document.querySelector("#saRows img") && document.getElementById("saRows").textContent.includes("<img src=x onerror=alert(1)>")'),'Subject descriptions were interpreted as HTML.');
    await evaluate(`document.querySelector('.sa-view[data-subject="${mainRow.subject_id}"]').click()`);
    await until('document.querySelectorAll("#saDetailContent .sa-faculty-card").length===2 && document.activeElement?.closest("#saDetailModal")','Faculty detail modal did not open.');
    assert(await evaluate('document.querySelectorAll("#saDetailContent .sa-meetings tbody tr").length===6'),'Modal lost lecture/lab meeting assignments.');
    assert(await evaluate('document.getElementById("saDetailContent").textContent.includes("Room TBA") && document.getElementById("saDetailContent").textContent.includes("Faculty Need")'),'TBA or staffing details were missing.');
    assert(await evaluate('!document.querySelector("#saDetailTitle img") && document.querySelector("#saDetailContent a").getAttribute("href").startsWith("manage-workload.php?faculty_id=")'),'Faculty links or safe descriptions failed.');
    await evaluate('document.querySelector("#saDetailModal .btn-close").click()');
    await until('!document.getElementById("saDetailModal").classList.contains("show")','Detail modal did not close.');
    await evaluate('document.getElementById("saSearch").value="Peña";document.getElementById("saSearch").dispatchEvent(new Event("input",{bubbles:true}))');
    await until('document.querySelectorAll(".sa-view").length===1 && document.getElementById("saResultCount").textContent.includes("of 1")','Faculty search did not filter subjects.');
    assert(await evaluate('window.__saRequests.at(-1).search==="Peña"'),'Unicode search was not sent correctly.');
    await evaluate('document.getElementById("saReset").click()');
    await until('document.querySelectorAll(".sa-view").length===3','Reset did not restore subjects.');
    await evaluate('document.getElementById("saAssignment").value="unassigned";document.getElementById("saAssignment").dispatchEvent(new Event("change"))');
    await until('document.querySelectorAll(".sa-view").length===2','Unassigned filter failed.');
    assert(await evaluate('window.__saRequests.at(-1).assignment==="unassigned"'),'Assignment filter was not sent.');
    await evaluate('window.__saMode="pagination";document.getElementById("saReset").click()');
    await until('document.querySelectorAll(".sa-view").length===25','First subject page was incomplete.');
    await evaluate('document.getElementById("saNext").click()');
    await until('document.querySelectorAll(".sa-view").length===3 && document.getElementById("saPage").textContent==="Page 2 of 2"','Subject pagination failed.');
    assert(await evaluate('document.getElementById("saNext").disabled && !document.getElementById("saPrevious").disabled'),'Pagination bounds were wrong.');
    await evaluate('window.__saMode="race";document.getElementById("saReset").click()');
    await until('document.querySelectorAll(".sa-view").length===3','Reset after pagination failed.');
    await evaluate('document.getElementById("saSearch").value="Different";document.getElementById("saFilters").dispatchEvent(new Event("submit",{cancelable:true,bubbles:true}));document.getElementById("saSearch").value="Peña";document.getElementById("saFilters").dispatchEvent(new Event("submit",{cancelable:true,bubbles:true}))');
    await delay(650);
    assert(await evaluate('document.querySelectorAll(".sa-view").length===1 && document.getElementById("saRows").textContent.includes("Peña")'),'A stale search response replaced the latest results.');
    await evaluate('window.__saMode="failure";document.getElementById("saRefresh").click()');
    await until('!document.getElementById("saNotice").classList.contains("d-none")','A failed read was hidden.');
    assert(await evaluate('!document.querySelector("#saNotice script") && document.getElementById("saTotal").textContent==="—"'),'Failure message was unsafe or totals were stale.');
    await evaluate('window.__saMode="normal";document.getElementById("saReset").click()');
    await until('document.querySelectorAll(".sa-view").length===3','The list did not recover after a failed read.');
    await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false},session);
    assert(await evaluate('document.documentElement.scrollWidth<=window.innerWidth+2'),'The page overflowed at mobile width.');
    await evaluate(`document.querySelector('.sa-view[data-subject="${mainRow.subject_id}"]').click()`);
    await until('document.querySelectorAll("#saDetailContent .sa-faculty-card").length===2 && document.activeElement?.closest("#saDetailModal")','Mobile detail did not open.');
    assert(await evaluate('document.querySelector("#saDetailModal .modal-content").getBoundingClientRect().width<=window.innerWidth && document.querySelector("#saDetailModal .modal-body").scrollWidth<=document.querySelector("#saDetailModal .modal-body").clientWidth+2'),'Faculty detail overflowed the mobile modal.');
    assert((await evaluate('window.__saErrors')).length===0,'Browser scripts raised errors.');
    if (process.argv.includes('--screenshot')) {
        await call('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false},session);
        await evaluate('document.querySelector("#saDetailModal .btn-close").click()'); await delay(400);
        const screenshot=await call('Page.captureScreenshot',{format:'png'},session);
        const file=path.join(temp, `subject-assignments-${nonce}.png`); fs.writeFileSync(file,Buffer.from(screenshot.data,'base64')); console.log('Screenshot: '+file);
    }
    // Exercise the real workload page's existing change handlers after the new
    // link selects an allowed faculty. All AJAX calls are intercepted before init.
    const workloadHtml=workload.response.replace('<head>', '<head><base href="/synk/scheduler/">');
    fs.writeFileSync(pagePath,`<?php ${guard} header('Content-Type: text/html; charset=utf-8');echo base64_decode(${quote(Buffer.from(workloadHtml).toString('base64'))});`);
    const workloadInjection=`
        window.__workloadRequests=[];window.__workloadErrors=[];
        window.addEventListener('error',event=>window.__workloadErrors.push(event.message));
        let localJQuery;
        Object.defineProperty(window,'jQuery',{configurable:true,get(){return localJQuery;},set(value){
            localJQuery=value;
            value.ajax=function(options){
                const deferred=value.Deferred();window.__workloadRequests.push({url:options.url,data:options.data});
                const url=String(options.url||'');
                const response=url.includes('query_load_faculty_workload')||url.includes('query_class_schedule_loader')?[]:{status:'ok',rows:[],faculties:[],needs:[],overview:[],entries:[],programs:[]};
                setTimeout(()=>{if(typeof options.success==='function')options.success(response);deferred.resolve(response);},5);
                return deferred.promise({abort(){},readyState:4});
            };
        }});
    `;
    await call('Page.addScriptToEvaluateOnNewDocument',{source:workloadInjection},session);
    const linkedQuery=new URLSearchParams({qa:token, faculty_id:String(workload.known.faculty[0]), ay_id:workload.known.term.split(':')[0], semester:workload.known.term.split(':')[1],college_id:String(workload.known.college_id)});
    await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(pagePath)}?${linkedQuery}`},session);
    await until(`document.readyState==='complete' && document.getElementById('faculty_id')?.value==='${workload.known.faculty[0]}'`,'Workload link did not select its faculty.');
    assert(await evaluate(`window.__workloadRequests.some(request=>String(request.url).includes('query_load_faculty_workload') && Number(request.data?.faculty_id)===${workload.known.faculty[0]})`),'The selected faculty workload was not requested through existing handlers.');
    assert(!(await evaluate('window.__workloadRequests')).some(request=>/query_apply_workload|query_remove|query_clear/.test(String(request.url))),'A viewing link attempted a workload write.');
    assert((await evaluate('window.__workloadErrors')).length===0,'Workload link initialization raised an error.');
    linkedQuery.set('college_id','2147483647');
    await call('Page.navigate',{url:`http://localhost/synk/tmp/${path.basename(pagePath)}?${linkedQuery}`},session);
    await until(`document.readyState==='complete' && !!document.getElementById('faculty_id')`,'Workload page did not load with a foreign link.');
    assert(await evaluate(`document.getElementById('faculty_id').value!=='${workload.known.faculty[0]}'`),'A foreign-college link selected a faculty.');
    console.log(`Subject Assignments browser: ${checks} checks passed at 1440 px and 390 px. All data requests were intercepted.`);
    await call('Browser.close');
}
main().catch(error => {console.error(error.stack);process.exitCode=1;}).finally(async () => {
    if (socket) socket.close(); if (browser) {browser.kill();await delay(800);}
    for (const file of [apiPath,pagePath]) if (fs.existsSync(file)) fs.unlinkSync(file);
    const target=path.resolve(profile);
    if (!target.startsWith(path.resolve(temp)+path.sep) || !/^subject-browser-[0-9a-f]{16}$/.test(path.basename(target))) throw new Error('Unexpected browser profile cleanup target.');
    if (fs.existsSync(target)) {
        if (fs.lstatSync(target).isSymbolicLink()) throw new Error('Browser profile is a reparse point.');
        fs.rmSync(target,{recursive:true,force:true,maxRetries:5,retryDelay:200});
    }
});
