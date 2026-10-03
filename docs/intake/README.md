# Approved source-data intake templates

These CSV files contain **headers only**. They are collection worksheets, not direct database import formats or approved records. Copy them to a restricted location outside the repository before entering real data, especially customer details and VINs. Do not commit filled copies to Git.

Record the source document and approver for every branch, vehicle and offer. For customers, record the lawful collection source, purpose and consent evidence; import only through a restricted, audited process after privacy review. Prices must state currency, VAT inclusion and additional charges explicitly. Preserve the synthetic `wp-autobrands` database for development; prepare a separate staging dataset from approved input and reconcile it before catalog authority changes. See `../LAUNCH-DATA-AND-SCOPE-2026-10-03.md`.
