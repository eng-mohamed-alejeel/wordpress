<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Integer minor units only. Tax rounds half up to one halala. */
final class Money {
	/** Parse user-facing SAR exactly; internal parse() continues to accept minor units. */
	public static function from_sar( $value ): ?int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return null; }
		$value = strtr( trim( (string) $value ), array( '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9', '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٫'=>'.' ) );
		if ( ! preg_match( '/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', $value, $parts ) ) { return null; }
		return self::parse( $parts[1] . str_pad( $parts[2] ?? '', 2, '0' ) );
	}

	/** Signed, exact display without float conversion, including aggregate SQL strings. */
	public static function decimal( $minor ): string {
		if ( ! is_int( $minor ) && ! is_string( $minor ) ) { throw new \InvalidArgumentException( 'Invalid minor amount.' ); }
		if ( ! preg_match( '/\A(-?)([0-9]+)\z/', (string) $minor, $parts ) ) { throw new \InvalidArgumentException( 'Invalid minor amount.' ); }
		$digits = str_pad( ltrim( $parts[2], '0' ), 3, '0', STR_PAD_LEFT );
		return ( '000' === $digits ? '' : $parts[1] ) . substr( $digits, 0, -2 ) . '.' . substr( $digits, -2 );
	}

	public static function display( $minor ): string {
		return null === $minor ? '—' : self::decimal( $minor ) . ' ' . __( 'SAR', 'auto-dealership-core' );
	}

	/** Prevent a form opened before the unit change from submitting minor units as SAR. */
	public static function require_sar_form(): void {
		if ( 'SAR' !== ( $_POST['adc_money_unit'] ?? null ) ) {
			wp_die( esc_html__( 'Reload this form before entering amounts in SAR.', 'auto-dealership-core' ), '', array( 'response'=>400 ) );
		}
	}

	/** Reject invalid form values before any service can write or coerce them. */
	public static function form_values( array $input, array $fields, array $nullable = array() ): array {
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			$value = $input[$field];
			if ( '' === $value && in_array( $field, $nullable, true ) ) { continue; }
			$amount = self::from_sar( $value );
			if ( null === $amount ) { wp_die( esc_html__( 'Enter a valid SAR amount with at most two decimal places.', 'auto-dealership-core' ), '', array( 'response'=>400 ) ); }
			$input[$field] = $amount;
		}
		return $input;
	}
	public static function parse( $value ): ?int {
		if ( is_int( $value ) ) { return $value >= 0 ? $value : null; }
		if ( ! is_string( $value ) || ! preg_match( '/\A[0-9]+\z/', $value ) ) { return null; }
		$digits = ltrim( $value, '0' );
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $maximum ) || ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) ) { return null; }
		return '' === $digits ? 0 : (int) $digits;
	}

	public static function calculate( int $base, int $discount, int $rate_bps ): array {
		if ( $base < 1 || $discount < 0 || $discount >= $base || $rate_bps < 0 || $rate_bps > 10000 ) {
			throw new \InvalidArgumentException( 'Invalid quote amounts or tax rate.' );
		}
		$net = $base - $discount;
		// Divide before multiplying so even the largest supported integer cannot overflow.
		$tax = intdiv( $net, 10000 ) * $rate_bps + intdiv( ( $net % 10000 ) * $rate_bps + 5000, 10000 );
		if ( $net > PHP_INT_MAX - $tax ) { throw new \OverflowException( 'Quote total exceeds the integer amount limit.' ); }
		return array( 'base_amount' => $base, 'discount_amount' => $discount, 'tax_rate_bps' => $rate_bps, 'tax_amount' => $tax, 'final_amount' => $net + $tax );
	}

	public static function format( int $minor ): string {
		if ( $minor < 0 ) { throw new \InvalidArgumentException( 'Negative amount.' ); }
		return (string) intdiv( $minor, 100 ) . '.' . str_pad( (string) ( $minor % 100 ), 2, '0', STR_PAD_LEFT );
	}
}
