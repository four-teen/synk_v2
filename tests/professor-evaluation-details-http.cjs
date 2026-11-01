// Real read-only controller, isolated subprocess fixtures, all DB changes rolled back.
const {spawnSync}=require('node:child_process');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
let checks=0;
for(const [scenario,status] of Object.entries({pending:200,results:200,export:200,forged_identity:200,anonymous:401,wrong_role:401,disabled:403,revoked:403,unlinked:403,inactive_faculty:403,reassigned:404,hidden:403,hidden_export:403,foreign:404,stale_term:409,wrong_college:409,malformed:422,bad_view:422,method:405})){
 const result=spawnSync('php',[path.join(__dirname,'fixtures/professor-evaluation-details-request.php'),scenario],{cwd:root,encoding:'utf8',windowsHide:true});
 if(result.status!==0)throw Error(scenario+': '+result.stderr+result.stdout);
 const response=JSON.parse(result.stdout),body=response.body;
 if(response.status!==status)throw Error(scenario+': '+result.stdout);
 if(status===200){
  if(scenario==='export'){
   if(typeof body!=='string'||!body.includes('Student number')||!body.includes('Ana Test Student Jr.')||!body.includes('Failed Test Student Jr.')||body.includes('José'))throw Error('CSV contains incorrect students.');
   if(!response.filename||!response.filename.endsWith('.csv')||!response.filename_context.every(value=>response.filename.includes(value)))throw Error('CSV filename is missing subject, course or section.');
  }else{
   if(!body.ok||body.counts.total!==3||body.counts.evaluated!==1||body.counts.not_evaluated!==2)throw Error('Incorrect class counts: '+scenario);
   if(scenario==='results'){
    if(!body.html.includes('80.00% normalized score')||!body.html.includes('COMMENT &lt;script&gt;bad()&lt;/script&gt;')||body.html.includes('Test Student'))throw Error('Results have incorrect scoring, unsafe comments, or named evaluators.');
   }else if(!body.html.includes('Ana Test Student Jr.')||!body.html.includes('Failed Test Student Jr.')||body.html.includes('José'))throw Error('Pending panel includes an evaluated or unrelated student.');
  }
 }else if(body.ok!==false||'html' in body||'students' in body)throw Error('A failed request exposed data: '+scenario);
 checks++;
}
console.log('Professor evaluation detail controller: '+checks+' checks passed. Fixtures rolled back.');
