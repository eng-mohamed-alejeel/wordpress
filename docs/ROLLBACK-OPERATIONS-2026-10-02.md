# Operational rollback smoke — 2026-10-02

## Isolation and release pairs

- A fresh read-only logical snapshot of the **post-upgrade** local source database was restored into a new MariaDB data directory on `127.0.0.1:33344`. Its SQL SHA-256 was `de77a1dbadc510530f7eb745b5490e3e381aaf7d19cafe73cc0556413e2b56ee`. All 45 source table row counts and checksums matched immediately after restore.
- The current pair used plugin `1.29.13` and theme `car-dealer` `1.29.13` from a copied content root. The prior pair used plugin `1.29.4` and theme `car-dealer` `1.0` from the **same** commit `b2fd4fe`; its archive SHA-256 was `332519a6b1823471787a088e9121a7e91f1075cf3e4e5877418d87c884ab36f0`. Both pairs used the same local WordPress core but separate copied `wp-content` directories. Neither loaded the source `wp-config.php`.
- The inspection harness checked the clone's port and exact temporary MariaDB data directory before WordPress boot. Mail, cron and external HTTP were disabled. It selected an existing administrator in the clone without recording identity fields. It made no request to the source database except read-only fingerprints and the initial logical dump.

## Results

| Check | Current pair | Prior pair |
|---|---:|---:|
| Schema marker and verification | `1.17.0`, no issues | `1.17.0`, no issues |
| Dealership REST route count | 108 | 108 |
| Administrator capabilities | Workspace, inventory, audit and own-lead capabilities present | Same four capabilities present |
| Privacy exporter and eraser hooks | Present | Present |
| Vehicle, customer, lead, sale and reservation rows | All zero | All zero |
| Dealership admin menu slugs | 33 | 30 |
| Shared admin screens rendered | 12 of 12 | 12 of 12 |

The 12 rendered screens were the workspace, settings, audit, CRM, messages, bookings, subscribers, inventory identity, suppliers, quotations, finance and operational reports. Every render produced a heading without a PHP fatal. The current pair additionally registers `adc-editorial-setup`, `adc-inventory` and `car-dealer-settings`; these three screens also rendered after switching back from the prior pair. Their absence from the older pair is expected because the matching older theme also restores its earlier navigation.

A final source fingerprint matched **45/45** original table row counts and checksums. Only `wp_options` changed on the disposable clone as version markers and roles were recalculated between pairs; no clone business table changed. Temporary clone data and copied source configuration were removed after the exercise.

## Boundary

This closes the **read-only operational rollback smoke** for the empty local dataset and the matching prior code pair. It does not prove a downgrade of populated sales, reservations, payments or provider state, and it does not replace a recovery drill on the target deployment. Those transactional and external paths require their own isolated fixtures and provider staging once the necessary business inputs exist.
