<?php
/** Runs only inside the restored disposable installation. */
use AutoDealership\Core\UpdateCompatibility as Compatibility;
if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
$count = 0;
$check = static function ( bool $ok, string $label ) use ( &$count ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$count; echo "PASS Compatibility: $label\n"; };
$parent = $config['temporary'] . '/guard-fixtures'; mkdir( $parent, 0700 );
$original = json_decode( file_get_contents( ADC_PATH . 'resources/release.json' ), true );
$header = file_get_contents( ADC_PATH . 'auto-dealership-core.php' );
$fixture = static function ( array $changes = array(), ?string $replacement_header = null ) use ( $parent, $original, $header ): string {
	$dir = $parent . '/' . bin2hex( random_bytes( 6 ) ); mkdir( $dir . '/resources', 0700, true );
	file_put_contents( $dir . '/resources/release.json', json_encode( array_replace( $original, $changes ) ) ); file_put_contents( $dir . '/auto-dealership-core.php', $replacement_header ?? $header ); return $dir;
};
$error = static fn( $result, string $code ): bool => is_wp_error( $result ) && $code === $result->get_error_code();
$check( true === Compatibility::validate_directory( ADC_PATH, 'plugin' ), 'Current matched plugin package is accepted' );
$check( true === Compatibility::validate_directory( get_theme_root( 'car-dealer' ) . '/car-dealer', 'theme' ), 'Current matched theme package is accepted' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'contract'=>999 ) ), 'plugin' ), 'adc_update_contract' ), 'Unsupported integration contract is blocked' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'requires_php'=>'99.0' ) ), 'plugin' ), 'adc_update_environment' ), 'Unsupported PHP requirement is blocked' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'requires_wp'=>'99.0' ) ), 'plugin' ), 'adc_update_environment' ), 'Unsupported WordPress requirement is blocked' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'version'=>'1.41.0' ) ), 'plugin' ), 'adc_update_header' ), 'Version/header disagreement is blocked' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'partner_min'=>'3.0.0', 'partner_before'=>'4.0.0' ) ), 'plugin' ), 'adc_update_partner' ), 'Incompatible installed partner is blocked' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'schema_max'=>'1.0.0', 'schema_target'=>'1.0.0' ) ), 'plugin' ), 'adc_update_schema' ), 'Package unable to read installed schema is blocked' );
$check( $error( Compatibility::validate_directory( $fixture( array( 'schema_target'=>'1.21.0' ) ), 'plugin' ), 'adc_update_schema' ), 'Schema downgrade is blocked even when package version is unchanged' );
$older = str_replace( 'Version: 1.40.1', 'Version: 1.39.1', $header );
$check( $error( Compatibility::validate_directory( $fixture( array( 'version'=>'1.39.1' ), $older ), 'plugin' ), 'adc_update_downgrade' ), 'Code-only downgrade is blocked' );
$missing = $fixture(); unlink( $missing . '/resources/release.json' );
$check( $error( Compatibility::validate_directory( $missing, 'plugin' ), 'adc_update_manifest' ), 'Missing release manifest is blocked' );
$broken = $fixture(); file_put_contents( $broken . '/resources/release.json', '{broken' );
$check( $error( Compatibility::validate_directory( $broken, 'plugin' ), 'adc_update_contract' ), 'Malformed release manifest is blocked' );
$bad = $fixture( array( 'contract'=>999 ) );
$check( $error( apply_filters( 'upgrader_source_selection', $bad, '', null, array( 'plugin'=>'auto-dealership-core/auto-dealership-core.php' ) ), 'adc_update_contract' ), 'WordPress source selection hook enforces the guard' );
$prior = new WP_Error( 'prior_filter', 'Prior failure' );
$check( $prior === Compatibility::source_selection( $prior ), 'Earlier updater errors are preserved' );
$check( $parent === Compatibility::source_selection( $parent, '', null, array( 'plugin'=>'other/plugin.php' ) ), 'Unrelated plugin updates are untouched' );
$legacy_callbacks = 0; global $wp_filter;
foreach ( array( 'pre_wp_mail', 'wp_mail_from', 'wp_mail_from_name', 'phpmailer_init', 'wp_mail_failed', 'admin_notices', 'retrieve_password_notification_email' ) as $hook ) {
	foreach ( ( $wp_filter[$hook]->callbacks ?? array() ) as $callbacks ) { foreach ( $callbacks as $callback ) { if ( $callback['function'] instanceof Closure ) { $file = ( new ReflectionFunction( $callback['function'] ) )->getFileName(); if ( $file && in_array( realpath( $file ), array( realpath( WPMU_PLUGIN_DIR . '/autobrands-mail.php' ), realpath( WPMU_PLUGIN_DIR . '/autobrands-password-email.php' ) ), true ) ) { ++$legacy_callbacks; } } } }
}
$check( 0 === $legacy_callbacks, 'Old MU mail callbacks are replaced without duplicate handlers or file edits' );
$check( has_filter( 'pre_wp_mail', '__return_false' ) !== false && 'disabled' === adc_product_mail_config()['mode'], 'Rehearsal mail block remains registered after legacy compatibility handling' );
$theme = get_theme_root( 'car-dealer' ) . '/car-dealer'; $theme_metadata = file_get_contents( $theme . '/inc/release.json' ); $theme_header = file_get_contents( $theme . '/style.css' );
try {
	$data = json_decode( $theme_metadata, true ); $data['partner_before'] = '1.40.1'; file_put_contents( $theme . '/inc/release.json', json_encode( $data ) );
	$check( $error( Compatibility::validate_directory( ADC_PATH, 'plugin' ), 'adc_update_partner' ), 'Partner compatibility is checked in both directions' );
	unlink( $theme . '/inc/release.json' );
	$check( $error( Compatibility::validate_directory( ADC_PATH, 'plugin' ), 'adc_update_partner' ), 'Unknown partner without a manifest is blocked' );
	file_put_contents( $theme . '/style.css', str_replace( 'Version: 1.40.1', 'Version: 1.40.0', $theme_header ) );
	$check( true === Compatibility::validate_directory( ADC_PATH, 'plugin' ), 'Known legacy bridge permits sequential component updates' );
} finally { file_put_contents( $theme . '/inc/release.json', $theme_metadata ); file_put_contents( $theme . '/style.css', $theme_header ); }
echo "Compatibility guard passed ($count checks).\n";
