<?php
namespace AutoDealership\Security;

use AutoDealership\Admin\RoleManager;
use AutoDealership\Core\Capabilities;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Enforce account suspension and time-limited grants at every capability boundary. */
final class AccessPolicy {
	public static function boot(): void {
		add_filter( 'user_has_cap', array( self::class, 'capabilities' ), PHP_INT_MAX, 4 );
		add_filter( 'authenticate', array( self::class, 'authenticate' ), PHP_INT_MAX, 3 );
		add_filter( 'determine_current_user', array( self::class, 'current_user' ), PHP_INT_MAX );
		add_action( 'wp_login', array( self::class, 'login' ), 40, 2 );
		add_action( 'adc_access_maintenance', array( self::class, 'maintenance' ) );
		add_action( 'adc_expire_user_permission', array( self::class, 'expire' ), 10, 2 );
		if ( ! wp_next_scheduled( 'adc_access_maintenance' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'adc_access_maintenance' ); }
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'adc_access_maintenance' );
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks['adc_expire_user_permission'] ?? array() as $event ) { wp_unschedule_event( $timestamp, 'adc_expire_user_permission', $event['args'] ); }
		}
	}

	public static function protected_user( \WP_User $user ): bool {
		return in_array( 'administrator', $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
	}

	public static function suspended( int $id ): bool {
		$user = get_userdata( $id );
		return $user && ! self::protected_user( $user ) && '1' === (string) get_user_meta( $id, 'adc_suspended', true );
	}

	public static function temporary( int $id ): array {
		$value = get_user_meta( $id, 'adc_temporary_permissions', true );
		return is_array( $value ) ? $value : array();
	}

	public static function capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( ! $user->ID || self::protected_user( $user ) ) { return $allcaps; }
		if ( '1' === (string) get_user_meta( $user->ID, 'adc_suspended', true ) ) {
			foreach ( $allcaps as $cap => $value ) { $allcaps[$cap] = false; }
			foreach ( $caps as $cap ) { $allcaps[$cap] = false; }
			return $allcaps;
		}
		foreach ( self::temporary( $user->ID ) as $cap => $grant ) {
			if ( in_array( $cap, Capabilities::assignable_capabilities(), true ) && is_array( $grant ) && (int) ( $grant['expires'] ?? 0 ) > time() ) { $allcaps[$cap] = true; }
		}
		return $allcaps;
	}

	public static function authenticate( $user, $username = '', $password = '' ) {
		return $user instanceof \WP_User && self::suspended( $user->ID ) ? new \WP_Error( 'adc_account_suspended', __( 'This account is suspended. Contact the administrator.', 'auto-dealership-core' ) ) : $user;
	}

	public static function current_user( $id ) { return $id && self::suspended( (int) $id ) ? 0 : $id; }
	public static function login( string $login, \WP_User $user ): void { update_user_meta( $user->ID, 'adc_last_login', time() ); }

	public static function change( int $id, string $operation, array $input, string $reason ) {
		$user = get_userdata( $id );
		if ( ! RoleManager::authorized() || ! $user || ! current_user_can( 'edit_user', $id ) || self::protected_user( $user ) ) { return new \WP_Error( 'adc_access_forbidden', __( 'This account is protected or access is denied.', 'auto-dealership-core' ) ); }
		$reason = sanitize_textarea_field( $reason );
		if ( '' === trim( $reason ) || mb_strlen( $reason ) > 2000 ) { return new \WP_Error( 'adc_reason_required', __( 'A change reason is required.', 'auto-dealership-core' ) ); }
		$before = array( 'suspended' => self::suspended( $id ), 'temporary' => self::temporary( $id ), 'session_count' => count( \WP_Session_Tokens::get_instance( $id )->get_all() ) );
		$after = $before;
		if ( 'suspend' === $operation || 'resume' === $operation ) {
			$after['suspended'] = 'suspend' === $operation;
		} elseif ( 'grant' === $operation || 'revoke' === $operation ) {
			$cap = $input['cap'] ?? '';
			if ( ! is_string( $cap ) || ! in_array( $cap, Capabilities::assignable_capabilities(), true ) ) { return new \WP_Error( 'adc_invalid_permission', __( 'Unsupported permission.', 'auto-dealership-core' ) ); }
			if ( 'grant' === $operation ) {
				$expires = $input['expires'] ?? '';
				if ( ! is_string( $expires ) ) { return new \WP_Error( 'adc_invalid_expiry', __( 'Choose a future expiration time.', 'auto-dealership-core' ) ); }
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $expires, wp_timezone() );
				if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $expires || $date->getTimestamp() <= time() || $date->getTimestamp() > time() + YEAR_IN_SECONDS ) { return new \WP_Error( 'adc_invalid_expiry', __( 'Choose a future expiration time within one year.', 'auto-dealership-core' ) ); }
				$after['temporary'][$cap] = array( 'expires' => $date->getTimestamp(), 'granted_by' => get_current_user_id(), 'reason' => $reason );
			} else { unset( $after['temporary'][$cap] ); }
		} elseif ( 'sessions' !== $operation ) { return new \WP_Error( 'adc_invalid_operation', __( 'Unsupported operation.', 'auto-dealership-core' ) ); }
		if ( in_array( $operation, array( 'suspend', 'sessions' ), true ) ) { $after['session_count'] = 0; }
		if ( ! Schema::is_ready() ) { return new \WP_Error( 'adc_schema_unavailable', __( 'The access change could not be saved.', 'auto-dealership-core' ) ); }
		if ( ! \AutoDealership\Database\Transaction::begin() ) { return new \WP_Error( 'adc_audit_failed', __( 'The access change could not be saved.', 'auto-dealership-core' ) ); }
		global $wpdb;
		$locked = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $id ) );
		wp_cache_delete( $id, 'user_meta' );
		$current = array( 'suspended' => self::suspended( $id ), 'temporary' => self::temporary( $id ), 'session_count' => count( \WP_Session_Tokens::get_instance( $id )->get_all() ) );
		if ( ! $locked || $current !== $before ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_access_stale', __( 'Access changed since the preview. Review the impact again.', 'auto-dealership-core' ) ); }
		update_user_meta( $id, 'adc_suspended', $after['suspended'] ? '1' : '0' );
		update_user_meta( $id, 'adc_temporary_permissions', $after['temporary'] );
		$verified = get_user_meta( $id, 'adc_temporary_permissions', true ) === $after['temporary'] && self::suspended( $id ) === $after['suspended'];
		if ( ! \AutoDealership\Database\Transaction::commit( static fn() => $verified && AuditLog::record( 'security.access_' . $operation, 'user', $id, $reason, $before, $after ) ) ) {
			clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' );
			return new \WP_Error( 'adc_audit_failed', __( 'The access change could not be saved.', 'auto-dealership-core' ) );
		}
		if ( in_array( $operation, array( 'suspend', 'sessions' ), true ) ) { \WP_Session_Tokens::get_instance( $id )->destroy_all(); }
		if ( 'grant' === $operation ) { wp_schedule_single_event( $after['temporary'][$cap]['expires'], 'adc_expire_user_permission', array( $id, $cap ) ); }
		return true;
	}

	public static function expire( int $id, string $cap ): void {
		$grants = self::temporary( $id );
		if ( ! isset( $grants[$cap] ) || (int) $grants[$cap]['expires'] > time() ) { return; }
		if ( ! \AutoDealership\Database\Transaction::begin() ) { return; }
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $id ) );
		wp_cache_delete( $id, 'user_meta' ); $grants = self::temporary( $id );
		if ( ! isset( $grants[$cap] ) || (int) $grants[$cap]['expires'] > time() ) { $wpdb->query( 'ROLLBACK' ); return; }
		$before = $grants; unset( $grants[$cap] );
		update_user_meta( $id, 'adc_temporary_permissions', $grants );
		if ( ! \AutoDealership\Database\Transaction::commit( static fn() => get_user_meta( $id, 'adc_temporary_permissions', true ) === $grants && AuditLog::record( 'security.permission_expired', 'user', $id, 'Temporary permission expired', $before, $grants ) ) ) { wp_cache_delete( $id, 'user_meta' ); }
	}

	public static function maintenance(): void {
		$summary = array( 'generated_at' => time(), 'accounts' => 0, 'flagged' => 0, 'suspended' => 0 );
		for ( $page = 1; ; ++$page ) {
			$users = get_users( array( 'number' => 200, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $users as $user ) {
				foreach ( array_keys( self::temporary( $user->ID ) ) as $cap ) { self::expire( $user->ID, $cap ); }
				++$summary['accounts'];
				if ( self::suspended( $user->ID ) ) { ++$summary['suspended']; }
				if ( self::review_flags( $user ) ) { ++$summary['flagged']; }
			}
			if ( count( $users ) < 200 ) { break; }
		}
		update_option( 'adc_access_review_summary', $summary, false );
	}

	public static function financial_flags( \WP_User $user ): array {
		$flags = array();
		foreach ( array( 'Payments: recording and verification' => array( 'adc_record_payments', 'adc_verify_payments' ), 'Refunds: requesting and verification' => array( 'adc_record_refunds', 'adc_verify_refunds' ), 'Discounts: requesting and review' => array( 'adc_view_own_leads', 'adc_review_discounts' ) ) as $label => $caps ) {
			if ( $user->has_cap( $caps[0] ) && $user->has_cap( $caps[1] ) ) { $flags[] = $label; }
		}
		return $flags;
	}

	public static function review_flags( \WP_User $user ): array {
		$flags = self::financial_flags( $user );
		$last = (int) get_user_meta( $user->ID, 'adc_last_login', true );
		if ( $last && $last < time() - 90 * DAY_IN_SECONDS ) { $flags[] = 'No login for 90 days'; }
		if ( ! $last ) { $flags[] = 'No tracked login'; }
		if ( $user->has_cap( 'adc_view_workspace' ) && ! BranchScope::is_global( $user->ID ) && ! array_filter( BranchScope::assigned_branches( $user->ID ), array( BranchScope::class, 'is_active' ) ) ) { $flags[] = 'No active branch assigned'; }
		if ( array_intersect( Capabilities::assignable_capabilities(), array_keys( $user->caps ) ) ) { $flags[] = 'Individual permission overrides'; }
		if ( self::temporary( $user->ID ) ) { $flags[] = 'Temporary permissions'; }
		foreach ( array( 'adc_approve_high_discounts', 'adc_change_vehicle_vin', 'adc_manage_integrations' ) as $cap ) { if ( $user->has_cap( $cap ) ) { $flags[] = 'Sensitive permissions'; break; } }
		return $flags;
	}
}
