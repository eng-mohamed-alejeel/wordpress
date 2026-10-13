/* Accessibility enhancements for native WordPress markup, without changing core files. */
(function () {
    'use strict';
    const modalBackgrounds = new Set();
    let mediaReturnFocus = null;
    let activeMediaModal = null;
    let mediaOpener = null;
    const visibleMediaModal = () => [...document.querySelectorAll('.media-modal')].find(el => el.getClientRects().length > 0);
    document.addEventListener('focusin', event => {
        if (!activeMediaModal && !visibleMediaModal() && event.target instanceof HTMLElement) mediaReturnFocus = event.target;
    });
    function enhance() {
        // Menu items own their semantics; list wrappers are presentational.
        document.querySelectorAll('#wpadminbar li[role="group"]').forEach(el => el.setAttribute('role', 'none'));
        document.querySelectorAll('#wpbody-content header:not([role])').forEach(el => el.setAttribute('role', 'group'));
        // Native media items are checkboxes, so their container is a named group.
        document.querySelectorAll('.media-frame ul.attachments:not([role])').forEach(el => {
            el.setAttribute('role', 'listbox');
            el.setAttribute('aria-multiselectable', 'true');
            el.setAttribute('aria-label', document.documentElement.lang.startsWith('ar') ? 'مكتبة الوسائط' : 'Media library');
        });
        document.querySelectorAll('.media-frame .attachments > .attachment').forEach(el => {
            if (el.getAttribute('role') !== 'option') el.setAttribute('role', 'option');
            const selected = el.getAttribute('aria-checked') === 'true' ? 'true' : 'false';
            if (el.getAttribute('aria-selected') !== selected) el.setAttribute('aria-selected', selected);
        });
        document.querySelectorAll('.media-modal[aria-labelledby]').forEach(el => {
            const title = document.getElementById(el.getAttribute('aria-labelledby'));
            if (!title || !title.textContent.trim()) {
                const heading = el.querySelector('h1, h2, .media-frame-title');
                if (heading && heading.textContent.trim()) el.setAttribute('aria-label', heading.textContent.trim());
            }
        });
        const modal = visibleMediaModal();
        if (modal && !activeMediaModal) mediaOpener = mediaReturnFocus;
        document.querySelectorAll('#wpwrap, .wp-full-overlay').forEach(el => {
            if (modal && !el.contains(modal) && el.getAttribute('aria-hidden') === 'true' && !el.inert) {
                el.inert = true;
                modalBackgrounds.add(el);
            }
        });
        modalBackgrounds.forEach(el => {
            if (!modal || el.getAttribute('aria-hidden') !== 'true') {
                el.inert = false;
                modalBackgrounds.delete(el);
            }
        });
        if (!modal && activeMediaModal && mediaOpener && mediaOpener.isConnected) mediaOpener.focus({ preventScroll: true });
        activeMediaModal = modal;
        const controls = document.getElementById('customize-controls');
        document.querySelectorAll('#customize-controls .customize-section-title h3').forEach(el => el.setAttribute('aria-level', '2'));
        // Iris' 2D drag handle has no keyboard action. Its labelled HEX input is
        // the keyboard equivalent and represents the same complete color value.
        document.querySelectorAll('#customize-controls .iris-square-value').forEach(el => {
            el.tabIndex = -1;
            if (el.getAttribute('aria-hidden') !== 'true') el.setAttribute('aria-hidden', 'true');
        });
        if (controls && !document.getElementById('ab-customizer-main')) {
            const main = document.createElement('main');
            main.id = 'ab-customizer-main';
            main.setAttribute('aria-label', document.documentElement.lang.startsWith('ar') ? 'أدوات تخصيص الموقع' : 'Site customization controls');
            controls.before(main);
            main.append(controls);
            const heading = document.createElement('h1');
            heading.className = 'screen-reader-text';
            heading.textContent = document.documentElement.lang.startsWith('ar') ? 'تخصيص الموقع' : 'Customize site';
            controls.prepend(heading);
        }
        const announcements = document.getElementById('a11y-speak-intro-text');
        if (controls && announcements && !controls.contains(announcements)) controls.append(announcements);
    }
    enhance();
    // Media views are dynamic; only selection and visibility attributes need observation.
    let queued = false;
    new MutationObserver(() => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => { queued = false; enhance(); });
    }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['aria-checked', 'aria-hidden'] });
})();
