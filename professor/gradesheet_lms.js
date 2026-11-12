(function () {
  'use strict';
  const button=document.getElementById('gsPrint'),status=document.getElementById('gsPrintStatus'),preview=document.querySelector('.gs-preview');
  if(!button||!preview)return;
  function paginate(){
    const source=preview.querySelector('.gs-paper');
    const students=[...source.querySelectorAll('[data-student-id]')];
    const summary=[...source.querySelectorAll('.gs-summary')];
    const notes=[...source.querySelectorAll('.gs-signatures')];
    const completion=source.querySelector('.gs-completion');
    [...students,...summary,...notes,completion].forEach(node=>node.remove());
    const template=source.cloneNode(true),papers=[];
    preview.replaceChildren();
    function addPaper(){
      const paper=template.cloneNode(true),first=papers.length===0;
      paper.classList.toggle('gs-first-page',first);paper.classList.remove('gs-last-page');
      if(!first)paper.querySelector('.gs-intro').remove();
      preview.append(paper);papers.push(paper);return paper;
    }
    const bodyOf=paper=>paper.querySelector('.gs-grades tbody');
    const rect=(paper,selector)=>paper.querySelector(selector).getBoundingClientRect();
    // Leave a small physical gap above the institutional footer. Table widths and
    // fonts are identical on screen and in print, including wrapped student names.
    const rowsFit=paper=>rect(paper,'.gs-grades').bottom<=rect(paper,'.gs-footer').top-12;
    const endingFits=paper=>rect(paper,'.gs-body').bottom<=Math.min(rect(paper,'.gs-legend').top,rect(paper,'.gs-approved').top)-8;
    let paper=addPaper();
    for(const row of students){
      bodyOf(paper).append(row);
      if(rowsFit(paper))continue;
      row.remove();
      if(!bodyOf(paper).children.length)throw Error('A student row is too tall to fit on a grade sheet.');
      paper=addPaper();bodyOf(paper).append(row);
      if(!rowsFit(paper))throw Error('A student row is too tall to fit on a grade sheet.');
    }
    const blank=document.createElement('tr');blank.className='gs-blank';blank.setAttribute('aria-hidden','true');
    for(let column=0;column<7;column++)blank.append(document.createElement('td'));
    function attachEnding(target){
      bodyOf(target).append(blank,...summary);target.querySelector('.gs-body').append(...notes);target.append(completion);
      target.classList.add('gs-last-page');
    }
    function detachEnding(target){
      [blank,...summary,...notes,completion].forEach(node=>node.remove());target.classList.remove('gs-last-page');
    }
    attachEnding(paper);
    if(!endingFits(paper)){
      // Keep the last student with the one blank separator and summary when the
      // closing section needs another sheet. Earlier student rows retain order.
      detachEnding(paper);
      const lastStudent=bodyOf(paper).querySelector('[data-student-id]:last-child');
      paper=addPaper();if(lastStudent)bodyOf(paper).append(lastStudent);attachEnding(paper);
      if(!endingFits(paper))throw Error('The grade sheet signing section does not fit.');
    }
    papers.forEach((item,index)=>{
      const label='Sheet '+(index+1)+' of '+papers.length;
      item.setAttribute('aria-label','Grade sheet '+(index+1)+' of '+papers.length);
      item.querySelector('.gs-grades').setAttribute('aria-label','Student grades, sheet '+(index+1));
      item.querySelector('.gs-page-number').textContent=label;
    });
    document.getElementById('gsSheetCount').textContent=papers.length+' '+(papers.length===1?'sheet':'sheets');
    preview.setAttribute('aria-busy','false');
  }
  async function prepare(){
    try{
      await document.fonts.ready;
      paginate();
      await Promise.all([...document.images].map(image=>{
        if(image.complete){
          if(!image.naturalWidth)throw Error('A letterhead image could not be loaded.');
          return Promise.resolve();
        }
        return new Promise((resolve,reject)=>{
          image.addEventListener('load',resolve,{once:true});
          image.addEventListener('error',()=>reject(Error('A letterhead image could not be loaded.')),{once:true});
        });
      }));
      button.disabled=false;button.textContent='Print grade sheet';
    }catch(error){
      status.textContent='The grade sheet could not be prepared. Reload this page before printing. '+error.message;
      button.textContent='Reload to print';
    }
  }
  button.addEventListener('click',()=>window.print());
  prepare();
})();
