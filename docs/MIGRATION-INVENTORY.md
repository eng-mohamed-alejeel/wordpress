# Legacy migration inventory and reconciliation

Run this read-only report first on a restored staging copy:

```powershell
wp adc migration-report --format=json
wp adc migration-report --format=summary --branch=<fallback-active-branch-id> --fail-on-issues
```

The command reports aggregate counts only and does not emit names, phone numbers, email addresses, VINs, stock numbers, messages, or other record values. It does not change source or target rows.

## Source-to-target map

| Legacy source | Immutable link | Target | Current treatment |
|---|---|---|---|
| `car` post | `wp_posts.ID` → `adc_vehicles.public_post_id` | `adc_vehicles` | Supported by `migrate-vehicles`; source post and media remain intact. |
| `_car_vin`, `_car_stock_number` | Normalized exact value | Vehicle unique keys | Invalid and conflicting values block automatic import. |
| `_car_make`, `_car_model`, `_car_trim`, `_car_year` | Source post ID | Vehicle identity fields | Required identity fields are validated before import. |
| `_car_location` | Exact unique active branch name, or explicit `--branch` | `branch_id` | Ambiguous or missing mappings are reported and skipped. |
| `_car_inventory_status` | Source post ID | Vehicle status | Only explicit `available` and `pending` map to `available` and `received`. Missing/unknown values and transactional states require reconciliation. No receipt or inspection evidence is manufactured. |
| `_car_price` | Source post ID | `retail_price` | Decimal SAR with up to two fractional digits becomes integer halalas without floating point. Negative, malformed and overflowing values are rejected. |
| `_car_stock_number`, fallback `_car_stock` | Source post ID | `stock_number` | Fallback applies only when the preferred value is empty. |
| `_car_mileage`, fallback `_car_kilometers` | Source post ID | `mileage` | Nonnegative integer; zero is preserved and does not trigger fallback. |
| `_car_condition` | Source post ID | `condition_key` | `new`, `used` and `certified` are preserved; no default condition is invented. |
| `_car_color`, `_car_interior_color` | Source post ID | `exterior_color`, `interior_color` | Public text, at most 80 characters each. |
| `_car_engine_size` | Source post ID | `engine_size` | Preserved as text (40 characters); no undocumented conversion between litres and cc. Confirm the source unit before public cutover. |
| `_car_drivetrain` | Source post ID | `drivetrain` | Empty or `fwd`, `rwd`, `awd`, `4wd`. |
| `_car_doors`, `_car_seats`, `_car_horsepower` | Source post ID | Matching fields | Positive bounded integers; empty becomes null. Invalid values block import. |
| `_car_warranty`, `_car_interior_features`, `_car_exterior_features`, `_car_safety_features` | Source post ID | Matching fields | Bounded public plain text; never use these for internal/customer notes. |
| `car_offer` post + `_car_id` | Offer post ID and linked car post ID | Pending offer domain | Counted as linked or orphaned. Import waits for an approved offer schema and lifecycle. |
| `car_dealer_messages.id` | `(message,id)` | Lead `legacy_request_type/id` | Supported by `migrate-leads`; unique target pair makes retry idempotent. |
| `car_dealer_bookings.id` | `(booking,id)` | Lead `legacy_request_type/id` | Supported by `migrate-leads`; requested appointment is retained in activity context. |
| `cd_crm` posts | Post ID | Pending reconciliation | Counted separately because these may overlap message/booking workflow records. No automatic merge. |
| `car_dealer_subscribers` | Normalized email | Separate consent purpose | Excluded from CRM migration. Preserve until marketing consent and retention policy are approved. |

## Report categories

- `matched`: mapped vehicle and the selected source fields still agree.
- `drifted`: mapped identity, condition, descriptive/specification fields, mileage or retail price differ or are invalid. Operational status changes after import are not source drift.
- `eligible_unmapped`: valid source vehicle with a deterministic branch and no unique-key collision.
- `invalid_identity`: invalid identity, price, condition, mileage or specifications (aggregate compatibility category).
- `workflow_blocked`: missing/unknown status or transactional inventory requiring reconciliation.
- `unresolved_branch`: no explicit fallback and no unique active branch name match.
- `vin_conflict` / `stock_conflict`: another target row owns the unique value.
- `unlinked_or_orphaned`: offer has no existing linked `car` post.

## Staging sequence

1. Restore database and files from the same recovery point and keep the restored site isolated.
2. Record WordPress, PHP, MariaDB, theme, plugin, uploads, and scheduled-job state.
3. Run `migration-report` without a fallback branch. Resolve invalid identities, collisions, ambiguous branches, and workflow-blocked records.
4. Run `migrate-vehicles --dry-run` and `migrate-leads --dry-run`; compare their categories with the inventory report.
5. Freeze legacy vehicle edits during each import batch. Pilot one vehicle with `--post-id`, then import bounded batches. Vehicle, movement and audit commit together. Concurrent importer runs serialize on the source post and recheck the mapping; this does not serialize arbitrary third-party postmeta writes.
6. Rerun the report. Require zero unexplained drift/conflicts and reconcile source, imported, and intentionally excluded counts.
7. Verify media, catalog visibility, branch scope, CRM privacy export/erasure, and active workflows manually.
8. Keep legacy reads available until business sign-off. The current commands do not delete, rename, archive, or make source records read-only.

The repository cannot claim a restore rehearsal until this sequence is executed against an authorized production copy and the recovery point, duration, counts, exceptions, and approvers are recorded.

Existing mapped rows are skipped rather than overwritten. Empty new specification columns on old imports therefore appear as drift; resolve through the scoped specification editor or a separately reviewed backfill. Images, video, promotional finance estimates and sales contact details remain on the editorial post; private costs are not inferred from legacy public metadata.
