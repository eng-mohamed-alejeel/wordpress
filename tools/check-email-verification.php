<?php
/** CLI health check and schema initialization; prevents all email during this invocation. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-includes/plugin.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
require $root . '/wp-load.php';
if ( ! class_exists( 'AutoDealership\Accounts\EmailVerification' ) ) { fwrite( STDERR, "Auto Dealership Core is not active.\n" ); exit( 1 ); }
global $wpdb;
$checks = array(
	'newsletter_schema'=>'5' === get_option( 'adc_newsletter_schema' ),
	'subscriber_confirmation_source'=>(bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . \AutoDealership\Leads\NewsletterService::subscribers() . ' LIKE %s', 'confirmation_source' ) ),
	'verification_schema'=>'1' === get_option( 'adc_email_verification_schema' ),
	'subscriber_confirmation_column'=>(bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . \AutoDealership\Leads\NewsletterService::subscribers() . ' LIKE %s', 'confirmed_at' ) ),
	'verification_table_innodb'=>'InnoDB' === $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', \AutoDealership\Accounts\EmailVerification::table() ) ),
);
foreach ( $checks as $label=>$ok ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; }
echo "Mail is blocked and cron is disabled during this health check.\n";
exit( in_array( false, $checks, true ) ? 1 : 0 );
