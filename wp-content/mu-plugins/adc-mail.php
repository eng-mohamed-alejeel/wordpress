<?php
/** Generic mail bootstrap for installations that load MU plugins before normal plugins. */
defined( 'ABSPATH' ) || exit;
$file = WP_PLUGIN_DIR . '/auto-dealership-core/src/Mail/Transport.php';
if ( is_readable( $file ) ) { require_once $file; }
unset( $file );
