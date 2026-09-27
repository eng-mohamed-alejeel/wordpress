# Core 1.14.0 verification — 2026-09-27

## Results

| Check | Result |
|---|---|
| PHP syntax | PASS: 62 plugin PHP files |
| Offline authorization | PASS: 48 checks |
| Integer money | PASS: 12 checks |
| Isolated WordPress/MariaDB, including localhost HTTP | PASS: 365 checks; process exit 0 |
| Cleanup | PASS: generated database removed, independent test server shut down |

Environment: PHP CLI 8.2.12, local WordPress 7.1.2, MariaDB 10.4.32. The suite used a dedicated loopback server on port 33317 and a separate temporary data directory. It did not load source `wp-config.php`, contact the original database, import customer data or send external messages.

## Added coverage

- Upgrade from the pre-specification schema with existing inventory and prices preserved; schema verification after all additive columns are installed.
- All eleven specifications, same-branch permissions, denied sales/foreign-branch access, numeric/text bounds, unknown/private keys, required reason, optional clearing and unchanged retries.
- Audit failure restores specification values; reserved, sold, ready-for-delivery, delivered, transferred and cancelled states lock edits.
- Real HTTP creation/PATCH/catalog using WordPress cookies and nonces: anonymous, invalid nonce, wrong role, inactive foreign branch and malformed payload denial; successful persistence and public/private field separation.
- Real migration command with a test-only WP-CLI output adapter: dry run, exact decimal SAR, overflow rejection, aliases, zero mileage, certified condition, unsupported status, invalid specification and VIN/stock collision handling.
- Import movement/audit rollback, repeat import, matched/drifted reconciliation and serialized malformed metadata rejection.
- Two real PHP/WordPress connections import the same source: exactly one vehicle, movement and import audit; one worker imports and the other skips the existing mapping.
- Sale-owner cancellation denial even with capability; injected delivery read, payment aggregate, receipt update and finance update failures preserve prior state; finance under review closes on successful cancellation.
- Refund request/verification aggregate failures fail closed, completed return/cancellation retries preserve their record, cancellation audit linkage and financial-list capability boundaries.
- Old passed inspections cannot release a newly resolved cancellation hold; a subsequent passed inspection permits availability.

The suite has 98 more checks than the previous 267-check baseline. Counts include explicit assertions that fault injection reached its intended SQL statement. Early development runs were superseded by the final successful 365-check run. Schema error messages during intentional engine/schema corruption are expected; recovery and rejection assertions passed.

## Reproduction

Provision a dedicated empty localhost MariaDB instance on a nonproduction port, using its own data directory. Then run:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/branch-scope.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/money.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
```

Stop the dedicated server after completion. `--isolated` rejects port 3306 and servers containing application databases. Tests create synthetic data only and remove their generated database in the runner's cleanup block.

## Limits and next work

This establishes the covered local service/database/HTTP behavior. It does not establish visual admin/browser rendering, production-copy field reconciliation, performance at production volume, all concurrency schedules, provider execution, physical document authenticity or backup/restore readiness.

The original XAMPP database remains outside this run and requires a protected recovery/restore procedure. Next: production-copy recovery rehearsal and migration reconciliation; remaining independent implementation work is listed in `EXECUTION-STATUS.md`. Overall production readiness remains FAIL.
