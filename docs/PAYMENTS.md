# Receipt verification and delivery control

Version 1.1.0 introduces staff verification of receipts received outside this application. It records no charge, initiates no transfer and calls no payment/finance provider. A financing application marked approved is not proof that its funds were received.

## Staff workflow

1. A finance employee opens **Finance → تأكيدات السداد**, selects the sale and enters the received amount in SAR halalas, receipt source and reference. The confirmation starts pending.
2. Another finance employee checks the receipt against the external cash/bank/finance source and records a review note, then verifies or rejects it. The reviewer cannot be the recorder or the sale owner, including for administrator accounts.
3. Verified amounts accumulate against the sale. An approval exceeding the remaining balance is rejected. A source/reference cannot be counted twice.
4. Delivery preparation requires an approved sale and verified receipts covering its entire final price. Delivery release rechecks settlement, sale status, invoice reference, VIN confirmation, delivery approval and branch access. The sale owner and VIN confirmer cannot release the vehicle.

The existing `dealership_finance` role gains `adc_record_payments` and `adc_verify_payments`; service-level separation still requires two people. `adc_view_finance` controls the scoped receipt list. Managers who only view finance cannot record or verify receipts. Existing user-assigned branches remain in effect.

## API and storage

`POST /sales/{id}/payments` accepts `amount`, `source` and `reference`; `POST /payments/{id}/decision` accepts `approve` and `reason`. Sources are `cash_receipt`, `bank_transfer` and `finance_disbursement`. The new `adc_payment_confirmations` table stores the amount, source/reference, state, recorder, reviewer, reason and timestamps. References must identify evidence without containing card/account credentials or personal documents.

Sale locks serialize receipt approvals. Verified receipts are terminal through current application interfaces. Payment recording/decisions and all delivery transitions commit with their required audit events; failed audit writes roll back the corresponding state and inventory movements. Quotes linked to any sale cannot receive further discount requests.

## Existing data and rollout

The next plugin bootstrap upgrades schema/roles to 1.1.0. It creates an empty confirmation table; historical deposits, payment references and approved finance requests are never automatically marked paid. Undelivered legacy sales require actual receipt verification before preparation/release. Already delivered history is retained. Test staff assignments and upgrade a database copy before operational rollout.

## Remaining work

This is a manual receipt and refund control with application-enforced separation of duties. Version 1.13.0 applies the same external refund ledger to controlled vehicle returns and paid sale cancellations. It bounds pending and verified refunds by verified receipts, retains the original receipt history, and prevents a cancellation hold from being released before full refund verification. Signed provider webhooks, ERP reconciliation, automated fund movement, receipt attachments, delivery document checklist, invoice snapshots and broader concurrency/security coverage remain required. No provider connectivity or legal/accounting compliance certification is claimed.
