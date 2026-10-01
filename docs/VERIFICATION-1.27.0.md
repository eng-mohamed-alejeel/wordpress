# Verification — 1.27.0

Date: 2026-10-01  
Result: local implementation acceptance **PASS**; production readiness remains **FAIL**.

## Scope

Version 1.27.0 adds an explicit anonymous REST inventory, atomic database-backed request limits, privacy-preserving caller buckets, trusted-proxy parsing, bounded cleanup and a restricted operational security page. Schema 1.16.0 adds only `adc_request_limits`. No business/reference/sample data, provider configuration, credentials or outbound calls were added.

## Results

- **654** isolated database and HTTP checks passed on a dedicated disposable MariaDB server at `127.0.0.1:33337`.
- The 13 focused checks cover additive upgrade preservation, default forwarded-header rejection, trusted IPv4/CIDR resolution, malformed-chain fallback, raw-address minimization, the 8/9 boundary, separate authenticated/anonymous buckets, fail-closed SQL behavior, cleanup health, exact runtime anonymous-route inventory, minimized admin output, scheduled handler registration and a 12-process atomic race.
- The race accepted exactly 8 requests, rejected 4 with `adc_rate_limited`, and persisted all 12 attempts.
- PHP syntax passed across **105 plugin files** and **41 theme files**.
- **48** offline authorization checks, **12** money checks and **16** pricing-policy checks passed.
- JavaScript/CommonJS syntax passed across **8 files**.
- `git diff --check` reported no whitespace errors.

The disposable database was dropped automatically. The listener/process and verified temporary directory were removed. The intentionally empty source database was not contacted. XAMPP MariaDB emitted a shutdown-only minidump after the successful suite and database removal; no test failed and no listener remained.

## Security assertions

- The runtime anonymous surface is exactly `GET /branches`, `GET /vehicles` and `POST /leads` under the versioned namespace.
- The first two routes consume `public_read`; validated lead intake consumes `intake` immediately before persistence.
- Every other registered core method/route denied an anonymous permission check.
- Stored rate rows contain an opaque 64-character HMAC key and no raw client address.
- Forwarded headers cannot influence identity until the direct peer is explicitly trusted.
- Database unavailability cannot silently bypass request protection.
- Operations output is aggregate-only and capability-restricted.

## Limits

This is local application acceptance. Edge/WAF configuration, actual proxy networks, production-like load, external monitoring, provider execution, human accessibility and a broader manual security review remain release gates. The source schema remains intentionally unapplied until deployment preparation.
