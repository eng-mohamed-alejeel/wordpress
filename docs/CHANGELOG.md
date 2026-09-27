# Changelog

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
