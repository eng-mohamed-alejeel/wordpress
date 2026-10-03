# Deployment

## Requirements

Use a currently supported PHP version compatible with WordPress and all installed plugins, MySQL/MariaDB with InnoDB and utf8mb4, HTTPS, scheduled task execution and tested database/media backups. The repository reports WordPress 7.1.2; confirm the actual host PHP and database versions before release.

## Release sequence

1. Back up database, uploads and configuration; rehearse restore on staging.
2. Deploy plugin/theme code to staging and activate the core plugin there.
3. Apply versioned migrations; inspect logs and schema; execute regression, security and RTL checks.
4. Run migration in bounded batches with counts and reconciliation if a module is enabled.
5. Release to production during an agreed maintenance window; enable modules only after acceptance.
6. Verify public catalog, staff permissions, scheduled jobs, error logs, backups and monitoring; retain rollback package.

Keep secrets outside source control. For reliable schedules use a server scheduler invoking WordPress cron or WP-CLI. This document describes the planned process; no deployment has been performed.

## Release preflight — 2026-10-03

Run `php tools/release-preflight.php --inspect` on the intended staging release copy after database/files restore and again before production cutover. It is read-only and emits JSON. Exit `2` means an automatic gate failed; exit `3` means automatic gates passed but human evidence remains. A green command alone is not legal, business or production sign-off. The local baseline and every manual evidence item are in `RELEASE-PREFLIGHT-2026-10-03.md`.

Build the release from one reviewed commit containing the matching plugin/theme pair. Run `powershell -NoProfile -ExecutionPolicy Bypass -File tools/build-release-package.ps1` to create a local code-only candidate archive and SHA-256 manifest under `.tmp/release-packages`. The builder rejects dirty plugin/theme paths, mismatched versions, tracked configuration, unclassified files and unexpected archive entries. It excludes plugin tests and theme build tools. Check the manifest and release commit before transfer. Keep the live `wp-config.php`, credentials and salts outside Git and the distributed artifact. The local file remains available to this development site, and commit `66cf4f2` removed it from HEAD; review repository history for any previously exposed live secret. Supply production values through the approved secret store/configuration process. See `RELEASE-PACKAGE-2026-10-03.md` for the local candidate.

After preflight, record the real catalog reconciliation fingerprint, HTTPS and canonical route checks, administrator/customer journeys, Arabic/English mobile journeys, scheduler/mail/outbox health, backup/restore evidence, monitoring/alerts and rollback trigger. Required provider routes need their own signed contracts and sandbox acceptance; leave any unaccepted route disabled. Deploy only after the technical and business owners record their decision.

The local privacy and terms pages are review drafts only. Complete the entity/contact/retention/contract fields in `legal/`, obtain legal and business approval, then publish and re-run the enhanced legal preflight. Gather actual branch/vehicle/offer records using `intake/` worksheets outside Git and prepare a separate staging database; preserve the synthetic development site. The 2026-10-03 first-release scope explicitly defers purchase orders, official stored PDFs, financial-history merges and external adapters (`LAUNCH-DATA-AND-SCOPE-2026-10-03.md`).

## Legacy CRM intake pilot

The plugin can copy historical `car_dealer_messages` and `car_dealer_bookings` records without deleting or changing the source rows. On staging, first run `wp adc migrate-leads --dry-run`; then import with `wp adc migrate-leads --batch-size=100`. Use `--type=message` or `--type=booking` to migrate one table, and `--after-id=<id>` to resume a batch. Compare eligible/imported/invalid counts and spot-check customer, vehicle branch, request context and activity history before considering the old tables for retirement. Newsletter subscribers are deliberately excluded because their marketing consent is a separate purpose. The importer is idempotent through the source request type and ID; it does not archive or delete legacy data.
