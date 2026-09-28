# Customer identity and reviewed consolidation — 1.17.0

Implemented 2026-09-28; schema 1.12.0. Integration/browser/concurrency acceptance is pending. No source database upgrade, sample customer creation or merge was executed during development.

## Account linkage

Authenticated theme intake creates or reuses a customer by the server's current WordPress user ID. A unique nullable `customers.account_user_id` and a lock on the WordPress user row serialize concurrent first requests. The user table must use InnoDB. Name/email are checked against that account; phone originates from the account profile through the theme adapter. Linking, profile refresh, lead creation, compatibility request and audits participate in the intake transaction.

This establishes ownership of a signed-in account, not external verification of the person's legal identity, phone or email. Guest enquiries and generic REST `/leads` input never acquire an existing account/customer by matching contact fields. They remain separate contacts. No historical request, CRM post or email match is automatically linked.

Further authenticated theme submissions refresh the linked contact's name, email and phone. Profile changes without a new enquiry are not synchronized immediately. An explicit positive marketing consent can be recorded; an omitted/false consent does not withdraw existing consent in this identity refresh path. A separate preference-management journey remains to be implemented. Guest consent and the privacy erasure process retain their existing contracts.

With the core identity class loaded, login/profile hooks no longer create or synchronize the theme's duplicate account CRM post. Existing private CRM posts remain unchanged; legacy request import/capture and legacy profile authorization still require retirement/review. Do not interpret this as deletion or migration of historical profiles.

## Administrator review

Open **Dealership Core → مراجعة ملفات العملاء**, or use the review link in a lead's CRM history. Enter a source customer ID; at most 50 exact stored email/mobile matches are suggested. Select a destination to preview both identities and the number of leads to move. Candidate equality is a search aid, not proof of identity.

The preview and merge both enforce:

- Global administrator capability (`manage_options`); branch-manager permissions alone are insufficient.
- Two distinct active customer records, with nonempty matching stored phone numbers and matching email addresses. Older noncanonical phone formats require separate reviewed remediation.
- The source is not linked to an account; an account-linked profile must be the destination. Two account identities cannot be merged. Recorded legacy request owners, where present, must agree with the destination account.
- Both customers have the same set of branch/lead-owner pairs. Source leads must exist, and the combined preview is limited to 500 leads. This prevents consolidation from broadening the customer scope of another employee/branch.
- The source has no reservation, quotation, quote-version or sale references, including closed historical records. Sources already serving as a merge destination are also rejected. Financial/documentary merges and merge-chain rewriting are outside this increment.
- Referenced compatibility records must exist and use InnoDB. Missing storage, lookup errors and changed eligibility reject the operation.

After independently checking identity, the administrator supplies an internal evidence reference (maximum 120 characters; no phone, identity document or other personal data) and explicitly confirms the review. A current preview revision and object-specific nonce are required by the form. REST requires its current revision and `verified: true` as well as administrator authentication.

Execution locks customer IDs in order, rechecks current leads, references and ownership, then compares the preview revision. It moves source leads to the destination, appends activity notes and records `customer.merged`. The source remains as a tombstone with `merged_into_id`; its direct contact fields are cleared. The destination's identity is retained. Marketing consent remains positive only if both records consented. Compatibility `user_id`, lead owner/branch/stage, reservations and financial documents are not reassigned. A guest booking does not become visible in an account merely because the CRM contact was merged.

Every mutation commits with audit or rolls back. There is no automatic unmerge. Operational customer authorization now locks the active customer before new reservation/quotation/sale references are inserted, and rejects merged source IDs. The existing financial workflows therefore serialize against this consolidation boundary.

## Privacy and limits

Export includes the account ID. Export/erasure can locate contacts and account request copies by the current account email's WordPress user ID, including copies carrying an older email. Erasure and retention clear the customer-account link; old contact IDs are not automatically restored by a later authenticated enquiry. Merge tombstones retain minimal references and are excluded from scheduled identity retention. Operational audit IDs remain under the existing audit retention policy.

Verification on 2026-09-28 passed syntax across 70 plugin PHP files and four theme files, 468 database/HTTP checks, 48 authorization checks, 12 money checks and seven real Chromium DOM checks. New identity coverage includes additive upgrade, concurrent first account enquiries, impersonation denial, audit/history rollback, owner conflicts, stale/concurrent merges, quotation-reference rejection, conservative consent, privacy after email changes and source tombstones. Real HTTP tests cover reviewed merge permissions/nonces and admin markup escaping. Changed-profile refresh, mixed merge/new-operational-reference races and full browser preview/confirmation journeys remain open; see `VERIFICATION-1.17.0.md`.

Remaining identity work includes secure historical account claims, merges involving financial records or different contact values, bulk deduplication, an opt-out/preference interface, immediate profile synchronization, legacy profile retirement and release verification. Full CRM completion is not asserted.
