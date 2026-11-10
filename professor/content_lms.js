(function () {
  'use strict';
  const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function inlineCode(value) {
    const pattern=/(`+)([^`\n]+)\1(?!`)/g;let html='',offset=0;
    for(const match of value.matchAll(pattern)){html+=escape(value.slice(offset,match.index))+'<code class="assessment-inline-code">'+escape(match[2])+'</code>';offset=match.index+match[0].length;}
    return html+escape(value.slice(offset));
  }
  function formatQuestion(value) {
    const lines=String(value).replace(/\r\n?/g,'\n').split('\n');let html='',text=[],code=[],fence='',language='';
    const flushText=()=>{if(text.length)html+='<div class="assessment-formatted-text">'+inlineCode(text.join('\n'))+'</div>';text=[];};
    const flushCode=()=>{html+='<figure class="assessment-code-block"><figcaption>'+escape(language||'Code')+'</figcaption><pre tabindex="0" aria-label="Code example"><code>'+escape(code.join('\n'))+'</code></pre></figure>';code=[];};
    for(const line of lines){
      if(fence){const close=line.match(/^ {0,3}(`{3,})[ \t]*$/);if(close&&close[1].length>=fence.length){flushCode();fence='';}else code.push(line);}
      else{const open=line.match(/^ {0,3}(`{3,})([a-zA-Z0-9_+#.-]{0,30})[ \t]*$/);if(open){flushText();fence=open[1];language=open[2];}else text.push(line);}
    }
    if(fence)flushCode();flushText();return html;
  }
  window.synkLmsContent={escape,formatQuestion};
})();
