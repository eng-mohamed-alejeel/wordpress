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
 global $wpdb;
 $catalog_post_id = (int) $wpdb->get_var( 'SELECT m.public_post_id FROM ' . \AutoDealership\Database\Schema::table( 'catalog_models' ) . " m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id WHERE p.post_type='car' AND p.post_status IN ('publish','draft') AND EXISTS(SELECT 1 FROM " . \AutoDealership\Database\Schema::table( 'onyx_stock' ) . ' s WHERE s.model_id=m.id AND s.present=1) ORDER BY m.id LIMIT 1' );
 $source_stock_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \AutoDealership\Database\Schema::table( 'onyx_stock' ) . ' WHERE present=1' );
 file_put_contents( $path, wp_json_encode( array( 'id'=>$id, 'token'=>$token, 'catalog_post_id'=>$catalog_post_id, 'source_stock_count'=>$source_stock_count, 'cookies'=>array(
  array( 'name'=>AUTH_COOKIE, 'value'=>wp_generate_auth_cookie( $id, $expiry, 'auth', $token ) ),
  array( 'name'=>LOGGED_IN_COOKIE, 'value'=>wp_generate_auth_cookie( $id, $expiry, 'logged_in', $token ) ),
 ) ) ) );
 echo "Local dashboard session prepared.\n";
} elseif ( '--cleanup' === ( $argv[1] ?? '' ) && is_file( $path ) ) {
 $data = json_decode( file_get_contents( $path ), true );
 WP_Session_Tokens::get_instance( $data['id'] )->destroy( $data['token'] );
 unlink( $path ); echo "Temporary dashboard session destroyed.\n";
}
