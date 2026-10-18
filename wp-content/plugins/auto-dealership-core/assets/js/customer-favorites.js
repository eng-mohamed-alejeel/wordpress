(() => {
  'use strict';
  const pending=new Set(),ar=document.documentElement.lang.startsWith('ar');
  let noticeTimer;
  function announce(message,error=false){
    let node=document.querySelector('.adc-favorite-feedback');
    if(!node){node=document.createElement('div');node.className='adc-favorite-feedback';document.body.append(node);}
    clearTimeout(noticeTimer);node.setAttribute('role',error?'alert':'status');node.textContent=message;node.hidden=false;
    noticeTimer=setTimeout(()=>{node.hidden=true;},error?6000:3000);
  }
  const controls=car=>[...document.querySelectorAll('[data-favorite-car]')].filter(button=>button.dataset.favoriteCar===car);
  document.addEventListener('submit',async event=>{
    const form=event.target,button=form.querySelector?.('[data-favorite-car]');if(!button)return;
    event.preventDefault();const car=button.dataset.favoriteCar;if(pending.has(car))return;
    pending.add(car);controls(car).forEach(node=>{node.disabled=true;node.setAttribute('aria-busy','true');});
    try{
      const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
      if(response.redirected&&new URL(response.url).origin===location.origin){location.assign(response.url);return;}
      if(!response.headers.get('content-type')?.includes('application/json'))throw Error(ar?'تعذر تحديث المفضلة. حدّث الصفحة ثم حاول مجددًا.':'Could not update favorites. Refresh the page and try again.');
      const json=await response.json();if(!response.ok||!json.success)throw Error(json.data?.message||(ar?'تعذر تحديث المفضلة. أعد المحاولة.':'Could not update favorites. Please try again.'));
      const saved=json.data.saved===true;
      controls(car).forEach(node=>{node.setAttribute('aria-pressed',String(saved));const label=saved?node.dataset.labelRemove:node.dataset.labelSave;node.setAttribute('aria-label',label);node.title=label;node.form.elements.save.value=saved?'0':'1';});
      announce(json.data.message);
    }catch(error){const fallback=ar?'تعذر الاتصال. أعد المحاولة.':'Connection failed. Please try again.';announce(error instanceof TypeError||error instanceof SyntaxError?fallback:error.message||fallback,true);}
    finally{pending.delete(car);controls(car).forEach(node=>{node.disabled=false;node.removeAttribute('aria-busy');});}
  });
})();
