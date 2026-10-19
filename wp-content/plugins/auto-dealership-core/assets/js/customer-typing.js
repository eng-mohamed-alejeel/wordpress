/* Transient typing state. Draft text never leaves this module. */
(() => {
  'use strict';
  const cfg=window.adcChat;
  if(!cfg?.logged)return;
  const ar=cfg.lang==='ar',tab=crypto.randomUUID(),bound=new WeakSet();
  let active=null,lastInput=0,sequence=0,lastSignal=0,idleTimer=0,renewTimer=0;
  let statusTimer=0,statusBusy=false,statusFailures=0,expiryTimer=0,lastPresence=null;
  const conversation=()=>document.querySelector('.adc-p-conversation[data-thread]')||document.querySelector('.adc-chat[data-thread]');
  const visible=root=>!!root&&!document.hidden&&(!root.classList.contains('adc-chat')||!root.querySelector('.adc-chat-panel')?.hidden);
  const composer=root=>root?.querySelector('textarea[name=body]');
  function writable(input){
    const root=conversation();return input&&visible(root)&&document.activeElement===input&&!input.disabled&&!input.closest('[hidden]')&&!!input.value.trim()&&!root.querySelector('[name=internal]')?.checked;
  }
  function payload(root,typing,ttl=0){
    const data=new FormData();Object.entries({action:'adc_live_status',operation:'typing',nonce:cfg.nonce,portal_scope:cfg.scope||'personal',thread:root.dataset.thread,tab,seq:++sequence,typing:typing?'1':'0',visible:document.hidden?'0':'1',ttl:Math.max(0,Math.ceil(ttl)),lang:cfg.lang}).forEach(([key,value])=>data.set(key,String(value)));return data;
  }
  function signal(root,typing,ttl=0,beacon=false){
    if(!root?.dataset.thread)return;
    const data=payload(root,typing,ttl);lastSignal=performance.now();
    if(beacon&&navigator.sendBeacon?.(cfg.ajax,data))return;
    fetch(cfg.ajax,{method:'POST',body:data,credentials:'same-origin',cache:'no-store',keepalive:!typing}).catch(()=>{});
  }
  function stop(beacon=false){
    clearTimeout(idleTimer);clearTimeout(renewTimer);
    if(active){const root=active.root;active=null;signal(root,false,0,beacon);}
  }
  function renew(){
    clearTimeout(renewTimer);if(!active)return;
    const remaining=2000-(performance.now()-lastInput);
    if(remaining<=0||!writable(active.input)){stop();return;}
    signal(active.root,true,remaining);renewTimer=setTimeout(renew,Math.min(1000,remaining));
  }
  function changed(input){
    if(!writable(input)){stop();return;}
    const root=conversation(),starting=!active||active.input!==input;
    if(active&&active.input!==input)stop();lastInput=performance.now();active={root,input};
    clearTimeout(idleTimer);idleTimer=setTimeout(()=>stop(),2000);
    if(starting||performance.now()-lastSignal>=1000)renew();
  }
  function render(presence){
    const root=conversation();if(!visible(root))return;
    const node=root.querySelector('.adc-chat-peer');if(!node)return;
    clearTimeout(expiryTimer);lastPresence=presence;
    node.textContent=presence.typing?(ar?'يكتب الآن…':'Typing…'):presence.online?(ar?'متصل الآن':'Online now'):(ar?'غير متصل حاليًا؛ ستصله رسالتك للمتابعة.':'Currently offline; your message will be saved for follow-up.');
    node.classList.toggle('is-online',!!presence.online);node.classList.toggle('is-typing',!!presence.typing);
    if(presence.typing){expiryTimer=setTimeout(()=>render({...presence,typing:false}),Math.max(1,Math.min(2000,Number(presence.typing_ms)||2000)));}
  }
  function schedule(){
    clearTimeout(statusTimer);if(!visible(conversation())||navigator.onLine===false)return;
    statusTimer=setTimeout(poll,Math.min(15000,1000*Math.pow(2,statusFailures)));
  }
  async function poll(){
    const root=conversation();if(!visible(root)||statusBusy||navigator.onLine===false)return;
    statusBusy=true;
    try{
      const url=new URL(cfg.ajax);url.search=new URLSearchParams({action:'adc_live_status',nonce:cfg.nonce,portal_scope:cfg.scope||'personal',thread:root.dataset.thread,lang:cfg.lang});
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store'}),json=await response.json();
      if(!response.ok||!json.success)throw Error('status unavailable');
      statusFailures=0;if(root===conversation()&&visible(root))render(json.data);
    }catch{statusFailures++;/* The last server lease still expires locally during a network failure. */}
    finally{statusBusy=false;schedule();}
  }
  function bind(){
    const root=conversation(),input=composer(root);if(!input||bound.has(input))return;
    bound.add(input);input.addEventListener('input',()=>changed(input));input.addEventListener('blur',()=>stop());
    input.form?.addEventListener('submit',()=>stop(),true);
    root.querySelector('[name=internal]')?.addEventListener('change',()=>stop());
    new MutationObserver(()=>{if(active&&!writable(active.input))stop();}).observe(root,{subtree:true,attributes:true,attributeFilter:['hidden','disabled']});
    poll();
  }
  // The floating composer is created after the customer selects a branch.
  const observer=new MutationObserver(()=>{bind();if(!visible(conversation())){stop();clearTimeout(statusTimer);}else if(!statusTimer&&!statusBusy)poll();});
  observer.observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
  document.addEventListener('visibilitychange',()=>{if(document.hidden){stop(true);clearTimeout(statusTimer);statusTimer=0;}else{poll();}});
  window.addEventListener('pagehide',()=>{stop(true);clearTimeout(statusTimer);});
  window.addEventListener('offline',()=>{stop();clearTimeout(statusTimer);statusTimer=0;});
  window.addEventListener('online',()=>poll());
  document.addEventListener('click',event=>{if(event.target.closest('[data-chat-close],.adc-chat-launch')){queueMicrotask(()=>{if(!visible(conversation())){stop();clearTimeout(statusTimer);statusTimer=0;}else poll();});}});
  bind();
})();
