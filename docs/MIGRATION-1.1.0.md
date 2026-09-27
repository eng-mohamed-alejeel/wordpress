# Core 1.1.0 migration notes

The upgrade adds `adc_payment_confirmations`, adds finance receipt capabilities, and validates existing operational tables. It does not copy/delete legacy customers or vehicles, backfill paid statuses, or convert table engines automatically.

1. Take an off-web-root database/files backup and rehearse restoration on the deployment's staging environment. The disposable verification suite is not this backup.
2. Run `database-runner.php --inspect` to capture the non-secret local runtime baseline and `--run` to check the code against a fresh isolated database on the same server. Read `TESTING.md` for privileges and limitations.
3. On a protected production-copy staging site, allow the plugin bootstrap to install schema/roles 1.1.0. Check `adc_db_version` and `adc_schema_issues` through an authorized maintenance tool. Successful installation clears issues and records `1.1.0`.
4. On failure, use the reported `table:column`, `table:default`, `table:index`, `missing_table` or `requires_innodb` issue. Preserve rows and investigate collisions/engine/default drift before changing tables. Automatic retry is delayed five minutes; an explicit `Schema::install()` retries immediately. Do not manually set the version marker to bypass verification.
5. Verify receipt recorder/reviewer branch assignments and the full delivery journey described in `PAYMENTS.md`. Existing undelivered sales need real verified receipt entries; historical finance approvals or deposits are not automatically treated as settlement.

## Rollback boundary

Keep the added payment table and all audit events. Do not downgrade to code that ignores receipt settlement while deliveries remain enabled; that would reopen the old release path. A code rollback requires disabling delivery operations and reconciling receipts created since cutover. No automated production rollback or data reconciliation has been executed in this development increment.
