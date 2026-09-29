# Verification — 1.18.0 customer preferences and CRM retirement

Completed 2026-09-29 against synthetic data on a dedicated MariaDB 10.4 instance bound to `127.0.0.1:33317`. Core code is 1.18.0 and schema remains 1.12.0.

## Results

| Check | Result |
|---|---|
| PHP syntax | PASS: all 76 plugin and 40 theme PHP files |
| JavaScript harness syntax and diff whitespace | PASS |
| Offline authorization | PASS: 48 checks; no database used |
| Integer money | PASS: 12 checks |
| Isolated WordPress/MariaDB and localhost HTTP | PASS: 515 checks; exit 0 |
| Real theme account journey | PASS: 33 browser checks plus three post-journey database assertions |
| Public intake DOM harness | PASS: 7 checks; no site or database used |
| Cleanup | PASS: every generated database removed; temporary HTTP/browser processes stopped |

The isolated runner refused port 3306, created a random `adc_verify_<hex>` database on the dedicated server and dropped it after each run. It did not load the source `wp-config.php` or contact the intentionally empty source business database.

## 1.18 coverage

- A new account is opted out by default. Explicit opt-out can be stored before the first enquiry and overrides a later form checkbox.
- Opt-in and opt-out update WordPress user metadata and the linked canonical customer in one transaction. Audit failure restores both stores.
- Cookie-authenticated REST reads and writes require the current session and nonce, validate the explicit boolean field and expose only the signed-in account's customer.
- Later authenticated enquiries cannot restore consent after an explicit account opt-out.
- Display name, email and normalized phone changes refresh an existing canonical customer immediately. Audit failure leaves all three CRM fields unchanged, and no hook creates or claims a customer by contact equality.
- Account linkage retires legacy `cd_crm` posts only by their recorded user ID, clears actionable follow-up fields and leaves one canonical Core customer.
- Retired records deny read/edit to non-administrators. Administrators receive read access while edit/delete capability and legacy form writes remain denied.
- Privacy export includes account preference, legacy CRM identity/contact and free-text activity. Fault injection proves Core rows, account metadata, posts and comments roll back together. Successful erasure anonymizes the legacy post/activity, retires it and removes account preference metadata.
- Real account UI covers opt-in, REST opt-out, immediate profile synchronization before another enquiry, subsequent enquiry reuse, booking cancellation, staff reply, second-account isolation, responsive RTL behavior and accessible controls.

The acceptance run exposed a stale WordPress comment-cache path after direct transactional privacy updates. `PrivacyTools` now invalidates affected CRM activity comment caches after commit; the rollback and successful erasure cases pass afterward.

## Reproduction

With an empty dedicated loopback MariaDB instance on a nonproduction port:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317

$env:ADC_JOURNEY_ONLY = '1'
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
Remove-Item Env:\ADC_JOURNEY_ONLY

& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/branch-scope.php
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/money.php
& 'C:/Program Files/nodejs/node.exe' wp-content/plugins/auto-dealership-core/tests/browser-public-intake.cjs
```

`ADC_BROWSER` and `ADC_NODE` may select alternate Chromium and Node executables. The browser uses a temporary profile and loopback HTTP only.

## Limits

These results cover the local synthetic 1.18 boundary. They do not establish physical-device behavior, a full WCAG audit, load characteristics, provider delivery, production cutover or business acceptance of pricing, deposits and branded documents. Historical customer claims and financial/documentary merges remain excluded and are not required for the intentionally empty deployment unless the business later introduces legacy data.
