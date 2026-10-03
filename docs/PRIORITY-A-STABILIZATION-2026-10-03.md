# Priority A — release stabilization, 2026-10-03

Follow-up completed: the later `PRIORITY-A-ACCEPTANCE-2026-10-03.md` records 690 isolated database/HTTP, 34 catalog browser and 33 account browser checks, all passing. Statements below about tests not rerun describe the original stabilization step only. The release artifact and target-environment gates remain open.

Baseline: `4893af9`, plugin/theme 1.29.13, schema contract 1.17.0. The user requested execution of the comprehensive review's first priority.

## Completed

- R07: repaired the offline authorization fixture's stale expectation that `adc_view_finance` alone includes acquisition costs. Runtime policy remains unchanged: costs require `adc_view_vehicle_costs` or administrator access, alongside vehicle-read and branch authorization.
- Added checks for finance-only denial, explicit cost access without VIN, retained branch scope, deny-all for missing branch assignment, and cost-capability revocation. Existing inventory-only VIN access without cost access remains covered.
- R09: corrected `TESTING.md`'s outdated 1.28 acceptance status, empty-source description and obsolete active-theme test path. Current commands and prior acceptance evidence are distinguished.
- R08 documentation: corrected the release-package claim that later bilingual edits remain uncommitted. The pre-stabilization tree was clean; the older ZIP still represents its own earlier commit and is not current-release evidence.
- Updated role documentation, execution status and readiness to reflect the stabilization result.

## Verification performed

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/branch-scope.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/money.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/pricing-policy.php
& C:/xampp/php/php.exe -l wp-content/plugins/auto-dealership-core/tests/branch-scope.php
git diff --check
```

Results: **52 authorization, 12 money and 16 pricing-policy checks passed**, changed-test syntax passed, and whitespace validation passed. The authorization suite records queries; it does not prove SQL execution, HTTP authentication or concurrent writes. No database or browser suite was rerun because runtime code was unchanged. No source data, runtime permission, catalog setting, page publication or provider activation changed.

## Remaining before phase A / release closure

R07 and R09 are complete for the identified review findings. R08 is only partially complete: a new immutable package requires a reviewed release commit containing the final accepted changes. No commit or package was created during this step; the builder correctly rejects modified plugin/theme paths.

Full release acceptance still requires a fresh isolated database/HTTP and browser run against the final candidate, followed by target-environment checks. The review's four automatic launch failures and nine manual gates remain open: HTTPS staging, approved legal/business data, real authoritative catalog, secret-history review, operations, security/accessibility/load/recovery and release sign-off. Follow phases B–F in `COMPREHENSIVE-REVIEW-AND-PLAN-2026-10-03-AR.md`.
