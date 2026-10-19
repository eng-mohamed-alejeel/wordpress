(function () {
  'use strict';
  function init() {
    const tools = document.querySelector('[data-car-dialogs]');
    if (!tools) return;
    document.documentElement.classList.add('cd-car-interactive');
    const panels = new Map([...tools.querySelectorAll('[data-car-panel]')].map(panel => [panel.id, panel]));
    const hashKey = () => {
      try { const key = decodeURIComponent(location.hash.slice(1)); return panels.has(key) ? key : ''; }
      catch (_) { return ''; }
    };
    if (!window.HTMLDialogElement || typeof HTMLDialogElement.prototype.showModal !== 'function') {
      const reveal = () => { const panel = panels.get(hashKey()); if (panel) panel.open = true; };
      document.addEventListener('click', event => { const link = event.target.closest('a[href^="#"]'); if (link && panels.has(link.hash.slice(1))) panels.get(link.hash.slice(1)).open = true; });
      window.addEventListener('hashchange', reveal); reveal(); return;
    }
    const entries = new Map();
    let active = null, savedScroll = null, baseURL = location.href, frame = 0;
    function viewport() {
      frame = 0;
      if (!active) return;
      const view = window.visualViewport;
      active.dialog.style.setProperty('--cd-dialog-height', (view ? view.height : innerHeight) + 'px');
      active.dialog.style.setProperty('--cd-dialog-top', (view ? view.offsetTop : 0) + 'px');
    }
    function scheduleViewport() { if (!frame) frame = requestAnimationFrame(viewport); }
    function lock() {
      if (savedScroll) return;
      const properties = ['position', 'top', 'left', 'right', 'width'];
      savedScroll = { x: scrollX, y: scrollY, styles: properties.map(key => [key, document.body.style.getPropertyValue(key), document.body.style.getPropertyPriority(key)]) };
      document.documentElement.classList.add('cd-car-modal-open');
      Object.assign(document.body.style, { position: 'fixed', top: -savedScroll.y + 'px', left: '0', right: '0', width: '100%' });
    }
    function unlock() {
      if (!savedScroll) return;
      const saved = savedScroll; savedScroll = null;
      saved.styles.forEach(([key, value, priority]) => value ? document.body.style.setProperty(key, value, priority) : document.body.style.removeProperty(key));
      document.documentElement.classList.remove('cd-car-modal-open');
      window.scrollTo({ left: saved.x, top: saved.y, behavior: 'instant' });
    }
    function finish(entry, options = {}) {
      if (active !== entry) return;
      active = null;
      if (entry.dialog.open) entry.dialog.close();
      if (options.switching) return;
      unlock();
      if (options.history !== false && hashKey()) {
        const state = { ...(history.state || {}) }; delete state.cdCarDialog;
        history.replaceState(state, '', baseURL);
      }
      if (options.focus !== false) {
        const opener = entry.opener && entry.opener.isConnected ? entry.opener : document.querySelector('a[href="#' + entry.id + '"]');
        opener?.focus({ preventScroll: true });
      }
    }
    function open(id, opener, writeHistory) {
      const entry = entries.get(id);
      if (!entry) return;
      if (active === entry) { entry.heading.focus({ preventScroll: true }); return; }
      if (!active) {
        const url = new URL(location.href); if (hashKey()) url.hash = '';
        baseURL = url.href;
      } else finish(active, { switching: true });
      entry.opener = opener || null;
      lock(); active = entry; viewport();
      try { entry.dialog.showModal(); }
      catch (_) { active = null; unlock(); return; }
      entry.heading.focus({ preventScroll: true });
      if (writeHistory && hashKey() !== id) history.pushState({ ...(history.state || {}), cdCarDialog: id }, '', '#' + id);
    }
    function revealInside(entry, element) {
      const box = entry.body.getBoundingClientRect(), rect = element.getBoundingClientRect();
      if (rect.top < box.top + 12 || rect.height > box.height) entry.body.scrollTop += rect.top - box.top - 16;
      else if (rect.bottom > box.bottom - 12) entry.body.scrollTop += rect.bottom - box.bottom + 16;
    }
    panels.forEach((panel, id) => {
      const content = panel.querySelector('[data-car-panel-content]');
      if (!content) return;
      const dialog = document.createElement('dialog');
      dialog.id = id + '-dialog'; dialog.className = 'cd-car-dialog' + (id === 'finance-calculator' ? ' cd-car-dialog-wide' : '');
      dialog.dir = tools.dir; dialog.lang = tools.lang;
      const header = document.createElement('header'); header.className = 'cd-car-dialog-header';
      const caption = document.createElement('div'); caption.className = 'cd-car-dialog-caption';
      const heading = document.createElement('h2'); heading.id = id + '-dialog-title'; heading.tabIndex = -1; heading.textContent = panel.dataset.panelTitle;
      const vehicle = document.createElement('p'); vehicle.id = id + '-dialog-car'; vehicle.textContent = tools.dataset.carName;
      caption.append(heading, vehicle);
      const close = document.createElement('button'); close.type = 'button'; close.className = 'cd-car-dialog-close'; close.setAttribute('aria-label', tools.dataset.closeLabel);
      const cross = document.createElement('span'); cross.setAttribute('aria-hidden', 'true'); cross.textContent = '×'; close.append(cross);
      header.append(caption, close);
      content.classList.add('cd-car-dialog-body');
      dialog.setAttribute('aria-labelledby', heading.id); dialog.setAttribute('aria-describedby', vehicle.id);
      dialog.append(header, content); document.body.append(dialog);
      const entry = { id, dialog, heading, body: content, opener: null }; entries.set(id, entry);
      close.addEventListener('click', () => finish(entry));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish(entry); });
      dialog.addEventListener('close', () => { if (active === entry && !dialog.open) finish(entry); });
      const outside = event => { const r = dialog.getBoundingClientRect(); return event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom; };
      let backdropDown = false;
      dialog.addEventListener('pointerdown', event => { backdropDown = event.target === dialog && outside(event); });
      dialog.addEventListener('pointerup', event => { if (backdropDown && event.target === dialog && outside(event)) finish(entry); backdropDown = false; });
      let invalidFrame = 0;
      dialog.addEventListener('invalid', () => { if (invalidFrame) return; invalidFrame = requestAnimationFrame(() => { invalidFrame = 0; const field = content.querySelector('input:invalid,select:invalid,textarea:invalid'); if (active === entry && field) { field.focus({ preventScroll: true }); revealInside(entry, field); } }); }, true);
      content.querySelectorAll('form').forEach(form => {
        let lastMessage = '', wasBusy = false;
        new MutationObserver(() => {
          const busy = form.dataset.adcSubmitting === '1' || form.dataset.calculating === 'true';
          form.setAttribute('aria-busy', busy ? 'true' : 'false');
          if (busy) { wasBusy = true; lastMessage = ''; return; }
          if (!wasBusy) return;
          const status = form.querySelector('.cd-form-status,[data-loan-status]');
          const message = status?.textContent.trim();
          if (!message || message === lastMessage) return;
          lastMessage = message; wasBusy = false;
          if (active === entry) { status.tabIndex = -1; status.focus({ preventScroll: true }); revealInside(entry, status); }
        }).observe(form, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['data-adc-submitting', 'data-calculating', 'role'] });
      });
    });
    if (!entries.size) return;
    tools.hidden = true;
    document.querySelectorAll('a[href^="#"]').forEach(link => { if (entries.has(link.hash.slice(1))) { link.setAttribute('aria-haspopup', 'dialog'); link.setAttribute('aria-controls', link.hash.slice(1) + '-dialog'); } });
    document.addEventListener('click', event => {
      const link = event.target.closest('a[href^="#"]');
      if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      const id = link.hash.slice(1); if (!entries.has(id)) return;
      event.preventDefault(); event.stopImmediatePropagation(); open(id, link, true);
    }, true);
    function sync() { const id = hashKey(); if (id) open(id, null, false); else if (active) finish(active, { history: false }); }
    window.addEventListener('popstate', sync); window.addEventListener('hashchange', sync);
    window.addEventListener('resize', scheduleViewport);
    window.visualViewport?.addEventListener('resize', scheduleViewport);
    window.visualViewport?.addEventListener('scroll', scheduleViewport);
    window.addEventListener('pagehide', () => { if (active) finish(active, { history: false, focus: false }); });
    if (hashKey()) { window.scrollTo({ top: 0, left: 0, behavior: 'instant' }); sync(); }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true }); else init();
}());
