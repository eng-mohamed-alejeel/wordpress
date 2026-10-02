# Local development dataset — 2026-10-02

The user explicitly replaced the earlier empty-business-database preference for **local development** and authorized comprehensive realistic demonstration data in `wp-autobrands`. All new staff/customer identities, contact addresses, stock numbers, VINs, supplier identities, offers and finance providers are synthetic. They are not actual customers, vehicles, contracts, receipts or approved commercial terms. Public editorial titles and offer text identify the records as development examples.

## Before mutation

- Confirmed database `wp-autobrands`, prefix `wp_`, local site `http://localhost/wordpress`, active plugin and schema 1.17.0 with no verification issues.
- All targeted business tables were empty. Existing WordPress pages and two existing attachments were left intact.
- Saved a single-transaction SQL backup at `C:\Users\cv\AppData\Local\Temp\adc-dev-seed-20261002-7df860bf219647488189896a29ffe79e\wp-autobrands-before-seed.sql` (124,539 bytes; SHA-256 `5EC7293A08EB5E9F15C45E93699D8B6844DC79C8138C5AA50828FCA048F3D31F`). This local temporary backup contains private site data and is not durable off-site recovery.
- After verification, saved `wp-autobrands-after-seed.sql` in the same temporary directory (198,815 bytes; SHA-256 `F43F07A3FBC8BEDB7C015CE40D86B7CC0282D8F78B56B9C12288A19B158FEF33`). This also contains private site data and is temporary local recovery only.
- After linking WordPress taxonomies, saved the final `wp-autobrands-final-demo.sql` in that directory (199,653 bytes; SHA-256 `2C762B7D17B91E11009F94DA71DEE2A530A343560026AD3DE5B581D3182E29CB`). Use this copy if the complete demonstration dataset must be restored locally.

## Created by the guarded seed

| Entity | Count | Detail |
|---|---:|---|
| Branches / brands / locations | 3 / 5 / 3 | Riyadh, Jeddah and Dammam; Toyota, Hyundai, Kia, Nissan and Mazda. |
| Suppliers | 3 | Fictional local, import and trade suppliers; no provider contract or credential. |
| Vehicles / mapped car posts | 12 / 12 | Plausible model/year/specification and SAR prices, synthetic VINs/stock IDs, acquisition costs, receiving and inspection records. |
| WordPress brand / category terms | 5 / 3 | Every demonstration car post has both a brand and body-category term for compatibility archives and filters. |
| Public catalog / offers | 8 / 2 | Availability excludes two transactional vehicles plus maintenance and inspection vehicles; offers are visibly labeled examples. |
| Customers / leads / activities | 6 / 6 / 12 | Fictional identities using `example.invalid`; marketing consent is false. |
| Staff accounts | 8 | Sales, manager, finance, purchasing and inventory roles with random undisclosed passwords and branch assignments. |
| Quotes / reservations / sales / finance attempts | 2 / 2 / 2 / 2 | One pending sale with first provider attempt rejected and second submitted; another approved simulated sale. No external provider was contacted. |
| Payment confirmations / deliveries | 1 / 1 | One explicitly labeled `DEMO-NO-FUNDS-002` payment simulation and one delivery in `preparing` state. No money, physical VIN confirmation or handover occurred. |

The seed records use natural keys (`DEMO-*` and `adc_demo_*`) and an option marker. It refuses any other database or nonlocal site, refuses a first run over existing business rows, and skips its own records on rerun. The final repeat preserved all counts, including 175 audit events. It does not change pre-existing editorial pages or enable authoritative catalog mode or external provider routes.

## Commands and verification

```powershell
& 'C:\xampp\php\php.exe' wp-content/plugins/auto-dealership-core/tests/development-seed.php --inspect
& 'C:\xampp\php\php.exe' wp-content/plugins/auto-dealership-core/tests/development-seed.php --apply
```

The source schema reported zero issues; catalog reconciliation reported zero unmapped, duplicate or invalid mappings. No restricted acquisition field appeared in public catalog items. Both example offers passed the public offer read model. Local Apache returned 200 for `/`, `/cars/`, `/offers/` and the v1 vehicles route. A real v2 vehicles request returned 200 with 8 items and the same request ID in the response header and envelope. The delivery has no VIN confirmer, approver or delivery timestamp.

The current dataset supports development and populated release rehearsals. It must be replaced or reviewed record by record before public deployment; the approved privacy/terms pages, real inventory and providers remain separate business inputs.
