# Verification 1.22.0

Date: 2026-09-29

## Result

The audited catalog mapping and cutover workspace passed against a dedicated MariaDB 10.4 server on `127.0.0.1:33317` with its own temporary data directory. The runner created and removed only random `adc_verify_<hex>` databases. The intentionally empty source database was never contacted.

## Passing evidence

- **576 isolated database/HTTP checks** passed before the opt-in browser journey. The 14 focused 1.22 assertions cover empty-catalog rejection, discrepancy detection and complete totals, the catalog-state fingerprint, administrator authorization, post-type validation, atomic audit success/failure, invalid-target remediation, duplicate prevention, idempotency, successful reconciliation, empty eligible-result rejection, escaped and labelled administration controls, and audited unmapping.
- The opt-in run added **34 real-theme catalog browser checks**, **33 account-browser checks**, and **three post-journey database assertions**. The complete runner therefore printed 579 checks after the browser journey.
- Arabic RTL and English LTR catalog behavior still passes at 1440, 768, 390 and 320 pixels. The synthetic English catalog loaded in 800 ms with 17 local resources during the final accepted run. The representative 240-vehicle database scenario stayed at 10 queries and 0.024 seconds against its 25-query and 3-second local budgets.
- **7 standalone Chromium DOM checks**, **48 offline authorization checks**, **12 money checks**, and **16 pricing-policy checks** passed.
- PHP syntax passed for **84 plugin files and 41 theme files**. Node syntax passed for all three browser scripts.
- `git diff --check` reported no whitespace errors; its only messages were expected LF-to-CRLF working-copy notices.

Browser screenshots remain under `docs/artifacts/catalog/` and `docs/artifacts/account-journey/`.

## Reproduction

Provision a dedicated empty MariaDB instance on a nonproduction port with its own data directory, then run:

```powershell
$env:ADC_BROWSER_JOURNEY = '1'
$env:ADC_NODE = 'C:\Program Files\nodejs\node.exe'
$env:ADC_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
Remove-Item Env:\ADC_BROWSER_JOURNEY
Remove-Item Env:\ADC_NODE
Remove-Item Env:\ADC_BROWSER
```

The runner rejects port 3306 and refuses an isolated server that already contains application databases.

## Remaining release evidence

- Create actual branches, brands, locations, staff assignments, vehicles and editorial posts using approved business values. Use **Dealership Core → Catalog cutover** to map and reconcile them, record the fingerprint, then review and enable authoritative mode.
- Complete human screen-reader, contrast, zoom and physical-device review. Automated structure and browser behavior do not establish full WCAG conformance.
- Measure the catalog with real staging data, production-like cache configuration and concurrent traffic. The synthetic result is a local regression budget rather than a capacity claim.
- Rehearse deployment, monitoring and rollback. External finance, payment, ERP and messaging adapters remain outside this increment.

Compatibility mode remains selected while the operational database is intentionally empty.
