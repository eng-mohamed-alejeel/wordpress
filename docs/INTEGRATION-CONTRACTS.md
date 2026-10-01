# Integration contracts, activation and reconciliation

Version 1.26.0 completes the provider-neutral runtime foundation. It does not configure a provider, store credentials, enable a route or send an external request.

## Safety model

- Every event is disabled by default.
- Provider code registers routes but cannot enable them. A general manager or administrator must enable each event through the audited central gate.
- Activation requires all safe readiness checks: credentials, endpoint, authentication, timeouts, idempotency, acknowledgements and reconciliation.
- Runtime publication repeats the route and readiness checks. A directly altered WordPress option cannot bypass an incomplete adapter.
- The filter `adc_integration_event_enabled` may veto an enabled route for provider maintenance or policy. It cannot enable a disabled route.
- Outbox payloads contain only subject type/ID, branch ID, state, version and optional actor ID. Customer identity, contact values, VIN, amounts, credentials and message content are absent.
- Remote work and provider polling run asynchronously through the durable outbox. Business and administrator transactions never wait for a provider request.

## Event catalogue

| Event | Subject | State | Producer |
|---|---|---|---|
| `reservation.confirmed` | reservation | confirmed | Reservation creation |
| `sale.approved` | sale | approved | Separated sale approval |
| `finance.submitted` | finance request | submitted | Consented finance request |
| `finance.under_review` | finance request | under_review | Finance review transition |
| `finance.approved` | finance request | approved | Finance decision |
| `finance.rejected` | finance request | rejected | Finance decision |
| `payment.verified` | payment | verified | Independent receipt verification |
| `delivery.released` | delivery | delivered | Final controlled release |

Each producer executes inside its owning database transaction. Its replay identity is derived from event key, subject ID, state and version.

## Provider adapter

A provider plugin implements `AdapterContract` and `AdapterReadinessContract`. It implements `ReconciliationContract` when the provider supports status polling.

```php
final class ProviderAdapter implements
	\AutoDealership\Integrations\AdapterContract,
	\AutoDealership\Integrations\AdapterReadinessContract,
	\AutoDealership\Integrations\ReconciliationContract {

	public function id(): string { return 'approved-provider'; }
	public function events(): array { return array( 'finance.submitted' ); }
	public function environment(): string { return 'sandbox'; }

	public function readiness_checks(): array {
		return array(
			'credentials'=>true, 'endpoint'=>true, 'authentication'=>true,
			'timeouts'=>true, 'idempotency'=>true,
			'acknowledgements'=>true, 'reconciliation'=>true,
		);
	}

	public function deliver( string $event_key, array $payload, array $event ) {
		// Resolve the minimum current data, enforce consent, send with the stored
		// idempotency hash, verify the response, then return its opaque reference.
		return \AutoDealership\Integrations\ProviderResult::pending( 'REMOTE-123' );
	}

	public function reconcile( string $event_key, string $remote_reference, array $receipt ) {
		return \AutoDealership\Integrations\ProviderResult::accepted( $remote_reference );
	}
}

add_action( 'adc_integrations_register', static function (): void {
	\AutoDealership\Integrations\IntegrationRegistry::register( new ProviderAdapter() );
} );
```

Readiness methods return booleans only. They must not return secret names, values, endpoints, tokens or remote response text. Credentials remain in environment variables, a deployment secret manager or another reviewed protected configuration owned by the provider plugin.

## Structured results and acknowledgements

`deliver()` and `reconcile()` return one of:

- `ProviderResult::pending( $reference, $safe_code )` when the provider accepted processing but has no final decision;
- `ProviderResult::accepted( $reference, $safe_code )` for a final acceptance;
- `ProviderResult::rejected( $reference, $safe_code )` for a final business rejection;
- `WP_Error` with a safe machine code for a transient or technical failure that the outbox may retry.

Schema 1.15.0 stores the necessary opaque reference in `adc_integration_receipts` for polling and keeps a SHA-256 fingerprint for operator display. The full reference, credentials, payload and remote response body are never rendered in the readiness workspace.

An adapter receiving a webhook must verify its TLS/authentication/signature/timestamp/replay rules first, then call:

```php
\AutoDealership\Integrations\AcknowledgementService::receive_acknowledgement(
	'approved-provider',
	'finance.submitted',
	\AutoDealership\Integrations\ProviderResult::accepted( $verified_reference )
);
```

An early verified acknowledgement is retained and linked when its matching delivery appears. Exact duplicates are idempotent. Contradictory final states become `mismatch` and require investigation; the system does not silently choose one.

## Activation and operations

Open **Audit Log → جاهزية التكاملات**.

- Auditors may view route and acknowledgement metadata.
- General managers and administrators may enable/disable a ready route and request reconciliation.
- Every activation change and reconciliation request requires a reason and an audit record.
- Reconciliation requests are queued as `integration.reconcile` and executed by the existing five-minute worker.
- The screen shows scheduler health, safe machine errors and masked reference fingerprints only.

Safe extension hooks include `adc_outbox_failed` for terminal queue failures, `adc_integration_acknowledgement_changed` for safe receipt-state metadata and `adc_integration_event_enabled` for a final provider-owned veto. No external alert destination is configured by Core.

## Activation gate

Before enabling one event:

1. Approve the provider contract, purpose, minimum fields, retention and consent/template policy.
2. Store credentials outside source control and confirm access restrictions and rotation.
3. Implement bounded timeouts, TLS/authentication, safe errors and remote idempotency.
4. Implement and test response verification, webhook signature/replay protection and reconciliation.
5. Exercise success, pending, final acceptance, rejection, duplicate callback, contradictory callback, credential failure and outage in staging.
6. Enable one sandbox route, monitor the outbox and receipt ledger, then reconcile local subjects against provider records before production activation.

Provider adapters, production credentials, signature algorithms, consent/templates, staging execution and external alert channels remain pending until actual provider contracts are supplied.
