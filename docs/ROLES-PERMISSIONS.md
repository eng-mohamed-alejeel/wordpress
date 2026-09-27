# Roles, Capabilities and Separation of Duties

The plugin registers these initial scoped roles; these are capability names, not proof that row-level branch scope is complete. Services must still check the object's branch and ownership.

| Role | Capability scope |
|---|---|
| Administrator | WordPress administrator plus all `adc_*` capabilities. |
| Dealership Sales | Own leads, manage own leads, create reservations, view workspace. |
| Dealership Sales Manager | Branch leads, discount reviews up to configured limit, reservation management, sales/delivery approval, workspace. |
| Dealership General Manager | High discount review, branch leads, pricing floors, sales/delivery approval, finance/audit reads, workspace. |
| Dealership Inventory | Inventory read/write and transfers, workspace. |
| Dealership Finance | Finance read/write, workspace. |
| Dealership Auditor | Audit read only, workspace. |

| Action | Minimum capability | Additional rule |
|---|---|---|
| View/edit lead | `adc_view_own_leads` / `adc_manage_own_leads` | Owner match; manager services enforce branch. |
| Manage inventory | `adc_manage_inventory` | Validate transition and branch scope; audit changes. |
| Transfer vehicle | `adc_transfer_inventory` | Source/destination authorization and reason. |
| Review discount | `adc_review_discounts` | Requester cannot approve own request. |
| Manage reservation | `adc_manage_reservations` | Lock/recheck vehicle availability within transaction. |
| View finance | `adc_view_finance` | Mask sensitive fields by default. |
| Correct vehicle VIN | `adc_change_vehicle_vin` | General manager/administrator only; pre-sale states, branch scope, reason and audit required. |
| Manage finance | `adc_manage_finance` | No storage of bank credentials/payment card data. |
| Record receipt confirmation | `adc_record_payments` | Finance role; sale branch scope, positive amount and unique source/reference; pending until reviewed. |
| Verify/reject receipt | `adc_verify_payments` | Finance role; reviewer differs from recorder and sale owner; approval cannot exceed remaining balance. |
| Read audit | `adc_view_audit` | No edit/delete capability exposed. |

New roles receive no general `edit_posts`, `manage_options`, or WordPress administrator rights. Activation adds capabilities but does not downgrade existing roles. Reconcile/cleanup on uninstall is deliberately absent because user role data may be in active use.

## Central branch policy

Version 1.1.0 adds payment recording and verification capabilities to the finance role and administrators. Global scope does not bypass payment or delivery separation of duties. Staff require two finance accounts for recording/review; one account cannot approve its own receipt. See `PAYMENTS.md` for the workflow.

`Security/BranchScope.php` is the shared policy for plugin service checks and scoped inventory, CRM, transfer, approval, finance and delivery lists. Version 1.4.0 stores one primary `adc_branch_id` and up to 25 active allowed branches in `adc_branch_ids`. Existing users with only the primary metadata retain their original single-branch scope. Authenticated administrators with `manage_options` have global scope. Branch assignment alone does not grant a feature capability. Missing, zero, malformed or inactive assignments deny access; only global administrators can triage branchless incoming leads.

Version 1.11.0 grants `adc_process_returns` to sales managers, general managers and administrators. The capability accepts a completed delivery back into same-branch inventory and records a pending refund obligation; it does not authorize payment verification or financial refund completion.

Version 1.12.0 grants finance staff `adc_record_refunds` and `adc_verify_refunds`. A requester cannot verify the same refund, and branch scope and verified receipt balance are rechecked by the service.

Version 1.13.0 grants `adc_cancel_sales` to sales managers, general managers and administrators. It applies only before final delivery and does not let the cancelling manager verify a financial refund.

Sales ownership is evaluated together with the allowed active branches. Removing a branch immediately removes access to records in that branch; adding a branch does not bypass lead ownership for sales users. Direct operational vehicle reads require an inventory/finance capability plus branch scope; requesting private fields does not grant VIN or purchase-cost access. Public reads continue through the published catalog.

Profile branch changes require administrator and `edit_user` capabilities, a nonce bound to the target user, active integer branch identifiers, and inclusion of the primary branch in the allowed list. The update and audit event succeed together through compensating restoration. Explicit global dealership roles without `manage_options` and legacy theme access remain separate work items.
