<?php
/** Generic password-mail bootstrap for installations that load MU plugins first. */
defined( 'ABSPATH' ) || exit;
$file = WP_PLUGIN_DIR . '/auto-dealership-core/src/Mail/PasswordReset.php';
if ( is_readable( $file ) ) { require_once $file; }
unset( $file );
