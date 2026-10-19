(() => {
 'use strict';
 document.querySelectorAll('.adc-color-admin-card').forEach(card=>{
  const fields={hex:card.querySelector('[data-color-field=hex]'),second:card.querySelector('[data-color-field=second]')},alias=card.querySelector('select'),dot=card.querySelector('.adc-color-dot');
  function preview(){const selected=alias.selectedOptions[0];const hex=alias.value?selected.dataset.hex:fields.hex.value,second=alias.value?selected.dataset.second:fields.second.value;const known=/^#[a-f0-9]{6}$/i.test(hex||'');dot.classList.toggle('is-unknown',!known);dot.textContent=known?'':'?';dot.style.setProperty('--vehicle-color',known?hex:'#e5e7eb');dot.style.setProperty('--vehicle-color-second',known&&/^#[a-f0-9]{6}$/i.test(second||'')?second:(known?hex:'#e5e7eb'));}
  card.querySelectorAll('[data-color-picker]').forEach(picker=>{const field=fields[picker.dataset.colorPicker];picker.addEventListener('input',()=>{field.value=picker.value;field.dispatchEvent(new Event('input',{bubbles:true}));});field.addEventListener('input',()=>{if(/^#[a-f0-9]{6}$/i.test(field.value))picker.value=field.value;preview();});});alias.addEventListener('change',preview);
 });
})();
