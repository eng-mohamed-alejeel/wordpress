document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('[data-margin-form]'); if (!form) return;
  const en = form.dataset.language === 'en';
  const money = new Intl.NumberFormat(en ? 'en-SA' : 'ar-SA', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function preview() {
    const type = form.elements.type.value, stage = form.elements.stage.value;
    const value = Math.round(Number(form.elements.value.value) * 100), base = Math.round(Number(form.querySelector('[data-margin-example]').value) * 100);
    form.querySelector('[data-margin-unit]').textContent = type === 'percentage' ? ' %' : (en ? ' SAR' : ' ريال');
    form.elements.value.max = type === 'percentage' ? '1000' : '';
    if (!Number.isSafeInteger(base) || !Number.isSafeInteger(value) || base < 1 || value < 0) {form.querySelector('[data-margin-preview]').textContent = en ? 'Enter valid values.' : 'أدخل قيمًا صحيحة.'; return;}
    const margin = amount => type === 'fixed' ? value : type === 'percentage' ? Math.round(amount * value / 10000) : 0;
    const before = stage === 'before_tax' ? margin(base) : 0;
    const tax = Math.round((base + before) * Number(form.dataset.vat) / 10000);
    const after = stage === 'after_tax' ? margin(base + tax) : 0;
    const parts = [[en ? 'Base' : 'السعر الأساسي', base], [en ? 'Margin' : 'الهامش', before + after], [en ? 'VAT' : 'الضريبة', tax], [en ? 'Total before plates' : 'الإجمالي قبل اللوحات', base + before + tax + after]];
    form.querySelector('[data-margin-preview]').textContent = parts.map(([label, amount]) => label + ': ' + money.format(amount / 100) + (en ? ' SAR' : ' ريال')).join(' · ');
  }
  form.addEventListener('input', preview); form.addEventListener('change', preview); preview();
});
