# Customer preferences and legacy CRM retirement — 1.18.0

Implemented and locally verified 2026-09-29 without a database-schema change. Schema remains 1.12.0. Acceptance passed 515 isolated database/HTTP checks, 48 authorization checks, 12 money checks, 33 real-theme browser checks plus three post-journey database assertions, seven public-intake DOM checks and syntax across all 76 plugin and 40 theme PHP files. See `VERIFICATION-1.18.0.md`.

## Marketing preference

Authenticated customers can explicitly allow or decline marketing contact from **My Account → Communication preferences**. The choice is separate from registration acceptance of the privacy policy and terms, and does not suppress service messages needed to process enquiries, bookings or account actions.

The preference is stored on the WordPress account so it can exist before the first enquiry. If the account already has a canonical Core customer, the same transaction updates `customers.consent_marketing`, its consent timestamp and the user metadata, then appends a minimized `customer.preference_changed` audit event. Opt-out is explicit and clears the consent timestamp. A later authenticated enquiry respects the saved account choice and cannot silently restore consent from form input.

Authenticated REST clients can read or update the same preference through `GET` or `POST /wp-json/auto-dealership/v1/account/preferences`. Cookie authentication requires the WordPress REST nonce. The endpoint never accepts another user or customer ID.

## Immediate profile synchronization

Changes to the WordPress display name, email or `car_dealer_phone` immediately refresh an existing account-linked Core customer. Login also retries synchronization. Phone values use the same canonical Saudi/international normalization as intake. Synchronization never creates a customer, claims an unlinked contact by email/mobile, follows a merge source or changes consent.

The theme account form validates and normalizes the phone before storage, then requests a final Core synchronization after the WordPress hooks. Core records only changed field names in `customer.profile_refreshed`; the audit payload does not duplicate contact values.

## Legacy CRM retirement and access

When authenticated intake links a Core customer, or a linked profile is synchronized, theme `cd_crm` posts carrying the same server-owned `_crm_user_id` are marked as retired. Email or phone equality alone never retires or links a profile.

Retirement stops due tasks, excludes the post from active CRM counts, searches and CSV exports, and prevents all writes through the legacy editor. Sales and manager accounts cannot read retired posts, including direct object capability checks. Administrators can open a separate retired-record view in read-only mode to inspect preserved history and the Core customer reference.

WordPress privacy export now includes matching legacy CRM profile/contact/activity data. Erasure minimizes the legacy title and contact metadata, removes account/profile task values, erases free-text CRM activities and marks the post as privacy-erased read-only history. Core customer and legacy request erasure retain their existing transactional behavior.

## Operational limits

- Existing legacy profiles are retired only from their recorded WordPress user ID. There is no automatic historical claim based on contact equality.
- Financial/documentary customer merges and merge-chain rewriting remain excluded.
- Marketing delivery providers and notification workers must check the stored preference when those adapters are implemented.
- Local release acceptance for this boundary is complete. Physical-device, full accessibility, load, provider and business acceptance remain part of the wider release plan. No source database change or backfill is part of this increment.
