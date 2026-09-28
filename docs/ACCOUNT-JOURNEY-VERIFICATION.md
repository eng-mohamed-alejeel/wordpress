# Account journey and concurrency acceptance — 2026-09-28

## Scope

Follow-up to `VERIFICATION-1.17.0.md`: exercise the real customer account and vehicle pages, complete the listed mixed CRM races and legacy request pagination/cursor checks, and review Arabic responsive account rendering. Core remains 1.17.0 / schema 1.12.0; this increment changes theme integration and verification, with no new database migration.

## Repairs

- The actual account page failed after login because `car_dealer_request_statuses()` was unavailable. The theme now loads the existing CRM and customer workflow dependencies.
- Theme bootstrap functions shadowed the compatible price/booking forms with non-form containers. Both entry points now call shared renderers with the existing AJAX actions, validated identity fields, booking date/time and replay script support.
- Authenticated form data was localized to an unregistered JavaScript handle. It now targets the script actually enqueued by the theme.
- Public forms declare POST so an early submission before JavaScript attaches does not place contact values in the URL. AJAX remains the supported submission flow.
- Arabic account content declares `lang="ar"`; contact email/phone fields use LTR. Request tables have scoped column headings and labelled, keyboard-focusable horizontal scroll regions. Narrow-screen headings, spacing and action wrapping keep account content readable.
- The existing reconciliation callback is now a named function with the same `admin_init` hook and capability guard, allowing direct failure/cursor verification without dispatching unrelated admin hooks.

The user's existing footer, page content and main JavaScript edits were preserved. No source WordPress bootstrap, source database connection, real account change or production sample-data insertion was performed.

## Database and HTTP acceptance added

- Quote creation versus customer merge, using independent connections and both worker launch orders: the source either remains active with its quotation or becomes a tombstone without a new quotation. One conflicting operation is rejected.
- Account intake versus privacy erasure, using both worker launch orders: old canonical identity, compatibility copies and replay fingerprints remain erased. A later explicit enquiry may create a new profile; there is at most one current account-linked profile.
- Date-only/time-only rescheduling preserves the omitted appointment field. A vehicle mapped to another branch blocks confirmation.
- Customer pagination uses authenticated account IDs and a ten-row page plus sentinel; staff branch membership does not grant customer-account ownership.
- Reconciliation advances over core-owned requests without duplicate legacy profiles, and lookup failure leaves its cursor unchanged for retry.
- Actual staff request renderer crosses the twenty-row boundary with scoped counts, next-page links and unassigned-row exclusion.
- After the browser journey, database assertions verify one canonical customer and no duplicate legacy CRM profile from login/profile changes.

## Real browser journey

The test boots the actual `car-dealer` theme in a guarded disposable WordPress installation. It uses synthetic credentials passed on stdin, a temporary Edge profile and loopback HTTP. The customer visits actual account and vehicle detail pages with their real CSS/JavaScript and submits forms using browser button input.

Covered journey: anonymous redirect → essential-cookie choice → customer login → contact enquiry → owned request display → profile name/phone update → subsequent enquiry refreshes CRM identity → vehicle price enquiry → test-drive booking → account cancellation → staff reply → customer reads reply → second customer registration and ownership isolation.

Staff reply uses the production `RequestPage` renderer on a test-only administrator-protected wrapper, then the production admin-post handler. This does not claim verification of all WordPress admin navigation. Account registration/login, customer forms, vehicle template and customer cancellation use their actual theme entry points.

Responsive checks cover 1440, 768, 390 and 320 pixels; phone widths use Chromium mobile emulation. Assertions cover document overflow, RTL content, LTR contact values, labelled controls, keyboard table scrolling, mobile menu state, local CSS/JS loading and uncaught JavaScript errors. Screenshots are under `artifacts/account-journey/`.

## Reproduction

Provision an empty dedicated loopback MariaDB instance using its own data directory and a nonproduction port. Then:

```powershell
$env:ADC_BROWSER_JOURNEY = '1'
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
Remove-Item Env:\ADC_BROWSER_JOURNEY
```

For the account browser journey alone on a newly generated test database:

```powershell
$env:ADC_JOURNEY_ONLY = '1'
& C:/xampp/php/php.exe wp-content/plugins/auto-dealership-core/tests/database-runner.php --isolated 33317
Remove-Item Env:\ADC_JOURNEY_ONLY
```

`ADC_NODE` and `ADC_BROWSER` optionally specify Node and Edge/Chrome executables. Defaults match this Windows workstation. Use Node with built-in `fetch` and `WebSocket`. The theme fixture is active only for the test HTTP process, the generated database name and `ADC_THEME_TEST=1`; it does not alter the source site's active theme. The runner drops its generated database and stops its HTTP server. Stop the dedicated MariaDB instance after use.

## Limits and subsequent work

This closes the listed local account journey, responsive and targeted concurrency acceptance. It does not establish every interleaving, physical mobile device behavior, a full WCAG audit, translated English journeys, all theme catalog/admin pages, load behavior or provider integrations. External requests are blocked; Google fonts and external emoji images are not used as evidence for deployed typography. Native WordPress password recovery/email delivery and logout handling remain outside this custom account workflow run.

Next implementation areas remain consent/preferences and immediate profile synchronization, applicable legacy profile retirement/access, business pricing/deposit policies, branded documents, complete bilingual catalog and integrations/operations. Overall production readiness remains FAIL; reference-data setup still requires real business inputs, and historical import is not required for the intentionally empty deployment.
