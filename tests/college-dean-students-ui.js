// node tests/college-dean-students-ui.js
// Runs the actual directory controller with isolated API responses.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = new Map();
function element(id) {
  if (!elements.has(id)) elements.set(id,{id,value:'',textContent:'',innerHTML:'',disabled:false,handlers:new Map(),attrs:{},
    addEventListener(event,handler){this.handlers.set(event,handler);},setAttribute(key,value){this.attrs[key]=value;},
    fire(event,props={}){return this.handlers.get(event)?.({preventDefault(){},...props});}});
  return elements.get(id);
}
element('deanStudentsConfig').textContent = JSON.stringify({csrf_token:'students-token',college_id:1,campus_id:1});
for (const [id,value] of Object.entries({deanStudentsTerm:'all',deanStudentsProgram:'0',deanStudentsSection:'0',deanStudentsSort:'name',deanStudentsPageSize:'25'})) element(id).value=value;
const profiles = [
  {student_id:11,student_number:'000123',student_name:'Peña, José Niño',email_address:'Jose.Pena@sksu.edu.ph',programs:'BSCS',sections:'BSCS 1A',subject_count:2,last_imported:'2026-10-07 09:00:00'},
  {student_id:12,student_number:'000124',student_name:'<img src=x onerror=alert(1)> Muñoz, María',programs:'BSIT',sections:'BSIT 1B',subject_count:1,last_imported:'2026-10-07 09:00:00'}
];
const options = {programs:[{program_id:1,label:'BSCS'},{program_id:2,label:'BSIT'}],
  terms:[{key:'2:1',label:'2026-2027 - 1st Semester'},{key:'2:2',label:'2026-2027 - 2nd Semester'}],
  sections:[{section_id:10,program_id:1,term:'2:1',section_label:'BSCS 1A'},{section_id:20,program_id:1,term:'2:2',section_label:'BSCS 1A'},
    {section_id:30,program_id:2,term:'2:1',section_label:'BSIT 1B'}]};
const requests = [];
let listError='', detailError='', heldList=null, heldDetail=null;
const response = data=>({ok:data.status==='ok',json:async()=>data});
async function fetch(endpoint,request) {
  const params = Object.fromEntries(request.body.entries()); requests.push({endpoint,params});
  if(params.action==='bootstrap')return response({status:'ok',options,current_term:{ay_id:2,semester:1}});
  if(params.action==='list') {
    if(listError)return response({status:'error',message:listError});
    if(heldList && params.search==='slow')await heldList.promise;
    let rows = params.search==='absent' ? [] : profiles;
    if(params.search==='PEÑA')rows=[profiles[0]];
    if(params.email_status==='missing') rows=rows.filter(row=>!String(row.email_address || '').trim());
    if(params.email_status==='present') rows=rows.filter(row=>String(row.email_address || '').trim());
    const total=rows.length, size=Number(params.page_size), page=Math.min(Number(params.page),Math.max(1,Math.ceil(total/size)));
    return response({status:'ok',rows:rows.slice((page-1)*size,page*size),summary:{students:total,classes:total ? 3 : 0,programs:total ? 2 : 0},
      pagination:{page,page_size:size,pages:Math.max(1,Math.ceil(total/size)),total,from:total ? (page-1)*size+1 : 0,to:Math.min(page*size,total)}});
  }
  if(params.action==='detail') {
    if(heldDetail)await heldDetail.promise;
    if(detailError)return response({status:'error',message:detailError});
    return response({status:'ok',student:{...profiles[0],email_address:''},subjects:[
      {subject_code:'CS 111',descriptive_title:'Fundamentals of Programming',program_label:'BSCS',section_label:'BSCS 1A',
        term_label:'2026-2027 - 1st Semester',faculty_name:'ANTONIO, ELBREN',source_file_name:'<img src=x> & roster.csv',imported_at:'2026-10-07 09:00:00'},
      {subject_code:'CS 112',descriptive_title:'Programming 2',program_label:'BSCS',section_label:'BSCS 1A',
        term_label:'2026-2027 - 1st Semester',faculty_name:'ANTONIO, ELBREN',source_file_name:'roster2.csv',imported_at:'2026-10-07 09:00:00'}
    ]});
  }
  throw Error('Unexpected directory action.');
}
class FormData { constructor(){this.data=new Map();} append(key,value){this.data.set(key,value);} entries(){return this.data.entries();} }
const modals = new Map();
class Modal { constructor(target){this.target=target;modals.set(target.id,this);} show(){this.visible=true;} hide(){this.visible=false;this.target.fire('hide.bs.modal');} }
let timer=0; const timers = new Map();
const context = vm.createContext({document:{getElementById:element},FormData,fetch,bootstrap:{Modal},
  setTimeout:callback=>{const id=++timer;timers.set(id,callback);return id;},clearTimeout:id=>timers.delete(id)});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../college-dean/students.js'),'utf8'),context);
let checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;};
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const fire=async(id,event,props={})=>{await element(id).fire(event,props);await tick();};
const last=action=>requests.filter(item=>item.params.action===action).at(-1).params;
const openDetails=()=>fire('deanStudentsRows','click',{target:{closest:()=>({dataset:{student:'11'}})}});
(async()=>{
  await tick();
  check(!element('deanStudentsProgram').disabled && element('deanStudentsTerm').value==='2:1' && last('list').term==='2:1','The directory did not default to the current semester.');
  check(element('deanStudentsTerm').innerHTML.includes('(Current)'),'The current academic term is not identified.');
  check(element('deanUniqueStudents').textContent==='2' && element('deanStudentsRows').innerHTML.includes('Peña, José Niño'),'Unique student totals or accented names were not displayed.');
  check(element('deanStudentsRows').innerHTML.includes('000123'),'The student ID display lost leading zeros.');
  check(!element('deanStudentsRows').innerHTML.includes('<img') && element('deanStudentsRows').innerHTML.includes('&lt;img'),'Student names were inserted as executable HTML.');
  element('deanStudentsEmailStatus').value='missing'; await fire('deanStudentsEmailStatus','change');
  check(last('list').email_status==='missing' && last('list').page===1 && element('deanUniqueStudents').textContent==='1' && !element('deanStudentsRows').innerHTML.includes('000123'),'Missing-email filtering did not refresh the directory and totals.');
  await fire('deanStudentsRefresh','click'); check(element('deanStudentsEmailStatus').value==='missing' && last('list').email_status==='missing','Refresh discarded the missing-email filter.');
  element('deanStudentsEmailStatus').value='present'; await fire('deanStudentsEmailStatus','change');
  check(element('deanStudentsRows').innerHTML.includes('jose.pena@sksu.edu.ph') && !element('deanStudentsRows').innerHTML.includes('000124'),'With-email filtering showed students without addresses.');
  await fire('deanStudentsReset','click'); check(element('deanStudentsEmailStatus').value==='all' && last('list').email_status==='all','Reset retained the email-status filter.');
  element('deanStudentsPageSize').value='1'; await fire('deanStudentsPageSize','change');
  check(!element('deanStudentsNext').disabled && element('deanStudentsPageInfo').textContent.includes('1–1 of 2'),'Pagination did not use the selected page size.');
  await fire('deanStudentsNext','click');
  check(last('list').page===2 && element('deanStudentsPageInfo').textContent.includes('Page 2 of 2') && element('deanStudentsNext').disabled,'Next page did not advance and stop at the last page.');
  await fire('deanStudentsPrevious','click'); check(last('list').page===1,'Previous page did not move back.');
  element('deanStudentsSearch').value='PEÑA'; await fire('deanStudentsFilters','submit');
  check(last('list').search==='PEÑA' && last('list').page===1 && element('deanUniqueStudents').textContent==='1','Accented search did not reset pagination or request the correct query.');
  element('deanStudentsProgram').value='1'; await fire('deanStudentsProgram','change');
  check(!element('deanStudentsSection').innerHTML.includes('BSIT'),'Section options ignored the selected program.');
  element('deanStudentsSection').value='20'; element('deanStudentsTerm').value='2:1'; await fire('deanStudentsTerm','change');
  check(element('deanStudentsSection').value==='0' && !element('deanStudentsSection').innerHTML.includes('value="20"'),'Term changes retained a stale section selection.');
  element('deanStudentsSection').value='10'; await fire('deanStudentsSection','change');
  await openDetails();
  check(modals.get('deanStudentDetailsModal').visible && last('detail').term==='2:1' && last('detail').student_id===11,'Student Details was not scoped to the selected student and term.');
  check(element('deanStudentDetailsContent').innerHTML.includes('000123') && element('deanStudentDetailsContent').innerHTML.includes('CS 112'),'Student Details did not show the profile and separate subject lists.');
  check(!element('deanStudentDetailsContent').innerHTML.includes('<img') && element('deanStudentDetailsContent').innerHTML.includes('&lt;img'),'Import filenames were inserted as executable HTML.');
  await fire('deanStudentsRefresh','click');
  check(element('deanStudentsProgram').value==='1' && element('deanStudentsTerm').value==='2:1' && element('deanStudentsSection').value==='10','Refresh discarded valid directory filters.');
  await fire('deanStudentsReset','click');
  check(last('list').search==='' && last('list').term==='2:1' && last('list').program_id==='0' && last('list').section_id==='0','Reset did not restore the current semester directory.');
  element('deanStudentsTerm').value='year:2'; await fire('deanStudentsTerm','change');
  check(element('deanStudentsSection').innerHTML.includes('value="10"') && element('deanStudentsSection').innerHTML.includes('value="20"'),'An academic-year filter hid class sections from another semester.');
  element('deanStudentsTerm').value='all'; await fire('deanStudentsTerm','change'); await fire('deanStudentsRefresh','click');
  check(element('deanStudentsTerm').value==='all','Refresh lost an explicitly selected All academic terms filter.');
  await fire('deanStudentsReset','click');
  element('deanStudentsSearch').value='absent'; await fire('deanStudentsFilters','submit');
  check(element('deanStudentsRows').innerHTML.includes('No matching students') && element('deanUniqueStudents').textContent==='0','Empty search results did not display a clear empty state.');
  check(element('deanStudentsRows').innerHTML.includes('colspan="6"'),'The shared directory empty state did not span its six columns.');
  listError='Your access changed.'; await fire('deanStudentsRefresh','click');
  check(element('deanStudentsNotice').textContent===listError && !element('deanStudentsRefresh').disabled,'A failed list request did not offer a working refresh.');
  listError=''; element('deanStudentsSearch').value=''; await fire('deanStudentsRefresh','click');
  detailError='This student is no longer available.'; await openDetails();
  check(element('deanStudentDetailsContent').innerHTML.includes(detailError) && !element('deanStudentDetailsContent').innerHTML.includes('000123'),'A failed detail request displayed stale student data.');
  detailError=''; heldDetail={}; heldDetail.promise=new Promise(resolve=>heldDetail.resolve=resolve);
  element('deanStudentsRows').fire('click',{target:{closest:()=>({dataset:{student:'11'}})}}); await tick();
  modals.get('deanStudentDetailsModal').hide(); heldDetail.resolve(); await tick(); heldDetail=null;
  check(element('deanStudentDetailsContent').innerHTML.includes('Loading student details'),'A closed detail request overwrote a later modal state.');
  heldList={}; heldList.promise=new Promise(resolve=>heldList.resolve=resolve);
  element('deanStudentsSearch').value='slow'; element('deanStudentsFilters').fire('submit'); await tick();
  element('deanStudentsSearch').value='absent'; await fire('deanStudentsFilters','submit');
  heldList.resolve(); await tick(); heldList=null;
  check(element('deanUniqueStudents').textContent==='0' && element('deanStudentsRows').innerHTML.includes('No matching students'),'An older search response overwrote newer results.');
  check(requests.every(item=>item.params.csrf_token==='students-token' && item.params.college_id===1 && item.params.campus_id===1),'Requests lost their assigned college or session token.');
  console.log(`College Dean students UI: ${checks} checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
