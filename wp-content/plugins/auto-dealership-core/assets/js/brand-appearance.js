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
        root.style.colorScheme = mode;
        document.querySelectorAll('[data-ab-appearance]').forEach(control => { control.value = preference; });
        document.querySelectorAll('[data-ab-appearance-option]').forEach(control => {
            const active = control.dataset.abAppearanceOption === preference;
            control.classList.toggle('is-active', active);
            control.setAttribute('aria-checked', String(active));
            control.tabIndex = active ? 0 : -1;
        });
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
        const option = event.target.closest('[data-ab-appearance-option]');
        if (option) {
            select(option.dataset.abAppearanceOption);
            return;
        }
        if (event.target.closest('#wp-admin-bar-ab-appearance > a')) {
            event.preventDefault();
            const modes = ['system', 'light', 'dark'];
            select(modes[(modes.indexOf(preference) + 1) % modes.length]);
        }
    });
    document.addEventListener('keydown', event => {
        const current = event.target.closest('[data-ab-appearance-option]');
        if (!current || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        const options = [...current.closest('[data-ab-appearance-control]').querySelectorAll('[data-ab-appearance-option]')];
        let index = options.indexOf(current);
        if (event.key === 'Home') index = 0;
        else if (event.key === 'End') index = options.length - 1;
        else {
            let step = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1;
            if (['ArrowLeft', 'ArrowRight'].includes(event.key) && getComputedStyle(current).direction === 'rtl') step *= -1;
            index = (index + step + options.length) % options.length;
        }
        event.preventDefault();
        select(options[index].dataset.abAppearanceOption);
        options[index].focus();
    });
    system.addEventListener('change', apply);
    window.addEventListener('storage', event => { if (event.key === key) { preference = valid(event.newValue) ? event.newValue : 'system'; apply(); } });
})();
