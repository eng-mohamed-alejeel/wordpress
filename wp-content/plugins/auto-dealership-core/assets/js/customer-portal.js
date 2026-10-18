(() => {
  'use strict';
  if (!window.adcPortal) return;
  const cfg=window.adcPortal, ar=cfg.lang==='ar';
  const errorText=error=>error instanceof TypeError||error instanceof SyntaxError?cfg.failed:error.message||cfg.failed;
  if(location.hash==='#communication-preferences'&&document.querySelector('.adc-portal')){const url=new URL(location.href);if(url.searchParams.get('portal')!=='preferences'){url.searchParams.set('portal','preferences');url.hash='';location.replace(url);return;}}
  document.querySelectorAll('.adc-portal form.adc-p-form').forEach(form=>{
    if(['send','read'].includes(form.elements.operation?.value))return;
    const result=document.createElement('p');result.setAttribute('role','status');result.setAttribute('aria-live','polite');form.append(result);
    form.addEventListener('submit',async event=>{event.preventDefault();const button=form.querySelector('button[type=submit]');if(button.disabled)return;button.disabled=true;result.textContent='';
      try{const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}}),json=await response.json();if(!response.ok||!json.success)throw new Error(json.data?.message||cfg.failed);location.assign(json.data.redirect);}
      catch(error){result.textContent=errorText(error);result.setAttribute('role','alert');button.disabled=false;}
    });
  });
  document.querySelectorAll('.adc-portal form[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm))event.preventDefault();}));
  const root=document.querySelector('[data-thread]'); if(!root)return;
  const history=root.querySelector('.adc-p-messages'), status=root.querySelector('.adc-p-poll-status');
  let after=Number(root.dataset.after)||0, failures=0, timer=0, busy=false, more=!!root.querySelector('[data-load-more]'), lastRead=0, followOwnMessage=false;
  const ids=new Set();history.querySelectorAll('[data-message-id]').forEach(item=>{ids.add(Number(item.dataset.messageId));after=Math.max(after,Number(item.dataset.messageId));});
  async function post(form,data){const response=await fetch(form.getAttribute('action'),{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});const json=await response.json();if(!response.ok||!json.success)throw new Error(json.data?.message||cfg.failed);return json.data;}
  function notice(message,error=false){status.textContent=message;status.setAttribute('role',error?'alert':'status');}
  function addMessage(message){
    if(ids.has(Number(message.id)))return;ids.add(Number(message.id));
    const article=document.createElement('article');article.className='adc-p-message'+(message.mine?' is-mine':'')+(message.visibility==='internal'?' is-internal':'');article.dataset.messageId=String(message.id);article.dataset.visibility=message.visibility;
    const head=document.createElement('header'),name=document.createElement('strong'),time=document.createElement('time'),body=document.createElement('p');name.textContent=message.sender_label;time.textContent=message.created_at+' UTC';head.append(name,time);
    if(message.visibility==='internal'){const flag=document.createElement('span');flag.textContent=ar?'ملاحظة داخلية':'Internal note';head.append(flag);}
    // Server text is never interpreted as HTML.
    body.textContent=message.body;body.style.whiteSpace='pre-wrap';article.append(head,body);history.append(article);
  }
  function markRead(){
    const form=root.querySelector('form input[name=operation][value=read]')?.form;if(!form||document.hidden)return;
    const visible=[...history.querySelectorAll('[data-message-id][data-visibility=public]')].reduce((max,node)=>Math.max(max,Number(node.dataset.messageId)),0);if(!visible)return;
    if(visible<=lastRead)return;const data=new FormData(form);data.set('last',String(visible));post(form,data).then(()=>{lastRead=visible;}).catch(()=>{});
  }
  function schedule(){clearTimeout(timer);timer=window.setTimeout(()=>poll(),Math.min(120000,4000*Math.pow(2,failures)));}
  async function poll(manual=false){
    if(busy||document.hidden){schedule();return;}if(more&&!manual){schedule();return;}
    busy=true;try{
      const url=new URL(cfg.ajax);url.search=new URLSearchParams({action:'adc_portal_poll',nonce:cfg.pollNonce,lang:cfg.lang,id:root.dataset.thread,after:String(after)}).toString();
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store'}),json=await response.json();if(!response.ok||!json.success)throw new Error(json.data?.message||cfg.failed);
      const data=json.data,nearBottom=history.scrollHeight-history.scrollTop-history.clientHeight<90;let added=0;
      for(const message of data.messages){if(!ids.has(Number(message.id)))added++;addMessage(message);after=Math.max(after,Number(message.id));}
      root.querySelectorAll('input[name=revision]').forEach(input=>{input.value=String(data.revision);});
      const labels={staff:ar?'مفتوحة':'Open',customer:ar?'مفتوحة':'Open',closed:ar?'مغلقة':'Closed'};
      const badge=root.querySelector('[data-thread-status] .adc-p-badge');if(badge){badge.dataset.state=data.status;badge.textContent=labels[data.status]||data.status;}
      const composer=root.querySelector('.adc-p-composer');if(composer)composer.hidden=data.status==='closed'||!data.can_reply;
      const stateForm=[...root.querySelectorAll('form')].find(form=>['close','reopen'].includes(form.elements.operation?.value));if(stateForm){stateForm.elements.operation.value=data.status==='closed'?'reopen':'close';stateForm.querySelector('button').textContent=data.status==='closed'?(ar?'إعادة فتح المحادثة':'Reopen conversation'):(ar?'إغلاق المحادثة':'Close conversation');}
      more=data.has_more;let load=root.querySelector('[data-load-more]');if(more&&!load){load=document.createElement('button');load.type='button';load.className='adc-p-button is-secondary';load.dataset.loadMore='';load.textContent=cfg.more;history.after(load);}if(load)load.hidden=!more;
      if(added){notice(cfg.new);if(nearBottom||followOwnMessage)history.scrollTop=history.scrollHeight;followOwnMessage=false;}else if(failures)notice('');failures=0;
      if(!more)markRead();
    }catch(error){failures++;notice(errorText(error),true);}finally{busy=false;schedule();}
  }
  root.addEventListener('click',event=>{const load=event.target.closest('[data-load-more],[data-refresh-thread]');if(load){event.preventDefault();poll(true);}});
  root.querySelectorAll('form').forEach(form=>{
    const operation=form.elements.operation?.value;if(!['send','read'].includes(operation))return;
    form.addEventListener('submit',async event=>{
      event.preventDefault();const button=form.querySelector('button');if(button?.disabled)return;button.disabled=true;notice('');
      try{await post(form,new FormData(form));if(operation==='send'){followOwnMessage=true;form.elements.body.value='';form.elements.request_key.value=crypto.randomUUID();if(form.elements.internal)form.elements.internal.checked=false;await poll(true);}else notice(cfg.saved);}
      catch(error){notice(errorText(error),true);}finally{button.disabled=false;}
    });
  });
  document.addEventListener('visibilitychange',()=>{if(document.hidden)clearTimeout(timer);else poll();});
  // Keep the start of paginated history readable; polling never skips unseen pages.
  if(!more){history.scrollTop=history.scrollHeight;markRead();}schedule();
})();
