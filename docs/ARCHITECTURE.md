# Automotive Platform Architecture

## Current repository audit

- WordPress core declares 7.1.2. The initial audit found Meta Box; the repository now also contains Auto Dealership Core. Directory presence does not establish runtime activation.
- `car-dealer` is a custom theme. It registers the public `car` and `car_offer` post types and currently implements vehicle metadata, inventory status, offers, leads/CRM, customer accounts, bookings, messages, dashboard and reporting in `inc/` files.
- Existing customer request tables are created outside the proposed core plugin. Existing vehicle data is stored as posts and post meta. These are compatibility constraints for a staged migration.
- No custom dealership core plugin existed at the original audit time. The current repository contains its 1.0.0 baseline and subsequent unreleased hardening; theme compatibility paths remain active in code.
- The worktree already contained edits to `wp-config.php`, theme PHP/CSS and untracked media/font assets. Those changes are pre-existing and were not edited by this work.

## Target layers

```text
Browser / bilingual public theme / WordPress admin
                    |
       REST controllers and admin controllers
                    |
     authorization + validation + workflows
                    |
       application services (transactions)
                    |
     repositories: WordPress content + SQL
                    |
           MySQL / MariaDB
                    |
      integrations through adapters/queues
```

The theme owns rendering, templates, styles, and public navigation only. The `auto-dealership-core` plugin owns business rules, custom tables, capabilities, APIs and integration boundaries. WordPress posts remain appropriate for editorial pages, news and publicly indexed vehicle listings during migration; high-write operational entities use dedicated tables.

## Module boundaries and dependencies

`Core` supplies bootstrapping, configuration and shared validation; `Security` supplies authorization and privacy controls; `Audit` records sensitive state changes. `Branches` and `Brands` are reference data. `Vehicles` depends on Brands and Branches; `Inventory` depends on Vehicles and Branches. `Customers` precedes `Leads` and `CRM`; `Pricing` precedes `Discounts`, `Quotations` and `Sales`; `Reservations` depends on customers, vehicles and inventory; `Finance` and `Delivery` depend on Sales. `Reports`, `Notifications`, `API` and `Integrations` consume domain services rather than write around them.

Keep controllers thin. Services enforce state transitions, permissions and transaction boundaries. Repositories own persistence. External integrations receive events through retryable, idempotent jobs and never become a prerequisite for local record integrity.

The target layers are not yet fully separated because current services still contain SQL. `Security/BranchScope.php` centralizes plugin branch checks and scoped list predicates; per-operation capabilities remain the responsibility of each service/controller. Core 1.23.0 adds the local durable outbox and worker boundary. Version 1.25.0 adds the provider-neutral registry and transactional producers. Version 1.26.0 adds audited activation, adapter readiness, durable acknowledgements and asynchronous reconciliation; provider-specific transport and signature implementations remain pending approved contracts. See `OUTBOX-OPERATIONS.md`, `INTEGRATION-CONTRACTS.md` and `EXECUTION-STATUS.md`.

Version 1.13.0 adds audited sale cancellation and open-delivery reversal, closes converted reservations and pending receipt evidence, and gates paid cancellation holds on independent refund verification. It preserves original verified receipts and builds on controlled returns, post-return inspection, hold and maintenance workflows, physical locations and multi-branch scope. `Security/CustomerScope`, checked schema installation, deterministic integer pricing, frozen VAT and append-only quote revisions remain enforced. Branch, inventory, migration, transfer, CRM, quote/discount, sale approval, finance, payment, delivery and retention writes use checked audit-atomic transactions; WordPress option/meta changes use verified compensating rollback. Delivery requires full verified settlement. This is a manual staff confirmation workflow; no payment-provider connection is implied. Local baseline inspection and isolated database verification evidence are recorded in `EXECUTION-STATUS.md` and `TESTING.md`.

Version 1.19.0 extends that boundary with frozen fee/promotion/subtotal and seller identity on quotation revisions, frozen discount approval tiers and signed margin evidence, separate reservation-deposit evidence/review/refund records, and configured delivery-document references. Deposit receipts count once in later settlement and refund totals. The admin workspaces and REST services enforce branch scope and separation of duties. These additions passed the dedicated local synthetic 1.19 acceptance; they remain manual external attestations without provider execution.

## Existing implementation and migration

Current feature implementations in the theme are legacy compatibility paths, not verified production-grade modules. Do not register duplicate post types or replace existing data in one deployment. First add plugin-owned services/tables, then migrate in repeatable batches with IDs preserved and reconciliation reports. Switch reads to the plugin only after parity and backup verification. Remove theme business logic only after migration acceptance. `car` and `car_offer` remain registered by the theme until that cutover is explicitly implemented.

The 1.20.0 public catalog boundary keeps editorial posts and media in WordPress while the plugin owns eligibility, operational fields, filtering and sorting. Compatibility mode suppresses mapped unavailable inventory and permits unmapped legacy posts. Authoritative mode inner-joins every public car query to exactly one available operational vehicle in an active branch and a published `car` post; duplicate mappings are withheld. Theme code only adapts the read model into cards, details, controls and structured data. Version 1.21.0 adds the explicit Arabic/English URL and RTL/LTR presentation contract, canonical alternates, localized intake responses and representative query budgets. Version 1.22.0 adds an administrator-only transactional mapping boundary, append-only audit events, bounded reconciliation details with complete totals and a catalog-state fingerprint. The audited mode switch remains on compatibility until real mapping reconciliation and staging review are complete.

## Security boundaries

Every write checks a specific capability server side, validates object scope (including branch and owner), validates state transitions, and uses a nonce for cookie-authenticated admin requests. REST routes declare `permission_callback`. Prepared SQL and allowlists are mandatory. Customer and financial fields are minimized and masked by capability. Audit records are append-only through application interfaces; routine user interfaces expose no edit/delete path. Separation of duties is enforced in services, not merely hidden buttons. Secrets come from deployment configuration and are never stored in source control.

## Initial implementation in this repository

Version 1.0.0 adds role/capability definitions, operational tables, REST routes, staff admin screens and initial inventory/lead/reservation/quotation/sales/finance/delivery services. New theme message and booking events are copied idempotently into plugin CRM; legacy intake has a dry-run-capable WP-CLI migration. A WordPress privacy exporter/eraser covers CRM and linked theme intake. Branch transfers use separate request, destination approval, source dispatch and destination receipt steps. Vehicle rows can be copied from legacy posts using the explicit WP-CLI migration command. The current theme remains active; production data reconciliation, offers/media migration and complete public catalog cutover remain. Review branch assignment, tax configuration, retention and approval policies before operational use.
