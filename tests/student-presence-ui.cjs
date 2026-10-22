// Deterministic inactivity-clock checks against the actual Student heartbeat controller.
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../student/presence.js'), 'utf8');
let checks = 0;
const check = (ok, message) => { if (!ok) throw Error(message); checks++; };
const flush = () => new Promise(resolve => setImmediate(resolve));
async function portal() {
  let clock = 0, deadline = 900000, sequence = 0, failed = false, stalled = false;
  const timers = new Map(), events = new Map(), requests = [], redirects = [];
  const document = { visibilityState:'visible', hidden:false,
    getElementById:() => ({textContent:JSON.stringify({csrf_token:'bound-student-csrf',idle_seconds:900})}),
    addEventListener:(type,fn) => events.set(type,fn) };
  const context = { document, URLSearchParams, AbortController, Date:{now:()=>clock},
    location:{replace:url=>redirects.push(url)},window:{addEventListener:()=>{}},
    setTimeout:(fn,ms)=>{const id=++sequence;timers.set(id,{fn,at:clock+ms});return id;},
    clearTimeout:id=>timers.delete(id),
    fetch:async(url,options)=>{
      const body=Object.fromEntries(options.body);requests.push({at:clock,url,options,body});
      if(stalled)await new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(Error('Request aborted')),{once:true}));
      if(failed)throw Error('Network unavailable');
      if(clock>=deadline)return {status:401,json:async()=>({status:'signed_out',reason:'student_idle_timeout'})};
      if(body.action==='activity')deadline=clock+900000;
      return {status:200,json:async()=>({status:'ok',seconds_remaining:Math.floor((deadline-clock)/1000)})};
    }
  };
  vm.runInNewContext(source,context);await flush();
  const advance=async target=>{
    for(;;){const next=[...timers.entries()].sort((a,b)=>a[1].at-b[1].at)[0];if(!next||next[1].at>target)break;clock=next[1].at;timers.delete(next[0]);next[1].fn();await flush();}
    clock=target;await flush();
  };
  return {advance,events,requests,redirects,document,get deadline(){return deadline;},set deadline(value){deadline=value;},set failed(value){failed=value;},set stalled(value){stalled=value;}};
}
(async()=>{
  let p=await portal();
  check(p.requests.length===1&&p.requests[0].body.action==='status','Opening a page checks the session without inventing interaction.');
  await p.advance(899000);
  check(p.redirects.length===0&&p.deadline===900000&&p.requests.every(r=>r.body.action==='status'),'Background polling does not extend the idle deadline.');
  await p.advance(900000);
  check(p.redirects.length===1&&p.redirects[0].includes('student_idle_timeout'),'At the 15-minute deadline the browser follows server-confirmed idle logout.');
  const calls=p.requests.length;await p.advance(1800000);
  check(p.requests.length===calls,'Signed-out pages stop their heartbeat timer.');
  p=await portal();await p.advance(100000);
  for(let i=0;i<100;i++)p.events.get('pointermove')({isTrusted:true});
  await p.advance(105000);
  check(p.requests.filter(r=>r.body.action==='activity').length===1&&p.deadline===1005000,'Real pointer activity is coalesced into one update and extends a valid session.');
  await p.advance(900000);
  check(p.redirects.length===0,'A student remains signed in for 15 minutes after the accepted interaction.');
  await p.advance(1005000);
  check(p.redirects.length===1,'Status requests after the last interaction cannot keep the student signed in indefinitely.');
  p=await portal();await p.advance(100000);p.events.get('keydown')({isTrusted:false});
  p.document.visibilityState='hidden';p.document.hidden=true;p.events.get('pointermove')({isTrusted:true});
  await p.advance(900000);
  check(p.requests.every(r=>r.body.action==='status')&&p.redirects.length===1,'Synthetic events and hidden-tab activity do not reset inactivity.');
  p=await portal();await p.advance(890000);p.deadline=1790000;await p.advance(900000);
  check(p.redirects.length===0&&p.requests.at(-1).body.action==='status','A stale tab checks the shared deadline instead of logging out a session active in another tab.');
  p=await portal();p.failed=true;await p.advance(100000);
  check(p.requests.every(r=>r.body.action==='status')&&p.deadline===900000,'Connection failures never fabricate activity or renew the server deadline.');
  check(p.requests.every(r=>r.body.csrf_token==='bound-student-csrf'&&r.options.credentials==='same-origin'&&r.options.cache==='no-store'),'Heartbeat requests retain CSRF, credentials and private cache behavior.');
  await p.advance(900000);
  check(p.redirects.length===1,'An offline idle page still leaves the protected screen at its last confirmed deadline.');
  p=await portal();await p.advance(100000);p.failed=true;p.events.get('keydown')({isTrusted:true});
  await p.advance(200000);p.failed=false;await p.advance(240000);
  check(p.deadline===900000,'A lost activity report is not replayed minutes later to extend inactivity.');
  p=await portal();p.stalled=true;await p.advance(901000);
  check(p.redirects.length===1,'A stalled request is bounded and cannot prevent idle logout indefinitely.');
  p=await portal();await p.advance(100000);p.events.get('input')({isTrusted:true});await p.advance(105000);
  check(p.deadline===1005000,'Trusted input, including mobile text entry, counts as Student activity.');
  console.log(`Student presence UI: ${checks} checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
