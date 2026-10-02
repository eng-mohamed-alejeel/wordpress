# Source cutover verification — 2026-10-02

## Scope

The local WordPress installation at `http://localhost/wordpress` uses plugin `auto-dealership-core` `1.29.13`, theme `car-dealer` `1.29.13`, PHP `8.2.12`, WordPress `7.1.2` and MariaDB `10.4.32`. This step applied only the already rehearsed schema and content-registry upgrades to the source database. No business or editorial record was created, published or edited.

## Backup and change

- Immediately before the change, a single-transaction logical dump and a ZIP of `wp-content`, `wp-config.php` and `.htaccess` were captured in a local temporary directory outside the repository. The SQL file was 122,433 bytes with SHA-256 `71cfd5fae3b90bc6a310d40b91235bba719a074fcee60d0ca3ae914a52318bab`; the files ZIP was 4,066,200 bytes with SHA-256 `0d9a68b6b7de1b6ff1252b783f8696c60d70f58f087ac0b845125d454b0555d8`. The prior disposable staging restore had already confirmed this 45-table data shape can be restored. The temporary archive contains private configuration and database contents; it is not a durable off-site backup.
- The guarded CLI cutover required the verified dump hash, the expected local database name, table prefix and plugin version. It cleared only the failed-schema retry marker, reran `Schema::install()`, and called the plugin's content-registry activation to refresh WordPress rewrite rules.
- `wp_adc_discount_requests.margin_before` and `.margin_after` changed from signed `BIGINT NOT NULL DEFAULT 0` to nullable signed `BIGINT`. `adc_db_version` is now `1.17.0`, `adc_schema_issues` is absent, and `adc_content_registry_version` is `1.0.0`. Both `cars/?$` and `offers/?$` rewrite rules are present.

## Verification

| Check | Result |
|---|---|
| Database fingerprint | All 45 source tables were present before and after. Only `wp_options` changed its checksum. No table row count changed; the `wp_posts` checksum stayed identical. Vehicle, customer, lead and sale counts remained zero. |
| Local Apache routes | `/`, `/about/`, `/contact/`, `/finance/`, `/cars/`, `/offers/` and `/cars/?lang=en` returned HTTP 200. None displayed a raw dealership shortcode, empty/unsafe href or PHP critical error in the captured HTML. |
| Local assets | All 14 distinct local CSS/JS/image assets referenced by the checked pages returned HTTP 200. |
| Legal pages | `/privacy-policy/` and `/terms/` returned HTTP 404 as expected: privacy remains a draft and no terms page exists. No placeholder legal copy was published. |

The earlier source-specific 404 on `/offers/` is resolved. This is a local Apache smoke, not approval of a public deployment or a full operational rollback. The published WordPress example page still has a `localhost` link requiring editorial review. Approved legal copy, production configuration, off-site backup, provider setup and human accessibility review remain separate release work.
