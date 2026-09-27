# Security Model

- Apply least privilege through explicit `adc_*` capabilities. Add row/branch/owner checks in each service; a capability alone does not grant cross-branch data access.
- For browser writes, verify a WordPress nonce and capability. Nonces mitigate CSRF but are not authorization.
- Sanitize at input boundaries, validate against domain allowlists, escape at output, and use `$wpdb->prepare()` for SQL values.
- Keep financial totals server-calculated. Log sensitive changes and approvals; never place secrets, authentication tokens, card numbers or bank credentials in logs.
- Minimize customer data and approve retention, consent, access, export and erasure procedures before CRM migration.
- The CRM registers WordPress personal-data export/erasure handlers. Erasure anonymizes CRM and legacy enquiry identifiers/text, clears marketing consent and removes matching newsletter subscriptions while retaining limited transaction/workflow records.
- Scheduled retention is disabled by default. An administrator may configure 30–3650 days after policy approval. A daily batch anonymizes at most 100 inactive identities and rechecks eligibility under a transaction; active or recent operational and financial records block anonymization. Every completed anonymization requires an audit event.
- Financial CSV export requires `adc_view_finance`, applies active branch scope, accepts at most 366 days and returns at most 5000 rows. It excludes customer contact data, VIN, payment references and finance-provider data, neutralizes spreadsheet formulas, and is withheld if its audit event cannot be stored.
- Restrict upload MIME/type/size and use WordPress media APIs. Do not accept arbitrary paths or executable uploads.
- Use HTTPS, secure WordPress salts, environment-managed credentials, supported PHP/DB versions and timely updates in deployment.
- Review REST permission callbacks, IDOR/branch scope, CSRF, SQL injection, XSS, rate limits and race conditions for every module.

The audit writer is a foundation, not tamper-proof storage against a database administrator. Production audit integrity requires database access controls, protected backups and monitored retention policies.
