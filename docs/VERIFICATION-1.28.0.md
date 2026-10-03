# 1.28 business-model acceptance — 2026-10-02

The current plugin/theme pair is 1.29.13 and the verified schema is 1.17.0. This report closes the previously missing focused acceptance of the 1.28 business-model slice. It does not approve production deployment.

## Isolated acceptance

`tests/increment-1.28.php` is included in the full database runner. The runner created a randomly named `adc_verify_*` database on the local MariaDB server, ran the suite, and removed that database afterward. The source `wp-autobrands` tables were not changed by this test run.

Command: `C:\xampp\php\php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --run`

Result: **687 isolated database/HTTP checks passed**, up from the previous 657. The focused 1.28 scenarios cover:

- Supplier identity uniqueness, role visibility, restricted contacts, deactivation and write denial.
- Acquisition cost values in integer halalas, audit reasons, document validation, branch scope, supplier status, private-field exclusion and locked vehicle states.
- Finance consent, optional terms, one open attempt, separation of decision roles, branch scope, rejected-attempt history and second-provider lineage. Two independent PHP processes competing for a fresh sale persisted exactly one open attempt.
- Repeated schema installation preserved supplier and finance-attempt records.
- The expanded role matrix, HMAC-only rate-bounded failed-login audit, and WordPress role-change audit.
- v1 compatibility, v2 success/error envelopes and request IDs, authenticated OpenAPI 3.1 discovery, and anonymous access denial.

The isolated runner loads the plugin after `plugins_loaded`, so it now explicitly boots the response contract, OpenAPI registration and security audit hooks. `rest_do_request()` does not apply WordPress's `rest_post_dispatch` filter; the contract assertions explicitly apply `ResponseContract::format()` after dispatch. A separate real Apache request to `/wp-json/auto-dealership/v2/vehicles` returned HTTP 200, a matching header/body request ID, and 8 development catalog items after both simulated sales.

## Development-source verification

After isolated acceptance, the user explicitly authorized realistic demonstration records in the local `wp-autobrands` database. The guarded, repeatable seed in `tests/development-seed.php` was rerun after each dataset stage without changing any count on the repeated run. Schema verification returned no issues; catalog mapping found zero unmapped, duplicate or invalid mappings; all 12 car posts received brand/category terms; 8 public vehicles exposed no restricted acquisition fields; both demonstration offers were eligible. The local home, cars, offers and v1 vehicle routes returned HTTP 200. See `DEVELOPMENT-DATA-2026-10-02.md` for the exact dataset and pre-seed/final backups.

## Boundary

The records are fictional and clearly labeled. Provider adapters remain disabled, and the public catalog stays in compatibility mode. Business policy decisions, public deployment, human accessibility/security review and durable off-site backup remain open. The populated transactional rollback was subsequently exercised on a copied database; see `POPULATED-ROLLBACK-2026-10-02.md`.
