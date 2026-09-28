# CRM intake and request workflow — 1.15.0–1.17.0

Intake implemented 2026-09-27; linked request workflow and bounded account identity/consolidation implemented 2026-09-28. Integration/browser verification is pending. The intentionally empty business database remains the deployment baseline; no sample customers, branches or historical imports are required by these increments. Account linkage and merge eligibility are documented in `CUSTOMER-IDENTITY.md`.

## Implemented boundary

- `PublicIntake` validates public REST and theme contact/test-drive requests. `ContactIdentity` normalizes Saudi numbers, Arabic/Persian digits and email and requires explicit marketing consent.
- A public name, phone or email is unverified input. Guest and generic REST enquiries create separate contacts; matching values never grant account ownership or cause an automatic merge. Since 1.17.0, authenticated theme intake reuses a contact by the server-owned account ID.
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

In 1.16.0, linked request replies, cancellation and rescheduling use `RequestWorkflow` from both the theme adapter and core REST/admin forms. These updates append core activity notes and audit records transactionally. Earlier legacy updates are not backfilled. In 1.17.0, automatic duplicate theme account-profile creation/synchronization stops while the core identity class is present. Existing legacy profiles remain. Authenticated intake linkage and administrator-reviewed CRM-only merging are implemented; historical claims, broader profile retirement and financial merges remain outside this increment.

## Linked request workflow (1.16.0)

Open CRM → سجل متابعة الفرصة and select a lead. If it has a compatibility message/booking, the embedded form shows its status, reply and appointment. View-only users cannot submit the edit form. Sales staff manage owned leads in active assigned branches; branch managers manage leads in their branch scope. Global administrators can triage branch-zero records. The same form is embedded in the existing theme request table when its menu is accessible.

Staff writes require a current opaque revision covering the request row and latest core activity ID. The service locks the customer, lead and compatibility request, then checks the revision against current activity state. Concurrent edits or stale tabs return 409 rather than overwriting newer content. Exact no-op submissions do not add activity/audit rows. Customer cancellation uses the authenticated recorded account ID, never an email match or a submitted user ID, and repeated cancellation is harmless.

Messages move from new to read/completed/cancelled, or read to completed/cancelled. Bookings move from pending to confirmed/cancelled, or confirmed to pending/completed/cancelled. Closed requests can receive a reply correction but cannot reopen or reschedule. New/changed active appointments must be in the future in the site timezone. Confirmation/rescheduling rechecks availability; mapped operational vehicles must still belong to the lead's active branch. Changing a test-drive request does not change a vehicle reservation, lead stage or generic follow-up date.

Request text and appointment changes are recorded in erasable activity notes. The audit contains the request reference, old/new status, activity ID, changed field names and customer/staff action classification. A compatibility update, activity insert, lead update or audit failure rolls back the operation. The compatibility table must be InnoDB.

Theme request tables, related-request lists and dashboard message/booking counters use the core view scope in SQL, including pagination totals. Unmapped legacy requests lack a branch/owner relationship and are limited to administrators for staff access; their original customer-owned cancellation remains available. Core sales roles use the CRM form without requiring the theme's `manage_car_dealer` capability. Old CRM profile contents and other legacy screens are not converted by this change.

The authenticated-request reconciliation cursor now advances past core-owned requests without recreating legacy CRM records. Schema/storage lookup errors stop processing rather than treating a mapped request as legacy. Disabling the public intake switch does not disable authorization/auditing of already-linked requests.

Verification on 2026-09-28: the 468-check database/HTTP suite covers intake replay/validation/rollback, account identity, scoped reads, stale and concurrent updates, cancellation ownership/retry, unavailable vehicles, terminal states, no-op saves, privacy and retention rollback. Seven real Chromium DOM checks cover key generation, retry, changed content and reset. Remaining targeted acceptance includes date-only/time-only rescheduling, mapped vehicle transfers, mixed privacy races, legacy cursor progress, scoped table/count pagination and full customer-account/browser journeys. See `VERIFICATION-1.17.0.md` for exact coverage.

## Rollout and reversal

1. Preserve the current database/files backup and apply the additive schema 1.12.0 upgrade through the existing installer. Verify its marker and compatibility/user-table engines on an isolated/staging copy before operational use.
2. The `adc_core_public_intake_enabled` filter defaults to true. Returning false switches theme creation submissions back to the pre-existing theme handlers and disables the plugin form script. Public REST intake still uses core validation; linked request updates retain the 1.16.0 service boundary and duplicate CRM capture remains suppressed. This is a theme intake switch, not a schema downgrade or reversal of recorded requests.
3. Keep both core and theme rows during the review window. No table deletion, automatic merge, backfill or source-data import is part of this increment. Before reverting plugin code, assess the additive schema and changed phone/source contracts; do not delete the replay columns to make a version marker match.
4. Review the passing local evidence and remaining acceptance in `VERIFICATION-1.17.0.md` before operational use. Current synthetic coverage establishes the listed intake, identity, workflow and rollback behaviors; full theme/account journeys and the remaining mixed races and legacy pagination/cursor checks still require acceptance.
