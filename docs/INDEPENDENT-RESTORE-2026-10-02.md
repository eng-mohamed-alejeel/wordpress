# Populated independent-instance restore — 2026-10-02

## Recovery point and isolation

- Exported a fresh, single-transaction logical snapshot of the user-authorized synthetic `wp-autobrands` development database with `--quick --skip-lock-tables --hex-blob --routines --events --triggers --databases`. SQL size: 200,284 bytes; SHA-256: `c7fb32251e5e3ae81c1b0b530ab52d62d5ed820099492b2669a372188097dac2`.
- Archived `wp-content`, `wp-config.php` and `.htaccess` to a local tar file. The archive was 14,524,416 bytes with SHA-256 `700a0ec543f2d815111871c8a427e69d3f637516ef0054e9d6411f1784d143b0`. All **666** restored files matched their source SHA-256 hashes; the sorted file-manifest digest was `511eee1fa8af57cee9f8c71ab1dcc3daba03fe1048a297cbd20253cd0a4de77d`.
- Initialized a **separate MariaDB 10.4.32 process and data directory** at a random `adc-restore-<32 hex>/mariadb` path on local loopback port `33345`. The source remained on port `3306`. The server reported recovery mode `0` and event scheduler `OFF`. The guarded restore helper checked the exact port and data directory and refused an application database on the target before importing. This is process and data-directory isolation on the same host, not an off-site or separate-host recovery.

## Verification

| Check | Observation |
|---|---|
| Logical import | Completed in 1.164 seconds; this is import time, not a full recovery-time objective. |
| Database reconciliation before WordPress bootstrap | All **45/45** table row counts and extended checksums matched the source snapshot. |
| Restored application | Copied plugin/theme `1.29.13` booted with schema `1.17.0` and no schema issues. The worker used the restored content directory and explicit `127.0.0.1:33345` database host; it never loaded the source `wp-config.php`. Cron, external HTTP and mail were disabled. |
| Business and public reads | 3 branches, 12 vehicles and car posts, 6 customers/leads, 2 quotes/reservations/sales/finance attempts, 1 payment and delivery were readable. Compatibility catalog returned 8 eligible vehicles, both example offers were eligible, and 108 dealership REST routes registered. |
| Transactional state | Original pending sale and simulated verified payment retained their states. Delivery stayed in `preparing` with no VIN confirmer, approval or handover timestamp. |
| Media | Both referenced attachment files resolved within restored `uploads`; none was missing or used an unsafe path. |
| Server restart | Graceful shutdown followed by restart from the restored data directory changed **0/45** table fingerprints; application readback passed again. |
| Source during exercise | The **44 non-option tables** retained their row counts and checksums. `wp_options` kept 157 rows but changed checksum. A read-only comparison with the untouched SQL snapshot identified changes in `cron`, `adc_outbox_health`, and one WordPress theme-pattern transient timeout. The rehearsal issued no mutation to port `3306`; the exact origin of these concurrent option updates was not established. |

The exercise proves local restoration of the populated snapshot into a separate MariaDB instance and recovery of the copied WordPress content, including business reads and media. It does **not** prove an off-site restore, a separate physical host, public HTTP/browser operation, production credentials, external provider callbacks, full recovery-time/data-loss objectives, or approved legal content. Those remain release work.

## Tooling and disposal

`tools/restore-rehearsal.php` performed the guarded import and fingerprints. `tools/independent-restore-inspect.php` performs the read-only application check and refuses port `3306`, an unexpected data directory, recovery mode or enabled event scheduler. The restored site configuration was copied for recovery completeness but not executed. All temporary SQL, file archive, copied configuration and database files contain potentially private data and were removed after the report was recorded. The earlier local development backup remains separately documented in `DEVELOPMENT-DATA-2026-10-02.md`; a durable encrypted off-site backup is still required.
