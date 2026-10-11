(() => {
  'use strict';
  const form = document.querySelector('form input[name="action"][value="adc_create_vehicle"]')?.form;
  if (!form || !window.adcVinLookup) return;
  const vin = form.elements.vin;
  const sourceCode = form.elements.onyx_identifier;
  const sourceId = form.elements.onyx_stock_id;
  const confirmation = form.elements.onyx_link_confirmed;
  const status = form.querySelector('[data-vin-status]');
  const confirmationLabel = form.querySelector('[data-onyx-link]');
  let timer, controller, sequence = 0;
  const clear = () => { sourceId.value = ''; confirmation.checked = false; confirmationLabel.hidden = true; vin.setCustomValidity(''); };
  const check = async (input) => {
    controller?.abort(); const request = ++sequence; clear();
    const value = input.value.trim().toUpperCase(); input.value = value;
    if (value.length < 4) { status.textContent = ''; return; }
    controller = new AbortController(); status.textContent = adcVinLookup.checking;
    try {
      const response = await fetch(adcVinLookup.url, { method: 'POST', credentials: 'same-origin', signal: controller.signal, body: new URLSearchParams({ action: 'adc_lookup_vin', nonce: adcVinLookup.nonce, identifier: value }) });
      const json = await response.json(); if (!response.ok || !json.success) throw new Error('lookup');
      if (request !== sequence) return;
      const result = json.data;
      if (result.ambiguous) { status.textContent = adcVinLookup.ambiguous; return; }
      if (result.operational_exists) { status.textContent = adcVinLookup.exists; if (value.length === 17 && input === vin) vin.setCustomValidity(adcVinLookup.exists); return; }
      if (result.source) {
        status.textContent = String(result.source.present) === '0' ? adcVinLookup.missing : result.source.vehicle_id ? adcVinLookup.exists : adcVinLookup.source;
        if (!result.source.vehicle_id && String(result.source.present) !== '0') {
          sourceId.value = result.source.id; sourceCode.value = result.source.source_identifier; confirmationLabel.hidden = false;
          for (const [name, source] of Object.entries({ brand: 'brand', model: 'model', trim: 'trim_name', model_year: 'model_year' })) if (form.elements[name] && !form.elements[name].value) form.elements[name].value = result.source[source];
          if (!form.elements.stock_number.value) form.elements.stock_number.value = 'ONYX-' + result.source.source_identifier;
          if (!form.elements.retail_price.value) { const amount = String(result.source.listed_price).padStart(3, '0'); form.elements.retail_price.value = amount.slice(0, -2) + '.' + amount.slice(-2); }
        }
        return;
      }
      status.textContent = result.exists ? adcVinLookup.exists : adcVinLookup.new;
    } catch (error) { if (error.name !== 'AbortError' && request === sequence) status.textContent = adcVinLookup.failed; }
  };
  for (const input of [vin, sourceCode]) {
    input.addEventListener('input', () => { clearTimeout(timer); controller?.abort(); ++sequence; clear(); timer = setTimeout(() => check(input), 350); });
    input.addEventListener('blur', () => { clearTimeout(timer); check(input); });
  }
})();
