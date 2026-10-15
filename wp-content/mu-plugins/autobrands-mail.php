<?php
/** Compatibility loader for existing installations; new installs need only the core plugin. */
defined( 'ABSPATH' ) || exit;
$file = WP_PLUGIN_DIR . '/auto-dealership-core/src/Mail/Transport.php';
if ( is_readable( $file ) ) { require_once $file; }
unset( $file );
