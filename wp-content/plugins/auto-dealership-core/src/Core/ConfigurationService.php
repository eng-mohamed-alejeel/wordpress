<?php
namespace AutoDealership\Core;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Compensates WordPress option/meta writes when their required audit cannot be persisted. */
final class ConfigurationService {
	private const OPTIONS = array( 'adc_vat_rate_bps', 'adc_reservation_hours', 'adc_sales_manager_discount_limit', 'adc_default_branch_id', 'adc_privacy_retention_days' );

	public static function update( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية حفظ الإعدادات.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$default_branch = absint( $input['default_branch_id'] ?? 0 );
		$retention_days = absint( $input['privacy_retention_days'] ?? get_option( 'adc_privacy_retention_days', 0 ) );
		if ( 0 !== $retention_days && ( $retention_days < 30 || $retention_days > 3650 ) ) { return new \WP_Error( 'adc_invalid_retention', __( 'Retention must be disabled or between 30 and 3650 days.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
		if ( $default_branch && ! VehicleService::branch_exists( $default_branch ) ) { return new \WP_Error( 'adc_invalid_default_branch', __( 'الفرع الافتراضي غير نشط أو غير موجود.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
		$before_state = self::state();
		$before = array_map( static fn( $item ) => $item['value'], $before_state );
		$after = array(
			'adc_vat_rate_bps' => min( 10000, absint( $input['vat_rate_bps'] ?? 0 ) ),
			'adc_reservation_hours' => min( 168, max( 1, absint( $input['reservation_hours'] ?? 24 ) ) ),
			'adc_sales_manager_discount_limit' => absint( $input['sales_manager_discount_limit'] ?? 0 ),
			'adc_default_branch_id' => $default_branch,
			'adc_privacy_retention_days' => $retention_days,
		);
		if ( $before === $after ) { return array( 'updated' => false ); }
		foreach ( $after as $key => $value ) { update_option( $key, $value, false ); }
		if ( self::values() !== $after || ! AuditLog::record( 'settings.updated', 'settings', 0, 'Dealership settings changed', self::public_keys( $before ), self::public_keys( $after ) ) ) {
			self::restore_options( $before_state );
			return new \WP_Error( 'adc_settings_failed', __( 'تعذر حفظ الإعدادات وتوثيقها.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'updated' => true );
	}

	public static function assign_branch( int $user_id, int $branch_id ) {
		return self::assign_branches( $user_id, $branch_id, $branch_id > 0 ? array( $branch_id ) : array() );
	}

	public static function assign_branches( int $user_id, int $primary_branch_id, array $branch_ids ) {
		if ( $user_id < 1 || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'Branch assignment permission is required.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$branches = array();
		foreach ( $branch_ids as $value ) {
			if ( ! is_int( $value ) && ! is_string( $value ) ) { return new \WP_Error( 'adc_invalid_branch', __( 'Every assigned branch must be active and valid.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
			$branch_id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $branch_id || ! VehicleService::branch_exists( (int) $branch_id ) ) { return new \WP_Error( 'adc_invalid_branch', __( 'Every assigned branch must be active and valid.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
			$branches[ (int) $branch_id ] = (int) $branch_id;
		}
		$branches = array_values( $branches );
		if ( count( $branches ) > 25 || $primary_branch_id < 0 || ( 0 === $primary_branch_id && $branches ) || ( $primary_branch_id > 0 && ! in_array( $primary_branch_id, $branches, true ) ) ) {
			return new \WP_Error( 'adc_invalid_branch', __( 'The primary branch must be included in the allowed branches.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( $primary_branch_id > 0 ) {
			$branches = array_merge( array( $primary_branch_id ), array_values( array_diff( $branches, array( $primary_branch_id ) ) ) );
		}
		$primary_existed = metadata_exists( 'user', $user_id, 'adc_branch_id' );
		$list_existed = metadata_exists( 'user', $user_id, 'adc_branch_ids' );
		$before_primary = BranchScope::assigned_branch( $user_id );
		$before_branches = BranchScope::assigned_branches( $user_id );
		if ( $before_primary === $primary_branch_id && $before_branches === $branches && $primary_existed && $list_existed ) {
			return array( 'updated' => false, 'branch_id' => $primary_branch_id, 'branch_ids' => $branches );
		}
		update_user_meta( $user_id, 'adc_branch_id', $primary_branch_id );
		update_user_meta( $user_id, 'adc_branch_ids', $branches );
		BranchScope::clear_cache();
		$verified = BranchScope::assigned_branch( $user_id ) === $primary_branch_id && BranchScope::assigned_branches( $user_id ) === $branches;
		$audited = $verified && AuditLog::record( 'user.branches_assigned', 'user', $user_id, '', array( 'primary_branch_id' => $before_primary, 'branch_ids' => $before_branches ), array( 'primary_branch_id' => $primary_branch_id, 'branch_ids' => $branches ) );
		if ( ! $audited ) {
			$primary_existed ? update_user_meta( $user_id, 'adc_branch_id', $before_primary ) : delete_user_meta( $user_id, 'adc_branch_id' );
			$list_existed ? update_user_meta( $user_id, 'adc_branch_ids', $before_branches ) : delete_user_meta( $user_id, 'adc_branch_ids' );
			BranchScope::clear_cache();
			return new \WP_Error( 'adc_branch_assignment_failed', __( 'Could not save and audit the branch assignments.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'updated' => true, 'branch_id' => $primary_branch_id, 'branch_ids' => $branches );
	}

	private static function values(): array {
		return array(
			'adc_vat_rate_bps' => absint( get_option( 'adc_vat_rate_bps', 0 ) ),
			'adc_reservation_hours' => absint( get_option( 'adc_reservation_hours', 24 ) ),
			'adc_sales_manager_discount_limit' => absint( get_option( 'adc_sales_manager_discount_limit', 0 ) ),
			'adc_default_branch_id' => absint( get_option( 'adc_default_branch_id', 0 ) ),
			'adc_privacy_retention_days' => absint( get_option( 'adc_privacy_retention_days', 0 ) ),
		);
	}

	private static function state(): array {
		$values = self::values();
		$state = array();
		foreach ( self::OPTIONS as $key ) {
			$sentinel = new \stdClass();
			$state[ $key ] = array( 'exists' => $sentinel !== get_option( $key, $sentinel ), 'value' => $values[ $key ] );
		}
		return $state;
	}

	private static function public_keys( array $values ): array {
		return array_combine( array_map( static fn( $key ) => substr( $key, 4 ), array_keys( $values ) ), array_values( $values ) );
	}

	private static function restore_options( array $state ): void {
		foreach ( self::OPTIONS as $key ) {
			$state[ $key ]['exists'] ? update_option( $key, $state[ $key ]['value'], false ) : delete_option( $key );
		}
	}
}
