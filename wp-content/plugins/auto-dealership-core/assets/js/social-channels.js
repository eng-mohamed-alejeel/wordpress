(() => {
 'use strict';
 function init(){
  let activeTooltip=null;
  function placeTooltip(link){const box=link.getBoundingClientRect(),style=getComputedStyle(link,'::after'),width=(parseFloat(style.width)||180)+(parseFloat(style.paddingLeft)||0)+(parseFloat(style.paddingRight)||0),height=(parseFloat(style.height)||24)+(parseFloat(style.paddingTop)||0)+(parseFloat(style.paddingBottom)||0);link.style.setProperty('--adc-tooltip-left',Math.max(12,Math.min(innerWidth-width-12,box.left+box.width/2-width/2))+'px');link.style.setProperty('--adc-tooltip-top',(box.top>height+16?box.top-8:box.bottom+height+8)+'px');}
  document.querySelectorAll('.adc-social-link[data-tooltip]').forEach(link=>{link.title=link.dataset.tooltip;const activate=()=>{activeTooltip=link;placeTooltip(link);};link.addEventListener('pointerenter',activate);link.addEventListener('focus',activate);link.addEventListener('pointerleave',()=>{if(document.activeElement!==link)activeTooltip=null;});link.addEventListener('blur',()=>{if(!link.matches(':hover'))activeTooltip=null;});});
  window.addEventListener('resize',()=>{if(activeTooltip)placeTooltip(activeTooltip);},{passive:true});
  window.addEventListener('scroll',()=>{if(activeTooltip)placeTooltip(activeTooltip);},{passive:true,capture:true});
  document.querySelectorAll('[data-social-share]').forEach(root=>{
  const status=root.querySelector('.adc-share-status'),fallback=root.querySelector('.adc-share-fallback');let timer;
  function message(text){clearTimeout(timer);status.textContent=text;if(text===root.dataset.copied)timer=setTimeout(()=>{status.textContent='';},4000);}
  const copy=root.querySelector('[data-share-action="copy"]');copy.hidden=false;
  copy.addEventListener('click',async()=>{
   let copied=false;
   try{if(navigator.clipboard?.writeText&&window.isSecureContext){await navigator.clipboard.writeText(root.dataset.shareUrl);copied=true;}}catch{}
   if(!copied){const active=document.activeElement,area=document.createElement('textarea');area.value=root.dataset.shareUrl;area.setAttribute('readonly','');Object.assign(area.style,{position:'fixed',left:'0',top:'0',width:'1px',height:'1px',opacity:'0'});document.body.append(area);area.focus({preventScroll:true});area.select();try{copied=document.execCommand('copy');}catch{}area.remove();active?.focus({preventScroll:true});}
   fallback.hidden=copied;message(copied?root.dataset.copied:root.dataset.copyFallback);if(!copied){const field=fallback.querySelector('input');field.focus({preventScroll:true});field.select();}
  });
  const share=root.querySelector('[data-share-action="share"]');
  if(typeof navigator.share==='function'){share.hidden=false;share.addEventListener('click',async()=>{share.disabled=true;try{await navigator.share({title:root.dataset.shareTitle,url:root.dataset.shareUrl});}catch(error){if(error.name!=='AbortError')message(root.dataset.shareError);}finally{share.disabled=false;}});}
 });}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
