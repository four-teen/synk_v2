// Exercise the actual Scheduler removal handlers with a server refusal.
const fs = require('node:fs'), vm = require('node:vm'), path = require('node:path'), assert = require('node:assert/strict');
let checks = 0;
const check = (value,message) => { assert.ok(value,message); checks++; };
const source = name => fs.readFileSync(path.join(__dirname,'../scheduler',name),'utf8').replace(/\r\n/g,'\n');
const block = (text,start,end) => text.slice(text.indexOf(start),text.indexOf(end,text.indexOf(start)));
const entries = [
  ['main',block(source('manage-workload.php'),'    $(document).on("click", ".btnRemoveWL"','    $("#btnClearCollegeWorkload")')],
  ['schedule',block(source('manage-class-schedule.php'),'$(document).on("click", ".btn-scheduler-remove-workload"','$(document).on("click", "#blockScheduleModal .btn-open-section-matrix"')],
  ['bulk',block(source('manage-workload.php'),'    function clearAllCollegeWorkloadInScope()','    function formatCompactTime')]
];
const message='This class has 39 students. Clear its student list in the Dean module before removing the workload. <img src=x>';
async function main() {
  for (const [name,raw] of entries) {
    check(raw.includes('query_remove_'),name+' removal handler found.');
    let handler, failure, always, request, refreshes=0; const alerts=[];
    const button={data:key=>({ids:'11,12',id:11,deleteCount:2,subject:'CS 101',assigneeType:'faculty'})[key],prop(){return this;},html(value){return value===undefined?'Remove':this;}};
    const chain={done(){return this;},fail(fn){failure=fn;return this;},always(fn){always=fn;return this;}};
    const $=value=>value===context.document?{on(event,selector,fn){handler=fn;}}:button;
    $.post=(url,data)=>{request={url,data};return chain;}; $.ajax=options=>{request=options;return chain;};
    const context={document:{},$,CSRF_TOKEN:'qa-token',WORKLOAD_COLLEGE_NAME:'Test College',schedulerFacultyWorkloadState:{facultyName:'Test Faculty'},
      Swal:{fire:options=>{alerts.push(options);return Promise.resolve({isConfirmed:true});},close(){},showLoading(){}},
      escapeHtml:value=>String(value).replace(/[&<>"']/g,'_'),beginSchedulerActivity(){return 1;},endSchedulerActivity(){},setButtonBusy(){},
      refreshWorkloadPanels(){refreshes++;},loadFacultyOverview(){refreshes++;},loadSchedulerFacultyWorkloadModal(){refreshes++;},loadScheduleTable(){refreshes++;},loadScheduledClasses(){refreshes++;},loadWorkloadList(){refreshes++;},
      getFacultyBrowserTermContext:()=>({ayId:2,semesterNum:1,ayText:'2026-2027',semesterUi:'1st'})};
    const code=raw.replace(/<\?=[\s\S]*?\?>/g,JSON.stringify('qa-token')).replace(/\r\n/g,'\n');
    vm.createContext(context); vm.runInContext(code,context);
    if(name==='bulk')context.clearAllCollegeWorkloadInScope();else handler.call(button);
    await new Promise(resolve=>setImmediate(resolve));
    check(request?.data.csrf_token==='qa-token',name+' carries the session token.');
    check(typeof failure==='function',name+' handles a server refusal.');
    failure({status:409,responseJSON:{status:'blocked',message}}); if(always)always();
    const alert=alerts.at(-1);
    check(alert.title==='Student list must be cleared'&&alert.text===message&&alert.icon==='warning',name+' displays the exact student count and guidance as text.');
    check(!alert.html&&refreshes===0,name+' never treats a blocked removal as success or renders its message as HTML.');
    if(name==='main'||name==='schedule')check(request.data.workload_ids.join(',')==='11,12',name+' submits paired workload IDs together.');
    if(name==='bulk')check(request.data.ay_id===2&&request.data.semester===1,'Bulk removal retains the selected term.');
  }
  console.log(`Scheduler workload removal UI: ${checks} checks passed.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
