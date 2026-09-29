# Verification 1.19.0

Completed 2026-09-29 against synthetic data on a dedicated MariaDB 10.4 instance bound to `127.0.0.1:33317`. Core code is 1.19.0 and the isolated installation used schema 1.13.0.

## Results

| Suite | Result |
|---|---|
| PHP syntax | PASS: 79 plugin files and 40 theme files |
| Offline branch authorization | PASS: 48 checks |
| Integer money | PASS: 12 checks |
| Pricing policy | PASS: 16 checks |
| Isolated WordPress/MariaDB and localhost HTTP | PASS: 541 checks; exit 0 |
| Real theme account journey | PASS: 33 Chromium checks plus three database assertions; combined isolated total 544 |
| Public intake script | PASS: seven Chromium DOM checks |
| Diff whitespace | PASS; line-ending conversion warnings only |

The database runner created a random `adc_verify_<hex>` database, installed isolated WordPress and schema 1.13.0, ran synthetic fixtures, and removed that database. The dedicated server and its verified temporary data directory were then stopped and removed. Source `wp-config.php` was not loaded, port 3306 was not used, and the intentionally empty source business database was not contacted or modified.

## Added 1.19 coverage

- Fee, active promotion, discount, subtotal, VAT and final-total ordering using integer minor units.
- Immutable promotion, fee and seller identity snapshots after policy settings change.
- Frozen sales-manager/general-manager tiers, ceiling enforcement and before/after purchase-cost margins.
- Rejection of margin-based discounts when purchase cost is unavailable.
- Frozen reservation deposit requirements, idempotent evidence, branch scope and recorder/reviewer/reservation-owner separation.
- Sale conversion blocked before deposit verification and allowed after independent verification.
- Pending-evidence cancellation block, paid-reservation hold, bounded idempotent refund, independent verification and inventory release.
- Configured delivery checklist, cross-branch denial, approval block while incomplete, evidence completion and final release.
- Regression updates for qualified payment aggregate SQL and an encoding-independent printable-quote assertion.

## Reproduction

Provision a dedicated empty localhost MariaDB server with its own data directory on a nonproduction port, then run:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/branch-scope.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/money.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/pricing-policy.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
$env:ADC_BROWSER_JOURNEY = '1'
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
Remove-Item Env:\ADC_BROWSER_JOURNEY
& 'C:/Program Files/nodejs/node.exe' wp-content/plugins/auto-dealership-core/tests/browser-public-intake.cjs
```

## Limits

This is local synthetic verification. It does not execute payment, finance, ERP, messaging or document-storage providers, move funds, validate physical evidence, certify tax/accounting policy, run load tests, replace a full WCAG review or approve production deployment. Actual seller identity, pricing ceilings, deposit policy, required documents, branch/reference data and provider reconciliation still require business and deployment review.
