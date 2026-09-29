# Public catalog cutover

## Read modes

The `adc_public_catalog_mode` setting has two allowlisted values:

- `compatibility` is the default. Legacy unmapped `car` posts continue to render. A mapped post is hidden when its operational vehicle is unavailable or its branch is inactive. Mapped card/detail/schema fields use operational values.
- `authoritative` makes operational inventory the catalog authority. A public vehicle must be `available`, belong to an active branch, and have `public_post_id` mapped to a published `car` post. Unmapped posts and private operational rows do not render.

Authoritative mode is effective only while the installed schema version matches `Schema::VERSION`. The setting is saved through the compensated, audited configuration service.

The settings screen reports operational rows, eligible public rows, published car posts, unmapped published posts and duplicate mappings. Authoritative activation is blocked until the schema is current and both unmapped published posts and duplicate mappings are zero. An intentionally empty catalog can still be valid when it has no published vehicle posts.

## Filter contract

The archive exposes shareable GET parameters for search; brand, model and trim; year, price and mileage ranges; body, fuel and transmission; engine and drivetrain; exterior/interior colors; branch; condition; and sorting. Theme prices are entered in SAR and converted to stored halalas. REST price filters use halalas directly. Page size is bounded to 48 and SQL ordering is selected only from a fixed allowlist.

Filtered archive URLs emit `noindex,follow` and a canonical link to the base vehicle archive. The unfiltered catalog remains indexable under the site's normal SEO policy.

## Activation checklist

1. Keep `compatibility` selected while operational inventory is empty or incomplete.
2. Create the real active branches and operational vehicles without sample data.
3. Map every intended public vehicle to one unique published `car` post through `public_post_id`.
4. Reconcile counts for available mapped vehicles, inactive branches, unavailable vehicles, duplicate mappings and unmapped published posts.
5. Review several cards and detail pages for price conversion, model/trim, mileage, colors, drivetrain, branch, stock number, media and structured data.
6. Repeat the accepted Arabic RTL and English LTR catalog journeys at mobile and desktop sizes, including empty results, localized form responses and every sort option.
7. Measure query time and page rendering with representative catalog volume and the production cache configuration.
8. Select **Operational inventory authority** in **Dealership Core → Settings**, then repeat count and public-output checks.

## Rollback

Select **Gradual compatibility with theme data** in **Dealership Core → Settings**. This is an audited option change and does not alter mappings or inventory. Mapped unavailable vehicles remain suppressed for safety.

## Current acceptance state

Implementation and expanded local synthetic acceptance are complete in 1.21.0. The isolated suite passed 565 database/HTTP checks and the real-theme Chromium catalog passed 34 Arabic/English checks at 1440, 768, 390 and 320 pixels. A 240-vehicle scenario passed its 25-query/3-second budget using 10 queries in 0.028-0.056 seconds. The source database remains intentionally empty, so real mapping/count reconciliation, human accessibility review and staging load/cache measurement are still required before authoritative activation. See `VERIFICATION-1.21.0.md`.
