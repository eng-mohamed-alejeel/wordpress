# Integration contracts and domain events

Version 1.25.0 adds a provider-neutral adapter contract and transactional domain-event producers. It does not configure a provider, store credentials, enable a route or send an external request.

## Safety model

- Every event is disabled by default.
- An event is published only when a provider plugin registers one adapter for that event and explicitly enables it through `adc_integration_event_enabled`.
- Activation without a registered route fails closed. When publication is part of a business transaction, the business mutation, audit records and outbox row roll back together.
- Outbox payloads contain only subject type/ID, branch ID, state, version and the acting user ID. Customer identity, contact values, VIN, references, amounts, credentials and message content are absent.
- Remote work still runs asynchronously through the durable at-least-once outbox. The business transaction never waits on a provider request.

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

Each producer executes inside its owning database transaction. Its deterministic replay identity is derived from the event key, subject ID, state and version.

## Provider plugin contract

A provider plugin implements `AutoDealership\Integrations\AdapterContract`, then registers during `adc_integrations_register`:

```php
add_action( 'adc_integrations_register', static function (): void {
	$adapter = new AcmeDealershipAdapter();
	\AutoDealership\Integrations\IntegrationRegistry::register( $adapter );
} );
```

The adapter supplies a stable `id()`, an allowlisted `events()` array, and `deliver()`. `deliver()` returns `true` after remote acceptance or `WP_Error` with a safe machine code. It must use the stored outbox idempotency hash as the remote replay key when supported. Exception messages, remote response bodies and secrets must not be persisted.

After configuration and operational approval, the provider plugin enables only its intended events:

```php
add_filter( 'adc_integration_event_enabled', static function ( $enabled, $event_key, $payload ) {
	return 'sale.approved' === $event_key ? true : $enabled;
}, 10, 3 );
```

The provider plugin owns endpoint validation, authentication, credential rotation, TLS policy, timeouts, consent/template checks, rate limits, response verification and provider-specific reconciliation. It resolves the minimum current data by the subject ID immediately before delivery.

## Activation gate

Before enabling one event:

1. Approve the provider contract, data fields, purpose, retention and consent/template policy.
2. Store credentials outside the outbox and confirm access restrictions and rotation.
3. Implement bounded timeouts, safe error codes and remote idempotency.
4. Define provider acknowledgement semantics and reconciliation queries.
5. Exercise success, retry, permanent rejection, duplicate delivery, credential failure and provider outage in staging.
6. Enable one route, monitor the outbox, and reconcile local subjects against provider acknowledgements before expanding scope.

Provider adapters, production credentials, external alerts and reconciliation implementations remain pending until actual provider contracts are supplied.
