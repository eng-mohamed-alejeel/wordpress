document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.querySelector('.menu-toggle');
  var nav = document.querySelector('.main-navigation');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('is-open', !open);
    });
  }

  document.querySelectorAll('.cd-ajax-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var status = form.querySelector('.cd-form-status');
      var data = new FormData(form);
      data.append('action', form.dataset.action);
      data.append('nonce', window.carDealer ? window.carDealer.nonce : '');
      if (status) status.textContent = 'جاري الإرسال...';
      fetch(window.carDealer.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (result) {
          if (status) {
            status.textContent = result.data && result.data.message ? result.data.message : 'لم يتم تأكيد حفظ الطلب. يرجى المحاولة مجدداً.';
            status.setAttribute('role', result.success ? 'status' : 'alert');
            if (result.success && result.data.account_url) {
              var link = document.createElement('a');
              link.href = result.data.account_url;
              link.textContent = ' عرض حجزي في حسابي ←';
              status.appendChild(link);
            }
          }
          if (result.success) form.reset();
        })
        .catch(function () { if (status) status.textContent = 'تعذر إرسال الطلب الآن.'; });
    });
  });

  document.querySelectorAll('.cd-compare-button').forEach(function (button) {
    button.addEventListener('click', function () {
      var data = new FormData();
      data.append('action', 'car_dealer_comparison');
      data.append('compare_action', 'add');
      data.append('car_id', button.dataset.carId);
      data.append('nonce', window.carDealer ? window.carDealer.nonce : '');
      fetch(window.carDealer.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (result) { button.textContent = result.success ? 'تمت الإضافة' : 'تعذر الإضافة'; });
    });
  });

  function updateLoan(box) {
    var price = Number(box.querySelector('[data-loan-price]').value || 0);
    var down = Number(box.querySelector('[data-loan-down]').value || 0);
    var rate = Number(box.querySelector('[data-loan-rate]').value || 0) / 100 / 12;
    var months = Number(box.querySelector('[data-loan-months]').value || 1);
    var principal = Math.max(price - down, 0);
    var payment = rate > 0 ? principal * rate / (1 - Math.pow(1 + rate, -months)) : principal / months;
    box.querySelector('[data-loan-result]').textContent = Math.round(payment).toLocaleString('ar-SA') + ' ر.س';
  }

  document.querySelectorAll('[data-loan-calculator]').forEach(function (box) {
    box.querySelectorAll('input').forEach(function (input) {
      input.addEventListener('input', function () { updateLoan(box); });
    });
    updateLoan(box);
  });
});

// Use the signed-in customer's profile in request forms.
document.addEventListener('DOMContentLoaded', function () {
  if (!window.carDealerCustomer) return;
  document.querySelectorAll('.cd-ajax-form').forEach(function (form) {
    ['name', 'email', 'phone'].forEach(function (key) {
      var field = form.querySelector('[name="' + key + '"]');
      if (!field || !window.carDealerCustomer[key]) return;
      field.value = window.carDealerCustomer[key];
      field.defaultValue = window.carDealerCustomer[key];
      if (key !== 'phone') field.readOnly = true;
    });
  });
});
