(function (api) {
    'use strict';
    const colors = {
        primary_color: ['--ab-brand-navy'],
        accent_color: ['--ab-brand-blue']
    };
    Object.entries(colors).forEach(([key, properties]) => {
        api('car_dealer_theme_settings[' + key + ']', function (setting) {
            setting.bind(function (value) {
                if (!/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(value || '')) return;
                properties.forEach(property => document.documentElement.style.setProperty(property, value));
            });
        });
    });
})(wp.customize);
(function () {
    // Selective-refresh edit shortcuts must remain valid children of menu lists.
    function repairMenuShortcuts() {
        document.querySelectorAll('ul > .customize-partial-edit-shortcut, ol > .customize-partial-edit-shortcut').forEach(shortcut => {
            const item = document.createElement('li');
            item.style.display = 'contents';
            shortcut.before(item);
            item.append(shortcut);
        });
    }
    function ready() {
        repairMenuShortcuts();
        new MutationObserver(repairMenuShortcuts).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();
