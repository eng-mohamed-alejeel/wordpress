# Implementation Roadmap

Scope confirmed by the user on 2026-09-27: start with an intentionally empty business database. Historical recovery/import is not a launch dependency. Next implementation focus is CRM/intake consolidation and removal of duplicate theme writes, followed by the remaining workflows and release gates. Reference data must use actual business inputs; migration tools remain available for later imports.

1.17.0 adds account-linked authenticated intake, administrator-reviewed CRM-only customer consolidation and suppression of duplicate account-profile writes. These build on scoped request updates and public intake in 1.15.0–1.16.0. Integration/browser/concurrency verification remains pending. Remaining identity work includes historical claims, financial merges, legacy profile retirement/access and profile/preferences synchronization. Current implementation and verification are recorded at the top of `EXECUTION-STATUS.md`; these increments do not complete the full plan.

Current increments and test evidence are in `EXECUTION-STATUS.md`; detailed acceptance criteria are in `IMPLEMENTATION-PLAN.md`. Core 1.13.0 adds sale cancellation and open-delivery reversal to independently verified refunds, controlled vehicle returns, hold and maintenance resolution, receiving and inspection evidence and protected inventory identity. Broad phases below remain incomplete.

1. **Audit and baseline:** inventory current routes, CPT/meta keys, custom SQL, roles/capabilities, scheduled jobs and integrations; snapshot and characterize current data.
2. **Architecture and security:** agree domain ownership, localized fields, branch policy, retention, approval thresholds and data migration plan.
3. **Core plugin:** bootstrap, versioned migrations, scoped capabilities, audit writer, error handling and service/repository conventions. Foundation is started in 0.1.0.
4. **Reference data and vehicles:** brands, branches, vehicle repository, VIN uniqueness, transition service and legacy CPT adapter.
5. **Inventory:** locations, receiving, inspection, movement history, transfer, holds, returns and availability locking. Staged branch transfers, audited holds, controlled returns, refunds and sale cancellation are implemented; field parity and reconciliation remain.
6. **Public website:** bilingual RTL/LTR rendering, accessible search/catalog/details, schema and URL-based filters backed by published vehicle data. Central availability now suppresses linked unavailable vehicles; end-to-end theme cutover and accessibility review remain.
7. **Customers, leads and CRM:** privacy/consent, assignments, pipeline, activities and secure import from current requests. New requests bridge automatically; historical messages/bookings now have a dry-run-capable WP-CLI migration.
8. **Pricing and quotations:** integer price history, originating-branch/customer/vehicle snapshots and browser print/PDF output are implemented; branded templates, server-generated PDF files and approved delivery adapters remain.
9. **Discount approvals and reservations:** configurable thresholds, separation of duties, atomic reservation, expiry job and audit.
10. **Sales, finance and delivery:** validated state transitions, manual receipt/refund verification, finance-provider adapter boundary and controlled vehicle release.
11. **Admin workspace and reports:** branch-aware dashboards, audit review and export controls.
12. **Integrations and hardening:** outbox/retries, notifications, rate limiting, privacy review, accessibility and performance.
13. **Verification and operations:** automated unit/integration/API/permission/workflow/security coverage, staging rehearsal, monitoring and recovery drill.
14. **Migration and release:** repeatable batches, row counts and reconciliation, backups, cutover, smoke checks and rollback plan.

Each phase requires acceptance criteria, migrated-data reconciliation and a staging review before dependent modules are enabled. Production readiness is not asserted by this roadmap or the 0.1.0 foundation.
