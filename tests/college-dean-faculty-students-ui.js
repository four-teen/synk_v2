// Executes the actual Reports controller with isolated API responses.
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const elements=new Map();
function element(id){if(!elements.has(id))elements.set(id,{id,value:'',innerHTML:'',textContent:'',className:'',hidden:false,disabled:false,handlers:new Map(),
  setAttribute(name,value){this[name]=value;},addEventListener(event,callback){this.handlers.set(event,callback);},
  async fire(event,props={}){return this.handlers.get(event)?.(props);}});return elements.get(id);}
element('deanReportConfig').textContent=JSON.stringify({csrf_token:'qa-report-token',college_id:1,campus_id:1});
const a={faculty_id:411,faculty_name:'Peña, José Niño <img src=x onerror=alert(1)>',assigned_classes:3,with_lists:2,without_lists:1,unique_students:2,total_enrollments:3};
const b={faculty_id:412,faculty_name:'Muñoz, María',assigned_classes:2,with_lists:2,without_lists:0,unique_students:3,total_enrollments:4};
const c={faculty_id:413,faculty_name:'Unassigned, Faculty',assigned_classes:0,with_lists:0,without_lists:0,unique_students:0,total_enrollments:0};
const term={key:'2:1',label:'2026-2027 - 1st Semester'};
let listData={status:'ok',rows:[a,b,c],summary:{faculties:3,assigned_classes:4,with_lists:3,without_lists:1,faculties_missing:1,unique_students:3,total_enrollments:5},term};
let detailData={status:'ok',faculty:a,term,is_current_term:true,classes:[
  {offering_id:944,subject_code:'CS 111',descriptive_title:'Programming',program_label:'BSCS',section_display:'BSCS 1A',student_count:2,workload_url:'faculty-workload.php?faculty_id=411&offering_id=944&list_status=all'},
  {offering_id:945,subject_code:'CS 222',descriptive_title:'<img src=x onerror=alert(1)>',program_label:'BSCS',section_display:'BSCS 2A',student_count:0,workload_url:'faculty-workload.php?faculty_id=411&offering_id=945&list_status=missing'}]};
let holdNextList=null,holdDetail=null,detailError='',listError='';
const requests=[];
class FormData{constructor(){this.params=new Map();}append(key,value){this.params.set(key,value);}entries(){return this.params.entries();}}
const fetch=async(endpoint,options)=>{
  const params=Object.fromEntries(options.body.entries());requests.push({endpoint,params});let data;
  if(params.action==='bootstrap')data={status:'ok',options:{current_term:{ay_id:2,semester:1},terms:[term,{key:'1:1',label:'2025-2026 - 1st Semester'}],programs:[{program_id:10,label:'BSCS'},{program_id:11,label:'BSIS'}]}};
  else if(params.action==='list'){const hold=holdNextList;holdNextList=null;data=listError?{status:'error',message:listError}:JSON.parse(JSON.stringify(listData));if(hold)await hold.promise;}
  else if(params.action==='detail'){data=detailError?{status:'error',message:detailError}:JSON.parse(JSON.stringify(detailData));if(holdDetail)await holdDetail.promise;}
  else throw Error('Unexpected report action.');
  return {ok:data.status==='ok',json:async()=>data};
};
const modals=new Map();
class Modal{constructor(target){this.target=target;this.visible=false;modals.set(target.id,this);}show(){this.visible=true;}hide(){this.target.handlers.get('hide.bs.modal')?.();this.visible=false;}}
const context=vm.createContext({document:{getElementById:element},bootstrap:{Modal},fetch,FormData,setTimeout,clearTimeout,console});
vm.runInContext(fs.readFileSync(path.join(__dirname,'../college-dean/reports.js'),'utf8'),context);
const tick=()=>new Promise(resolve=>setImmediate(resolve));let checks=0;
const check=(condition,message)=>{assert.ok(condition,message);checks++;};
const open=async id=>{await element('deanReportRows').fire('click',{target:{closest:()=>({dataset:{facultyClasses:String(id)}})}});await tick();};
(async()=>{
  await tick();
  check(element('deanReportTerm').value==='2:1'&&!element('deanReportProgram').disabled,'The report did not default to the current academic term.');
  check(element('deanReportUnique').textContent==='3'&&element('deanReportEnrollments').textContent==='5','The overview summed duplicate per-faculty totals instead of using distinct college totals.');
  check(element('deanReportMissing').textContent==='1'&&element('deanReportFacultyMissing').textContent==='1','Missing lists were counted incorrectly.');
  check(element('deanReportRows').innerHTML.includes('Peña, José Niño')&&element('deanReportRows').innerHTML.includes('&lt;img')&&!element('deanReportRows').innerHTML.includes('<img'),'Faculty names were damaged or rendered as executable HTML.');
  check(element('deanReportRows').innerHTML.includes('No assigned classes'),'Unassigned faculty were not identified separately.');
  await open(411);
  check(modals.get('deanFacultyClassesModal').visible&&element('deanFacultyClassesTotals').textContent.includes('2 unique students'),'Faculty class details failed to open.');
  check(element('deanFacultyClassesContent').innerHTML.includes('No Students Imported')&&element('deanFacultyClassesContent').innerHTML.includes('offering_id=945&amp;list_status=missing'),'A missing list had no direct workload import link.');
  check(!element('deanFacultyClassesContent').innerHTML.includes('<img')&&element('deanFacultyClassesContent').innerHTML.includes('&lt;img'),'Subject descriptions were rendered as executable HTML.');
  element('deanFacultyClassStatus').value='missing';await element('deanFacultyClassStatus').fire('change');
  check(element('deanFacultyClassesContent').innerHTML.includes('CS 222')&&!element('deanFacultyClassesContent').innerHTML.includes('CS 111'),'The class detail filter included a populated class.');
  element('deanFacultyClassStatus').value='with';await element('deanFacultyClassStatus').fire('change');
  check(element('deanFacultyClassesContent').innerHTML.includes('CS 111')&&!element('deanFacultyClassesContent').innerHTML.includes('CS 222'),'The class detail filter included an empty class.');
  modals.get('deanFacultyClassesModal').hide();
  const assignedDetail=detailData;detailData={...detailData,faculty:c,classes:[]};await open(413);
  check(element('deanFacultyClassesContent').innerHTML.includes('No classes are assigned')&&element('deanFacultyClassesTotals').textContent.includes('0 classes without lists'),'Unassigned faculty were presented as a missing student-list filter result.');
  detailData=assignedDetail;modals.get('deanFacultyClassesModal').hide();
  element('deanReportProgram').value='10';element('deanReportTerm').value='1:1';element('deanReportStatus').value='missing';await element('deanReportStatus').fire('change');await tick();
  const filtered=requests.filter(request=>request.params.action==='list').at(-1).params;
  check(filtered.program_id==='10'&&filtered.term==='1:1'&&filtered.status==='missing','Program, term, or missing-list filters were not forwarded to the report.');
  detailData={...detailData,is_current_term:false,term:{key:'1:1',label:'2025-2026 - 1st Semester'},classes:detailData.classes.map(row=>({...row,workload_url:null}))};
  await open(411);
  check(element('deanFacultyClassStatus').value==='missing'&&!element('deanFacultyClassesNotice').hidden&&!element('deanFacultyClassesContent').innerHTML.includes('href='),'Historical details offered an import link or ignored the selected missing-list status.');
  modals.get('deanFacultyClassesModal').hide();
  holdDetail={};holdDetail.promise=new Promise(resolve=>holdDetail.resolve=resolve);await open(411);
  modals.get('deanFacultyClassesModal').hide();const closedContent=element('deanFacultyClassesContent').innerHTML;holdDetail.resolve();await tick();holdDetail=null;
  check(element('deanFacultyClassesContent').innerHTML===closedContent,'A dismissed detail request changed the closed modal.');
  detailError='Faculty unavailable';await open(411);
  check(element('deanFacultyClassesContent').innerHTML.includes(detailError)&&element('deanFacultyClassStatus').disabled,'A detail error left old classes or enabled the filter.');
  detailError='';modals.get('deanFacultyClassesModal').hide();
  element('deanReportSearch').value='PEÑA';await element('deanReportSearch').fire('input');await new Promise(resolve=>setTimeout(resolve,280));
  check(requests.filter(request=>request.params.action==='list').at(-1).params.search==='PEÑA','Accented faculty searches were not forwarded.');
  await element('deanReportReset').fire('click');await tick();
  check(element('deanReportTerm').value==='2:1'&&element('deanReportProgram').value==='0'&&element('deanReportStatus').value==='all'&&element('deanReportSearch').value==='','Reset Filters did not restore the current term and all faculty.');
  const previous=element('deanReportRows').innerHTML;listError='Temporary report error';await element('deanReportRefresh').fire('click');await tick();
  check(element('deanReportRows').innerHTML===previous&&element('deanReportNotice').textContent===listError&&!element('deanReportRefresh').disabled,'A failed refresh removed the visible report or stranded its controls.');
  listError='';holdNextList={};holdNextList.promise=new Promise(resolve=>holdNextList.resolve=resolve);const hold=holdNextList;
  await element('deanReportRefresh').fire('click');await tick();
  listData={...listData,rows:[b],summary:{...listData.summary,faculties:1,unique_students:3}};
  element('deanReportStatus').value='complete';await element('deanReportStatus').fire('change');await tick();hold.resolve();await tick();
  check(element('deanReportRows').innerHTML.includes('Muñoz')&&!element('deanReportRows').innerHTML.includes('Peña'),'A stale report response replaced the current filter results.');
  check(requests.every(request=>request.params.csrf_token==='qa-report-token'&&request.params.college_id===1&&request.params.campus_id===1),'A report request lost its session or college scope.');
  console.log(`Faculty Student Summary UI: ${checks} checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
