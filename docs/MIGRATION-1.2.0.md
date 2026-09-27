# Core 1.2.0 migration notes

Version 1.2.0 adds `tax_rate_bps` to current quotations and creates `adc_quotation_versions`. The migration is additive and does not rewrite existing quote totals.

## Staging procedure

1. Restore a current production backup into protected staging and record row counts for quotations, discount requests, sales and audit events.
2. Activate or update the plugin and allow the guarded installer to run. A successful result records `adc_db_version = 1.2.0` with an empty `adc_schema_issues` option.
3. Confirm every existing quotation has exactly one captured row matching its current version and amounts. Re-running the installer must not add a duplicate because `(quotation_id, version)` is unique.
4. Confirm legacy quotations have `tax_rate_bps = NULL` unless the historic rate was already stored by a trusted migration source. Do not infer the rate from totals: rounding, exemptions and earlier configuration may make that inference wrong.
5. Create a synthetic quote, request a discount, change the global VAT setting, then approve the request. Its approved totals must use the VAT rate stored on the quote, and versions 1, 2 and 3 must remain readable.
6. Exercise owner, branch manager, finance and cross-branch access to `GET /wp-json/auto-dealership/v1/quotations/{id}/versions`.

## Operational behavior

Legacy quotes remain readable and can still support an unchanged valid sale. A legacy quote without a known VAT snapshot cannot accept a discount that requires recalculation; issue a new quote. Version rows are financial history and should not be updated or deleted by application workflows.

The local isolated suite validates fresh and repeated installation, but it is not a production-copy backup/restore rehearsal. Complete the staging steps and reconciliation before deployment.
