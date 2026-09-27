# Local backup/restore rehearsal — 2026-09-27

## Current state after the user's manual repairs

The user reported manual database repairs during this task. A fresh read-only inspection found MariaDB 10.4.32 running on localhost port 3306 with `innodb_force_recovery=0` and `read_only=0`. The configured `wp-autobrands` database has 40 readable InnoDB tables, schema version 1.10.0 and role version 1.14.0.

This supersedes earlier reports that the source database was unavailable. The manual repair procedure has not been supplied, so no claim is made about how it was repaired or whether data predating those repairs was preserved. No original data-directory files were replaced, removed, repaired or restored by this rehearsal. The original server was not restarted or stopped.

## Backup and isolated restore evidence

| Item | Result |
|---|---|
| Logical database export | PASS: `mysqldump --single-transaction --quick --skip-lock-tables --hex-blob --routines --events --triggers`; 104,982 bytes |
| Files archive | PASS: `wp-content`, `wp-config.php`, `.htaccess`; 3,767,000 bytes |
| Independent target | Fresh MariaDB data directory, loopback port 33318, event scheduler disabled, recovery mode 0 |
| Logical restore | PASS: mysql client exit 0; measured import time 0.923 seconds (not a full recovery-time objective) |
| Database reconciliation before application bootstrap | PASS: all 40 table row counts and extended checksums match source |
| Files reconciliation | PASS: all 576 extracted files match source SHA-256 hashes |
| Source preservation | PASS: all 40 table counts/checksums remained unchanged across the rehearsal |
| Plugin schema on restored copy | PASS: no column/default/index/engine issues |
| Migration rehearsal | Dry run, import and retry completed for vehicle and lead commands on the isolated copy |
| External side effects | WordPress mail, HTTP requests and cron disabled; live config and theme/plugin autoload excluded |

Checksums:

- `site.sql`: `a15200b0e375615a20e434e2977adc539d0b36958d509510a5149ad1916b5d84`
- `site-files.zip`: `28352b192e876cb1588c409a2e8a1997128e19201270864708dfea1f0dc6af8b`

Persistent local recovery artifacts are stored outside the web root:

`C:\Users\cv\Documents\DealershipBackups\2026-09-27-f105a275`

Contents: `site.sql`, `site-files.zip`, `source-fingerprint.json`, `restored-fingerprint.json`, `source-after.json`, `rehearsal.json`, `file-manifest.json`. The database and configuration archive contain private information; they are not committed to the repository. This is a local backup, not an encrypted off-site backup. The temporary clone was stopped after verification; the original server remains running.

## Confirmed scope: intentionally empty business data

The restored current snapshot has **zero** legacy vehicles, offers, CRM posts, messages and bookings, and no referenced attachment files. Every migration command reported zero eligible/imported/already-imported rows. Repeating the command therefore verifies the empty-source path only. It does not demonstrate migration of the user's historical business records.

The earlier 365-check synthetic suite separately verifies nonempty migration, concurrent import and rollback behavior. It cannot establish completeness of the manually repaired database.

The user subsequently confirmed that the current empty business database is intentional. This is a new deployment; historical-data recovery and legacy reconciliation are not applicable to this launch. The clarification is closed. No fields, branches, vehicles or customers were fabricated, and migration tools remain available for any future import.

## Reproduction tooling

`tools/restore-rehearsal.php` provides three CLI-only modes for this local environment:

- `fingerprint PORT`: read table counts/checksums; no WordPress bootstrap or writes.
- `restore PORT ARCHIVE_DIRECTORY`: import `site.sql` into an empty independent server.
- `rehearse PORT ARCHIVE_DIRECTORY`: verify schema, run dry/import/retry commands and inspect referenced media using restored plugin files.

The writing modes reject port 3306, nonzero recovery mode, enabled database event scheduling, a data directory outside the explicitly identified temporary `adc-restore-<32 hex>/mariadb` directory, and missing restored files. The restore mode refuses servers with application databases. The helper is specific to this installation (`wp-autobrands`, `wp_`, local root account); it is not a general production recovery utility. WordPress core is supplied by the current installation and was not included in the archive.

The initial recovery review consulted [MariaDB recovery-mode documentation](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-troubleshooting/innodb-recovery-modes). No recovery mode was needed after the user's manual work restored normal service.

## Next gate

Current-state local restore: **PASS**. Historical recovery and legacy migration: **not applicable**, per the user's confirmation. New-installation configuration, full browser/login/media review, off-site recovery and production release: **pending**. Production readiness remains **FAIL** because other platform/release work is incomplete.
