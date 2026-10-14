(() => {
  'use strict';

  const scopeSelector = '.site form, .adc-admin .wrap form, .adc-admin-native .wrap form, .login form, #customize-controls, .media-modal, .media-frame';
  const controlSelector = 'input, select, textarea';
  const selectSelector = 'select:not([multiple]):not([size])';
  let nextId = 0;
  let selectRefreshQueued = false;

  const owned = control => control instanceof HTMLElement && control.matches(controlSelector) && control.closest(scopeSelector);
  const ignored = control => control.matches('[type="hidden"], [type="submit"], [type="button"], [type="reset"], [type="image"], .wp-editor-area, .wp-color-picker');
  const tokens = value => (value || '').split(/\s+/).filter(Boolean);

  const language = control => {
    const declared = control.closest('[lang]')?.getAttribute('lang') || document.documentElement.lang || 'ar';
    return declared.toLowerCase().startsWith('ar') ? 'ar' : 'en';
  };

  function overrideMessage(control, reason, currentLanguage) {
    return control.getAttribute(`data-validation-${reason}-${currentLanguage}`) || '';
  }

  function requiredMessage(control, currentLanguage) {
    const custom = overrideMessage(control, 'required', currentLanguage);
    if (custom) return custom;
    const arabic = currentLanguage === 'ar';
    if (control instanceof HTMLSelectElement) return arabic ? 'يرجى اختيار قيمة من القائمة.' : 'Please choose an option from the list.';
    if (control instanceof HTMLInputElement) {
      if (control.type === 'checkbox') return arabic ? 'يرجى تحديد هذا الخيار للمتابعة.' : 'Please select this option to continue.';
      if (control.type === 'radio') return arabic ? 'يرجى اختيار أحد الخيارات للمتابعة.' : 'Please choose an option to continue.';
      if (control.type === 'file') return arabic ? 'يرجى اختيار ملف.' : 'Please choose a file.';
      if (control.type === 'date') return arabic ? 'يرجى اختيار التاريخ.' : 'Please choose a date.';
      if (control.type === 'time') return arabic ? 'يرجى اختيار الوقت.' : 'Please choose a time.';
    }
    return arabic ? 'يرجى تعبئة هذا الحقل.' : 'Please complete this field.';
  }

  function localizedValidationMessage(control) {
    const validity = control.validity;
    const currentLanguage = language(control);
    const arabic = currentLanguage === 'ar';
    if (validity.valueMissing) return requiredMessage(control, currentLanguage);
    if (validity.typeMismatch) {
      if (control.type === 'email') return arabic ? 'يرجى إدخال بريد إلكتروني صحيح.' : 'Please enter a valid email address.';
      if (control.type === 'url') return arabic ? 'يرجى إدخال رابط صحيح.' : 'Please enter a valid web address.';
      return arabic ? 'يرجى إدخال قيمة بالصيغة الصحيحة.' : 'Please enter a value in the correct format.';
    }
    if (validity.badInput) return arabic ? 'يرجى إدخال قيمة صحيحة.' : 'Please enter a valid value.';
    if (validity.patternMismatch) return overrideMessage(control, 'pattern', currentLanguage) || (arabic ? 'القيمة المدخلة لا تطابق الصيغة المطلوبة.' : 'Please match the requested format.');
    if (validity.tooShort) return arabic ? `يرجى إدخال ${control.minLength} أحرف على الأقل.` : `Please enter at least ${control.minLength} characters.`;
    if (validity.tooLong) return arabic ? `يرجى ألا يتجاوز النص ${control.maxLength} حرفًا.` : `Please use no more than ${control.maxLength} characters.`;
    if (validity.rangeUnderflow) return arabic ? `يجب ألا تقل القيمة عن ${control.min}.` : `The value must be at least ${control.min}.`;
    if (validity.rangeOverflow) return arabic ? `يجب ألا تزيد القيمة على ${control.max}.` : `The value must be no more than ${control.max}.`;
    if (validity.stepMismatch) return arabic ? 'يرجى إدخال قيمة متوافقة مع الخطوة المحددة.' : 'Please enter a value that matches the allowed step.';
    return arabic ? 'يرجى مراجعة هذا الحقل.' : 'Please review this field.';
  }

  function clearLocalizedValidity(control) {
    if (control.dataset.abLocalizedValidity !== 'true') return;
    control.setCustomValidity('');
    delete control.dataset.abLocalizedValidity;
  }

  function messageId(control) {
    if (!control.id) control.id = `ab-field-${++nextId}`;
    return `${control.id}-validation`;
  }

  function showError(control) {
    if (!owned(control) || ignored(control)) return;
    clearLocalizedValidity(control);
    if (control.validity.valid) {
      clearError(control, true);
      return;
    }
    if (!control.validity.customError) {
      control.setCustomValidity(localizedValidationMessage(control));
      control.dataset.abLocalizedValidity = 'true';
    }
    const id = messageId(control);
    let message = document.getElementById(id);
    if (!message) {
      message = document.createElement('span');
      message.id = id;
      message.className = 'ab-field-error';
      message.dataset.fieldError = '';
      message.setAttribute('role', 'alert');
      const wrappingLabel = control.closest('label');
      if (wrappingLabel && wrappingLabel.contains(control)) wrappingLabel.insertAdjacentElement('afterend', message);
      else control.insertAdjacentElement('afterend', message);
    }
    message.textContent = control.validationMessage || localizedValidationMessage(control);
    control.setAttribute('aria-invalid', 'true');
    control.dataset.abInvalid = 'client';
    const describedBy = new Set(tokens(control.getAttribute('aria-describedby')));
    describedBy.add(id);
    control.setAttribute('aria-describedby', [...describedBy].join(' '));
    control.setAttribute('aria-errormessage', id);
  }

  function clearError(control, force = false) {
    if (!owned(control)) return;
    clearLocalizedValidity(control);
    if (control.dataset.abInvalid !== 'client' || (!force && !control.validity.valid)) return;
    const id = control.id ? `${control.id}-validation` : '';
    if (id) document.getElementById(id)?.remove();
    delete control.dataset.abInvalid;
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-errormessage');
    const describedBy = tokens(control.getAttribute('aria-describedby')).filter(token => token !== id);
    if (describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '));
    else control.removeAttribute('aria-describedby');
  }

  function relatedControls(control) {
    if (!(control instanceof HTMLInputElement) || control.type !== 'radio' || !control.name) return [control];
    const candidates = control.form ? [...control.form.elements] : [...document.querySelectorAll('input[type="radio"]')];
    return candidates.filter(candidate => candidate instanceof HTMLInputElement && candidate.type === 'radio' && candidate.name === control.name);
  }

  function clearEditedControl(control) {
    if (!owned(control)) return;
    relatedControls(control).forEach(candidate => clearError(candidate, true));
  }

  function selectedText(select) {
    return (select.selectedOptions[0]?.textContent || '').trim();
  }

  function selectedTextWidth(select, text) {
    const style = getComputedStyle(select);
    const canvas = selectedTextWidth.canvas || (selectedTextWidth.canvas = document.createElement('canvas'));
    const context = canvas.getContext('2d');
    if (!context) return 0;
    context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
    return context.measureText(text).width;
  }

  function syncSelectTitle(select) {
    if (!owned(select) || !select.matches(selectSelector)) return;
    const text = selectedText(select);
    const style = getComputedStyle(select);
    const available = select.clientWidth - parseFloat(style.paddingInlineStart || 0) - parseFloat(style.paddingInlineEnd || 0) - 24;
    const clipped = !!text && available > 0 && selectedTextWidth(select, text) > available;
    const generated = select.dataset.abGeneratedTitle === 'true';
    if (clipped && (!select.hasAttribute('title') || generated)) {
      select.title = text;
      select.dataset.abGeneratedTitle = 'true';
    } else if (!clipped && generated) {
      select.removeAttribute('title');
      delete select.dataset.abGeneratedTitle;
    }
  }

  function prepareSelect(select) {
    if (!owned(select) || !select.matches(selectSelector)) return;
    const hasLongOption = [...select.options].some(option => option.textContent.trim().length > 32);
    select.toggleAttribute('data-ab-long-options', hasLongOption);
    requestAnimationFrame(() => syncSelectTitle(select));
  }

  function refreshSelects(root = document) {
    if (root instanceof HTMLSelectElement) prepareSelect(root);
    else if (root instanceof Element) {
      const containingSelect = root.closest(selectSelector);
      if (containingSelect) prepareSelect(containingSelect);
    }
    if (root.querySelectorAll) root.querySelectorAll(selectSelector).forEach(prepareSelect);
  }

  function queueSelectRefresh() {
    if (selectRefreshQueued) return;
    selectRefreshQueued = true;
    requestAnimationFrame(() => {
      selectRefreshQueued = false;
      document.querySelectorAll(selectSelector).forEach(syncSelectTitle);
    });
  }

  document.addEventListener('invalid', event => showError(event.target), true);
  document.addEventListener('input', event => clearEditedControl(event.target), true);
  document.addEventListener('change', event => {
    clearEditedControl(event.target);
    if (event.target instanceof HTMLSelectElement) {
      prepareSelect(event.target);
      syncSelectTitle(event.target);
    }
  }, true);
  document.addEventListener('reset', event => {
    if (!event.target.closest(scopeSelector)) return;
    setTimeout(() => event.target.querySelectorAll('[data-ab-invalid="client"]').forEach(control => clearError(control, true)));
  }, true);
  refreshSelects();
  addEventListener('load', queueSelectRefresh, { once: true });
  addEventListener('resize', queueSelectRefresh, { passive: true });
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node instanceof Element) refreshSelects(node);
  }))).observe(document.documentElement, { childList: true, subtree: true });
})();
