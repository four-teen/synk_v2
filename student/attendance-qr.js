'use strict';
(() => {
  const config=JSON.parse(document.getElementById('student-attendance-config').textContent);
  const target=document.getElementById('student-attendance-qr'),error=document.getElementById('student-qr-error'),retry=document.getElementById('student-qr-retry');
  const save=document.getElementById('student-qr-save');
  let imageUrl='';

  function wrapText(context,text,maxWidth) {
    const lines=[];
    let line='';
    for(const word of String(text).split(/\s+/u)) {
      const next=line ? line+' '+word : word;
      if(context.measureText(next).width<=maxWidth) { line=next;continue; }
      if(line) { lines.push(line);line=''; }
      // Long names are wrapped without dropping characters or shrinking the QR.
      for(const character of Array.from(word)) {
        if(line && context.measureText(line+character).width>maxWidth) { lines.push(line);line=''; }
        line+=character;
      }
    }
    if(line)lines.push(line);
    return lines;
  }

  async function prepareDownload(code) {
    const canvas=document.createElement('canvas'),context=canvas.getContext('2d');
    canvas.width=1080;
    context.font='600 36px Arial, sans-serif';
    const names=wrapText(context,config.full_name,920);
    const moduleCount=code.getModuleCount(),scale=Math.floor(900/(moduleCount+8));
    const qrSize=(moduleCount+8)*scale,qrTop=242+names.length*48;
    canvas.height=qrTop+qrSize+100;
    context.fillStyle='#fff';context.fillRect(0,0,canvas.width,canvas.height);
    context.textAlign='center';context.textBaseline='top';
    context.fillStyle='#696cff';context.font='600 30px Arial, sans-serif';
    context.fillText('SYNK STUDENT · MY QR',540,56);
    context.fillStyle='#566579';context.font='24px Arial, sans-serif';
    context.fillText('FULL NAME',540,126);
    context.fillStyle='#1f2937';context.font='600 36px Arial, sans-serif';
    names.forEach((line,index)=>context.fillText(line,540,168+index*48));
    context.font='30px Arial, sans-serif';
    context.fillText('Student ID: '+config.student_number,540,184+names.length*48);
    // Integer pixels and a four-module white border preserve scan readability.
    const left=Math.floor((canvas.width-qrSize)/2)+4*scale,top=qrTop+4*scale;
    context.fillStyle='#000';
    for(let row=0;row<moduleCount;row++)for(let col=0;col<moduleCount;col++) {
      if(code.isDark(row,col))context.fillRect(left+col*scale,top+row*scale,scale,scale);
    }
    context.fillStyle='#566579';context.font='24px Arial, sans-serif';
    context.fillText('Show to Management for event attendance. Keep this QR private.',540,qrTop+qrSize+28);
    const blob=await new Promise((resolve,reject)=>canvas.toBlob(value=>value ? resolve(value) : reject(Error('Unable to prepare the QR image.')),'image/png'));
    if(imageUrl)URL.revokeObjectURL(imageUrl);
    imageUrl=URL.createObjectURL(blob);save.href=imageUrl;
    save.download='Synk-QR-'+String(config.student_number).replace(/[^a-zA-Z0-9_-]/g,'')+'.png';
    save.setAttribute('aria-describedby','student-qr-save-help');save.hidden=false;
  }

  async function load() {
    retry.disabled=true;retry.hidden=true;error.hidden=true;save.hidden=true;
    try {
      const response=await fetch(config.endpoint,{method:'POST',credentials:'same-origin',body:new URLSearchParams({csrf_token:config.csrf_token}),headers:{Accept:'application/json'}});
      const data=await response.json();if (!response.ok || data.status!=='ok') throw Error(data.message || 'Unable to load your QR code.');
      const code=qrcode(0,'M');code.addData(data.qr_code);code.make();
      target.innerHTML=code.createSvgTag({cellSize:8,margin:32,scalable:true});
      try { await prepareDownload(code); }
      catch(e) { error.textContent='Your QR is ready to scan, but the download could not be prepared. Tap Try again.';error.hidden=false;retry.hidden=false; }
    } catch (e) { target.textContent='QR unavailable';error.textContent=e.message;error.hidden=false;retry.hidden=false; }
    finally { retry.disabled=false; }
  }
  window.addEventListener('pagehide',event=>{if(!event.persisted && imageUrl)URL.revokeObjectURL(imageUrl);});
  retry.addEventListener('click',load);load();
})();
