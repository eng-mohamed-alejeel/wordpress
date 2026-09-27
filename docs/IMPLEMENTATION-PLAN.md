# Implementation Plan — Automotive Dealership Platform

## Purpose and current baseline

Implementation increments and verification evidence are recorded in `EXECUTION-STATUS.md`. Completed increments include central branch authorization, verified schema/customer transactions and manual receipt confirmation with funded-delivery controls. They do not close the full foundational or delivery phases.

This plan closes the gaps recorded in `PRODUCTION-READINESS.md` and expands `ROADMAP.md` into reviewable delivery phases. The repository already contains the `auto-dealership-core` plugin, operational tables, initial vehicle/branch/lead/reservation/sales/finance/delivery services, REST routes, staff screens, audit logging, privacy handlers, legacy migration commands, and a functioning `car-dealer` theme. The theme still owns substantial business behavior and remains the source of truth for unmigrated records.

Treat the platform as **not production ready** until the final release gate passes. Do not enable a module for operational use merely because its tables or screen exist.

## Delivery rules

1. Keep existing theme behavior and records working during migration. Do not drop or rename legacy tables, post types, metadata, or routes before reconciliation and an approved rollback window.
2. For each phase, record the owner, scope, schema/API changes, migration needs, security review, user documentation, and acceptance evidence in the pull request or release record.
3. Implement business rules in plugin services. REST and admin controllers validate input and call the same services; the theme renders and calls supported interfaces.
4. Use capability checks plus object ownership and branch scope on the server for every read/write. Enforce separation of duties in services.
5. Use UTC timestamps, integer minor currency units, prepared SQL, bounded queries, idempotent migration and jobs, and append-only audit interfaces.
6. Run the relevant automated checks for each phase, then the full verification suite at integration and release gates. Never record PASS without evidence.

## Phase 0 — Baseline, decisions, and delivery controls

**Scope:** establish the actual deployed baseline before changing business data.

**Work:**
- Inventory active plugins, theme behavior, post types/meta keys, request tables, scheduled jobs, REST/admin-ajax handlers, roles, capabilities, integrations, and deployment configuration.
- Compare the inventory with the current architecture/API/database docs and correct stale statements (including plugin version, installed plugin assumptions, and implemented routes).
- Capture a restorable database/files backup and a staging copy. Record PHP, WordPress, MySQL/MariaDB versions and the production-like environment configuration without exposing secrets.
- Decide and document branch assignment rules, vehicle/lead ownership, VAT configuration and rounding, quote validity, discount thresholds, reservation/deposit policy, finance states, privacy retention, and customer consent language.
- Define migration source-to-target maps and immutable legacy identifiers for vehicles, customers, bookings, messages, offers, media, and CRM records.

**Deliverables:** verified repository/deployment inventory; decision register; migration mapping; staging baseline; updated architecture and readiness docs.

**Acceptance:** a clean staging restore is repeatable; all legacy data sources and write paths are known; unresolved business decisions have named owners and dates; production secrets are outside source control.

## Phase 1 — Core boundaries, schema, and service conventions

**Scope:** make the core safe to extend before implementing more domains.

**Work:**
- Review bootstrap/loading, namespacing, error handling, schema-version upgrades, activation/deactivation behavior, and module boundaries. Introduce repository abstractions where they reduce duplicated persistence logic without building generic frameworks.
- Reconcile schema with the target model: brands, vehicle specifications and private costs, locations/warehouses/yards, customer consent/preferences, quote version snapshots, discount original/proposed prices and margin impact, payment verification references, delivery checklist/documents, and audit correlation/retention needs.
- Add only required tables/columns with versioned, repeatable migrations and useful indexes. Document constraints that cannot safely be enforced by dbDelta or all supported database versions.
- Add stable domain error codes, transaction helpers, and common authorization/branch-scope conventions. Confirm all monetary columns and conversions use SAR halalas and never floats.
- Make outbox event keys unique and define retry/backoff, dead-letter visibility, idempotency, and payload minimization before dispatching external events.

**Deliverables:** migration design and ERD update; versioned schema migration; service/repository conventions; error and event contract.

**Acceptance:** upgrade works on a production-like database copy without data loss; repeated migration is safe; schema checks detect partial installation; no external event is sent twice after retry; sensitive values are not copied into logs.

## Phase 2 — RBAC, branch isolation, privacy, and audit coverage

**Scope:** establish enforceable access boundaries before expanding staff operations.

**Work:**
- Complete role/capability matrix for sales, managers, inventory, delivery, purchasing, finance, marketing/customer service, auditor, and system administration. Grant only task-specific capabilities.
- Define a single branch-scope service and apply it consistently to REST, admin pages, exports, searches, counts, reports, and direct object access. Specify global-user behavior and users assigned to multiple branches.
- Review every route and admin-post handler for nonce/authentication, capability, ownership/branch checks, input validation, output escaping, rate limiting where public, and stable errors. Check IDOR and data leakage with negative cases.
- Enforce SoD for discount request/approval, sale creation/approval, finance status, delivery VIN confirmation/approval/release, and any payment verification. Reject self-approval in service code.
- Complete customer consent capture, privacy export/erasure across plugin and legacy data, retention schedule, masking, and deletion/anonymization policy. Ensure audit records do not retain unnecessary personal or financial information.
- Expand audit events for sensitive changes and add restricted audit review/export policy plus tamper-evidence/monitoring strategy.

**Deliverables:** reviewed RBAC/SoD matrix; access-control inventory; privacy and retention policy; audit event catalogue; remediation log.

**Acceptance:** automated permission tests cover allowed and denied roles, other-branch records, other-owner records, and self-approval; customer export/erasure is complete and repeatable; no ordinary staff path can edit/delete audit history.

## Phase 3 — Reference data and authoritative vehicle inventory

**Scope:** make the plugin inventory reliable as the single operational authority.

**Work:**
- Implement brand and branch/location management, including warehouses/yards and active/inactive rules. Add vehicle specification fields required by the business and define which fields may be public.
- Complete vehicle validation and transitions: Ordered, In Transit, Received, Inspection, Available, Reserved, Sold, Ready for Delivery, Delivered, plus Hold, Maintenance, Returned, Cancelled, and Transferred. Record actor, UTC time, reason, and movement/audit event.
- Protect VIN and stock number uniqueness, validate VIN changes with elevated permission and reason, and prevent sale/delivery without confirmed VIN.
- Complete receiving, inspection, holds, location changes, transfers, returns, reconciliation and physical receipt evidence. Keep a movement history for every location/status change.
- Add field-level access controls for purchase cost, total cost, price floors, and margin; confirm public and standard staff APIs never expose them.
- Run vehicle migration in dry-run batches, preserve legacy post IDs and media references, report duplicates/invalid rows, reconcile counts and sampled fields, and provide reversible read cutover.

**Deliverables:** inventory domain; location and movement UI/API; migration reports; documented vehicle field mapping.

**Acceptance:** duplicate VIN/stock is rejected under concurrent requests; invalid transitions and wrong-branch operations fail; every movement is traceable; imported and source row counts reconcile with explicit exception handling; public endpoints omit private fields.

## Phase 4 — CRM and customer intake migration

**Scope:** move leads and activities into the plugin without losing existing customer journeys.

**Work:**
- Complete customer profile, normalized contact lookup, source, consent/preferences, assignment, ownership, branch, interested vehicles, and notes with field-level privacy rules.
- Complete lead pipeline and transitions, including required lost reason, follow-up scheduling, reassignment rules, activity timeline, test drives, quotations, finance requests, reservations, and sales events.
- Consolidate website forms, WhatsApp/contact links, booking/message flows, and staff entry into supported plugin services. Apply anti-spam/rate controls and consent capture to public intake.
- Migrate legacy CRM posts, bookings, messages, and customer links idempotently. Resolve duplicate customers using an explicit match policy; do not merge ambiguous records automatically.
- Cut CRM reads/writes over behind a reversible feature flag. Retain a monitored compatibility bridge during the agreed transition, then remove duplicate theme-side business logic after acceptance.

**Deliverables:** CRM workflow; intake/API contracts; deduplication and migration report; cutover/rollback steps.

**Acceptance:** new and migrated records appear once; lead ownership and branch restrictions apply across lists/details/exports; lost leads require a reason; customer privacy export/erasure covers migrated and unmigrated records; rollback returns reads/writes to the legacy path without data loss.

## Phase 5 — Quotes, pricing, discounts, and reservations

**Scope:** implement trustworthy price decisions and vehicle holds.

**Work:**
- Build a central pricing calculation from configured base price, costs, VAT, fees, promotions, wholesale/retail policy and margin. Keep tax rate/effective date configurable and reviewed for the Saudi business context.
- Record immutable price history with old/new values, actor, time, and reason. Restrict private cost/margin reads.
- Complete quotations with numbered immutable versions, snapshots of vehicle/customer/pricing/tax, expiration, approval status, print/PDF output, email and WhatsApp-ready sharing. External delivery stays behind approved adapters and consent.
- Implement discount tiers and configurable thresholds with requester/approver separation, original/proposed price, reason, decision, and margin impact where authorized.
- Complete reservation lifecycle and expiry job, including idempotency, atomic availability lock, expiration, cancellation, deposits/payment references, manager holds, and conversion to sale. Never infer a verified payment from a reference string.

**Deliverables:** pricing policy/configuration; quote versioning and output; discount approval queue; reservation operations and expiry monitoring.

**Acceptance:** calculations pass boundary/rounding/tax cases; previous quote versions cannot be overwritten; concurrent confirmed reservations cannot hold the same vehicle; expiry does not release paid, held, or actively sold vehicles; approval thresholds and SoD are tested.

## Phase 6 — Sales, finance requests, and controlled delivery

**Scope:** implement the full controlled path from accepted quote to delivered vehicle.

**Work:**
- Complete sale creation from valid quote/reservation, sales approval, invoice reference, cancellation/reversal policy, and audited state transitions.
- Complete finance-provider request lifecycle and retrying another provider without recreating customer/vehicle context. Store only necessary provider references and consent; never store bank credentials or card data.
- Separate finance approval from payment verification. Define an authorized confirmation source and record its actor/reference/time; do not let browser-supplied status release inventory.
- Complete delivery checklist for customer/vehicle/VIN/documents/branch, VIN confirmation by a separate authorized user, approval, release, and delivered record. Require approved sale and verified payment/finance plus inventory checks.
- Add exception paths for failed finance, refunds/deposits, cancelled sale, returned vehicle, and delivery reversal with reason and audit trail.

**Deliverables:** sales and delivery state machines; finance adapter boundary; delivery checklist; exception runbooks.

**Acceptance:** delivery release is impossible without every required server-side precondition; same-user conflicting approvals fail; finance approval alone does not mark payment verified; every reversal preserves prior history and reason.

## Phase 7 — Public site, catalog cutover, bilingual UX, and accessibility

**Scope:** complete the customer-facing website on authoritative inventory while preserving editorial content.

**Work:**
- Agree bilingual implementation and translation ownership for Arabic/RTL and English/LTR, including slugs, metadata, empty/error states, forms, email templates, and staff-facing customer communications.
- Complete responsive, accessible homepage and pages: vehicle catalog/details, brands, offers, comparison, financing, reservation, branches, about, contact, FAQ, privacy, terms, news, search, and customer account. Reuse the existing theme components where suitable.
- Complete catalog filters and sorting (brand/model/trim/year/price/mileage/body/fuel/transmission/engine/drive/colors/branch/availability/condition), pagination, AJAX enhancement, shareable query URLs, canonical/noindex policy, and bounded database queries.
- Link published vehicle posts to core inventory with explicit mapping. Hide unavailable/inactive-branch vehicles and prevent unmapped/private operational records from public display.
- Complete SEO structured data, metadata, image sizing/lazy loading, caching strategy, accessible keyboard/focus states, contrast, labels, and form errors.
- Move remaining public-facing business rules out of theme into plugin APIs/services, leaving templates, rendering and navigation in the theme.

**Deliverables:** approved content/page matrix; bilingual design review; authoritative catalog; SEO/accessibility/performance review; theme cutover checklist.

**Acceptance:** representative Arabic and English journeys work on mobile and desktop; RTL/LTR is correct; filters persist/share safely; public output contains only eligible vehicles and fields; accessibility and performance criteria are measured and documented; no critical browser or PHP errors in staging.

## Phase 8 — Staff workspace, reports, notifications, and integrations

**Scope:** finish day-to-day operations and controlled system connections.

**Work:**
- Replace duplicate theme admin CRM/workspace flows with plugin-owned staff queues for leads, inventory, transfers, reservations, approvals, finance, delivery, audit, and branch dashboards.
- Implement operational and financial reports with explicit capability, branch scope, date limits, export audit, masking, and CSV formula-injection protection.
- Implement notification templates/channels and outbox workers with consent checks, retries, deduplication, failure queues and operator visibility. Do not block database transactions on remote services.
- Define and implement adapter contracts for ERP/accounting, payment verification, finance providers, WhatsApp/email, and vehicle feeds as actually required. Specify authentication, timeouts, retries, idempotency, field minimization and reconciliation per provider.
- Add monitoring for scheduled jobs, outbox failures, migration exceptions, schema failures, API errors and security events without logging secrets or unnecessary personal data.

**Deliverables:** staff workspace; report catalogue; notification operations page; integration contracts and adapter runbooks; monitoring dashboard/alerts.

**Acceptance:** every queue is branch/capability scoped; exports are audited and safe; provider outage does not lose local records; retries are bounded/idempotent; operational failures are visible with actionable recovery instructions.

## Phase 9 — Migration rehearsal and controlled cutover

**Scope:** migrate real data only after domain behavior and reconciliation are proven.

**Work:**
- Rehearse on a recent, protected production copy with the same database engine/version and representative scale.
- Run migrations in dry-run mode, then staged batches. Produce counts, duplicate/invalid records, relationship gaps, media mapping results and field-level samples for vehicles, CRM, bookings/messages and offers.
- Correct source data or document approved exceptions. Re-run until counts and business totals reconcile.
- Define a short write freeze or dual-write policy, feature flags, cutover order, monitoring window, decision owner, rollback trigger, and reverse-sync procedure for writes made after cutover.
- Cut over one domain at a time (inventory, CRM, reservations/sales), verify public and staff paths, and retain legacy data read-only until sign-off and retention requirements allow removal.

**Deliverables:** signed rehearsal report; reconciliation and exception register; cutover timeline; rollback and reverse-sync plan; post-cutover verification record.

**Acceptance:** a timed rehearsal completes within the maintenance window; no unexplained count/value mismatch; rollback has been rehearsed; named operators can restore service and reconcile post-cutover writes.

## Phase 10 — Verification, security review, and production gate

**Scope:** provide evidence for production use rather than relying on implementation claims.

**Work:**
- Run PHP lint/static checks, unit/service tests, REST contract tests, database migration tests, capability/branch/SoD tests, workflow tests, privacy tests, and theme browser journeys.
- Perform security review for authentication, authorization/IDOR, nonce handling, injection, XSS, uploads, rate limiting, data exposure, secrets, logging, CSRF, and dependency/update posture.
- Verify supported PHP/WordPress/database versions, production configuration, HTTPS, mail/cron behavior, backups, restore, monitoring, error reporting and rollback.
- Review mobile, RTL/LTR, accessibility, SEO, query performance under representative inventory size, caching invalidation and pagination/filter behavior.
- Update `PRODUCTION-READINESS.md` with PASS/WARNING/FAIL and evidence links for each area. Any critical FAIL blocks release; warnings need an owner, mitigation and accepted due date.

**Deliverables:** automated verification report; security findings and remediations; restore-drill record; final readiness assessment; release and rollback runbook.

**Acceptance:** no critical security or data-integrity issues; all critical user journeys and negative authorization cases pass; successful restore and rollback rehearsal; production readiness has evidence for every PASS and explicit sign-off by technical and business owners.

## Cross-phase module checklist

For every operation, confirm:

- Server-side capability, ownership, branch and state preconditions.
- Validation, bounded input/query sizes, prepared persistence and escaped output.
- Transaction/locking/idempotency where concurrent or retryable.
- Stable errors and useful operational logging without secrets or excess personal data.
- Audit event for sensitive change, with actor, UTC timestamp, reason and correlation ID.
- Data migration/backfill, privacy/export/erasure impact and rollback behavior.
- Arabic/English, RTL/LTR, responsive and accessible behavior where user-facing.
- Automated tests and updated API/schema/operator documentation.

## Suggested sequencing and release slices

Deliver in deployable slices: (A) Phase 0–2 foundation/security; (B) Phase 3 inventory; (C) Phase 4 CRM; (D) Phase 5 quote/reservation; (E) Phase 6 sale/delivery; (F) Phase 7 public cutover; (G) Phase 8 operations/integrations; (H) Phase 9–10 migration and production gate. Each slice may ship to staging independently, but production cutover depends on the relevant migration rehearsal and verification gate. Parallel implementation is safe only after shared schema, authorization, and API contracts are agreed.

## Items requiring business decisions

Resolve in Phase 0 before dependent implementation: branch/user assignment model; VAT/tax rates and effective dates; price/discount limits and approver hierarchy; deposit and refund policy; definition and source of payment verification; finance-provider status semantics; quote validity and numbering; customer consent wording; retention and erasure obligations; required Arabic/English translation workflow; integrations and data ownership with any ERP/accounting system.
