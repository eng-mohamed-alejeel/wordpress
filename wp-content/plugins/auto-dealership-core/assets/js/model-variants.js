(() => {
  'use strict';
  document.querySelectorAll('.onyx-variants').forEach(section => {
    const controls = [...section.querySelectorAll('[data-variant-field]')];
    const rows = [...section.querySelectorAll('tbody tr')];
    const result = section.querySelector('[data-variant-result]');
    const update = () => {
      let count = 0;
      for (const row of rows) {
        row.hidden = !controls.every(control => !control.value || row.getAttribute('data-' + control.dataset.variantField) === control.value);
        if (!row.hidden) count++;
      }
      result.textContent = count ? count + ' ' + section.dataset.matches : section.dataset.empty;
    };
    controls.forEach(control => control.addEventListener('change', update));
    section.querySelector('[data-variant-reset]').addEventListener('click', () => { controls.forEach(control => { control.value = ''; }); update(); });
    update();
  });
})();
