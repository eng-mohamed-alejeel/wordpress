<?php
/** Read-only local integration checks; synthetic quotation, no customer records. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! in_array( wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Local verification only.' ); }
$checks = 0;
$check = static function ( bool $condition, string $message ) use ( &$checks ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$checks;
};
\AutoDealership\Core\BrandIdentity::tokens();
\AutoDealership\Core\BrandIdentity::tokens();
$check( '' === wp_get_attachment_image( 0, array( 60, 60 ) ), 'A missing brand attachment returns empty markup without a fatal error.' );
$styles = wp_styles();
$check( count( $styles->get_data( 'autobrands-tokens', 'after' ) ) === 1, 'Repeated enqueue must not duplicate dynamic colors.' );
$options = car_dealer_theme_options();
$resolved = \AutoDealership\Core\BrandPalette::resolve();
$check( strtoupper( $options['primary_color'] ) === $resolved['brand-navy'] && strtoupper( $options['accent_color'] ) === $resolved['brand-blue'], 'Legacy settings match the central palette.' );
$palette = \AutoDealership\Core\BrandIdentity::editor_palette( array() );
$check( count( $palette['colors'] ) >= count( \AutoDealership\Core\BrandPalette::schema() ), 'Editor exposes the full semantic palette and compatibility slugs.' );
$invalid = car_dealer_sanitize_theme_settings( array( 'primary_color' => '</style><script>', 'accent_color' => 'red' ) );
$check( '#0E2A4A' === $invalid['primary_color'] && '#2563EB' === $invalid['accent_color'], 'Invalid color input falls back to brand defaults.' );
$quote = array( 'quote_number'=>'BRAND-TEST', 'version'=>1, 'tax_rate_bps'=>1500, 'seller_name'=>'AUTO BRANDS', 'seller_address'=>'', 'seller_phone'=>'', 'valid_until'=>'2026-12-31', 'customer_name'=>'<script>test</script>', 'vehicle_description'=>'Sample vehicle', 'vehicle_stock_number'=>'TEST', 'base_amount'=>10000000, 'discount_amount'=>0, 'tax_amount'=>1500000, 'final_amount'=>11500000 );
$html = \AutoDealership\Pricing\QuoteDocument::render( $quote );
$check( str_contains( $html, '--ab-brand-navy:' . $resolved['brand-navy'] ), 'Printable quote embeds the published brand palette.' );
$check( str_contains( $html, 'color:var(--ab-text)' ), 'Printable quote consumes the semantic palette.' );
$check( ! str_contains( $html, '<script>test</script>' ) && str_contains( $html, '&lt;script&gt;test&lt;/script&gt;' ), 'Quotation values remain escaped.' );
$check( ! str_contains( $html, 'brand-appearance.js' ), 'Documents have deterministic light colors independent of browser preference.' );
echo "BRAND INTEGRATION PASS: $checks checks.\n";
