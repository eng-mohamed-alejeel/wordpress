# Integration Architecture

Integrations are adapters behind domain interfaces, with credentials supplied by deployment configuration. Core 1.23.0 implements the local durable outbox, hashed idempotency, bounded backoff, leases, terminal failures and restricted operator visibility. See `OUTBOX-OPERATIONS.md`.

Adapters must resolve approved current data from minimized subject references, enforce consent, use the supplied replay key, and return only safe error codes. Define timeout, signature verification, replay protection, rate limits, credential rotation and manual reconciliation for each provider before registering its handler or producing its events.

Planned adapters: ERP/accounting, finance providers, payment gateway token/reference only, WhatsApp/notifications, analytics/BI and public API clients. No provider credentials, domain-event producers or active external integrations are included in 1.23.0.
