# Theme adapter API

Updated: 2026-10-02 for increment 1.29.13.

## Ownership

`AutoDealership\Content\PublicShortcodes` owns registration of every durable dealership shortcode while `adc_core_public_shortcodes_enabled` is true. Themes detect that boundary through `adc_core_owns_public_shortcodes()` and provide optional HTML renderers through filters. The plugin does not load theme files or call theme functions.

The theme attaches render filters only. Disabling `adc_core_public_shortcodes_enabled` leaves those shortcodes unavailable; the theme does not register them. Restore a matching earlier release if a full deployment rollback is needed.

## Renderer contract

For a shortcode named `{tag}`, the plugin applies:

```php
apply_filters(
    "adc_shortcode_{$tag}_html",
    $fallback_html,
    $view_model,
    $normalized_attributes,
    $enclosed_content,
    $tag
);
```

A renderer must return a string and must treat the view model as read-only. The plugin keeps its safe fallback when a renderer returns another type. Renderers must not perform business writes or register a second shortcode callback.

## Durable names

- `car_dealer_cars`
- `car_dealer_loan_calculator`
- `car_dealer_comparison`
- `car_dealer_testimonials`
- `car_dealer_contact_form`
- `ab_hero_section`
- `ab_mission_vision`
- `ab_stats_section`
- `ab_values_section`
- `ab_team_section`
- `ab_cta_section`
- `ab_testimonials_section`
- `ab_contact_hero`
- `ab_contact_grid`
- `ab_contact_map`
- `ab_contact_form_section`
- `ab_faq_section`
- `ab_social_section`

## View models

| Shortcode | Model |
|---|---|
| `car_dealer_cars` | Ordered eligible `post_ids`, bounded `count`, normalized `featured` value. The plugin owns the query and authoritative catalog flags. |
| `car_dealer_comparison` | Ordered eligible `post_ids` and the comparison `limit`. Cookie decoding and eligibility remain plugin decisions. |
| `car_dealer_loan_calculator` | Validated defaults and bounds: `price`, `down_payment`, `annual_rate`, `months`, `max_amount`, `max_months`, `max_rate`. |
| `car_dealer_contact_form` | Intake availability, authenticated state, current account display identity, account URL and request language. The server still ignores submitted identity fields for authenticated users. |
| `car_dealer_testimonials` | Bounded requested `count`. No testimonial records are invented by the plugin. |
| `ab_*` presentation sections | Stable `tag` only. Brand copy and section markup remain theme presentation. |

## Behavior without `car-dealer`

- Cars and comparison render neutral linked vehicle cards from plugin-selected post IDs.
- The finance calculator renders a neutral form backed by the plugin calculator endpoint.
- The contact shortcode renders a neutral form backed by the plugin intake endpoint.
- Testimonial and `ab_*` presentation sections render an empty string without a theme adapter because no approved theme-independent content repository exists. With `car-dealer`, appearance sections render through theme filters; both testimonial renderers remain empty until approved customer content is supplied.
- `assets/js/public-intake.js` owns contact, booking and newsletter submission when public intake is enabled. It supplies the existing nonce/action contract, retains the idempotency key across retries, and prevents duplicate theme submission handlers.

This API creates no pages, posts, customers, leads or sample content during registration or rendering. Business records are created only after a valid public form submission through the existing plugin services.

## Catalog language and structured data (1.29.8)

The theme calls `adc_catalog_language()`, `adc_catalog_localized_url( $url, $language )` and `adc_catalog_language_url( $language )` for its visual language switch and links. Arabic is the URL default; `?lang=en` selects English. The plugin preserves only the allowlisted archive filters when switching language.

`CatalogPresentation` owns `wp_robots`, vehicle canonical URLs and catalog canonical/alternate links. The theme retains its translated copy and markup but suppresses its former SEO hooks while the plugin is active. `PublicStructuredData` prints Organization JSON-LD on ordinary pages, eligible Vehicle JSON-LD on published cars, and Vehicle/Offer JSON-LD for published, unexpired linked offers with a positive price. The theme's former schema file is outside the active load chain. Missing prices and unavailable vehicles do not produce in-stock offer claims.

The theme has no catalog SEO or JSON-LD emitter. Disabling either plugin feature does not reactivate a theme implementation.

## Offer and account request models (1.29.9)

`adc_public_offer_view( $post_id )` returns `null` unless the offer is published, has a positive whole-SAR price, has a valid nonexpired date when one is set, and links to a publicly eligible car. Otherwise it returns a read-only model containing the offer and car IDs/titles, public URL, current/old/monthly prices, currency, expiry and image URL. The active theme renders cards and details from this model; the plugin returns 404 for invalid public offer details. Authorized WordPress previews retain their editorial behavior. Since 1.29.10, the plugin filters the main public `car_offer` archive query using the same price, expiry and linked-car visibility rules before WordPress calculates totals or pagination. Disabling the early offer ownership flag restores the theme's compatibility archive query.

`adc_customer_request_page( $type, $page )` accepts only `messages` or `bookings` for the signed-in account, selects at most 11 projection rows and returns at most 10 display items with `page` and `has_more`. It returns `WP_Error` if the schema or retained store is unavailable. Each item includes the displayed status, timestamps, customer reply, safe vehicle link where public, and `can_cancel` only for a pending/confirmed booking linked to that same account's active core customer. The theme renders the table and submits cancellation through the existing plugin account action.

The theme does not read offer or account history tables directly. If a plugin read model is unavailable, the theme displays an unavailable state. No model method inserts or changes business records.

Since 1.29.13, the active account file retains routing, navigation and rendering. It calls `adc_customer_account_process()`, `adc_customer_account_kind()`, `adc_customer_workspace_targets()` and `adc_customer_request_page()`. It has no account writer or SQL fallback.

The active contact file keeps form rendering. Submission uses plugin assets and handlers; unavailable plugin services produce a visible message instead of an active form. Old engagement, CRM and workflow files are archived outside the theme and are never loaded.

`functions.php` keeps car cards, visual utilities and shortcode renderers. It no longer loads content registration, vehicle editors or AJAX handlers. Its JavaScript contains only menu and back-to-top behavior.

`adc_customer_account_kind( WP_User $user )` and `adc_customer_account_should_redirect_admin( WP_User $user )` supply account decisions. `adc_public_vehicle_view( $post_id, $preview )` supplies mapped and legacy editorial display fields; `adc_public_offer_preview( $post_id )` supplies authorized draft preview fields. `adc_home_page_view()` supplies bounded eligible IDs and the published-car count. `adc_catalog_filter_options()` supplies choices for both compatibility and authoritative catalog modes. The theme renders these models and does not read dealership post metadata or tables directly. Theme archive links resolve only while the plugin-owned content type exists; empty inventory and offer sections do not render on the home page.
