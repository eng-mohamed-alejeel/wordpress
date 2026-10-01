<?php
namespace AutoDealership\Security;

defined( 'ABSPATH' ) || exit;

/** Resolves a client address without trusting forwarded headers by default. */
final class ClientAddress {
	public static function resolve(): string {
		$remote = self::normalize( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( '' === $remote ) { return 'unknown'; }
		$rules = self::trusted_rules();
		if ( ! self::matches_any( $remote, $rules ) ) { return $remote; }
		$forwarded = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' );
		if ( '' === $forwarded || strlen( $forwarded ) > 1024 ) { return $remote; }
		$parts = array_map( 'trim', explode( ',', $forwarded ) );
		if ( !$parts || count( $parts ) > 10 ) { return $remote; }
		$chain = array();
		foreach ( $parts as $part ) {
			$address = self::normalize( $part );
			if ( '' === $address ) { return $remote; }
			$chain[] = $address;
		}
		$chain[] = $remote;
		for ( $index = count( $chain ) - 1; $index >= 0; --$index ) {
			if ( ! self::matches_any( $chain[ $index ], $rules ) ) { return $chain[ $index ]; }
		}
		return $chain[0] ?? $remote;
	}

	/** Count only; the operations UI must not render deployment network rules. */
	public static function trusted_rule_count(): int { return count( self::trusted_rules() ); }

	private static function trusted_rules(): array {
		$declared = apply_filters( 'adc_trusted_proxy_cidrs', array() );
		if ( ! is_array( $declared ) ) { return array(); }
		$rules = array();
		foreach ( array_slice( $declared, 0, 32 ) as $rule ) {
			if ( ! is_string( $rule ) || strlen( $rule ) > 80 ) { continue; }
			$rule = trim( $rule );
			list( $address, $prefix ) = array_pad( explode( '/', $rule, 2 ), 2, null );
			$normalized = self::normalize( $address );
			$packed = '' !== $normalized ? @inet_pton( $normalized ) : false;
			$bits = false === $packed ? 0 : strlen( $packed ) * 8;
			if ( !$bits ) { continue; }
			if ( null === $prefix ) { $prefix = $bits; }
			if ( ! preg_match( '/\A\d{1,3}\z/', (string) $prefix ) || (int) $prefix < 0 || (int) $prefix > $bits ) { continue; }
			$rules[] = array( 'packed'=>$packed, 'prefix'=>(int) $prefix );
		}
		return $rules;
	}

	private static function normalize( string $address ): string {
		$address = trim( $address );
		$packed = '' !== $address && false === strpos( $address, '%' ) ? @inet_pton( $address ) : false;
		return false === $packed ? '' : (string) inet_ntop( $packed );
	}

	private static function matches_any( string $address, array $rules ): bool {
		$packed = @inet_pton( $address );
		if ( false === $packed ) { return false; }
		foreach ( $rules as $rule ) {
			if ( strlen( $packed ) !== strlen( $rule['packed'] ) ) { continue; }
			$bytes = intdiv( $rule['prefix'], 8 );
			$remainder = $rule['prefix'] % 8;
			if ( $bytes && ! hash_equals( substr( $rule['packed'], 0, $bytes ), substr( $packed, 0, $bytes ) ) ) { continue; }
			if ( $remainder ) {
				$mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;
				if ( ( ord( $rule['packed'][ $bytes ] ) & $mask ) !== ( ord( $packed[ $bytes ] ) & $mask ) ) { continue; }
			}
			return true;
		}
		return false;
	}
}
