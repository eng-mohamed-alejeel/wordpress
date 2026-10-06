(function () {
    'use strict';
    if (!window.adcPublicIntake) return;

    const config = window.adcPublicIntake;
    function label(form, key) {
        const language = form.querySelector('input[name="lang"]')?.value === 'en' ? 'en' : 'ar';
        return language === 'en' && config.englishLabels && config.englishLabels[key]
            ? config.englishLabels[key] : config[key];
    }
    const requests = new WeakMap();
    const selector = [
        '.cd-ajax-form[data-action="car_dealer_contact"]',
        '.cd-ajax-form[data-action="car_dealer_booking"]',
        '.cd-ajax-form[data-action="car_dealer_subscribe"]'
    ].join(', ');

    function uuid() {
        if (!window.crypto || !window.crypto.getRandomValues) return '';
        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function requestKey(form) {
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
    }

    function status(form, message, success) {
        const node = form.querySelector('.cd-form-status');
        if (!node) return;
        node.textContent = message;
        node.setAttribute('role', success ? 'status' : 'alert');
    }

    function accountLink(form, url) {
        if (!url) return;
        const node = form.querySelector('.cd-form-status');
        if (!node) return;
        const link = document.createElement('a');
        link.href = url;
        link.textContent = ' ' + label(form, 'accountLabel');
        node.appendChild(link);
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches(selector)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (form.dataset.adcSubmitting === '1') return;

        requestKey(form);
        const data = new FormData(form);
        data.append('action', form.dataset.action || '');
        data.append('nonce', config.nonce || '');
        form.dataset.adcSubmitting = '1';
        form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = true; });
        status(form, label(form, 'sendingLabel'), true);

        fetch(config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(response => response.json())
            .then(result => {
                const message = result.data && result.data.message ? result.data.message : label(form, 'unconfirmedLabel');
                status(form, message, !!result.success);
                if (!result.success) return;
                accountLink(form, result.data && result.data.account_url ? result.data.account_url : '');
                requests.delete(form);
                form.reset();
            })
            .catch(() => status(form, label(form, 'errorLabel'), false))
            .finally(() => {
                delete form.dataset.adcSubmitting;
                form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = false; });
            });
    }, true);

    document.addEventListener('reset', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches(selector)) return;
        requests.delete(form);
        const field = form.querySelector('input[name="idempotency_key"]');
        if (field) field.remove();
    }, true);
}());
