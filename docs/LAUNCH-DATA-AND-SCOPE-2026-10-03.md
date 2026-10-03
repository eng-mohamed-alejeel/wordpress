# Launch data and scope — 2026-10-03

## Data boundary

The current `wp-autobrands` database is a development fixture. It contains 12 synthetic vehicles and two simulated sales linked to those vehicles, plus synthetic customers, staff, suppliers and offers. Do not rename or overwrite these transactional rows to make them appear real: their audit, quotation, reservation, finance, payment and delivery history would remain synthetic. Preserve this database for development and populated recovery rehearsal. Build the release candidate's **separate staging database** from approved source records, then reconcile it before public activation. No real customer list, VINs, prices or offers were supplied in this session, so none was invented or imported.

| Approved source | Minimum review material before entry |
|---|---|
| Business and branches | Legal seller identity, branch names/codes/locations, authorized managers, public contacts and effective dates. |
| Vehicles | Verified VIN and stock identity, branch, make/model/year/trim, condition and status, published SAR price including tax/fee treatment, actual images and ownership/inspection evidence. |
| Customers and leads | Only lawfully collected records with source, purpose, contact verification, consent and retention basis. Transfer through a restricted, audited process; do not put personal data in Git. |
| Offers | Approved eligibility, vehicle scope, price/discount, start/end dates, financing claims, exclusions, owner and signed source document. |
| Media and editorial | Rights to use each image, Arabic/English copy, alt text, approved business claims and final links. |

After approved data is entered on staging, use **Dealership Core → Catalog cutover** to map one public post per vehicle and record the reconciliation fingerprint. Review branch/brand/location counts, availability, published post mappings, duplicate/invalid targets, price conversion, images and Arabic/English mobile pages. Activate `authoritative` through the audited setting only after that review; retain `compatibility` until then. The current synthetic mapping (12 mapped, 8 eligible, zero mapping issues) is development evidence only. See `PUBLIC-CATALOG-CUTOVER.md`.

Header-only collection worksheets are in `intake/`. Filled copies, especially customer and VIN files, belong in a restricted location outside Git. They are source documents for a reviewed import/mapping process, not files to load directly into production tables.

## First-release scope decision

The owner instructed on 2026-10-03 that the following remain outside first-release activation until policy and provider evidence exist:

| Capability | First-release handling |
|---|---|
| Purchase orders and approval chain | No purchase-order workflow is available or represented as complete. Approvers, limits, cancellation and receiving rules require business approval before implementation. |
| Automatic total acquisition cost | Keep the existing restricted, explicitly entered cost fields. No formula is asserted until charge/tax/transport/customs treatment and effective-date policy are approved. |
| Stored official PDFs and invoice snapshots | Existing print views and references are not official stored invoices. Issue contractual/fiscal documents through the approved external process until a template, numbering, storage and signature policy is agreed. |
| Historical identity merges touching finance | Do not merge financial/documentary histories automatically; review each case through a separate approved procedure. |
| External finance, payment, ERP and messaging | No adapter or credential is supplied. Keep all eight outbound event routes disabled; finance inquiries remain internal and the calculator is non-binding. |
| Retention | The local automated identity-retention setting is `0` (disabled). Approve category-specific retention and legal holds before configuring any automated period. |
| Specialist reports and job-title changes | Use the current capability matrix and aggregate reports. Approve organization-specific titles, scopes and specialist reports before adding them. |

This scope decision does not approve legal text, prices, data import or public launch. The launch preflight remains red until the separate staging database, approved pages, real catalog and human acceptance evidence exist.
