(() => {
 'use strict';
 document.addEventListener('adc:vehicle-option', event => {
  const items = event.detail?.technical;
  if (!items) return;
  let visible = false;
  document.querySelectorAll('[data-technical-field]').forEach(node => {
   const item = items[node.dataset.technicalField];
   node.hidden = !item?.label;
   if (node.hidden) return;
   visible = true;
   node.querySelector('[data-technical-value]').textContent = item.label;
   // SVG is generated exclusively by the server's fixed path allowlist.
   if (item.svg) node.querySelector('[data-technical-icon]').innerHTML = item.svg;
  });
  const summary = document.querySelector('[data-technical-summary]');
  if (summary) summary.hidden = !visible;
  document.querySelectorAll('[data-technical-row]').forEach(node => {
   const item = items[node.dataset.technicalRow];
   node.hidden = !item?.label;
   node.querySelector('[data-technical-detail]').textContent = item?.label || '';
  });
 });
 const init = () => {
  document.querySelectorAll('.adc-capacity-input').forEach(group => {
   const input = group.querySelector('input');
   const unit = group.querySelector('select');
   let previous = unit.value;
   const sync = () => { input.min = unit.value === 'l' ? '.05' : '50'; input.max = unit.value === 'l' ? '30' : '30000'; input.step = unit.value === 'l' ? '.001' : '1'; };
   unit.addEventListener('change', () => {
    const amount = Number(input.value);
    if (input.value !== '' && Number.isFinite(amount) && previous !== unit.value) {
     input.value = String(Math.round((unit.value === 'l' ? amount / 1000 : amount * 1000) * 1000) / 1000);
     input.dispatchEvent(new Event('input', {bubbles:true}));
    }
    previous = unit.value;
    sync();
   });
   sync();
  });
 };
 if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
})();
