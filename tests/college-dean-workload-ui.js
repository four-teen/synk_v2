// Run: node tests/college-dean-workload-ui.js
// Executes the actual page controller, including uploads and confirmation, with isolated responses.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../college-dean/faculty-workload.js'), 'utf8');
const elements = new Map();
const renderedCards = new Map();
const scrollCalls = [];
const viewport = {location:{search:''},scrollX:0,scrollY:0,innerHeight:800,
  requestAnimationFrame:callback=>setImmediate(callback),
  scrollTo(position) { this.scrollX=position.left;this.scrollY=position.top;scrollCalls.push(position); }};
let shiftOnModalHide = false;
function element(id) {
  if (!elements.has(id)) {
    elements.set(id, {
    id, value:'', innerHTML:'', textContent:'', className:'', src:'', alt:'', hidden:false, disabled:false, files:[],
    getAttribute(name) { return this[name] || null; },
    setAttribute(name,value) { this[name]=value; },
    querySelectorAll(selector) {
      if (this.id!=='deanClasses') return [];
      return selector==='article[data-offering-id]' ? [...renderedCards.values()] : [...renderedCards.values()].flatMap(card=>card.buttons);
    },
    querySelector(selector) { return renderedCards.get(Number(selector.match(/data-offering-id="(\d+)"/)?.[1])) || null; },
    handlers:new Map(), addEventListener(event,callback,options={}) {
      this.handlers.set(event,options.once?args=>{this.handlers.delete(event);return callback(args);}:callback);
    },
    reset() { element('deanCsvFile').files=[]; },
    async fire(event, props={}) { return this.handlers.get(event)?.({preventDefault(){this.prevented=true;}, ...props}); }
    });
    if (id==='deanClasses') {
      let markup='';
      Object.defineProperty(elements.get(id),'innerHTML',{
        get:()=>markup,
        set:value=>{
          if (markup.includes('<article')&&!value.includes('<article')) viewport.scrollY=0;
          markup=value;renderedCards.clear();
          for (const match of value.matchAll(/<article[^>]*data-offering-id="(\d+)"[^>]*>([\s\S]*?)<\/article>/g)) {
            const index=renderedCards.size;
            const details={open:/<details[^>]*\bopen(?:\s|>)/.test(match[2])};
            renderedCards.set(Number(match[1]),{dataset:{offeringId:match[1]},details,
              querySelector:()=>details,buttons:[{disabled:false},{disabled:false}],
              getBoundingClientRect:()=>({top:1800+index*260-viewport.scrollY,bottom:2050+index*260-viewport.scrollY})});
          }
        }
      });
    }
  }
  return elements.get(id);
}
element('deanWorkloadConfig').textContent = JSON.stringify({csrf_token:'qa-token',ay_id:2,semester:1,college_id:1,campus_id:1});
element('deanFacultyPhoto').src='faculty-placeholder.svg';
const faculties = [{faculty_id:411,faculty_name:'ANTONIO, ELBREN',profile_image_url:'/synk/uploads/profile/profile_2.jpg?v=first'},
  {faculty_id:412,faculty_name:'PEÑA, JOSÉ NIÑO',profile_image_url:''}];
const classes = [{offering_id:944,subject_code:'CS 111',section_display:'BSCS 1A',descriptive_title:'Fundamentals of Programming',
  term_text:'2026-2027 - 1st Semester',roster_count:0,schedules:[
    {schedule_id:11115,type:'LEC',days:'M, W',time_start:'09:00:00',time_end:'10:00:00',room:'CCS 304'},
    {schedule_id:11116,type:'LAB',days:'T, Th',time_start:'07:30:00',time_end:'09:00:00',room:'CCS 309'}
  ]}];
const requests = [];
let previewData = {status:'ok',file_name:'class.csv',preview_token:'preview-token', preview:{
  can_confirm:true,summary:{students:2,add:2,existing:0,new_profiles:2,conflicts:0,duplicate_rows:0},notes:['Assigned schedules are retained.'],
  rows:[{source_row_number:8,student_number:123,student_name_raw:'Student, First',action:'add',issue:''},
    {source_row_number:9,student_number:'000124',student_name_raw:'<img src=x onerror=alert(1)> Peña, JOSÉ NIÑO',action:'add',issue:''}]
}};
let confirmError = '';
let holdPreview = null;
let holdClasses = null;
const response = data => ({ok:data.status==='ok',json:async()=>data});
const fetch = async (endpoint, options) => {
  const params = Object.fromEntries(options.body.entries()); requests.push({endpoint,params});
  switch(params.action) {
    case 'bootstrap': return response({status:'ok',import_ready:true,faculties:JSON.parse(JSON.stringify(faculties))});
    case 'classes': {
      const faculty=JSON.parse(JSON.stringify(faculties.find(row=>row.faculty_id===Number(params.faculty_id))));
      if (holdClasses && Number(params.faculty_id)===411) await holdClasses.promise;
      return response({status:'ok',faculty,classes:JSON.parse(JSON.stringify(classes))});
    }
    case 'workload': return response({status:'ok',rows:[{schedule_id:11115,units:2,lec:2,lab_hours:0,faculty_load:2},{schedule_id:11116,units:1,lec:0,lab_hours:3,faculty_load:2.25}],meta:{total_load:4.25,total_preparations:1}});
    case 'roster': return response({status:'ok',students:[{student_number:123,student_name:'Student, First'},{student_number:'000124',student_name:'Peña, José Niño Second'}]});
    case 'preview': if (holdPreview) await holdPreview.promise; return response(previewData);
    case 'confirm': if (confirmError) return response({status:'error',message:confirmError}); classes[0].roster_count=2; return response({status:'ok',message:'2 students added. 0 existing students skipped.'});
    case 'cancel': return response({status:'ok'});
    default: throw Error('Unexpected action: '+params.action);
  }
};
const modals = new Map();
class Modal {
  constructor(target) { this.target=target; this.visible=false; modals.set(target.id,this); }
  show() { this.visible=true; }
  hide() {
    const event={prevented:false,preventDefault(){this.prevented=true;}};
    this.target.handlers.get('hide.bs.modal')?.(event);
    if(!event.prevented) {
      this.visible=false;
      if(shiftOnModalHide)viewport.scrollY=0;
      this.target.handlers.get('hidden.bs.modal')?.(event);
    }
  }
}
class FormData {
  constructor() { this.params=new Map(); }
  append(key,value) { this.params.set(key,value); }
  entries() { return this.params.entries(); }
}
const context = vm.createContext({document:{getElementById:element},bootstrap:{Modal},fetch,FormData,URLSearchParams,
  window:viewport, console});
vm.runInContext(source,context);
let checks=0;
const check = (passed,message) => {assert.ok(passed,message);checks++;};
const tick = () => new Promise(resolve=>setImmediate(resolve));
const clickClass = async kind => {
  await element('deanClasses').fire('click',{target:{closest:()=>({disabled:false,dataset:{[kind]:'944'}})}}); await tick();
};
(async()=>{
  await tick();
  check(!element('deanFaculty').disabled && element('deanFaculty').innerHTML.includes('ANTONIO'),'Faculty selector did not load.');
  element('deanFaculty').value='411'; await element('deanFaculty').fire('change');
  check(element('deanFacultyPhoto').src===faculties[0].profile_image_url && element('deanFacultyPhoto').alt.includes('ANTONIO'),'The selected faculty photo was not loaded.');
  check(element('deanClassCount').textContent===1,'Lecture and laboratory inflated class counts.');
  check(element('deanLoadCount').textContent==='4.25' && element('deanPreparationCount').textContent==='1','Dashboard workload metrics were not displayed.');
  check(element('deanClasses').innerHTML.includes('CCS 304') && element('deanClasses').innerHTML.includes('CCS 309'),'Both lecture and laboratory schedules are required.');
  const card=element('deanClasses').innerHTML;
  const summary=card.split('<summary')[1].split('</summary>')[0];
  check(!card.split('</summary>')[0].includes('<table') && ['Type','Days','Time','Room'].every(label=>summary.includes(`>${label}:</span>`)) && summary.includes('CCS 304') && summary.includes('CCS 309'),'The collapsed schedule must show labelled lecture and lab details without a table.');
  check(/<details class="dean-workload-details">/.test(card) && ['Type','Days','Time','Room','Units','Lec','Lab Hrs','Load'].every(label=>card.split('</summary>')[1].includes(`>${label}</th>`)) && !['Units','Lec','Lab Hrs','Load'].some(label=>summary.includes(`>${label}:</span>`)),'The full schedule table and workload metrics must appear only inside the expandable details.');
  check(card.includes('text-muted mt-2 mb-0">0 students') && !card.includes('dean-student-count-populated'),'An empty student list changed its muted color.');
  check(element('deanMissingCount').textContent===1,'The classes without student lists counter is inaccurate.');
  element('deanRosterFilter').value='with';await element('deanRosterFilter').fire('change');
  check(!element('deanClasses').innerHTML.includes('<article')&&element('deanClassVisibility').textContent==='Showing 0 of 1 assigned classes.','The with-lists filter included an empty student roster.');
  element('deanRosterFilter').value='missing';await element('deanRosterFilter').fire('change');
  check(element('deanClasses').innerHTML.includes('CS 111')&&element('deanClassCount').textContent===1,'The without-lists filter excluded an assigned class or changed its workload totals.');
  element('deanRosterFilter').value='all';await element('deanRosterFilter').fire('change');
  faculties[0].profile_image_url='/synk/uploads/profile/profile_2.jpg?v=replaced';
  renderedCards.get(944).details.open=true;viewport.scrollY=1750;
  const existingMarkup=element('deanClasses').innerHTML;
  holdClasses={};holdClasses.promise=new Promise(resolve=>holdClasses.resolve=resolve);
  const refreshing=element('deanRefresh').fire('click');await tick();
  check(element('deanClasses').innerHTML===existingMarkup&&viewport.scrollY===1750&&element('deanClasses').getAttribute('aria-busy')==='true','Refreshing removed the visible cards and clamped the scroll position.');
  holdClasses.resolve();await refreshing;holdClasses=null;
  check(viewport.scrollY===1750&&renderedCards.get(944).details.open,'Refreshing lost the scroll position or expanded workload details.');
  check(element('deanFacultyPhoto').src===faculties[0].profile_image_url,'Refresh did not pick up a replaced Professor profile image.');
  await element('deanFacultyPhoto').fire('error');
  check(element('deanFacultyPhoto').src==='faculty-placeholder.svg','An unavailable faculty photo did not use the placeholder.');
  await element('deanFacultyPhoto').fire('error');
  check(element('deanFacultyPhoto').src==='faculty-placeholder.svg','A missing placeholder caused a fallback loop.');
  element('deanFaculty').value='412'; await element('deanFaculty').fire('change');
  check(element('deanFacultyPhoto').src==='faculty-placeholder.svg' && element('deanFacultyPhoto').alt.includes('PEÑA, JOSÉ NIÑO'),'A faculty without an upload did not receive a correctly labelled placeholder.');
  holdClasses={}; holdClasses.promise=new Promise(resolve=>holdClasses.resolve=resolve);
  element('deanFaculty').value='411'; const pendingClasses=element('deanFaculty').fire('change'); await tick();
  element('deanFaculty').value='412'; await element('deanFaculty').fire('change');
  holdClasses.resolve(); await pendingClasses; holdClasses=null;
  check(element('deanFacultyPhoto').src==='faculty-placeholder.svg' && element('deanFacultyPhoto').alt.includes('PEÑA'),'An older workload response replaced the current faculty photo.');
  element('deanFaculty').value=''; await element('deanFaculty').fire('change');
  check(element('deanFacultyPhoto').src==='faculty-placeholder.svg' && element('deanSummary').hidden && !element('deanRefresh').disabled,'Clearing faculty selection did not reset the photo and controls.');
  element('deanFaculty').value='411'; await element('deanFaculty').fire('change');
  await clickClass('roster');
  check(modals.get('deanRosterModal').visible && element('deanStudentTotal').textContent==='2 students','Roster viewer did not open.');
  element('deanStudentSearch').value='124'; await element('deanStudentSearch').fire('input');
  check(element('deanRosterContent').innerHTML.includes('Second') && !element('deanRosterContent').innerHTML.includes('First'),'Student ID search does not filter the roster.');
  check(element('deanRosterContent').innerHTML.includes('000124') && element('deanRosterContent').innerHTML.includes('Peña, José Niño'),'The roster display lost leading zeros or accented characters.');
  element('deanStudentSearch').value='PEÑA'; await element('deanStudentSearch').fire('input');
  check(element('deanRosterContent').innerHTML.includes('Peña') && !element('deanRosterContent').innerHTML.includes('First'),'Accented name search does not handle case differences.');
  viewport.scrollY=1750;renderedCards.get(944).details.open=true;
  await clickClass('import');
  check(element('deanConfirmButton').hidden && element('deanConfirmButton').disabled,'Confirmation is enabled before preview.');
  element('deanCsvFile').files=[{name:'bad.txt',size:100}]; await element('deanImportForm').fire('submit');
  check(!requests.some(r=>r.params.action==='preview'),'A non-CSV file was uploaded.');
  element('deanCsvFile').files=[{name:'class.csv',size:100}]; await element('deanImportForm').fire('submit');
  check(!element('deanConfirmButton').hidden && !element('deanConfirmButton').disabled,'A valid preview did not enable confirmation.');
  check(!requests.some(r=>r.params.action==='confirm'),'Preview saved the roster automatically.');
  check(element('deanImportPreview').innerHTML.includes('&lt;img') && !element('deanImportPreview').innerHTML.includes('<img'),'CSV content was rendered as executable markup.');
  check(element('deanImportPreview').innerHTML.includes('Peña, JOSÉ NIÑO') && element('deanImportPreview').innerHTML.includes('000124'),'The CSV preview lost leading zeros or accented characters.');
  check(requests.find(r=>r.params.action==='preview').params.offering_id===944,'Preview was not bound to the selected class.');
  const beforeFailedImport=scrollCalls.length;
  confirmError='Student records changed. Preview again.'; await element('deanConfirmButton').fire('click');
  check(modals.get('deanImportModal').visible && element('deanImportNotice').textContent===confirmError,'A failed confirmation hid the error or closed the dialog.');
  check(scrollCalls.length===beforeFailedImport&&viewport.scrollY===1750,'A rejected import moved the workload page.');
  confirmError='';shiftOnModalHide=true;await element('deanConfirmButton').fire('click');shiftOnModalHide=false;
  check(!modals.get('deanImportModal').visible && element('deanNotice').textContent.includes('2 students added'),'Confirmed import did not show completion.');
  check(element('deanRosterCount').textContent===1 && element('deanClasses').innerHTML.includes('2 students in class list'),'Student counts were not refreshed after importing.');
  check(element('deanClasses').innerHTML.includes('dean-student-count-populated mt-2 mb-0">2 students'),'A populated student list was not highlighted in orange after import.');
  check(viewport.scrollY===1750&&renderedCards.get(944).details.open,'The completed import did not restore the class position after the modal closed.');
  check(element('deanMissingCount').textContent===0,'The missing-list counter was not refreshed after importing.');
  element('deanRosterFilter').value='missing';await element('deanRosterFilter').fire('change');
  check(!element('deanClasses').innerHTML.includes('<article'),'A completed student import remained in the without-lists filter.');
  element('deanRosterFilter').value='with';await element('deanRosterFilter').fire('change');
  check(element('deanClasses').innerHTML.includes('CS 111')&&element('deanClasses').innerHTML.includes('2.25')&&element('deanLoadCount').textContent==='4.25','Filtering populated classes lost workload details or changed faculty load.');
  element('deanRosterFilter').value='all';await element('deanRosterFilter').fire('change');
  check(requests.filter(r=>r.params.action==='confirm').every(r=>r.params.preview_token==='preview-token'),'Confirmation did not use the server-issued token.');
  await clickClass('import');
  previewData={...previewData,preview_token:'',preview:{...previewData.preview,can_confirm:false,summary:{...previewData.preview.summary,add:0,existing:2}}};
  element('deanCsvFile').files=[{name:'class.csv',size:100}]; await element('deanImportForm').fire('submit');
  check(element('deanConfirmButton').hidden && element('deanImportNotice').textContent.includes('already in this class'),'Duplicate uploads can still be confirmed.');
  previewData={...previewData,preview:{...previewData.preview,summary:{...previewData.preview.summary,conflicts:1}}};
  await element('deanImportForm').fire('submit');
  check(element('deanConfirmButton').hidden && element('deanImportNotice').textContent.includes('conflicts'),'Identity conflicts can still be confirmed.');
  previewData={...previewData,preview_token:'preview-next',preview:{...previewData.preview,can_confirm:true,summary:{...previewData.preview.summary,add:2,existing:0,conflicts:0}}};
  await element('deanImportForm').fire('submit'); await element('deanCsvFile').fire('change'); await tick();
  check(element('deanConfirmButton').hidden && requests.some(r=>r.params.action==='cancel' && r.params.preview_token==='preview-next'),'Changing the file leaves the previous preview confirmable.');
  holdPreview={}; holdPreview.promise=new Promise(resolve=>holdPreview.resolve=resolve);
  const pending=element('deanImportForm').fire('submit'); await tick(); modals.get('deanImportModal').hide();
  check(modals.get('deanImportModal').visible && element('deanImportClose').disabled,'An in-flight import preview can be dismissed.');
  holdPreview.resolve(); await pending; holdPreview=null;
  modals.get('deanImportModal').hide(); await tick();
  check(!modals.get('deanImportModal').visible && element('deanConfirmButton').hidden,'Closing the import did not discard its preview.');
  check(requests.every(r=>r.params.csrf_token==='qa-token' && r.params.ay_id===2 && r.params.semester===1),'Requests lost their session token or selected term.');
  console.log(`College Dean workload UI: ${checks} checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
