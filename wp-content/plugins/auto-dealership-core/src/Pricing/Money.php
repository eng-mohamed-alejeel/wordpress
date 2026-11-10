<?php
namespace AutoDealership\Pricing;

defined( 'ABSPATH' ) || exit;

/** Integer minor units only. Tax rounds half up to one halala. */
final class Money {
	/**
	 * Parse user-facing major units exactly; internal parse() continues to accept minor units.
	 * @param mixed $value
	 */
	public static function from_major( $value ): ?int {
		$code = \AutoDealership\Core\CurrencySettings::base_code();
		return '' === $code ? null : self::from_currency( $value, $code );
	}

	public static function from_currency( $value, ?string $currency_code = null ): ?int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return null; }
		$value = strtr( trim( (string) $value ), array( '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9', '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9', '٫'=>'.' ) );
		$decimals = \AutoDealership\Core\CurrencySettings::decimals( $currency_code );
		$pattern = 0 === $decimals ? '/\A([0-9]+)\z/' : '/\A([0-9]+)(?:\.([0-9]{1,' . $decimals . '}))?\z/';
		if ( ! preg_match( $pattern, $value, $parts ) ) { return null; }
		return self::parse( $parts[1] . str_pad( $parts[2] ?? '', $decimals, '0' ) );
	}

	/** Parse a user-facing percentage independently of the configured currency. */
	public static function from_percent( $value ): ?int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return null; }
		$value = strtr( trim( (string) $value ), array( '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9', '٫'=>'.' ) );
		if ( ! preg_match( '/\A([0-9]+)(?:\.([0-9]{1,2}))?\z/', $value, $parts ) ) { return null; }
		return self::parse( $parts[1] . str_pad( $parts[2] ?? '', 2, '0' ) );
	}

	/** Format basis points as a user-facing percentage. */
	public static function percent( $basis_points ): string {
		if ( ! is_int( $basis_points ) && ! is_string( $basis_points ) ) { throw new \InvalidArgumentException( 'Invalid percentage.' ); }
		if ( ! preg_match( '/\A([0-9]+)\z/', (string) $basis_points, $parts ) ) { throw new \InvalidArgumentException( 'Invalid percentage.' ); }
		$raw = ltrim( $parts[1], '0' ); $raw = '' === $raw ? '0' : $raw;
		$digits = str_pad( $raw, 3, '0', STR_PAD_LEFT );
		return rtrim( rtrim( substr( $digits, 0, -2 ) . '.' . substr( $digits, -2 ), '0' ), '.' );
	}

	/**
	 * Signed, exact display without float conversion, including aggregate SQL strings.
	 * @param mixed $minor
	 */
	public static function decimal( $minor, ?string $currency_code = null ): string {
		if ( ! is_int( $minor ) && ! is_string( $minor ) ) { throw new \InvalidArgumentException( 'Invalid minor amount.' ); }
		if ( ! preg_match( '/\A(-?)([0-9]+)\z/', (string) $minor, $parts ) ) { throw new \InvalidArgumentException( 'Invalid minor amount.' ); }
		$decimals = \AutoDealership\Core\CurrencySettings::decimals( $currency_code );
		$raw = ltrim( $parts[2], '0' ); $raw = '' === $raw ? '0' : $raw;
		if ( 0 === $decimals ) { return $parts[1] . $raw; }
		$digits = str_pad( $raw, $decimals + 1, '0', STR_PAD_LEFT );
		return $parts[1] . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
	}

	/**
	 * @param mixed $minor
	 */
	public static function display( $minor ): string {
		$code = \AutoDealership\Core\CurrencySettings::base_code();
		if ( null === $minor || '' === $code ) { return '—'; }
		return self::decimal( $minor, $code ) . ' ' . \AutoDealership\Core\CurrencySettings::display_unit( $code );
	}

	/** Prevent a form opened before the unit change from submitting the wrong minor unit. */
	public static function require_currency_form(): void {
		if ( ! \AutoDealership\Core\CurrencySettings::is_configured() ) {
			wp_die( esc_html__( 'فعّل العملة الأساسية من إعداد المنصة قبل إدخال أي مبلغ.', 'auto-dealership-core' ), '', array( 'response'=>409 ) );
		}
		$posted_unit = (string) ( $_POST['adc_money_unit'] ?? '' );
		// A few server-rendered legacy admin fragments may still carry the historical marker;
		// The base currency is immutable after setup, so the amount is always parsed in it.
		if ( '' !== $posted_unit && $posted_unit !== \AutoDealership\Core\CurrencySettings::base_code() ) {
			wp_die( esc_html__( 'أعد تحميل النموذج قبل إدخال المبالغ بعملة النظام الحالية.', 'auto-dealership-core' ), '', array( 'response'=>400 ) );
		}
	}

	/** Reject invalid form values before any service can write or coerce them. */
	public static function form_values( array $input, array $fields, array $nullable = array() ): array {
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			$value = $input[$field];
			if ( '' === $value && in_array( $field, $nullable, true ) ) { continue; }
			$amount = self::from_major( $value );
			if ( null === $amount ) { wp_die( esc_html__( 'Enter a valid amount using the configured currency precision.', 'auto-dealership-core' ), '', array( 'response'=>400 ) ); }
			$input[$field] = $amount;
		}
		return $input;
	}
	/**
	 * @param mixed $value
	 */
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

	public static function format( int $minor, ?string $currency_code = null ): string {
		if ( $minor < 0 ) { throw new \InvalidArgumentException( 'Negative amount.' ); }
		$decimals = \AutoDealership\Core\CurrencySettings::decimals( $currency_code );
		if ( 0 === $decimals ) { return (string) $minor; }
		$factor = 10 ** $decimals;
		return (string) intdiv( $minor, $factor ) . '.' . str_pad( (string) ( $minor % $factor ), $decimals, '0', STR_PAD_LEFT );
	}
}
