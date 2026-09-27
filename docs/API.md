# API Architecture

Version 1.15.0 implements the following routes under `/wp-json/auto-dealership/v1`. Integration verification of the new intake/activity changes is pending.

## Public intake and activity history (1.15.0)

`POST /leads` requires `name` and `mobile`; optional fields are `email`, `city`, `branch_id`, `consent_marketing`, `message` (4000 characters), `request_kind`, `car_id` (WordPress car post ID), `date`, `time`, `website` (honeypot, must remain empty) and `idempotency_key` (UUID v4). Source is always `website`. Kinds: `contact`, `finance`, `finance_request`, `price_request`, `offer_request`, `test_drive`, `purchase`. Marketing consent defaults to false; public contact values never authorize merging into another customer's record.

Saudi local mobile numbers and Arabic/Persian digits normalize to a canonical number; email is lowercased after validation. Car enquiries require a published, available car. A mapped car determines the branch; contradictory branches are rejected. Other requests use the configured active default branch or branch zero for administrator triage. Test-drive requests require a future `YYYY-MM-DD` / `HH:MM` appointment in the site timezone. This records a request, not a confirmed appointment or vehicle reservation.

Success remains `{ "id": 123, "status": "new" }`. With a UUID, an identical canonical payload under the same authentication identity replays this acknowledgement; a different payload returns `adc_intake_key_conflict` (409). No customer data or current internal stage is returned on replay. Requests without UUIDs create separate enquiries. Public validation and the shared transient IP limit (eight attempts per hour) run before replay, so unavailable vehicles, expired appointment times or an exhausted limit can still reject a retry. See `CRM-INTAKE.md` for browser lifetime and salt-rotation limits.

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
| `GET /vehicles` | Public available vehicle catalog; bounded filters and page size. |
| `POST /vehicles` | Inventory capability; create vehicle with unique VIN and stock number. |
| `POST /vehicles/{id}/status` | Inventory capability; allowed transition and reason required. |
| `POST /vehicles/{id}/issues` | Inventory capability; opens a documented `hold` or `maintenance` case with optional assignee and review date. Direct transitions into these states are rejected. |
| `POST /vehicle-issues/{id}/resolve` | Branch-scoped inventory capability; records the resolution and returns the vehicle to inspection. A new passed checklist is required before availability. |
| `POST /vehicles/{id}/transfer` | Source branch inventory staff request transfer of an available vehicle to an active target branch; vehicle is made unavailable until the workflow resolves. |
| `POST /transfers/{id}/decision`, `/dispatch`, `/receipt` | Destination branch approves/rejects, source requester marks dispatched, and a different destination staff member confirms receipt. Branch/status checks and movement history are enforced by the service. |
| `POST /leads` | Public enquiry intake with IP-based hourly limit; source fixed to website. |
| `GET /leads` | Sales owner, branch manager or administrator; scoped customer fields. |
| `GET /leads/{id}/activities` | Same view capability, branch and ownership scope as the lead list; paginated activity notes. |
| `POST /leads/{id}/stage`, `/assignment`, `/activities` | Ownership/branch scoped CRM operations. |
| `POST /reservations`, `POST /reservations/{id}/cancel` | Sales creates with idempotency UUID and transactional lock; branch manager cancels with a reason. Deposits/payment references place the vehicle on hold pending manual review. |
| `POST /quotations`, `/quotations/{id}/discounts` | Sales/manager; branch scoped, amount stored in SAR halalas. |
| `GET /quotations/{id}/versions` | Quote owner, branch lead viewer, finance viewer or administrator, with branch/ownership scope. Returns up to 50 immutable revisions per page, newest first. |
| `POST /discounts/{id}/decision` | Discount reviewer; requester cannot approve own request and high discounts require general manager. |
| `POST /sales`, `/sales/{id}/approval` | Sales creation; separate manager approval and invoice reference. |
| `POST /finance-requests`, `/finance-requests/{id}/status` | Finance capability; no card or bank credentials. |
| `POST /sales/{id}/payments` | `adc_record_payments`; branch scoped receipt entry, positive integer `amount` in halalas, `source` (`cash_receipt`, `bank_transfer`, `finance_disbursement`) and `reference` required. Creates pending confirmation, not a charge. |
| `POST /payments/{id}/decision` | `adc_verify_payments`; `approve` boolean and `reason` required. Different reviewer from recorder and sale owner; verified amount cannot exceed the remaining balance. |
| `POST /sales/{id}/delivery`, `/deliveries/{id}/vin`, `/approval`, `/release` | Delivery preparation, VIN confirmer, separate approver and authorized release. |
| `POST /deliveries/{id}/return` | `adc_process_returns`; receives a completed delivery into an active same-branch location with condition, odometer, document reference and reason. Marks the financial obligation pending refund and requires a new inspection before availability. |
| `POST /returns/{id}/refunds` | `adc_record_refunds`; records a pending external refund using an amount in halalas, method and unique reference. Pending plus verified refunds cannot exceed verified receipts. |
| `POST /refunds/{id}/decision` | `adc_verify_refunds`; a different same-branch finance employee verifies or rejects the refund with a reason. |
| `POST /sales/{id}/cancellation` | `adc_cancel_sales`; cancels an undelivered sale with a reason and atomically reconciles its reservation, open delivery, pending payment evidence and vehicle state. |
| `POST /cancellations/{id}/refunds` | `adc_record_refunds`; records a pending external refund against a paid sale cancellation. |

Routes use an explicit `permission_callback`, validate request schemas and call services instead of writing directly. Authenticated browser requests use WordPress cookie authentication and REST nonces; external clients use a reviewed authentication method with scoped capabilities and revocation. Public catalog responses omit VIN and purchase cost and include only available vehicles linked to a published `car` post and an active branch. Each item includes its public title, URL and featured image URL; the response includes `total` and `total_pages`. Inventory records without an explicitly published public post remain private. Theme car queries also hide mapped posts when their central inventory status is not available or their branch is inactive; unmigrated legacy posts continue to follow the theme's existing behavior. Finance and audit data remain private.

Reservation retries must retain the same authenticated owner, vehicle, customer and deposit. Matching retries return the original response shape; mismatches return `adc_idempotency_conflict` / HTTP 409 without disclosing the existing record. A key cannot bypass current branch/customer authorization. Receipt references are unique per source; identical submissions by the same recorder against an eligible sale reuse the existing confirmation. Quote prices cannot be discounted once linked to a sale. Quote amounts are integer halalas; `tax_rate_bps` and originating branch are frozen at creation. Vehicle relocation cannot transfer access to historical quote documents. Legacy quotes without a known tax-rate snapshot remain readable but require a new quote before recalculation.

Delivery preparation and release require verified receipt totals covering the whole sale. Approved finance requests, submitted receipts and reservation deposit fields do not establish settlement. The release operation rechecks settlement and sale/invoice/VIN approvals server side. See `PAYMENTS.md` for the manual verification policy and remaining integration work.

Endpoints return domain error codes and appropriate HTTP status. Pagination is bounded. Audit records carry correlation IDs; a universal API correlation header and generated OpenAPI specification remain pending.
