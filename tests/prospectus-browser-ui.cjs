// Run: node tests/prospectus-browser-ui.cjs
// Exercise the rendered page's filter/request logic without changing a live browser or records.
const {execFileSync} = require('node:child_process');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
let checks = 0;
function check(condition, message) { if (!condition) throw Error(message); checks++; }

function page(scenario) {
    const fixture = JSON.parse(execFileSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',
        [path.join(root, 'tests/fixtures/program-chair-curriculum-request.php'), scenario], {encoding:'utf8'}));
    const html = fixture.response;
    const script = [...html.matchAll(/<script(?:\s[^>]*)?>(.*?)<\/script>/gs)].map(item=>item[1]).find(item=>item.includes('const CSRF_TOKEN'));
    const elements = new Map([...html.matchAll(/id="([^"]+)"/g)].map(item=>[item[1], {id:item[1],value:'',options:[],events:{},attrs:{},disabled:false,html:'',text:''}]));
    const requests = [], document = {};
    class Option { constructor(text,value,defaultSelected=false,selected=false) { Object.assign(this,{text,value:String(value),selected,attrs:{}}); } }
    class Selection {
        constructor(items) { this.items=items; this.length=items.length; }
        select2() { return this; }
        each(callback) { this.items.forEach(item=>callback.call(item)); return this; }
        on(event,selector,handler) {
            if (typeof selector==='function') this.items.forEach(item=>item.events[event]=selector);
            return this;
        }
        trigger(event) { this.items.forEach(item=>item.events[event]?.call(item)); return this; }
        empty() { this.items.forEach(item=>{item.value='';item.options=[];item.html='';}); return this; }
        append(value) {
            this.items.forEach(item=>{
                if (value instanceof Selection) value.items.forEach(option=>this.appendOption(item,option));
                else if (value instanceof Option) this.appendOption(item,value);
                else item.html+=String(value);
            });
            return this;
        }
        appendOption(item,option) { item.options.push(option); if (item.options.length===1) item.value=option.value; }
        val(value) { if (value===undefined) return this.items[0]?.value ?? null; this.items.forEach(item=>item.value=String(value));return this; }
        prop(key,value) { if(value===undefined)return this.items[0]?.[key];this.items.forEach(item=>item[key]=value);return this; }
        attr(key,value) { if(value===undefined)return this.items[0]?.attrs[key];this.items.forEach(item=>item.attrs[key]=value);return this; }
        text(value) { if(value===undefined)return this.items[0]?.text;this.items.forEach(item=>item.text=String(value));return this; }
        html(value) { if(value===undefined)return this.items[0]?.html;this.items.forEach(item=>item.html=String(value));return this; }
        find(selector) { return new Selection(selector==='option'?this.items.flatMap(item=>item.options):[]); }
        toArray() { return this.items; }
    }
    function $(value) {
        if (value===document) return new Selection([]);
        if (typeof value!=='string') return new Selection(value?[value]:[]);
        return new Selection(value.split(',').map(key=>elements.get(key.trim().replace(/^#/,''))).filter(Boolean));
    }
    const prefilters=[];
    $.ajaxPrefilter=callback=>prefilters.push(callback);
    $.isPlainObject=value=>value!==null&&typeof value==='object'&&!Array.isArray(value);
    $.post=(url,data,success)=>{
        const options={type:'POST',data};prefilters.forEach(callback=>callback(options));
        const request={url,data:options.data,success,aborted:false,doneCallbacks:[],failCallbacks:[],
            done(callback){this.doneCallbacks.push(callback);return this;},
            fail(callback){this.failCallbacks.push(callback);return this;},
            abort(){this.aborted=true;this.failCallbacks.forEach(callback=>callback({},'abort'));},
            respond(result){this.success?.(result);this.doneCallbacks.forEach(callback=>callback(result));}};
        requests.push(request);return request;
    };
    const context=vm.createContext({$,document,Option,Swal:{fire(){throw Error('Unexpected alert');}}});
    vm.runInContext(script,context);
    return {fixture,elements,requests,context,$,run:code=>vm.runInContext(code,context),filters:vm.runInContext('browserFilters',context)};
}

const admin=page('page_admin_browser');
const own=admin.filters.programs.find(item=>Number(item.program_id)===admin.fixture.own_program);
const target=admin.filters.programs.find(item=>Number(item.program_id)===admin.fixture.target_program);
check(admin.requests.length===0,'Administrator loaded rows before a college was chosen.');
admin.$('#filterCampus').val(own.campus_id).trigger('change');
const allowedColleges=admin.filters.colleges.filter(item=>String(item.campus_id)===String(own.campus_id));
check(admin.elements.get('filterCollege').options.length===allowedColleges.length+1,'Campus did not restrict college options.');
check(admin.elements.get('filterProgram').disabled,'Program remained enabled before college selection.');
admin.$('#filterCollege').val(own.college_id).trigger('change');
let request=admin.requests.at(-1);
check(request.url.endsWith('/query_prospectus_browser.php')&&request.data.load_browser_prospectuses===1&&request.data.program_id==='','College selection did not request all programs.');
check(String(request.data.campus_id)===String(own.campus_id)&&String(request.data.college_id)===String(own.college_id),'Wrong campus or college request.');
check(request.data.csrf_token==='curriculum-qa','Request lost the existing CSRF protection.');
check(admin.elements.get('filterProgram').options.some(item=>item.value===String(target.program_id)&&item.text.endsWith('0 curricula')),'Zero-curriculum programs or count labels disappeared.');
const rows=[{...own,prospectus_id:701,cmo_no:'CMO A',effective_sy:'2026-2027',yearsem_count:1,subject_count:2,total_units:7},
    {...target,program_name:'Target <img src=x onerror=alert(1)>',prospectus_id:702,cmo_no:'Empty CMO',effective_sy:'2027-2028',yearsem_count:0,subject_count:0,total_units:0}];
request.respond(rows);
let rendered=admin.elements.get('prospectusTable').html;
check(rendered.includes('pid=701')&&rendered.includes('pid=702'),'College view lost one of its prospectus actions.');
check(rendered.includes(`data-program-id="${own.program_id}"`)&&rendered.includes(`data-program-id="${target.program_id}"`),'Actions used the blank program filter instead of their own row.');
check(rendered.includes('&lt;img')&&!rendered.includes('<img'),'Program labels were inserted without escaping.');
check(admin.elements.get('browserResultsSummary').text==='2 curricula across 2 programs','College summary counts are incorrect.');
check(admin.elements.get('filterProgram').options.some(item=>item.value===String(target.program_id)&&item.text.endsWith('1 curriculum')),'Counts were not refreshed when an empty curriculum appeared.');
admin.$('#filterProgram').val(own.program_id).trigger('change');
const stale=admin.requests.at(-1);
check(String(stale.data.program_id)===String(own.program_id),'Optional program filter was not sent.');
admin.$('#filterProgram').val(target.program_id).trigger('change');
admin.requests.at(-1).respond([]);
stale.respond(rows);
check(stale.aborted&&admin.elements.get('prospectusTable').html.includes('No prospectus found for this program'),'An old response replaced the selected program results.');
admin.$('#filterCampus').val('').trigger('change');
check(admin.elements.get('filterCollege').value===''&&admin.elements.get('filterCollege').disabled&&admin.elements.get('filterProgram').disabled,'Clearing campus retained a previous college/program.');
check(admin.elements.get('browserResultsSummary').text==='','Clearing campus retained stale summary counts.');
const foreign=admin.filters.programs.find(item=>Number(item.program_id)===admin.fixture.foreign_program);
const refreshed=structuredClone(admin.filters);
refreshed.programs.find(item=>String(item.program_id)===String(foreign.program_id)).curriculum_count=2;
admin.run(`refreshBrowserResults(${foreign.program_id})`);
check(admin.requests.at(-1).data.load_browser_filters===1,'A saved change did not request refreshed filters/counts.');
admin.requests.at(-1).respond(refreshed);
request=admin.requests.at(-1);
check(String(admin.elements.get('filterCampus').value)===String(foreign.campus_id)&&String(admin.elements.get('filterCollege').value)===String(foreign.college_id),'Copy/transfer did not switch to the destination campus and college.');
check(String(request.data.program_id)===String(foreign.program_id)&&String(request.data.college_id)===String(foreign.college_id),'Copy/transfer loaded the wrong destination program.');
check(admin.elements.get('filterProgram').options.some(item=>item.value===String(foreign.program_id)&&item.text.endsWith('2 curricula')),'A saved change did not refresh destination counts.');

const chair=page('page_browser');
check(!chair.elements.has('filterCampus')&&!chair.elements.has('filterCollege'),'Chair page contains administrator filters.');
request=chair.requests.at(-1);
check(chair.requests.length===1&&request.data.load_browser_prospectuses===1&&request.data.program_id==='','Chair did not automatically load its college.');
check(!('campus_id' in request.data)&&!('college_id' in request.data),'Chair browsing sent a client-selected college or campus.');
check(request.url.endsWith('/query_program_chair_prospectus_browser.php'),'Chair used the administrator endpoint.');
check(chair.filters.programs.every(item=>Number(item.college_id)===chair.fixture.college_id),'Chair initial data contains another college.');
request.respond([]);
check(chair.elements.get('prospectusTable').html.includes('No prospectuses found for this college'),'Empty college message is incorrect.');
chair.$('#filterProgram').val(chair.fixture.own_program).trigger('change');
check(String(chair.requests.at(-1).data.program_id)===String(chair.fixture.own_program),'Chair program selection failed.');
console.log(`Prospectus browser UI logic: ${checks} checks passed using rendered Administrator and Chair pages.`);
