# Pricing, deposits and delivery evidence

## Quote calculation

All monetary values are integer SAR halalas. A new quote is calculated in this order:

1. vehicle retail base price;
2. configured fixed fee;
3. active explicit promotion, fixed or percentage;
4. approved discount;
5. VAT on the resulting subtotal.

The quote and every immutable revision store the base, fee, promotion code and amount, discount, subtotal, VAT rate and amount, final total, and configured seller identity. Promotion dates use UTC calendar dates. A promotion is only applied when its exact configured code is submitted. Historical quote components are never recalculated from current settings.

## Discount controls

The settings screen defines a sales-manager ceiling and a general-manager ceiling. A request above the second ceiling is rejected. The request stores its required tier when created, so a later settings change cannot weaken approval. It also stores gross margin before and after the requested discount from the frozen quote base/promotion and private vehicle purchase cost. A missing purchase cost blocks a new margin-based request; legacy requests can retain an unknown margin. The requester cannot approve their own request; the high tier additionally requires `adc_approve_high_discounts`.

## Reservation deposits

The configured policy is `none`, a fixed halala amount, or percentage basis points. Creation freezes the policy and required amount but records no receipt. `POST /reservations/{id}/deposit` creates evidence in `pending`; a different finance reviewer uses `/reservation-deposits/{id}/decision`. The reviewer cannot be the evidence recorder or reservation owner. Only `verified` evidence updates the reservation deposit amount/reference. A required deposit must be verified before conversion to sale and is then included once in settlement, refunds and finance exports. Pending or verified evidence prevents automatic expiry release. Pending evidence must be decided before manual cancellation. Cancelling a paid reservation places the vehicle on hold and opens an independently reviewed external-refund workflow through `/reservations/{id}/refunds`.

## Delivery documents

Administrators select required document types from invoice, customer identity, vehicle registration, insurance, signed handover form and finance clearance. Authorized delivery/VIN staff record plain external references through `/deliveries/{id}/documents` or the delivery workspace. References can change only while a delivery is preparing or VIN-confirmed, and every change is audited. Both approval and release recheck the configured requirements server-side. Finance staff can record and independently review deposit evidence from the payments workspace.

## Deployment

Plugin 1.19.0 requires additive schema 1.13.0. Normal guarded WordPress activation/bootstrap applies and verifies it. Review VAT, fee, promotion dates, both discount ceilings, deposit policy, seller identity and required delivery documents before staff use. Empty required-document configuration preserves the earlier delivery behavior. This increment did not connect a payment, finance, ERP, messaging or PDF-storage provider and did not touch the intentionally empty source database.
