# Operational reports

Version 1.24.0 adds a read-only, branch-scoped operational report under **التقارير التشغيلية** in WordPress administration.

## Access and scope

- `adc_view_reports` is granted to sales managers, general managers, auditors and administrators.
- Non-administrator users see aggregates only for their active allowed branches.
- Administrators with `manage_options` retain global operational scope.
- The report service applies the central `BranchScope` predicate to every section and fails closed if any query fails.
- The report never selects or exports customer names, contact details, VINs, stock numbers, document references or other row-level identities.

## Sections

The selected UTC date range is inclusive and limited to 366 days. Leads, reservations, quotations, sales and deliveries are grouped by branch and workflow state for that period. Quotation and sale amounts are summed as integer minor units in SAR.

Inventory is a current snapshot grouped by branch and vehicle state. Operational exceptions are also a current snapshot and include overdue active lead follow-up, expired confirmed reservations, open vehicle issues, pending discount decisions, pending finance requests, pending payment evidence, pending reservation-deposit evidence, pending sale refunds and pending reservation-deposit refunds.

Sales and delivery branch attribution uses the originating quotation branch. This preserves the transaction's historical branch after a vehicle is moved.

## CSV export

The protected POST action requires `adc_view_reports` and a WordPress nonce. A successful export appends `operations.report_exported` to the audit log with only the date range and aggregate row count. If the audit event cannot be stored, no CSV is returned.

The UTF-8 CSV columns are:

`report, branch_id, branch_name, status, count, amount, currency, from, to, generated_at_utc`

Every cell beginning with a spreadsheet formula prefix is neutralized. The export contains the same aggregate rows as the screen and contains no customer or vehicle identity fields.

## Data policy

This increment does not insert reference, customer, inventory or transaction data. The source business database remains intentionally empty until actual business values are supplied. Automated acceptance uses a separate disposable MariaDB instance and removes its generated database after the run.
