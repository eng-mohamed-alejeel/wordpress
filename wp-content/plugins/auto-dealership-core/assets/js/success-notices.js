(function () {
    'use strict';
    function initialize() {
        const notices = Array.from(document.querySelectorAll('[data-success-notice]'));
        if (!notices.length) return;
        const url = new URL(window.location.href);
        notices.forEach(notice => {
            (notice.dataset.noticeParam || '').split(',').filter(Boolean).forEach(key => url.searchParams.delete(key));
        });
        // Keep account view, language, filters and hash; do not add a history entry.
        if (url.href !== window.location.href) window.history.replaceState(window.history.state, '', url.href);
        notices.forEach(notice => {
            let timer, dismissed = false;
            const close = document.createElement('button');
            close.type = 'button'; close.className = 'adc-success-close';
            close.setAttribute('aria-label', window.adcSuccessNotices?.close || 'Dismiss notification');
            close.textContent = '×';
            notice.appendChild(close);
            function pause() { clearTimeout(timer); }
            function dismiss(immediate) {
                if (dismissed) return;
                dismissed = true; pause();
                if (immediate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { notice.remove(); return; }
                notice.classList.add('is-dismissing'); setTimeout(() => notice.remove(), 180);
            }
            function resume() {
                pause();
                if (!dismissed && !document.hidden && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
                    timer = setTimeout(() => dismiss(false), 8000);
                }
            }
            close.addEventListener('click', () => dismiss(false));
            notice.addEventListener('mouseenter', pause);
            notice.addEventListener('mouseleave', resume);
            notice.addEventListener('focusin', pause);
            notice.addEventListener('focusout', () => setTimeout(resume, 0));
            document.addEventListener('visibilitychange', () => document.hidden ? pause() : resume());
            window.addEventListener('pagehide', () => dismiss(true));
            window.addEventListener('pageshow', event => { if (event.persisted) dismiss(true); });
            resume();
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
    else initialize();
})();
