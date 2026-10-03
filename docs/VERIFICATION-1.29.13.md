# 1.29.13 theme/plugin cutover verification — 2026-10-02

## Environment and data boundary

- Plugin: 1.29.13; current theme `car-dealer`: 1.29.13; schema contract: 1.17.0; WordPress: 7.1.2; CLI PHP: 8.2.12; isolated MariaDB: 10.4.32.
- The verification server listened only on `127.0.0.1:33339`. `tests/database-runner.php --isolated 33339` created a fresh `adc_verify_*` database for each run and dropped it afterward. The final server inventory contained only MariaDB system databases and `test`. The source WordPress database was not contacted or seeded.
- Runtime fixtures existed only in disposable test databases. Browser screenshots were written to `docs/artifacts/catalog` and `docs/artifacts/account-journey`.
- The stock-theme cutover used the official [Twenty Twenty-Five 1.5](https://wordpress.org/themes/twentytwentyfive/) archive only inside `tests/isolated-content/themes`; its ZIP SHA-256 was `F333CE53AAA4049639298247D03480A4BD9B56FA8F0FEA0440DA262D7D595BA4`. It was never installed or activated on the source site.

## Results

| Gate | Result | Evidence |
|---|---|---|
| Plugin service/HTTP boundary without theme rendering | PASS for exercised paths | 657 isolated database/HTTP checks, including schema, roles, branch scope, identity, privacy, financial workflow, REST, admin forms, contact/booking AJAX and replay/nonce checks. HTTP router loaded the plugin without rendering the dealership theme. |
| Dealership catalog browser | PASS for fixture paths | 34 Chromium checks: Arabic RTL, English LTR, filtering, canonical/hreflang, mapped public fields, structured data, form submission, keyboard focus, four viewport widths and local assets. |
| Dealership account browser | PASS for fixture paths | 33 Chromium checks: login, account ownership, contact/booking, preferences, profile sync, staff reply, registration isolation, navigation, accessible labels, four viewport widths and local assets. |
| PHP/JavaScript syntax and whitespace | PASS | 157 plugin/theme PHP files and four asset JavaScript files passed syntax checks; `git diff --check` reported no whitespace error. |
| Stock theme and dealership theme on an empty isolated site | PASS for exercised paths | 53 cutover checks with Twenty Twenty-Five 1.5 and `car-dealer`: equivalent post types, taxonomies, plugin-owned shortcodes, REST routes, privacy hooks, admin hooks, cron, capability, one logged-in and anonymous contact/booking handler, zero business rows, empty home/car/offer routes, four structural editorial pages, stylesheets and invalid-nonce rejection. The dealership empty home and archives contained no fabricated cards. |
| Editorial content and deployment rollback | PARTIAL | A copy of the actual source pages and a same-commit prior plugin/theme pair were exercised on disposable staging; details below. The source schema/rewrite upgrade subsequently passed on the local installation (`SOURCE-CUTOVER-2026-10-02.md`). Legal pages and a full operational downgrade remain open. |

The first isolated runs exposed stale assumptions in the test harness after ownership moved to the plugin: late plugin bootstrap omitted account/legacy CRM hooks, and the HTTP router included a theme form file even though the plugin owns AJAX writes. Those harness paths were corrected. `LegacyVehicleMapper` also now treats an absent optional gallery meta value as an empty list. A real theme defect was corrected: the main CSS forced `html` to RTL even when the English catalog declared LTR. The final full isolated run completed successfully.

## Actual-content staging and rollback rehearsal — 2026-10-02

The notes below record the source state **before** the controlled source upgrade later that day. Its result is in `SOURCE-CUTOVER-2026-10-02.md`.

- A read-only snapshot captured all 45 source database tables and 662 `wp-content`, `wp-config.php` and `.htaccess` files. Every copied file hash matched. The logical dump SHA-256 was `c697770428e8ab2217dc577958004078cf7f12660a3e5b35cfe999903c6ca692`; the files ZIP SHA-256 was `dd0105d9a5e186cea396a33b4d99b3ca50d17eb969bc74d52966ed58dccc9d89`. The independent MariaDB restore at `127.0.0.1:33341` matched all 45 source table counts and checksums before runtime boot. Snapshots contained credentials and user hashes, were kept outside the repository for this rehearsal, and were removed after it.
- The currently active pair is plugin `1.29.13` and theme `car-dealer` `1.29.13`, with PHP `8.2.12`, WordPress `7.1.2`, MariaDB `10.4.32` and schema contract `1.17.0`. The prior pair came together from commit `b2fd4fe`: plugin `1.29.4` and theme `car-dealer` `1.0`; its code ZIP SHA-256 was `332519a6b1823471787a088e9121a7e91f1075cf3e4e5877418d87c884ab36f0`. Both were booted from separate copied content roots against the isolated restored database using the same WordPress core, without loading source `wp-config.php`.
- Current and prior pairs each registered `car`, `car_offer`, `cd_crm`, both catalog taxonomies, 18 dealership shortcodes and 108 dealership REST routes. Both rendered `/`, `/about/`, `/contact/`, `/finance/`, `/cars/` and `/offers/` with HTTP 200 after the content-registry activation step, with no PHP critical error or raw dealership shortcode. Published pages remained four; vehicle, offer, lead, customer and sale counts stayed zero. Returning to the current pair after the prior-pair smoke also succeeded. This is a read-only public smoke of the rollback pair, not a full downgrade of operational workflows.
- The actual source has published `about`, `contact`, `finance` and a WordPress example page. The first three render with their manual content. The example page contains one `localhost` link, so it needs editorial review before public launch. The configured privacy-policy page is a draft and `terms` does not exist; both correctly return 404 publicly. No legal text or page was generated. The current theme's contact social shortcode was rendering five `href="#"` links for unconfigured networks; it now omits those links and the entire section when no configured channel exists. The copied contact page then rendered HTTP 200 with zero empty/unsafe hrefs.
- The initial current-pair `/offers/` request returned 404 because the restored source had no `adc_content_registry_version` marker and its stored rewrite rules lacked `offers`. Calling the plugin's content-registry activation on the **clone only** refreshed those rules; `/offers/` then returned 200 on both pairs. The source database was not changed. The source requires an authorized plugin activation or administrator-triggered registry upgrade before this route is accepted on the live installation.
- The source also lacked `adc_db_version`: its legacy `wp_adc_discount_requests.margin_before` and `.margin_after` columns were `NOT NULL` while schema `1.17.0` requires nullable margins. `dbDelta()` did not repair that shape. `Schema::install()` now explicitly repairs only those two existing signed BIGINT columns when needed. On the clone, retrying installation recorded `adc_db_version=1.17.0`; a repeated installation returned an empty schema-issue list. No business rows were created. The source still needs this schema repair during a controlled deployment.
- A final read-only fingerprint of the source matched the original **45/45 table counts and checksums**. In staging, only `wp_options` changed from runtime/version/rewrite markers; business table counts and checksums stayed unchanged. The remaining release gate covers live web-server configuration, published approved legal text, and the source activation/schema upgrade under deployment control.

## Reproduction

Start an empty disposable MariaDB instance on a dedicated port other than 3306, then run:

```powershell
$env:ADC_BROWSER_JOURNEY='1'
$env:ADC_BROWSER='C:\Program Files\Google\Chrome\Application\chrome.exe'
& 'C:\xampp\php\php.exe' 'wp-content/plugins/auto-dealership-core/tests/database-runner.php' --isolated 33339
```

Chromium required local process IPC access on Windows. The browser run was executed outside the restricted process sandbox, while every application request still used the disposable loopback database. Successful completion prints `Completed 657 isolated database checks.` and `Disposable test database removed. Source database was not contacted.`

The separate empty-site cutover uses the official Twenty Twenty-Five 1.5 theme extracted under `wp-content/plugins/auto-dealership-core/tests/isolated-content/themes/twentytwentyfive` and a fresh disposable MariaDB server. Prepare the fixture without installing it on the site:

```powershell
$fixtureRoot = 'wp-content/plugins/auto-dealership-core/tests/isolated-content/themes'
New-Item -ItemType Directory -Path $fixtureRoot -Force | Out-Null
$zip = Join-Path $fixtureRoot 'twentytwentyfive.1.5.zip'
curl.exe --fail --location --output $zip 'https://downloads.wordpress.org/theme/twentytwentyfive.1.5.zip'
if ((Get-FileHash -Algorithm SHA256 -LiteralPath $zip).Hash -ne 'F333CE53AAA4049639298247D03480A4BD9B56FA8F0FEA0440DA262D7D595BA4') { throw 'Unexpected theme archive' }
Expand-Archive -LiteralPath $zip -DestinationPath $fixtureRoot
```

Then run:

```powershell
$env:ADC_THEME_CUTOVER='1'
& 'C:\xampp\php\php.exe' 'wp-content/plugins/auto-dealership-core/tests/database-runner.php' --isolated 33340
```

It completed 53 checks and dropped its `adc_verify_*` database. The four editorial pages were created only in that disposable database. The downloaded ZIP and extracted stock theme are temporary test assets and can be removed after the run. After extending the router for the stock theme, the complete 657-check database/HTTP and 34+33 browser suite was repeated successfully on a new disposable database.

## Release decision

The exercised runtime paths, stock-theme switch, actual published-page smoke and matching-code rollback smoke pass on disposable staging. The local source schema/rewrite upgrade and 12-screen read-only administration rollback smoke also passed (`SOURCE-CUTOVER-2026-10-02.md`, `ROLLBACK-OPERATIONS-2026-10-02.md`). A populated transactional downgrade and separate-process local restore passed later (`POPULATED-ROLLBACK-2026-10-02.md`, `INDEPENDENT-RESTORE-2026-10-02.md`). **The full 1.29-F release gate remains pending** for approved legal content, real inventory, public deployment checks, off-site recovery and human review. Keep compatibility catalog mode and external integrations disabled until their separate gates pass.
