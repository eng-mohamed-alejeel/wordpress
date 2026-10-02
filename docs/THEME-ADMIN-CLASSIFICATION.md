# Theme Administration Classification

Updated: 2026-10-01 for increment 1.29.6.

**1.29.13 disposition:** the dormant files and assets listed below were moved to `docs/archive/car-dealer` without loading them. The table remains the historical classification from 1.29.6. The current theme has no operational administration module; the plugin-owned pages remain the active implementation. Runtime and theme-switch acceptance are pending.

## Scope

This record classifies the administration sources found in `car-dealer` before any removal or reuse. The active theme require chain does not load the four modules below. They remain in place for review and rollback history; this increment does not include, copy or execute them.

## Decisions

| Theme source | Runtime state | Decision | Plugin replacement |
|---|---|---|---|
| `inc/admin-dashboard.php` | Dormant; not required by `functions.php` | Retain inert until the rollback window closes. Do not copy its role mutation, direct post/meta writers, counters, user-role form or theme asset loader. | `WorkspacePage`, `OperationsPages`, WordPress Users, `SettingsPage`, `VehiclePostEditor` |
| `inc/admin-workspace.php` | Dormant; required only by dormant `admin-dashboard.php` | Retain inert. Its global admin header and recent-car query are tied to the retired theme menu. | `WorkspacePage` and its screen-only plugin stylesheet |
| `inc/inventory-management.php` | Dormant; not required by `functions.php` | Retain inert. Its post-meta status editor and post-query report describe the editorial projection and must not become an operational inventory writer. | `OperationsPages`, `VehicleService`, `VehiclePostEditor`, `OperationalReportPage` |
| `inc/advanced-features.php` | Dormant; not required by `functions.php` | Retain inert. Its direct metadata writer is superseded. Editorial list columns/filters may be reconsidered later as a read-only plugin enhancement. | `VehiclePostEditor` and the `car` editorial screen registered by `ContentRegistry` |
| `assets/css/admin-dashboard.css` | Dormant with theme dashboard | Keep with the inactive source until cleanup; do not enqueue on plugin pages. | `assets/css/admin-workspace.css` in the plugin |
| `assets/css/admin-workspace.css` | Dormant with theme dashboard | Keep with the inactive source until cleanup; do not enqueue on plugin pages. | `assets/css/admin-workspace.css` in the plugin |
| `assets/js/admin-workspace.js` | Dormant with theme dashboard | Keep with the inactive source until cleanup; no behavior was copied because the new workspace requires no JavaScript. | No replacement required |

## Active administration boundary after 1.29.6

- `adc-workspace` is the plugin-owned role-aware operations home.
- `adc-inventory` is the plugin-owned operational inventory list and create/status screen.
- `adc-editorial-setup` is the plugin-owned explicit page setup screen. It creates selected missing drafts only; existing pages and manual edits are preserved.
- `adc-crm`, the retained engagement slugs, transfers, suppliers, acquisition, approvals, finance, delivery, reports, audit and settings remain plugin-owned screens.
- `car` and `car_offer` remain normal WordPress editorial screens under the plugin menu and use plugin validation for dealership fields.
- The theme account template is a presentation adapter and links staff to these plugin URLs.

## Cleanup gate

Delete dormant PHP or assets only after a stock-theme administration journey and the documented rollback window are accepted. Removal must be a separate reviewed change and must not alter post types, metadata, URLs, capabilities or business records.
