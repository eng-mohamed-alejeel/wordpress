(function () {
  'use strict';

  var sequence = 0;
  var labels = {
    ar: { show: 'إظهار كلمة المرور', hide: 'إخفاء كلمة المرور' },
    en: { show: 'Show password', hide: 'Hide password' }
  };

  function language() {
    return document.documentElement.lang && document.documentElement.lang.toLowerCase().startsWith('en') ? 'en' : 'ar';
  }

  function icon() {
    return '<svg class="cd-password-eye cd-password-eye-show" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.75"/></svg>' +
      '<svg class="cd-password-eye cd-password-eye-hide" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m3 3 18 18M10.6 6.1A9.8 9.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16.7 16.7 0 0 1-2.2 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a9.7 9.7 0 0 0 3.1-.5M9.9 9.9A3 3 0 0 0 14.1 14.1"/></svg>';
  }

  function update(button, input, visible) {
    var copy = labels[language()];
    var label = visible ? copy.hide : copy.show;
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', label);
    button.setAttribute('title', label);
    input.setAttribute('data-password-visible', String(visible));
  }

  function enhance(input) {
    if (!(input instanceof HTMLInputElement) || input.dataset.passwordToggleReady === 'true') return;
    input.dataset.passwordToggleReady = 'true';

    var coreWrapper = input.closest('.wp-pwd');
    var coreButton = coreWrapper && coreWrapper.querySelector('.wp-hide-pw');
    if (coreButton) {
      coreWrapper.classList.add('cd-password-field', 'cd-password-field-core');
      coreButton.classList.add('cd-password-toggle-core');
      return;
    }

    var wrapper = document.createElement('span');
    wrapper.className = 'cd-password-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    if (!input.id) input.id = 'cd-password-' + (++sequence);
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'cd-password-toggle';
    button.setAttribute('aria-controls', input.id);
    button.innerHTML = icon();
    update(button, input, input.type === 'text');
    wrapper.appendChild(button);

    button.addEventListener('click', function () {
      var start = input.selectionStart;
      var end = input.selectionEnd;
      var visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      update(button, input, visible);
      input.focus({ preventScroll: true });
      if (start !== null && end !== null) {
        requestAnimationFrame(function () {
          try { input.setSelectionRange(start, end); } catch (_) {}
        });
      }
    });

    input.form && input.form.addEventListener('reset', function () {
      requestAnimationFrame(function () {
        input.type = 'password';
        update(button, input, false);
      });
    });
  }

  function scan(root) {
    if (root instanceof HTMLInputElement && root.matches('input[type="password"]')) enhance(root);
    if (root.querySelectorAll) root.querySelectorAll('input[type="password"]').forEach(enhance);
  }

  function start() {
    scan(document);
    new MutationObserver(function (records) {
      records.forEach(function (record) {
        if (record.type === 'attributes') scan(record.target);
        record.addedNodes.forEach(function (node) {
          if (node.nodeType === Node.ELEMENT_NODE) scan(node);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['type'] });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
