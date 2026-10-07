<?php
namespace AutoDealership\API;

use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/** Add explicit SAR fields while preserving existing integer minor-unit API fields. */
final class CurrencyContract {
	public const FIELDS = array( 'amount','retail_price','minimum_price','purchase_cost','additional_cost','total_cost','wholesale_price','down_payment','monthly_payment','requested_amount','base_amount','fee_amount','promotion_amount','discount_amount','subtotal_amount','tax_amount','final_amount','deposit_required_amount','deposit_amount','verified_amount','margin_before','margin_after','min_price','max_price' );

	public static function input( array $input ) {
		foreach ( self::FIELDS as $field ) {
			$alias = $field . '_sar';
			if ( ! array_key_exists( $alias, $input ) ) { continue; }
			if ( array_key_exists( $field, $input ) ) { return new \WP_Error( 'adc_currency_conflict', 'Provide either the SAR field or the minor-unit field, never both.', array( 'status'=>400 ) ); }
			$minor = Money::from_sar( $input[$alias] );
			if ( null === $minor ) { return new \WP_Error( 'adc_currency_invalid', 'Provide a SAR decimal string with at most two decimal places.', array( 'status'=>400 ) ); }
			$input[$field] = $minor;
			unset( $input[$alias] );
		}
		if ( isset( $input['acquisition'] ) && is_array( $input['acquisition'] ) ) {
			$input['acquisition'] = self::input( $input['acquisition'] );
			if ( is_wp_error( $input['acquisition'] ) ) { return $input['acquisition']; }
		}
		return $input;
	}

	public static function output( array $data ): array {
		foreach ( $data as $key=>$value ) {
			if ( is_array( $value ) ) { $data[$key] = self::output( $value ); }
			elseif ( in_array( $key, self::FIELDS, true ) && ( null === $value || is_int( $value ) || ( is_string( $value ) && preg_match( '/\A-?[0-9]+\z/', $value ) ) ) ) {
				$data[$key . '_sar'] = null === $value ? null : Money::decimal( $value );
			}
		}
		return $data;
	}

	public static function endpoint( array $endpoint ): array {
		$original = $endpoint['args'] ?? array();
		foreach ( self::FIELDS as $field ) {
			if ( ! isset( $original[$field] ) ) { continue; }
			$endpoint['args'][$field]['required'] = false;
			$endpoint['args'][$field]['description'] = 'Integer SAR minor units (100 = 1 SAR). Alternatively provide ' . $field . '_sar; never both.';
			unset( $endpoint['args'][$field]['default'] );
			$endpoint['args'][$field . '_sar'] = array( 'type'=>'string', 'description'=>'Amount in Saudi riyals (SAR), at most two decimal places.' );
		}
		$callback = $endpoint['callback'];
		$endpoint['callback'] = static function ( \WP_REST_Request $request ) use ( $callback, $original ) {
			$input = self::input( $request->get_params() );
			if ( is_wp_error( $input ) ) { return $input; }
			foreach ( $original as $field=>$schema ) {
				if ( ! in_array( $field, self::FIELDS, true ) ) { continue; }
				if ( ! array_key_exists( $field, $input ) ) {
					if ( ! empty( $schema['required'] ) ) { return new \WP_Error( 'rest_missing_callback_param', 'Missing monetary amount.', array( 'status'=>400 ) ); }
					if ( array_key_exists( 'default', $schema ) ) { $request->set_param( $field, $schema['default'] ); }
					continue;
				}
				$valid = rest_validate_value_from_schema( $input[$field], $schema, $field );
				if ( is_wp_error( $valid ) ) { return $valid; }
				$request->set_param( $field, $input[$field] );
			}
			if ( isset( $input['acquisition'] ) ) { $request->set_param( 'acquisition', $input['acquisition'] ); }
			foreach ( self::FIELDS as $field ) { if ( array_key_exists( $field, $input ) ) { $request->set_param( $field, $input[$field] ); } }
			foreach ( self::FIELDS as $field ) { unset( $request[$field . '_sar'] ); }
			$result = call_user_func( $callback, $request );
			if ( is_wp_error( $result ) ) { return $result; }
			$response = rest_ensure_response( $result );
			$data = $response->get_data();
			if ( is_array( $data ) ) { $response->set_data( self::output( $data ) ); }
			$response->header( 'X-ADC-Currency', 'SAR' );
			$response->header( 'X-ADC-Money-Unit', 'minor; *_sar=major' );
			return $response;
		};
		return $endpoint;
	}
}
