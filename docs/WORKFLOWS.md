# Domain Workflows

## Account identity and CRM-only consolidation (1.17.0)

Authenticated theme enquiries reuse the customer linked to the current WordPress account; guest and generic REST contacts remain separate. Administrators can inspect matching contact candidates, review independent identity evidence and consolidate eligible CRM-only source leads into a destination under a current preview revision. Source operational/documentary references, conflicting accounts or branch/owner scopes block the merge. The source becomes a tombstone and the operation appends activity/audit records atomically. Existing booking account ownership is preserved. See `CUSTOMER-IDENTITY.md`; integration acceptance is pending.

## Public CRM intake and linked requests (1.15.0–1.16.0)

Contact/test-drive forms and public REST enquiries validate through the core intake service. A successful submission atomically records the contact, lead, initial activity and audit; theme forms also retain their customer-account request copy. A valid replay UUID prevents an additional persisted enquiry for the same canonical payload. This does not confirm a test drive, reserve inventory or merge an unverified identity. Staff read the initial message and later activities through scoped history. Since 1.16.0, linked theme booking/reply updates and authenticated customer cancellation also append activities and audit atomically; staff writes require a current revision. Closed requests cannot reopen or reschedule. Unmapped legacy records retain their original workflow with administrator-only staff access. See `CRM-INTAKE.md`; integration verification remains pending.

## Operational transitions

Transitions below are target states; the current theme uses a smaller set and does not yet enforce these state machines consistently.

```mermaid
stateDiagram-v2
  [*] --> Ordered
  Ordered --> InTransit
  InTransit --> Received
  Received --> Inspection
  Inspection --> Available
  Available --> Reserved
  Reserved --> Available: expire/cancel after checks
  Reserved --> Sold
  Sold --> ReadyForDelivery
  ReadyForDelivery --> Delivered
  Available --> Hold
  Available --> Maintenance
  Available --> Transferred
  Transferred --> Available
```

Lead: New → Contacted → Qualified → Quotation → Finance or Negotiation → Reserved → Won; any eligible stage may become Lost with required reason. Reservation: Confirmed → Converted to Sale, or Expired/Cancelled. Expiry releases only unpaid/unreferenced reservations; cancelled reservations with a deposit/reference put the vehicle on Hold for manual reconciliation. Sale: Quotation → Negotiation → discount review when policy requires it → reservation → payment/finance verification → sales approval → invoice reference → delivery preparation → delivery approval → vehicle release → delivered. Finance: Submitted → Under Review → Approved/Rejected; approval never implies payment. Delivery: Preparing → VIN confirmed → approved by another user → released by an authorized user → delivered. Any exceptional reversal records prior and new state, actor, UTC timestamp and reason.

Each transition is an application service operation that checks authorization and preconditions server side. Reservation confirmation must lock or atomically conditionally update the vehicle/reservation and recheck availability inside the transaction. Expiry jobs are idempotent and must not release a vehicle with payment verification, manager hold or active sale. No front-end supplied price, VIN confirmation or approval status is trusted.

## Implemented settlement gate in 1.1.0

External receipt recorded (pending) → independent financial reviewer → verified or rejected. The reviewer must differ from the recorder and sale owner. Receipt approvals lock the sale and cannot exceed its outstanding amount. Source/reference is unique. Only verified receipts count toward settlement; financing approval and deposit fields do not count.

Approved sale + invoice reference + full verified settlement → delivery preparation → confirmed VIN → separate delivery approval → release with settlement/sale/VIN/scope checks repeated → delivered. The sale owner and VIN confirmer cannot perform release. Tested receipt and delivery changes roll back together with inventory movement if required audit writes fail. Receipt refund/reversal and delivery document checklist remain pending.
