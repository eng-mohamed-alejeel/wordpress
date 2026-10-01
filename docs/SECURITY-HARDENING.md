# Public boundary security — 1.27.0

## Anonymous surface

The core plugin has three deliberately anonymous relative method/route pairs, exposed through both compatibility v1 and enveloped v2:

| Method and route | Policy | Limit |
|---|---|---|
| `GET /auto-dealership/v1|v2/branches` | `public_read` | 120 requests per 60 seconds |
| `GET /auto-dealership/v1|v2/vehicles` | `public_read` | 120 requests per 60 seconds |
| `POST /auto-dealership/v1|v2/leads` | `intake` | 8 validated attempts per 3600 seconds |

`Routes::PUBLIC_ENDPOINTS` is the canonical inventory. Isolated acceptance enumerates every registered core REST handler as an anonymous user and fails if the runtime surface differs. All other routes retain their capability, authentication, ownership, branch and state checks.

## Atomic request protection

`PublicRequestGuard` uses an InnoDB row per policy, fixed window and caller identity. `INSERT ... ON DUPLICATE KEY UPDATE` assigns the attempt atomically; the connection-local result determines whether that exact request is accepted. A storage or schema error fails closed with `adc_rate_unavailable` (503). An exceeded policy returns `adc_rate_limited` (429) with `retry_after` metadata.

Version 1.29.1 adds the `account_auth` policy for the theme-compatible sign-in and registration boundary: ten attempts per resolved caller in a 15-minute window. The same opaque atomic storage rules apply; profile and preference updates remain authenticated and nonce protected.

Version 1.29.2 places comparison changes and calculator estimates behind plugin-owned nonces and the existing `public_read` atomic policy. Comparison values are bounded to four public post IDs and finance-estimate inputs are bounded before calculation; neither endpoint creates a business record.

Caller identity is the authenticated WordPress user ID or a resolved network address. It is combined with the policy/window and stored only as an HMAC-SHA256 bucket key using the WordPress authentication salt. Raw addresses, user IDs and forwarded chains are not stored in `adc_request_limits`, returned by the API, sent to the rate-limit hook, or shown in the administrator page. Salt rotation naturally creates new opaque buckets.

The fixed window is an application safety control. Production edge/WAF limits, distributed denial-of-service controls and capacity protection remain deployment responsibilities.

## Trusted proxies

`REMOTE_ADDR` is authoritative by default. `X-Forwarded-For` is ignored unless the direct peer matches an exact address or CIDR supplied through `adc_trusted_proxy_cidrs`. At most 32 rules, 10 forwarded hops and 1024 header bytes are considered. IPv4 and IPv6 are supported. Malformed chains fall back to the direct peer.

Deployment configuration should return only the reverse proxies controlled by the operator. The restricted security page shows the number of configured rules and never their values.

Example deployment filter:

```php
add_filter( 'adc_trusted_proxy_cidrs', static fn() => array( '10.20.0.0/16', '2001:db8:1234::/48' ) );
```

Replace these documentation-only ranges with the actual proxy networks during deployment review.

## Operations

The `adc_request_limits` table keeps expired buckets for one additional day to support safe cleanup without affecting the active window. `adc_prune_request_limits` runs hourly and deletes at most 5000 expired rows per invocation. Its option stores only finish time, deleted count and a safe error code.

Users with `adc_view_audit` can open **Audit Log → Public API Security**. The page is read-only and exposes the public route inventory, policy limits, current-window aggregate bucket counts, trusted-rule count, next cleanup and last cleanup health. It never renders addresses, bucket keys or trusted network values.

The `adc_public_rate_limited` hook receives only `policy` and `retry_after`. External alerting should consume that minimized event after an approved monitoring provider is available.

## Remaining release work

- Configure and verify the actual reverse-proxy CIDRs in staging and production.
- Apply edge/WAF policies and test behavior across all intended proxy/CDN paths.
- Run production-like load and abuse testing with real cache/proxy topology.
- Complete dependency, upload, security-header and human penetration review.
- Connect approved external monitoring and define alert thresholds/ownership.
