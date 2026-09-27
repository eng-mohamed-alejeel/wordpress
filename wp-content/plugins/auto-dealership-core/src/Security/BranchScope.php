<?php
namespace AutoDealership\Security;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Shared branch policy. A branch assignment never grants an action capability. */
final class BranchScope {
	private static array $active = array();

	public static function is_global( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	public static function assigned_branch( ?int $user_id = null ): int {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id < 1 ) {
			return 0;
		}
		$value = get_user_meta( $user_id, 'adc_branch_id', true );
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return 0;
		}
		$branch_id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $branch_id ? 0 : $branch_id;
	}

	/** Stored assignments; active status is applied at the authorization boundary. */
	public static function assigned_branches( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id < 1 ) { return array(); }
		$primary = self::assigned_branch( $user_id );
		$stored = get_user_meta( $user_id, 'adc_branch_ids', true );
		$values = is_array( $stored ) ? $stored : array();
		if ( $primary > 0 ) { array_unshift( $values, $primary ); }
		$branches = array();
		foreach ( $values as $value ) {
			if ( ! is_int( $value ) && ! is_string( $value ) ) { continue; }
			$branch_id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false !== $branch_id ) { $branches[ (int) $branch_id ] = (int) $branch_id; }
		}
		return array_values( $branches );
	}

	public static function allows( int $branch_id, ?int $user_id = null ): bool {
		if ( $branch_id < 0 ) {
			return false;
		}
		// Only administrators can triage intake with no branch (branch_id = 0).
		return self::is_global( $user_id ) || ( $branch_id > 0 && in_array( $branch_id, self::assigned_branches( $user_id ), true ) && self::is_active( $branch_id ) );
	}

	public static function is_active( int $branch_id ): bool {
		global $wpdb;
		if ( $branch_id < 1 ) { return false; }
		if ( ! array_key_exists( $branch_id, self::$active ) ) {
			self::$active[ $branch_id ] = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT active FROM ' . Schema::table( 'branches' ) . ' WHERE id = %d', $branch_id ) );
		}
		return self::$active[ $branch_id ];
	}

	/** Clear request-local state after an administrator changes branch activity. */
	public static function clear_cache(): void {
		self::$active = array();
	}

	/** Returns a predicate and prepare arguments. Column names must be code-owned. */
	public static function predicate( string $column, ?int $user_id = null ): array {
		if ( ! preg_match( '/\A[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?\z/i', $column ) ) {
			throw new \InvalidArgumentException( 'Invalid branch column.' );
		}
		if ( self::is_global( $user_id ) ) {
			return array( '1 = 1', array() );
		}
		$branches = array_values( array_filter( self::assigned_branches( $user_id ), array( self::class, 'is_active' ) ) );
		if ( ! $branches ) { return array( '1 = 0', array() ); }
		if ( 1 === count( $branches ) ) { return array( $column . ' = %d', $branches ); }
		return array( $column . ' IN (' . implode( ',', array_fill( 0, count( $branches ), '%d' ) ) . ')', $branches );
	}

	public static function can_manage_lead( array $lead ): bool {
		if ( ! isset( $lead['branch_id'], $lead['owner_user_id'] ) || ! self::allows( (int) $lead['branch_id'] ) ) {
			return false;
		}
		return self::is_global()
			|| current_user_can( 'adc_manage_branch_leads' )
			|| ( current_user_can( 'adc_manage_own_leads' ) && get_current_user_id() === (int) $lead['owner_user_id'] );
	}
}
