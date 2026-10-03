# Release preflight baseline — 2026-10-03

## Read-only gate

`tools/release-preflight.php --inspect` loads WordPress with `SHORTINIT`, reads the current configuration and database, verifies the plugin's declared schema without installing it, and emits JSON with `pass`, `fail` and `manual` checks. It does not run plugin/theme hooks, change an option, publish a page, activate an integration or send mail/network requests. Exit code `2` means an automatic failure, `3` means only manual gates remain, and `0` requires all gates to be resolved. The output contains counts and configuration state, not customer names, contacts, VINs or secrets.

Local run: PHP 8.2.12, WordPress 7.1.2, MariaDB 10.4.32, plugin/theme 1.29.13, schema 1.17.0. Result after retiring the untouched WordPress example page and committing the configuration exclusion: **8 PASS, 4 FAIL, 9 MANUAL**, exit `2`. An immediate 45-table fingerprint before/after a second read-only run matched **45/45** source row counts and checksums. The page status change was a separate, guarded operation; its content remains saved as a draft.

## Automatic findings

| Check | Result | Evidence / action |
|---|---|---|
| Release pair and schema | PASS | Correct active plugin/theme; schema marker 1.17.0 and code-owned table/column/index/InnoDB verification found zero issues. |
| Public origin | FAIL | Both site URLs use `http://localhost`; configure the intended staging/production HTTPS origin in that environment. |
| Legal pages | FAIL | Privacy page 3 and terms page 41 now contain guarded Arabic/English review drafts. Both remain unpublished with completion markers; obtain the actual entity/policy details, approved text and effective dates before publication. The gate now rejects published WordPress sample text or draft markers. |
| Editorial placeholders | PASS | The unchanged WordPress example page (ID 2) contained the only published `localhost` link. It was moved to draft with its content preserved; the published-page scan now finds zero placeholders. |
| Development dataset | FAIL | Seed marker present; 12 demo vehicles, two simulated sales linked to them, 6 demo customers, 3 demo suppliers, 14 demo car/offer posts and 8 demo staff accounts. These records remain only in development and cannot establish approved inventory or customers. |
| Catalog reconciliation | PASS for synthetic data | All 12 vehicles are mapped; zero unmapped published posts, duplicate mappings or invalid mappings; 8 eligible rows. Repeat with approved real records and capture a new fingerprint. |
| Catalog authority | FAIL | `compatibility` remains selected. Activate `authoritative` only after the real-data review and staging acceptance. |
| Provider safety | PASS | Zero external events are enabled. Provider-specific acceptance remains an explicit release decision. |
| Referenced media | PASS | Both referenced files exist under `uploads`; none is missing or uses an unsafe path. |
| Release configuration | PASS for current HEAD | Commit `66cf4f2` excludes `wp-config.php` from both HEAD and index; `.gitignore` excludes it and the local file remains in place. Repository history still contains old configuration. Review and rotate any credentials or salts that were actually used. |

## Manual gates and evidence owners

| Gate | Evidence needed |
|---|---|
| Legal approval | Business/legal sign-off for privacy, terms, consent wording and effective dates. |
| Business data | Approved branches, vehicles, prices, offers, contacts and demo-data disposition. |
| Provider scope | Named required providers or a decision to keep each route disabled; contracts, credential handling and sandbox reconciliation for any enabled route. |
| Historical secrets | Review of previously committed configuration and rotation of any live credentials/salts it contained. |
| Scheduler and operations | Working system scheduler, mail, queue processing, alert thresholds and responsible operator. |
| Security | Actual proxy CIDRs, edge/WAF policies, security headers, dependency/upload review and manual penetration review. |
| Accessibility and performance | Human Arabic/English accessibility checks and load/cache measurements on the target topology. |
| Recovery | Encrypted off-site backup, separate-host restore, and agreed recovery-time/data-loss evidence. |
| Deployment approval | Immutable release artifact, maintenance window, rollback trigger, operators and technical/business sign-off. |

## Execution order

1. Prepare a separate staging host with HTTPS, secrets outside the repository, scheduler, mail and monitored backups. Re-run the preflight there before exposing public traffic.
2. Obtain approved legal/editorial and real business data. Preserve the current development database as a test dataset; use a reviewed staging import or new approved records for the release candidate.
3. Reconcile the real catalog, exercise Arabic/English public and staff journeys, and activate operational authority only after its separate gate passes.
4. Complete manual security, accessibility, load and separate-host recovery evidence. Keep uncontracted integrations disabled.
5. Review the release commit, run the preflight and final smoke tests again, record sign-off, then follow `DEPLOYMENT.md` for the controlled cutover and rollback window.

The local preflight is deliberately red. It is a preparation tool and evidence inventory; no public deployment was performed. A code-only plugin/theme candidate package is recorded in `RELEASE-PACKAGE-2026-10-03.md`. The legal drafts, first-release deferrals and source-data requirements are in `legal/REVIEW.md` and `LAUNCH-DATA-AND-SCOPE-2026-10-03.md`.
