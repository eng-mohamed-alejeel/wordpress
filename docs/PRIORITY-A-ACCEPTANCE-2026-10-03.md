# Priority A — isolated acceptance, 2026-10-03

Follow-up to `PRIORITY-A-STABILIZATION-2026-10-03.md`, executed at the user's request. Baseline commit: `4893af9`, plus the documented offline authorization fixture correction and working documentation. Plugin/theme: 1.29.13; schema: 1.17.0; PHP: 8.2.12; MariaDB: 10.4.32.

## Results

| Verification | Result |
|---|---|
| Isolated database/HTTP suite | **690 checks passed**, exit 0 |
| Real-theme catalog browser | **34 checks passed** |
| Real-theme account browser | **33 checks passed** |
| PHP syntax | **168 files**, zero failures |
| JavaScript/CommonJS syntax | **9 files**, zero failures |
| Offline authorization / money / pricing | Previously executed stabilization result: **52 / 12 / 16 passed** |

The 690 total includes the existing 687 database/HTTP assertions plus three account-journey database assertions. The 34 and 33 browser checks are separate; they are not included in the 690 total. No application defect was found requiring runtime code changes in this step.

## Isolation and reproduction

A new MariaDB data directory was initialized under ignored `.tmp/priority-a-acceptance-68980db0ff43417d94346332018328e7/data`. The server used `--no-defaults`, loopback-only binding and port **33351**, distinct from the source server. No Windows service was installed. The runner's `--isolated` mode does not load source `wp-config.php` and refuses a server containing application databases.

With an empty dedicated server running:

```powershell
$env:ADC_BROWSER_JOURNEY = '1'
$env:ADC_BROWSER = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33351
```

The initial restricted run completed all 687 service/HTTP assertions but failed to start the catalog browser. This was an environment/process restriction, not a passing browser result. After escalation approval, the full suite was repeated outside process isolation while retaining the separate disposable database, and completed successfully with both browser journeys.

The successful log is `.tmp/priority-a-acceptance-68980db0ff43417d94346332018328e7/acceptance-browser.log`; the initial attempt is `acceptance.log`. These ignored local logs are development evidence, not release contents. Intentional SQL/schema fault-injection diagnostics are expected during the suite.

The runner removed its generated `adc_verify_*` database. Final `SHOW DATABASES` on port 33351 contained only `information_schema`, `mysql`, `performance_schema` and `test`. The temporary server then shut down cleanly through `mysqladmin` on that exact port. The source database was not contacted by this acceptance run; no provider, source form or source business write was performed.

## Browser evidence

Updated screenshots are under `docs/artifacts/catalog` (eight Arabic/English viewport screenshots) and `docs/artifacts/account-journey` (four account viewport screenshots). They contain synthetic test fixtures.

Catalog coverage includes eligibility/filtering, public-field minimization, Arabic RTL/English LTR, canonical/hreflang and structured data, localized enquiry submission, structural accessibility, local asset availability and 1440/768/390/320-pixel layouts. Account coverage includes actual login, contact/booking, marketing preferences, profile synchronization, owner cancellation, staff reply, registration isolation, responsive layout and accessible controls.

## Remaining gates

The identified stabilization regression and this isolated acceptance step are complete. A new immutable artifact has not been built: current edits still require review and inclusion in a clean release commit. Stock-theme switching, independent restoration, bilingual public-site presentation and rollback have earlier evidence; they were not rerun in this step.

These local synthetic-fixture results do not approve production. Approved business/legal content, real staging inventory, HTTPS, authoritative catalog cutover, human accessibility/security review, target load/cache behavior, monitored scheduling/mail and off-site recovery remain open. Proceed to phases B and C of `COMPREHENSIVE-REVIEW-AND-PLAN-2026-10-03-AR.md`; keep the current development data intact.
