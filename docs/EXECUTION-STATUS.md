# Implementation execution status

Updated: 2026-09-29. This records completed increments, not completion of whole phases.

## Current increment: 1.20.0 (implementation and local acceptance complete)

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

1. Local 1.14.0 integration verification is complete for the covered scenarios. Interactive specification form layout, accessibility and browser journeys remain part of the staging review.
2. Prepare the new-installation setup using actual branch, brand, location and staff assignments when supplied. Current-state backup/restore passed; no historical restore/import is required.
3. Local 1.18.0 verification passes 515 database/HTTP checks, 33 real-theme Chromium checks plus three post-journey database assertions, and seven separate form-script checks. Historical claims and financial/documentary merging apply only if later required; no historical import is needed for this empty deployment.
4. Pricing fees/promotions/discount tiers, reservation deposit policy, branded quote output and delivery document gates are implemented and locally accepted in 1.19.0. Complete provider/ERP reconciliation and business review of the configured values.
5. Reconcile real vehicle/post mappings and activate/review the implemented authoritative catalog. The synthetic Arabic responsive journey passes; complete English LTR, AJAX enhancement, formal accessibility measurement and performance review.
6. Implement scoped operational reporting, notification outbox/retries, provider-specific ERP/payment/finance/message adapters and job monitoring after provider contracts and credentials are supplied.
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
| REST/admin | PASS: real localhost HTTP checks cover session cookies, nonces, validation, public active-branch listing, public lead intake, scoped CRM, inventory creation/state, complete public catalog filters/minimization, quote/discount/reservation/sale/finance/payment boundaries, complete staged transfers, and the funded delivery sequence through balance entry, independent verification, preparation, VIN confirmation, approval and release. Negative cases assert unchanged rows/state; eligible actors complete transitions. Arabic catalog and account browser layouts pass at four viewport sizes; English LTR and formal accessibility remain open. |
| Cleanup | PASS: the runner removed its generated database; test scenarios did not write to source tables. |

## Next ordered work

1. Complete the production-copy staging backup/restore rehearsal, route/job inventory and detailed source-to-target migration mapping. A fresh synthetic database is now available as a repeatable test fixture; it does not replace that rehearsal.
2. Decide whether server-generated PDF storage/delivery is required beyond the branded browser print/PDF document, and define retention controls if it is. Extend remaining route-level HTTP IDOR coverage.
3. Approve the configured legal retention duration and exported accounting fields, then decide whether a non-WordPress global dealership role is required.
4. Complete vehicle field parity, provider/ERP reconciliation and cancellation exceptions, then reconcile vehicle/CRM migration and remove duplicate theme business logic.
5. Connect payment-provider/ERP reconciliation when an approved provider contract exists, while keeping the current manual receipt, deposit, refund and delivery-document attestations explicit until that adapter is verified.
6. Complete the English catalog journey and real-data cutover, then continue reports/outbox/integrations and the release gate in the implementation plan.

Phase 0 remains incomplete: no production-copy restore, migration reconciliation, or business-policy sign-off is claimed. Phases 1–2 progressed and the existing sales/delivery workflow gained a necessary payment control from phase 6. Full completion of any of those phases is not claimed. Overall production readiness remains FAIL.
