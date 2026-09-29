<?php
namespace AutoDealership\Leads;

defined( 'ABSPATH' ) || exit;

/** Canonical contact input, without treating an unverified phone/email as ownership. */
final class ContactIdentity {
	public static function normalize( array $input ) {
		foreach ( array( 'name'=>190, 'mobile'=>60, 'email'=>190, 'city'=>100 ) as $field => $limit ) {
			if ( isset( $input[$field] ) && ( ! is_string( $input[$field] ) || mb_strlen( $input[$field] ) > $limit ) ) { return self::invalid(); }
		}
		$name = sanitize_text_field( $input['name'] ?? '' );
		$mobile = self::normalize_mobile( (string) ( $input['mobile'] ?? '' ) );
		$email = trim( $input['email'] ?? '' );
		if ( '' === trim( $name ) || is_wp_error( $mobile ) || ( '' !== $email && ! is_email( $email ) ) ) { return self::invalid(); }
		$consent = $input['consent_marketing'] ?? false;
		if ( ! in_array( $consent, array( true,false,1,0,'1','0','' ), true ) ) { return self::invalid(); }
		return array( 'name'=>$name, 'mobile'=>$mobile, 'email'=>strtolower( sanitize_email( $email ) ), 'city'=>sanitize_text_field( $input['city'] ?? '' ), 'consent_marketing'=>in_array( $consent, array( true,1,'1' ), true ) );
	}

	/** Normalize an account phone before it is stored; an empty value is allowed only for profile setup. */
	public static function normalize_mobile( string $value, bool $required = true ) {
		$mobile = strtr( trim( $value ), array_combine( preg_split( '//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
		$mobile = preg_replace( '/[\s()\-]/u', '', $mobile );
		if ( '' === $mobile && ! $required ) { return ''; }
		if ( str_starts_with( $mobile, '00' ) ) { $mobile = '+' . substr( $mobile, 2 ); }
		if ( preg_match( '/\A05[0-9]{8}\z/', $mobile ) ) { $mobile = '+966' . substr( $mobile, 1 ); }
		elseif ( preg_match( '/\A9665[0-9]{8}\z/', $mobile ) ) { $mobile = '+' . $mobile; }
		return preg_match( '/\A\+?[0-9]{8,15}\z/', $mobile ) ? $mobile : self::invalid();
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'adc_invalid_lead', __( 'تحقق من الاسم ورقم الجوال والبريد الإلكتروني.', 'auto-dealership-core' ), array( 'status'=>400 ) );
	}
}
