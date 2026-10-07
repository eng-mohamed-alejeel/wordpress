(function () {
 'use strict';
 function renumber(program) {
  program.querySelectorAll('[data-finance-rules] > [data-finance-rule]').forEach(function (rule,index) {
   rule.querySelector('[data-rule-number]').textContent=index+1;
   rule.querySelectorAll('[name]').forEach(function(input){input.name=input.name.replace(/\[rules\]\[[^\]]+\]/,'[rules]['+index+']');});
  });
 }
 function updateAdminMode(program) {
  var mode=program.querySelector('[name$="[admin_mode]"]');
  program.querySelectorAll('[name$="[values][admin]"]').forEach(function(input){input.max=mode.value==='fixed'?'100000000':'100';});
 }
 document.querySelectorAll('[data-finance-program]').forEach(function(program){updateAdminMode(program);});
 document.addEventListener('change',function(event){if(event.target.matches('[name$="[admin_mode]"]'))updateAdminMode(event.target.closest('[data-finance-program]'));});
 document.addEventListener('click',function(event){
  var add=event.target.closest('[data-finance-add-rule]');
  if(add){var program=add.closest('[data-finance-program]');var list=program.querySelector('[data-finance-rules]');if(list.children.length>=100){alert('الحد الأقصى 100 شرط لكل برنامج.');return;}list.append(program.querySelector('template').content.cloneNode(true));renumber(program);updateAdminMode(program);list.lastElementChild.querySelector('select').focus();return;}
  var remove=event.target.closest('[data-finance-remove-rule]');if(remove){var rule=remove.closest('[data-finance-rule]');var parent=rule.closest('[data-finance-program]');rule.remove();renumber(parent);return;}
  var move=event.target.closest('[data-finance-move-rule]');if(move){var item=move.closest('[data-finance-rule]');if(move.dataset.financeMoveRule==='up'&&item.previousElementSibling)item.parentNode.insertBefore(item,item.previousElementSibling);else if(move.dataset.financeMoveRule==='down'&&item.nextElementSibling)item.parentNode.insertBefore(item.nextElementSibling,item);renumber(item.closest('[data-finance-program]'));}
 });
 // Keep field names intact for browser Back/Forward restoration and repeat submissions.
 document.addEventListener('submit',function(event){
  var form=event.target;if(form.matches('[data-finance-admin-form]'))form.querySelectorAll('[data-finance-program]').forEach(renumber);
 });
 // Modify only the outgoing FormData, avoiding PHP max_input_vars truncation.
 document.addEventListener('formdata',function(event){
  var form=event.target;if(!form.matches('[data-finance-admin-form]')||!form.querySelector('[name^="provider["],[name^="settings["]'))return;
  var data=event.formData;
  try {
   var payload={};var keys=[];
   data.forEach(function(value,name){
    if(!(name.startsWith('provider[')||name.startsWith('settings[')||name==='complete'))return;
    keys.push(name);var parts=name.match(/[^\[\]]+/g);var append=name.endsWith('[]');var target=payload;
    parts.forEach(function(key,index){if(['__proto__','constructor','prototype'].includes(key))throw new Error('اسم حقل غير صحيح.');if(index===parts.length-1){if(append){if(!target[key])target[key]=[];target[key].push(value);}else target[key]=value;}else {if(!target[key])target[key]={};target=target[key];}});
   });
   var json=JSON.stringify(payload);keys.forEach(function(key){data.delete(key);});data.set('payload',json);
  }catch(error){data.delete('complete');data.delete('payload');var status=form.querySelector('[data-finance-editor-status]');if(status)status.textContent=error.message;}
 });
}());
