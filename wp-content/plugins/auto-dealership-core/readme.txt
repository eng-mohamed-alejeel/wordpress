=== Auto Dealership Core ===
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.23.0

Modular dealership core for branch-aware vehicle inventory, leads, reservations, quotations, approvals, sales, finance and delivery workflows. The existing theme remains active as a compatibility layer during staged migration.

Audit callers must not pass passwords, tokens, payment card data, or unnecessary customer personal data.

Version 1.23.0 adds a durable local outbox with hashed idempotency, minimized allowlisted payloads, integrity verification, concurrent leases, bounded backoff, terminal failures, audited operator retry, a restricted job monitor and a five-minute WP-Cron worker. No external provider or random business data is enabled. Schema 1.14.0 is additive. Local acceptance passes 597 isolated database/HTTP checks; see docs/OUTBOX-OPERATIONS.md and docs/VERIFICATION-1.23.0.md.

Version 1.22.0 adds an administrator catalog-cutover workspace, audited one-to-one operational vehicle/post mapping, bounded discrepancy lists with complete totals, a reconciliation fingerprint, and stricter activation gates that reject an empty source, an empty eligible public result, or invalid mappings. Compatibility remains the default while the intentionally empty operational database has no real values to map. Schema remains 1.13.0. Local acceptance passes 576 isolated database/HTTP checks, 34 catalog-browser checks, 33 account-browser checks plus three post-journey assertions, seven DOM checks, 48 authorization checks, 12 money checks, 16 pricing-policy checks, and syntax across 84 plugin plus 41 theme PHP files and three Node scripts. See docs/VERIFICATION-1.22.0.md.

Version 1.21.0 adds an explicit Arabic/English catalog URL contract, responsive RTL/LTR rendering, localized catalog forms and AJAX responses, canonical/hreflang output, structural accessibility checks, and a representative 240-vehicle query/performance budget. Compatibility remains the default while the intentionally empty operational database has no real post mappings. Schema remains 1.13.0. Local acceptance passes 565 isolated database/HTTP checks, 34 catalog-browser checks, 33 account-browser checks plus three post-journey assertions, seven DOM checks, 48 authorization checks, 12 money checks and 16 pricing-policy checks. No source database was contacted; see docs/VERIFICATION-1.21.0.md.

Version 1.20.0 adds the operational public-catalog read model, bounded URL filters and sorting, theme card/detail/schema adapters, SEO handling for filtered URLs, and an audited compatibility/authoritative cutover setting. See docs/VERIFICATION-1.20.0.md.

Version 1.19.0 adds deterministic quote fees and dated promotions, frozen branded seller snapshots, two configured discount approval tiers with margin impact, reservation deposit policy with independently reviewed receipt/refund evidence, and configurable delivery-document gates. Schema 1.13.0 is additive. Local acceptance passes 541 isolated database/HTTP checks plus the existing authorization, money and browser suites; no source database was contacted or modified. See docs/PRICING-POLICY.md and docs/VERIFICATION-1.19.0.md.

Version 1.18.0 adds explicit account marketing preferences, immediate synchronization of existing linked customer profiles, authenticated preference REST routes, account-ID-based retirement of legacy CRM profiles and privacy export/erasure for legacy CRM history. Schema remains 1.12.0. Local acceptance passes 515 isolated database/HTTP, 48 authorization, 12 money, 33 real-theme browser plus three post-journey database, and seven DOM checks; see docs/VERIFICATION-1.18.0.md.

Verification update 2026-09-29: 1.15–1.17 now passes 490 isolated database/HTTP checks, 48 authorization checks, 12 money checks, 27 real-theme Chromium journey checks, seven separate public-intake DOM checks and syntax across 74 plugin plus seven theme PHP files. These results supersede the initial pending-verification notes below and close the listed account journey, targeted mixed races and legacy pagination/cursor acceptance; see docs/VERIFICATION-1.17.0.md and docs/ACCOUNT-JOURNEY-VERIFICATION.md. No source database was contacted or modified by these runs.

Version 1.17.0 adds authenticated account-linked intake and administrator-reviewed CRM-only customer consolidation with preview revisions, evidence confirmation, scope/reference checks and atomic audit. New login/profile hooks stop creating duplicate theme account profiles. Schema 1.12.0 adds nullable account and merge references. Subsequent integration, targeted concurrency and account-browser acceptance passed as recorded in docs/CUSTOMER-IDENTITY.md and docs/ACCOUNT-JOURNEY-VERIFICATION.md.

Version 1.16.0 centralizes linked request replies, status updates, test-drive rescheduling and customer cancellation. It adds scoped REST/admin forms, stale-form protection, atomic activity/audit writes and scoped legacy request lists/counts. Schema remains 1.11.0. Subsequent integration and browser verification is included in the current evidence above.

Version 1.13.0 adds audited pre-delivery sale cancellation, delivery reversal, reservation closure, pending receipt cancellation and refund-gated inventory holds.

Version 1.14.0 adds validated public vehicle specifications, scoped editing with audit history, shared legacy field mapping and precise decimal price migration. Schema 1.10.0 is required. Local verification passed 365 database/HTTP checks, 48 authorization checks and 12 money checks. Production restore, reconciliation and cutover remain pending.

Version 1.15.0 consolidates public REST/theme enquiry creation, normalizes contact input, supports UUID replay protection and atomically retains the theme's customer-account request copy. Staff can read scoped activity history; follow-up dates update the lead. Schema 1.11.0 is additive. Integration and browser verification of this increment remain pending; the 1.14.0 test results do not cover it. See docs/CRM-INTAKE.md for rollout, rollback and remaining legacy workflows.
