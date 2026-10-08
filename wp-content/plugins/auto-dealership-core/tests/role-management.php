<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true ); define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use AutoDealership\Admin\RoleManager;
use AutoDealership\Admin\UserPermissions;
use AutoDealership\Core\Capabilities;
$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; echo "PASS $message\n"; };
$admin = (int) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0];
$roles_before = get_option( wp_roles()->role_key );
$definitions_before = get_option( 'adc_role_definitions', null );
$version_before = get_option( 'adc_roles_version' );
$users = array();
$slug = 'adc_role_test_' . strtolower( wp_generate_password( 8, false ) );
$post_before = $_POST;
try {
	wp_set_current_user( $admin );
	$input = array( 'slug' => $slug, 'ar' => 'دور اختبار', 'en' => 'Test role', 'caps' => array( 'adc_view_workspace', 'adc_view_inventory' ), 'new' => '1' );
	$check( $slug === RoleManager::save_role( $input ), 'Create a bilingual role.' );
	$check( get_role( $slug )->has_cap( 'read' ) && ! get_role( $slug )->has_cap( 'manage_options' ), 'Custom role preserves account access without system privileges.' );
	$check( is_wp_error( RoleManager::save_role( $input ) ), 'Reject duplicate role.' );
	$input['new'] = '0'; $input['caps'][] = 'manage_options';
	$check( is_wp_error( RoleManager::save_role( $input ) ), 'Reject system capability injection.' );
	$input['slug'] = 'administrator'; $input['caps'] = array();
	$check( is_wp_error( RoleManager::save_role( $input ) ), 'Protect administrator role.' );
	$input['slug'] = $slug;
	$_POST = array( 'adc_permissions_present' => '1', 'adc_permissions_custom' => '1', 'adc_permissions_nonce' => wp_create_nonce( 'adc_user_permissions_0' ), 'adc_permissions' => array( 'adc_view_workspace', 'adc_view_reports' ) );
	$data = (object) array( 'role' => $slug, 'user_login' => $slug ); $errors = new WP_Error(); UserPermissions::validate( $errors, false, $data );
	$check( ! $errors->has_errors(), 'Validate individual permissions before account creation.' );
	$id = wp_insert_user( array( 'user_login' => $slug, 'user_pass' => wp_generate_password( 40 ), 'role' => $slug ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); } $users[] = $id;
	$check( user_can( $id, 'adc_view_reports' ) && ! user_can( $id, 'adc_view_inventory' ), 'Apply custom grants and denials during user creation.' );
	$input['caps'] = array( 'adc_view_workspace', 'adc_view_inventory', 'adc_manage_inventory' );
	$check( $slug === RoleManager::save_role( $input ), 'Update role permissions.' );
	$check( ! user_can( $id, 'adc_manage_inventory' ), 'Individual deny survives a role permission update.' );
	$_POST = array();
	$inherited = wp_insert_user( array( 'user_login' => $slug . '_default', 'user_pass' => wp_generate_password( 40 ), 'role' => $slug ) );
	if ( is_wp_error( $inherited ) ) { throw new RuntimeException( $inherited->get_error_message() ); } $users[] = $inherited;
	$check( user_can( $inherited, 'adc_manage_inventory' ), 'Users without overrides inherit updated role.' );
	$input['slug'] = 'dealership_sales'; $input['caps'] = array( 'adc_view_workspace' );
	$check( 'dealership_sales' === RoleManager::save_role( $input ), 'Edit existing dealership role.' );
	Capabilities::activate();
	$check( ! get_role( 'dealership_sales' )->has_cap( 'adc_manage_own_leads' ), 'Activation preserves removed default permissions.' );
	$input['slug'] = 'editor';
	$check( 'editor' === RoleManager::save_role( $input ) && get_role( 'editor' )->has_cap( 'edit_posts' ), 'Editing dealership caps preserves native WordPress permissions.' );
	wp_set_current_user( $inherited );
	$check( is_wp_error( RoleManager::save_role( $input ) ), 'Employee cannot edit roles.' );
	wp_set_current_user( $admin );
	$_POST = array( 'adc_permissions_present' => '1', 'adc_permissions_custom' => '1', 'adc_permissions_nonce' => 'bad', 'adc_permissions' => array() );
	$errors = new WP_Error(); UserPermissions::validate( $errors, false, $data );
	$check( $errors->has_errors(), 'Reject forged creation request.' );
} finally {
	wp_set_current_user( $admin ); $_POST = $post_before;
	foreach ( $users as $id ) { wp_delete_user( $id ); }
	update_option( wp_roles()->role_key, $roles_before );
	if ( null === $definitions_before ) { delete_option( 'adc_role_definitions' ); } else { update_option( 'adc_role_definitions', $definitions_before, false ); }
	update_option( 'adc_roles_version', $version_before );
	wp_roles()->for_site();
}
echo "Role management: $checks checks passed.\n";
