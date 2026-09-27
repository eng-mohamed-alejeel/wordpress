# Core 1.3.0 migration notes

Version 1.3.0 adds `branch_id` to current quotations and adds originating branch plus minimal customer/vehicle identity fields to quotation revisions. It does not copy phone, email or VIN.

On a protected production-copy staging site:

1. Record quotation and revision counts, then upgrade and confirm `adc_db_version = 1.3.0` with no `adc_schema_issues`.
2. Confirm every current quotation has a nonzero branch and every revision has branch, customer display name, stock number and vehicle description.
3. Treat values added to pre-1.3.0 rows as the currently known identity, not proven historical identity. Reconcile samples against archived documents where available.
4. Move a quoted test vehicle between branches and verify its original owner/branch can still read the revision while destination-branch staff cannot.
5. Open **عروض الأسعار**, select a revision, print it and exercise the browser's Save as PDF action in Arabic RTL and supported browsers.
6. Run a synthetic WordPress privacy export and erasure. Confirm revisions are exported and customer names in their snapshots are anonymized while amounts remain.

No PDF file is generated or retained on the server. Email and WhatsApp delivery remain disabled until approved adapters, consent and retry/audit behavior are implemented.
