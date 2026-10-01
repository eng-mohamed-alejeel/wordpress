# Verification record — 1.26.0

Date: 2026-09-30

## Result

The full isolated database and HTTP suite completed **640 checks** successfully against a dedicated MariaDB 10.4 instance on `127.0.0.1:33327`. The runner removed its generated database and reported that the source database was not contacted.

The 19 new checks verify:

- additive schema 1.15.0 installation while preserving existing outbox rows;
- strict provider reference validation;
- complete and incomplete safe readiness reports;
- activation authorization and refusal before readiness;
- runtime rejection after direct activation-option tampering;
- audited activation and exact option compensation when audit persistence fails;
- independent activation per event;
- durable structured pending receipts before outbox completion;
- denial of receipt inspection and reconciliation to sales staff;
- audited asynchronous reconciliation and accepted-state persistence;
- retention of a verified acknowledgement that arrives before delivery;
- later linking without downgrading the final state;
- idempotent duplicate acknowledgement handling;
- mismatch visibility for contradictory final acknowledgements;
- masked operator output without the full provider reference;
- protected integration workspace output;
- audited independent route disablement.

The implementation adds no provider adapter, endpoint, credential, external request or source business/reference/sample record. All event routes remain disabled. Schema 1.15.0 adds only the provider acknowledgement ledger.

## Remaining boundary

Static regression covers 101 plugin PHP files, 41 theme PHP files, 8 JavaScript/CommonJS files, 48 authorization checks, 12 money checks and 16 pricing-policy checks. Existing browser evidence remains valid for unchanged journeys. Each real provider still requires its approved data and consent policy, transport/authentication implementation, signature algorithm, sandbox credentials, outage and rotation exercises, production scheduler confirmation and external alert channel.
