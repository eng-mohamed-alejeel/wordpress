(() => {
 'use strict';
 function init(root) {
  if(root.dataset.socialReady)return;root.dataset.socialReady='1';
  const input=root.querySelector('[data-social-preferences]'),rows=root.querySelector('[data-social-rows]');
  let dragging=null,dropTarget=null,dropAfter=false;
  function clearDrop(){rows.querySelectorAll('.is-drop-before,.is-drop-after').forEach(row=>row.classList.remove('is-drop-before','is-drop-after'));}
  function update(){const value={style:root.querySelector('[data-social-style]').value,order:[],channels:{}};rows.querySelectorAll('[data-social-key]').forEach(row=>{const key=row.dataset.socialKey;value.order.push(key);value.channels[key]={};row.querySelectorAll('[data-social-flag]').forEach(flag=>value.channels[key][flag.dataset.socialFlag]=flag.checked);});input.value=JSON.stringify(value);input.dispatchEvent(new Event('change',{bubbles:true}));syncButtons();}
  function syncButtons(){const items=[...rows.children];items.forEach((row,index)=>{row.querySelector('[data-social-move="-1"]').disabled=index===0;row.querySelector('[data-social-move="1"]').disabled=index===items.length-1;});}
  function syncValue(value){try{const prefs=typeof value==='string'?JSON.parse(value):value;if(!prefs?.order||!prefs.channels)return;prefs.order.forEach((key,index)=>{const row=rows.querySelector('[data-social-key="'+key+'"]');if(!row)return;if(rows.children[index]!==row)rows.insertBefore(row,rows.children[index]||null);row.querySelectorAll('[data-social-flag]').forEach(flag=>flag.checked=!!prefs.channels[key]?.[flag.dataset.socialFlag]);});root.querySelector('[data-social-style]').value=prefs.style;input.value=JSON.stringify(prefs);syncButtons();}catch{}}
  root.addEventListener('change',event=>{if(event.target!==input)update();});
  root.addEventListener('click',event=>{const button=event.target.closest('[data-social-move]');if(!button)return;const row=button.closest('[data-social-key]'),delta=Number(button.dataset.socialMove),neighbor=delta<0?row.previousElementSibling:row.nextElementSibling;if(!neighbor)return;if(delta<0)rows.insertBefore(row,neighbor);else rows.insertBefore(neighbor,row);update();row.querySelector(delta<0?'[data-social-move="1"]':'[data-social-move="-1"]').focus({preventScroll:true});});
  rows.addEventListener('dragstart',event=>{if(event.target.closest('input,select,button,label')){event.preventDefault();return;}dragging=event.target.closest('[data-social-key]');if(dragging){event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',dragging.dataset.socialKey);dragging.classList.add('is-dragging');}});
  rows.addEventListener('dragover',event=>{if(!dragging)return;const target=event.target.closest('[data-social-key]');if(!target||target===dragging)return;event.preventDefault();event.dataTransfer.dropEffect='move';const rect=target.getBoundingClientRect();dropTarget=target;dropAfter=event.clientY>=rect.top+rect.height/2;clearDrop();target.classList.add(dropAfter?'is-drop-after':'is-drop-before');});
  rows.addEventListener('drop',event=>{if(!dragging||!dropTarget)return;event.preventDefault();rows.insertBefore(dragging,dropAfter?dropTarget.nextSibling:dropTarget);clearDrop();update();});
  rows.addEventListener('dragend',()=>{clearDrop();if(dragging){dragging.classList.remove('is-dragging');dragging=null;dropTarget=null;}syncButtons();});
  syncButtons();
  if(typeof window.wp?.customize==='function'&&input.hasAttribute('data-customize-setting-link')){window.wp.customize(input.getAttribute('data-customize-setting-link'),setting=>{syncValue(setting.get());setting.bind(syncValue);});}
 }
 function scan(){document.querySelectorAll('.adc-social-settings').forEach(init);}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});else scan();
 new MutationObserver(records=>{if(records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1&&(node.matches?.('.adc-social-settings')||node.querySelector?.('.adc-social-settings')))))scan();}).observe(document.documentElement,{childList:true,subtree:true});
})();
