# Auto Brands — local content and inventory activation

Executed on 2026-10-03 after the owner explicitly requested the next implementation step. This is activation on `http://localhost/wordpress`, not public deployment or approval of legal pages.

## Applied

- Published updated Arabic/English content to existing about (6), contact (7) and finance (5) pages. Promoted review FAQ (45) and buying guide (46) to their canonical `/faq/` and `/buying-guide/` paths. All five use the default page template and the plugin's English content/title fields.
- A timestamped backup of previous titles, slugs, status, content, relevant metadata, theme settings and site name was saved under ignored `.tmp/autobrands-content-before-*.json` before changes.
- Set the local site name to شركة أوتو براندز and appearance contact fields to 0550928190, autobrands2020@gmail.com and جدة، حي الجوهرة. Other appearance settings and existing social/WhatsApp configuration were preserved; no new WhatsApp activation was inferred.
- Updated home-page headline/intro to the owner-supplied business scope in both languages. Footer now links to FAQ and the buying guide.
- New published content states fixed SAR 20,000 deposit, reservation up to three days, and refund within three days of cancellation through the original payment method. The Makkah location is explicitly under construction.
- Added six synthetic new-vehicle examples from `docs/launch-content/demo-inventory.csv` through audited branch, vehicle, receipt, inspection, status, metadata and catalog-mapping services. New vehicle IDs are 13–18; car post IDs are 59–64. The separate `AB-DEMO-JED` branch is explicitly labelled as a development fixture. No operating Makkah branch was created.
- Synthetic VINs are internal fixture values; titles/descriptions in both languages label the cars as examples and prices as illustrative. No actual vehicle inspection, receipt, sale, funds or handover occurred. Images remain placeholders rather than invented vehicle photographs.
- Repeated seed application preserved IDs and counts. Existing 12 vehicles and transactional histories were retained. The old two sales, two reservations, two quotes, two finance attempts, one payment and one delivery retained their counts. This was a count check, not a full before/after content fingerprint.
- Fixed the vehicle card's missing zero-mileage display. Updated release preflight to count both `DEMO-*` and `AB-DEMO-*` fixtures, so the new dataset cannot escape demo-data detection.

## Verification

- **66/66 local bilingual browser checks passed**, with external browser requests blocked and no contact/booking/registration/transaction form submissions. The catalog GET search is read-only.
- Coverage includes five editorial pages in both languages, owner contact details, deposit/refund copy, construction status, zero-mileage demo cards, footer links, local asset availability, JavaScript errors, and checked responsive views at 1440/768/390/320 pixels.
- Contact English mobile screenshot was inspected visually; copy and contact fields wrap within the viewport. Local screenshots remain under `.tmp/bilingual-acceptance`.
- Initial browser failure was a stale expectation for the previous contact heading. The test now checks the new editorial titles and plugin translation mechanism rather than the retired shortcode section markup. Final assertions also verify the actual owner policy and contacts.
- Changed PHP scripts/templates pass syntax; offline authorization passes **52** checks and pricing policy **16**.
- Final reconciliation: **18 vehicles, 18 mapped, 14 publicly eligible, zero unmapped published posts, zero duplicate or invalid mappings**.
- Preflight remains **8 PASS / 4 FAIL / 9 MANUAL**. Demo detection now correctly reports **18 demo vehicles and 20 car/offer posts**.

## Reproduction and limitations

`tools/activate-autobrands-content.php --inspect|--apply` inspects/applies the local editorial bundle with a fresh pre-change backup. It is an explicit content replacement command: inspect and review current manual edits before applying it again.

`tools/seed-autobrands-inventory.php --inspect|--apply` operates only on the guarded local development database with its existing seed marker. It preserves existing synthetic identity collisions rather than repurposing them. Individual domain operations are transactional/audited; the entire six-vehicle batch is not one transaction and a failed run can be resumed.

`node wp-content/plugins/auto-dealership-core/tests/browser-bilingual-site.cjs` performs the read-only presentation acceptance; Chrome required the previously authorized execution outside the restricted process sandbox.

No fresh full isolated 690-check transactional suite was needed or run for these editorial changes and the zero-mileage display fix. Earlier transactional acceptance remains separate evidence. Legal drafts are unpublished; catalog remains compatibility mode; external providers remain disabled. No public host, HTTPS staging or final release package was deployed. Legal identity/registration, tax/fees, retention and reviewed legal text plus staging configuration remain required before general release.
