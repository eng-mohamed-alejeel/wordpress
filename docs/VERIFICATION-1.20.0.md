# Verification 1.20.0

Date: 2026-09-29

## Result

The operational public-catalog increment passed local acceptance against a dedicated MariaDB 10.4 server on `127.0.0.1:33317` with its own temporary data directory. The runner created and removed only a random `adc_verify_<hex>` database. After the run, MariaDB was stopped and the temporary data directory was removed. The intentionally empty source database was never contacted.

## Passing evidence

- **562 isolated database/HTTP checks** passed. Catalog coverage includes readiness and activation gates, duplicate/unmapped mappings, compatibility and authoritative WordPress queries, public field minimization, price-unit normalization, every documented filter, allowlisted sorting, search privacy, REST validation, and real HTTP pagination/filter responses.
- **19 real-theme Chromium catalog checks** passed against the disposable WordPress fixture. They cover operational archive/detail rendering, native GET submission, retained combined filters, empty results, canonical and robots output, structured data, private-field exclusion, Arabic RTL semantics, labels, visible focus, asset/browser errors, and layouts at 1440, 768, 390 and 320 pixels.
- **33 existing account-browser checks plus three post-journey database assertions** passed in the same run.
- **7 standalone Chromium DOM checks**, **48 offline authorization checks**, **12 money checks**, and **16 pricing-policy checks** passed.
- PHP syntax passed for **80 plugin files and 41 theme files**. Node syntax passed for all three browser scripts. `git diff --check` reported no whitespace errors; Git only reported the repository's existing LF-to-CRLF checkout notices.

Catalog screenshots are stored under `docs/artifacts/catalog/`.

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

- Create real branches and vehicles, map each intended public `car` post once, and reconcile production counts before selecting authoritative mode.
- Complete the English LTR journey and a formal WCAG review with screen readers, contrast measurement and physical devices.
- Measure representative catalog/database/cache performance and establish production thresholds.
- Rehearse deployment, monitoring and rollback in staging. External finance, payment, ERP and messaging adapters remain outside this increment.

Compatibility mode therefore remains selected while the operational database is intentionally empty.
