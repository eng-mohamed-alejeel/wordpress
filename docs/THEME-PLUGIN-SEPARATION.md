# Theme and Core Plugin Separation Plan

## Objective

Make `auto-dealership-core` the stable owner of dealership data, business rules, permissions, workflows, APIs, background work and operational administration. Keep `car-dealer` as a replaceable presentation layer that owns templates, layout, public styling, theme supports and navigation.

The cutover must preserve the current post type slugs, taxonomy slugs, post meta keys, URLs, shortcodes and legacy request identifiers. The intentionally empty business database must remain empty; this work does not create branches, brands, vehicles, customers, suppliers, offers or sample content.

This sequence is currently tracked as **1.29.13 — deployable theme cleanup**. The plugin owns dealership registration, writes, permissions, operations and public read models. The theme renders them and holds appearance settings only. Former theme business PHP, historical checks and unused admin assets are preserved under `docs/archive/car-dealer`, outside the deployable theme. No runtime fallback loads them. Theme-switch and release acceptance remain pending.

## Current repository finding

The normal plugin-owned theme bootstrap loads these files directly:

- `inc/contact-form-manager.php`
- `inc/about-contact-pages.php`
- `inc/accounts.php`
- `inc/public-catalog.php`
- `inc/customization-manager.php`

`inc/customization-manager.php` is the fifth active include and owns only appearance and public contact settings. No legacy business include remains in the theme load chain. The plugin provides authorized editorial preview models as well as public models.

Vehicle/offer editors and account/customer/CRM/request mutations are plugin-owned. The hidden `cd_crm` type remains plugin-registered private read-only history. Message, booking and newsletter staff pages are plugin-owned under their existing slugs. The theme consumes plugin facades for shortcodes, catalog policy, structured data, vehicle/offer/home models, account history and workspace decisions. Former theme implementations are archived, not executable by the current theme. Public forms and catalog/account screens display an unavailable state if their plugin service is missing.

The historical classification is retained in `THEME-ADMIN-CLASSIFICATION.md`; the source files were moved without loading or copying their hooks into the plugin.

## Ownership contract

### The plugin owns

- Public and private dealership post types, taxonomies, metadata contracts and rewrite lifecycle.
- Roles, capabilities, branch scope, customer scope, privacy, audit and security events.
- Validation, state changes, pricing calculations, inventory eligibility and all writes to dealership records.
- Custom tables and schema upgrades.
- REST, AJAX, admin-post, WP-CLI and scheduled-job handlers that perform business work.
- Durable shortcodes and blocks whose content must survive a theme switch. The plugin supplies neutral HTML hooks or a view model that a theme may style.
- Operational WordPress administration pages and their own CSS/JavaScript assets.
- Notification/outbox production, external adapter contracts and provider configuration.
- Compatibility readers and migrations for legacy theme tables, posts and metadata.

### The theme owns

- `header.php`, `footer.php`, page/archive/single templates and template parts.
- Public layout, CSS, responsive behavior, typography, icons and purely visual JavaScript.
- Theme supports, image sizes, menus, Customizer appearance settings and editor styles.
- Rendering of plugin-provided catalog/account/form view models through documented hooks or template overrides.
- Presentation-only helpers such as card markup, headings, empty states and display formatting wrappers.

### Dependency rule

The plugin must never load a file from the theme or call a theme function. The theme may call a documented plugin facade after checking that it exists and must render a safe unavailable state if the plugin is inactive. No database write, permission decision or workflow transition may fall back to a theme implementation.

## File ownership matrix

| Current theme area | Target owner | Planned treatment |
|---|---|---|
| `functions.php`: `car`, `car_brand`, `car_category`, rewrite version | Plugin | Move unchanged public slugs and capability mapping into a plugin content registry; retire theme registration after the plugin registry is active. |
| `functions.php`: car meta boxes and `save_post` writes | Plugin | Replace direct metadata writes with a plugin editor/controller using the canonical vehicle specification and catalog mapping services. Keep keys readable during compatibility. |
| `functions.php`: `car_dealer_lead` AJAX and email | Plugin | Route through `PublicIntake` and the outbox policy; keep the action name as a compatibility alias during cutover. |
| `functions.php`: cars, finance calculator and testimonial shortcodes | Split | Register durable shortcode names in the plugin. Move query/calculation/state into plugin services; keep theme styling through classes, hooks or template overrides. Remove hard-coded testimonial records. |
| `contact-form-manager.php` | Plugin | Plugin owns intake handlers, legacy table compatibility, status changes, privacy and admin operations. Theme keeps form templates only. Stop theme `dbDelta` and duplicate writes after reconciliation. |
| `crm.php` and `customer-workflow.php` | Plugin | Retain only a bounded legacy reader/migration in the plugin. Core CRM becomes the sole writer. Move staff pages/actions into plugin admin and retire the `cd_crm` active workflow without deleting legacy posts. |
| `accounts.php` | Split | Plugin owns customer role, account actions, profile validation, request queries and supported endpoints. Theme keeps the account page template and navigation presentation. |
| `public-catalog.php` | Split | Catalog query policy, language contract, eligibility and canonical/robots/alternate decisions are plugin-owned. Theme keeps translated copy, archive/detail controls, cards and the visual language switch. |
| `schema-markup.php` | Plugin | `PublicStructuredData` now prints organization and eligible vehicle/offer JSON-LD independently of the active theme. The old theme file is dormant pending reviewed cleanup. |
| `about-contact-pages.php` and `auto-pages.php` | Theme/content setup | Implemented in 1.29.6: page templates and presentation blueprints remain in the theme; the plugin owns the explicit, idempotent and audited setup tool. Existing pages are never changed and the duplicate dormant writer is inert. |
| `admin-dashboard.php`, `admin-workspace.php`, vehicle editor/list files | Plugin | Move operational menus, forms, counters, permission checks and assets into plugin Admin modules. Theme must not be required for staff operations. |
| `inventory-management.php` and `advanced-features.php` | Plugin | Consolidate editor columns/filters/meta UI around plugin inventory services; remove direct status writes that bypass state rules. |
| `offers.php` | Split | Plugin owns offer registration, validation, eligibility and durable fields. Theme keeps offer archives, cards and detail rendering. Preserve `car_offer` and its public URLs. |
| `vehicle-comparison.php` | Split | Plugin owns identifiers, limits, validation and an optional account-backed store. Theme owns the comparison layout and interaction styling. |
| `loan-calculator.php` | Split | Plugin owns deterministic calculation/configuration and the shortcode/block contract. Theme owns the visual form. Results must state that they are estimates. |
| `social-media-integration.php` | Split | Plugin owns settings and queued outbound integrations; theme keeps share-link presentation. |
| `customization-manager.php`, `white-label.php` | Theme for appearance; plugin for admin product settings | Separate colors/logos/layout from operational labels, permissions and settings before moving anything. |
| `performance-optimization.php` | Review | Keep asset and rendering optimizations in the theme; move data-cache invalidation tied to domain writes into the plugin. |
| `testimonials.php` | Plugin registration, theme rendering | If this feature is retained, register durable content in the plugin and render it in the theme. Do not add example testimonials. |
| Remaining unreferenced `inc/` files | Review before use | Confirm whether they are dormant, obsolete or externally loaded. Delete or archive only after hook/runtime inventory and replacement acceptance. |

## Execution sequence

### 1.29-A — Inventory and stable contracts

**Implementation status:** the static load/hook baseline is recorded in `THEME-HOOK-INVENTORY.json`; runtime confirmation and later-slice retirement checks remain pending.

1. Produce a machine-readable inventory of theme hooks, shortcodes, post types, taxonomies, tables, options, meta keys, AJAX/admin-post actions, assets and scheduled jobs.
2. Mark each item as active, dormant, compatibility-only or obsolete by tracing the actual load chain.
3. Define plugin public facades for content registration, catalog reads, intake forms, account operations, display labels and shortcode view models.
4. Freeze existing public identifiers. Do not rename `car`, `car_offer`, taxonomy slugs, shortcode names, AJAX action names or existing metadata in this increment.
5. Record collision guards so exactly one owner registers each hook during every deployment state.

**Exit condition:** every theme business hook has a named plugin destination or an explicit retirement decision, and no uncertain file is copied into the plugin.

### 1.29-B — Content registry and compatibility bridge

**Implementation status:** implemented; verification pending.

1. Add plugin-owned registration for dealership post types, taxonomies and public metadata.
2. Preserve labels through the plugin text domain, capability types, REST visibility, archive paths and rewrite slugs.
3. Move rewrite-version management to plugin activation/upgrade. Never flush rewrites on every request.
4. Add a temporary compatibility signal that makes the theme skip its registrations when the plugin registry is available.
5. Keep the existing posts, terms, media and metadata in place; this is an ownership change, not a data copy.

**Exit condition:** switching away from `car-dealer` leaves dealership content types and public URLs registered by the plugin, with no duplicate registration warnings.

### 1.29-C — Single write path

**Implementation status:** public lead/contact/booking/subscription actions, compatibility-table installation, vehicle/offer editors, account/CRM/request mutations and comparison/calculator decisions are plugin-owned. Controlled retirement is implemented as a retain decision with measured exit gates: no compatibility store is dropped while active code or privacy behavior depends on it.

1. Move vehicle metadata/editor writes behind plugin validation and operational state rules. **Implemented:** mapped inventory status is read-only, unmapped compatibility values are validated, and failed audit persistence restores the previous metadata.
2. Make `PublicIntake` the sole writer for messages, enquiries and test-drive requests while preserving compatibility action names.
3. Make plugin CRM/account/customer workflow services the sole writers. Theme forms submit to supported plugin interfaces. **Implemented:** the existing account form delegates to `CustomerAccount`; `CustomerIdentity` and `RequestWorkflow` own updates; legacy `cd_crm` records are read-only history.
4. Move offer, comparison and calculator decisions into plugin services where retained. **Implemented:** offer linkage uses operational availability when mapped and compensates persistence failures; comparison accepts prior cookie formats but admits only publicly eligible vehicles; the calculator returns a bounded provider-neutral estimate and does not create a finance request.
5. Disable theme table installation and direct email dispatch after the plugin path is active. Keep historical rows read-only; allow only the controlled plugin projection writes still required by account/request/privacy behavior until a native replacement satisfies the retirement gates.
6. Audit every cutover write and preserve branch, ownership, replay, consent and privacy behavior.

**Exit condition:** theme PHP performs no dealership database writes, role/capability mutation, operational email dispatch, cron work or business AJAX/admin-post handling.

**Compatibility decision:** `car_dealer_messages` and `car_dealer_bookings` remain plugin-written controlled projections until native account history, request updates and privacy handling replace them. `car_dealer_subscribers` is a canonical consent store and requires a separately approved successor. `cd_crm` remains private read-only history. `CompatibilityRetirement::report()` exposes aggregate readiness and permanently returns `can_drop_request_tables=false` for this contract version; removal requires a later reviewed implementation that satisfies every reported exit requirement.

### 1.29-D — Operational administration

1. Move dealership dashboard, CRM, vehicle/editor, engagement and workflow pages to plugin Admin modules. **Implemented for active operations:** CRM/workflows, engagement pages, role-aware workspace and operational inventory are plugin-owned. Dormant theme modules are classified in `THEME-ADMIN-CLASSIFICATION.md` and remain inert pending cleanup acceptance.
2. Move the CSS/JavaScript used only by those plugin pages into plugin assets and enqueue it only on owned screens.
3. Replace theme counters and direct SQL with scoped plugin query services.
4. Retain WordPress editorial editing where appropriate, but enforce plugin validation on dealership fields.
5. Confirm staff can operate the system with a stock WordPress theme active.

**Exit condition:** no operational admin URL, action or asset depends on `get_template_directory()` or a `car_dealer` theme function.

### 1.29-E — Public adapters and slim theme

1. Move durable shortcode registration to the plugin and preserve names/content compatibility. **Implemented in 1.29.7 for all 18 recorded names**, with an early rollback filter and no duplicate active registration.
2. Expose neutral view models and filters for cars, offers, forms, account requests, comparison and calculation results. **Implemented in 1.29.7 for cars, comparison, calculator and contact intake; 1.29.8 adds catalog language/SEO and structured data; 1.29.9 adds eligible public offer models and bounded account request history; 1.29.10 adds archive query parity and isolates account compatibility paths; 1.29.11 isolates engagement and bootstrap compatibility and makes legacy CRM/workflow loading conditional; 1.29.12 moves account decisions into the plugin and isolates remaining public fallbacks. Runtime acceptance is pending.**
3. Reduce active theme `inc/` files to rendering adapters and appearance helpers.
4. Keep catalog templates, account template, cards and form markup in the theme where they are presentation concerns. The plugin prints JSON-LD so it remains available when the theme changes.
5. Document the minimum plugin API the theme requires and the visible behavior when the plugin is inactive.

**Exit condition:** searching the active theme finds no custom-table creation, `$wpdb` business queries, domain post/meta writes, role creation, business handler registration or workflow permission logic. The static source condition is met in 1.29.13; runtime acceptance remains pending.

### 1.29-F — Cutover, rollback and cleanup

1. Enable each ownership switch independently; never run old and new writers together.
2. Compare registered hooks/routes, public URLs and bounded record counts before and after each switch.
3. Rehearse rollback by restoring a matching pre-1.29.13 theme and plugin release together. Plugin feature filters still control their plugin services but no longer activate theme writers or readers. Do not include individual archived PHP files on a live site.
4. Remove compatibility code only after the new owner passes acceptance and the rollback window closes.
5. Classify dormant files and remove them in a separate reviewed change; do not mix deletion with ownership transfer.

**Exit condition:** the plugin passes the theme-independence gate, the `car-dealer` theme contains presentation code only, and rollback steps are documented and reversible.

## Acceptance gates

- Activating a standard WordPress theme does not remove dealership post types, taxonomies, REST routes, admin pages, roles, privacy handlers, jobs or operational workflows.
- Activating `car-dealer` with the plugin does not register any dealership type, shortcode or handler twice.
- Existing post IDs, term IDs, media, slugs, metadata keys and legacy linkage identifiers remain unchanged.
- Public Arabic/English catalog and account URLs retain their documented behavior.
- The active theme contains no `CREATE TABLE`, `dbDelta`, business `$wpdb` write, domain `update_post_meta`, role/capability mutation, operational `wp_mail`, scheduled business job or business AJAX/admin-post handler.
- All public writes still use nonce/replay/rate controls, consent rules and plugin validation; all staff reads/writes still enforce capability and branch/customer scope.
- A theme-switch journey covers public catalog, vehicle detail, intake, account, staff CRM, inventory and a representative controlled workflow.
- Cutover and rollback do not insert synthetic business data and do not contact a source/production database from local verification.

## First implementation slice

The implemented **1.29-B content registry and compatibility bridge** follows the recorded 1.29-A static hook inventory. This slice establishes the dependency direction needed by every later move:

1. Introduce a plugin `ContentRegistry` for `car`, `car_offer`, `car_brand`, `car_category` and their stable metadata contracts.
2. Register it from the plugin bootstrap before admin/public consumers.
3. Add a plugin ownership function/filter for the theme to detect.
4. Guard and then remove theme registration only after the plugin path is present.
5. Record rewrite migration version in the plugin without creating content or flushing on normal requests.

The 1.29-E ownership work runs through 1.29.13. The deployable theme now contains presentation and appearance code; its former business sources are in `docs/archive/car-dealer`. The 1.29-F acceptance sequence and evidence requirements are recorded in `THEME-CUTOVER-ACCEPTANCE.md`; the theme-switch journey, hook/URL review and matching-release rollback rehearsal remain pending. Source placement alone is not runtime acceptance.
