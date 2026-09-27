=== Auto Dealership Core ===
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.15.0

Modular dealership core for branch-aware vehicle inventory, leads, reservations, quotations, approvals, sales, finance and delivery workflows. The existing theme remains active as a compatibility layer during staged migration.

Audit callers must not pass passwords, tokens, payment card data, or unnecessary customer personal data.

Version 1.13.0 adds audited pre-delivery sale cancellation, delivery reversal, reservation closure, pending receipt cancellation and refund-gated inventory holds.

Version 1.14.0 adds validated public vehicle specifications, scoped editing with audit history, shared legacy field mapping and precise decimal price migration. Schema 1.10.0 is required. Local verification passed 365 database/HTTP checks, 48 authorization checks and 12 money checks. Production restore, reconciliation and cutover remain pending.

Version 1.15.0 consolidates public REST/theme enquiry creation, normalizes contact input, supports UUID replay protection and atomically retains the theme's customer-account request copy. Staff can read scoped activity history; follow-up dates update the lead. Schema 1.11.0 is additive. Integration and browser verification of this increment remain pending; the 1.14.0 test results do not cover it. See docs/CRM-INTAKE.md for rollout, rollback and remaining legacy workflows.
