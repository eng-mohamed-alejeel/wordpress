<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Deterministic fee, promotion, tax, discount-tier and deposit policy calculations. */
final class PricingPolicy {
	public static function quote( int $base, int $discount, int $tax_rate_bps, string $promotion_code = '', ?array $snapshot = null ): array {
		$fee = null === $snapshot ? Money::parse( get_option( 'adc_pricing_fee_amount', 0 ) ) : Money::parse( $snapshot['fee_amount'] ?? null );
		$promotion = null === $snapshot ? self::promotion( $base, $promotion_code ) : array(
			'code'=>sanitize_key( $snapshot['promotion_code'] ?? '' ),
			'amount'=>Money::parse( $snapshot['promotion_amount'] ?? null ),
		);
		if ( $base < 1 || $discount < 0 || $tax_rate_bps < 0 || $tax_rate_bps > 10000 || null === $fee || null === $promotion['amount'] ) {
			throw new \InvalidArgumentException( 'Invalid pricing policy amounts.' );
		}
		if ( $base > PHP_INT_MAX - $fee ) { throw new \OverflowException( 'Fee exceeds the integer amount limit.' ); }
		$gross = $base + $fee;
		if ( $promotion['amount'] >= $gross || $discount >= $gross - $promotion['amount'] ) {
			throw new \InvalidArgumentException( 'Promotion or discount consumes the quote value.' );
		}
		$subtotal = $gross - $promotion['amount'] - $discount;
		$tax = self::ratio( $subtotal, $tax_rate_bps );
		if ( $subtotal > PHP_INT_MAX - $tax ) { throw new \OverflowException( 'Quote total exceeds the integer amount limit.' ); }
		return array(
			'base_amount'=>$base,
			'fee_amount'=>$fee,
			'promotion_code'=>$promotion['code'],
			'promotion_amount'=>$promotion['amount'],
			'discount_amount'=>$discount,
			'subtotal_amount'=>$subtotal,
			'tax_rate_bps'=>$tax_rate_bps,
			'tax_amount'=>$tax,
			'final_amount'=>$subtotal + $tax,
		);
	}

	/** Return the immutable approval tier and its configured ceiling. */
	public static function discount_tier( int $amount ) {
		$manager = Money::parse( get_option( 'adc_sales_manager_discount_limit', 0 ) );
		$general = Money::parse( get_option( 'adc_general_manager_discount_limit', PHP_INT_MAX ) );
		if ( $amount < 1 || null === $manager || null === $general || $general < $manager || $amount > $general ) {
			return new \WP_Error( 'adc_discount_policy', __( 'قيمة الخصم تتجاوز حدود سياسة الاعتماد.', 'auto-dealership-core' ), array( 'status'=>409 ) );
		}
		return array( 'tier'=>$amount <= $manager ? 'sales_manager' : 'general_manager', 'ceiling'=>$amount <= $manager ? $manager : $general );
	}

	/** Required deposit snapshot for a reservation; it never proves receipt or settlement. */
	public static function reservation_deposit( int $retail_price ): array {
		$type = (string) get_option( 'adc_reservation_deposit_type', 'none' );
		$value = Money::parse( get_option( 'adc_reservation_deposit_value', 0 ) );
		if ( ! in_array( $type, array( 'none','fixed','percentage' ), true ) || null === $value || $retail_price < 1 ) {
			throw new \InvalidArgumentException( 'Invalid reservation deposit policy.' );
		}
		$required = 'fixed' === $type ? $value : ( 'percentage' === $type ? self::ratio( $retail_price, $value ) : 0 );
		if ( $required >= $retail_price ) { throw new \InvalidArgumentException( 'Deposit must be below the vehicle price.' ); }
		return array( 'type'=>$type, 'value'=>$value, 'required_amount'=>$required );
	}

	public static function seller_snapshot(): array {
		return array(
			'seller_name'=>sanitize_text_field( (string) get_option( 'adc_seller_name', get_bloginfo( 'name' ) ) ),
			'seller_tax_number'=>sanitize_text_field( (string) get_option( 'adc_seller_tax_number', '' ) ),
			'seller_address'=>sanitize_textarea_field( (string) get_option( 'adc_seller_address', '' ) ),
			'seller_phone'=>sanitize_text_field( (string) get_option( 'adc_seller_phone', '' ) ),
		);
	}

	private static function promotion( int $base, string $requested_code ): array {
		$requested_code = sanitize_key( $requested_code );
		$code = sanitize_key( (string) get_option( 'adc_promotion_code', '' ) );
		$type = (string) get_option( 'adc_promotion_type', 'none' );
		$value = Money::parse( get_option( 'adc_promotion_value', 0 ) );
		$starts = (string) get_option( 'adc_promotion_starts_at', '' );
		$ends = (string) get_option( 'adc_promotion_ends_at', '' );
		$today = gmdate( 'Y-m-d' );
		if ( '' === $requested_code ) { return array( 'code'=>'', 'amount'=>0 ); }
		if ( '' === $code || ! hash_equals( $code, $requested_code ) || ! in_array( $type, array( 'fixed','percentage' ), true ) || null === $value || ( $starts && $today < $starts ) || ( $ends && $today > $ends ) ) {
			throw new \InvalidArgumentException( 'Promotion is invalid or inactive.' );
		}
		$amount = 'fixed' === $type ? $value : self::ratio( $base, $value );
		if ( $amount < 1 || $amount >= $base ) { throw new \InvalidArgumentException( 'Promotion amount is invalid.' ); }
		return array( 'code'=>$code, 'amount'=>$amount );
	}

	private static function ratio( int $amount, int $basis_points ): int {
		if ( $amount < 0 || $basis_points < 0 || $basis_points > 10000 ) { throw new \InvalidArgumentException( 'Invalid ratio.' ); }
		return intdiv( $amount, 10000 ) * $basis_points + intdiv( ( $amount % 10000 ) * $basis_points + 5000, 10000 );
	}
}
