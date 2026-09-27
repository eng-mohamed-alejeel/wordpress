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

The target layers are not yet fully separated: current services contain SQL, and the outbox worker is not implemented. `Security/BranchScope.php` now centralizes plugin branch checks and scoped list predicates; per-operation capabilities remain the responsibility of each service/controller. See `EXECUTION-STATUS.md` for verified implementation increments.

Version 1.13.0 adds audited sale cancellation and open-delivery reversal, closes converted reservations and pending receipt evidence, and gates paid cancellation holds on independent refund verification. It preserves original verified receipts and builds on controlled returns, post-return inspection, hold and maintenance workflows, physical locations and multi-branch scope. `Security/CustomerScope`, checked schema installation, deterministic integer pricing, frozen VAT and append-only quote revisions remain enforced. Branch, inventory, migration, transfer, CRM, quote/discount, sale approval, finance, payment, delivery and retention writes use checked audit-atomic transactions; WordPress option/meta changes use verified compensating rollback. Delivery requires full verified settlement. This is a manual staff confirmation workflow; no payment-provider connection is implied. Local baseline inspection and isolated database verification evidence are recorded in `EXECUTION-STATUS.md` and `TESTING.md`.

## Existing implementation and migration

Current feature implementations in the theme are legacy compatibility paths, not verified production-grade modules. Do not register duplicate post types or replace existing data in one deployment. First add plugin-owned services/tables, then migrate in repeatable batches with IDs preserved and reconciliation reports. Switch reads to the plugin only after parity and backup verification. Remove theme business logic only after migration acceptance. `car` and `car_offer` remain registered by the theme until that cutover is explicitly implemented.

## Security boundaries

Every write checks a specific capability server side, validates object scope (including branch and owner), validates state transitions, and uses a nonce for cookie-authenticated admin requests. REST routes declare `permission_callback`. Prepared SQL and allowlists are mandatory. Customer and financial fields are minimized and masked by capability. Audit records are append-only through application interfaces; routine user interfaces expose no edit/delete path. Separation of duties is enforced in services, not merely hidden buttons. Secrets come from deployment configuration and are never stored in source control.

## Initial implementation in this repository

Version 1.0.0 adds role/capability definitions, operational tables, REST routes, staff admin screens and initial inventory/lead/reservation/quotation/sales/finance/delivery services. New theme message and booking events are copied idempotently into plugin CRM; legacy intake has a dry-run-capable WP-CLI migration. A WordPress privacy exporter/eraser covers CRM and linked theme intake. Branch transfers use separate request, destination approval, source dispatch and destination receipt steps. Vehicle rows can be copied from legacy posts using the explicit WP-CLI migration command. The current theme remains active; production data reconciliation, offers/media migration and complete public catalog cutover remain. Review branch assignment, tax configuration, retention and approval policies before operational use.
