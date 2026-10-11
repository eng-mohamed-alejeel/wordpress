document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-customer-filters]').forEach(function (form) {
    var rows = JSON.parse(form.querySelector('[data-catalog-choices]').textContent);
    var brand = form.elements.brand, model = form.elements.model;
    function choices(name) {
      var select = form.elements[name], field = select.dataset.catalogChoice, current = select.value;
      var values = new Map();
      rows.filter(function (row) {
        return (!brand.value || row.brand === brand.value) && (name === 'model' || !model.value || row.model === model.value);
      }).forEach(function (row) { if (row[field]) values.set(row[field], row[field + '_label'] || row[field]); });
      while (select.options.length > 1) select.remove(1);
      values.forEach(function (label, value) { select.add(new Option(label, value)); });
      select.value = values.has(current) ? current : '';
    }
    brand.addEventListener('change', function () { model.value = ''; form.elements.trim.value = ''; choices('model'); choices('trim'); choices('exterior_color'); });
    model.addEventListener('change', function () { form.elements.trim.value = ''; choices('trim'); choices('exterior_color'); });
    form.addEventListener('submit', function (event) {
      var valid = true;
      [['min_year','max_year'],['min_price','max_price']].forEach(function (pair) {
        var from = form.elements[pair[0]], to = form.elements[pair[1]];
        to.setCustomValidity('');
        if (from.value && to.value && Number(from.value) > Number(to.value)) {
          to.setCustomValidity(document.documentElement.lang.startsWith('en') ? 'The maximum must be at least the minimum.' : 'يجب أن يكون الحد الأعلى أكبر من الحد الأدنى أو مساويًا له.');
          form.querySelector('details').open = true; to.reportValidity(); valid = false;
        }
      });
      if (!valid) event.preventDefault();
    });
    form.addEventListener('input', function () { form.elements.max_year.setCustomValidity(''); form.elements.max_price.setCustomValidity(''); });
  });
  var auth = document.querySelector('[data-auth-switch]');
  if (auth) {
    var authLinks = auth.querySelectorAll('.cd-auth-tabs a');
    var authHeading = auth.querySelector('h1');
    var initialAuthView = new URL(location.href).searchParams.get('cd_account');
    function showAuthView(view) {
      if (view !== 'login' && view !== 'register') return false;
      auth.querySelectorAll('[data-auth-view]').forEach(function (form) {
        form.hidden = form.dataset.authView !== view;
      });
      authLinks.forEach(function (link) {
        if (new URL(link.href).searchParams.get('cd_account') === view) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      });
      authHeading.textContent = view === 'register' ? authHeading.dataset.registerTitle : authHeading.dataset.loginTitle;
      var notice = document.querySelector('.cd-account > .cd-account-notice');
      if (notice) notice.hidden = view !== initialAuthView;
      document.querySelectorAll('.site-language-switch a').forEach(function (link) {
        var url = new URL(link.href);
        url.searchParams.set('cd_account', view);
        link.href = url.href;
      });
      return true;
    }
    authLinks.forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        var url = new URL(link.href);
        if (url.origin !== location.origin) return;
        var view = url.searchParams.get('cd_account');
        if (!showAuthView(view)) return;
        event.preventDefault();
        if (url.href !== location.href) history.pushState(null, '', url.href);
      });
    });
    window.addEventListener('popstate', function () {
      showAuthView(new URL(location.href).searchParams.get('cd_account'));
    });
  }
  document.querySelectorAll('[data-vehicle-row], [data-horizontal-row]').forEach(function (row) {
    var track = row.querySelector('.latest-row-track, [data-horizontal-track]');
    var left = row.querySelector('[data-scroll-direction="left"]');
    var right = row.querySelector('[data-scroll-direction="right"]');
    if (!track || !left || !right) return;
    function updateControls() {
      var limit = Math.max(0, track.scrollWidth - track.clientWidth);
      var rtl = getComputedStyle(track).direction === 'rtl';
      left.disabled = rtl ? track.scrollLeft <= -limit + 2 : track.scrollLeft <= 2;
      right.disabled = rtl ? track.scrollLeft >= -2 : track.scrollLeft >= limit - 2;
    }
    function move(direction) {
      track.scrollBy({ left: direction * track.clientWidth * .85, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
    left.addEventListener('click', function () { move(-1); });
    right.addEventListener('click', function () { move(1); });
    track.addEventListener('keydown', function (event) {
      if (event.target === track && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
        event.preventDefault();
        move(event.key === 'ArrowLeft' ? -1 : 1);
      }
    });
    track.addEventListener('scroll', updateControls, { passive: true });
    new ResizeObserver(updateControls).observe(track);
    updateControls();
  });
  document.querySelectorAll('.language-dropdown').forEach(function (dropdown) {
    document.addEventListener('click', function (event) {
      if (!dropdown.contains(event.target)) dropdown.open = false;
    });
    dropdown.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && dropdown.open) {
        dropdown.open = false;
        dropdown.querySelector('summary').focus();
      }
    });
    dropdown.addEventListener('focusout', function (event) {
      if (!dropdown.contains(event.relatedTarget)) dropdown.open = false;
    });
  });
  var toggle = document.querySelector('.menu-toggle');
  var nav = document.querySelector('.main-navigation');
  if (toggle && nav) {
    var mobileMenu = window.matchMedia('(max-width: 1120px)');
    function closeMenu(returnFocus) {
      toggle.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
      if (returnFocus) toggle.focus();
    }
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      nav.classList.toggle('is-open', !open);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && nav.classList.contains('is-open')) closeMenu(true);
    });
    document.addEventListener('click', function (event) {
      if (!nav.contains(event.target) && !toggle.contains(event.target)) closeMenu(false);
    });
    nav.addEventListener('click', function (event) {
      if (mobileMenu.matches && event.target.closest('a')) closeMenu(false);
    });
    mobileMenu.addEventListener('change', function () {
      closeMenu(nav.contains(document.activeElement) && mobileMenu.matches);
    });
  }

});

/* ── Back to Top Button ── */
(function () {
  var btn = document.getElementById('abBackTop');
  if (!btn) return;
  var scrollThreshold = 400;

  function toggleButton() {
    if (window.scrollY > scrollThreshold) {
      btn.classList.add('is-visible');
    } else {
      btn.classList.remove('is-visible');
    }
  }

  window.addEventListener('scroll', toggleButton, { passive: true });
  toggleButton();

  btn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
})();

