# Verification 1.21.0

Date: 2026-09-29

## Result

The bilingual catalog, accessibility automation and representative local performance increment passed against a dedicated MariaDB 10.4 server on `127.0.0.1:33317` with its own temporary data directory. The runner created and removed only random `adc_verify_<hex>` databases. The intentionally empty source database was never contacted.

## Passing evidence

- **565 isolated database/HTTP checks** passed. The added performance fixture creates 240 published mapped vehicles only in the disposable database, verifies a bounded 48-row page, an exact 240-row total, distinct second-page records and populated filter options, then deletes the fixture.
- The cold catalog page, total and filter-option path stayed within its **25-query and 3-second** local budgets. Accepted runs used **10 queries in 0.028-0.056 seconds**; the recorded sources were eight operational vehicle queries, one posts query and one postmeta query.
- **34 real-theme Chromium catalog checks** passed. They cover Arabic RTL and English LTR archive/detail rendering, language switching, localized filters/prices/specifications/forms/AJAX success, language-preserving links, canonical/hreflang output, structured data, private-field exclusion, asset/JavaScript errors, visible focus, and layouts at 1440, 768, 390 and 320 pixels.
- The English detail page passed automated structural checks for one main landmark and H1, named actions, labelled controls, unique IDs and image alternative text.
- **33 account-browser checks plus three post-journey database assertions**, **7 standalone Chromium DOM checks**, **48 offline authorization checks**, **12 money checks**, and **16 pricing-policy checks** passed.
- PHP syntax passed for **81 plugin files and 41 theme files**. Node syntax passed for all three browser scripts.

Arabic and English screenshots are stored under `docs/artifacts/catalog/`. Account screenshots are stored under `docs/artifacts/account-journey/`.

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

- Create actual branches and vehicles, map every intended public `car` post exactly once, and reconcile eligible counts and samples before selecting authoritative mode.
- Complete human screen-reader, contrast, zoom and physical-device review. The automated checks cover structure and browser behavior, not full WCAG conformance.
- Measure the catalog with real staging data, production-like cache configuration and concurrent traffic. The 240-record result is a local regression budget rather than a capacity claim.
- Rehearse deployment, monitoring and rollback. External finance, payment, ERP and messaging adapters remain outside this increment.

Compatibility mode remains selected while the operational database is intentionally empty.
