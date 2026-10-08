<?php
/** Real WordPress authorization, persistence and bilingual profile regression checks. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true );
define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AutoDealership\Admin\UserPermissions;
use AutoDealership\Core\Capabilities;

$count = 0;
$check = static function ( bool $ok, string $message ) use ( &$count ): void {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	++$count;
	echo "PASS $message\n";
};
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$id = wp_insert_user( array( 'user_login' => 'adc_permissions_' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'dealership_sales' ) );
if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
$old_post = $_POST;
$submit = static function ( array $selected, bool $custom = true, ?string $nonce = null ) use ( $id ): WP_Error {
	$_POST = array( 'adc_permissions_present' => '1', 'adc_permissions_nonce' => $nonce ?? wp_create_nonce( 'adc_user_permissions_' . $id ), 'adc_permissions' => $selected, 'adc_permissions_reason' => 'Permission regression test' );
	if ( $custom ) { $_POST['adc_permissions_custom'] = '1'; }
	$errors = new WP_Error();
	UserPermissions::validate( $errors, true, (object) array( 'ID' => $id ) );
	if ( ! $errors->has_errors() ) { wp_update_user( array( 'ID' => $id, 'description' => wp_generate_password( 8, false ) ) ); }
	return $errors;
};
try {
	wp_set_current_user( $admin );
	$caps = Capabilities::assignable_capabilities();
	$catalog = array_keys( UserPermissions::catalog() );
	sort( $caps ); sort( $catalog );
	$check( $caps === $catalog, 'Catalog covers every assignable permission exactly.' );
	$check( ! $submit( array( 'adc_view_workspace', 'adc_view_inventory' ) )->has_errors(), 'Administrator can save checked permissions.' );
	$check( user_can( $id, 'adc_view_inventory' ) && ! user_can( $id, 'adc_view_own_leads' ), 'Checked permission grants access; unchecked inherited permission is denied.' );
	$check( ! user_can( $id, 'manage_options' ) && user_can( $id, 'read' ), 'WordPress administration and basic login permissions are preserved.' );
	$user = get_userdata( $id ); $user->set_role( 'dealership_inventory' );
	$check( ! user_can( $id, 'adc_manage_inventory' ), 'Custom deny survives a role change.' );
	$check( ! $submit( array(), false )->has_errors() && user_can( $id, 'adc_manage_inventory' ), 'Reset restores new role defaults.' );
	$check( ! array_intersect( array_keys( get_userdata( $id )->caps ), $caps ), 'Reset removes all individual overrides.' );
	$check( $submit( array( 'manage_options' ) )->has_errors() && ! user_can( $id, 'manage_options' ), 'Forged system capability is rejected.' );
	$check( $submit( array( 'adc_view_inventory' ), true, 'invalid' )->has_errors(), 'Invalid nonce is rejected.' );
	wp_set_current_user( $id );
	$check( $submit( array( 'adc_view_finance' ) )->has_errors() && ! user_can( $id, 'adc_view_finance' ), 'Employee cannot grant their own permissions.' );
	wp_set_current_user( $admin );
	$_POST = array( 'adc_permissions_present' => '1', 'adc_permissions_nonce' => wp_create_nonce( 'adc_user_permissions_' . $admin ) );
	$errors = new WP_Error(); UserPermissions::validate( $errors, true, (object) array( 'ID' => $admin ) );
	$check( $errors->has_errors() && user_can( $admin, 'manage_options' ), 'Administrator account is protected.' );
	$language = 'ar';
	$locale_filter = static function () use ( &$language ): string { return $language; };
	add_filter( 'locale', $locale_filter );
	foreach ( array( 'ar', 'en_US' ) as $language ) {
		Capabilities::localize_roles();
		foreach ( array_keys( Capabilities::role_matrix() ) as $role ) {
			$label = wp_roles()->role_names[$role];
			$check( (bool) preg_match( '/[\x{0600}-\x{06ff}]/u', $label ) === ( 'ar' === $language ), 'Role language: ' . $role . '/' . $language );
		}
		ob_start(); UserPermissions::render( get_userdata( $id ) ); $html = ob_get_clean();
		$check( substr_count( $html, 'name="adc_permissions[]"' ) === count( $caps ) && ! str_contains( $html, '????' ), 'Complete profile renders in ' . $language );
	}
	remove_filter( 'locale', $locale_filter );
} finally {
	$_POST = $old_post;
	wp_set_current_user( $admin );
	wp_delete_user( $id );
}
echo "User permissions: $count checks passed.\n";
