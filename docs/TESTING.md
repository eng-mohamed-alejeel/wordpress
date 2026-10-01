# Verification Plan

## Current evidence and independent server mode

On 2026-10-01, version 1.27.0 passed **654 isolated database/HTTP checks** on an independent disposable MariaDB server. Its 13 focused assertions cover additive rate-table migration, proxy trust/fallback, HMAC-only storage, policy isolation, fail-closed storage, cleanup, exact anonymous-route inventory, minimized operations output, scheduling and a 12-process atomic race. Syntax passes across 105 plugin PHP, 41 theme PHP and 8 JavaScript/CommonJS files; 48 authorization, 12 money and 16 pricing-policy checks also pass. The disposable database and server directory were removed and the intentionally empty source database was not contacted. See `VERIFICATION-1.27.0.md`.

On 2026-09-30, version 1.26.0 passed **640 isolated database/HTTP checks** on the independent MariaDB server. Its 19 new assertions cover additive receipt-ledger migration, safe readiness, activation authorization and audit compensation, runtime tamper rejection, durable structured receipts, asynchronous reconciliation, early acknowledgement linking, duplicate idempotency, contradictory-state visibility and reference minimization. Syntax passes across 101 plugin PHP files; the existing 48 authorization, 12 money, 16 pricing-policy, 41 theme PHP and 8 JavaScript/CommonJS checks remain part of the final static regression. The disposable database was removed and the intentionally empty source database was never contacted. See `VERIFICATION-1.26.0.md`.

On 2026-09-30, version 1.25.0 passed **621 isolated database/HTTP checks** on the independent MariaDB server. Its 11 new assertions cover adapter/event validation, default-disabled routing, missing/duplicate routes, deterministic replay, minimized payloads, worker dispatch, service production and transactional rollback on outbox failure. Syntax passes across 94 plugin PHP, 41 theme PHP and 8 JavaScript/CommonJS files; 48 authorization, 12 money and 16 pricing-policy checks also pass. The disposable database was removed and the intentionally empty source database was never contacted. See `VERIFICATION-1.25.0.md`.

Version 1.24.0 previously passed 610 isolated checks for branch-scoped aggregate reports and safe audited exports. See `VERIFICATION-1.24.0.md`.

Version 1.23.0 previously passed 597 isolated checks. Its 21 assertions cover additive outbox migration, payload minimization/integrity, replay conflicts, delivery/retries/leases, role separation, audited retry and a two-process claim race. See `VERIFICATION-1.23.0.md`.

On 2026-09-29, version 1.22.0 passed 576 isolated database/HTTP checks, 48 offline authorization checks, 12 money checks, 16 pricing-policy checks, 34 catalog Chromium checks, 33 account Chromium checks plus three post-journey assertions, seven standalone DOM checks, and syntax across all 84 plugin plus 41 theme PHP files and three Node scripts. The 14 focused catalog-cutover assertions cover an empty-catalog gate, complete reconciliation totals/fingerprint, authorization, post validation, audited mapping and rollback, invalid-target remediation, duplicate prevention, idempotency, an empty eligible-result gate, escaped and labelled admin controls, and audited unmapping. The disposable database was removed and the intentionally empty source database was not contacted. See `VERIFICATION-1.22.0.md`.

On 2026-09-29, version 1.21.0 passed 565 isolated database/HTTP checks, 48 offline authorization checks, 12 money checks, 16 pricing-policy checks, 34 real-theme catalog Chromium checks, 33 account Chromium checks plus three post-journey database assertions, seven separate public-intake DOM checks, and syntax across all 81 plugin plus 41 theme PHP files and three Node scripts. New coverage includes English LTR archive/detail/forms, localized AJAX success, canonical/hreflang, structural accessibility, four responsive sizes and a 240-vehicle local performance budget. See `VERIFICATION-1.21.0.md`.

On 2026-09-29, version 1.20.0 passed 562 isolated database/HTTP checks, 48 offline authorization checks, 12 money checks, 16 pricing-policy checks, 19 real-theme catalog Chromium checks, 33 account Chromium checks plus three post-journey database assertions, seven separate public-intake DOM checks, and syntax across all 80 plugin plus 41 theme PHP files. The catalog cases cover readiness/cutover, public minimization, every filter, REST validation, native GET submission, SEO output, structured data, Arabic RTL, focus/labels and responsive layouts. See `VERIFICATION-1.20.0.md`.

On 2026-09-29, version 1.19.0 passed 541 isolated database/HTTP checks, 48 offline authorization checks, 12 money checks, 16 pricing-policy checks, 33 real-theme Chromium checks plus three post-journey database assertions, seven separate public-intake DOM checks, and syntax across all 79 plugin plus 40 theme PHP files. The 26 new database assertions cover frozen fee/promotion/seller snapshots, discount tiers and margins, deposit evidence and refund separation, and delivery-document gates. See `VERIFICATION-1.19.0.md`.

On 2026-09-29, version 1.18.0 passed 515 isolated database/HTTP checks, 48 offline authorization checks, 12 money checks, 33 real-theme Chromium checks plus three post-journey database assertions, seven separate public-intake DOM checks, and syntax across all 76 plugin plus 40 theme PHP files. Preference, immediate profile synchronization, legacy CRM retirement/capabilities and legacy privacy rollback/success are covered in `tests/customer-preferences-scenarios.php`, `tests/customer-preferences-http.php` and the extended account journey. See `VERIFICATION-1.18.0.md`.

On 2026-09-29, core 1.17.0 passed 490 database/HTTP checks, 48 offline authorization checks, 12 money checks, 27 real-theme Chromium journey checks, seven separate public-intake DOM checks, and syntax checks for 74 plugin plus seven theme PHP files. CRM additions live in `tests/increment-crm.php`, `tests/increment-crm-http.php`, `tests/account-workflow-scenarios.php`, `tests/browser-public-intake.cjs` and `tests/browser-account-journey.cjs`. See `VERIFICATION-1.17.0.md` and `ACCOUNT-JOURNEY-VERIFICATION.md` for reproduction, failure/concurrency paths and limits.

On 2026-09-27, core 1.14.0 completed 365 database/HTTP checks using `database-runner.php --isolated 33317` on a separate MariaDB data directory. That run never loaded source `wp-config.php`; its disposable database was removed afterward. All 62 PHP files passed syntax checks, plus 48 offline authorization and 12 money checks. See `VERIFICATION-1.14.0.md` for evidence and limits. Historical totals below document earlier increments.

For a separately provisioned disposable localhost MariaDB server:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
```

This mode uses the disposable server's local root account without a password, rejects port 3306 and refuses a server containing application databases. Use only a dedicated loopback test instance and its own data directory. It creates and drops only its generated test database. It does not repair, restart or contact the source MariaDB service. Stop the disposable server after use. Existing `--run` and `--inspect` modes below still use the source configuration and must not be used as a substitute for independent isolation.

Implemented 1.14.0 coverage in `tests/increment-1.14.php`, `tests/migration-concurrency.php` and the HTTP portion of `tests/database-scenarios.php`: specification permissions/branch/state/input/audit rollback, public field minimization, additive upgrades, decimal and overflow migration prices, metadata aliases, malformed/unknown status, field reconciliation, concurrent import and partial-write rollback, refund aggregate SQL failures and completed replay, and post-resolution inspection chronology. Legacy fixtures now supply explicit valid condition/status. `tests/cli-double.php` captures command output while exercising the real migration command; it does not replace database operations.

## Offline branch authorization regression

Run without WordPress bootstrap or a database connection:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/branch-scope.php
```

The suite uses the real branch policy and service methods with a query recorder and small WordPress function doubles. It covers malformed/missing branch assignments, branch-zero intake, owner plus branch restrictions after staff reassignment, denied CRM writes, capability-filtered vehicle fields, scoped vehicle/list queries, workflow queue predicates, and profile assignment nonce/input rejection. It does not prove database execution, REST authentication, browser behavior, or concurrent writes.

Result on 2026-09-26: **48 checks passed** on PHP CLI 8.2.12. These checks complement the real-database suite below.

## Isolated WordPress and MariaDB suite

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --inspect
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --run
```

`--inspect` uses SHORTINIT and reads only PHP/WordPress/database versions plus selected non-secret runtime options. `--run` creates a fresh random `adc_verify_<hex>` database with the existing database account, starts a separate WordPress installation with synthetic users/data, blocks external HTTP/mail, runs scenarios, and drops only the database it created in a `finally` block. Credentials travel through a subprocess stdin pipe and a child-process environment variable for the localhost HTTP server; no credential file or copied customer database is created. The HTTP server binds to `127.0.0.1` on a random port and accepts only the generated disposable database name. The account needs CREATE/DROP DATABASE privileges. Child WordPress uses an isolated content directory to exclude site plugins/themes/drop-ins. This is an integration fixture, not a production staging copy or backup/restore rehearsal.

The suite covers fresh/repeated/additive schema install, missing/truncated indexes, column/default/engine drift, customer/branch/owner restrictions, reservation replay and two-process contention, REST permissions/validation, failed audit rollbacks, receipt verification/SoD/balance controls and the full funded sale-to-delivery path. Fault injection covers branch/vehicle creation, inventory transitions, CRM creation/stage/assignment/activity, all transfer stages, reservations, quotes, sales, receipts and delivery. It also renders the payment admin page and checks branch-filtered output. Expected schema-error messages occur during intentional corruption tests.

Quote pricing also has a dependency-free unit suite:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/money.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/pricing-policy.php
```

These cover strict integer parsing, rounding, formatting, overflow, fees, dated fixed/percentage promotions, frozen snapshots, approval tiers, reservation deposits and seller identity. The database suite additionally verifies frozen VAT after a settings change, append-only quote versions, rollback when history persistence fails, ordered REST history and ownership isolation.

Version 1.3 scenarios also verify frozen customer/vehicle/branch identity, escaped printable HTML, continued originating-branch access after vehicle relocation, denial to the destination branch, privacy export and snapshot-name anonymization. Version 1.3.1 adds inactive-branch deny-all behavior, direct REST IDOR denial, global administrator remediation and compensation after failed settings/metadata audit. The localhost HTTP boundary verifies real WordPress sessions/nonces, validation, public branch and lead intake, scoped CRM, inventory, catalog field minimization, quote/discount/reservation/sale/finance/payment boundaries, complete staged transfers, and a fully funded delivery from remaining-balance recording through independent verification, preparation, VIN confirmation, approval and release. Rejected writes assert unchanged rows/state, followed by successful transitions for eligible actors. Visual RTL layout, browser print dialogs and Save as PDF output still require the staging browser matrix.

Version 1.4.0 checks primary plus secondary branch access, prepared multi-branch SQL predicates, inactive secondary filtering, invalid primary/list rejection, deterministic ordering, single-branch compatibility, and compensation when assignment auditing fails.

Version 1.5.0 adds retention eligibility and rollback checks plus financial export date limits, field minimization, spreadsheet-formula neutralization and mandatory audit persistence. The isolated suite now passes 217 checks.

Version 1.6.0 adds read-only migration inventory checks for eligible and invalid vehicles, linked and orphaned offers, unchanged target rows and invalid fallback branches. The isolated suite now passes 221 checks.

Version 1.7.0 adds verified schema upgrade, audited brand/yard creation, duplicate and inactive-parent rejection, vehicle reference validation and in-use deactivation protection. The isolated suite now passes 225 checks.

Version 1.8.0 adds same-branch and cross-branch location checks, movement history, elevated VIN authorization, workflow-state locking and audit-failure rollback for both operations. The isolated suite now passes 232 checks.

Version 1.9.0 adds receipt and inspection schema checks plus real HTTP denial before receipt, successful receiving, denial before a passed checklist, checklist completion and final availability. The isolated suite now passes 236 checks.

Version 1.10.0 adds automatic maintenance on failed inspection, denial of direct transition while a case is open, documented resolution back to inspection and mandatory successful reinspection. The isolated suite now passes 240 checks.

Version 1.11.0 adds return authorization, cross-branch denial, direct-transition denial, audit rollback, duplicate denial, pending-refund recording and mandatory inspection newer than the return baseline. The isolated suite now passes 249 checks.

Version 1.12.0 adds refund request rollback and idempotency, immutable reference conflicts, self-review and cross-branch denial, independent verification, partial/full obligation states and verified-balance limits. The isolated suite now passes 259 checks.

Version 1.13.0 adds atomic cancellation rollback, active delivery reversal, duplicate denial, paid-sale hold, refund-gated hold resolution, post-cancellation inspection and unpaid cancellation with pending receipt closure. The isolated suite now passes 267 checks.

Current verified results are recorded in `EXECUTION-STATUS.md`. Interactive browser behavior, remaining route-level HTTP cases, all payment race cases, production data migration, physical receipt/document evidence and provider connectivity need separate verification.

## Database and browser verification

For each module, cover unit tests for domain rules, database integration tests, REST permission tests, role/branch scope, state transitions and failure paths. Security cases include duplicate VIN, double reservation/race, unauthorized discount or vehicle release, price tampering, expiry with payment/hold, cross-branch access, IDOR, CSRF, XSS, SQL injection and audit failure.

End-to-end scenario: customer → lead → quote → discount request/review → reservation → finance/payment verification → sale → delivery approval → vehicle release. Test Arabic RTL and English LTR, mobile layouts, accessibility, performance and browser errors. Run on a staging database copy with synthetic records, never production data.

Run the guarded integration smoke test against a disposable/staging WordPress database from PowerShell:

```powershell
$env:ADC_RUN_INTEGRATION_TESTS = '1'
php wp-content/plugins/auto-dealership-core/tests/workflow-smoke.php
Remove-Item Env:\ADC_RUN_INTEGRATION_TESTS
```

It creates temporary roles and workflow records, removes those records/users, and retains audit events as the system of record. Never run it against a production database. Existing theme tests are under `wp-content/themes/car-dealer/tests`. This smoke suite does not replace the full automated security, accessibility, regression or provider-integration tests required for release.
