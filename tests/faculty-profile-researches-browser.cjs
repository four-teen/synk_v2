// Responsive browser QA of both portfolio tabs using rollback-only fixture HTML.
const fs = require('node:fs'), path = require('node:path'), http = require('node:http'), crypto = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..'), temp = path.join(root, '.tmp'), nonce = crypto.randomBytes(8).toString('hex');
const html = path.join(temp, `profile-research-${nonce}.html`), profile = path.join(temp, `profile-research-browser-${nonce}`);
let server, browser, ws, call, checks = 0;
const errors = [], delay = ms => new Promise(resolve => setTimeout(resolve, ms));
const check = (ok, message) => {if (!ok) throw Error(message); checks++;};
async function main() {
  fs.mkdirSync(temp, {recursive:true});
  const render = spawnSync(process.env.SYNK_QA_PHP || 'php', ['-d', 'memory_limit=512M', path.join(__dirname, 'faculty-profile-researches-http.php'), '--render', html], {cwd:root, encoding:'utf8', windowsHide:true});
  if (render.status !== 0 || !fs.existsSync(html)) throw Error(render.stdout + render.stderr);
  const markup = fs.readFileSync(html, 'utf8').replace(/<base\b[^>]*>/i, '');
  server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/synk/professor/manage-profile.php') {res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'}); res.end(markup); return;}
    if (url.pathname === '/synk/professor/education-options.php') {res.writeHead(200, {'Content-Type':'application/json'}); res.end('{"results":[],"pagination":{"more":false}}'); return;}
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
    let logs = ''; const timer = setTimeout(() => reject(Error('Browser start timeout.')), 15000);
    browser.stderr.on('data', chunk => {logs += chunk; const match = logs.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) {clearTimeout(timer); resolve(match[1]);}});
    browser.on('error', reject);
  });
  ws = new WebSocket(address);
  await new Promise((resolve, reject) => {ws.addEventListener('open', resolve, {once:true}); ws.addEventListener('error', reject, {once:true});});
  let sequence = 0; const pending = new Map();
  ws.addEventListener('message', event => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {const item = pending.get(message.id); pending.delete(message.id); clearTimeout(item.timer); message.error ? item.reject(Error(message.error.message)) : item.resolve(message.result);}
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (message.method === 'Page.javascriptDialogOpening') call('Page.handleJavaScriptDialog', {accept:true}, message.sessionId).catch(() => {});
  });
  call = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++sequence, timer = setTimeout(() => {pending.delete(id); reject(Error(method + ' timeout.'));}, 15000);
    pending.set(id, {resolve,reject,timer}); ws.send(JSON.stringify({id,method,params,...(sessionId ? {sessionId} : {})}));
  });
  const target = await call('Target.createTarget', {url:'about:blank'});
  const session = (await call('Target.attachToTarget', {targetId:target.targetId, flatten:true})).sessionId;
  await call('Page.enable', {}, session); await call('Runtime.enable', {}, session);
  const evaluate = async expression => {
    const result = await call('Runtime.evaluate', {expression,returnByValue:true,awaitPromise:true,userGesture:true}, session);
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  };
  const until = async expression => {for (let i=0; i<120; i++) {if (await evaluate('Boolean(' + expression + ')')) return; await delay(100);} throw Error('Condition timed out: ' + expression + '; ' + errors.join('; '));};
  const resize = async width => {await call('Emulation.setDeviceMetricsOverride', {width,height:1000,deviceScaleFactor:1,mobile:false}, session); await delay(100);};
  const screenshot = async name => fs.writeFileSync(path.join(temp, name), Buffer.from((await call('Page.captureScreenshot', {format:'png'}, session)).data, 'base64'));
  await resize(1440);
  await call('Page.navigate', {url:`http://127.0.0.1:${server.address().port}/synk/professor/manage-profile.php?tab=researches`}, session);
  await until('document.readyState === "complete" && document.querySelector("[data-researches]")');
  check(await evaluate('!document.getElementById("faculty-panel-researches").hidden && document.getElementById("faculty-panel-profile").hidden && document.getElementById("research-editor").hidden'), 'Research records open directly with the editor collapsed.');
  check(await evaluate('document.querySelectorAll("[data-research-id]").length === 1 && document.querySelector(".profile-research-record").textContent.includes("Oct 15, 2026") && document.querySelectorAll(".profile-research-links a").length === 2'), 'Saved research displays the conference date and separate publisher and Scopus links.');
  check(await evaluate('document.querySelectorAll("[data-researches] .profile-research-table thead tr").length === 1 && document.querySelectorAll("[data-researches] .profile-research-table thead th[scope=col]").length === 7 && [...document.querySelectorAll("[data-research-edit], [data-research-delete]")].every(action => action.closest("td").cellIndex === 6)'), 'Researches share one table header and all row controls are in the Actions column.');
  check(await evaluate('getComputedStyle(document.querySelector(".profile-research-title")).textTransform === "uppercase" && [...document.querySelectorAll("[data-researches] .profile-research-table thead th")].every(header => getComputedStyle(header).textTransform === "uppercase") && getComputedStyle(document.getElementById("research_research_title")).textTransform === "uppercase"'), 'Research titles and header labels display in uppercase.');
  check(await evaluate('(()=>{const cell=document.querySelector(".profile-research-conference"),name=cell.querySelector(".profile-research-conference-name"),date=cell.querySelector("time"),links=document.querySelector(".profile-research-links .profile-research-lines");return date.dateTime==="2026-10-15"&&name.getBoundingClientRect().height<=parseFloat(getComputedStyle(name).lineHeight)+1&&name.title===name.textContent&&date.getBoundingClientRect().top>=name.getBoundingClientRect().bottom&&date.getBoundingClientRect().top-name.getBoundingClientRect().bottom<=3&&parseFloat(getComputedStyle(links).rowGap)<=3;})()'), 'Conference date is the second line even for a long name, with compact spacing and the full name available on hover.');
  check(await evaluate('[...document.querySelectorAll(".profile-research-links a")].every(link => link.target === "_blank" && link.rel.includes("noopener"))'), 'Publication links open safely in a separate tab.');
  check(await evaluate('(()=>{const cell=document.querySelector(".profile-research-publisher"),indexing=cell.querySelector("[data-research-indexing]"),publisher=cell.querySelector(".profile-research-lines > span");return indexing.textContent==="Scopus"&&indexing.getBoundingClientRect().top>=publisher.getBoundingClientRect().bottom;})()'), 'Indexing appears on the second line beneath Publisher.');
  check(await evaluate('(()=>{const role=document.querySelector("[data-research-role]"),title=document.querySelector(".profile-research-title-text"),scope=document.querySelector(".profile-research-scope-funding"),details=document.querySelector(".profile-research-publication-details");return role.textContent==="Co-Author"&&role.closest("th")===title.closest("th")&&role.getBoundingClientRect().top>=title.getBoundingClientRect().bottom&&getComputedStyle(role).textTransform==="none"&&scope.textContent.includes("International")&&scope.textContent.includes("Funded")&&details.textContent.includes("ISSN: 1234-5679")&&details.textContent.includes("Volume: 12")&&details.textContent.includes("Issue: 3");})()'), 'Role is below the title, Scope and Funding share a cell, and ISSN, Volume and Issue share a labeled cell.');
  await screenshot('faculty-researches-desktop.png');
  await evaluate('document.querySelector("[data-research-edit]").click()');
  const editState = await evaluate('({hidden:document.getElementById("research-editor").hidden,title:document.getElementById("research-editor-title").textContent,fields:Object.fromEntries(new FormData(document.getElementById("faculty-research-form")))})');
  check(await evaluate('document.getElementById("research_author_role").value === "Co-Author" && document.getElementById("research_indexing").value === "Scopus" && document.getElementById("research_issn_number").value === "1234-5679" && document.getElementById("research_volume").value === "12" && document.getElementById("research_issue").value === "3"'), 'Editing restores Role, Indexing, ISSN, Volume and Issue.');
  check(await evaluate('!document.getElementById("research-editor").hidden && document.getElementById("research-editor-title").textContent === "Edit research record" && document.getElementById("research_research_title").value === "Community learning and sustainable agriculture" && document.getElementById("research_conference_date").value === "2026-10-15" && document.getElementById("research_research_scope").value === "International" && document.getElementById("research_funding_type").value === "Funded" && Number(document.querySelector("#faculty-research-form [name=research_id]").value) > 0'), 'Edit fills all research fields with their saved values and identity: ' + JSON.stringify(editState));
  await evaluate('document.getElementById("research_research_title").value = "Edited title kept across tabs"; document.getElementById("research_research_title").dispatchEvent(new Event("input",{bubbles:true})); document.querySelector("[data-profile-tab=profile]").click(); document.querySelector("[data-profile-tab=researches]").click()');
  check(await evaluate('document.getElementById("research_research_title").value === "Edited title kept across tabs" && document.getElementById("research-save-status").textContent === "Unsaved research changes"'), 'Tab switching retains an unsaved research draft.');
  await evaluate('window.confirm = () => false; document.querySelector("[data-research-cancel]").click()');
  check(await evaluate('!document.getElementById("research-editor").hidden && document.getElementById("research_research_title").value === "Edited title kept across tabs"'), 'Declining Cancel preserves the edited research.');
  await evaluate('window.confirm = () => true; document.querySelector("[data-research-cancel]").click(); document.querySelector("[data-research-add]").click()');
  check(await evaluate('document.getElementById("research-editor-title").textContent === "Add research record" && document.querySelector("#faculty-research-form [name=research_id]").value === "0" && document.getElementById("research_research_title").value === "" && document.getElementById("research_publisher_link").value === "" && document.activeElement.id === "research_research_title"'), 'Add opens a clean record and focuses the title.');
  check(await evaluate('!document.getElementById("faculty-research-form").checkValidity()'), 'A new record requires title, scope and funding.');
  await evaluate(`(() => {const values = {research_title:'New local research',research_scope:'Local',funding_type:'Personal',conference_name:'Faculty Research Forum',conference_date:'2027-01-15',publisher:'Sample Publisher',publisher_link:'https://publisher.example/article?id=12',scopus_link:'https://www.scopus.com/record/display.uri?eid=123'}; for(const [key,value] of Object.entries(values)){const field=document.getElementById('research_'+key);field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));}})()`);
  check(await evaluate(`(() => {const form=document.getElementById('faculty-research-form'),data=new FormData(form);return form.checkValidity()&&data.get('profile_action')==='research_save'&&data.get('research_title')==='New local research'&&data.get('research_scope')==='Local'&&data.get('funding_type')==='Personal'&&data.get('conference_date')==='2027-01-15'&&data.get('publisher_link').includes('publisher.example')&&data.get('scopus_link').includes('scopus.com')&&!data.has('full_name')&&!data.has('additional_degrees_present');})()`), 'Research submits its own complete record without personal or degree fields.');
  for (const width of [1440,768,390,320]) {
    await resize(width);
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth && [...document.querySelectorAll("[data-researches] .btn")].every(button => {const box=button.getBoundingClientRect();return box.height>=44&&(button.closest(".profile-research-table-scroll")||(box.left>=0&&box.right<=innerWidth));})'), 'Research form fits and the table scrolls within its own region at ' + width + 'px.');
    if (width === 1440 || width === 390) {
      await evaluate('document.getElementById("research-editor").scrollIntoView({block:"start",behavior:"instant"})');
      await screenshot('faculty-researches-editor-' + (width===1440?'desktop':'mobile') + '.png');
    }
  }
  await evaluate('document.querySelector("[data-research-cancel]").click(); document.querySelector("[data-researches]").scrollIntoView({block:"start",behavior:"instant"})');
  await screenshot('faculty-researches-mobile.png');
  await evaluate('const scroller=document.querySelector(".profile-research-table-scroll");scroller.scrollLeft=scroller.scrollWidth');
  check(await evaluate('(()=>{const region=document.querySelector(".profile-research-table-scroll").getBoundingClientRect();return [...document.querySelectorAll("[data-researches] .profile-research-actions .btn")].every(button=>{const box=button.getBoundingClientRect();return box.left>=region.left&&box.right<=region.right;});})()'), 'The Actions column is reachable by horizontally scrolling the table on mobile.');
  await screenshot('faculty-researches-mobile-actions.png');
  const deletionCancelled = await evaluate('window.confirm=()=>false; !document.querySelector("[data-research-delete]").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');
  check(deletionCancelled, 'Declining Remove cancels deletion.');
  await evaluate('document.querySelector("[data-research-add]").click(); document.getElementById("research_research_title").value="Minimal research"; document.getElementById("research_research_scope").value="Local"; document.getElementById("research_funding_type").value="Personal"; document.getElementById("faculty-research-form").addEventListener("submit",event=>{event.preventDefault();window.researchSubmitted=Object.fromEntries(new FormData(event.target));},{once:true}); document.getElementById("faculty-research-form").requestSubmit()');
  check(await evaluate('window.researchSubmitted.research_title === "Minimal research" && window.researchSubmitted.publisher_link === "" && document.getElementById("research-save-button").disabled'), 'An unpublished record can be submitted with optional details empty.');
  await resize(1440);
  await evaluate('window.confirm=()=>true; document.querySelector("[data-profile-tab=trainings]").click(); document.querySelector("[data-trainings]").scrollIntoView({block:"start",behavior:"instant"})');
  check(await evaluate('!document.getElementById("faculty-panel-trainings").hidden && document.getElementById("faculty-panel-researches").hidden && document.getElementById("training-editor").hidden && document.querySelectorAll("[data-training-id]").length === 1'), 'Training opens with saved records and the editor collapsed.');
  check(await evaluate('document.querySelectorAll("[data-trainings] thead th").length === 7 && document.querySelector("[data-training-id]").textContent.includes("Faculty Development Institute") && document.querySelectorAll("[data-training-id] time")[0].dateTime === "2026-10-14" && document.querySelectorAll("[data-training-id] time")[1].dateTime === "2026-10-15"'), 'The training table shows Agency, both dates, Venue, Mode and Actions.');
  await screenshot('faculty-trainings-desktop.png');
  await evaluate('document.querySelector("[data-training-edit]").click()');
  check(await evaluate('document.getElementById("training-editor-title").textContent === "Edit training record" && document.getElementById("training_training_title").value === "Inclusive teaching and learning" && document.getElementById("training_agency").value === "Faculty Development Institute" && document.getElementById("training_date_from").value === "2026-10-14" && document.getElementById("training_date_to").value === "2026-10-15" && document.getElementById("training_venue").value === "University Training Hall" && document.getElementById("training_mode").value === "Face to face"'), 'Editing training restores all six saved fields.');
  await evaluate('document.getElementById("training_training_title").value="Training draft"; document.getElementById("training_training_title").dispatchEvent(new Event("input",{bubbles:true})); document.querySelector("[data-profile-tab=profile]").click(); document.querySelector("[data-profile-tab=trainings]").click()');
  check(await evaluate('document.getElementById("training_training_title").value === "Training draft" && document.getElementById("training-save-status").textContent === "Unsaved training changes"'), 'Training drafts survive switching tabs.');
  await evaluate('window.confirm=()=>false; document.querySelector("[data-training-cancel]").click()');
  check(await evaluate('!document.getElementById("training-editor").hidden && document.getElementById("training_training_title").value === "Training draft"'), 'Declining Cancel preserves the training draft.');
  await evaluate('window.confirm=()=>true; document.querySelector("[data-training-cancel]").click(); document.querySelector("[data-training-add]").click()');
  check(await evaluate('document.getElementById("training-editor-title").textContent === "Add training record" && document.getElementById("training_training_title").value === "" && document.querySelector("#faculty-training-form [name=training_id]").value === "0" && document.activeElement.id === "training_training_title" && !document.getElementById("faculty-training-form").checkValidity()'), 'Add training starts a blank required form.');
  await evaluate(`(()=>{const values={training_title:'Faculty Development Workshop',agency:'Faculty Development Institute',date_from:'2026-11-14',date_to:'2026-11-15',venue:'Zoom',mode:'Online'};for(const [key,value] of Object.entries(values)){const field=document.getElementById('training_'+key);field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));}})()`);
  check(await evaluate('document.getElementById("faculty-training-form").checkValidity() && document.getElementById("training_date_to").min === "2026-11-14"'), 'Online training is valid and the end-date minimum follows the start date.');
  await evaluate('document.getElementById("training_date_to").value="2026-11-13"');
  check(await evaluate('!document.getElementById("faculty-training-form").checkValidity()'), 'The browser rejects an end date before the start date.');
  await evaluate('document.getElementById("training_date_to").value="2026-11-14"');
  check(await evaluate('document.getElementById("faculty-training-form").checkValidity()'), 'Same-day training is valid.');
  for (const width of [1440,768,390,320]) {
    await resize(width);
    check(await evaluate('document.documentElement.scrollWidth <= innerWidth && [...document.querySelectorAll("[data-trainings] .btn")].every(button=>{const box=button.getBoundingClientRect();return box.height>=44&&(button.closest(".profile-research-table-scroll")||(box.left>=0&&box.right<=innerWidth));})'), 'Training fits the page and keeps table scrolling inside its region at '+width+'px.');
    if(width===1440||width===390){await evaluate('document.getElementById("training-editor").scrollIntoView({block:"start",behavior:"instant"})');await screenshot('faculty-trainings-editor-'+(width===1440?'desktop':'mobile')+'.png');}
  }
  await evaluate('document.querySelector("[data-training-cancel]").click(); document.querySelector("[data-trainings]").scrollIntoView({block:"start",behavior:"instant"})');
  await screenshot('faculty-trainings-mobile.png');
  await evaluate('const trainingScroller=document.querySelector("[data-trainings] .profile-research-table-scroll");trainingScroller.scrollLeft=trainingScroller.scrollWidth');
  check(await evaluate('(()=>{const region=document.querySelector("[data-trainings] .profile-research-table-scroll").getBoundingClientRect();return [...document.querySelectorAll("[data-trainings] .profile-research-actions .btn")].every(button=>{const box=button.getBoundingClientRect();return box.left>=region.left&&box.right<=region.right;});})()'), 'Training actions are reachable by horizontal scrolling on mobile.');
  check(await evaluate('window.confirm=()=>false; !document.querySelector("[data-training-delete]").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))'), 'Declining Remove cancels training deletion.');
  await evaluate('window.confirm=()=>true; document.querySelector("[data-training-add]").click(); const trainingValues={training_title:"New training",agency:"Training Agency",date_from:"2026-11-14",date_to:"2026-11-14",venue:"Campus Hall",mode:"Face to face"}; for(const [key,value] of Object.entries(trainingValues)) document.getElementById("training_"+key).value=value; document.getElementById("faculty-training-form").addEventListener("submit",event=>{event.preventDefault();window.trainingSubmitted=Object.fromEntries(new FormData(event.target));},{once:true}); document.getElementById("faculty-training-form").requestSubmit()');
  check(await evaluate('window.trainingSubmitted.training_title === "New training" && window.trainingSubmitted.profile_action === "training_save" && !Object.hasOwn(window.trainingSubmitted,"full_name") && !Object.hasOwn(window.trainingSubmitted,"research_title") && document.getElementById("training-save-button").disabled'), 'Training submits its own record without profile or research fields.');
  check(errors.length === 0, 'Browser errors: ' + errors.join('; '));
  console.log('Faculty profile portfolio browser: ' + checks + ' checks passed. Desktop/mobile screenshots saved in .tmp.');
}
main().catch(error => {console.error(error); process.exitCode=1;}).finally(async () => {
  if (call) try {await call('Browser.close');} catch {}
  if (ws) ws.close(); if (browser && browser.exitCode===null) {browser.kill(); await new Promise(resolve=>browser.once('exit',resolve));}
  if (server) await new Promise(resolve=>server.close(resolve));
  if (fs.existsSync(html)) fs.unlinkSync(html);
  const relative=path.relative(temp,profile);
  if (relative&&!relative.startsWith('..')&&!path.isAbsolute(relative)&&fs.existsSync(profile)) fs.rmSync(profile,{recursive:true,force:true});
});
