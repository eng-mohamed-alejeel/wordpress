# Backup and Restore

Back up database, `wp-content/uploads`, plugin/theme code, `wp-config.php` and any external object storage configuration. Encrypt off-site copies, restrict access and set retention based on legal/privacy policy. Record WordPress/PHP/database versions and plugin versions with each backup.

Restore procedure: isolate the target; restore database and files from the same recovery point; restore configuration/secrets through the approved secret store; verify database connectivity and file ownership; run WordPress/plugin schema checks; clear caches; verify login, catalog, media, CRM access controls and scheduled jobs; compare critical table counts and audit latest events; document recovery point and data loss window. Perform a restore drill on staging regularly. No restore drill has yet been run for this new plugin.
