# Arabic/English public-site acceptance — 2026-10-03

## Scope and environment

Read-only public browser checks ran against the local Apache site at `http://localhost/wordpress/` with the current `car-dealer` theme and Auto Dealership Core plugin, WordPress 7.1.2, PHP 8.2.12 and the synthetic `wp-autobrands` development database. The browser script is `wp-content/plugins/auto-dealership-core/tests/browser-bilingual-site.cjs`. It blocks external browser requests and does not submit contact, registration, newsletter or transaction forms. The only form submission is a catalog GET search.

## Result

**53/53 browser checks passed.** Arabic defaults to RTL; English `?lang=en` renders LTR. Header/footer language controls have the correct active state and preserve the current page or account view. Internal navigation and catalog search retain English. Home, about, contact, finance, vehicle and offer archives, and login/register presentation were exercised. The contact form carries the selected language without being submitted. Finance editorial content shows only the selected language. No visible PHP warning, uncaught browser exception, missing local script/style, horizontal overflow or clipped language control was found at the checked widths (1440, 768, 390 and 320 pixels). Arabic contact was checked at 390 and 320 pixels.

The configured WhatsApp button is fixed and present; on mobile it does not cover the footer copyright. The footer omits draft legal links and its empty legal column, and the newsletter text/consent controls remain legible. A vehicle without a photo now displays an SVG instead of a font-dependent emoji. Local screenshots are under ignored `.tmp/bilingual-acceptance/` for home, contact and footer views; they are development evidence, not approved marketing media.

The review caught one runtime defect: an undefined shortcode tag prevented English translation of about/contact sections and emitted PHP warnings. The adapter now uses the registered shortcode tag. The header search also now targets the car catalog and keeps the chosen language. The footer newsletter inherited a white general-form panel with white labels; its dark-footer presentation and conditional legal column were corrected.

The read-only release preflight was rerun after these changes. Its result remains **8 PASS, 4 FAIL, 9 MANUAL**: local HTTP origin, unpublished legal drafts, synthetic data and `compatibility` catalog mode are still expected failures. External provider routes remain disabled. The older ZIP candidate described in `RELEASE-PACKAGE-2026-10-03.md` was built from commit `66cf4f2` and does **not** include these uncommitted language/theme changes; the builder correctly requires a reviewed, clean release commit before producing another artifact.

## Limits of this acceptance

This is local public presentation acceptance. It does not certify authenticated account actions, form delivery, screen-reader and keyboard review by a person, contrast on actual devices, approved English descriptions for each vehicle/offer, production-like caching/load or an HTTPS staging deployment. Legal/privacy publication and real-data catalog authority remain separate gates. The local WhatsApp number must be configured independently on staging/production.
