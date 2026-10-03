# Changelog

## 2026-10-03 — Finance Calculator page

- Renamed the published `/finance/` page to “حاسبة التمويل” with “Finance Calculator” for English visitors. Updated its navigation/footer labels, editorial blueprint and bilingual page copy, and aligned the browser title with the English title. The URL remains unchanged.
- Replaced the calculator's misleading initial `0 SAR` with an unset result, sent the selected language with calculation requests, and clarified that the estimate and enquiry are not a provider offer or application. The local page update has a guarded rerunnable script and a pre-change backup in `.tmp`.
- Refined the homepage calculator card for desktop and narrow screens: full-width labeled inputs, readable result and disclaimer, accessible focus styling, and a compact page link. Completed the English labels in that form.

## 2026-10-03 — Bilingual public-site acceptance

- Unified Arabic/English page direction and language controls across the public plugin/theme interface; added optional English editorial fields for pages, vehicles and offers. Restored the configured floating WhatsApp link and rebuilt the conditional footer.
- Fixed the shortcode adapter's undefined tag warning found in live local browser inspection. Header search now submits to the vehicle catalog with the selected language. Footer newsletter copy and consent are legible on the dark background; unpublished legal links leave no empty column. Vehicles without photos use an SVG fallback.
- Added a read-only local browser acceptance script. Its 53 checks pass across public routes and desktop/mobile widths, without posting customer data. The preflight still reports 8 PASS, 4 FAIL and 9 MANUAL; the previous code-only ZIP predates these uncommitted changes. See `BILINGUAL-SITE-ACCEPTANCE-2026-10-03.md`.

## 2026-10-03 — Release preflight and documentation alignment

- Added a read-only CLI release gate for active code/schema, public origin, legal pages, editorial placeholders, demo data, catalog reconciliation/mode, provider route safety, media and configuration tracking. After preserving the untouched WordPress example page as a draft and excluding configuration from HEAD, the local baseline is 8 PASS, 4 FAIL and 9 MANUAL, with 45/45 source fingerprints stable around a repeat read-only run. See `RELEASE-PREFLIGHT-2026-10-03.md`.
- Excluded `wp-config.php` from HEAD and index in local commit `66cf4f2` without deleting the working site file; historical-secret review remains. Added a constrained plugin/theme release builder and a local 163-file code-only candidate with per-file and archive SHA-256 evidence (`RELEASE-PACKAGE-2026-10-03.md`). Updated stale empty-database and pending-rollback statements in current operation docs while retaining historical test context.
- Prepared guarded Arabic/English privacy and terms drafts in local WordPress without publishing them; replaced the default privacy sample text, created the terms draft and corrected the public finance copy to avoid unapproved provider claims. Recorded approved first-release deferrals and the real-data intake boundary; the synthetic dataset and compatibility catalog remain unchanged (`legal/REVIEW.md`, `LAUNCH-DATA-AND-SCOPE-2026-10-03.md`).

## 2026-10-02 — Populated independent-instance restore

- Restored the current synthetic development snapshot and 666 files into a separate local MariaDB process/data directory. All 45 table fingerprints and file hashes matched before WordPress boot; copied plugin/theme business, catalog, route and media reads passed. A graceful server restart preserved all 45 target table fingerprints. Added a guarded read-only application inspection helper and documented the concurrent `wp_options` change on the source in `INDEPENDENT-RESTORE-2026-10-02.md`.

## 2026-10-02 — Populated rollback rehearsal

- Restored a fresh 45-table snapshot of the user-authorized synthetic development database into a disposable local database. The matching prior plugin/theme pair read existing sale, finance, payment and delivery states, created a clone-only quote/reservation/sale, verified idempotent reservation/payment retries, and rejected the new no-funds payment. The current pair read the resulting chain after switching back. All source table counts and checksums stayed unchanged. Added guarded CLI workers and recorded scope/limits in `POPULATED-ROLLBACK-2026-10-02.md`.

## 2026-10-02 — 1.28 acceptance and local development data

- Added 30 focused isolated checks for suppliers, restricted acquisition data, finance-attempt history, roles/security audit and v1/v2/OpenAPI compatibility. Full database/HTTP suite passed 687 checks. The late-loaded isolated runner now boots the response contract, OpenAPI and security audit hooks explicitly. See `VERIFICATION-1.28.0.md`.
- With the user's new authorization, backed up and populated the local `wp-autobrands` development database using a guarded repeatable seed: three branches, five brands, three suppliers, twelve synthetic vehicles and mapped car posts, six leads/customers, two labeled offers, a pending sale with two finance attempts, and a second simulated sale with one no-funds payment and a delivery only in preparation. Repeated seed runs preserve counts. See `DEVELOPMENT-DATA-2026-10-02.md`.

## 1.29.13 — Deployable theme cleanup

- Same-commit prior-pair rollback smoke on a fresh post-upgrade clone: plugin `1.29.4` and theme `1.0` kept schema `1.17.0`, 108 dealership REST routes, privacy hooks and administrator capabilities. Twelve common administration screens rendered on both pairs, and the current pair's three newer screens rendered after switching back. The source's 45 table fingerprints stayed unchanged. Populated transactional downgrade remains a separate gate; see `ROLLBACK-OPERATIONS-2026-10-02.md`.
- Controlled local source cutover on 2026-10-02: after a logical/file snapshot, repaired the two discount-margin column nullability mismatches, recorded schema `1.17.0`, and refreshed content-registry rewrite rules. `/offers/` now returns HTTP 200 through local Apache; seven public routes and 14 local assets passed smoke checks. Only `wp_options` changed checksum, with no row-count changes. See `SOURCE-CUTOVER-2026-10-02.md`.
- Actual-content cutover rehearsal: restored 45 source tables and 662 files to an isolated clone, rendered the published about/contact/finance pages, and booted the prior plugin/theme pair from commit `b2fd4fe` for a public rollback smoke. All source table fingerprints remained unchanged at that stage. Fixed five unconfigured `href="#"` social links on the contact page. Repaired the schema installer for existing signed discount-margin BIGINT columns that `dbDelta()` left nonnullable; the clone reached schema marker `1.17.0`. Privacy/terms publication and public deployment acceptance remain open. See `VERIFICATION-1.29.13.md`.
- 2026-10-02 verification: 657 isolated database/HTTP, 34 catalog browser, 33 account browser and 53 empty-site Twenty Twenty-Five switch checks passed without contacting the source database. The stock-theme run included four structural editorial pages. Corrected isolated harness assumptions after plugin ownership moved, treated missing optional gallery metadata as an empty list, and removed a CSS rule that overrode English LTR direction. See `VERIFICATION-1.29.13.md`.
- Follow-up cutover correction: removed duplicate theme form wrappers that could redeclare `car_dealer_lead_form()` and `car_dealer_booking_form()` during bootstrap. Public catalog queries, single-car eligibility and intake now share the same mapped/legacy availability rule; an unmapped car with blank legacy status remains eligible, while a sold or reserved legacy car is hidden and cannot accept requests. The shared public form script is also enqueued when only the newsletter service is enabled.
- Added plugin read models for vehicle cards/details, authorized offer previews, bounded home-page selections, compatibility/authoritative filter choices and account workspace targets. The home page no longer selects business records or invents customer counts, financing offers, brand counts, response times or universal warranty terms; empty inventory sections stay hidden.
- Removed all theme business PHP and AJAX/finance JavaScript fallbacks from the active load chain. Archived former theme handlers, dormant administration modules, unused statistics styling, old admin assets and historical checks under `docs/archive/car-dealer` without loading them.
- Activated theme-only appearance/contact settings under Appearance, removed fixed page IDs, broken relative links, empty team-contact links and fabricated contact/map placeholders, and rebuilt public CSS without the old admin module. Missing plugin services now produce unavailable states instead of theme-side writes; archive links are omitted when their plugin-owned content types are unavailable. Prices retain fractional riyals when the operational record contains halalas.
- No business data, editorial pages or sample records were created in the source database. Rollback restores a matching earlier theme/plugin release rather than toggling an owner flag; only the public rollback smoke has been exercised so far.

## 1.29.12 — Account decisions and remaining public fallback isolation

- `CustomerAccount` now classifies the account workspace and decides whether a customer-only user should be redirected from the admin area. The theme retains labels and navigation; the previous capability rules are loaded only with the account rollback file.
- Moved the old catalog language, URL and SEO policy into conditional `catalog-legacy-policy.php`. The active catalog file delegates those decisions to the plugin and retains English copy, formatting and the visual language switch.
- Moved shortcode car selection, comparison-cookie decoding and finance-form defaults into the theme's conditional compatibility file. Raw offer metadata reads for rollback and editorial previews now use the lazy `offer-legacy-view.php` helper; published offer cards and details use the plugin model.
- Preserved existing identifiers and preview behavior. No schema, business record or sample content is created. Runtime, database, browser and theme-switch acceptance remain pending.

## 1.29.11 — Conditional legacy CRM and engagement loading

- Moved the theme's former engagement table installer, public AJAX writers and staff list SQL into `inc/engagement-legacy.php`. `inc/contact-form-manager.php` now keeps the form renderers and identity presentation, loading the compatibility file only when an applicable plugin owner is disabled before theme bootstrap.
- Made the old `inc/crm.php` and `inc/customer-workflow.php` theme require path conditional on the corresponding account, request, intake, marketing, engagement or CRM ownership switch. The plugin remains the active owner of these workflows in normal operation.
- Moved the theme's old car content registration, vehicle metadata editor, direct-email lead AJAX handler and comparison AJAX handler from `functions.php` to conditional `inc/theme-legacy-core.php`; active card, shortcode and form rendering remain in the theme.
- Kept legacy slugs, actions and rollback code available. No schema migration, business record or sample content is created by this source change. Runtime, browser, database and theme-switch acceptance are pending.

## 1.29.10 — Offer archive pagination and account adapter cleanup

- The plugin now restricts the main public `car_offer` archive query to published offers with a positive price, a valid unexpired date and a publicly eligible linked car. WordPress counts and paginates the same eligible rows that offer cards render, including compatibility and authoritative catalog modes.
- Moved the account theme's former role, authentication/profile mutation and direct-SQL request history paths into separate compatibility files. They load only when their corresponding plugin ownership switch is disabled before bootstrap; the active account file retains routing and presentation.
- Kept existing identifiers and early rollback flags. No schema or business/content records are changed or created. Runtime, database, browser and theme-switch acceptance remain pending.

## 1.29.9 — Public offer and customer request read models

- Added `PublicOfferView` as the plugin-owned read model for published, unexpired offers with a positive whole-SAR price and a publicly eligible linked vehicle. It normalizes old and monthly prices, supplies public URLs and image fallback, and marks invalid singular offers as 404 while preserving staff previews.
- Converted active offer cards and single-offer templates to render the plugin model. The archive suppresses ineligible cards and still exposes pagination when a page contains only suppressed entries. Legacy metadata reads remain a guarded rollback path.
- Added `CustomerRequestView` for bounded, authenticated account history from retained message and booking projections. It selects only display fields, scopes by the current account ID, fails closed on missing schema/storage, and offers cancellation only for an active booking linked to that same account's core customer.
- The account table now renders the plugin's rows and status/action decisions; its direct SQL path is a guarded rollback. The account contact form uses the durable plugin shortcode contract. No schema or content records are created. Runtime/database/browser acceptance remains pending.

## 1.29.8 — Catalog language, SEO and structured-data ownership

- Added `CatalogPresentation` as the plugin owner of URL-bound Arabic/English catalog language, allowlisted language-switch URLs, filtered-archive indexing, canonical links and language alternatives. The theme's active language switch uses plugin facades; its duplicate SEO hooks are guarded for plugin-inactive compatibility only.
- Added `PublicStructuredData` as the plugin-owned JSON-LD emitter for eligible public vehicles and valid linked offers. Missing prices are omitted rather than fabricated; unavailable cars, unpublished or expired offers and offers without a valid price do not receive an in-stock offer claim.
- Guarded the theme require for `inc/schema-markup.php` so structured data has one active emitter and remains available after a theme switch. Independent early ownership flags retain controlled theme rollback without duplicate hooks; the old file remains for later reviewed cleanup.
- The shortcode contact view model now uses the shared catalog language policy. No schema, business record, page or sample content is created. Runtime and browser acceptance remain pending.

## 1.29.7 — Plugin-owned public shortcode contracts

- Added `PublicShortcodes` as the sole active registrar for all 18 durable shortcode names, including cars, comparison, finance calculator, contact form and the stored `ab_*` page-section names.
- Added bounded plugin view models for eligible car IDs, comparison selection, calculator limits/defaults and authenticated contact-form identity. Cars and comparison no longer execute their domain query in the active theme renderer.
- Converted `car-dealer` callbacks into `adc_shortcode_{tag}_html` presentation filters. The existing markup remains active, while the ownership filter and theme fallback retain a controlled rollback path without duplicate registration.
- Added neutral stock-theme fallbacks for cars, comparison, finance calculation and contact intake. Presentation-only `ab_*` and testimonial output stays empty without an adapter because no approved independent content repository exists.
- Moved contact, booking and newsletter browser submission to the plugin public-intake asset, including the existing nonce/action response contract, retry-stable idempotency keys and duplicate-submit prevention. The theme handler now runs only during rollback.
- Removed hard-coded fictional testimonials from both active theme shortcode renderers. The shortcode names remain registered and render no testimonial section until approved customer content is supplied.
- Documented the adapter API, model shapes, fallbacks and rollback in `THEME-ADAPTER-API.md`. Updated the plugin to 1.29.7; schema remains 1.17.0 and no content, business or sample record is created by this cutover. Verification has not been run.

## 1.29.6 — Explicit editorial page setup

- Removed the active theme's `admin_init` and `after_switch_theme` page writer. Visiting administration or changing themes can no longer create pages or overwrite manually edited titles and content.
- Added the plugin-owned `EditorialPageSetup` service and `adc-editorial-setup` administrator screen. It reads bounded theme presentation blueprints, reports existing/missing pages, and creates only selected missing pages as drafts after a nonce-protected request with a recorded reason.
- Made page creation idempotent and serialized. Existing pages in every status are preserved, all selected writes and the minimized audit event commit together, and a failure rolls the batch back.
- Converted dormant `inc/auto-pages.php` into an inert compatibility include so loading it later cannot restore automatic page writes. Privacy and terms pages remain excluded because approved legal copy has not been supplied.
- Updated the plugin to 1.29.6. Schema remains 1.17.0; the upgrade and normal requests create no editorial, business or sample records. Verification has not been run.

## 1.29.5 — Unified plugin operations workspace

- Added `WorkspacePage` as the theme-independent home for `adc-workspace`. Its navigation cards are produced from the current user's capabilities and link to the existing plugin CRM, engagement, inventory, purchasing, approval, finance, delivery, reporting, audit and settings screens.
- Moved the operational inventory page from the workspace root to `adc-inventory`; vehicle create, transition, location and VIN actions now return to that route. The active account template uses the same route.
- Added a screen-only plugin workspace stylesheet without copying the dormant theme dashboard styles or JavaScript.
- Classified `admin-dashboard.php`, `admin-workspace.php`, `inventory-management.php`, `advanced-features.php` and their administration assets. They are not in the active theme require chain and remain inert until separate rollback-window cleanup; their direct writers, role mutation and projection counters were not copied.
- Updated the plugin to 1.29.5. Schema remains 1.17.0, and this slice creates no business, editorial or sample data. Verification has not been run.

## 1.29.4 — Plugin-owned engagement administration

- Added plugin-owned staff pages for the existing `car-dealer-messages`, `car-dealer-bookings` and `car-dealer-subscribers` slugs under `adc-workspace`; the pages remain available when the presentation theme changes.
- Added `EngagementQuery` as the bounded read boundary. Message and booking rows use the existing branch/owner lead predicate, while subscriber access requires the new `adc_view_marketing_subscribers` capability granted only to marketing, general managers and administrators.
- Reused `RequestWorkflow` and `RequestPage` for all request changes. Successful changes now return to the originating message or booking list, and unmapped historical rows remain read-only.
- Added a plugin-only administration stylesheet enqueued solely on the three owned screens. The active theme now suppresses its duplicate menu registration through `adc_core_owns_engagement_admin_pages`; the early `adc_core_engagement_admin_enabled` filter retains a rollback path.
- Updated the active account presentation to recognize core staff roles and link to plugin workspaces instead of dormant theme dashboard, CRM, inventory and settings URLs. This cutover creates no customer, request, subscription or sample record. Verification has not been run for this slice.

## 1.29.3 — Controlled compatibility-retirement decision

- Added `CompatibilityRetirement` as a read-only aggregate readiness report within the migration inventory. It records table presence/engine, total/core-linked/unmapped/active request counts and legacy CRM retirement totals without exposing customer data.
- Formally retained message and booking tables as controlled request projections because account history, status/reply/appointment updates, privacy export/erasure, retention and historical migration still depend on them.
- Classified `car_dealer_subscribers` as the current canonical marketing-consent store rather than a disposable request copy. It cannot be retired until an approved consent repository replaces it.
- Kept `cd_crm` as private read-only history. Pending account-ID-based retirement is reported separately; no contact-based claim, post conversion or deletion was added.
- Defined the removal gates: native account/request reads and writes, native privacy handling, complete historical reconciliation, aggregate count parity, and accepted backup/rollback rehearsal. No table, row, identifier or sample record is created, changed or removed by the report. Verification has not been run for this slice.

## 1.29.2 — Plugin-owned comparison and finance estimate decisions

- Added `LoanCalculator` as the bounded provider-neutral installment estimate service. It validates vehicle price, down payment, annual percentage and term, returns whole-SAR estimate fields and explicitly marks the result as an estimate rather than an approval or provider offer.
- Added `VehicleComparison` as the owner of comparison selection rules. It accepts both existing cookie formats, keeps at most four unique vehicles and validates every addition against the same public-catalog eligibility boundary used by the selected catalog mode.
- Added plugin-owned `car_dealer_comparison` and `car_dealer_loan_calculator` AJAX handlers with nonce and shared atomic public-read rate protection, plus a plugin asset for the public interactions.
- Preserved the existing shortcode and comparison action identifiers. The theme continues to render the calculator form, comparison button and car cards while its JavaScript and guarded rollback handler no longer define the active business decisions.
- Comparison remains browser-owned and does not create an audit or database record. The calculator does not create a lead, finance request or business record. Added the early rollback filter `adc_core_public_tools_enabled`; schema remains 1.17.0 and verification has not been run for this slice.

## 1.29.1 — Plugin-owned customer account and CRM write boundary

- Added `CustomerAccount` as the owner of the existing front-end registration, sign-in, profile, preference and booking-cancellation form contract. The theme keeps account routing and markup while delegating every mutation to the plugin.
- Added an atomic `account_auth` request policy, retained the existing privacy-consent and honeypot fields, and provisioned the stable `car_dealer_customer` role through plugin activation/version upgrades instead of theme `init`.
- Added an audit-atomic account profile update that changes WordPress display name/phone and any linked core customer together. Audit data records changed field names only; failed writes or audit persistence roll back the transaction.
- Registered `cd_crm` through `LegacyCrmBridge` as private read-only history. Linked legacy profiles are retired through compensating audited metadata writes; no legacy profile, lead or sample record is created.
- Disabled the theme's legacy CRM registration, editor/export/capture/reconciliation handlers, account-profile hooks and unmapped request writers while plugin ownership is active. Core `LeadService`, `RequestWorkflow`, `CustomerIdentity` and the plugin CRM workspace remain the active operational paths.
- Added early rollback filters `adc_core_customer_account_enabled` and `adc_core_legacy_crm_bridge_enabled`. Schema remains 1.17.0. Verification has not been run for this slice.

## 1.29.0 — Plugin-owned public content registry

- Moved ownership of the existing `car` and `car_offer` registrations plus `car_brand` and `car_category` into `AutoDealership\\Content\\ContentRegistry`, preserving public slugs, archives, REST visibility, supports and vehicle capability mapping. Offers now use dedicated content capabilities instead of generic post-editing permissions; this slice provisions them only to administrators.
- Declared the legacy-compatible car/offer metadata keys without exposing them through REST or changing their stored values. Operational inventory remains authoritative in plugin tables; posts remain the editorial/public projection.
- Added the stable `adc_core_owns_content_registry()` facade. The active theme now skips its duplicate content registration and theme rewrite flush while retaining its current templates and guarded rollback definitions.
- Added a versioned rewrite lifecycle and collision notice. Activation or a registry-version upgrade performs the flush; ordinary requests do not.
- Added the custom vehicle/taxonomy capabilities to administrator provisioning. No dealership content, reference value, page or sample business record is inserted, moved or deleted. Verification has not been run for this increment.
- Moved the existing `car_dealer_lead`, `car_dealer_contact` and `car_dealer_booking` AJAX action names to `PublicIntake`. The theme registers its prior handlers only when the plugin ownership switch is explicitly disabled, so one writer is active at a time.
- Added a plugin-owned, versioned and installation-locked compatibility schema for messages, bookings and newsletter subscriptions. Message/booking copies remain transitional dependencies of the current account/request workflow and are written only inside the plugin's audited intake transaction.
- Added an audited newsletter boundary with explicit opt-in, honeypot, canonical email validation and the shared atomic public-intake rate policy. The theme keeps only the rendered form and consent label.
- Replaced the plugin-to-theme account URL call with the `adc_customer_account_url` presentation filter.
- Moved the active vehicle compatibility editor into `AutoDealership\\Content\\VehiclePostEditor`. The editor preserves existing keys/nonces, validates price/year/mileage/text/status fields, treats mapped operational status as read-only and records only changed field names in audit history.
- Added `PostMetaStore` as a compensating writer: a failed metadata write or failed audit event restores the exact previous value or absence across every touched post/key.
- Moved offer linkage and durable fields into `AutoDealership\\Content\\OfferPostEditor`. Mapped vehicles must be uniquely linked, operationally available and assigned to an active branch; unmapped posts retain the legacy empty/available compatibility rule. Invalid public offers remain drafts.
- Added vehicle/offer ownership facades, early rollback filters and guards around the active theme editor plus dormant offer, advanced-feature and inventory writers. Existing identifiers and presentation helpers remain unchanged. Verification has not been run for this slice.

## 1.28.0 — Business model, RBAC and API contract foundation

- Added an additive supplier directory and restricted vehicle acquisition model covering supplier, origin, cylinders, customs reference, arrival date, explicit purchase/additional/total costs, wholesale price, video, image gallery, acquisition documents and internal notes.
- Added purchasing, delivery, customer-service and marketing roles with task-specific capabilities. Vehicle costs and acquisition documents require dedicated capabilities and remain absent from the public catalog.
- Expanded finance requests into ordered provider attempts with predecessor links, down payment, term, monthly payment, decision reason and timestamps. A new attempt is blocked while another is open or approved.
- Added minimized audit coverage for successful/failed login, password reset, account lifecycle, role changes and direct capability-meta changes.
- Registered the existing API as both compatibility `v1` and enveloped `v2`. Both emit `X-Request-ID`; `v2` returns stable success/error envelopes. An authenticated OpenAPI 3.1 document is generated from registered routes at `/wp-json/auto-dealership/schema/v2`.
- Raised the plugin to 1.28.0 and schema to 1.17.0. No supplier, vehicle, finance, reference or sample business record was inserted. Purchase-order states/approvers and automatic total-cost rules remain disabled pending approved business policy. Verification has not yet been executed for this increment.

## 1.27.0 — Public boundary hardening

- Declared the exact anonymous REST inventory and applied named `public_read` and `intake` policies at the core boundary.
- Replaced the transient counter with atomic fixed-window InnoDB buckets. Caller identity is HMAC protected; raw addresses and user IDs are not persisted or shown.
- Added default-deny forwarded-header handling with bounded exact/CIDR trusted-proxy configuration for IPv4 and IPv6.
- Added bounded hourly cleanup, minimized rate-limit hooks and a capability-restricted aggregate security page.
- Schema 1.16.0 adds only `adc_request_limits`. Added 13 focused assertions within **654 isolated database/HTTP checks**, including a 12-process race and full anonymous-route inventory. The source database was not contacted and no business/sample data was inserted. See `SECURITY-HARDENING.md` and `VERIFICATION-1.27.0.md`.

## 1.26.0 — Integration activation and reconciliation readiness

- Added a central fail-closed route policy. Only general managers and administrators can enable a route, every change requires a reason and audit persistence, and provider code may veto but cannot self-enable delivery.
- Added safe adapter readiness checks for credentials, endpoint, authentication, timeouts, idempotency, acknowledgements and reconciliation without exposing values or secret names.
- Added structured `ProviderResult` responses and schema 1.15.0's durable acknowledgement ledger. Early acknowledgements link to later deliveries, duplicates remain idempotent, and contradictory final states become visible mismatches.
- Added optional asynchronous provider polling through `ReconciliationContract`, audited operator requests, bounded outbox execution and safe alert hooks for terminal queue failures.
- Added the restricted Arabic integration workspace with route state, missing controls, scheduler health, acknowledgement counts, masked reference fingerprints and reconciliation controls.
- No provider, endpoint, credential, external call or business/reference/sample data was added. Added 19 focused assertions within **640 isolated database/HTTP checks**; the disposable database was removed and the source database was not contacted. See `INTEGRATION-CONTRACTS.md` and `VERIFICATION-1.26.0.md`.

## 1.25.0 — Provider-neutral integration contracts

- Added a strict runtime adapter contract, one-owner event registry and the `adc_integrations_register` registration boundary.
- Added an allowlisted domain-event catalogue for reservation confirmation, sale approval, finance transitions, verified payments and completed delivery.
- Kept every event disabled by default. Explicit activation without a registered adapter fails closed, and no provider, credential, endpoint or outbound request is configured.
- Made enabled event persistence part of each owning business transaction. A failed outbox insert rolls back the business mutation and audit together.
- Reused the durable outbox with deterministic replay keys and minimized subject/branch/state/version payloads that exclude identity, contact, VIN, references and amounts.
- Raised the plugin to 1.25.0; schema remains 1.14.0. Added 11 focused assertions within **621 isolated database/HTTP checks**. The disposable database was removed and the source database was not contacted. See `INTEGRATION-CONTRACTS.md` and `VERIFICATION-1.25.0.md`.

## 1.24.0 — Branch-scoped operational reports

- Added a restricted Arabic operational-report workspace covering inventory, leads, reservations, quotations, sales, deliveries and current exception queues.
- Applied the central active-branch scope to every aggregate and retained historical sale/delivery attribution through the originating quotation branch. Administrators retain global scope.
- Added a nonce-protected UTF-8 CSV export with formula neutralization, aggregate-only fields and mandatory `operations.report_exported` audit persistence.
- Excluded customer names, contact details, VINs, stock numbers and reference identifiers from both the screen and export. Any section query failure rejects the whole report instead of returning partial results.
- Raised the plugin to 1.24.0; schema remains 1.14.0 and no business/reference/sample data was inserted into the intentionally empty source database.
- Added 13 focused assertions within a full run of **610 isolated database/HTTP checks**. The disposable database was removed and the source database was not contacted. See `OPERATIONAL-REPORTS.md` and `VERIFICATION-1.24.0.md`.

## 1.23.0 — Durable outbox and job monitoring

- Added a durable at-least-once outbox with SHA-256 replay keys and payload hashes, a strict reference-only payload allowlist, delayed availability, atomic worker leases and expired-lease recovery.
- Added bounded retry delays, five-attempt terminal failures, safe error-code retention, and terminal rejection for malformed or hash-mismatched stored payloads.
- Added a five-minute WP-Cron worker and a restricted **Audit Log → مراقبة المهام** page with queue counts, scheduled-job timestamps and safe event metadata. Payloads and replay keys are never rendered.
- Added read-only auditor access and general-manager/administrator retry access. A retry requires a reason and commits atomically with `outbox.retry_requested`; audit failure restores the terminal state.
- Raised the plugin to 1.23.0 and schema to 1.14.0. The additive upgrade preserves the earlier outbox shape and data. No provider adapters, credentials, outbound messages or sample business records were added.
- Added 21 focused assertions within a full run of **597 isolated database/HTTP checks**, including a real two-process worker race. Authorization (48), money (12), pricing-policy (16), 87 plugin PHP, 41 theme PHP and 8 JavaScript/CommonJS checks also pass. The disposable database was removed and the intentionally empty source database was never contacted. See `OUTBOX-OPERATIONS.md` and `VERIFICATION-1.23.0.md`.

## 1.22.0 — Audited catalog mapping and cutover workspace

- Added **Dealership Core → Catalog cutover**, an administrator workspace that lists operational vehicles and eligible WordPress `car` posts, records a required reason, and applies one-to-one mapping changes through a shared service.
- Made mapping writes transactional and append-only audited. Invalid post types, trashed posts, duplicate assignments, non-administrators and audit failures are rejected; audit failure rolls the mapping back and identical retries create no event.
- Added bounded reconciliation details for unmapped posts, unmapped operational vehicles, duplicate mappings and invalid/unpublished targets, while reporting complete issue totals and a SHA-256 review fingerprint derived from complete catalog-state aggregates.
- Tightened authoritative activation so a current schema, at least one operational vehicle, at least one published vehicle post, at least one eligible public record, zero unmapped published posts, zero duplicate mappings and zero invalid targets are all required.
- Added 14 focused mapping/cutover assertions within a full run of **576 isolated database/HTTP checks**. Regression also passed 34 catalog-browser checks, 33 account-browser checks plus three post-journey assertions, seven DOM checks, 48 authorization checks, 12 money checks, 16 pricing-policy checks, and syntax across 84 plugin plus 41 theme PHP files and three Node scripts. The disposable database was removed and the intentionally empty source database was not contacted. Schema remains 1.13.0; see `VERIFICATION-1.22.0.md`.

## 1.21.0 — Bilingual catalog and measurable acceptance

- Added explicit `lang=ar|en` catalog URLs with Arabic default, visible language switching, correct document/component RTL and LTR, localized filters, cards, specifications, prices, lead forms and AJAX success/error responses.
- Added language-preserving catalog/detail links, localized Vehicle structured-data URLs, and `ar`, `en` and `x-default` hreflang alternates alongside filtered-URL canonical/noindex behavior.
- Primed mapped WordPress post and metadata caches before catalog rendering to avoid per-card lookup growth.
- Added a 240-vehicle isolated performance scenario with stable pagination, exact totals, filter options, a 25-query ceiling and a 3-second local ceiling. Accepted runs used 10 queries in 0.028-0.056 seconds.
- Added automated structural accessibility checks and Arabic/English responsive Chromium screenshots at 1440, 768, 390 and 320 pixels. English lead submission is exercised through the real AJAX handler.
- Local acceptance passed 565 isolated database/HTTP checks, 34 catalog-browser checks, 33 account-browser checks plus three post-journey assertions, seven DOM checks, 48 authorization checks, 12 money checks, 16 pricing-policy checks, syntax across 81 plugin plus 41 theme PHP files, and all three Node scripts. The source database was not contacted. See `VERIFICATION-1.21.0.md`.

## 1.20.0 — Operational public catalog cutover

- Added a plugin-owned public catalog read model with bounded filters for brand, model, trim, year, price, mileage, body, fuel, transmission, engine, drivetrain, colors, branch and condition, plus allowlisted sorting and search.
- Added an audited `compatibility` / `authoritative` setting and cutover readiness counts. Compatibility remains the default for the intentionally empty operational database; authoritative activation is blocked by schema drift, unmapped published posts or duplicate mappings, and exposes only available records mapped to published `car` posts in active branches.
- Switched mapped theme cards, vehicle details and Vehicle structured data to operational values, including minor-unit price conversion, specifications, branch and stock identity.
- Added authoritative archive controls with shareable query URLs, bounded filter options, result announcements, keyboard focus styling, and noindex/canonical handling for filtered result URLs.
- Expanded `GET /vehicles` to use the same central filter contract and return normalized active filters. Schema remains 1.13.0.
- Local acceptance passed 562 isolated database/HTTP checks, 19 real-theme catalog browser checks, 33 account-browser checks plus three post-journey assertions, seven DOM checks, 48 authorization checks, 12 money checks, 16 pricing-policy checks and syntax across 80 plugin plus 41 theme PHP files. The disposable database and MariaDB data directory were removed; the source database was not contacted. See `VERIFICATION-1.20.0.md`.

## 1.19.0 — Pricing, deposits and delivery evidence

- Added one central integer pricing policy for fixed fees, explicit dated promotion codes, approved discounts, subtotal, VAT and final total. Every quote revision freezes all components and the configured seller identity.
- Added configured sales-manager and general-manager discount ceilings. Each request freezes its approval tier and before/after gross margin; requester/reviewer separation remains enforced.
- Added reservation deposit policy snapshots and a separate pending/verified/rejected evidence workflow. A reference never proves receipt, and recorder, reservation owner and reviewer are separated. Verified deposits count once toward settlement; cancelled paid reservations use the existing independently reviewed refund ledger before inventory release.
- Added configurable delivery document requirements and audited document references. Missing configured evidence blocks both delivery approval and final release.
- Added deposit recording/review to the finance workspace, delivery-document entry to the delivery workspace, and tier/margin visibility to the discount review queue. Pending deposit evidence must be decided before reservation cancellation.
- Raised schema to 1.13.0 and plugin to 1.19.0. The migration is additive and has not been applied to the intentionally empty source database.
- Added 16 dependency-free pricing-policy checks and 26 database scenarios for frozen pricing, margin tiers, deposit evidence/refunds and delivery-document gates. Local acceptance passes 541 isolated database/HTTP checks, 48 authorization checks, 12 money checks, 33 real-theme browser checks plus three database assertions, seven DOM checks and syntax across 79 plugin plus 40 theme PHP files. See `VERIFICATION-1.19.0.md`.

## 1.18.0 — Customer preferences and legacy CRM retirement

- Added explicit account marketing opt-in/opt-out with an authenticated REST boundary, user-level pre-enquiry persistence, canonical customer synchronization and minimized audit events.
- Added immediate existing-customer refresh after WordPress name, email or phone changes; contact equality still cannot create or claim an account identity.
- Retired legacy `cd_crm` profiles only from their recorded account ID, removed them from active counts/search/export and restricted their history to administrator read-only access.
- Extended WordPress privacy export/erasure to legacy CRM posts and activities.
- Kept schema 1.12.0. Local acceptance passed 515 isolated database/HTTP, 48 authorization, 12 money, 33 real-theme browser plus three post-journey database, and seven DOM checks. No source database migration or backfill was executed; see `VERIFICATION-1.18.0.md`.
- Invalidated legacy CRM activity comment caches after committed privacy erasure so the current request cannot display erased text from WordPress object cache.

## 2026-09-29 — Account journey and targeted acceptance

- Completed 490 isolated database/HTTP checks, 48 authorization checks, 12 money checks, 27 real-theme Chromium journey checks and seven separate public-intake DOM checks.
- Added both launch orders for merge-versus-quotation and erasure-versus-intake races, partial appointment rescheduling, mapped-vehicle branch rejection, customer ownership pagination, reconciliation cursor failure/progress and staff table pagination.
- Exercised the actual Arabic account and vehicle pages from login through enquiry, profile refresh, booking, cancellation, staff reply and second-customer isolation at 1440, 768, 390 and 320 pixels.
- Repaired theme dependency loading, shared enquiry/booking rendering, script localization, POST fallbacks, RTL/LTR field semantics and accessible mobile request-table scrolling.
- Syntax passed for 74 plugin and seven theme PHP files. The disposable database was removed and no source database was contacted. See `ACCOUNT-JOURNEY-VERIFICATION.md`.

## 2026-09-28 — Verification of 1.15–1.17

- Added 103 assertions for CRM intake, identity and linked workflows to the isolated database/HTTP suite; all 468 checks pass, alongside 48 authorization and 12 money checks.
- Added four concurrent CRM scenarios, SQL fault injection, additive upgrade, privacy/retention and actual theme AJAX/admin-post/REST coverage.
- Added seven passing real Chromium DOM checks for the production form script. Full-site visual review and remaining mixed races/legacy cases stay open.
- Syntax passed for 70 plugin and four theme PHP files. No production behavior or source database was changed in this verification increment. See `VERIFICATION-1.17.0.md`.

## 1.17.0 — Account-linked intake and reviewed CRM consolidation

- Added authenticated account reuse with a unique account key, transactional first-request serialization and audited contact refresh; public email/mobile values never authorize account claims.
- Added administrator-only candidate search, preview, revision-protected CRM merge and a confirmation screen. Matching contact/scope and reviewed evidence are required; financial references, conflicting accounts and merge chains are rejected.
- Retained source tombstones, moved eligible leads with activity/audit history and rejected merged IDs in operational customer authorization.
- Stopped duplicate theme account-profile creation/synchronization on login/profile hooks; preserved existing legacy profiles.
- Extended privacy to linked account IDs and old-email account request copies. Schema 1.12.0 is additive; no live migration or merge was executed.
- Initial syntax passed for 68 plugin and four theme PHP files. Subsequent local verification and its limits are recorded above and in `VERIFICATION-1.17.0.md`.

## 1.16.0 — Scoped linked request updates

- Centralized replies, test-drive rescheduling/status transitions and account-owned cancellation with transaction locks, stale-form revisions and atomic activity/audit recording.
- Added scoped GET/PATCH linked-request routes, customer cancellation route and lead-specific nonce-protected forms in CRM and theme request tables.
- Scoped request lists, related-request reads, pagination totals and dashboard counters. Unmapped legacy requests require administrator triage for staff access.
- Reconciliation advances past core-owned records and stops on schema/storage errors; intake-switch reversal cannot bypass linked-request authorization.
- Schema remains 1.11.0. Syntax passed for 66 plugin and four theme PHP files; integration/concurrency/browser scenarios are pending. No source database changes or data imports were performed.

## 1.15.0 — Unified public CRM intake

- Centralized public REST/theme enquiry validation, contact normalization, explicit consent, rate counter and honeypot; retained logged-in theme identity and customer-account request copies.
- Added UUID replay/conflict handling with HMAC fingerprints and atomic customer/lead/activity/compatibility/audit writes. Schema 1.11.0 adds nullable replay columns and a unique key.
- Added scoped activity-history REST reads and a CRM history screen; activity writes update the lead and supplied follow-up date.
- Extended privacy erasure and retention to replay fingerprints; eligible legacy request copies are anonymized transactionally during retention.
- Added a separate plugin form script and reversible theme intake filter; manual theme JavaScript edits remain intact. See `CRM-INTAKE.md` for remaining legacy workflows and rollback limits.
- PHP syntax passed for 64 plugin files and two changed theme files; new JavaScript syntax and diff whitespace checks passed. Integration and browser scenarios were not run for this increment.

## 1.14.0 — Vehicle specifications and migration parity

- Added eleven public specification fields, strict validation, scoped atomic edits, REST PATCH and a nonce-protected Arabic staff screen. Transactional inventory states lock edits.
- Reused one source mapper for imports and reconciliation, preserved decimal SAR prices and certified condition, corrected mileage/stock aliases, and rejected unknown inventory status.
- Added source-row serialization and movement history to vehicle import; dry runs now report existing VIN/stock collisions.
- Cancellation now checks finance/payment update failures, includes finance under review, rejects the sale owner and captures an inspection baseline when a hold is resolved.
- Refund aggregation errors fail closed; replay of a completed refund returns its original record. Added cancellation links to refund decision audits and capability checks to financial queues.
- Schema 1.10.0 adds vehicle specifications and resolved-issue inspection baselines. Existing mapped vehicles are not overwritten or automatically backfilled.
- Reject serialized non-string identity/status metadata before casting or array lookup in migration.
- Verified 365 isolated database/HTTP checks, 48 authorization checks, 12 money checks and syntax across 62 PHP files. Added real concurrent imports, specification HTTP coverage and SQL fault injection; see `VERIFICATION-1.14.0.md`.

## 1.13.0 — Sale cancellation and delivery reversal

- Added manager-authorized cancellation for pending, approved and ready-for-delivery sales; delivered sales remain in the controlled return workflow.
- Atomically closes the converted reservation, reverses an open delivery, cancels pending receipt evidence and unresolved finance requests, and records vehicle movement.
- Unpaid cancellations release inventory. Verified receipts create a documented hold and refund obligation that cannot be resolved before independent refund verification.
- Extended the refund ledger to support sale cancellations while retaining separation of duties, branch scope and verified-balance limits.
- Added REST operations and a nonce-protected cancellation screen.
- Advanced the independent schema version to 1.9.0 and expanded the isolated database suite to 267 checks.

## 1.12.0 — Independently verified refunds

- Added append-only external refund records linked to controlled vehicle returns and original sales.
- Added separate refund recording and verification capabilities for finance staff; requesters cannot approve their own refund.
- Bounded pending and verified refunds by verified SAR receipts to prevent concurrent over-refund requests.
- Added partial and complete refund obligation states without rewriting original payment confirmations.
- Added branch-scoped REST operations and a nonce-protected finance screen.
- Advanced the independent schema version to 1.8.0 and expanded the isolated database suite to 259 checks.

## 1.11.0 — Controlled vehicle returns

- Added a branch-scoped delivered-vehicle return record with receiving location, condition, odometer, document reference, reason and responsible manager.
- Added the dedicated `adc_process_returns` capability for sales managers, general managers and administrators.
- Return receipt atomically marks delivery, sale and inventory as returned, records location/status movement and creates audit evidence.
- Records the financial obligation as `pending_refund`; the workflow does not claim or simulate a completed financial refund.
- Blocks direct transitions into returned state and requires a new inspection created after the return before inventory can become available.
- Advanced the independent schema version to 1.7.0 and expanded the isolated database suite to 249 checks.

## 1.10.0 — Vehicle holds and maintenance

- Added branch-scoped operational cases for vehicle holds and maintenance, including reason, assignee, review date, resolution and responsible staff.
- Failed mandatory inspections now atomically open a maintenance case, remove the vehicle from availability and record movement and audit history.
- Required staff to resolve an open case before any further vehicle transition; resolution returns the vehicle to inspection and a new passed checklist is required for availability.
- Added REST operations and a nonce-protected staff screen for opening and resolving cases.
- Advanced the independent schema version to 1.6.0 and expanded the isolated database suite to 240 checks.

## 1.9.0 — Vehicle receiving and mandatory inspection

- Added verified receipt and inspection tables with staff, UTC time, odometer, condition, document reference, notes, checklist result and optional image evidence IDs.
- Required a receipt before `received → inspection` and a latest passed checklist before `inspection → available`.
- Added mandatory exterior, interior, engine, tires and VIN checks; failed inspections require notes and keep inventory unavailable.
- Added branch-scoped REST operations and nonce-protected staff forms. Receipt and inspection writes commit only with audit events.
- Advanced the independent schema version to 1.5.0 and added real HTTP acceptance checks for both transition gates.

## 1.8.0 — Physical location movement and protected VIN correction

- Added same-branch vehicle movement between active physical locations with immutable movement history and audit events.
- Added the independent `adc_change_vehicle_vin` capability for general managers and administrators; inventory and sales roles do not receive it.
- VIN corrections require a valid 17-character VIN and documented reason and are limited to pre-sale operational states.
- Added REST and nonce-protected administrator interfaces for both operations.
- Audit failures roll back the location, movement row and VIN update; audit data stores VIN hashes rather than the identifiers.

## 1.7.0 — Brand and physical-location reference foundation

- Added verified `adc_brands` and `adc_locations` tables and optional indexed brand/location links on vehicles and movement history.
- Added audited administrator services and a nonce-protected management screen for brands, showrooms, warehouses, yards and service locations.
- New vehicle creation validates active brand and same-branch location references while retaining text-brand and zero-reference compatibility for existing records.
- Prevented deactivation of references used by inventory that has not reached delivered or cancelled state.
- Advanced the independent schema version to 1.4.0 and added isolated upgrade/reference tests.

## 1.6.0 — Migration inventory and reconciliation foundation

- Added `wp adc migration-report` with JSON or summary output, optional active fallback branch validation, and a CI-friendly `--fail-on-issues` mode.
- Inventoried legacy vehicle, offer, CRM, message, booking and subscriber sources plus their active write paths without returning personal or vehicle identifiers.
- Classified mapped parity/drift, eligible vehicles, invalid identity, workflow holds, unresolved branches, VIN/stock conflicts and orphaned offers.
- Made each legacy vehicle insert and required audit event commit atomically.
- Added the source-to-target map, staging sequence and explicit unresolved offer/CRM/subscriber decisions in `docs/MIGRATION-INVENTORY.md`.

## 1.5.0 — Privacy retention and minimized financial export

- Added an administrator-controlled 30–3650 day identity-retention setting, disabled by default, with daily bounded processing.
- Added transactional anonymization with a fresh lock-time eligibility check; active or recent leads, reservations, quotes, sales, financing, payments and deliveries remain protected.
- Added a finance-capability and branch-scoped CSV export with a 366-day range limit, 5000-row bound, minimized fields, spreadsheet-formula neutralization and mandatory audit event.
- Added an Arabic administrator download screen and six isolated database checks for policy eligibility, active-record preservation, audit rollback, date validation, minimization and export audit failure.

## 1.4.0 — Audited multi-branch staff scope

- Added a primary branch plus an allowed active-branch list for staff, with transparent fallback for existing single-branch metadata.
- Central branch checks and SQL predicates now authorize all assigned active branches and automatically exclude disabled assignments.
- Added an administrator profile multi-select, strict primary/list validation, a 25-branch bound, audit records, and compensating restoration of both metadata values.
- Added offline and isolated MariaDB checks for prepared multi-branch predicates, inactive secondary branches, canonical primary ordering, invalid primary lists and single-branch compatibility.

## Verification update — real HTTP authentication boundary

- Added a localhost-only HTTP harness over the disposable database, with test credentials held in process memory and no source-database writes.
- Verified real WordPress session cookies and REST/action nonces for anonymous, invalid-nonce, global administrator, foreign-branch and inactive-branch cases.
- Verified the printable quote admin action rejects an invalid nonce and serves the scoped immutable document with a valid session and action nonce.
- Extended the HTTP matrix across quote creation validation, discounts, reservations, payment recording and delivery VIN actions; denied requests also assert that protected database state is unchanged.
- Added real HTTP transition coverage for discount review, financing decisions, sale approval, independent receipt verification, and transfer approval/dispatch/receipt with both rejected foreign actors and successful eligible actors.
- Added HTTP coverage for public active-branch and lead intake, scoped CRM assignment/stage/activity/listing, inventory receipt/state transitions, and public catalog omission of VIN and purchase cost.
- Completed the funded-delivery HTTP path: remaining balance recording, independent verification, delivery preparation, exact VIN confirmation, separate approval and final release, with a rejected foreign/inactive actor before every protected transition.

## 1.3.1 — Inactive-branch revocation and configuration compensation

- Central branch scope now verifies that the assigned branch is active. Deactivation immediately denies service, list and direct-object REST access for staff while global administrators retain remediation access.
- Centralized dealership option and staff-branch changes in a configuration service. If audit persistence fails, every changed option or metadata value is restored, including restoring an originally absent option/meta record.
- Rejected inactive default branches and inactive staff assignments rather than silently substituting another value.
- Added offline and isolated MariaDB checks for inactive-branch scope, REST IDOR denial, administrator recovery access and audit-failure compensation.

## 1.3.0 — Printable immutable quotation documents

- Froze the originating branch, customer display name, vehicle stock number and vehicle description in every new quotation revision; phone, email and VIN are not copied.
- Changed quote-history authorization to the originating quote branch, preventing later vehicle relocation from transferring access to historical customer documents.
- Added a nonce-protected staff quotation screen and standalone escaped RTL print document. Browser printing provides PDF output without storing generated files.
- Included quotation identity snapshots in WordPress personal-data export and anonymized the customer-name snapshot during erasure while retaining financial history.
- Added conservative upgrade enrichment for older revisions from the currently known customer, vehicle and branch records; it does not claim those values are historical when the old system did not store them.

## 1.2.1 — Audit-atomic inventory and CRM writes

- Made branch and vehicle creation, inventory status transitions and all staged transfer transitions commit only with their audit event.
- Made public lead/customer creation, stage changes, reassignment and activity entry audit-atomic with locked authorization reads.
- Moved imported legacy request context into the same transaction as customer and lead creation, so a failed activity insert can be retried without leaving an incomplete imported lead.
- Added real InnoDB fault injection for every updated path, including the complete requested → approved → dispatched → received transfer workflow.

## 1.2.0 — Deterministic pricing and quote history

- Added strict integer minor-unit parsing and half-up VAT calculation without floating point.
- Stored `tax_rate_bps` on new quotes and reused that snapshot for later approved discounts, even after the global setting changes.
- Added append-only `adc_quotation_versions` snapshots for quote creation, discount request, approval and rejection, plus a branch/ownership-scoped REST history endpoint.
- Added conservative legacy backfill: preserve only the current known revision, keep unknown historical VAT as `NULL`, and block recalculation of such quotes.
- Made discount requests/decisions, sale approval and finance creation/status changes commit only when their required audit records succeed.
- Added money boundary tests and isolated MariaDB scenarios for frozen VAT, history capture rollback, revision ordering and history authorization.

## 1.1.0 — Schema verification, scoped transactions and receipt controls

- Added canonical schema checks for InnoDB, columns, explicit defaults and full/unique indexes; failed upgrades do not receive a success version marker. Dealership REST/admin operations are guarded while the schema is unverified.
- Added a disposable database runner for real WordPress/MariaDB integration and concurrent reservation tests; source credentials remain in memory and test databases are removed.
- Restricted customer use in quotes, reservations and sales to an accessible lead in the vehicle branch. Protected reservation replay by actor, payload and current scope; prevented post-sale quote discounts.
- Made reservation creation/cancellation/expiry, quote/sale creation, receipt operations and delivery transitions atomic with their audit events. Expiry now records inventory movement.
- Added receipt recording and independent financial verification with new capabilities, API routes and a Finance submenu. Delivery now requires verified full settlement and rechecks it at release; finance approval alone does not qualify.
- Fixed delivery preparation returning an invalid ID after database updates reset the last insert ID.

### Branch authorization included in 1.1.0

- Added a shared branch-scope policy and applied it to operational services and inventory, CRM, transfer, approval, finance and delivery queries.
- Denied unassigned employees access to branch-zero records and revoked old-branch lead access after staff reassignment, including stage/activity writes.
- Restricted direct vehicle reads by capability and branch; the private-fields argument cannot grant VIN access or purchase-cost permissions.
- Added a target-user nonce and strict input checks for staff branch assignment.
- Added 44 offline authorization regression checks. All passed on PHP CLI 8.2.12; plugin-wide PHP lint passed. Live database and browser integration remain unverified for this increment.

## 1.0.0 — Staged branch transfers

- Replaced immediate branch relocation with a transfer record and requested, approved/rejected, dispatched and received states.
- Added destination approval, source dispatch and separate destination receipt through REST and a staff transfer queue.
- Kept transfer-controlled vehicles unavailable to sales and generic inventory status changes until rejection or confirmed receipt; recorded each physical/status step in movement history.

## 0.9.0 — Branch inventory transfers

- Added a branch-scoped transfer service for available vehicles with a required reason and transactional movement history plus an audit event.
- Added a staff transfer queue and `POST /vehicles/{id}/transfer` endpoint; reserved, sold and otherwise unavailable vehicles cannot be moved.

## 0.8.0 — CRM privacy requests

- Registered WordPress personal-data export and erasure handlers for dealership customer, lead activity, and legacy message/booking data.
- Erasure removes direct identifiers and enquiry text while preserving minimized operational records linked to quotes, sales and audit history.
- Removes matching legacy newsletter subscriptions as part of an erasure request and reports retained operational records to the WordPress privacy workflow.

## 0.7.0 — Historical CRM intake migration

- Added `wp adc migrate-leads` for batched, idempotent import of existing theme messages and test-drive bookings, with dry-run and resume options.
- Preserved legacy request type, status, subject, requested appointment and message/reply context in CRM activity notes.

## 0.6.0 — Public catalog alignment

- Limited the public vehicle API to available inventory explicitly linked to a published vehicle post at an active branch; added public listing metadata and total-page counts.
- Synchronized public theme car queries with operational availability, hiding linked vehicles that are sold, reserved, held, or assigned to an inactive branch.

## 0.5.0 — Legacy intake bridge

- Connected new theme message and booking events to idempotent plugin CRM lead capture.
- Added explicit default-branch configuration and manual assignment of branchless incoming leads.
- Added the plugin's inventory, CRM, settings and read-only audit admin screens.
- Added administrator queues for discount and sale approvals, finance requests, and delivery preparation and release.
- Enforced assigned-branch scope within finance service actions, including REST calls, and limited VIN display to staff with operational VIN access.

## 0.4.0 — Workflow completion increment

- Added configurable tax rate, reservation duration, discount thresholds and administrator branch assignment.
- Added general manager controls, CRM assignment/activity endpoints and pricing floors.
- Tightened protected transitions so reservations, sales and delivery cannot be bypassed through generic inventory updates.
- Added a guarded end-to-end WordPress integration smoke test.

## 0.3.0 — Operational foundation

- Added versioned operational schemas, protected REST routes, branch-scoped vehicle inventory and lead workflows.
- Added reservation locking/expiry, discount approval, finance review, sales approval and delivery VIN/separation-of-duties controls.
- Added a dry-run-capable WP-CLI vehicle import that preserves legacy posts.
- Added local activation and full workflow smoke verification.

## 0.1.0 — Foundation

- Added standalone Auto Dealership Core plugin.
- Added scoped initial dealership roles and capabilities.
- Added append-oriented audit event schema and writer with correlation IDs.
- Added repository audit, database design, RBAC, workflow, API, security, operations and phased roadmap documentation.
- Existing theme features and pre-existing worktree modifications remain active and unchanged.
