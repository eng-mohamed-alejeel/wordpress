# Outbox operations

## Scope

Core 1.23.0 provides a durable local outbox. It accepts minimized domain references, claims due events safely across concurrent workers, retries transient failures with bounded backoff, and exposes failed events to authorized operators. It does not enable any external provider by itself.

No provider credential, endpoint, message template or customer contact value belongs in the outbox payload. Provider adapters and the domain events that feed them are enabled only after their contracts, consent rules and production configuration are approved.

## Delivery contract

- Delivery is **at least once**. Every adapter must use the event's SHA-256 `idempotency_key` when the remote provider supports replay protection.
- An identical enqueue retry returns the original row. Reusing the same logical key with changed payload returns a conflict.
- Payloads accept only: `subject_type`, `subject_id`, `branch_id`, `actor_user_id`, `recipient_type`, `recipient_id`, `state`, `version`, `correlation_id`, `locale`, and `occurred_at`.
- Email, mobile, free text, credentials, tokens, payment data and nested values are rejected.
- `payload_hash` is verified before dispatch. A mismatch fails terminally without calling an adapter.
- An adapter returns `true` on success or `WP_Error` on failure. Exception messages are never persisted; only a sanitized error code is retained.

Register an adapter during plugin bootstrap:

```php
\AutoDealership\Operations\OutboxService::register_handler(
	'sale.approved',
	static function ( array $payload, array $event ) {
		// Resolve current approved data by subject ID, enforce consent, and send
		// with $event['idempotency_key'] as the provider replay key.
		return true;
	}
);
```

Queue an event inside the owning database transaction before its commit, so the business change and local event succeed or roll back together. Remote delivery still occurs later in the worker:

```php
$result = \AutoDealership\Operations\OutboxService::enqueue(
	'sale.approved',
	array( 'subject_type'=>'sale', 'subject_id'=>$sale_id, 'branch_id'=>$branch_id ),
	'sale-approved:' . $sale_id . ':v1'
);
```

## States and retries

| State | Meaning |
|---|---|
| `pending` | Ready at `next_attempt_at` or deliberately delayed. |
| `processing` | Owned by one worker through a unique lease token. |
| `retry` | Prior transient failure; waiting for the next attempt. |
| `completed` | Adapter returned success. |
| `failed` | Five attempts were exhausted, or payload validation/integrity failed. |

Retry delays before the five-attempt terminal state are 1, 5, 15 and 60 minutes. A processing lease older than 15 minutes can be reclaimed. This allows recovery after a worker exits, while preserving at-least-once semantics.

## Scheduled operation

`adc_process_outbox` runs every five minutes through WP-Cron and processes at most 20 events per invocation. A run summary is stored in `adc_outbox_health`; it contains timestamps, counters, or a safe error code only.

Production must invoke WordPress cron reliably. Configure the platform scheduler to call `wp-cron.php` or a controlled WP-CLI cron command, then verify that the next-run timestamp and last-run summary advance.

## Operator page

Open **Audit Log → مراقبة المهام**.

- General managers and administrators have `adc_view_outbox` and `adc_manage_outbox`.
- Auditors have read-only `adc_view_outbox`.
- Other roles cannot list or retry outbox events.
- The page shows event key, state, attempts, safe error code and timestamps. It never renders payloads or idempotency keys.
- A terminal failure can be returned to `retry` only with a reason. The change and its prior error state commit with `outbox.retry_requested` in the audit log.

Before retrying, confirm that the adapter or provider incident is resolved and that replay is safe. Repeated terminal integrity or payload failures require code/data investigation rather than manual retry.

## Deployment and rollback

Schema 1.14.0 adds nullable idempotency, hash, state, lease, failure and queue indexes to the existing table. The migration is additive and preserves old rows. Take the normal backup before deployment and confirm `Schema::verify()` is empty afterward.

Deactivation clears the outbox schedule but preserves queue records. Re-enabling the plugin restores the schedule. Do not delete failed/completed rows during incident review; retention policy for operational events remains a business decision.

## Current limits

- No ERP, accounting, payment, finance, WhatsApp, email or analytics adapter is active.
- No domain service emits provider events yet.
- The administrator page is polling-based and has no external alert delivery.
- Provider timeout, signature, consent, reconciliation, rate-limit and credential-rotation rules must be defined per adapter before activation.
