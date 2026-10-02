# Populated transactional rollback rehearsal — 2026-10-02

## Scope and isolation

- The local `wp-autobrands` database contained the user-authorized synthetic development dataset. A fresh `mysqldump --single-transaction --quick --skip-lock-tables` snapshot was restored into a randomly named `adc_verify_…` database on the same local MariaDB 10.4.32 server. The source was read only for the dump and table fingerprints. The cloned database name was `adc_verify_85d386294d954085`; its initial 45 table row counts and extended checksums matched the source exactly (SHA-256 aggregate `d30ec4a99c9b8bacbdd0fe1d4b181634e923f10b04b99f6d37e6e7e9935926d2`). The SQL snapshot SHA-256 was `B0D42C0B94CFD1C6BD5BA7A80D502C9365EDB3C6E44D54E6340E0757E0977321`.
- The current release pair was copied from the working tree: plugin/theme `1.29.13`. The matching prior pair came from commit `b2fd4fe`: plugin `1.29.4`, theme `1.0` (tar SHA-256 `1CB370CAE2204B5687D02C3717BFC9C831C710B97C7E5F7B18113702CB39E527`). Each pair ran from its own content directory against the clone; neither loaded the source `wp-config.php`. Cron and external HTTP were disabled and mail was intercepted. The clone used an invalid `.invalid` site URL.
- The worker accepts only `adc_verify_` plus 16 hexadecimal digits, checks the copied pair and schema before operations, and never boots `wp-autobrands`. The read-only fingerprint helper can inspect the source and disposable database; it emits counts/checksums only. No provider route was enabled or contacted.

## Observed result

| Stage | Result |
|---|---|
| Current pair before downgrade | Schema `1.17.0` verified; 3 branches, 12 vehicles, 6 customers/leads, 2 quotes, 2 reservations, 2 sales, 2 finance attempts, 1 payment and 1 delivery read successfully. |
| Prior pair against populated snapshot | Same schema and counts read successfully. Existing pending sale, verified simulated payment and `preparing` delivery retained their states; the delivery had no VIN confirmer, approver or handover timestamp. |
| Prior pair transactional exercise, clone only | Created one quote, reservation and sale for an available synthetic vehicle/customer. Reservation replay returned the same ID. Recorded one 100-halala `DEMO-ROLLBACK-NO-FUNDS` payment, replay returned the same ID, and a separate finance user rejected it. No funds were claimed. |
| Current pair after return | Read the newly created quote/reservation/sale/payment chain and verified quote `approved`, reservation `converted_to_sale`, sale `pending_approval`, payment `rejected`, vehicle `reserved`. Original sale, payment and delivery states remained intact. |
| Source protection | All 45 source table counts and checksums were identical before and after (`d30ec4…5926d2`). Clone-only changes were the expected quote/version, reservation, sale, payment and movement rows, five audit events, one vehicle state and four `wp_options` rows. |

The copied pair exercised one representative write path, idempotent retries and a rejected payment. The rehearsal does **not** prove every legacy workflow, a file-level rollback, recovery on an independent server, a production traffic switchover, a provider callback, real payment settlement, or legal/public deployment readiness. It uses the same MariaDB instance with a separate disposable database; production recovery still needs a separate environment and durable off-site backup.

## Repetition and cleanup

`wp-content/plugins/auto-dealership-core/tests/populated-rollback-worker.php` supports `--inspect` and `--write` with a JSON configuration on standard input (`database`, `host`, `user`, `password`, `content_dir`). `populated-rollback-fingerprint.php` accepts only the local source name or the guarded disposable name. Before rerunning the write mode, restore a fresh copy; the write deliberately consumes an available demo vehicle in the clone. After recording the evidence, the disposable database and sensitive local staging archive/SQL snapshot were removed. Both worker files passed PHP lint and `git diff --check` reported no whitespace errors. The source's earlier final demo backup is documented separately in `DEVELOPMENT-DATA-2026-10-02.md`.
