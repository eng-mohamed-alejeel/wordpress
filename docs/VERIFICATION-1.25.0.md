# Verification record — 1.25.0

Date: 2026-09-30

## Result

The full isolated database and HTTP suite completed **621 checks** successfully against a dedicated loopback MariaDB instance on port 33317. The runner removed its generated database and reported that the source database was not contacted.

Static verification also passed across **94 plugin PHP files**, **41 theme PHP files** and **8 JavaScript/CommonJS files**, plus **48 branch-authorization**, **12 money** and **16 pricing-policy** checks.

The 11 new integration checks verify:

- rejection of events outside the approved catalogue;
- event/state validation;
- default-disabled publication with no queue mutation;
- fail-closed activation when no adapter owns the route;
- valid adapter registration and single-owner route enforcement;
- durable deterministic replay of the same domain version;
- minimized payloads without identity or financial values;
- dispatch through the existing outbox worker;
- transactional event creation from the reservation service;
- rollback of reservation, inventory state and audit when enabled outbox persistence fails.

No provider adapter, endpoint, credential, external call or sample record was added to the source installation. Schema remains 1.14.0.

## Remaining boundary

This verifies the provider-neutral runtime contract and producer/outbox transaction boundary with an in-memory acceptance adapter. Each real provider still needs approved data/consent rules, staging connectivity, timeout and authentication tests, remote idempotency, acknowledgement reconciliation, credential rotation and operational alerting.
