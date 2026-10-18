// Run: node tests/prospectus-builder-ui.cjs
// Run the actual Chair controller against the rendered page with controlled AJAX responses.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',
    [path.join(root,'tests/fixtures/program-chair-curriculum-request.php'),'page_builder'],{encoding:'utf8'}));
const html = fixture.response;
const config = JSON.parse(html.match(/window\.chairProspectusConfig = (\{[^\n]+\});/)[1]);
const decode = text => text.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&#0?39;/g,"'");
const attributes = text => Object.fromEntries([...text.matchAll(/([\w-]+)=(?:"([^"]*)"|'([^']*)')/g)].map(match=>[match[1],decode(match[2]??match[3])]));
const elements = new Map();
for(const match of html.matchAll(/<(\w+)\b([^>]*\bid="[^"]+"[^>]*)>/g)) {
    const attrs = attributes(match[2]);
    elements.set(attrs.id,{tag:match[1],attrs,value:attrs.value||'',text:'',html:'',options:[],events:{},disabled:/\sdisabled(?:\s|>|$)/.test(match[2]),visible:!String(attrs.style||'').includes('display:none'),reportValidity:()=>true});
}
for(const match of html.matchAll(/<select\b([^>]*\bid="[^"]+"[^>]*)>(.*?)<\/select>/gs)) {
    const element = elements.get(attributes(match[1]).id);
    for(const option of match[2].matchAll(/<option\b([^>]*)>(.*?)<\/option>/gs)) {
        const attrs = attributes(option[1]);
        element.options.push({value:attrs.value||'',text:decode(option[2].trim()),attrs});
    }
    element.value=element.options[0]?.value||'';
}
const requests=[], alerts=[], structureLoads=[], delegates={};
const document={events:{},attrs:{}};
let checks=0, confirmNext=false;
function check(condition,message){if(!condition)throw Error(message);checks++;}
class Option {constructor(text,value){this.text=String(text);this.value=String(value);this.attrs={};}}
class Selection {
    constructor(items){this.items=items;this.length=items.length;}
    on(event,selector,handler){this.items.forEach(item=>{if(typeof selector==='function')item.events[event]=selector;else delegates[selector]=handler;});return this;}
    trigger(event,args=[]){this.items.forEach(item=>item.events[event]?.call(item,{preventDefault(){}},...args));return this;}
    val(value){if(value===undefined)return this.items[0]?.value??null;this.items.forEach(item=>item.value=String(value));return this;}
    text(value){if(value===undefined)return this.items[0]?.text||'';this.items.forEach(item=>item.text=String(value));return this;}
    html(value){if(value===undefined)return this.items[0]?.html||'';this.items.forEach(item=>item.html=String(value));return this;}
    attr(key,value){if(value===undefined)return this.items[0]?.attrs[key];this.items.forEach(item=>item.attrs[key]=value);return this;}
    prop(key,value){if(value===undefined)return this.items[0]?.[key];this.items.forEach(item=>item[key]=value);return this;}
    empty(){this.items.forEach(item=>{item.html='';item.options=[];item.value='';});return this;}
    append(option){this.items.forEach(item=>{item.options.push(option);if(item.options.length===1)item.value=option.value;});return this;}
    find(){return new Selection([...elements.values()].filter(item=>['button','input','select'].includes(item.tag)));}
    toArray(){return this.items;}
    select2(){return this;}
    show(){this.items.forEach(item=>item.visible=true);return this;}
    hide(){this.items.forEach(item=>item.visible=false);return this;}
    modal(action){if(action==='show')this.show();else if(action==='hide')this.hide();return this;}
}
function $(value){
    if(typeof value==='function'){value();return new Selection([]);}
    if(typeof value!=='string')return new Selection(value?[value]:[]);
    if(value==='#program_id option:selected')return new Selection(elements.get('program_id').options.filter(option=>option.value===elements.get('program_id').value));
    if(value==='#program_id option')return new Selection(elements.get('program_id').options);
    return new Selection(value.split(',').map(selector=>elements.get(selector.trim().replace(/^#/,''))).filter(Boolean));
}
$.post=(endpoint,data,success)=>{
    const request={endpoint,data,success,aborted:false,failed:[],finished:[],
        fail(callback){this.failed.push(callback);return this;},always(callback){this.finished.push(callback);return this;},
        abort(){this.aborted=true;this.failed.forEach(callback=>callback({},'abort'));this.finished.forEach(callback=>callback());},
        respond(response){this.success(response);this.finished.forEach(callback=>callback());},
        reject(message){this.failed.forEach(callback=>callback({responseText:message},'error'));this.finished.forEach(callback=>callback());}};
    requests.push(request);return request;
};
const window={chairProspectusConfig:config,location:{href:'http://localhost/synk/program-chair/manage-prospectus.php'},history:{replaceState(state,title,url){window.location.href=new URL(url,window.location.href).href;}},loadYearSem:(...args)=>structureLoads.push(args)};
const context=vm.createContext({$,jQuery:$,window,document,Option,URL,Swal:{fire(...args){alerts.push(args);const result={isConfirmed:confirmNext};confirmNext=false;return Promise.resolve(result);}}});
vm.runInContext(fs.readFileSync(path.join(root,'program-chair/prospectus-builder.js'),'utf8'),context);
const last=()=>requests.at(-1), element=id=>elements.get(id);
const load=id=>delegates['.btnLoadProgramProspectus'].call({attrs:{'data-prospectus-id':String(id)}});
const submit=id=>$('#'+id).trigger('submit');
const list=rows=>{check(last().data.load_prospectus_by_program===1,'Expected a program version list request.');last().respond(rows);};
const original={prospectus_id:fixture.header,cmo_no:'Older CMO',effective_sy:'2024-2025',subject_count:2,total_units:7};
const newer={prospectus_id:900001,cmo_no:'Newer <img src=x onerror=alert(1)>',effective_sy:'2026-2027',subject_count:0,total_units:0};
const header=(pid,program,cmo,sy)=>{check(last().data.load_header===1&&String(last().data.prospectus_id)===String(pid),'Loaded the wrong prospectus ID.');last().respond(`OK|${program}|${cmo}|${sy}|`);};

async function main(){
    check(html.includes('id="btnCreateProspectus"')&&!html.includes('id="btnSaveHeader"')&&!html.includes('id="btnLoadHeader"'),'Chair still has the old create/load controls.');
    check(!html.includes('id="copy_source_prospectus"')&&html.includes('id="copy_source_label"'),'Copy source is still a separate prospectus selector.');
    check(requests.length===0&&element('btnCreateProspectus').disabled,'A program was not required before creation.');
    $('#program_id').val(fixture.own_program).trigger('change');
    check(String(last().data.program_id)===String(fixture.own_program)&&last().endpoint===config.listEndpoint,'Version list was not restricted to the selected program.');
    list([original,newer]);
    let rendered=element('programProspectusRows').html;
    check(rendered.indexOf('Newer')<rendered.indexOf('Older'),'Versions were not sorted by effectivity year.');
    check(rendered.includes('&lt;img')&&!rendered.includes('<img'),'Version labels were not escaped.');
    check(rendered.includes('900001')&&!element('loadedProspectusCard').visible,'An empty version disappeared or a version loaded automatically.');
    load(fixture.header);header(fixture.header,fixture.own_program,'Older CMO','2024-2025');
    list([original,newer]);
    check(element('prospectus_id').value===String(fixture.header)&&element('ys_prospectus_id').value===String(fixture.header),'Editor IDs did not follow the loaded version.');
    check(element('loadedCmo').text==='Older CMO'&&element('loadedEffectivity').text==='2024-2025'&&element('loadedProspectusCard').visible,'Loaded version details are incorrect.');
    check(structureLoads.at(-1)[0]===String(fixture.header)&&structureLoads.at(-1)[1]===false,'The wrong subject structure was requested.');
    check(element('programProspectusRows').html.includes('>Loaded</span>')&&window.location.href.endsWith('pid='+fixture.header),'Loaded marker or deep link did not update.');

    $('#program_id').val(fixture.target_program).trigger('change');
    check(element('prospectus_id').value===''&&!element('yearSemCard').visible&&!element('loadedProspectusCard').visible&&element('btnCopyProspectus').disabled,'Changing programs retained the old editor.');
    list([]);
    check(element('programProspectusRows').html.includes('Create New Prospectus'),'Empty program has no clear create action.');
    $('#btnCreateProspectus').trigger('click');
    $('#create_cmo_no').val('New CMO');$('#create_effective_sy').val('2027-2028');
    submit('createProspectusForm');
    check(last().data.save_header===1&&String(last().data.program_id)===String(fixture.target_program)&&last().data.csrf_token===config.csrfToken,'Creation changed the existing save contract or program scope.');
    const pending=requests.length;submit('createProspectusForm');
    check(requests.length===pending&&element('program_id').disabled,'Repeated create submitted twice or changed program while saving.');
    last().respond('OK|900002|Created');header(900002,fixture.target_program,'New CMO','2027-2028');
    const created={prospectus_id:900002,cmo_no:'New CMO',effective_sy:'2027-2028',subject_count:0,total_units:0};
    list([created]);
    check(!element('createProspectusModal').visible&&element('prospectus_id').value==='900002'&&element('loadedCmo').text==='New CMO','New empty prospectus was not loaded after creation.');

    $('#btnCreateProspectus').trigger('click');$('#create_cmo_no').val('New CMO');$('#create_effective_sy').val('2027-2028');
    submit('createProspectusForm');confirmNext=true;last().respond('EXISTING|900002|Already exists');await Promise.resolve();
    check(alerts.at(-1)[0].confirmButtonText==='Load Existing Prospectus','Duplicate creation did not offer to load the existing version.');
    header(900002,fixture.target_program,'New CMO','2027-2028');list([created]);
    $('#btnCopyProspectus').trigger('click');
    check(last().data.load_programs===1&&element('copy_source_label').value.includes('New CMO'),'Copy did not use the loaded source.');
    last().respond([{program_id:fixture.own_program,program_name:'Own Program',program_code:'OWN',major:''},{program_id:fixture.target_program,program_name:'Target Program',program_code:'TARGET',major:''}]);
    check(element('copyProspectusModal').visible&&element('copy_target_program').value===String(fixture.target_program),'Copy destination did not default to the source program.');
    $('#copy_target_program').val(fixture.own_program);$('#copy_cmo_no').val('Copied CMO');$('#copy_effective_sy').val('2028-2029');
    submit('copyProspectusForm');
    check(last().data.copy_prospectus===1&&last().data.source_prospectus_id==='900002'&&String(last().data.target_program_id)===String(fixture.own_program),'Copy sent the wrong source or destination.');
    const copying=requests.length;submit('copyProspectusForm');check(requests.length===copying,'Copy was submitted twice while pending.');
    last().respond('OK|900003|Copied');header(900003,fixture.own_program,'Copied CMO','2028-2029');
    list([original,newer,{prospectus_id:900003,cmo_no:'Copied CMO',effective_sy:'2028-2029',subject_count:2,total_units:7}]);
    check(element('program_id').value===String(fixture.own_program)&&element('prospectus_id').value==='900003'&&element('loadedCmo').text==='Copied CMO'&&element('loadedEffectivity').text==='2028-2029','Copy left the source header displayed over the copied subjects.');
    check(!element('copyProspectusModal').visible&&structureLoads.at(-1)[0]==='900003','Copied prospectus structure was not loaded.');

    load(fixture.header);const stale=last();
    $('#program_id').val(fixture.target_program).trigger('change');list([created]);
    stale.respond(`OK|${fixture.own_program}|Stale|2024-2025|`);
    check(element('prospectus_id').value===''&&element('program_id').value===String(fixture.target_program)&&!element('yearSemCard').visible,'A late header response restored a previous program.');
    load(900002);last().reject('ERROR|Role was revoked.');
    check(!element('loadedProspectusCard').visible&&alerts.at(-1)[1]==='Role was revoked.','Loading failure showed an old editor or hid the server error.');
    $('#btnRefreshProspectuses').trigger('click');last().reject('');
    check(element('programProspectusRows').html.includes('Choose Refresh to retry'),'Version-list failure has no retry path.');
    $('#btnRefreshProspectuses').trigger('click');list([created]);
    check(element('programProspectusRows').html.includes('900002'),'Version list did not recover after a failure.');
    check(requests.every(request=>request.data.csrf_token===config.csrfToken),'An action lost CSRF protection.');
    const sharedScript=[...html.matchAll(/<script(?:\s[^>]*)?>(.*?)<\/script>/gs)].map(match=>match[1]).find(script=>script.includes('function loadYearSem('));
    const helperStart=sharedScript.indexOf('function loadYearSem(prospectus_id, refreshVersionCounts = true)');
    const helperEnd=sharedScript.indexOf('// DOCUMENT READY',helperStart);
    vm.runInContext('const PROGRAM_CHAIR_BUILDER=true; let yearSemRequestVersion=0; let lastOpenedPys=null;\n'+sharedScript.slice(helperStart,helperEnd),context);
    $('#prospectus_id').val('900002');
    context.loadYearSem('900002');const oldStructure=last();
    context.loadYearSem('900002');last().respond('<p>Latest structure</p>');oldStructure.respond('<p>Old structure</p>');
    check(element('yearSemAccordion').html==='<p>Latest structure</p>','An older subject structure response replaced the latest one.');
    context.loadYearSem('900002');const abandoned=last();$('#prospectus_id').val('');abandoned.respond('<p>Abandoned program</p>');
    check(element('yearSemAccordion').html==='<p>Latest structure</p>','A structure response ignored the cleared prospectus selection.');
    console.log(`Prospectus builder UI logic: ${checks} checks passed using the real rendered Chair page and controller.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
