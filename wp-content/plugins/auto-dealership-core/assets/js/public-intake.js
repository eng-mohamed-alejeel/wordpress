/* Keep the same key after a failed submission, until the form payload changes. */
(function () {
    'use strict';
    const requests = new WeakMap();
    const selector = '.cd-ajax-form[data-action="car_dealer_contact"], .cd-ajax-form[data-action="car_dealer_booking"]';

    function uuid() {
        if (!window.crypto || !window.crypto.getRandomValues) return '';
        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    // Capture runs before the theme's submit handler constructs FormData.
    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches(selector)) return;
        const payload = JSON.stringify(Array.from(new FormData(form)).filter(entry => !['idempotency_key', 'nonce'].includes(entry[0])));
        let request = requests.get(form);
        if (!request || request.payload !== payload) {
            request = { payload: payload, key: uuid() };
            requests.set(form, request);
        }
        if (!request.key) return;
        let field = form.querySelector('input[name="idempotency_key"]');
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'idempotency_key';
            form.appendChild(field);
        }
        field.value = request.key;
    }, true);

    document.addEventListener('reset', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches(selector)) return;
        requests.delete(form);
        const field = form.querySelector('input[name="idempotency_key"]');
        if (field) field.remove();
    }, true);
}());
