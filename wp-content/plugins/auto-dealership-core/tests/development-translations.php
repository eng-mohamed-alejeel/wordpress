<?php
/** Apply only reviewed translations of known local demo fixtures. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) { fwrite( STDERR, "Usage: php development-translations.php --inspect|--apply\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require __DIR__ . '/development-translations-data.php';
global $wpdb;
if ( 'wp-autobrands' !== DB_NAME || 'wp_' !== $wpdb->prefix || 'http://localhost/wordpress' !== untrailingslashit( get_option( 'siteurl' ) ) || '2026-10-02-complete' !== get_option( 'adc_demo_seed_version' ) || ! \AutoDealership\Database\Schema::is_ready() ) { fwrite( STDERR, "Refusing: verified local demo database required.\n" ); exit( 2 ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { exit( 3 ); }
wp_set_current_user( (int) $admins[0] );
try { echo wp_json_encode( adc_apply_development_translations( '--apply' === $argv[1] ), JSON_PRETTY_PRINT ) . "\n"; }
catch ( \Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 4 ); }
