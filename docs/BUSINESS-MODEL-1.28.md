# Business model foundation — 1.28.0

## Implemented boundary

This increment prepares the missing business fields without inserting real or sample records.

### Supplier directory

Suppliers are global reference records with an immutable unique code, display/legal names, country, tax number, restricted contact fields, internal notes and active status. Deactivation preserves vehicle history. Only purchasing managers, general managers and administrators can change suppliers.

Purchase orders are not implemented in this increment. Their lifecycle, approval levels, cancellation policy, currency rules and receiving relationship require an approved business policy.

### Vehicle profile and acquisition

Public descriptive fields now include origin country, cylinder count, HTTPS video URL and an attachment-ID image gallery. Existing public-field filtering continues to exclude private commercial data.

Restricted acquisition fields include supplier, purchase cost, additional cost, explicit total cost, wholesale price, customs reference, arrival date, existing image/PDF document attachments and internal notes. All monetary values are integer SAR halalas. Changes require branch scope, a dedicated capability, an editable inventory state and an audit reason.

The system does not calculate total cost automatically. That protects accounting behavior until the business defines which charges, taxes, transport, customs and adjustments belong in the formula.

### Finance attempts

Finance requests retain their sale/customer/vehicle context while creating an ordered provider-attempt chain. Each attempt records its predecessor, requested amount, optional down payment, optional term/monthly payment, consent, provider reference, decision reason and submission/decision timestamps.

Only one submitted, under-review or approved attempt may exist for a sale. Rejected or cancelled attempts remain immutable history and a later provider receives the next attempt number. Finance approval remains separate from verified payment.

## Role matrix

| Role | Main capabilities |
|---|---|
| Sales | Own leads and reservation creation |
| Sales manager | Branch leads, discounts, reservations, sales/delivery approval and branch reports |
| General manager | Branch operations, high discounts, pricing, finance, restricted vehicle costs/suppliers, audit, integrations and reports |
| Inventory | Inventory operations, transfers, VIN confirmation and supplier names without costs |
| Purchasing | Supplier maintenance and restricted acquisition/cost fields |
| Finance | Finance requests, payment/refund recording and independent verification |
| Delivery | Inventory visibility, physical VIN confirmation and returns |
| Customer service | Branch lead handling and reservation creation |
| Marketing | Aggregate branch reports only |
| Auditor | Read-only audit, outbox, integration and aggregate report visibility |

Branch assignment remains mandatory for non-global operational access. Existing service rules still reject self-approval even if a user receives overlapping capabilities.

## Security and API

Security audit events cover successful and rate-bounded failed login records, password reset, account registration/deletion, role changes and direct WordPress capability-meta mutations. Failed-login identity is HMAC protected; usernames, passwords and raw network addresses are not copied into audit data.

REST v1 remains compatible. REST v2 wraps every response and supplies a request ID. The server generates an authenticated OpenAPI 3.1 description from the actual registered v2 routes, which prevents a manually maintained route list from silently drifting.

## Decisions still required

1. Purchase-order states, approvers, limits, cancellation/reversal and receiving rules.
2. The total-cost formula and its effective-date/change policy.
3. Final job-title mapping and whether any organization-specific role must be split further.
4. Mandatory finance fields, permitted terms and provider-specific state mappings.
5. Retention periods for supplier contacts, acquisition documents and finance decisions.
6. Business approval for specialist margin, aging, purchasing and employee reports.

For the first-release scope decision on 2026-10-03, the owner deferred purchase orders, an automatic total-cost formula, official stored PDF/invoice generation, financial-history identity merges and external provider adapters until their policies and contracts are approved. Existing explicit cost fields and current capability/reports remain. Automated identity retention is disabled at 0 days pending a category-specific policy. This is a scope decision, not a substitute for the outstanding business approvals; see `LAUNCH-DATA-AND-SCOPE-2026-10-03.md`.

## Verification state

Implementation began in plugin 1.28.0 and schema 1.17.0. On 2026-10-02, focused acceptance passed against the current plugin 1.29.13: the full isolated suite completed 687 database/HTTP checks, including the supplier, acquisition, finance, role, audit and v2/OpenAPI boundaries. The source schema upgrade had already been applied. The user then authorized labeled synthetic development data in the local source database; see `VERIFICATION-1.28.0.md` and `DEVELOPMENT-DATA-2026-10-02.md`. The business decisions above remain open.
