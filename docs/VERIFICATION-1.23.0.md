# Verification 1.23.0

Date: 2026-09-30

## Result

The durable outbox, bounded retries, concurrent worker lease and restricted operations monitor passed on a dedicated MariaDB 10.4 server at `127.0.0.1:33317` with its own temporary data directory. The runner created and removed only random `adc_verify_<hex>` databases. The intentionally empty source database was never contacted.

## Passing evidence

- **597 isolated database/HTTP checks** passed.
- The 21 new assertions cover additive migration with row preservation, event/payload/key validation, minimized storage, hashed replay and conflict handling, delayed delivery, success idempotency, bounded terminal failure, safe error retention, malformed payload rejection, hash-integrity rejection, expired-lease recovery, role separation, audited retry and rollback, payload-free administration output, two-process claiming, and five-minute worker wiring.
- The concurrency case starts two independent PHP/WordPress processes and proves that one due event receives one lease, one adapter invocation and one completed attempt.
- Schema 1.14.0 verified its columns, defaults, unique idempotency key, queue indexes and InnoDB engine after a simulated upgrade from the prior outbox shape.
- External HTTP and email remained blocked. No provider adapter or real notification was invoked.
- PHP syntax passed across **87 plugin files and 41 theme files**. Node syntax passed across **8 JavaScript/CommonJS files**.
- The dependency-free regressions passed **48 authorization checks**, **12 money checks**, and **16 pricing-policy checks**.
- `git diff --check` reported no whitespace errors; its messages were expected LF-to-CRLF working-copy notices.

## Reproduction

Provision a dedicated empty MariaDB instance on a nonproduction port with its own data directory, then run:

```powershell
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
```

The runner rejects port 3306, refuses a server containing application databases, and removes its generated database in `finally`.

## Remaining release evidence

- Define and approve each provider contract, credentials, consent policy, timeout, signature, replay, reconciliation and rate-limit behavior before registering an adapter or producing its domain events.
- Configure a reliable production scheduler and external alerting for stale job timestamps and terminal failures.
- Rehearse operator diagnosis/retry and provider outage recovery in staging.
- Continue the real business setup, catalog mapping, controlled cutover and full release gate. Compatibility mode remains selected while the operational database is intentionally empty.
