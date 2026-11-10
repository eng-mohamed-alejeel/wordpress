document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('[data-margin-form]'); if (!form) return;
  const en = form.dataset.language === 'en';
  const decimals = Number(form.dataset.decimals || 2), factor = 10 ** decimals;
  const unit = form.dataset.unit || form.dataset.currency || '';
  const money = new Intl.NumberFormat(en ? 'en-US' : 'ar-SA', {minimumFractionDigits: decimals, maximumFractionDigits: decimals});
  function preview() {
    const type = form.elements.type.value, stage = form.elements.stage.value;
    const value = Math.round(Number(form.elements.value.value) * (type === 'percentage' ? 100 : factor)), base = Math.round(Number(form.querySelector('[data-margin-example]').value) * factor);
    form.elements.value.step = type === 'percentage' ? '0.01' : (form.dataset.moneyStep || '0.01');
    form.querySelector('[data-margin-unit]').textContent = type === 'percentage' ? ' %' : ' ' + unit;
    form.elements.value.max = type === 'percentage' ? '1000' : '';
    if (!Number.isSafeInteger(base) || !Number.isSafeInteger(value) || base < 1 || value < 0) {form.querySelector('[data-margin-preview]').textContent = en ? 'Enter valid values.' : 'أدخل قيمًا صحيحة.'; return;}
    const margin = amount => type === 'fixed' ? value : type === 'percentage' ? Math.round(amount * value / 10000) : 0;
    const before = stage === 'before_tax' ? margin(base) : 0;
    const rate = Number(form.dataset.vat);
    const after = stage === 'after_tax' ? margin(base + Math.round(base * rate / 10000)) : 0;
    const netMargin = stage === 'after_tax' ? Math.floor((after * 10000 + Math.floor((10000 + rate) / 2)) / (10000 + rate)) : before;
    const fee = Number(form.dataset.fee || 0);
    const subtotal = base + netMargin + fee;
    const tax = Math.round(subtotal * rate / 10000);
    const parts = [[en ? 'Base' : 'السعر الأساسي', base], [en ? 'Margin before VAT' : 'الهامش قبل الضريبة', netMargin], [en ? 'Fees' : 'الرسوم', fee], [en ? 'VAT' : 'الضريبة', tax], [en ? 'Total before plates' : 'الإجمالي قبل اللوحات', subtotal + tax]];
    form.querySelector('[data-margin-preview]').textContent = parts.map(([label, amount]) => label + ': ' + money.format(amount / factor) + ' ' + unit).join(' · ');
  }
  form.addEventListener('input', preview); form.addEventListener('change', preview); preview();
});
