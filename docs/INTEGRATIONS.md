# Integration Architecture

Integrations are adapters behind domain interfaces, with credentials supplied by deployment configuration. Persist outbound events in a future outbox table and retry idempotently with bounded backoff; store delivery status and correlation IDs without logging secrets or unnecessary customer payloads. Define timeout, signature verification, replay protection and manual reconciliation for each provider.

Planned adapters: ERP/accounting, finance providers, payment gateway token/reference only, WhatsApp/notifications, analytics/BI and public API clients. No provider credentials or active integrations are included in the initial foundation.
