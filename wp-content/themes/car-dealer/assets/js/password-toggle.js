(function () {
  'use strict';

  var sequence = 0;
  var labels = {
    ar: {
      show: 'إظهار كلمة المرور', hide: 'إخفاء كلمة المرور', invalid: 'تأكد من استيفاء جميع شروط كلمة المرور.',
      mismatch: 'تأكيد كلمة المرور غير مطابق.', empty: 'لم تُكتب بعد', weak: 'ضعيفة', fair: 'مقبولة', strong: 'قوية', veryStrong: 'قوية جدًا',
      met: 'مستوفى', unmet: 'غير مستوفى',
      matches: 'كلمتا المرور متطابقتان.', generated: 'تم إنشاء كلمة مرور قوية وتعبئة حقل التأكيد. احفظها في مدير كلمات المرور.',
      copied: 'تم نسخ كلمة المرور.', copyFailed: 'تعذر النسخ تلقائيًا. استخدم زر إظهار كلمة المرور لنسخها.', unavailable: 'تعذر إنشاء كلمة مرور آمنة في هذا المتصفح.'
    },
    en: {
      show: 'Show password', hide: 'Hide password', invalid: 'Please meet all password requirements.',
      mismatch: 'The password confirmation does not match.', empty: 'Not entered yet', weak: 'Weak', fair: 'Fair', strong: 'Strong', veryStrong: 'Very strong',
      met: 'Met', unmet: 'Not met',
      matches: 'Passwords match.', generated: 'A strong password was generated and confirmed. Save it in your password manager.',
      copied: 'Password copied.', copyFailed: 'Could not copy automatically. Use the show-password button to copy it.', unavailable: 'A secure password could not be generated in this browser.'
    }
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

  function passwordRules(value) {
    var length = Array.from(value).length;
    return {
      length: length >= 12 && length <= 128,
      letter: /\p{L}/u.test(value),
      number: /\p{N}/u.test(value),
      symbol: /[\p{P}\p{S}]/u.test(value)
    };
  }

  function strengthLevel(value, rules) {
    if (!value) return 0;
    var met = Object.keys(rules).filter(function (key) { return rules[key]; }).length;
    if (met < 3) return 1;
    if (met < 4) return 2;
    var variedCase = /[a-z]/.test(value) && /[A-Z]/.test(value);
    return Array.from(value).length >= 16 && variedCase ? 4 : 3;
  }

  function randomIndex(maximum) {
    if (!window.crypto || !window.crypto.getRandomValues) throw new Error('Secure random values are unavailable.');
    var limit = 256 - (256 % maximum);
    var bytes = new Uint8Array(1);
    do window.crypto.getRandomValues(bytes); while (bytes[0] >= limit);
    return bytes[0] % maximum;
  }

  function generateStrongPassword() {
    var groups = [
      'ABCDEFGHJKLMNPQRSTUVWXYZ',
      'abcdefghijkmnopqrstuvwxyz',
      '23456789',
      '!@#$%&*+-=?_'
    ];
    var all = groups.join('');
    var characters = groups.map(function (group) { return group[randomIndex(group.length)]; });
    while (characters.length < 20) characters.push(all[randomIndex(all.length)]);
    for (var index = characters.length - 1; index > 0; index--) {
      var swap = randomIndex(index + 1);
      var current = characters[index];
      characters[index] = characters[swap];
      characters[swap] = current;
    }
    return characters.join('');
  }

  function copyText(value) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(value);
    return new Promise(function (resolve, reject) {
      var helper = document.createElement('textarea');
      helper.value = value;
      helper.setAttribute('readonly', '');
      helper.style.position = 'fixed';
      helper.style.opacity = '0';
      document.body.appendChild(helper);
      helper.select();
      try {
        if (!document.execCommand('copy')) throw new Error('Copy was rejected.');
        resolve();
      } catch (error) {
        reject(error);
      } finally {
        helper.remove();
      }
    });
  }

  function enhancePolicy(panel) {
    if (!(panel instanceof HTMLElement) || panel.dataset.passwordPolicyReady === 'true') return;
    var form = panel.closest('form');
    var password = form && form.querySelector('[data-password-policy]');
    var confirmation = form && form.querySelector('[name="password_confirm"]');
    if (!password || !confirmation) return;
    panel.dataset.passwordPolicyReady = 'true';

    var meter = panel.querySelector('[data-password-meter]');
    var strength = panel.querySelector('[data-password-strength-label]');
    var generate = panel.querySelector('[data-password-generate]');
    var copy = panel.querySelector('[data-password-copy]');
    var status = panel.querySelector('[data-password-status]');
    var matchStatus = form.querySelector('[data-password-confirm-status]');

    function update() {
      var copyLabels = labels[language()];
      var rules = passwordRules(password.value);
      var valid = Object.keys(rules).every(function (key) { return rules[key]; });
      Object.keys(rules).forEach(function (key) {
        var item = panel.querySelector('[data-password-rule="' + key + '"]');
        if (item) {
          item.classList.toggle('is-met', rules[key]);
          item.setAttribute('data-met', String(rules[key]));
          item.setAttribute('aria-label', item.textContent.trim() + ': ' + (rules[key] ? copyLabels.met : copyLabels.unmet));
        }
      });
      var level = strengthLevel(password.value, rules);
      meter.dataset.level = String(level);
      strength.textContent = [copyLabels.empty, copyLabels.weak, copyLabels.fair, copyLabels.strong, copyLabels.veryStrong][level];
      strength.dataset.level = String(level);
      password.setCustomValidity(password.value && !valid ? copyLabels.invalid : '');

      var matches = !!confirmation.value && confirmation.value === password.value;
      confirmation.setCustomValidity(confirmation.value && !matches ? copyLabels.mismatch : '');
      matchStatus.textContent = confirmation.value ? (matches ? copyLabels.matches : copyLabels.mismatch) : '';
      matchStatus.classList.toggle('is-match', matches);
      matchStatus.classList.toggle('is-mismatch', !!confirmation.value && !matches);
      copy.hidden = !valid;
      return valid;
    }

    password.addEventListener('input', function () { status.textContent = ''; update(); });
    confirmation.addEventListener('input', update);
    form.addEventListener('submit', update);
    form.addEventListener('reset', function () {
      requestAnimationFrame(function () { status.textContent = ''; update(); });
    });

    generate.addEventListener('click', function () {
      var copyLabels = labels[language()];
      try {
        var generated = generateStrongPassword();
        password.value = generated;
        confirmation.value = generated;
        password.dispatchEvent(new Event('input', { bubbles: true }));
        confirmation.dispatchEvent(new Event('input', { bubbles: true }));
        status.textContent = copyLabels.generated;
        password.focus({ preventScroll: true });
      } catch (_) {
        status.textContent = copyLabels.unavailable;
      }
    });

    copy.addEventListener('click', function () {
      var copyLabels = labels[language()];
      copyText(password.value).then(function () {
        status.textContent = copyLabels.copied;
      }).catch(function () {
        status.textContent = copyLabels.copyFailed;
      });
    });

    update();
  }

  function scan(root) {
    if (root instanceof HTMLInputElement && root.matches('input[type="password"]')) enhance(root);
    if (root.querySelectorAll) root.querySelectorAll('input[type="password"]').forEach(enhance);
    if (root instanceof HTMLElement && root.matches('[data-password-policy-panel]')) enhancePolicy(root);
    if (root.querySelectorAll) root.querySelectorAll('[data-password-policy-panel]').forEach(enhancePolicy);
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
