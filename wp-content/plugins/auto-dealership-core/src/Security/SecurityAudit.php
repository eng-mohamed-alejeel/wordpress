<?php
namespace AutoDealership\Security;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Security event coverage for account authentication and dealership role changes. */
final class SecurityAudit {
	private static bool $capability_change = false;

	public static function boot(): void {
		add_action( 'wp_login', array( self::class, 'login' ), 30, 2 );
		add_action( 'wp_login_failed', array( self::class, 'login_failed' ), 30, 2 );
		add_action( 'user_register', array( self::class, 'registered' ), 30, 2 );
		add_action( 'set_user_role', array( self::class, 'role_set' ), 30, 3 );
		add_action( 'add_user_role', array( self::class, 'role_added' ), 30, 2 );
		add_action( 'remove_user_role', array( self::class, 'role_removed' ), 30, 2 );
		add_action( 'after_password_reset', array( self::class, 'password_reset' ), 30, 2 );
		add_action( 'deleted_user', array( self::class, 'deleted' ), 30, 1 );
		add_action( 'added_user_meta', array( self::class, 'capability_meta_added' ), 30, 4 );
		add_action( 'updated_user_meta', array( self::class, 'capability_meta_updated' ), 30, 4 );
		add_action( 'deleted_user_meta', array( self::class, 'capability_meta_deleted' ), 30, 4 );
	}

	public static function login( string $user_login, \WP_User $user ): void {
		self::record( 'security.login_succeeded', (int) $user->ID, array( 'roles'=>array_values( $user->roles ) ) );
	}

	public static function login_failed( string $username, $error = null ): void {
		$identity_hash = hash_hmac( 'sha256', strtolower( trim( $username ) ), wp_salt( 'auth' ) );
		$throttle_key = 'adc_login_fail_' . substr( $identity_hash, 0, 32 );
		if ( get_transient( $throttle_key ) ) { return; }
		$user = get_user_by( 'login', $username );
		if ( ! $user && is_email( $username ) ) { $user = get_user_by( 'email', $username ); }
		if ( self::record( 'security.login_failed', $user ? (int) $user->ID : 0, array( 'identity_hash'=>$identity_hash, 'known_account'=>(bool) $user ) ) ) {
			set_transient( $throttle_key, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	public static function registered( int $user_id, array $userdata = array() ): void {
		self::record( 'security.user_registered', $user_id, array( 'roles'=>array_values( (array) ( $userdata['role'] ?? array() ) ) ) );
	}

	public static function role_set( int $user_id, string $role, array $old_roles ): void {
		self::record( 'security.user_role_set', $user_id, array( 'old_roles'=>array_values( $old_roles ), 'new_role'=>$role ) );
	}

	public static function role_added( int $user_id, string $role ): void {
		self::record( 'security.user_role_added', $user_id, array( 'role'=>$role ) );
	}

	public static function role_removed( int $user_id, string $role ): void {
		self::record( 'security.user_role_removed', $user_id, array( 'role'=>$role ) );
	}

	public static function password_reset( \WP_User $user, string $new_pass ): void {
		self::record( 'security.password_reset', (int) $user->ID );
	}

	public static function deleted( int $user_id ): void {
		self::record( 'security.user_deleted', $user_id );
	}

	public static function capability_meta_added( int $meta_id, int $user_id, string $meta_key, $value ): void { self::capability_meta( 'added', $user_id, $meta_key ); }
	public static function capability_meta_updated( int $meta_id, int $user_id, string $meta_key, $value ): void { self::capability_meta( 'updated', $user_id, $meta_key ); }
	public static function capability_meta_deleted( array $meta_ids, int $user_id, string $meta_key, $value ): void { self::capability_meta( 'deleted', $user_id, $meta_key ); }

	private static function capability_meta( string $operation, int $user_id, string $meta_key ): void {
		global $wpdb;
		if ( self::$capability_change || $wpdb->prefix . 'capabilities' !== $meta_key ) { return; }
		self::$capability_change = true;
		self::record( 'security.user_capabilities_' . $operation, $user_id );
		self::$capability_change = false;
	}

	private static function record( string $event, int $user_id, ?array $after = null ): bool {
		if ( ! Schema::is_ready() ) { return false; }
		return AuditLog::record( $event, 'user', max( 0, $user_id ), '', null, $after );
	}
}
