<?php
/** Real WordPress access enforcement and role lifecycle regression checks. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true ); define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use AutoDealership\Security\AccessPolicy;
use AutoDealership\Admin\AccessReviewPage;
use AutoDealership\Admin\RoleManager;
use AutoDealership\Admin\SettingsPage;
use AutoDealership\Audit\AuditLog;

$checks = 0;
$check = static function ( bool $ok, string $message ) use ( &$checks ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; echo "PASS $message\n"; };
$admin = (int) get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) )[0];
$roles_before = get_option( wp_roles()->role_key ); $defs_before = get_option( 'adc_role_definitions', null );
$review_before = get_option( 'adc_access_review_summary', null ); $post_before = $_POST;
$users = array(); $prefix = 'adc_role_governance_' . strtolower( wp_generate_password( 8, false ) );
$password = wp_generate_password( 40 );
try {
	wp_set_current_user( $admin );
	$id = wp_insert_user( array( 'user_login' => $prefix, 'user_pass' => $password, 'role' => 'dealership_finance' ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); } $users[] = $id;
	$user = get_userdata( $id );
	$check( count( AccessPolicy::financial_flags( $user ) ) === 2, 'Review detects payment and refund dual duties.' );
	$user->add_cap( 'adc_view_inventory', false );
	$date = wp_date( 'Y-m-d\TH:i', time() + HOUR_IN_SECONDS );
	$check( true === AccessPolicy::change( $id, 'grant', array( 'cap' => 'adc_view_inventory', 'expires' => $date ), 'Temporary cover' ), 'Grant a time-limited permission.' );
	$check( user_can( $id, 'adc_view_inventory' ), 'Temporary grant overrides an explicit denial.' );
	$check( 'Temporary grant' === AccessReviewPage::report( get_userdata( $id ) )['adc_view_inventory']['source'], 'Effective report identifies temporary source.' );
	ob_start(); \AutoDealership\Admin\UserPermissions::render( get_userdata( $id ) ); $profile = ob_get_clean();
	$check( (bool) preg_match( '/value="adc_view_inventory"\s*>/', $profile ), 'Profile edits retain permanent denial beneath a temporary grant.' );
	$check( is_wp_error( AccessPolicy::change( $id, 'grant', array( 'cap' => 'manage_options', 'expires' => $date ), 'Forged' ) ), 'Temporary grants cannot grant system administration.' );
	$check( is_wp_error( AccessPolicy::change( $id, 'grant', array( 'cap' => 'adc_view_inventory', 'expires' => '2020-01-01T00:00' ), 'Past date' ) ), 'Reject past expiry.' );
	$check( is_wp_error( AccessPolicy::change( $id, 'suspend', array(), '' ) ), 'Require reason for access changes.' );
	$token = WP_Session_Tokens::get_instance( $id )->create( time() + HOUR_IN_SECONDS );
	$check( true === AccessPolicy::change( $id, 'suspend', array(), 'Employee departure' ), 'Suspend account.' );
	$check( ! WP_Session_Tokens::get_instance( $id )->verify( $token ), 'Suspension terminates existing sessions.' );
	$check( ! user_can( $id, 'read' ) && ! user_can( $id, 'adc_view_inventory' ), 'Suspension denies both native and temporary capabilities.' );
	ob_start(); \AutoDealership\Admin\UserPermissions::render( get_userdata( $id ) ); $profile = ob_get_clean();
	$check( (bool) preg_match( '/value="adc_record_payments"\s+checked=/', $profile ), 'Suspension does not erase permanent permissions in the editor.' );
	$check( 0 === AccessPolicy::current_user( $id ), 'Existing authenticated requests are rejected.' );
	$check( is_wp_error( wp_authenticate( $prefix, $password ) ), 'Suspended account cannot authenticate.' );
	$check( true === AccessPolicy::change( $id, 'resume', array(), 'Reinstated' ) && user_can( $id, 'adc_view_inventory' ), 'Resume restores permitted access.' );
	$token = WP_Session_Tokens::get_instance( $id )->create( time() + HOUR_IN_SECONDS );
	$check( true === AccessPolicy::change( $id, 'sessions', array(), 'Session review' ) && ! WP_Session_Tokens::get_instance( $id )->verify( $token ), 'Terminate sessions without suspending the account.' );
	$grants = AccessPolicy::temporary( $id ); $grants['adc_view_inventory']['expires'] = time() - 1; update_user_meta( $id, 'adc_temporary_permissions', $grants );
	$check( ! user_can( $id, 'adc_view_inventory' ), 'Expiry is enforced immediately without running cron.' );
	AccessPolicy::expire( $id, 'adc_view_inventory' );
	$check( ! AccessPolicy::temporary( $id ) && false === get_userdata( $id )->caps['adc_view_inventory'], 'Expiry removes grant and preserves original denial.' );
	$check( is_wp_error( AccessPolicy::change( $admin, 'suspend', array(), 'Protected' ) ), 'System administrator account is protected.' );
	wp_set_current_user( $id );
	$check( is_wp_error( AccessPolicy::change( $id, 'grant', array( 'cap' => 'adc_view_inventory', 'expires' => $date ), 'Self escalation' ) ), 'Employee cannot grant themselves access.' );
	wp_set_current_user( $admin );
	$input = array( 'slug' => $prefix, 'ar' => 'دور حوكمة', 'en' => 'Governance role', 'caps' => array( 'adc_view_inventory' ), 'new' => '1', 'reason' => 'Role regression' );
	$check( $prefix === RoleManager::save_role( $input ), 'Create a role for lifecycle testing.' );
	$user = get_userdata( $id ); $user->set_role( $prefix ); $user->add_role( 'dealership_marketing' );
	$user->add_cap( 'adc_manage_inventory', false );
	$impact = RoleManager::impact( $prefix, array( 'read' => true, 'adc_view_inventory' => true, 'adc_manage_inventory' => true ) );
	$check( 2 === $impact['users'][0]['overrides'] && ! $impact['users'][0]['added'], 'Role preview accounts for per-user overrides.' );
	$input['new'] = '0'; $input['caps'] = array( 'adc_manage_inventory' );
	$preview = RoleManager::save_role( $input, true ); $input['revision'] = $preview['revision'];
	$user->add_cap( 'adc_view_inventory', true );
	$check( is_wp_error( RoleManager::save_role( $input ) ), 'Reject stale role preview after an individual permission change.' );
	$preview = RoleManager::save_role( $input, true ); $input['revision'] = $preview['revision'];
	$check( $prefix === RoleManager::save_role( $input ), 'Apply a current reviewed role change.' );
	$delete = RoleManager::delete_role( $prefix, 'dealership_inventory', 'Role retired', true );
	$check( is_array( $delete ) && 1 === count( $delete['users'] ), 'Preview affected users before deleting a custom role.' );
	$check( 'dealership_inventory' === RoleManager::delete_role( $prefix, 'dealership_inventory', 'Role retired', false, $delete['revision'] ), 'Transfer users and delete custom role.' );
	$user = get_userdata( $id );
	$check( ! get_role( $prefix ) && in_array( 'dealership_inventory', $user->roles, true ) && in_array( 'dealership_marketing', $user->roles, true ) && false === $user->caps['adc_manage_inventory'], 'Transfer preserves other roles and individual overrides.' );
	$check( is_wp_error( RoleManager::delete_role( 'dealership_sales', 'dealership_inventory', 'Forbidden deletion', true ) ), 'Factory roles cannot be deleted.' );
	$_POST = array( 'adc_branch_scope_present' => '1', 'adc_branch_id' => '0', 'adc_branch_ids' => array( '999999999' ), 'adc_branch_nonce' => wp_create_nonce( 'adc_assign_branch_' . $id ) );
	$errors = new WP_Error(); SettingsPage::validate_branch( $errors, true, (object) array( 'ID' => $id ) );
	$check( $errors->has_errors() && ! get_user_meta( $id, 'adc_branch_ids', true ), 'Invalid branches fail validation without changing assignments.' );
	AccessPolicy::maintenance();
	$review = get_option( 'adc_access_review_summary' );
	$check( $review['accounts'] >= 2 && $review['generated_at'] <= time(), 'Scheduled maintenance creates an account review summary.' );
	global $wpdb;
	$events = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . AuditLog::table_name() . " WHERE subject_id=%d AND event_key LIKE 'security.access_%%' AND before_data IS NOT NULL AND after_data IS NOT NULL AND actor_user_id=%d", $id, $admin ) );
	$check( $events >= 4, 'Access changes audit actor, reason and full before/after state.' );
} finally {
	wp_set_current_user( $admin ); $_POST = $post_before;
	foreach ( $users as $id ) { wp_clear_scheduled_hook( 'adc_expire_user_permission', array( $id, 'adc_view_inventory' ) ); wp_delete_user( $id ); }
	update_option( wp_roles()->role_key, $roles_before );
	if ( null === $defs_before ) { delete_option( 'adc_role_definitions' ); } else { update_option( 'adc_role_definitions', $defs_before, false ); }
	if ( null === $review_before ) { delete_option( 'adc_access_review_summary' ); } else { update_option( 'adc_access_review_summary', $review_before, false ); }
	wp_roles()->for_site();
}
echo "Access governance: $checks checks passed.\n";
