<?php
/** CLI-only disposable users and role for browser access-governance acceptance. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true ); define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( untrailingslashit( get_option( 'siteurl' ) ) !== 'http://localhost/wordpress' ) { exit( 2 ); }
$path = ABSPATH . '.tmp/access-browser-fixture.json';
$admin = (int) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0];
wp_set_current_user( $admin );
if ( '--prepare' === ( $argv[1] ?? '' ) ) {
	if ( is_file( $path ) ) { throw new RuntimeException( 'Clean up the previous fixture first.' ); }
	$slug = 'adc_role_browser_' . strtolower( wp_generate_password( 8, false ) );
	$defs = get_option( 'adc_role_definitions', null );
	$result = \AutoDealership\Admin\RoleManager::save_role( array( 'slug' => $slug, 'ar' => 'دور اختبار المتصفح', 'en' => 'Browser test role', 'caps' => array( 'adc_view_workspace', 'adc_view_reports' ), 'new' => '1', 'reason' => 'Browser test fixture' ) );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	$id = wp_insert_user( array( 'user_login' => $slug, 'display_name' => 'Access governance browser', 'user_pass' => wp_generate_password( 40 ), 'role' => $slug ) );
	if ( is_wp_error( $id ) ) { remove_role( $slug ); throw new RuntimeException( $id->get_error_message() ); }
	$user = get_userdata( $id ); $user->add_cap( 'adc_manage_inventory', false );
	file_put_contents( $path, wp_json_encode( array( 'id' => $id, 'role' => $slug, 'defs_before' => $defs, 'expires' => wp_date( 'Y-m-d\TH:i', time() + HOUR_IN_SECONDS ) ) ) );
	echo "Browser fixture prepared.\n";
} elseif ( '--cleanup' === ( $argv[1] ?? '' ) && is_file( $path ) ) {
	$data = json_decode( file_get_contents( $path ), true );
	wp_clear_scheduled_hook( 'adc_expire_user_permission', array( $data['id'], 'adc_view_inventory' ) );
	wp_delete_user( $data['id'] ); remove_role( $data['role'] );
	if ( null === $data['defs_before'] ) { delete_option( 'adc_role_definitions' ); } else { update_option( 'adc_role_definitions', $data['defs_before'], false ); }
	unlink( $path ); echo "Browser fixture removed.\n";
}
