<?php
define( 'ABSPATH', __DIR__ . '/' );

$adc_options = array();
function get_option( string $key, $default = false ) { global $adc_options; return $adc_options[ $key ] ?? $default; }
function get_bloginfo( string $field = '' ): string { return 'Synthetic Dealer'; }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function __( string $text, string $domain = '' ): string { return $text; }

class WP_Error {
	public function __construct( private string $code ) {}
	public function get_error_code(): string { return $this->code; }
}

require_once dirname( __DIR__ ) . '/src/Pricing/Money.php';
require_once dirname( __DIR__ ) . '/src/Pricing/PricingPolicy.php';

use AutoDealership\Pricing\PricingPolicy;

$checks = 0;
$check = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
};
$throws = static function ( callable $callback ): bool {
	try { $callback(); } catch ( InvalidArgumentException | OverflowException $error ) { return true; }
	return false;
};

$adc_options = array(
	'adc_pricing_fee_amount' => 2500,
	'adc_promotion_code' => 'launch-26',
	'adc_promotion_type' => 'fixed',
	'adc_promotion_value' => 1000,
	'adc_promotion_starts_at' => gmdate( 'Y-m-d', time() - 86400 ),
	'adc_promotion_ends_at' => gmdate( 'Y-m-d', time() + 86400 ),
);
$quote = PricingPolicy::quote( 100000, 500, 1500, 'LAUNCH-26' );
$check( 2500 === $quote['fee_amount'] && 1000 === $quote['promotion_amount'], 'Fee and active fixed promotion are applied in minor units.' );
$check( 101000 === $quote['subtotal_amount'] && 15150 === $quote['tax_amount'] && 116150 === $quote['final_amount'], 'Promotion, discount and tax calculation order is deterministic.' );
$check( 'launch-26' === $quote['promotion_code'], 'Promotion code is normalized in the quote snapshot.' );

$snapshot = array( 'fee_amount'=>2500, 'promotion_code'=>'launch-26', 'promotion_amount'=>1000 );
$adc_options['adc_pricing_fee_amount'] = 999999;
$adc_options['adc_promotion_value'] = 999999;
$check( $quote === PricingPolicy::quote( 100000, 500, 1500, '', $snapshot ), 'Frozen quote inputs ignore later policy changes.' );
$check( $throws( static fn()=>PricingPolicy::quote( 100000, 0, 1500, 'missing' ) ), 'Unknown promotion codes are rejected.' );
$adc_options['adc_promotion_code'] = 'expired';
$adc_options['adc_promotion_type'] = 'fixed';
$adc_options['adc_promotion_value'] = 1000;
$adc_options['adc_promotion_ends_at'] = gmdate( 'Y-m-d', time() - 86400 );
$check( $throws( static fn()=>PricingPolicy::quote( 100000, 0, 1500, 'expired' ) ), 'Expired promotions are rejected.' );
$adc_options['adc_promotion_code'] = 'percent';
$adc_options['adc_promotion_type'] = 'percentage';
$adc_options['adc_promotion_value'] = 1250;
$adc_options['adc_promotion_starts_at'] = '';
$adc_options['adc_promotion_ends_at'] = '';
$adc_options['adc_pricing_fee_amount'] = 0;
$check( 1250 === PricingPolicy::quote( 9999, 0, 0, 'percent' )['promotion_amount'], 'Percentage promotions round half up.' );

$adc_options['adc_sales_manager_discount_limit'] = 1000;
$adc_options['adc_general_manager_discount_limit'] = 5000;
$check( 'sales_manager' === PricingPolicy::discount_tier( 1000 )['tier'], 'Discount at the branch-manager ceiling uses that tier.' );
$check( 'general_manager' === PricingPolicy::discount_tier( 1001 )['tier'], 'Discount above the branch-manager ceiling uses the general-manager tier.' );
$check( PricingPolicy::discount_tier( 5001 ) instanceof WP_Error, 'Discount above the general-manager ceiling is blocked.' );

$adc_options['adc_reservation_deposit_type'] = 'none';
$adc_options['adc_reservation_deposit_value'] = 0;
$check( 0 === PricingPolicy::reservation_deposit( 100000 )['required_amount'], 'No-deposit policy freezes a zero requirement.' );
$adc_options['adc_reservation_deposit_type'] = 'fixed';
$adc_options['adc_reservation_deposit_value'] = 25000;
$check( 25000 === PricingPolicy::reservation_deposit( 100000 )['required_amount'], 'Fixed reservation deposit is returned exactly.' );
$adc_options['adc_reservation_deposit_type'] = 'percentage';
$adc_options['adc_reservation_deposit_value'] = 1000;
$check( 1000 === PricingPolicy::reservation_deposit( 9999 )['required_amount'], 'Percentage reservation deposit rounds half up.' );
$adc_options['adc_reservation_deposit_type'] = 'fixed';
$adc_options['adc_reservation_deposit_value'] = 100000;
$check( $throws( static fn()=>PricingPolicy::reservation_deposit( 100000 ) ), 'A deposit cannot consume the full vehicle price.' );

$adc_options['adc_seller_name'] = ' Dealer <b>One</b> ';
$adc_options['adc_seller_tax_number'] = ' TAX-123 ';
$adc_options['adc_seller_address'] = " Riyadh <script>x</script> ";
$adc_options['adc_seller_phone'] = ' +966 50 000 0000 ';
$seller = PricingPolicy::seller_snapshot();
$check( 'Dealer One' === $seller['seller_name'] && 'TAX-123' === $seller['seller_tax_number'], 'Seller identity snapshot is sanitized.' );
$check( ! str_contains( $seller['seller_address'], '<script>' ) && '+966 50 000 0000' === $seller['seller_phone'], 'Seller contact snapshot removes markup and surrounding whitespace.' );

echo "PASS: $checks pricing policy checks.\n";
