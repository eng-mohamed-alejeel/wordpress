<?php
/**
 * Plugin Name: AutoBrands Mail Transport
 * Description: Environment-specific SMTP transport for all WordPress mail.
 */
defined( 'ABSPATH' ) || exit;

function autobrands_mail_setting( string $name, string $default = '' ): string {
	$value = getenv( $name );
	return false !== $value ? $value : ( defined( $name ) ? (string) constant( $name ) : $default );
}

function autobrands_mail_config(): array {
	$mode = autobrands_mail_setting( 'ADC_MAIL_MODE', 'disabled' );
	$local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	$config = array( 'mode' => $mode, 'valid' => false );
	if ( 'mailpit' === $mode ) {
		return $config + array( 'host' => '127.0.0.1', 'port' => 1025, 'encryption' => '', 'username' => '', 'password' => '', 'from' => 'wordpress@autobrands.test', 'name' => 'AutoBrands' , 'local' => $local );
	}
	if ( 'smtp' !== $mode ) { return $config; }
	$config['host'] = autobrands_mail_setting( 'ADC_SMTP_HOST' );
	$config['port'] = (int) autobrands_mail_setting( 'ADC_SMTP_PORT', '587' );
	$config['encryption'] = autobrands_mail_setting( 'ADC_SMTP_ENCRYPTION', 'tls' );
	$config['username'] = autobrands_mail_setting( 'ADC_SMTP_USERNAME' );
	$config['password'] = autobrands_mail_setting( 'ADC_SMTP_PASSWORD' );
	$config['from'] = autobrands_mail_setting( 'ADC_MAIL_FROM' );
	$config['name'] = autobrands_mail_setting( 'ADC_MAIL_FROM_NAME', 'AutoBrands' );
	$config['valid'] = '' !== $config['host'] && ! preg_match( '/[\s\/]/', $config['host'] )
		&& $config['port'] > 0 && $config['port'] <= 65535
		&& in_array( $config['encryption'], array( 'tls', 'ssl' ), true ) && (bool) is_email( $config['from'] )
		&& '' !== $config['username'] && '' !== $config['password'];
	return $config;
}

add_filter( 'pre_wp_mail', static function ( $result, $attributes ) {
	$config = autobrands_mail_config();
	$valid = 'mailpit' === $config['mode'] ? $config['local'] : $config['valid'];
	if ( ! $valid ) {
		do_action( 'wp_mail_failed', new WP_Error( 'adc_mail_configuration', 'Mail transport is disabled or invalid for this environment.' ) );
		return false;
	}
	return $result;
}, PHP_INT_MAX, 2 );

// WordPress validates From before phpmailer_init; localhost needs a valid domain.
add_filter( 'wp_mail_from', static function ( $from ) {
	$config = autobrands_mail_config();
	return $config['from'] ?? $from;
}, PHP_INT_MAX );
add_filter( 'wp_mail_from_name', static function ( $name ) {
	$config = autobrands_mail_config();
	return $config['name'] ?? $name;
}, PHP_INT_MAX );

add_action( 'phpmailer_init', static function ( $mailer ) {
	$config = autobrands_mail_config();
	if ( ! isset( $config['host'] ) ) { return; }
	$mailer->isSMTP();
	$mailer->Host = $config['host'];
	$mailer->Port = $config['port'];
	$mailer->SMTPSecure = $config['encryption'];
	$mailer->SMTPAutoTLS = 'smtp' === $config['mode'];
	$mailer->SMTPAuth = 'smtp' === $config['mode'];
	$mailer->Username = $config['username'];
	$mailer->Password = $config['password'];
	$mailer->SMTPDebug = 0;
	$mailer->Timeout = 10;
	$mailer->SMTPKeepAlive = false;
	$mailer->setFrom( $config['from'], $config['name'], true );
}, PHP_INT_MAX );

// Record status only: never log recipients, message bodies, tokens or credentials.
add_action( 'wp_mail_failed', static function ( $error ) {
	error_log( '[AutoBrands mail] failed code=' . sanitize_key( (string) $error->get_error_code() ) );
} );

add_action( 'admin_notices', static function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$config = autobrands_mail_config();
	$valid = 'mailpit' === $config['mode'] ? $config['local'] : $config['valid'];
	if ( ! $valid ) {
		echo '<div class="notice notice-error"><p>' . esc_html( 'البريد متوقف: يجب ضبط إعدادات SMTP لهذه البيئة. / Mail is disabled: configure SMTP for this environment.' ) . '</p></div>';
	}
} );
