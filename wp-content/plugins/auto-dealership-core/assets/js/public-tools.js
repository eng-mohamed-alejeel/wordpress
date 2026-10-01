(function () {
    'use strict';
    if (!window.adcPublicTools) return;

    function request(data) {
        data.append('nonce', window.adcPublicTools.nonce);
        return fetch(window.adcPublicTools.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) { return response.json(); });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.cd-compare-button');
        if (!button || button.disabled) return;
        event.preventDefault();
        var data = new FormData();
        data.append('action', 'car_dealer_comparison');
        data.append('compare_action', button.getAttribute('aria-pressed') === 'true' ? 'remove' : 'add');
        data.append('car_id', button.dataset.carId || '');
        button.disabled = true;
        request(data).then(function (result) {
            if (!result.success) throw new Error(result.data && result.data.message ? result.data.message : window.adcPublicTools.comparisonError);
            document.querySelectorAll('.cd-compare-button[data-car-id="' + button.dataset.carId + '"]').forEach(function (item) {
                item.setAttribute('aria-pressed', result.data.active ? 'true' : 'false');
                item.classList.toggle('is-active', !!result.data.active);
                item.textContent = result.data.active ? (item.dataset.removeLabel || window.adcPublicTools.removeLabel) : (item.dataset.addLabel || window.adcPublicTools.addLabel);
            });
        }).catch(function (error) {
            button.textContent = error.message || window.adcPublicTools.comparisonError;
        }).finally(function () { button.disabled = false; });
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-loan-calculator]')) return;
        event.preventDefault();
        var resultNode = form.querySelector('[data-loan-result]');
        var statusNode = form.querySelector('[data-loan-status]');
        var data = new FormData(form);
        data.append('action', 'car_dealer_loan_calculator');
        if (statusNode) statusNode.textContent = window.adcPublicTools.calculatingLabel;
        request(data).then(function (result) {
            if (!result.success) throw new Error(result.data && result.data.message ? result.data.message : window.adcPublicTools.calculatorError);
            var locale = document.documentElement.lang === 'en' ? 'en-SA' : 'ar-SA';
            if (resultNode) resultNode.textContent = new Intl.NumberFormat(locale).format(result.data.monthly_payment) + ' ' + result.data.currency;
            if (statusNode) statusNode.textContent = '';
        }).catch(function (error) {
            if (statusNode) statusNode.textContent = error.message || window.adcPublicTools.calculatorError;
        });
    });
}());
