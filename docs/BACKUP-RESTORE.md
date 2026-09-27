# Backup and Restore

Back up database, `wp-content/uploads`, plugin/theme code, `wp-config.php` and any external object storage configuration. Encrypt off-site copies, restrict access and set retention based on legal/privacy policy. Record WordPress/PHP/database versions and plugin versions with each backup.

Restore procedure: isolate the target; restore database and files from the same recovery point; restore configuration/secrets through the approved secret store; verify database connectivity and file ownership; run WordPress/plugin schema checks; clear caches; verify login, catalog, media, CRM access controls and scheduled jobs; compare critical table counts and audit latest events; document recovery point and data loss window. Perform a restore drill on staging regularly.

## 2026-09-27 local rehearsal

After the user's manual repairs, MariaDB was verified running normally. A logical copy of all 40 site tables was restored to an independent server; all row counts/checksums matched. All 576 files in the recovered archive matched source SHA-256 values. Artifacts are retained under `C:\Users\cv\Documents\DealershipBackups\2026-09-27-f105a275`.

See `RESTORE-REHEARSAL-2026-09-27.md` for scope, evidence and reproduction. The user confirmed the empty business dataset is intentional; historical recovery/import is not required for this new deployment. This rehearsal does not replace an encrypted off-site backup or a complete production/browser release exercise.
