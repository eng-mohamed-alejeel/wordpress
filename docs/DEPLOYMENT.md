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

## Legacy CRM intake pilot

The plugin can copy historical `car_dealer_messages` and `car_dealer_bookings` records without deleting or changing the source rows. On staging, first run `wp adc migrate-leads --dry-run`; then import with `wp adc migrate-leads --batch-size=100`. Use `--type=message` or `--type=booking` to migrate one table, and `--after-id=<id>` to resume a batch. Compare eligible/imported/invalid counts and spot-check customer, vehicle branch, request context and activity history before considering the old tables for retirement. Newsletter subscribers are deliberately excluded because their marketing consent is a separate purpose. The importer is idempotent through the source request type and ID; it does not archive or delete legacy data.
