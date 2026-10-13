<?php
/** Apply/restore only the two identity settings on the local workspace. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--apply', '--rollback', '--status' ), true ) ) { exit( "Usage: php tools/apply-autobrands-palette.php --apply|--rollback|--status\n" ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
if ( ! in_array( wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { throw new RuntimeException( 'Local workspace only.' ); }
$key = 'adc_brand_palette_backup_v1';
$settings = get_option( 'car_dealer_theme_settings', array() );
$fields = array( 'primary_color' => '#0E2A4A', 'accent_color' => '#2563EB' );
if ( '--apply' === $argv[1] ) {
    if ( false === get_option( $key, false ) ) {
        add_option( $key, array_intersect_key( $settings, $fields ), '', false );
    }
    update_option( 'car_dealer_theme_settings', array_replace( $settings, $fields ) );
} elseif ( '--rollback' === $argv[1] ) {
    $backup = get_option( $key, false );
    if ( false === $backup ) { throw new RuntimeException( 'No palette backup exists.' ); }
    foreach ( $fields as $name => $value ) {
        if ( array_key_exists( $name, $backup ) ) { $settings[$name] = $backup[$name]; }
        else { unset( $settings[$name] ); }
    }
    update_option( 'car_dealer_theme_settings', $settings );
    delete_option( $key );
}
if ( '--status' !== $argv[1] && function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
$actual = get_option( 'car_dealer_theme_settings', array() );
echo wp_json_encode( array( 'theme' => get_stylesheet(), 'colors' => array_intersect_key( $actual, $fields ), 'backup_available' => false !== get_option( $key, false ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
