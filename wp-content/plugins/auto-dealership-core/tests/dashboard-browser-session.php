<?php
/** CLI-only temporary local browser session; no content/settings fixtures. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( untrailingslashit( get_option( 'siteurl' ) ) !== 'http://localhost/wordpress' ) { exit( 2 ); }
$path = ABSPATH . '.tmp/dashboard-browser-session.json';
if ( '--prepare' === ( $argv[1] ?? '' ) ) {
 if ( is_file( $path ) ) { throw new RuntimeException( 'Clean up the previous session first.' ); }
 $ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
 $id = (int) $ids[0]; $expiry = time() + 900;
 $token = WP_Session_Tokens::get_instance( $id )->create( $expiry );
 file_put_contents( $path, wp_json_encode( array( 'id'=>$id, 'token'=>$token, 'cookies'=>array(
  array( 'name'=>AUTH_COOKIE, 'value'=>wp_generate_auth_cookie( $id, $expiry, 'auth', $token ) ),
  array( 'name'=>LOGGED_IN_COOKIE, 'value'=>wp_generate_auth_cookie( $id, $expiry, 'logged_in', $token ) ),
 ) ) ) );
 echo "Local dashboard session prepared.\n";
} elseif ( '--cleanup' === ( $argv[1] ?? '' ) && is_file( $path ) ) {
 $data = json_decode( file_get_contents( $path ), true );
 WP_Session_Tokens::get_instance( $data['id'] )->destroy( $data['token'] );
 unlink( $path ); echo "Temporary dashboard session destroyed.\n";
}
