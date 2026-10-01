# Theme and Core Plugin Separation Plan

## Objective

Make `auto-dealership-core` the stable owner of dealership data, business rules, permissions, workflows, APIs, background work and operational administration. Keep `car-dealer` as a replaceable presentation layer that owns templates, layout, public styling, theme supports and navigation.

The cutover must preserve the current post type slugs, taxonomy slugs, post meta keys, URLs, shortcodes and legacy request identifiers. The intentionally empty business database must remain empty; this work does not create branches, brands, vehicles, customers, suppliers, offers or sample content.

This is the immediate implementation increment after 1.28.0 and is tracked as **1.29.0 — theme/plugin ownership cutover**. The content-registry/compatibility-bridge code slice is implemented; its verification and the remaining slices are pending.

## Current repository finding

The active theme bootstrap currently loads these files directly:

- `inc/contact-form-manager.php`
- `inc/about-contact-pages.php`
- `inc/accounts.php`
- `inc/crm.php`
- `inc/customer-workflow.php`
- `inc/public-catalog.php`
- `inc/schema-markup.php`

The theme source still contains compatibility definitions for `car`, `car_brand`, `car_category`, legacy engagement tables and public AJAX handlers, but the implemented plugin ownership facades now suppress those registrations and writers during normal operation. Vehicle metadata saving, the hidden CRM post type, account/customer mutations, durable shortcodes and public catalog adapters remain active theme responsibilities pending their later slices.

Several other `inc/` files contain business or administration code but are not referenced by the current theme require chain. They must be classified as dormant, externally loaded or obsolete before any move. Copying them into the plugin without that check would reactivate duplicate hooks and handlers.

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
| `public-catalog.php` | Split | Move query policy, language contract, eligibility and canonical data builders to the plugin. Theme keeps archive/detail controls, cards and layout adapters. |
| `schema-markup.php` | Split | Plugin supplies authoritative vehicle and offer structured-data values; theme may print presentation markup. Generic site identity markup may remain in the theme. |
| `about-contact-pages.php` and `auto-pages.php` | Theme/content setup | Keep page templates and sections in the theme. Replace automatic `wp_insert_post`/`wp_update_post` on admin requests with an explicit, idempotent setup tool; do not insert pages automatically during this cutover. |
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

**Implementation status:** public lead/contact/booking/subscription actions and compatibility-table installation are plugin-owned. Vehicle/offer metadata, account/CRM mutations and final retirement of compatibility copies remain pending.

1. Move vehicle metadata/editor writes behind plugin validation and operational state rules.
2. Make `PublicIntake` the sole writer for messages, enquiries and test-drive requests while preserving compatibility action names.
3. Make plugin CRM/account/customer workflow services the sole writers. Theme forms submit to supported plugin interfaces.
4. Move offer, comparison and calculator decisions into plugin services where retained.
5. Disable theme table installation and direct email dispatch after the plugin path is active. Preserve legacy tables read-only until the existing migration/retention policy permits removal.
6. Audit every cutover write and preserve branch, ownership, replay, consent and privacy behavior.

**Exit condition:** theme PHP performs no dealership database writes, role/capability mutation, operational email dispatch, cron work or business AJAX/admin-post handling.

### 1.29-D — Operational administration

1. Move dealership dashboard, CRM, vehicle/editor, engagement and workflow pages to plugin Admin modules.
2. Move the CSS/JavaScript used only by those plugin pages into plugin assets and enqueue it only on owned screens.
3. Replace theme counters and direct SQL with scoped plugin query services.
4. Retain WordPress editorial editing where appropriate, but enforce plugin validation on dealership fields.
5. Confirm staff can operate the system with a stock WordPress theme active.

**Exit condition:** no operational admin URL, action or asset depends on `get_template_directory()` or a `car_dealer` theme function.

### 1.29-E — Public adapters and slim theme

1. Move durable shortcode registration to the plugin and preserve names/content compatibility.
2. Expose neutral view models and filters for cars, offers, forms, account requests, comparison and calculation results.
3. Reduce active theme `inc/` files to rendering adapters and appearance helpers.
4. Keep catalog templates, account template, cards, schema printing and form markup in the theme where they are presentation concerns.
5. Document the minimum plugin API the theme requires and the visible behavior when the plugin is inactive.

**Exit condition:** searching the active theme finds no custom-table creation, `$wpdb` business queries, domain post/meta writes, role creation, business handler registration or workflow permission logic.

### 1.29-F — Cutover, rollback and cleanup

1. Enable each ownership switch independently; never run old and new writers together.
2. Compare registered hooks/routes, public URLs and bounded record counts before and after each switch.
3. Rehearse rollback by restoring the prior owner flag without changing identifiers or deleting records.
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

The public-intake portion of 1.29-C is implemented. The remaining 1.29-C work starts with vehicle/offer metadata writes, followed by account and legacy CRM mutations; each handoff must retain exactly one active writer.
