<?php
/** Keep system typography available even when the dealership plugin is inactive. */
defined( 'ABSPATH' ) || exit;
$autobrands_typography_file = WP_PLUGIN_DIR . '/auto-dealership-core/src/Core/Typography.php';
if ( is_readable( $autobrands_typography_file ) ) {
	require_once $autobrands_typography_file;
	\AutoDealership\Core\Typography::boot();
}
unset( $autobrands_typography_file );
