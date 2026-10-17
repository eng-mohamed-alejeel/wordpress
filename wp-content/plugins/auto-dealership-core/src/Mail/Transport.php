<?php
/**
 * Plugin Name: AutoBrands Mail Transport
 * Description: Environment-specific SMTP transport for all WordPress mail.
 */
defined( 'ABSPATH' ) || exit;
if ( defined( 'ADC_MAIL_TRANSPORT_LOADED' ) ) { return; }
define( 'ADC_MAIL_TRANSPORT_LOADED', true );
require_once __DIR__ . '/LegacyHooks.php';
adc_product_remove_legacy_mail_hooks( 'autobrands-mail.php', array( 'pre_wp_mail', 'wp_mail_from', 'wp_mail_from_name', 'phpmailer_init', 'wp_mail_failed', 'admin_notices' ) );

function adc_product_mail_setting( string $name, string $default = '' ): string {
	if ( get_option( 'adc_product_mail_dashboard', false ) && class_exists( '\AutoDealership\Core\ProductSetup' ) ) { return \AutoDealership\Core\ProductSetup::mail_setting( $name, $default ); }
	$value = getenv( $name );
	if ( false !== $value ) { return $value; }
	if ( defined( $name ) ) { return (string) constant( $name ); }
	return class_exists( '\AutoDealership\Core\ProductSetup' ) ? \AutoDealership\Core\ProductSetup::mail_setting( $name, $default ) : $default;
}

function adc_product_mail_config(): array {
	$mode = adc_product_mail_setting( 'ADC_MAIL_MODE', 'disabled' );
	$local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	$config = array( 'mode' => $mode, 'valid' => false );
	if ( 'mailpit' === $mode ) {
		return $config + array( 'host' => '127.0.0.1', 'port' => 1025, 'encryption' => '', 'username' => '', 'password' => '', 'from' => 'wordpress@autobrands.test', 'name' => 'AutoBrands' , 'local' => $local );
	}
	if ( 'smtp' !== $mode ) { return $config; }
	$config['host'] = adc_product_mail_setting( 'ADC_SMTP_HOST' );
	$config['port'] = (int) adc_product_mail_setting( 'ADC_SMTP_PORT', '587' );
	$config['encryption'] = adc_product_mail_setting( 'ADC_SMTP_ENCRYPTION', 'tls' );
	$config['username'] = adc_product_mail_setting( 'ADC_SMTP_USERNAME' );
	$config['password'] = adc_product_mail_setting( 'ADC_SMTP_PASSWORD' );
	$config['from'] = adc_product_mail_setting( 'ADC_MAIL_FROM' );
	$config['name'] = adc_product_mail_setting( 'ADC_MAIL_FROM_NAME', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );
	$config['valid'] = '' !== $config['host'] && ! preg_match( '/[\s\/]/', $config['host'] )
		&& $config['port'] > 0 && $config['port'] <= 65535
		&& in_array( $config['encryption'], array( 'tls', 'ssl' ), true ) && (bool) is_email( $config['from'] )
		&& '' !== $config['username'] && '' !== $config['password'];
	return $config;
}

if ( ! function_exists( 'autobrands_mail_setting' ) ) { function autobrands_mail_setting( string $name, string $default = '' ): string { return adc_product_mail_setting( $name, $default ); } }
if ( ! function_exists( 'autobrands_mail_config' ) ) { function autobrands_mail_config(): array { return adc_product_mail_config(); } }

add_filter( 'pre_wp_mail', static function ( $result, $attributes ) {
	$config = adc_product_mail_config();
	$valid = 'mailpit' === $config['mode'] ? $config['local'] : $config['valid'];
	if ( ! $valid ) {
		do_action( 'wp_mail_failed', new WP_Error( 'adc_mail_configuration', 'Mail transport is disabled or invalid for this environment.' ) );
		return false;
	}
	return $result;
}, PHP_INT_MAX, 2 );

// WordPress validates From before phpmailer_init; localhost needs a valid domain.
add_filter( 'wp_mail_from', static function ( $from ) {
	$config = adc_product_mail_config();
	return $config['from'] ?? $from;
}, PHP_INT_MAX );
add_filter( 'wp_mail_from_name', static function ( $name ) {
	$config = adc_product_mail_config();
	return $config['name'] ?? $name;
}, PHP_INT_MAX );

add_action( 'phpmailer_init', static function ( $mailer ) {
	$config = adc_product_mail_config();
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
	$message=strtolower($error->get_error_message());
	$kind='other';
	if('adc_mail_configuration'===$error->get_error_code()){$kind='configuration';}
	elseif(preg_match('/535|534|authenticat|password|credentials/',$message)){$kind='authentication';}
	elseif(preg_match('/certificate|crypto|starttls|tls handshake/',$message)){$kind='tls';}
	elseif(preg_match('/connect|timed out|network|resolve|unreachable/',$message)){$kind='connection';}
	elseif(preg_match('/quota|rate limit|too many|daily.*limit/',$message)){$kind='limit';}
	update_option('adc_product_mail_result',array('status'=>'failed','kind'=>$kind,'time'=>gmdate('Y-m-d H:i:s')),false);
	error_log( '[AutoBrands mail] failed code=' . sanitize_key( (string) $error->get_error_code() ) );
} );
add_action('wp_mail_succeeded',static function(): void { update_option('adc_product_mail_result',array('status'=>'accepted','kind'=>'','time'=>gmdate('Y-m-d H:i:s')),false); });

add_action( 'admin_notices', static function () {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$config = adc_product_mail_config();
	$valid = 'mailpit' === $config['mode'] ? $config['local'] : $config['valid'];
	if ( ! $valid ) {
		echo '<div class="notice notice-error"><p>' . esc_html( 'البريد متوقف: يجب ضبط إعدادات SMTP لهذه البيئة. / Mail is disabled: configure SMTP for this environment.' ) . '</p></div>';
	}
} );
