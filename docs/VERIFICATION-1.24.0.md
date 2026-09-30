# Verification record — 1.24.0

Date: 2026-09-30

## Result

The full isolated database and HTTP suite completed **610 checks** successfully on a dedicated loopback MariaDB instance on port 33317. The runner removed its generated database and reported that the source database was not contacted.

Static verification also passed across **90 plugin PHP files**, **41 theme PHP files** and **8 JavaScript/CommonJS files**, plus **48 branch-authorization**, **12 money** and **16 pricing-policy** checks.

The 13 new checks verify:

- report capability denial for a regular salesperson;
- malformed, reversed and overlong date-range rejection;
- active multi-branch scope for managers and branch-restricted auditor access;
- administrator global scope;
- exact quotation counts and integer amount totals against source SQL;
- current overdue follow-up and pending reservation-refund exceptions;
- UTF-8 aggregate-only CSV output and spreadsheet-formula neutralization;
- mandatory export audit and withholding the file when audit persistence fails;
- exclusion of foreign-branch rows from exports;
- fail-closed behavior when a section query fails;
- protected admin export controls, escaped branch content and absence of customer identity.

The test restores every mutated fixture value in a `finally` block. No random business record is added to the source installation.

## Remaining verification boundary

This proves the local aggregate report service, admin boundary and CSV behavior against synthetic isolated fixtures. It does not prove production-scale query performance, human review of business definitions, real branch data, external provider reconciliation or production deployment. Those checks remain part of staging and release acceptance.
