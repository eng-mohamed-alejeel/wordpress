(function () {
    'use strict';
    if (window.autoBrandsAppearance) return;
    const key = 'autobrands.appearance', root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    const valid = value => ['light', 'dark', 'system'].includes(value);
    let preference = 'system';
    try { const stored = localStorage.getItem(key); if (valid(stored)) preference = stored; } catch (_) {}
    function apply() {
        const mode = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference;
        root.dataset.abTheme = mode;
        root.dataset.abPreference = preference;
        root.style.colorScheme = mode;
        const ar = root.lang.startsWith('ar');
        const label = mode === 'dark' ? (ar ? 'تفعيل الوضع الفاتح' : 'Switch to light mode') : (ar ? 'تفعيل الوضع الداكن' : 'Switch to dark mode');
        document.querySelectorAll('[data-ab-toggle], [data-ab-cycle]').forEach(control => {
            control.setAttribute('aria-label', label);
            control.setAttribute('title', label);
            if (control.matches('[data-ab-cycle]')) control.textContent = label;
        });
        const status = preference === 'system' ? (ar ? 'تلقائي حسب الجهاز' : 'Automatic — device setting') : preference === 'dark' ? (ar ? 'داكن — اختيارك' : 'Dark — your choice') : (ar ? 'فاتح — اختيارك' : 'Light — your choice');
        document.querySelectorAll('[data-ab-preference-status]').forEach(control => { control.textContent = status; });
        document.querySelectorAll('[data-ab-reset]').forEach(control => { control.disabled = preference === 'system'; });
        document.querySelectorAll('[data-ab-appearance]').forEach(control => { control.value = preference; });
    }
    function select(value) {
        if (!valid(value)) return;
        preference = value;
        try { if (value === 'system') localStorage.removeItem(key); else localStorage.setItem(key, value); } catch (_) {}
        apply();
    }
    function toggle() { select(root.dataset.abTheme === 'dark' ? 'light' : 'dark'); }
    window.autoBrandsAppearance = { select, toggle };
    apply();
    function ready() {
        const control = document.getElementById('ab-customizer-appearance');
        const footer = document.querySelector('#customize-controls .wp-full-overlay-footer');
        if (control && footer) footer.prepend(control);
        apply();
        requestAnimationFrame(() => requestAnimationFrame(() => { root.dataset.abAppearanceReady = 'true'; }));
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
    document.addEventListener('change', event => { if (event.target.matches('[data-ab-appearance]')) select(event.target.value); });
    document.addEventListener('click', event => {
        if (event.target.closest('[data-ab-reset]')) { select('system'); return; }
        if (event.target.closest('[data-ab-toggle], #wp-admin-bar-ab-appearance > a')) { event.preventDefault(); toggle(); }
    });
    system.addEventListener('change', () => { if (preference === 'system') apply(); });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) { preference = valid(event.newValue) ? event.newValue : 'system'; apply(); }
    });
    window.addEventListener('pageshow', apply);
})();