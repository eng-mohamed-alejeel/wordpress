# Core 1.15–1.17 verification — 2026-09-28

> Follow-up completed 2026-09-29: the suite now passes 490 database/HTTP checks, 27 real-theme browser journey checks and syntax across 74 plugin plus seven theme PHP files. The account journey, responsive review, targeted mixed races and legacy pagination/cursor cases listed as open below are closed by `ACCOUNT-JOURNEY-VERIFICATION.md`. The tables and limits in this document remain the historical result of the original 2026-09-28 run.

## Results

| Check | Result |
|---|---|
| PHP syntax | PASS: 70 plugin PHP files and four theme compatibility files |
| Offline authorization | PASS: 48 checks |
| Integer money | PASS: 12 checks |
| Isolated WordPress/MariaDB, including real localhost HTTP | PASS: 468 checks; process exit 0 |
| Chromium DOM / public form script | PASS: 7 checks in headless Edge with a temporary profile |
| JavaScript test harness syntax | PASS on Node 22.16.0 |
| Cleanup | PASS: generated database removed; dedicated test server shut down |

Environment: PHP CLI 8.2.12, local WordPress 7.1.2, MariaDB 10.4.32. The database suite used a dedicated loopback server on port 33317 and a fresh temporary data directory. Source `wp-config.php` was not loaded; the source database and its intentionally empty business dataset were not contacted or modified. Fixtures contain synthetic records only. External WordPress HTTP requests and email delivery were disabled.

The previous 365-check baseline passed before the additions. The final suite contains 103 additional assertions, including assertions that SQL fault injection reached its intended statement. Initial development failures were superseded by the successful final run. The merge test initially expected an empty string from `wpdb::get_var()`, which returns null for an empty cell; correcting that assertion required no change to the merge service. No production behavior was changed during this verification increment.

## Added coverage

- Populated pre-intake schema upgrade restores account, merge and replay columns/indexes while preserving existing customer and lead counts.
- Public contact validation, Arabic/Persian/international mobile normalization, consent validation, honeypot, bounded payload, UUID conflict/replay and the eight-attempt rate boundary.
- Exactly one customer, lead, compatibility message, initial activity and audit for an enquiry. Insert failures in each relevant table roll back the complete write. Nontransactional compatibility storage is rejected.
- Authenticated account reuse, rejection of forged identity, preservation of existing consent, and absence of account claims from matching generic REST contact values.
- Scoped request/activity reads, required current revisions, no-op saves, stale update rejection, terminal-state protection and atomic audit failure rollback. Customer reply text stays out of permanent audit JSON.
- Test-drive date and availability checks, required confirmation, account-owned cancellation and safe cancellation retries.
- Administrator-only consolidation preview, evidence confirmation, same-owner scope, conflicting compatibility owners, operational quotation references, stale previews, conservative consent, atomic history/audit failure rollback and source tombstone rejection in customer scope.
- Four pairs of independent PHP/WordPress connections exercise guest replay uniqueness, concurrent first enquiries for one account, competing request updates and competing merges. These establish the tested outcomes, not every possible interleaving.
- Privacy export follows account linkage after an email change. Erasure clears canonical identity, account linkage, compatibility copies and payload fingerprints together; injected compatibility failure restores the original identity.
- Retention anonymizes old completed request copies atomically, restores both copies on failed audit, and preserves active requests and merge tombstones.
- Real HTTP cookies and REST/form nonces exercise the actual theme contact/booking handlers, forged contact replacement, replay, request detail/update/history, admin-post updates, customer cancellation and reviewed consolidation. Anonymous, bad nonce, foreign branch, wrong role and nonowner cases are denied.
- Actual admin renderers include revision/nonce/review controls and escape hostile customer names.
- Headless Edge loads the production public-intake script into an isolated `about:blank` DOM: UUID v4 is available before the submit bubble handler builds FormData; retry and nonce renewal preserve it; content changes and resets rotate it; bookings are independent and unrelated forms remain untouched.

## Reproduction

Provision a dedicated empty localhost MariaDB instance with its own data directory, then run:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/branch-scope.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/money.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
& 'C:/Program Files/nodejs/node.exe' wp-content/plugins/auto-dealership-core/tests/browser-public-intake.cjs
```

The browser harness accepts an optional installed Edge/Chrome executable path and requires Node with built-in `fetch` and `WebSocket`. It uses a separate temporary browser profile and no application page or database. The database runner drops its generated database in cleanup. Stop the dedicated database server after use.

This machine's temporary MariaDB instance initially failed with a native asynchronous I/O assertion. The disposable instance ran successfully with `--innodb-use-native-aio=0`; no source service configuration was changed. Intentional schema/engine corruption tests emit expected diagnostics before restoration.

## Limits and remaining verification

This is local synthetic verification. Full theme/account page journeys, RTL/LTR mobile rendering, accessibility, date-only/time-only rescheduling, mapped vehicle transfers during request updates, legacy reconciliation cursor/count pagination, account profile refresh after contact edits, and mixed merge/new-financial-reference or erasure/intake races still need targeted acceptance. The browser harness tests the production form script in a real DOM; it is not a visual or full-site browser review.

Provider execution, production load, physical identity evidence, business policy approval, monitoring and release/cutover remain outside this run. Historical data migration is not required for the user's intentionally empty new deployment. Overall production readiness remains FAIL; see `EXECUTION-STATUS.md` for remaining delivery work.
