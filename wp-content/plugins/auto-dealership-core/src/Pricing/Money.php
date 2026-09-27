<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Integer minor units only. Tax rounds half up to one halala. */
final class Money {
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
