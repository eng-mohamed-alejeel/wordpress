# Theme/plugin cutover acceptance — 1.29

Status: partial release acceptance on 2026-10-02. The isolated database/HTTP, dealership browser and stock-theme switch paths passed. Actual published pages and a same-commit prior plugin/theme pair passed disposable staging public and read-only administration smoke checks. The local source registry/schema upgrade and Apache route smoke also passed. A representative populated transactional rollback rehearsal now passes on a copied database. Approved legal pages, public deployment checks and broader production recovery remain. See `VERIFICATION-1.29.13.md`, `SOURCE-CUTOVER-2026-10-02.md`, `ROLLBACK-OPERATIONS-2026-10-02.md` and `POPULATED-ROLLBACK-2026-10-02.md`.

## Scope and data boundary

- Use a disposable staging copy with the same PHP, WordPress, database, plugin and theme versions as the intended release. The source business database was empty during the 1.29.13 acceptance recorded below; it now contains user-authorized, labeled synthetic development records. Use those records only for local development and copied staging rehearsals. Do not invent provider credentials or treat demo records as approved production data.
- Record the exact plugin/theme release pair, schema version, active theme, PHP and WordPress versions, and a restorable database/files snapshot before switching themes.
- Keep public catalog mode `compatibility` until real branch, vehicle and editorial mappings are available and the separate catalog cutover gate passes. Leave external provider routes disabled.

## Acceptance sequence

| Gate | Action on disposable staging | Evidence required |
|---|---|---|
| Plugin alone | Activate a standard WordPress theme with Auto Dealership Core active. Inspect registered `car`, `car_offer`, `car_brand`, `car_category`, dealership shortcodes, REST routes, staff pages, capabilities, privacy hooks and scheduled jobs. | Registration inventory with no missing plugin-owned capability and no dependency on `car-dealer` functions or files. |
| Theme plus plugin | Activate `car-dealer`. Compare the same inventory and visit the home page, empty car/offer archives, account, contact, about and legal pages that actually exist. | No duplicate registration, PHP fatal, empty archive link or fabricated inventory/offer; missing optional pages and contact fields are omitted. |
| Availability parity | On an isolated fixture database only, compare an unmapped available car, an unmapped sold/reserved car and a mapped operational car across archive totals, cards, details, offers, comparison and public intake. | The same car is eligible or ineligible at every public boundary; pagination totals match visible rows. The former empty-source restriction applied before the user authorized the local demo seed. |
| Access and writes | On isolated fixtures, exercise anonymous contact/booking, signed-in account history, staff CRM, branch scope, inventory and one controlled sale/reservation workflow. | Nonce, replay, rate, consent, ownership and branch rules hold; exactly one plugin handler owns each write. |
| URLs and presentation | Inspect Arabic and `?lang=en` catalog URLs, canonical/hreflang output, previews, keyboard navigation and responsive layouts. | Stable public slugs, correctly escaped links, no missing stylesheet or script, and no theme-owned structured-data duplication. |
| Theme-independent rollback | Restore the matching prior plugin/theme release pair from a staging snapshot and repeat a read-only smoke inspection. | Recorded restored versions, URL/hook inventory and unchanged source data counts. Never include PHP from `docs/archive/car-dealer` on a live site. |

## Release decision

Mark a gate PASS only after attaching dated runtime evidence and the release pair used. A static source review alone leaves it pending. If a gate fails, keep the current release disabled for operational use, repair the owner that failed, and repeat the affected gate on disposable staging. Do not switch the source business database to authoritative catalog mode or activate external providers as part of this cutover.

## 2026-10-02 staging result

| Gate | Result | Remaining action |
|---|---|---|
| Plugin and theme on isolated fixtures | PASS for exercised paths | 657 database/HTTP, 34 catalog browser, 33 account browser and 53 stock-theme switch checks passed. |
| Actual published pages on restored data | PASS for existing `about`, `contact` and `finance` pages | Review the example page's `localhost` link; approve and publish real privacy/terms text when available. |
| Current public URLs | PASS on clone and local Apache | Source rewrite rules were refreshed; `/offers/` and six other checked routes returned HTTP 200. |
| Matching prior code pair | PASS for read-only public/admin smoke | Plugin `1.29.4` and theme `1.0` from commit `b2fd4fe` loaded against the post-upgrade restored copy. Twelve shared administrator screens rendered on both pairs; populated transactional downgrade remains untested. |
| Source schema and local deployment | PASS for exercised paths | Both margin columns are nullable, schema `1.17.0` has no issues, and 14 checked local assets returned HTTP 200. Public deployment remains unverified. |

No vehicle, offer, customer, lead or sale fixture was inserted into the source database. Its 45 table fingerprints matched before and after the rehearsal.

The later controlled source upgrade changed only the `wp_options` checksum; all 45 table row counts and the `wp_posts` checksum remained unchanged. Privacy and terms still require approved text before publication.

## Development data after the empty-site acceptance

On 2026-10-02, after the source upgrade and rollback smoke above, the user authorized a pre-seed SQL backup and realistic synthetic records in the local source database. Twelve cars are mapped one-to-one, 8 are publicly eligible after two simulated sales, and two labeled example offers are eligible. Mapping reports zero unmapped, duplicate or invalid rows; local public routes and a real v2 request pass. See `DEVELOPMENT-DATA-2026-10-02.md` and `VERIFICATION-1.28.0.md`. The earlier 45-table fingerprints describe the pre-seed state only.

The subsequent populated rehearsal passed with a copied 45-table snapshot: the prior pair read the existing synthetic finance/payment/delivery states, wrote a new quote/reservation/sale and rejected a no-funds payment on the clone; the current pair then read the resulting chain. All source fingerprints stayed unchanged. This closes the representative local transactional rollback check, not full production recovery or public deployment acceptance. See `POPULATED-ROLLBACK-2026-10-02.md`.

A further local restore on a separate MariaDB process/data directory matched all 45 table fingerprints and 666 file hashes, passed application and media readback, and retained all target fingerprints across restart. The source's non-option tables were stable; three `wp_options` values changed concurrently. This remains a same-host rehearsal, with public deployment and off-site/separate-host recovery still open. See `INDEPENDENT-RESTORE-2026-10-02.md`.
