(function (api) {
    'use strict';
    const colors = {
        primary_color: ['--cd-color-primary', '--cd-navy'],
        accent_color: ['--cd-color-accent', '--cd-blue']
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
