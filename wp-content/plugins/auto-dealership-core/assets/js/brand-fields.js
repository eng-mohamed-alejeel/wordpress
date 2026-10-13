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

  function messageId(control) {
    if (!control.id) control.id = `ab-field-${++nextId}`;
    return `${control.id}-validation`;
  }

  function showError(control) {
    if (!owned(control) || ignored(control)) return;
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
    message.textContent = control.validationMessage || (document.documentElement.lang.startsWith('ar') ? 'يرجى مراجعة هذا الحقل.' : 'Please review this field.');
    control.setAttribute('aria-invalid', 'true');
    control.dataset.abInvalid = 'client';
    const describedBy = new Set(tokens(control.getAttribute('aria-describedby')));
    describedBy.add(id);
    control.setAttribute('aria-describedby', [...describedBy].join(' '));
    control.setAttribute('aria-errormessage', id);
  }

  function clearError(control) {
    if (!owned(control) || control.dataset.abInvalid !== 'client' || !control.validity.valid) return;
    const id = control.id ? `${control.id}-validation` : '';
    if (id) document.getElementById(id)?.remove();
    delete control.dataset.abInvalid;
    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-errormessage');
    const describedBy = tokens(control.getAttribute('aria-describedby')).filter(token => token !== id);
    if (describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '));
    else control.removeAttribute('aria-describedby');
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
  document.addEventListener('input', event => clearError(event.target), true);
  document.addEventListener('change', event => {
    clearError(event.target);
    if (event.target instanceof HTMLSelectElement) {
      prepareSelect(event.target);
      syncSelectTitle(event.target);
    }
  }, true);
  document.addEventListener('reset', event => {
    if (!event.target.closest(scopeSelector)) return;
    setTimeout(() => event.target.querySelectorAll('[data-ab-invalid="client"]').forEach(clearError));
  }, true);
  refreshSelects();
  addEventListener('load', queueSelectRefresh, { once: true });
  addEventListener('resize', queueSelectRefresh, { passive: true });
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node instanceof Element) refreshSelects(node);
  }))).observe(document.documentElement, { childList: true, subtree: true });
})();
