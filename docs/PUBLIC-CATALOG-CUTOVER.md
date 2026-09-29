# Public catalog cutover

## Read modes

The `adc_public_catalog_mode` setting has two allowlisted values:

- `compatibility` is the default. Legacy unmapped `car` posts continue to render. A mapped post is hidden when its operational vehicle is unavailable or its branch is inactive. Mapped card/detail/schema fields use operational values.
- `authoritative` makes operational inventory the catalog authority. A public vehicle must be `available`, belong to an active branch, and have `public_post_id` mapped to a published `car` post. Unmapped posts and private operational rows do not render.

Authoritative mode is effective only while the installed schema version matches `Schema::VERSION`. The setting is saved through the compensated, audited configuration service.

The settings screen reports operational rows, eligible public rows, published car posts, unmapped published posts, duplicate mappings and invalid targets. Authoritative activation is blocked until the schema is current, the operational source and published catalog are nonempty, at least one eligible public vehicle exists, and unmapped published posts, duplicate mappings and invalid targets are all zero.

**Dealership Core → Catalog cutover** is the setup and reconciliation workspace. It lists active branch, brand and location counts; lets an administrator assign or remove each operational vehicle's `public_post_id`; requires a reason; and records `vehicle.catalog_mapping_changed` in the append-only audit log inside the same transaction. The workspace shows complete issue totals with at most 100 detail rows per category by default and provides a SHA-256 fingerprint for the reviewed catalog state. It excludes VIN, purchase cost, customers and financial records.

## Filter contract

The archive exposes shareable GET parameters for search; brand, model and trim; year, price and mileage ranges; body, fuel and transmission; engine and drivetrain; exterior/interior colors; branch; condition; and sorting. Theme prices are entered in SAR and converted to stored halalas. REST price filters use halalas directly. Page size is bounded to 48 and SQL ordering is selected only from a fixed allowlist.

Filtered archive URLs emit `noindex,follow` and a canonical link to the base vehicle archive. The unfiltered catalog remains indexable under the site's normal SEO policy.

## Activation checklist

1. Keep `compatibility` selected while operational inventory is empty or incomplete.
2. Create the real active branches, brands, locations and operational vehicles without sample data.
3. Create or review the corresponding editorial `car` posts.
4. Open **Dealership Core → Catalog cutover** and map every intended public vehicle to one unique post, recording a useful reason for each assignment.
5. Reconcile active setup counts, eligible rows, unmapped vehicles/posts, invalid targets and duplicate mappings. Record the displayed fingerprint in the release evidence.
6. Review several cards and detail pages for price conversion, model/trim, mileage, colors, drivetrain, branch, stock number, media and structured data.
7. Repeat the accepted Arabic RTL and English LTR catalog journeys at mobile and desktop sizes, including empty results, localized form responses and every sort option.
8. Measure query time and page rendering with representative catalog volume and the production cache configuration.
9. Select **Operational inventory authority** in **Dealership Core → Settings**, then repeat count and public-output checks and confirm the reviewed fingerprint has not changed unexpectedly.

## Rollback

Select **Gradual compatibility with theme data** in **Dealership Core → Settings**. This is an audited option change and does not alter mappings or inventory. Mapped unavailable vehicles remain suppressed for safety.

## Current acceptance state

The mapping workspace and stricter activation gate are complete in 1.22.0. The isolated suite passed 576 database/HTTP checks, including authorization, target validation and remediation, audit rollback, duplicate prevention, idempotency, reconciliation and empty-result blocking. The final real-theme Chromium regression passed 34 Arabic/English catalog checks at 1440, 768, 390 and 320 pixels, and the 240-vehicle scenario stayed within its 25-query/3-second budget using 10 queries in 0.024 seconds. The source database remains intentionally empty, so real setup/mapping, human accessibility review and staging load/cache measurement are still required before authoritative activation. See `VERIFICATION-1.22.0.md`.
