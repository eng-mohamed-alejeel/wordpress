<?php
/** Temporary role sessions for the AD-033 mobile acceptance test. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( untrailingslashit( get_option( 'siteurl' ) ) !== 'http://localhost/wordpress' ) { exit( 2 ); }

$path = ABSPATH . '.tmp/ad033-mobile-roles-fixture.json';
$roles = array_values( array_diff( array_keys( \AutoDealership\Core\Capabilities::role_matrix() ), array( 'car_dealer_customer' ) ) );

if ( '--prepare' === ( $argv[1] ?? '' ) ) {
	if ( is_file( $path ) ) { throw new RuntimeException( 'Clean up the previous AD-033 fixture first.' ); }
	$branches = array_values( array_filter( \AutoDealership\Branches\BranchService::public_list(), static fn( array $branch ): bool => ! empty( $branch['active'] ) ) );
	$branch_id = $branches ? (int) $branches[0]['id'] : 0;
	$users = array();
	$expiry = time() + 1800;
	foreach ( $roles as $role ) {
		if ( ! get_role( $role ) ) { throw new RuntimeException( 'Missing role: ' . $role ); }
		$id = wp_insert_user( array(
			'user_login'   => 'adc_ad033_' . strtolower( wp_generate_password( 10, false ) ),
			'user_email'   => $role . '@example.invalid',
			'user_pass'    => wp_generate_password( 40 ),
			'display_name' => 'AD-033 ' . $role,
			'role'         => $role,
		) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		if ( $branch_id > 0 ) {
			update_user_meta( $id, 'adc_branch_id', $branch_id );
			update_user_meta( $id, 'adc_branch_ids', array( $branch_id ) );
		}
		$token = WP_Session_Tokens::get_instance( $id )->create( $expiry );
		$users[ $role ] = array(
			'id'      => (int) $id,
			'token'   => $token,
			'cookies' => array(
				array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $id, $expiry, 'auth', $token ) ),
				array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $id, $expiry, 'logged_in', $token ) ),
			),
		);
	}
	file_put_contents( $path, wp_json_encode( array( 'users' => $users ) ) );
	echo "AD-033 mobile role fixture prepared.\n";
} elseif ( '--cleanup' === ( $argv[1] ?? '' ) && is_file( $path ) ) {
	$data = json_decode( file_get_contents( $path ), true );
	foreach ( (array) ( $data['users'] ?? array() ) as $user ) {
		if ( ! empty( $user['id'] ) && ! empty( $user['token'] ) ) { WP_Session_Tokens::get_instance( (int) $user['id'] )->destroy( $user['token'] ); }
		if ( ! empty( $user['id'] ) ) { wp_delete_user( (int) $user['id'] ); }
	}
	unlink( $path );
	echo "AD-033 mobile role fixture removed.\n";
}
