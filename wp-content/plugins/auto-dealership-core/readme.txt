=== Auto Dealership Core ===
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.17.0

Modular dealership core for branch-aware vehicle inventory, leads, reservations, quotations, approvals, sales, finance and delivery workflows. The existing theme remains active as a compatibility layer during staged migration.

Audit callers must not pass passwords, tokens, payment card data, or unnecessary customer personal data.

Verification update 2026-09-28: 1.15–1.17 now passes 468 isolated database/HTTP checks, 48 authorization checks, 12 money checks, seven real Chromium DOM checks and syntax across 70 plugin plus four theme PHP files. These results supersede the initial pending-verification notes below. Full theme/browser journeys, mixed races and legacy pagination/cursor acceptance remain; see docs/VERIFICATION-1.17.0.md. No source database was contacted or modified by this run.

Version 1.17.0 adds authenticated account-linked intake and administrator-reviewed CRM-only customer consolidation with preview revisions, evidence confirmation, scope/reference checks and atomic audit. New login/profile hooks stop creating duplicate theme account profiles. Schema 1.12.0 adds nullable account and merge references. Syntax passed for 68 plugin PHP files and four theme files; integration/concurrency/browser verification remains pending. See docs/CUSTOMER-IDENTITY.md for eligibility and limits.

Version 1.16.0 centralizes linked request replies, status updates, test-drive rescheduling and customer cancellation. It adds scoped REST/admin forms, stale-form protection, atomic activity/audit writes and scoped legacy request lists/counts. Schema remains 1.11.0. PHP syntax passed for 66 plugin files and four changed theme files; integration/browser verification remains pending.

Version 1.13.0 adds audited pre-delivery sale cancellation, delivery reversal, reservation closure, pending receipt cancellation and refund-gated inventory holds.

Version 1.14.0 adds validated public vehicle specifications, scoped editing with audit history, shared legacy field mapping and precise decimal price migration. Schema 1.10.0 is required. Local verification passed 365 database/HTTP checks, 48 authorization checks and 12 money checks. Production restore, reconciliation and cutover remain pending.

Version 1.15.0 consolidates public REST/theme enquiry creation, normalizes contact input, supports UUID replay protection and atomically retains the theme's customer-account request copy. Staff can read scoped activity history; follow-up dates update the lead. Schema 1.11.0 is additive. Integration and browser verification of this increment remain pending; the 1.14.0 test results do not cover it. See docs/CRM-INTAKE.md for rollout, rollback and remaining legacy workflows.
