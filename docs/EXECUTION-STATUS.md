# Implementation execution status

Updated: 2026-10-03. This records completed increments, not completion of whole phases.

2026-10-03 priority-A acceptance follow-up: **690 isolated database/HTTP, 34 catalog browser and 33 account browser checks passed** on independent MariaDB port 33351. Syntax passed for 168 PHP and nine JavaScript/CommonJS files. The first restricted attempt passed the 687 base checks but could not start Chrome; the approved repeat completed all journeys and three additional database assertions. Screenshots were refreshed, the generated database was removed and the temporary server stopped. Source configuration/database were not contacted. No runtime fix or new release artifact was needed/created in this step. See `PRIORITY-A-ACCEPTANCE-2026-10-03.md`; launch gates remain open.

2026-10-03 priority-A stabilization: corrected the offline authorization fixture to require the dedicated vehicle-cost capability rather than finance read alone. All 52 authorization, 12 money and 16 pricing-policy checks pass; the changed PHP test passes syntax. No runtime permission, source record or deployment setting changed. Testing paths, current evidence and release-package status were corrected. Earlier paragraphs describing uncommitted bilingual edits record their state at that time; the pre-stabilization tree at `4893af9` was clean. Database/browser acceptance was not rerun for this test-only change. Final artifact, target-environment acceptance and the existing launch gates remain open. See `PRIORITY-A-STABILIZATION-2026-10-03.md`.

2026-10-03 bilingual public-site acceptance: 53 read-only local browser checks now pass for RTL/LTR, header/footer language controls, current-page navigation, catalog search, contact and finance presentation, account entry screens, WhatsApp and 1440/768/390/320-pixel layout. The review repaired an undefined shortcode-tag warning, corrected the catalog search target, made the footer newsletter legible, omitted the unpublished legal column and replaced the vehicle emoji placeholder with SVG. The release preflight remains 8 PASS, 4 FAIL, 9 MANUAL; the earlier ZIP does not contain these uncommitted changes. See `BILINGUAL-SITE-ACCEPTANCE-2026-10-03.md`.

2026-10-03 release preparation: a read-only CLI preflight now verifies the active pair, exact schema contract, HTTPS origin, legal/public content, demo data, catalog mapping/mode, provider safety, media and Git configuration tracking. After preserving the untouched WordPress example page as a draft and excluding `wp-config.php` from HEAD and index in commit `66cf4f2`, the local result is 8 PASS, 4 FAIL and 9 MANUAL; 45/45 source table fingerprints matched around a repeat read-only run. The local configuration remains on disk and old repository history still needs secret review. A 163-file plugin/theme code-only candidate package and SHA-256 manifest were built from that commit (`RELEASE-PACKAGE-2026-10-03.md`). See `RELEASE-PREFLIGHT-2026-10-03.md`. No public deployment was performed.

2026-10-03 editorial and launch-scope preparation: exact-content guards replaced the WordPress sample privacy draft on page 3 with an Arabic/English review draft, created terms page 41 as a draft, and corrected the published finance page 5 to describe only a non-binding estimate and internal enquiry. The former page content was backed up locally under ignored `.tmp`; the sample page 2 remains a draft. Both legal drafts contain explicit business/legal completion fields and are unpublished. `LAUNCH-DATA-AND-SCOPE-2026-10-03.md` records the user's decision to defer purchase orders, official stored PDFs, financial-history merges and external providers; retention automation is currently disabled (0 days). Source demo data, including two simulated sales, remains intact for development. The release preflight still reports 8 PASS, 4 FAIL and 9 MANUAL.

The user authorized realistic demonstration data in the local `wp-autobrands` database on 2026-10-02, superseding the earlier empty-business-database preference for development. The 1.28-focused acceptance now passes 687 isolated checks; the repeatable local seed created 12 synthetic vehicles, 6 customer/lead pairs and representative workflows. Existing editorial pages were preserved. See `VERIFICATION-1.28.0.md` and `DEVELOPMENT-DATA-2026-10-02.md`.

A populated local rollback rehearsal now passes for one representative transaction: the matching `b2fd4fe` prior pair read the synthetic sale/finance/payment/delivery data and wrote a quote/reservation/sale plus a rejected no-funds payment on a disposable database; the current pair read the resulting chain after switching back. The source's 45 table fingerprints were unchanged during that rehearsal. See `POPULATED-ROLLBACK-2026-10-02.md`. The separate-instance restore completed later is recorded below; legal/public deployment remains open.

A separate local MariaDB process and data directory now passed a populated restore and restart rehearsal: 45/45 table fingerprints matched before application bootstrap, 666/666 restored files matched, the copied plugin/theme read the business and public catalog records, both referenced media files resolved, and 45/45 target fingerprints survived restart. The source's 44 non-option tables stayed unchanged; three `wp_options` values changed concurrently during the exercise. See `INDEPENDENT-RESTORE-2026-10-02.md`. Off-site recovery and public deployment remain open.

## Current increment: 1.29.13 (deployable theme cleanup; acceptance partial)

- After the earlier empty-site rollback/source cutover evidence, the source database was deliberately seeded for development. Eight mapped vehicles are publicly eligible, two labeled example offers are eligible, all 12 car posts have brand/category terms, source schema/mapping checks pass and local home/car/offer/v1/v2 routes return HTTP 200. A second simulated sale has an explicitly labeled no-funds payment and a delivery only in `preparing` state. The later copied-data transactional rollback rehearsal is documented above; public deployment remains open.

- A post-upgrade source snapshot was restored into a disposable database and inspected with the current pair and commit `b2fd4fe` prior pair. Both retained schema `1.17.0`, 108 dealership REST routes, privacy hooks and core administrator capabilities; 12 shared administration screens rendered for each. The source's 45 table fingerprints were unchanged. This closes the empty-site read-only administration rollback smoke; populated transactional downgrade and public deployment remain. See `ROLLBACK-OPERATIONS-2026-10-02.md`.
- On 2026-10-02, the guarded local source upgrade applied the rehearsed nullable-margin repair and content-registry rewrite refresh after a logical/file snapshot. Schema `1.17.0` has no issues; `/offers/` and six other local Apache routes return 200, with 14 local assets available. Only `wp_options` changed checksum across 45 tables and no row count changed. Legal publication, public deployment and populated transactional downgrade remain. See `SOURCE-CUTOVER-2026-10-02.md`.
- On 2026-10-02, a disposable restore matched all 45 source database table fingerprints and all 662 copied file hashes. Actual published about/contact/finance pages and a same-commit prior plugin/theme pair (`b2fd4fe`, plugin `1.29.4`, theme `1.0`) passed public route/hook smoke; the source fingerprints remained unchanged at that step. The contact social adapter now omits unconfigured links. A staging-only schema retry exposed and repaired two legacy nonnullable margin columns; that source upgrade was subsequently completed as recorded above. Privacy is draft, terms is absent, and the example page has a `localhost` link. See `VERIFICATION-1.29.13.md`.
- On 2026-10-02, 657 isolated database/HTTP checks, 34 real-theme catalog browser checks, 33 real-theme account browser checks and 53 empty-site stock-theme switch checks passed. English catalog direction was repaired, and the moved plugin ownership was reflected in the isolated harness. The source business database was not contacted. See `VERIFICATION-1.29.13.md`.

- A static cutover review found and removed duplicate form wrapper declarations in the active theme. Compatibility catalog queries and intake now use the same availability rule for unmapped legacy cars; mapped cars continue to use the operational status and active branch. Newsletter-only configurations now receive the shared public form script. No business records were touched.
- The active theme `inc/` directory contains only account, catalog, contact, editorial presentation and appearance settings adapters. Former business PHP, unused statistics styling, admin assets and historical theme checks were moved to `docs/archive/car-dealer` and are never included at runtime. The footer stylesheet is bundled once in the generated main CSS.
- The plugin supplies read-only vehicle, offer preview, home-page and filter models plus account workspace target decisions. The theme no longer reads vehicle/offer metadata or selects home-page records directly; client-side intake, comparison and calculator fallbacks were removed.
- Appearance contact details and social links render only when configured. Fixed page IDs, broken relative links, false contact/map details, invented home-page figures and unsupported service guarantees were removed. Manually entered team identities remain intact, while their empty contact links were removed. Empty inventory sections and unavailable archive links do not render. Public CSS was rebuilt without the dormant admin module.
- The deployment rollback unit is a matching earlier theme and plugin release. No source or business database was seeded. The stock-theme switch and actual published-page/public prior-pair smoke passed on isolated staging. Legal publication and full operational downgrade remain pending.

## Previous increment: 1.28.0 (implementation and focused acceptance complete)

- Added schema 1.17.0 with the supplier directory, missing vehicle specification/acquisition fields and ordered finance-attempt history. The upgrade is additive and no source business data was created.
- Added capability-restricted supplier and vehicle-acquisition services/admin pages. Costs, supplier contacts, customs references, documents and internal notes never enter the public catalog response.
- Added four least-privilege dealership roles and dedicated supplier/cost capabilities while retaining the existing service-level branch and separation-of-duties checks.
- Added finance retry lineage and optional commercial terms without inventing provider credentials or mandatory field rules that have not been approved.
- Added account/login/role/capability audit hooks, `X-Request-ID`, an enveloped `v2` REST contract and an authenticated generated OpenAPI 3.1 description. Compatibility `v1` remains available.
- Purchase-order workflow, total-cost formula, cost-visibility sign-off, mandatory finance terms and document-retention policy remain explicit business decisions. Focused 1.28 acceptance passed on the current 1.29.13 code with 687 isolated database/HTTP checks; see `VERIFICATION-1.28.0.md`.

## Previous increment: 1.29.12 (account decisions and public fallback isolation; verification pending)

- The plugin now owns account workspace classification and the customer-only admin redirect decision. The theme consumes these facades for visible labels and navigation; its old capability logic lives in the account rollback file.
- The active public catalog delegates URL/language/filter policy to the plugin. Former URL and SEO decisions plus their hooks load from `catalog-legacy-policy.php` only for early rollback.
- Legacy shortcode car selection, comparison cookie parsing and finance defaults are isolated with the other guarded bootstrap helpers. Raw offer metadata reads are lazy and limited to offer rollback or editorial preview; published cards and details use `PublicOfferView`.
- No schema or business data changed. Runtime, database, browser and theme-switch acceptance remain pending. The next gate is an explicit theme-switch and independent rollback review; compatibility files remain available until that gate passes.

### Previous 1.29-E slice: 1.29.11

- The active theme contact file now contains form rendering and identity presentation; its former table installer, public AJAX writers and staff SQL are isolated in `engagement-legacy.php` behind independent early plugin-ownership checks.
- The theme now loads `inc/crm.php` and `inc/customer-workflow.php` only when an account, request, intake, marketing, engagement or CRM rollback path needs them. Normal plugin-owned requests do not load those legacy business files.
- `functions.php` now conditionally loads its former content registry, vehicle metadata editor, direct-email lead writer and comparison AJAX handler from `inc/theme-legacy-core.php`; the active bootstrap keeps appearance and rendering adapters.
- Existing action names, pages and compatibility files remain available for early rollback. No schema or business data changed. Runtime, browser, database and theme-switch acceptance remain pending. The remaining active-theme business helpers still need review before 1.29-E can close.

### Previous 1.29-E slice: 1.29.10

- `PublicOfferView` now filters the main offer archive at query time. The SQL follows the same published-car, inventory mapping, price and expiry rules as the public offer model, so `found_posts` and page links exclude ineligible offers.
- `inc/accounts.php` delegates active account actions and request history to plugin services. Its former role/auth/profile writer and direct-SQL history renderer were isolated in `account-legacy-actions.php` and `account-legacy-requests.php`, loaded only under their independent early rollback switches.
- No schema or content record changed. Runtime, database, browser and theme-switch acceptance remain pending. The remaining active-theme business helpers still require cleanup before 1.29-E is closed.

### Previous 1.29-E slice: 1.29.9

- `PublicOfferView` now makes public offer eligibility and price decisions for the active theme and structured-data emitter. Published offers require a positive price, valid nonexpired date and eligible linked vehicle. Invalid singular offers return 404; authorized previews remain available.
- Active offer card and detail templates consume the plugin model. At the time of this slice, the archive suppressed invalid cards but its post query still counted them; 1.29.10 closes that gap.
- `CustomerRequestView` now selects a bounded customer-owned page of message or booking projection rows. Missing schema/storage fails closed; cancellation is shown only for pending/confirmed bookings linked to the signed-in account's core customer. The theme renders the model and retains direct SQL only under an early rollback flag.
- The account contact form now uses the durable shortcode path. No schema, page or business record is created by this increment. Runtime, database and browser acceptance remain pending.

### Previous 1.29-E slice: 1.29.8

- `CatalogPresentation` now owns URL-bound catalog language, allowlisted language-switch URLs, archive filter indexing policy, canonical URLs and Arabic/English alternate links. `car-dealer` retains its visual switch and English presentation copy but delegates the decisions to the plugin.
- `PublicStructuredData` now emits JSON-LD from eligible published car records and validated, unexpired linked offers. It does not claim an in-stock offer without an actual positive price, and the active theme no longer loads its old JSON-LD emitter.
- No schema or content records changed. Stock-theme, duplicate-hook, Arabic/English SEO and browser acceptance have not been run.

### Previous 1.29-E slice: 1.29.7

- `PublicShortcodes` now registers all 18 recorded durable names through the plugin. The theme supplies HTML through `adc_shortcode_{tag}_html` filters, with the early `adc_core_public_shortcodes_enabled` flag retaining controlled theme registration for rollback.
- The plugin provides bounded cars, comparison, calculator and contact-form view models. Car IDs come from the central public-catalog query, and comparison IDs come from `VehicleComparison`; the active theme renders those models without selecting its own active records.
- Neutral plugin output keeps cars, comparison, calculator and contact intake usable with a stock theme. Presentation-only `ab_*` sections and testimonials stay empty without a theme renderer or approved content source.
- Public contact, booking and newsletter submissions now use the plugin script and existing AJAX contract. The theme script defers when the plugin script is present, and retry keys remain stable until the payload changes or the submission succeeds.
- Removed hard-coded, fictitious customer testimonials from both active theme renderers while preserving their shortcode names. No testimonial records were created.
- Updated the documented adapter API in `THEME-ADAPTER-API.md`. Version 1.29.7 changes no schema and creates no editorial, business or sample record during upgrade or rendering. Acceptance verification remains pending.

### Previous 1.29-D slice: 1.29.6

- Removed automatic editorial-page inserts and updates from `admin_init` and `after_switch_theme`. Administration visits and theme switches no longer create pages or overwrite manual content.
- Added `EditorialPageSetup` and the administrator-only `adc-editorial-setup` screen. The active theme supplies bounded presentation blueprints; the plugin reports page state and creates selected missing pages as drafts only after a nonce-protected request with a required reason.
- Existing pages in any status are preserved. Creation is serialized and commits its minimized audit event in the same transaction; a page, template or audit failure rolls the selected batch back.
- Made dormant `inc/auto-pages.php` inert so it cannot reactivate duplicate automatic writes. Privacy and terms pages are not fabricated without approved legal copy.
- Version 1.29.6 changes no schema contract and creates no business, editorial or sample data during upgrade or normal requests. Automated, database and browser verification has not been run for this increment.

### Previous 1.29-D slice: 1.29.5

- Added a plugin-owned `WorkspacePage` at `adc-workspace` for every role with `adc_view_workspace`. Each card is capability-gated and points only to an existing plugin or WordPress editorial screen.
- Separated the operational inventory list into `adc-inventory`. Vehicle creation, state transitions, location changes and VIN corrections return to that screen, and the active account template points inventory staff there.
- Added a neutral plugin stylesheet loaded only on the workspace home. No dormant theme administration CSS or JavaScript is loaded or copied.
- Classified the dormant theme administration modules and assets in `THEME-ADMIN-CLASSIFICATION.md`. Their legacy role creation, direct post/meta writes, projection counters, user-role form and theme admin shell remain inactive.
- Version 1.29.5 changes no schema contract and creates no business, editorial or sample data. Automated, database and browser verification has not been run for this increment.

### Earlier 1.29-D slice: 1.29.4

- Began 1.29-D by moving the existing message, test-drive booking and newsletter administration slugs under the plugin's `adc-workspace` menu. These pages no longer require a theme menu or theme callback.
- Added a bounded `EngagementQuery` service. Message and booking reads enforce the central active-branch/owner predicate; unmapped history remains administrator-visible and read-only. Subscriber reads require the dedicated `adc_view_marketing_subscribers` capability assigned to marketing, general managers and administrators.
- Reused the core request workflow for status, reply and appointment updates and return successful submissions to the originating staff list. No legacy theme request writer is reactivated.
- Added a plugin-owned stylesheet loaded only on the three engagement screens. The theme retains guarded renderer definitions for rollback but does not register their menus while plugin ownership is enabled.
- Updated the account presentation to recognize plugin staff roles and point its workspace cards at plugin administration URLs. Dormant theme dashboard, CRM, inventory and settings URLs are no longer exposed there.
- Version 1.29.4 changes no schema contract and creates no business or sample data. Automated, database and browser verification has not been run for this increment.

### Prior ownership slices through 1.29.3

- Repository inventory confirmed that the active theme registered the vehicle content model, saves vehicle metadata, installs legacy engagement tables, handles public writes, registers durable shortcodes and loads account/CRM/customer workflow logic.
- The target boundary is now explicit: the plugin owns all durable dealership contracts, writes, handlers, permissions and operational administration; the theme owns presentation, templates, public assets and guarded rendering adapters.
- The first code slice now adds a plugin-owned content registry for `car`, `car_offer`, `car_brand`, `car_category`, their current public slugs and legacy-compatible metadata keys. The theme checks a stable ownership facade and skips duplicate registration/rewrite work.
- The first 1.29-C cutover moves `car_dealer_lead`, contact, booking and newsletter AJAX ownership into the plugin. `PublicIntake` is the active lead boundary; newsletter activation requires explicit consent, honeypot/rate controls and an audit-atomic write.
- The plugin now owns the three legacy engagement table definitions under a versioned installation lock. Message/booking compatibility copies remain temporarily writable by plugin services because the current account and `RequestWorkflow` views still require their IDs. The theme installer and handlers are guarded and become rollback paths only when the corresponding plugin ownership filter is explicitly disabled.
- The plugin no longer calls the theme to build a booking account URL; the theme supplies that optional presentation URL through `adc_customer_account_url`.
- Vehicle and offer post editors now run from the plugin. They preserve the existing keys and nonces, validate bounded values, audit changed field names without recording values, and restore all touched metadata if a write or audit event fails.
- A mapped vehicle's legacy inventory status is read-only and synchronized from the operational vehicle. Offer eligibility uses the mapped operational vehicle and active branch when present, falls back to the existing post-status contract only for unmapped posts, rejects duplicate mappings and keeps an invalid public offer as a draft.
- Theme vehicle/offer meta-box, save and admin-column hooks are guarded. Dormant advanced/inventory/offer modules also check the ownership facades before registering duplicate editors or writers.
- Customer registration, sign-in, profile updates, marketing preferences and booking cancellation now enter through plugin services while the theme retains the account template and URL adapter. The customer role is provisioned by the plugin, and anonymous account attempts use the atomic request limiter.
- The plugin registers `cd_crm` as private read-only history and audit-retires profiles after an account is linked to a core customer. The legacy theme CRM editor, export, capture, reconciliation and request-update hooks are inactive under the ownership facade; current operations use `LeadService`, `RequestWorkflow` and the `adc-crm` workspace.
- Public comparison state and finance estimates now enter through `PublicTools`. Comparison accepts the two existing cookie formats, admits at most four vehicles that satisfy the active catalog visibility rules and remains browser-owned. The calculator validates bounded inputs and returns a provider-neutral whole-SAR estimate without creating a lead, finance request or approval.
- The theme retains the calculator form, comparison button and card-grid markup. Its active JavaScript defers to the plugin asset, and its old comparison AJAX path is registered only when the early ownership filter is disabled.
- The compatibility-retirement decision is now executable and measurable through `MigrationInventory`: message/bookings remain controlled projections while account, request-workflow and privacy code depends on them; subscribers remain the canonical consent store; `cd_crm` remains private read-only history. The report contains aggregate counts and never exposes customer rows.
- Removal is blocked until native request history/updates/privacy handling, complete reconciliation, count parity and a backup/rollback rehearsal are accepted. This increment performs no drop, delete, conversion or automatic backfill.
- The static active/dormant hook and load baseline is recorded in `THEME-HOOK-INVENTORY.json`. Runtime confirmation remains, followed by plugin-owned admin assets, public adapters and reversible removal of compatibility hooks.
- Existing identifiers and records are preserved. Dormant theme files will be classified before reuse or removal. No business record, reference value, page or sample content will be inserted by this increment.
- Detailed scope and acceptance gates: `THEME-PLUGIN-SEPARATION.md`. Automated, database and browser verification has not been run for this increment.

## Previous increment: 1.27.0 (implementation and local acceptance complete)

- Declared exactly three anonymous core REST pairs and added a regression inventory that rejects any unreviewed anonymous exposure.
- Replaced best-effort transient throttling with atomic fixed-window InnoDB policies. Stored identity is HMAC-only; storage failure returns 503 and an exceeded policy returns retry-aware 429.
- Added default-deny forwarded-header handling, bounded trusted exact/CIDR proxies, scheduled bounded cleanup and a restricted aggregate security page.
- Raised the plugin to 1.27.0 and schema to 1.16.0. No business/reference/sample data, provider, endpoint, credential or outbound request was added.
- Local acceptance passes 654 isolated database/HTTP checks plus 105 plugin PHP, 41 theme PHP, 8 JavaScript/CommonJS, 48 authorization, 12 money and 16 pricing-policy checks. The intentionally empty source database was never contacted. See `SECURITY-HARDENING.md` and `VERIFICATION-1.27.0.md`.

## Previous increment: 1.26.0

- Added an audited central activation policy. A route stays disabled unless an authorized manager enables it after all safe readiness checks pass; runtime publication rechecks readiness and rejects direct option tampering.
- Added structured provider results plus the additive `adc_integration_receipts` ledger for pending, accepted, rejected and mismatched acknowledgements. Full remote references remain restricted; operator reads expose SHA-256 prefixes only.
- Added verified-webhook intake after adapter-owned signature validation, idempotent duplicates, early acknowledgement linking and contradiction detection.
- Added optional asynchronous polling through `ReconciliationContract`, audited reconciliation requests, restricted integration capabilities and an Arabic readiness/matching workspace.
- Raised the plugin to 1.26.0 and schema to 1.15.0. No provider, endpoint, credential, outbound request or source business record was added.
- Local acceptance passes 640 isolated database/HTTP checks, including 19 focused upgrade, readiness, authorization, activation compensation, receipt, early/duplicate/conflicting acknowledgement, reconciliation and output-minimization assertions. The disposable database was removed and the intentionally empty source database was never contacted. See `VERIFICATION-1.26.0.md`.
- Real provider adapters, signature algorithms, credentials, consent/templates, provider staging execution and external alert channels remain pending their actual contracts.

## Previous increment: 1.25.0 (implementation and local acceptance complete)

- Added a provider-neutral `AdapterContract`, a one-owner event registry and the `adc_integrations_register` lifecycle hook. Invalid event declarations and duplicate route ownership are rejected.
- Added default-disabled transactional producers for reservation confirmation, sale approval, finance submission/decisions, verified payments and final delivery release.
- Enabled routes persist minimized subject/branch/state/version references through the durable outbox inside the owning business transaction. Missing adapter routes or outbox persistence fail closed and roll back the mutation and audit.
- No provider adapter, credential, endpoint, outbound request or sample business record was added. Schema remains 1.14.0.
- Local acceptance passes 621 isolated database/HTTP checks, including 11 focused contract, routing, minimization, dispatch and rollback assertions. The disposable database was removed and the intentionally empty source database was never contacted. See `INTEGRATION-CONTRACTS.md` and `VERIFICATION-1.25.0.md`.
- Provider-specific adapters, approved templates/consent policies, acknowledgements, reconciliation, production scheduler monitoring and external alerts remain.

## Previous increment: 1.24.0 (implementation and local acceptance complete)

- Added **التقارير التشغيلية**, a read-only aggregate workspace for inventory, leads, reservations, quotations, sales, deliveries and current operational exceptions.
- Added `adc_view_reports` for sales managers, general managers, auditors and administrators. Every query uses the central active-branch policy; only administrators with global scope can see all branches.
- Added a nonce-protected UTF-8 CSV export. It contains aggregates only, neutralizes spreadsheet formulas, and is withheld unless `operations.report_exported` is persisted successfully.
- Customer names, contact details, VINs, stock numbers and document references are absent from both the screen and CSV. Any failed section query rejects the complete report.
- Local acceptance passes 610 isolated database/HTTP checks, including 13 focused report assertions. The disposable database was removed and the intentionally empty source database was never contacted. Schema remains 1.14.0. See `OPERATIONAL-REPORTS.md` and `VERIFICATION-1.24.0.md`.
- Provider contracts, approved domain-event producers, provider adapters, reconciliation, production scheduler monitoring and external alerts remain the next integration work.

## Earlier increment: 1.23.0 (implementation and local acceptance complete)

- Added a durable local outbox with hashed idempotency, strict minimized payloads, payload-integrity checks, atomic worker leases, expired-lease recovery, bounded backoff and five-attempt terminal failure.
- Added a five-minute WP-Cron worker and restricted **Audit Log → مراقبة المهام** page. General managers/administrators can retry a terminal event with a required audited reason; auditors have metadata-only read access. Payloads and replay keys are never displayed.
- Raised the schema to 1.14.0 through an additive migration that preserves the prior outbox rows. No external adapter, credential, outbound message, domain-event producer or sample business record was added.
- Local acceptance passes 597 isolated database/HTTP checks, including 21 focused outbox assertions and a real two-process claim race, plus 48 authorization, 12 money and 16 pricing-policy checks. Syntax passes across 87 plugin PHP files, 41 theme PHP files and 8 JavaScript/CommonJS files. The generated database was removed and the intentionally empty source database was never contacted. See `OUTBOX-OPERATIONS.md` and `VERIFICATION-1.23.0.md`.
- Provider contracts, event producers, consent/template policy, reconciliation, production scheduler monitoring and external alerts remain the next integration work.

## Earlier increment: 1.22.0 (implementation and local acceptance complete)

- Added **Dealership Core → Catalog cutover** for the new-installation setup sequence. It shows active reference counts, cutover readiness, bounded discrepancy details with complete totals, and a reconciliation fingerprint without exposing VIN, cost, customer or financial fields.
- Added a shared administrator-only service for one-to-one operational vehicle/WordPress `car` post mapping. Every change requires a reason and commits with `vehicle.catalog_mapping_changed`; invalid targets, conflicting posts, unauthorized users and audit failures fail closed. Identical retries are idempotent.
- Tightened authoritative activation to require a current schema, a nonempty operational catalog, published vehicle posts and at least one eligible public row, in addition to zero unmapped published posts, duplicate mappings and invalid targets.
- Local acceptance passes 576 isolated database/HTTP checks including 14 mapping/cutover assertions, 34 catalog-browser checks, 33 account-browser checks plus three post-journey assertions, seven standalone DOM checks, 48 authorization checks, 12 money checks, 16 pricing-policy checks, and syntax across 84 plugin plus 41 theme PHP files and three Node scripts. The complete opt-in browser runner printed 579 checks after its three post-journey assertions. The disposable database was removed and the intentionally empty source database was not contacted. Schema remains 1.13.0. See `VERIFICATION-1.22.0.md`.
- Compatibility remains selected. Actual branches, brands, locations, staff assignments, vehicles and editorial posts must be entered using real business values before the new workspace can produce an activation-ready result.

## Previous increment: 1.21.0 (implementation and local acceptance complete)

- Added an explicit `lang=ar|en` catalog contract with Arabic as the default, a visible language switch, document and component RTL/LTR semantics, localized prices, filters, details, lead forms and AJAX responses.
- Added localized canonical URLs plus `ar`, `en` and `x-default` hreflang links while keeping filtered result pages out of the search index.
- Primed mapped WordPress post/meta caches in the central catalog read model and added a 240-vehicle isolated performance scenario. The cold page, total and filter-option path used 10 queries in 0.028-0.056 seconds against a budget of 25 queries and 3 seconds.
- Added structural accessibility coverage for landmarks, heading count, form labels, action names, duplicate IDs and image alternatives, plus English responsive screenshots at 1440, 768, 390 and 320 pixels.
- Local acceptance passes syntax across 81 plugin and 41 theme PHP files, all three Node scripts, 48 authorization checks, 12 money checks, 16 pricing-policy checks, 565 isolated database/HTTP checks, 34 real-theme catalog browser checks, 33 account-browser checks plus three post-journey database assertions, and seven standalone DOM checks. The source database was not contacted. See `VERIFICATION-1.21.0.md`.
- Compatibility remains selected because the operational database is intentionally empty. Authoritative activation still requires actual branch/vehicle setup, unique post mappings and real count/sample reconciliation.

## Earlier increment: 1.20.0 (implementation and local acceptance complete)

- Added the plugin-owned public catalog query boundary and expanded its REST contract across vehicle identity, specification, range, branch, search and allowlisted sort fields.
- Added an audited cutover setting and readiness counts. `compatibility` remains the default because the operational database is intentionally empty; `authoritative` activation rejects schema drift, unmapped published posts and duplicate mappings, then hides every unavailable, unpublished or inactive-branch vehicle.
- Connected mapped theme cards, vehicle details and schema.org output to operational inventory, while retaining legacy rendering for unmapped posts during compatibility mode.
- Added shareable archive filters, filtered-URL noindex/canonical output, result announcements and focus-visible controls.
- Local acceptance passes syntax across 80 plugin and 41 theme PHP files, 48 authorization checks, 12 money checks, 16 pricing-policy checks, 562 isolated database/HTTP checks, 19 real-theme catalog browser checks, 33 account-browser checks plus three post-journey database assertions, and seven standalone DOM checks. The isolated server/data directory was removed and the source database was not contacted. See `VERIFICATION-1.20.0.md`.
- Before activation: create/reference actual branches and vehicles, map `public_post_id`, reconcile eligible counts and samples, then select the authoritative source in **Dealership Core → Settings**. See `PUBLIC-CATALOG-CUTOVER.md`.

## Previous increment: 1.19.0 (implementation and local acceptance complete)

- Centralized deterministic quote fees, explicit dated promotions, discounts, subtotal, VAT and total. Immutable versions now retain all price components plus seller name, tax number, address and phone.
- Added frozen sales-manager/general-manager discount tiers with configured ceilings and before/after gross-margin snapshots.
- Added configured reservation deposit snapshots and independent evidence recording/review. Unverified references do not become deposits; verified deposits count once toward settlement, and cancellation requires an independently reviewed refund before inventory release.
- Added configurable required delivery documents, audited evidence references and server-side approval/release gates.
- Added finance and delivery admin controls for deposit evidence and delivery documents, plus approval-tier and margin evidence in the discount queue.
- Plugin 1.19.0 targets additive schema 1.13.0. Local acceptance passes syntax across 79 plugin and 40 theme PHP files, 48 authorization checks, 12 money checks, 16 pricing-policy checks, 541 isolated database/HTTP checks, 33 real-theme Chromium checks plus three post-journey database assertions, and seven separate public-intake DOM checks. The isolated schema was installed and removed; no source database migration or sample-data insertion was performed. See `VERIFICATION-1.19.0.md`.

## Previous increment: 1.18.0 (implementation and local acceptance complete)

- Added explicit authenticated marketing opt-in/opt-out in the account workspace and `GET`/`POST /account/preferences`. The preference exists before the first enquiry, synchronizes to an existing canonical customer and is recorded with a minimized audit event.
- Added immediate refresh of an existing account-linked Core customer after WordPress display-name, email or phone changes. It never creates a customer or claims one by contact equality.
- Added deterministic retirement of legacy `cd_crm` posts using only their recorded account ID. Retired records leave active CRM counts/search/export, reject writes, and are readable only by administrators through a separate historical view.
- Extended privacy export/erasure to legacy CRM profiles and free-text activities. Schema remains 1.12.0; no source database migration, backfill or sample data was executed.
- Code is 1.18.0. Local acceptance passes syntax across all 76 plugin and 40 theme PHP files, 48 authorization checks, 12 money checks, 515 isolated database/HTTP checks, 33 real-theme Chromium checks plus three post-journey database assertions, and seven separate public-intake DOM checks. Preference transactions, immediate profile hooks, legacy capabilities and privacy rollback/success are covered. See `VERIFICATION-1.18.0.md`.

## Previous increment: 1.17.0 (covered local integration scenarios passed)

- Authenticated theme intake creates/reuses a customer by the current account ID under a unique nullable key and row locks. Guest/REST contact values do not acquire account ownership. Profile refresh and linkage are audited inside intake's transaction.
- Added administrator-only duplicate candidates, preview and CRM-only merge via REST and **Dealership Core → مراجعة ملفات العملاء**. Merge requires independently reviewed identity, an evidence reference, a current revision and identical contact/branch/owner scope. Financial/documentary source references, different account owners and merge chains are blocked.
- Consolidation moves leads, appends activities, clears source contact fields and keeps a merge reference. Consent is conservatively combined. Customer scope locks active records and rejects merged source IDs for new operations.
- Login/profile hooks no longer create/synchronize duplicate theme account CRM posts while the core identity service is present. Existing legacy profiles are preserved; canonical profile refresh occurs on authenticated enquiry, not immediately on every account edit.
- Privacy export/erasure includes linked account identity and can follow the current WordPress account across old-email request copies. Erasure/retention removes account links; merged tombstones are skipped by retention.
- Code 1.17.0 / schema 1.12.0. The 2026-09-29 follow-up passes syntax for 74 plugin PHP files and seven theme files, 48 authorization checks, 12 money checks, 490 isolated database/HTTP checks, 27 real-theme Chromium journey checks and seven separate public-intake DOM checks. It closes the previously listed account journey, responsive, mixed-race and legacy cursor/pagination cases; see `ACCOUNT-JOURNEY-VERIFICATION.md`. No source database bootstrap, migration, merge or sample-data insertion was performed.
- This closed the bounded account-linkage/CRM-consolidation increment. The subsequent 1.18.0 implementation adds preference management, immediate profile synchronization and legacy-profile retirement/access; historical claims and financial/documentary merges remain excluded. See `CUSTOMER-IDENTITY.md` and `CUSTOMER-PREFERENCES.md`.

## Previous increment: 1.16.0 (covered by subsequent 1.17 verification)

- Centralized linked message/booking replies, status changes, appointment updates and customer cancellation in `RequestWorkflow`; staff writes require active branch/ownership scope, customer cancellation requires the recorded account ID.
- Added a nonce-protected form in CRM history and theme request tables, GET/PATCH `/leads/{id}/request`, and POST `/bookings/{id}/cancel`. Stale staff revisions return 409; closed requests cannot reopen or reschedule.
- Compatibility update, activity history, lead timestamp and minimal audit commit atomically. Confirmation/rescheduling checks vehicle availability and the mapped branch. SQL failures never select the legacy fallback.
- Scoped theme request lists, related request lists and dashboard counters, including pagination totals. Unmapped legacy staff access is administrator-only. Core sales roles use the CRM form directly.
- Reconciliation skips already-linked records and advances its cursor; duplicate CRM capture remains suppressed even when public intake creation is switched back to the legacy handler.
- Schema remains 1.11.0. Static checks passed for 66 plugin PHP files and four changed theme files; `git diff --check` passed. No integration/HTTP/browser tests were added or run. The original database was not bootstrapped or changed; no sample data was inserted.
- Subsequent 1.17.0 work adds authenticated account linkage and reviewed CRM-only consolidation. Covered 1.15.0–1.16.0 scenarios now pass in `VERIFICATION-1.17.0.md`; full theme journeys, mixed races and legacy cursor/pagination cases remain open. Duplicate profile retirement and broader legacy CRM profile access remain implementation work.

## Previous increment: 1.15.0 (covered by subsequent 1.17 verification)

- Unified REST enquiries and theme contact/test-drive creation in `PublicIntake`, with normalized contact identity, explicit consent, bounded context, vehicle/branch/date checks, a shared IP limit and a honeypot.
- Added optional UUID v4 replay protection with a unique HMAC key and payload fingerprint. Core customer/lead/activity/audit and the customer-account compatibility row share one transaction; core-owned submissions skip duplicate legacy request CRM capture.
- Added paginated, branch/owner-scoped activity history to REST and the CRM staff screen. Activity creation now updates the lead timestamp and supplied follow-up date.
- Erasure/retention clears replay payload fingerprints; retention checks and anonymizes completed, old compatibility copies in the same transaction. Active copies and SQL/storage failures block anonymization.
- Plugin 1.15.0 / schema 1.11.0. Added `CRM-INTAKE.md` with contracts, feature switch, rollback boundaries and the pending acceptance checklist.
- Static checks passed: 64 plugin PHP files plus two changed theme files, JavaScript syntax for the new plugin form script, and `git diff --check`. No integration/HTTP/browser tests were run in this increment. Existing 1.14.0 results below do not cover these changes.
- The user's manual theme edits were preserved. No WordPress bootstrap, source database write, service restart or sample-data insertion was performed during this increment. Schema application is pending the normal WordPress installer lifecycle.
- CRM remains partial: later legacy request replies/cancellations/rescheduling, account-profile synchronization and legacy staff scope are still theme-owned. Secure matching/merge and full read/write cutover are next; public email/phone values never trigger automatic merging.

## Previous increment: 1.14.0 (local integration verification passed)

- Added eleven public vehicle specification fields, validation, audited branch-scoped editing, a staff form and REST PATCH. Editing is locked during reservations, sales and delivery.
- Unified vehicle import/reconciliation field mapping; preserved decimal SAR prices, stock/mileage aliases and certified condition. Missing/unknown inventory status is blocked. Existing target rows are not overwritten.
- Added import source serialization, VIN/stock collision reporting in dry runs and atomic initial movement history.
- Hardened sale cancellation SQL failure handling and owner separation; unresolved finance includes `under_review`. A resolved hold captures an inspection baseline, requiring a subsequent passed inspection.
- Hardened refund balance failures, completed-request replay, finance-list capability checks and cancellation audit links.
- PHP syntax: all 62 plugin PHP files passed; 48 offline authorization checks and 12 money checks passed.
- The independent test MariaDB server on port 33317 completed 365 database/HTTP checks, including 98 additions for 1.14.0, without loading source `wp-config.php` or contacting its database. The generated database was removed. Detailed evidence and limits: `VERIFICATION-1.14.0.md`.
- Added concurrent import, additive upgrade, specification HTTP/security, migration rollback and cancellation/refund SQL failure coverage. Fixed malformed serialized identity/status metadata handling discovered during review.
- After the user's manual repairs, source MariaDB was verified running normally on port 3306 with recovery mode 0. A local protected-copy rehearsal restored all 40 tables with matching counts/checksums and all 576 archived files with matching SHA-256 values. See `RESTORE-REHEARSAL-2026-09-27.md`. The user confirmed that the empty business dataset is intentional. Historical-data recovery and representative legacy migration are not applicable to this new deployment; that clarification is resolved.

### Remaining ordered delivery work

1. Complete pending acceptance for 1.28.0 and the implemented 1.29.0–1.29.7 ownership slices. Continue 1.29-E by moving catalog language/SEO policy, offer models, account-request presentation and structured-data values behind plugin adapters while the theme keeps rendering. Preserve identifiers and maintain one active owner throughout.
2. Prepare the new-installation setup using actual branch, brand, location and staff assignments when supplied. Current-state backup/restore passed; no historical restore/import is required.
3. Local 1.18.0 verification passes 515 database/HTTP checks, 33 real-theme Chromium checks plus three post-journey database assertions, and seven separate form-script checks. Historical claims and financial/documentary merging apply only if later required; no historical import is needed for this empty deployment.
4. Pricing fees/promotions/discount tiers, reservation deposit policy, branded quote output and delivery document gates are implemented and locally accepted in 1.19.0. Complete provider/ERP reconciliation and business review of the configured values.
5. Enter actual reference/inventory/editorial values, use **Dealership Core → Catalog cutover** to reconcile and audit every intended public mapping, then activate/review the authoritative catalog. Synthetic Arabic/English responsive journeys, localized AJAX, structural accessibility checks and the 240-vehicle local budget pass; complete human accessibility review and production-like staging load/cache measurement.
6. The local outbox/retry worker, job monitor, aggregate operational reports, provider-neutral contracts, approved transactional producer catalogue, activation gate, acknowledgement ledger and reconciliation boundary are complete through 1.26.0; the application public-route inventory and atomic limiter are complete in 1.27.0. Implement provider-specific ERP/payment/finance/message transports, signatures and external alert delivery after contracts and credentials are supplied, then configure the actual proxy/WAF boundary.
7. Rehearse controlled cutover/rollback and satisfy the security, browser, operational and business release gates. Overall readiness remains FAIL; the full enterprise plan is not complete.

## Increment 1: central branch authorization

Completed the first bounded security increment from phases 1–2 of `IMPLEMENTATION-PLAN.md` after reviewing the current bootstrap, schema, roles, services and staff screens.

| Item | Evidence/status |
|---|---|
| Current repository baseline | Existing edits in wp-config.php and theme files, plus untracked plugin/docs/media, were present before this increment. This increment changes core plugin files and documentation only. |
| Local PHP | CLI 8.2.12, verified with C:/xampp/php/php.exe. Web-server PHP version is not established. |
| WordPress | Local wp-includes/version.php declares 7.1.2. Runtime activation and production environment are not established. |
| Shared branch policy | Implemented in src/Security/BranchScope.php; existing service facade delegates to it. |
| CRM authorization | Owned leads require both current branch and ownership; branchless managers cannot read or change unassigned intake. |
| Operational reads | Vehicle detail/list and CRM/transfer/approval/finance/delivery queues use central branch predicates. |
| Staff assignment | Existing one-branch policy retained; target-user nonce and strict integer validation added. |
| Regression verification | 44 offline authorization checks passed; query recorder tests SQL constraints and rejects unexpected writes. |
| Syntax verification | All 24 plugin PHP files passed PHP CLI lint. |
| Live database/browser verification | Pending. Offline tests do not demonstrate actual database filtering, REST auth, UI behavior or locking. |

## Behavior to review on staging

Create synthetic branch A/B users and records. Verify unassigned managers cannot see branchless intake, administrators can triage it, sales cannot see or change old-branch leads after their assignment changes, inventory cannot see purchase cost, and finance detail reads cannot cross branches. Confirm valid administrator profile changes still save and record audit events, while missing or wrong-user nonces fail. Exercise transfer queues from both source and destination branches and all approval/finance/delivery queues. No schema migration is required for this increment.

## Increment 2: verified schema and customer transactions

- Read-only local inspection verified PHP CLI 8.2.12, WordPress 7.1.2, MariaDB 10.4.32, active theme `car-dealer`, and active plugin `auto-dealership-core/auto-dealership-core.php`. Stored schema/role versions were 1.0.0 at inspection. This establishes the local baseline, not production deployment configuration.
- Added `SchemaInspector`, an independent schema version, advisory installation lock, failure diagnostics/retry delay, and REST/admin guards. Verification covers declared columns/types/nullability/auto-increment/defaults, full index definitions and InnoDB.
- Added a real-database test runner that creates a fresh isolated WordPress database, passes credentials via stdin, runs synthetic fixtures and removes the database. It does not import customer data or bootstrap the source site's theme/plugins for tests.
- Added shared customer authorization to quote/reservation/sale creation, bound reservation retries to the original actor/payload plus current permissions, and closed old-branch discount access.
- Made reservation create/cancel/expire, quote creation and sale creation commit only with audit records. Expiry records movement, and sale creation checks reservation conversion succeeded.

## Increment 3: receipt confirmations and funded delivery (1.1.0)

- Added `adc_payment_confirmations`, separate record/verify capabilities for finance staff, two REST routes, and **Finance → تأكيدات السداد**.
- Receipt entry stays pending until another finance employee reviews it; the sale owner cannot verify. Verification enforces branch scope, unique source/reference and remaining balance. Amounts are integer SAR halalas.
- Delivery preparation and release require full verified settlement. Finance approval and submitted evidence cannot satisfy the gate. Since 1.19.0, an independently verified reservation deposit contributes once to the sale settlement; release still rechecks the approved sale, invoice reference, VIN/approval actors, scope and total.
- Protected quote prices after sale creation. Made receipt operations and all delivery transitions atomic with audit, and fixed delivery preparation preserving its inserted ID before later SQL updates.
- Updated the older theme-dependent workflow smoke script for receipt verification; it was not run against the source database.

## Increment 4: deterministic quote pricing and revisions (1.2.0)

- Added integer-only minor-unit parsing and VAT calculation with explicit half-up rounding and overflow rejection. New quotes store their VAT rate in basis points; later discount approval uses that frozen rate instead of the current setting.
- Added append-only `adc_quotation_versions` snapshots for creation, discount request and approval/rejection. Each transition increments the quote version and commits its snapshot and audit record atomically.
- Added scoped `GET /quotations/{id}/versions`. Sales can read their own same-branch history; branch managers, authorized finance and global administrators retain their corresponding scope.
- Legacy upgrade captures only the current known revision and leaves an unavailable historic VAT rate unknown. Such quotes remain readable but cannot be recalculated; staff must issue a new quote.
- Made sale approval and finance creation/status transitions audit-atomic and required a provider reference for finance approval.

## Increment 5: audit-atomic inventory, transfers and CRM (1.2.1)

- Branch and vehicle creation now roll back when their audit event cannot be written. Vehicle status changes keep the status, movement and audit event in one checked transaction.
- Transfer request, destination decision, source dispatch and destination receipt now use the shared verified transaction boundary. Failure at any audit write restores the prior vehicle branch/status, transfer state and movement history.
- Public customer/lead creation, lead stage changes, reassignment and activity entry now lock their subject and commit only with audit. Imported legacy request context is created inside the customer/lead transaction so an incomplete import can be retried safely.
- WordPress settings and user metadata writes still need a reviewed compensation/idempotency design because they are not assumed to share the operational InnoDB transaction.

## Increment 6: printable immutable quotation documents (1.3.0)

- New quote revisions freeze the originating branch, customer display name, stock number and vehicle description alongside financial values. Phone, email and VIN are deliberately excluded.
- Quote-history authorization now uses the frozen quotation branch rather than the vehicle's current branch, so relocating inventory cannot disclose historical customer documents to the destination branch.
- Added **عروض الأسعار** for authorized staff and a nonce-protected standalone RTL document for each revision. The browser print dialog supplies print/PDF output; no server PDF file or external message is created.
- Quote snapshots participate in WordPress privacy export. Erasure anonymizes the snapshot customer name while retaining minimized financial history. Pre-1.3 rows are enriched from current related records and are not represented as proven historical identity.

## Increment 7: inactive-branch revocation and configuration compensation (1.3.1)

- Shared branch scope now verifies active status. An inactive assignment produces a deny-all query predicate and fails direct object checks across CRM, inventory, quotes, reservations, sales, finance, payments, transfers and delivery. Global administrators retain remediation access.
- Dealership settings and staff branch assignment now use a single service that verifies writes and restores every prior option/meta value if the audit event fails. Originally absent values are removed again during compensation.
- Inactive default branches and staff assignments return explicit errors. REST tests confirm direct quote-history identifiers cannot bypass an inactive branch.

## Increment 8: audited multi-branch staff scope (1.4.0)

- Staff may have one primary branch and up to 25 active allowed branches. Existing `adc_branch_id` assignments remain valid without a migration.
- Central direct checks and SQL predicates use every active assignment; inactive secondary branches are removed from scope without affecting other active assignments.
- The administrator profile exposes an allowed-branch multi-select. The primary branch must be included, and role capabilities remain independent from branch membership.
- Both metadata values are verified and audited as one configuration change. Audit failure restores the exact prior values or removes values that were originally absent.

## Increment 9: retention and financial export controls (1.5.0)

- Added a disabled-by-default identity retention policy configurable from 30 to 3650 days. A daily batch processes at most 100 candidates and rechecks age and operational activity while holding the customer row lock.
- Retention clears customer contact and consent data plus free-text CRM and quote snapshot identity, while keeping minimized transaction history. Active or recent leads, reservations, quotes, sales, financing, payments and deliveries block processing.
- Added a nonce-protected financial CSV screen for finance viewers. The service enforces active branch scope, a 366-day range and 5000-row bound, omits customer/VIN/provider/payment-reference data, neutralizes spreadsheet formulas and requires an audit record before returning content.

## Increment 10: migration inventory and reconciliation foundation (1.6.0)

- Added a read-only WP-CLI report for legacy vehicles, offers, CRM posts, messages and bookings, including deterministic source links and aggregate blocking categories without PII.
- Added mapped field parity checks, unique VIN/stock collision checks, branch resolution, workflow holds and orphaned-offer reporting. Invalid fallback branches fail before reporting.
- Vehicle migration now commits each imported row only when its audit event persists. Source posts and media remain unchanged.
- Documented the source-to-target map and production-copy staging procedure. Execution against an authorized production copy and backup/restore timing remains an external staging task.

## Increment 11: brand and physical-location references (1.7.0)

- Added audited brand and location records with unique keys/codes, localized brand names, active state, branch ownership and showroom/warehouse/yard/service types.
- Added optional indexed references to vehicles and movement rows. Existing records retain zero-reference compatibility; new nonzero references must be active and the location must belong to the vehicle branch.
- Added a nonce-protected administrator screen and blocked deactivation while active inventory uses a reference.

## Increment 12: location movement and VIN correction (1.8.0)

- Inventory staff may move vehicles between active locations in the same branch while retaining state and recording both location IDs in immutable movement history.
- General managers and administrators receive a separate VIN-correction capability. Corrections require a reason and are locked after the vehicle reaches available, reserved, sold, ready-for-delivery, delivered, transferred or cancelled state.
- Added scoped REST routes and a nonce-protected screen. Audit failure rolls back both operations, and VIN audit events retain SHA-256 fingerprints instead of VIN text.

## Increment 13: receiving and mandatory inspection (1.9.0)

- Added one physical receipt per vehicle and append-only inspection outcomes with staff, UTC time, odometer, condition, document reference, notes and optional validated WordPress image IDs.
- Enforced receipt before entering inspection and the latest passed exterior/interior/engine/tires/VIN checklist before availability. Failed checklists require notes.
- Added branch-scoped REST endpoints and nonce-protected staff forms; each receipt and inspection result requires its audit event to commit.

## Increment 14: vehicle holds and maintenance (1.10.0)

- Added documented hold and maintenance cases with branch scope, optional assignment/review date, resolution staff and immutable movement/audit evidence.
- Failed inspections atomically open maintenance. Open cases block direct status changes; resolving a case returns the vehicle to inspection and availability requires a fresh passed checklist.
- Added REST endpoints and a nonce-protected staff screen for opening and resolving cases. Audit failures roll case, vehicle and movement changes back together.

## Increment 15: controlled vehicle returns (1.11.0)

- Added manager-authorized return receipt for completed deliveries with same-branch location, condition, odometer, document reference and reason.
- Return receipt atomically updates delivery, sale, vehicle, location, movement and audit history. Direct return-state transitions and duplicate receipts are blocked.
- Financial state remains explicitly `pending_refund`; no refund is claimed. Availability requires an inspection newer than the return's captured inspection baseline.
- Added a REST endpoint and nonce-protected staff screen for eligible deliveries and branch-scoped return history.

## Increment 16: independently verified refunds (1.12.0)

- Added pending/verified/rejected refund records tied to vehicle returns and immutable original receipt history.
- Dedicated recording and verification capabilities enforce same-branch scope and a different reviewer from the requester.
- Pending plus verified requests cannot exceed verified receipts; verified totals update the return obligation to partial or complete.
- Added REST operations and a nonce-protected finance screen. All writes require audit persistence.

## Increment 17: sale cancellation and delivery reversal (1.13.0)

- Added branch-scoped manager cancellation for pending, approved and ready-for-delivery sales; completed deliveries must use the return workflow.
- Cancellation atomically updates sale, reservation, open delivery, pending receipts, unresolved finance state, vehicle movement and audit history.
- Verified receipts place inventory on a documented hold. The existing independent refund workflow settles the cancellation before inventory can return to inspection.
- Added REST operations and a nonce-protected cancellation screen.

## Historical verification evidence (1.14.0)

Current 1.15–1.17 results and limits are recorded at the top of this document and in `VERIFICATION-1.17.0.md`. The table below preserves the earlier baseline.

| Check | Result and limits |
|---|---|
| PHP syntax | PASS: all 62 core-plugin PHP files on PHP CLI 8.2.12 for this increment. |
| Offline authorization | PASS: 48 checks in `tests/branch-scope.php`. |
| Integer money unit suite | PASS: 12 checks in `tests/money.php`. |
| Isolated WordPress/MariaDB | PASS: 365 checks on 2026-09-27 via `database-runner.php --isolated 33317`, covering the current 1.14.0 changes. See `VERIFICATION-1.14.0.md` for scope and limits. |
| Schema/upgrade fixtures | PASS: fresh/repeated install, additive payment table with existing branch records preserved, detected missing/truncated index and column/default/engine drift, blocked unverified schema. |
| Real concurrency | PASS: two independent PHP/WordPress connections return one reservation for matching keys and one winner for competing keys. This does not establish every possible lock/deadlock scenario. |
| Quote pricing/history | PASS: frozen 15% VAT survives a setting change to 5%; history failure rolls back request/version; three revisions, conservative idempotent legacy backfill and owner-scoped REST history verified. |
| Quote documents/privacy | PASS: identity snapshots survive later source edits, output escapes hostile HTML, vehicle relocation does not transfer access, export includes revisions and erasure anonymizes their customer name. Browser print layout remains a staging/manual check. |
| Audit fault injection | PASS: rollback for operational tables and compensating restoration for dealership options and staff branch metadata after failed audit/history INSERTs. |
| Payment/delivery path | PASS: finance approval alone, pending/partial payment, self-approval, foreign branch, overpayment and audit failures are blocked; complete funded flow reaches delivered. |
| Retention/financial export | PASS: disabled-by-default bounded retention, active/recent record protection and audit rollback; scoped/minimized CSV, date bounds, formula neutralization and mandatory export audit. The operator must still approve the legal duration. |
| Migration inventory | PASS: eligible/invalid vehicles, linked/orphaned offers, read-only behavior and fallback-branch rejection. Production-copy counts and restore timing remain unverified. |
| REST/admin | PASS: real localhost HTTP checks cover session cookies, nonces, validation, public active-branch listing, public lead intake, scoped CRM, inventory creation/state, complete public catalog filters/minimization, quote/discount/reservation/sale/finance/payment boundaries, complete staged transfers, and the funded delivery sequence through balance entry, independent verification, preparation, VIN confirmation, approval and release. Negative cases assert unchanged rows/state; eligible actors complete transitions. Arabic and English catalog layouts plus the Arabic account journey pass at four viewport sizes; human accessibility review remains open. |
| Cleanup | PASS: the runner removed its generated database; test scenarios did not write to source tables. |

## Next ordered work

1. Complete the production-copy staging backup/restore rehearsal, route/job inventory and detailed source-to-target migration mapping. A fresh synthetic database is now available as a repeatable test fixture; it does not replace that rehearsal.
2. Decide whether server-generated PDF storage/delivery is required beyond the branded browser print/PDF document, and define retention controls if it is. Extend remaining route-level HTTP IDOR coverage.
3. Approve the configured legal retention duration and exported accounting fields, then decide whether a non-WordPress global dealership role is required.
4. Complete vehicle field parity, provider/ERP reconciliation and cancellation exceptions, then reconcile vehicle/CRM migration and remove duplicate theme business logic.
5. Connect payment-provider/ERP reconciliation when an approved provider contract exists, while keeping the current manual receipt, deposit, refund and delivery-document attestations explicit until that adapter is verified.
6. Complete the English catalog journey and real-data cutover, then continue reports/outbox/integrations and the release gate in the implementation plan.

Phase 0 remains incomplete: no production-copy restore, migration reconciliation, or business-policy sign-off is claimed. Phases 1–2 progressed and the existing sales/delivery workflow gained a necessary payment control from phase 6. Full completion of any of those phases is not claimed. Overall production readiness remains FAIL.
