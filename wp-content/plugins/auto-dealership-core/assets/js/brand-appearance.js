(function () {
    'use strict';
    if (window.autoBrandsAppearance) return;
    const key = 'autobrands.appearance';
    const root = document.documentElement;
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    const valid = value => ['light', 'dark', 'system'].includes(value);
    let preference = 'system';
    try { const stored = localStorage.getItem(key); if (valid(stored)) preference = stored; } catch (_) {}
    function apply() {
        const mode = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference;
        root.dataset.abTheme = mode;
        root.dataset.abPreference = preference;
        document.querySelectorAll('[data-ab-appearance]').forEach(control => { control.value = preference; });
        const ar = root.lang.startsWith('ar');
        document.querySelectorAll('[data-ab-cycle]').forEach(control => {
            const label = ar ? { light: 'فاتح', dark: 'داكن', system: 'الجهاز' } : { light: 'Light', dark: 'Dark', system: 'System' };
            control.textContent = (ar ? 'المظهر: ' : 'Appearance: ') + label[preference];
        });
    }
    function select(value) {
        if (!valid(value)) return;
        preference = value;
        try { localStorage.setItem(key, value); } catch (_) {}
        apply();
    }
    window.autoBrandsAppearance = { select };
    apply();
    document.addEventListener('DOMContentLoaded', () => {
        const control = document.getElementById('ab-customizer-appearance');
        const footer = document.querySelector('#customize-controls .wp-full-overlay-footer');
        if (control && footer) footer.prepend(control);
        apply();
    });
    document.addEventListener('change', event => { if (event.target.matches('[data-ab-appearance]')) select(event.target.value); });
    document.addEventListener('click', event => {
        if (event.target.closest('#wp-admin-bar-ab-appearance > a')) {
            event.preventDefault();
            const modes = ['system', 'light', 'dark'];
            select(modes[(modes.indexOf(preference) + 1) % modes.length]);
        }
    });
    system.addEventListener('change', apply);
    window.addEventListener('storage', event => { if (event.key === key) { preference = valid(event.newValue) ? event.newValue : 'system'; apply(); } });
})();
