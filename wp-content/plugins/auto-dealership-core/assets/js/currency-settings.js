(function () {
  'use strict';
  document.querySelectorAll('[data-currency-value]').forEach(function (input) {
    var row = input.closest('td');
    var type = row.querySelector('select');
    var unit = document.createElement('span');
    unit.setAttribute('aria-live', 'polite');
    input.after(unit);
    function update(reset) {
      input.step = type.value === 'fixed' ? '0.01' : '1';
      input.max = type.value === 'percentage' ? '9999' : '';
      unit.textContent = type.value === 'percentage' ? ' (0.01%)' : type.value === 'fixed' ? ' (SAR)' : '';
      input.disabled = type.value === 'none';
      if (reset) input.value = '0';
    }
    type.addEventListener('change', function () { update(true); });
    update(false);
  });
}());
