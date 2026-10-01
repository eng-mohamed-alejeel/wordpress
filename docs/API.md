# API Architecture

Version 1.28.0 exposes the same route catalogue under `/wp-json/auto-dealership/v1` and `/wp-json/auto-dealership/v2`. Version 1 remains the compatibility contract. Version 2 returns the envelope documented below. The exact anonymous surface and application request policies were last accepted for v1 in `VERIFICATION-1.27.0.md`; 1.28 verification is pending.

## Response contract and discovery (1.28.0)

Every v1 and v2 response includes `X-Request-ID`. A valid caller-supplied UUID is preserved; otherwise the server generates one. Audit events written during that REST request reuse the same UUID as their correlation ID.

Successful v2 responses use:

```json
{"success":true,"data":{},"meta":{"request_id":"..."}}
```

Failed v2 responses use:

```json
{"success":false,"error":{"code":"adc_error_code","message":"...","details":{}},"meta":{"request_id":"..."}}
```

Authenticated staff with `adc_view_workspace` can retrieve the generated OpenAPI 3.1 description from `GET /wp-json/auto-dealership/schema/v2`. Cookie-authenticated requests require `X-WP-Nonce`.

## Suppliers and vehicle acquisition (1.28.0)

- `GET /suppliers` requires supplier read access. `POST /suppliers` requires `adc_manage_suppliers`. Supplier deactivation is available through the protected administrator workspace and does not delete history.
- `GET /vehicles/{id}/acquisition` requires `adc_view_vehicle_costs`; `PATCH` requires `adc_manage_vehicle_costs`, active-branch scope, an editable inventory state and a change reason.
- Acquisition values use integer SAR halalas. `total_cost` is explicit because no business-approved landed-cost formula exists yet. Acquisition documents accept existing WordPress image/PDF attachments only, with a maximum of 20 files and 20 MiB per file.
- Public catalog routes include descriptive origin, cylinder, video and image-gallery fields. They never expose supplier identity, purchase/additional/total cost, wholesale price, customs reference, acquisition documents or internal notes.

## Finance attempt history (1.28.0)

- `POST /finance-requests` accepts optional `down_payment`, `term_months` and `monthly_payment` alongside the existing sale, provider, amount and consent fields.
- Each request receives an ordered `attempt_number` and `previous_request_id`. Another provider cannot be attempted while the latest request is submitted, under review or approved.
- `GET /sales/{id}/finance-requests` returns at most 100 ordered attempts to finance-authorized staff within branch scope.
- `POST /finance-requests/{id}/status` accepts `under_review`, `approved` or `rejected`, plus an optional decision reason. Approval still requires a provider reference and never verifies payment by itself.

## Public boundary protection (1.27.0)

The only anonymous core pairs are `GET /branches`, `GET /vehicles` and `POST /leads`. Reads consume the `public_read` policy (120 requests per 60 seconds). Validated lead intake consumes `intake` (8 attempts per 3600 seconds) immediately before persistence. Limits use atomic InnoDB buckets and return `adc_rate_limited` (429) with `retry_after`; unavailable protection returns `adc_rate_unavailable` (503). Stored bucket identity is HMAC-only.

`REMOTE_ADDR` is authoritative unless the direct peer matches an exact address/CIDR from `adc_trusted_proxy_cidrs`. Only then is a bounded, valid `X-Forwarded-For` chain considered. See `SECURITY-HARDENING.md`.

## Pricing, deposits and delivery documents (1.19.0)

- `POST /quotations` accepts optional `promotion_code`. The server resolves the configured active promotion and freezes fee, promotion, subtotal, VAT, total and seller identity in the immutable revision.
- `POST /quotations/{id}/discounts` freezes the configured approval tier and before/after gross margin. Requests above the general-manager ceiling are rejected; the stored tier controls approval even if settings later change.
- `POST /reservations` returns `deposit_policy`, `deposit_required_amount`, `deposit_verified_amount` and `currency`. The optional legacy `deposit_amount` can only declare the exact required value and never marks it received.
- `POST /reservations/{id}/deposit` requires `adc_record_payments`, exact required `amount`, a supported `source` and unique evidence `reference`. It creates pending evidence.
- `POST /reservation-deposits/{id}/decision` requires `adc_verify_payments`; the recorder and reservation owner cannot review it. Rejection requires a reason. Only approval updates the reservation's verified deposit. A configured required deposit must be verified before sale conversion and is included once in the sale's verified settlement/refund balance.
- Manual reservation cancellation is rejected while deposit evidence remains pending; finance must verify or reject that evidence first.
- `GET /reservation-deposits` returns at most 100 newest evidence records within the finance user's active branch scope, including the reservation owner needed to evaluate separation of duties.
- `POST /reservations/{id}/refunds` records an external refund for a cancelled reservation's verified deposit. Existing refund review applies through `/refunds/{id}/decision`; requester and reviewer remain separate, and the reservation owner cannot verify it.
- `GET /deliveries/{id}/documents` returns the configured checklist and evidence in branch scope. `POST` to the same route records or replaces a supported document reference before approval. Missing configured documents block approval and release.

## Account preferences (1.18.0)

- `GET /account/preferences` requires the current authenticated account and returns `consent_marketing`, `recorded_at` and `linked_customer_id`. It does not accept an account/customer identifier.
- `POST /account/preferences` requires the current authenticated account and boolean `consent_marketing`. It atomically stores the explicit account preference, updates an existing linked customer and writes a minimized audit event. Opt-out clears the consent timestamp. Cookie requests require the WordPress REST nonce.

The account preference can be recorded before the first enquiry. Later authenticated intake applies it when creating or refreshing the canonical customer, so submitted form data cannot silently reverse an account opt-out. See `CUSTOMER-PREFERENCES.md`.

## Customer identity review (1.17.0)

All three operations below require `manage_options`; browser cookie requests require the WordPress REST nonce. Public `/leads` input remains unlinked even if it submits an account/customer ID. Authenticated theme intake uses the current account ID through its server adapter and may reuse that account's customer record.

- `GET /customers/{id}/duplicates`: up to 50 active candidates with matching stored email and mobile, returning IDs/contact fields/account reference for administrator review. A candidate is not an approved merge.
- `GET /customers/merge?source_id=…&target_id=…`: validates merge eligibility and returns `source`, `target`, `lead_ids` and opaque `revision`. No writes occur.
- `POST /customers/merge`: required `source_id`, `target_id`, `revision` (64 lowercase hex characters), `evidence` (internal review reference, 1–120 characters) and `verified: true`. Returns `{source_id,target_id,moved_leads}` after audited commit. Changes since preview return 409; repeat execution against a merged source is rejected.

The source must be unlinked, active, have leads and no reservations, quotes, quote versions or sales. Email/mobile and the branch/lead-owner sets must match; the combined lead count is at most 500. Existing compatibility account owners must agree with the destination account. Sources with previous merged children are blocked. Errors include `adc_identity_conflict`, `adc_identity_operational`, `adc_identity_scope`, `adc_identity_stale` (409), `adc_identity_invalid` (400), `adc_identity_not_found` (404) and `adc_identity_unavailable` (503).

No arbitrary account-link endpoint is exposed. Legal identity verification, historical booking ownership claims and financial-record merges are outside this increment. See `CUSTOMER-IDENTITY.md`.

## Linked request updates (1.16.0)

- `GET /leads/{id}/request` requires lead-view capability plus active branch/ownership scope. Returns `lead_id`, `request_id`, `type`, `status`, `customer_reply`, `requested_date`, `requested_time`, `revision` and `can_edit`. A lead without a linked theme message/booking, or outside scope, returns 404. It does not automatically create a compatibility request for REST-only enquiries.
- `PATCH /leads/{id}/request` requires lead-management capability plus branch/ownership scope. Supply the latest 64-character `revision` from GET; optional fields are `status`, `customer_reply` (plain text, maximum 4000 characters), `requested_date` (`YYYY-MM-DD`) and `requested_time` (`HH:MM`). Omitted fields remain unchanged; an empty reply clears it. Dates apply to bookings only. Missing/changed revisions return `adc_request_stale` (409) from the service; REST rejects missing required fields before dispatch.
- `POST /bookings/{id}/cancel` uses the compatibility booking ID and requires authentication. Only the account recorded in `user_id` may cancel its pending/confirmed booking. Repeating cancellation of the same already-cancelled booking returns `updated: false`. Completed bookings cannot be cancelled. This endpoint does not accept staff impersonation, reply or reschedule fields; customer ownership is checked by the service.

Write success is `{ "updated": true, "status": "confirmed" }`; an unchanged staff submission with a current revision returns `updated: false`. Reload GET after saving, or after 409. The revision covers the compatibility row and latest activity ID, so another core activity can also invalidate it. Cookie-authenticated REST requests require the normal WordPress REST nonce. Admin forms have a lead-specific nonce; account cancellation retains its account-dashboard nonce.

Booking transitions are `pending → confirmed/cancelled`, `confirmed → pending/completed/cancelled`. Message transitions are `new → read/completed/cancelled`, `read → completed/cancelled`. The current state can be retained while changing a reply; completed/cancelled requests cannot reopen or reschedule. Rescheduling and entry into pending/confirmed require a future appointment in the site timezone. Confirmation and rescheduling recheck public vehicle availability and, for linked operational vehicles, the request branch. These operations do not reserve inventory, change the sales pipeline or send notifications.

Each changed request, activity note, lead timestamp and `lead.request_updated` audit record commit together. Audit JSON contains IDs, states and changed field names, without free-text replies or appointment details. Failed storage/transaction checks never fall back to the legacy write path.

## Public intake and activity history (1.15.0)

Since 1.29.0, the plugin registers the existing theme AJAX actions `car_dealer_lead`, `car_dealer_contact` and `car_dealer_booking` directly. Their nonce and response shape remain compatible, while validation, identity, availability, replay and rate enforcement run through `PublicIntake`. The active theme suppresses its old handlers when the ownership facade is enabled. Message and booking compatibility rows remain temporary account/request-workflow projections written inside the same plugin transaction; the theme no longer installs those tables in normal operation.

The plugin also owns `car_dealer_subscribe`. It requires a valid email, an explicit `consent_marketing=1`, an empty `website` honeypot and the shared atomic intake policy. Subscription activation and a minimized audit event commit together. The AJAX response contains only a display message.

`POST /leads` requires `name` and `mobile`; optional fields are `email`, `city`, `branch_id`, `consent_marketing`, `message` (4000 characters), `request_kind`, `car_id` (WordPress car post ID), `date`, `time`, `website` (honeypot, must remain empty) and `idempotency_key` (UUID v4). Source is always `website`. Kinds: `contact`, `finance`, `finance_request`, `price_request`, `offer_request`, `test_drive`, `purchase`. Marketing consent defaults to false; public contact values never authorize merging into another customer's record.

Saudi local mobile numbers and Arabic/Persian digits normalize to a canonical number; email is lowercased after validation. Car enquiries require a published, available car. A mapped car determines the branch; contradictory branches are rejected. Other requests use the configured active default branch or branch zero for administrator triage. Test-drive requests require a future `YYYY-MM-DD` / `HH:MM` appointment in the site timezone. This records a request, not a confirmed appointment or vehicle reservation.

Success remains `{ "id": 123, "status": "new" }`. With a UUID, an identical canonical payload under the same authentication identity replays this acknowledgement; a different payload returns `adc_intake_key_conflict` (409). No customer data or current internal stage is returned on replay. Requests without UUIDs create separate enquiries. Public validation and the atomic `intake` policy run before replay, so unavailable vehicles, expired appointment times or an exhausted limit can still reject a retry. See `CRM-INTAKE.md` for browser lifetime and salt-rotation limits.

`GET /leads/{id}/activities?page=1&per_page=50` returns newest activity first, with `id`, `lead_id`, `actor_user_id`, `type`, `notes`, `next_action_at`, `created_at`; maximum page size is 100. The service checks view capability, active branch and ownership on the content query. Out-of-scope leads return 404. `POST /leads/{id}/activities` now updates the lead's modification timestamp and, when supplied, its `next_action_at` in the audited transaction. Omitting the date preserves the existing lead schedule. Stored activity timestamps are UTC.

## Vehicle specifications (1.14.0)

`PATCH /vehicles/{id}/specifications` requires `adc_manage_inventory`, active branch access and authenticated REST nonce for cookie sessions. Send `reason` (1–2000 characters) and a `specifications` object. Unknown fields are rejected. Omitted fields remain unchanged; empty text and null optional numbers clear a value. Allowed fields: `exterior_color`, `interior_color` (80 chars), `engine_size` (40 chars, retain units), `drivetrain` (empty/fwd/rwd/awd/4wd), `doors` (1–10), `seats` (1–100), `horsepower` (1–5000), `warranty` (2000 chars), `interior_features`, `exterior_features`, `safety_features` (4000 chars each). All text is plain text intended for public display.

Edits are allowed in ordered, in_transit, received, inspection, available, hold, maintenance and returned states. Reservation/sale/delivery/transfer terminal states reject edits. A successful change and its before/after audit commit together. The response is `{id, updated}`; an identical retry returns `updated: false`. Creation accepts the same fields at the top level. Public catalog responses include specifications only for eligible published inventory; VIN and costs remain excluded.

## Existing workflow contracts

CRM list, stage and activity operations require the current employee's active assigned branch as well as their ownership/capability rules. Staff without a valid active branch cannot access operational records or unassigned intake; global administrators retain triage and remediation access. An employee moved to another branch loses access to their old-branch leads even while recorded as owner. Quotes, reservations and sale creation additionally require an accessible customer through a lead in the vehicle's branch (sales: own lead; manager: branch lead; global administrator: existing customer). Dealership routes return `adc_schema_unavailable` / HTTP 503 while a schema upgrade is unverified.

| Method and route | Access |
|---|---|
| `GET /branches` | Public active branch list. |
| `POST /branches` | Administrator; create branch. |
| `GET /vehicles` | Public available vehicle catalog; bounded filters, sorting and page size. |
| `POST /vehicles` | Inventory capability; create vehicle with unique VIN and stock number. |
| `POST /vehicles/{id}/status` | Inventory capability; allowed transition and reason required. |
| `POST /vehicles/{id}/issues` | Inventory capability; opens a documented `hold` or `maintenance` case with optional assignee and review date. Direct transitions into these states are rejected. |
| `POST /vehicle-issues/{id}/resolve` | Branch-scoped inventory capability; records the resolution and returns the vehicle to inspection. A new passed checklist is required before availability. |
| `POST /vehicles/{id}/transfer` | Source branch inventory staff request transfer of an available vehicle to an active target branch; vehicle is made unavailable until the workflow resolves. |
| `POST /transfers/{id}/decision`, `/dispatch`, `/receipt` | Destination branch approves/rejects, source requester marks dispatched, and a different destination staff member confirms receipt. Branch/status checks and movement history are enforced by the service. |
| `POST /leads` | Public enquiry intake with atomic HMAC-identity hourly policy; source fixed to website. |
| `GET /leads` | Sales owner, branch manager or administrator; scoped customer fields. |
| `GET /leads/{id}/activities` | Same view capability, branch and ownership scope as the lead list; paginated activity notes. |
| `POST /leads/{id}/stage`, `/assignment`, `/activities` | Ownership/branch scoped CRM operations. |
| `POST /reservations`, `POST /reservations/{id}/cancel` | Sales creates with idempotency UUID and transactional lock; branch manager cancels with a reason. Deposits/payment references place the vehicle on hold pending manual review. |
| `POST /reservations/{id}/deposit`, `/reservation-deposits/{id}/decision` | Finance recorder submits exact policy evidence; a separate finance reviewer verifies or rejects it. |
| `POST /quotations`, `/quotations/{id}/discounts` | Sales/manager; branch scoped, amount stored in SAR halalas. |
| `GET /quotations/{id}/versions` | Quote owner, branch lead viewer, finance viewer or administrator, with branch/ownership scope. Returns up to 50 immutable revisions per page, newest first. |
| `POST /discounts/{id}/decision` | Discount reviewer; requester cannot approve own request and high discounts require general manager. |
| `POST /sales`, `/sales/{id}/approval` | Sales creation; separate manager approval and invoice reference. |
| `POST /finance-requests`, `/finance-requests/{id}/status` | Finance capability; no card or bank credentials. |
| `POST /sales/{id}/payments` | `adc_record_payments`; branch scoped receipt entry, positive integer `amount` in halalas, `source` (`cash_receipt`, `bank_transfer`, `finance_disbursement`) and `reference` required. Creates pending confirmation, not a charge. |
| `POST /payments/{id}/decision` | `adc_verify_payments`; `approve` boolean and `reason` required. Different reviewer from recorder and sale owner; verified amount cannot exceed the remaining balance. |
| `POST /sales/{id}/delivery`, `/deliveries/{id}/vin`, `/approval`, `/release` | Delivery preparation, VIN confirmer, separate approver and authorized release. |
| `GET`, `POST /deliveries/{id}/documents` | Branch-scoped configured checklist and audited document-reference recording before approval. |
| `POST /deliveries/{id}/return` | `adc_process_returns`; receives a completed delivery into an active same-branch location with condition, odometer, document reference and reason. Marks the financial obligation pending refund and requires a new inspection before availability. |
| `POST /returns/{id}/refunds` | `adc_record_refunds`; records a pending external refund using an amount in halalas, method and unique reference. Pending plus verified refunds cannot exceed verified receipts. |
| `POST /refunds/{id}/decision` | `adc_verify_refunds`; a different same-branch finance employee verifies or rejects the refund with a reason. |
| `POST /sales/{id}/cancellation` | `adc_cancel_sales`; cancels an undelivered sale with a reason and atomically reconciles its reservation, open delivery, pending payment evidence and vehicle state. |
| `POST /cancellations/{id}/refunds` | `adc_record_refunds`; records a pending external refund against a paid sale cancellation. |

Routes use an explicit `permission_callback`, validate request schemas and call services instead of writing directly. Authenticated browser requests use WordPress cookie authentication and REST nonces; external clients use a reviewed authentication method with scoped capabilities and revocation. Public catalog responses omit VIN, purchase cost, internal location and brand reference IDs and include only available vehicles linked exactly once to a published `car` post and an active branch. Duplicate post mappings are withheld. Each item includes its public title, URL and featured image URL; the response includes `total`, `total_pages` and normalized `filters`. Supported filters are `search`, `brand`, `model`, `trim`, `model_year` or `min_year`/`max_year`, `min_price`/`max_price` in halalas, `min_mileage`/`max_mileage`, `body_type`, `fuel_type`, `transmission`, `engine_size`, `drivetrain`, `exterior_color`, `interior_color`, `branch_id`, and `condition`. Sorting is allowlisted to `newest`, `price_asc`, `price_desc`, `year_desc`, or `mileage_asc`; `per_page` is capped at 48. Inventory records without an explicitly published public post remain private. In theme compatibility mode, mapped unavailable records are suppressed while unmigrated legacy posts retain their existing behavior. In authoritative mode, all public car queries require an eligible operational mapping. Finance and audit data remain private.

Reservation retries must retain the same authenticated owner, vehicle, customer and deposit. Matching retries return the original response shape; mismatches return `adc_idempotency_conflict` / HTTP 409 without disclosing the existing record. A key cannot bypass current branch/customer authorization. Receipt references are unique per source; identical submissions by the same recorder against an eligible sale reuse the existing confirmation. Quote prices cannot be discounted once linked to a sale. Quote amounts are integer halalas; `tax_rate_bps` and originating branch are frozen at creation. Vehicle relocation cannot transfer access to historical quote documents. Legacy quotes without a known tax-rate snapshot remain readable but require a new quote before recalculation.

Delivery preparation and release require verified financial evidence covering the whole sale. Approved finance requests and submitted evidence do not establish settlement; an independently verified reservation deposit contributes once after sale conversion. The release operation rechecks settlement, configured documents and sale/invoice/VIN approvals server side. See `PAYMENTS.md` for the manual verification policy and remaining integration work.

Endpoints return domain error codes and appropriate HTTP status. Pagination is bounded. Audit records carry correlation IDs; a universal API correlation header and generated OpenAPI specification remain pending.
