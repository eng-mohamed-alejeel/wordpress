/**
 * Car Dealer — Cookie Consent Manager
 * Handles cookie consent display, localStorage persistence, and preferences
 */
(function() {
    'use strict';

    var CONSENT_COOKIE_NAME = 'cd_cookie_consent';
    var CONSENT_DURATION = 30 * 24 * 60 * 60 * 1000; // 30 days in milliseconds
    var COOKIE_VERSION = '1.0';
    var TEMPLATE_URI = window.cdCookieConfig ? window.cdCookieConfig.templateUri : '';
	var LANGUAGE = window.cdCookieConfig && window.cdCookieConfig.language || (document.documentElement.lang.indexOf('en') === 0 ? 'en' : 'ar');
	function text(arabic, english) { return LANGUAGE === 'en' ? english : arabic; }

    // Cookie categories
    var COOKIE_CATEGORIES = {
        essential: { name: text('ضرورية', 'Essential'), description: text('ضرورية لعمل الموقع بشكل صحيح', 'Required for the website to work correctly'), default: true, enabled: true },
        analytics: { name: text('تحليلات', 'Analytics'), description: text('تساعدنا على فهم كيفية استخدام الزوار للموقع', 'Help us understand how visitors use the website'), default: false, enabled: false },
        marketing: { name: text('تسويقية', 'Marketing'), description: text('تُستخدم لتخصيص المحتوى والإعلانات', 'Used to personalize content and advertising'), default: false, enabled: false },
        preferences: { name: text('تفضيلات', 'Preferences'), description: text('تتذكر اختياراتك مثل اللغة والموقع', 'Remember choices such as language and location'), default: false, enabled: false }
    };

    // Initialize the consent manager
    function init() {
        if (hasConsent()) {
            // Consent already given, don't show banner
            return;
        }

        // Show banner after a short delay
        setTimeout(function() {
            showBanner();
        }, 1500);
    }

    // Check if user has already given consent
    function hasConsent() {
        var stored = localStorage.getItem(CONSENT_COOKIE_NAME);
        if (!stored) return false;

        try {
            var data = JSON.parse(stored);
            // Check if consent is still valid (not expired)
            if (data.expires && data.expires > Date.now()) {
                return true;
            }
            // Consent expired, need to ask again
            return false;
        } catch (e) {
            return false;
        }
    }

    // Save consent to localStorage
    function saveConsent(preferences) {
        var data = {
            version: COOKIE_VERSION,
            timestamp: Date.now(),
            expires: Date.now() + CONSENT_DURATION,
            preferences: preferences
        };
        localStorage.setItem(CONSENT_COOKIE_NAME, JSON.stringify(data));

        // Also set a server-side cookie for PHP access
        document.cookie = CONSENT_COOKIE_NAME + '=1; max-age=' + (CONSENT_DURATION / 1000) + '; path=/; SameSite=Lax';
    }

    // Show the cookie banner
    function showBanner() {
        var banner = document.createElement('div');
        banner.className = 'cd-cookie-banner';
        banner.id = 'cd-cookie-banner';
		banner.dir = LANGUAGE === 'en' ? 'ltr' : 'rtl';
		banner.lang = LANGUAGE;
        banner.innerHTML = getBannerHTML();
        document.body.appendChild(banner);

        // Trigger animation
        setTimeout(function() {
            banner.classList.add('cd-cookie-visible');
        }, 50);

        // Create overlay
        var overlay = document.createElement('div');
        overlay.className = 'cd-cookie-overlay';
        overlay.id = 'cd-cookie-overlay';
        document.body.appendChild(overlay);

        // Add event listeners
        setupBannerEvents(banner, overlay);

        // Add stylesheet if not already loaded
        loadStylesheet();
    }

    // Get banner HTML
    function getBannerHTML() {
        return '<div class="cd-cookie-container">' +
            '<div class="cd-cookie-content">' +
                '<div class="cd-cookie-icon">🍪</div>' +
                '<h3>' + text('نستخدم ملفات الكوكيز', 'We use cookies') + '</h3>' +
                '<p>' + text('نستخدم ملفات الكوكيز لتحسين تجربتك، وتحليل حركة المرور، وتخصيص المحتوى. يمكنك اختيار تفضيلاتك أدناه.', 'We use cookies to improve your experience, analyze traffic and personalize content. Choose your preferences below.') + '</p>' +
                '<div class="cd-cookie-actions">' +
                    '<button class="cd-cookie-btn cd-cookie-btn-primary" id="cd-cookie-accept-all">' + text('قبول الكل', 'Accept all') + '</button>' +
                    '<button class="cd-cookie-btn cd-cookie-btn-secondary" id="cd-cookie-accept-essential">' + text('الأساسية فقط', 'Essential only') + '</button>' +
                    '<button class="cd-cookie-btn cd-cookie-btn-settings" id="cd-cookie-settings-btn">⚙ ' + text('الإعدادات', 'Settings') + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    // Setup banner event listeners
    function setupBannerEvents(banner, overlay) {
        // Accept all
        document.getElementById('cd-cookie-accept-all').addEventListener('click', function() {
            var prefs = {};
            Object.keys(COOKIE_CATEGORIES).forEach(function(key) {
                prefs[key] = true;
            });
            hideBanner(banner, overlay, prefs);
        });

        // Accept essential only
        document.getElementById('cd-cookie-accept-essential').addEventListener('click', function() {
            var prefs = {};
            Object.keys(COOKIE_CATEGORIES).forEach(function(key) {
                prefs[key] = key === 'essential';
            });
            hideBanner(banner, overlay, prefs);
        });

        // Settings button
        document.getElementById('cd-cookie-settings-btn').addEventListener('click', function(e) {
            e.stopPropagation();
            showSettings(banner, overlay);
        });

        // Overlay click
        overlay.addEventListener('click', function() {
            // Don't hide if settings are open
        });
    }

    // Show settings modal
    function showSettings(banner, overlay) {
        var settings = document.createElement('div');
        settings.className = 'cd-cookie-settings';
        settings.id = 'cd-cookie-settings';
		settings.dir = LANGUAGE === 'en' ? 'ltr' : 'rtl';
		settings.lang = LANGUAGE;
        settings.innerHTML = getSettingsHTML();
        document.body.appendChild(settings);

        // Show overlay
        overlay.classList.add('cd-overlay-visible');

        // Add close handler
        settings.querySelector('.cd-cookie-settings-close').addEventListener('click', function() {
            hideSettings(settings, banner);
        });

        // Add overlay click handler
        overlay.addEventListener('click', function() {
            hideSettings(settings, banner);
        });

        // Add save button handler
        var saveBtn = settings.querySelector('.cd-cookie-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var prefs = collectSettings(settings);
                hideSettings(settings, banner);
                saveConsent(prefs);
            });
        }

        // Animate in
        setTimeout(function() {
            settings.classList.add('cd-settings-visible');
        }, 50);
    }

    // Get settings HTML
    function getSettingsHTML() {
        var items = '';
        Object.keys(COOKIE_CATEGORIES).forEach(function(key) {
            var cat = COOKIE_CATEGORIES[key];
            items += '<div class="cd-cookie-setting-item">' +
                '<div>' +
                    '<label>' + cat.name + '</label>' +
                    '<p>' + cat.description + '</p>' +
                '</div>' +
                '<label class="cd-cookie-toggle">' +
                    '<input type="checkbox" data-category="' + key + '"' + (cat.default ? ' checked' : '') + '>' +
                    '<span class="cd-cookie-toggle-slider"></span>' +
                '</label>' +
            '</div>';
        });

        return '<div class="cd-cookie-settings-header">' +
            '<h3>⚙ ' + text('تفضيلات الكوكيز', 'Cookie preferences') + '</h3>' +
            '<button class="cd-cookie-settings-close" id="cd-cookie-settings-close" aria-label="' + text('إغلاق', 'Close') + '">&times;</button>' +
        '</div>' +
        items +
        '<div style="margin-top:var(--cd-spacing-lg);display:flex;gap:12px;justify-content:flex-end;">' +
            '<button class="cd-cookie-btn cd-cookie-btn-secondary" id="cd-cookie-settings-cancel">' + text('إلغاء', 'Cancel') + '</button>' +
            '<button class="cd-cookie-btn cd-cookie-btn-primary cd-cookie-save-btn">' + text('حفظ التفضيلات', 'Save preferences') + '</button>' +
        '</div>';
    }

    // Collect settings from checkboxes
    function collectSettings(settings) {
        var prefs = {};
        settings.querySelectorAll('.cd-cookie-toggle input').forEach(function(input) {
            var category = input.getAttribute('data-category');
            prefs[category] = input.checked;
        });
        return prefs;
    }

    // Hide settings and banner
    function hideSettings(settings, banner) {
        settings.classList.remove('cd-settings-visible');
        document.getElementById('cd-cookie-overlay').classList.remove('cd-overlay-visible');
        setTimeout(function() {
            if (settings.parentNode) settings.parentNode.removeChild(settings);
        }, 300);
    }

    // Hide banner and save consent
    function hideBanner(banner, overlay, preferences) {
        banner.classList.remove('cd-cookie-visible');
        banner.classList.add('cd-cookie-hidden');
        overlay.classList.remove('cd-overlay-visible');

        setTimeout(function() {
            if (banner.parentNode) banner.parentNode.removeChild(banner);
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 500);

        // Save consent
        saveConsent(preferences);
    }

    // Load the CSS stylesheet
    function loadStylesheet() {
        var existing = document.getElementById('cd-cookie-css');
        if (existing) return;

        var link = document.createElement('link');
        link.id = 'cd-cookie-css';
        link.rel = 'stylesheet';
        link.href = (TEMPLATE_URI ? TEMPLATE_URI : '') + '/assets/css/components/_cookies.css';
        link.type = 'text/css';
        document.head.appendChild(link);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
