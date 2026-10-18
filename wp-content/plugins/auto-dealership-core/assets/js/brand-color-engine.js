/* Pure color calculations shared by Customizer, live pages and advanced tools. */
(function(){'use strict';
window.adcBrandColors={canonical:value=>({format:1,light:{...(value.light||{})},dark:{...(value.dark||{})}}),create:function(schema){
    function luminance(color) {
        const rgb = [1, 3, 5].map(i => parseInt(color.slice(i, i + 2), 16) / 255).map(n => n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4);
        return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
    }
    function contrast(a, b) { const x = luminance(a), y = luminance(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
    function mix(a, b, amount) { return '#' + [1, 3, 5].map(i => Math.round(parseInt(a.slice(i, i + 2), 16) * (1 - amount) + parseInt(b.slice(i, i + 2), 16) * amount).toString(16).padStart(2, '0')).join('').toUpperCase(); }
    function resolve(config, scheme) {
        const result = {};
        const get = key => {
            if (result[key]) return result[key];
            const rule = config[scheme][key] || (scheme === 'dark' && schema[key].group === 'brand' ? config.light[key] : null) || schema[key][scheme];
            const read = value => value.startsWith('@') ? get(value.slice(1)) : value;
            if (Array.isArray(rule)) {
                if (rule[0] === 'mix') result[key] = mix(read(rule[1]), read(rule[2]), rule[3]);
                else if (rule[0] === 'tone') result[key] = '#' + [1, 3, 5].map(i => Math.max(0, Math.min(255, parseInt(read(rule[1]).slice(i, i + 2), 16) + parseInt(rule[3].slice(i, i + 2), 16) - parseInt(rule[2].slice(i, i + 2), 16))).toString(16).padStart(2, '0')).join('').toUpperCase();
                else { const backgrounds = rule.slice(1).map(read); result[key] = Math.min(...backgrounds.map(bg => contrast('#FFFFFF', bg))) >= Math.min(...backgrounds.map(bg => contrast('#000000', bg))) ? '#FFFFFF' : '#000000'; }
            } else result[key] = read(rule);
            return result[key];
        };
        Object.keys(schema).forEach(get); return result;
    }
    const pairs = [
        ['text', 'bg', 4.5], ['text', 'surface', 4.5], ['heading', 'surface', 4.5], ['muted', 'surface', 4.5], ['muted', 'soft', 4.5], ['link', 'bg', 4.5],
        ['on-action', 'action', 4.5], ['on-action', 'action-hover', 4.5], ['on-primary', 'primary', 4.5], ['on-primary', 'primary-hover', 4.5], ['on-offer', 'offer', 4.5], ['on-offer', 'offer-hover', 4.5],
        ['header-text', 'header', 4.5], ['header-active', 'header', 4.5], ['footer-text', 'footer', 4.5], ['footer-muted', 'footer', 4.5], ['footer-link', 'footer', 4.5], ['hero-text', 'hero', 4.5], ['hero-text', 'hero-end', 4.5], ['hero-muted', 'hero', 4.5], ['hero-muted', 'hero-end', 4.5],
        ['field-text', 'field-bg', 4.5], ['field-placeholder', 'field-bg', 4.5], ['field-border', 'field-bg', 3], ['field-focus', 'field-bg', 3], ['focus', 'surface', 3],
        ['success', 'success-bg', 4.5], ['warning', 'warning-bg', 4.5], ['error', 'error-bg', 4.5], ['info', 'info-bg', 4.5], ['chat-text', 'chat-bubble', 4.5], ['chat-peer-text', 'chat-peer', 4.5],
        ['mail-header-text', 'mail-header', 4.5], ['mail-heading', 'mail-surface', 4.5], ['mail-text', 'mail-surface', 4.5], ['mail-muted', 'mail-surface', 4.5], ['mail-on-action', 'mail-action', 4.5]
    ];

function css(config) { return ['light', 'dark'].map(scheme => (scheme === 'dark' ? ':root[data-ab-theme="dark"]' : ':root') + '{' + Object.entries(resolve(config, scheme)).map(([k, v]) => '--ab-' + k + ':' + v).join(';') + '}').join(''); }
return {resolve,css,mix,contrast,pairs};}};
})();
