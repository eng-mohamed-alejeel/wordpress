# Production Readiness Review

This is an initial repository review, not a production approval. PASS is used only where verified.

Current code is 1.15.0 / schema 1.11.0. Public CRM intake, replay protection, scoped activity history and compatibility retention changes passed syntax checks (64 plugin PHP files, two changed theme files and the new JavaScript file); integration/HTTP/browser verification is pending. Legacy request-update authorization and account/profile consolidation remain open. The earlier results below apply to 1.14.0 only; overall readiness remains **FAIL**. See `CRM-INTAKE.md`.

Update 2026-09-27: 1.14.0 passed syntax checks across 62 PHP files, 48 authorization checks, 12 money checks and 365 database/HTTP checks on a separate temporary MariaDB server using `--isolated 33317`. See `VERIFICATION-1.14.0.md`. After the user's manual repairs, source MariaDB is running normally. A local restore matched 40 table counts/checksums and 576 file hashes; the user confirmed the empty business dataset is intentional, so historical recovery/import is not a launch prerequisite. See `RESTORE-REHEARSAL-2026-09-27.md`. Overall readiness remains **FAIL**.

| Area | Status | Evidence / remaining work |
|---|---|---|
| Repository baseline | WARNING | Local read-only inspection verified PHP CLI 8.2.12, WordPress 7.1.2, MariaDB 10.4.32, active car-dealer theme and core plugin. Web-server PHP and full production deployment configuration remain unverified. |
| Architecture | WARNING | Plugin services, operational tables, REST/admin interfaces and an idempotent new-request bridge exist; historical business logic/data and public catalog cutover remain in theme. |
| Database | WARNING | Canonical column/default/index/InnoDB verification and failed-upgrade guards implemented. Fresh/repeated/additive-upgrade scenarios passed in an isolated database. Current-state local restore passed; historical migration is not applicable to the confirmed new deployment. |
| Security and RBAC | WARNING | Capability checks, branch scope and separation rules exist in initial services, including finance actions; WordPress privacy export/erasure handlers are registered, but comprehensive permission/IDOR and privacy-retention review remains. |
| Audit | WARNING | Tested reservation, quote/sale creation, receipt and delivery operations roll back on audit failure. Other services still require atomicity review; retention, tamper monitoring and complete event coverage remain. |
| Workflows | WARNING | Receipt recording/separate financial verification and full-settlement delivery gates are implemented and tested. Finance approval alone no longer unlocks delivery. Refunds, provider reconciliation, physical/document evidence, delivery checklist and broader exceptions remain. |
| Public website, RTL and accessibility | WARNING | Public API and theme car queries honor operational availability for linked posts; unmigrated legacy posts remain theme-driven. Theme catalog cutover, WCAG, responsive layouts and full bilingual behavior remain unverified. |
| API and integrations | WARNING | Versioned REST routes exist; ERP/payment/finance/WhatsApp adapters and OpenAPI docs remain. |
| Tests | WARNING | On 2026-09-27, 62 PHP files passed lint, 48 authorization checks, 12 money checks and 365 isolated database/HTTP checks passed, including current specifications, additive upgrade, actual/concurrent migration, cancellation/refund SQL failures and earlier workflows. Provider execution, visual browser and broader concurrency coverage remain; historical reconciliation is not applicable to this launch. |
| Backup, monitoring and deployment | WARNING | Current-state local restore passed for 40 tables and 576 files after manual repairs. The empty business dataset is intentional; off-site recovery, complete browser/release rehearsal, monitoring and production deployment remain. |
| Overall | FAIL | Platform scope is incomplete; do not represent this repository state as production-ready. |
