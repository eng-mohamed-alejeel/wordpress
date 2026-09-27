# CRM intake increment — 1.15.0

Implementation dated 2026-09-27. Integration/browser verification is pending. The intentionally empty business database remains the deployment baseline; no sample customers, branches or historical imports are required by this increment.

## Implemented boundary

- `PublicIntake` validates public REST and theme contact/test-drive requests. `ContactIdentity` normalizes Saudi numbers, Arabic/Persian digits and email and requires explicit marketing consent.
- A public name, phone or email is unverified input. Each new request creates a separate contact; matching values never grant account ownership or cause an automatic merge.
- Logged-in theme requests use the current account's name, email and saved phone. Customers missing a phone update their account profile before submitting. REST contact fields remain enquiry input and do not link accounts by email.
- Customer, lead, initial notes, optional theme request copy and audit commit together. The compatibility table must be InnoDB. Failure rolls back the complete write. Duplicate unique replay keys roll back the losing transaction before looking up the original acknowledgement.
- The existing AJAX action names, nonce and booking response fields remain supported. Customer-account reads continue using the theme tables. New core-owned requests skip the duplicate `cd_crm` request capture.
- Scoped activity history is available from CRM → سجل متابعة الفرصة and `GET /leads/{id}/activities`. Notes include the enquiry type, vehicle post, requested appointment and message. Activity creation updates the lead timestamp and any supplied next action.

## Replay and abuse controls

Forms load a separate plugin script; the theme's `main.js` is unchanged by this increment. The script creates a secure UUID before the theme builds FormData and retains it after failed submissions. Changing the form payload or resetting the form creates a new request key. A page reload loses the in-memory key; browsers without cryptographic random bytes submit without a key. External clients should persist a UUID per intended submission.

The server stores only HMACs of the authentication identity/key and canonical payload. Replays reveal the original lead ID and acknowledgement, never customer details or its current sales stage. A changed payload with the same UUID returns 409. Rotating the WordPress authentication salt changes the key namespace; replay across rotation is not guaranteed. Privacy erasure removes the payload fingerprint, causing old replays to conflict instead of restoring erased data.

Both public entry points share a transient IP counter with an eight-attempt hourly limit and a honeypot. The address comes from `REMOTE_ADDR`, without trusting arbitrary forwarded headers. The transient counter is best effort under concurrency and groups customers behind the same proxy/NAT; production edge rate limiting and proxy configuration remain release work. Validation/rate checks precede replay. This mechanism prevents matching retries that reach persistence; it does not promise acceptance after vehicle availability, appointment time or configuration changes.

## Privacy and compatibility

WordPress erasure clears contact identifiers, activity text/schedule, legacy request content and the public payload fingerprint. The scheduled retention job also anonymizes linked theme copies transactionally, only when they are completed/cancelled and older than the retention cutoff. Active or recently updated copies, SQL failures and non-InnoDB storage prevent that customer from being anonymized. Quote/sale operational retention rules continue to apply.

Request creation is consolidated; the whole CRM cutover is not complete. Theme request replies, cancellations and booking rescheduling still use the existing theme workflow. Its `manage_car_dealer` administration model is not yet replaced by the core branch/owner policy. Account-profile synchronization can still maintain a legacy `cd_crm` profile. Do not treat the new activity history as a complete history of those later legacy changes. Secure contact matching/merge, authenticated account linkage, scoped compatibility reads/writes and audited synchronization of subsequent request updates are the next CRM increment.

## Rollout and reversal

1. Preserve the current database/files backup and apply the additive schema 1.11.0 upgrade through the existing installer. Verify its marker and compatibility-table engines on an isolated/staging copy before operational use.
2. The `adc_core_public_intake_enabled` filter defaults to true. Returning false switches theme submissions back to the pre-existing theme handlers and disables the plugin form script. Public REST intake still uses core validation. This is a theme intake switch, not a schema downgrade or reversal of recorded requests.
3. Keep both core and theme rows during the review window. No table deletion, automatic merge, backfill or source-data import is part of this increment. Before reverting plugin code, assess the additive schema and changed phone/source contracts; do not delete the replay columns to make a version marker match.
4. Required verification remains: fresh/repeated/additive schema install; AJAX anonymous/account identity and nonce; REST validation/rate/consent; sequential and concurrent replay/conflict; audit/compatibility insert failures; branch/owner history access; customer-account reads; browser resubmission/reset; and privacy/retention rollback with active compatibility requests. Earlier 1.14.0 test counts do not establish these results.
