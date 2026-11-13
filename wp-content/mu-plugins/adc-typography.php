<?php
/** Keep system typography available even when the product plugin is inactive. */
defined( 'ABSPATH' ) || exit;
$adc_typography_file = WP_PLUGIN_DIR . '/auto-dealership-core/src/Core/Typography.php';
if ( is_readable( $adc_typography_file ) ) {
	require_once $adc_typography_file;
	\AutoDealership\Core\Typography::boot();
}
unset( $adc_typography_file );
