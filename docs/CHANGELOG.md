# Changelog

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
