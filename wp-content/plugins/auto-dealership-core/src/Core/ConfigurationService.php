<?php
namespace AutoDealership\Core;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Compensates WordPress option/meta writes when their required audit cannot be persisted. */
final class ConfigurationService {
	private const OPTIONS = array(
		'adc_vat_rate_bps', 'adc_pricing_fee_amount', 'adc_promotion_code', 'adc_promotion_type',
		'adc_promotion_value', 'adc_promotion_starts_at', 'adc_promotion_ends_at',
		'adc_reservation_hours', 'adc_reservation_deposit_type', 'adc_reservation_deposit_value',
		'adc_sales_manager_discount_limit', 'adc_general_manager_discount_limit',
		'adc_seller_name', 'adc_seller_tax_number', 'adc_seller_address', 'adc_seller_phone',
		'adc_delivery_required_documents', 'adc_default_branch_id', 'adc_privacy_retention_days',
		'adc_public_catalog_mode',
	);
	private const DELIVERY_DOCUMENTS = array( 'invoice', 'customer_identity', 'vehicle_registration', 'insurance', 'handover_form', 'finance_clearance' );

	/** Merge a section with persisted values before validating the complete policy. */
	public static function update_section( array $input, array $fields ) {
		$current = array();
		foreach ( self::values() as $key => $value ) { $current[substr( $key, 4 )] = $value; }
		return self::update( array_replace( $current, array_intersect_key( $input, array_flip( $fields ) ) ) );
	}

	public static function update( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية حفظ الإعدادات.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		$default_branch = absint( $input['default_branch_id'] ?? 0 );
		$retention_days = absint( $input['privacy_retention_days'] ?? get_option( 'adc_privacy_retention_days', 0 ) );
		$promotion_type = sanitize_key( (string) ( $input['promotion_type'] ?? 'none' ) );
		$current_catalog_mode = PublicCatalog::mode();
		$catalog_mode = sanitize_key( (string) ( $input['public_catalog_mode'] ?? $current_catalog_mode ) );
		$deposit_type = sanitize_key( (string) ( $input['reservation_deposit_type'] ?? 'none' ) );
		$promotion_start = self::date( $input['promotion_starts_at'] ?? '' );
		$promotion_end = self::date( $input['promotion_ends_at'] ?? '' );
		$manager_limit = absint( $input['sales_manager_discount_limit'] ?? 0 );
		$general_limit = absint( $input['general_manager_discount_limit'] ?? PHP_INT_MAX );
		$promotion_value = absint( $input['promotion_value'] ?? 0 );
		$deposit_value = absint( $input['reservation_deposit_value'] ?? 0 );
		$promotion_code = sanitize_key( (string) ( $input['promotion_code'] ?? '' ) );
		$seller_name = sanitize_text_field( (string) ( $input['seller_name'] ?? '' ) );
		if ( '' === $seller_name ) { $seller_name = sanitize_text_field( get_bloginfo( 'name' ) ); }
		if ( 'none' === $promotion_type ) { $promotion_code = ''; $promotion_value = 0; $promotion_start = ''; $promotion_end = ''; }
		if ( 'none' === $deposit_type ) { $deposit_value = 0; }
		$documents = array_values( array_intersect( self::DELIVERY_DOCUMENTS, array_map( 'sanitize_key', (array) ( $input['delivery_required_documents'] ?? array() ) ) ) );
		if ( 0 !== $retention_days && ( $retention_days < 30 || $retention_days > 3650 ) ) { return new \WP_Error( 'adc_invalid_retention', __( 'Retention must be disabled or between 30 and 3650 days.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
		if ( ! in_array( $catalog_mode, array( PublicCatalog::MODE_COMPATIBILITY, PublicCatalog::MODE_AUTHORITATIVE ), true ) || ! in_array( $promotion_type, array( 'none', 'fixed', 'percentage' ), true ) || ! in_array( $deposit_type, array( 'none', 'fixed', 'percentage' ), true ) || false === $promotion_start || false === $promotion_end || ( $promotion_start && $promotion_end && $promotion_start > $promotion_end ) || $general_limit < $manager_limit || ( 'none' !== $promotion_type && ( '' === $promotion_code || $promotion_value < 1 ) ) || ( 'none' !== $deposit_type && $deposit_value < 1 ) || ( 'percentage' === $promotion_type && $promotion_value >= 10000 ) || ( 'percentage' === $deposit_type && $deposit_value >= 10000 ) ) {
			return new \WP_Error( 'adc_invalid_policy', __( 'Pricing, approval, deposit or date policy is invalid.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( PublicCatalog::MODE_AUTHORITATIVE === $catalog_mode && PublicCatalog::MODE_AUTHORITATIVE !== $current_catalog_mode && ! PublicCatalog::readiness()['ready'] ) {
			return new \WP_Error( 'adc_catalog_not_ready', __( 'The authoritative catalog cannot be enabled until published vehicle mappings and schema checks are reconciled.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( $default_branch && ! VehicleService::branch_exists( $default_branch ) ) { return new \WP_Error( 'adc_invalid_default_branch', __( 'الفرع الافتراضي غير نشط أو غير موجود.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
		$before_state = self::state();
		$before = array_map( static fn( $item ) => $item['value'], $before_state );
		$after = array(
			'adc_vat_rate_bps' => min( 10000, absint( $input['vat_rate_bps'] ?? 0 ) ),
			'adc_pricing_fee_amount' => absint( $input['pricing_fee_amount'] ?? 0 ),
			'adc_promotion_code' => $promotion_code,
			'adc_promotion_type' => $promotion_type,
			'adc_promotion_value' => $promotion_value,
			'adc_promotion_starts_at' => $promotion_start,
			'adc_promotion_ends_at' => $promotion_end,
			'adc_reservation_hours' => min( 168, max( 1, absint( $input['reservation_hours'] ?? 24 ) ) ),
			'adc_reservation_deposit_type' => $deposit_type,
			'adc_reservation_deposit_value' => $deposit_value,
			'adc_sales_manager_discount_limit' => $manager_limit,
			'adc_general_manager_discount_limit' => $general_limit,
			'adc_seller_name' => $seller_name,
			'adc_seller_tax_number' => sanitize_text_field( (string) ( $input['seller_tax_number'] ?? '' ) ),
			'adc_seller_address' => sanitize_textarea_field( (string) ( $input['seller_address'] ?? '' ) ),
			'adc_seller_phone' => sanitize_text_field( (string) ( $input['seller_phone'] ?? '' ) ),
			'adc_delivery_required_documents' => $documents,
			'adc_default_branch_id' => $default_branch,
			'adc_privacy_retention_days' => $retention_days,
			'adc_public_catalog_mode' => $catalog_mode,
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
			'adc_pricing_fee_amount' => absint( get_option( 'adc_pricing_fee_amount', 0 ) ),
			'adc_promotion_code' => sanitize_key( (string) get_option( 'adc_promotion_code', '' ) ),
			'adc_promotion_type' => sanitize_key( (string) get_option( 'adc_promotion_type', 'none' ) ),
			'adc_promotion_value' => absint( get_option( 'adc_promotion_value', 0 ) ),
			'adc_promotion_starts_at' => (string) get_option( 'adc_promotion_starts_at', '' ),
			'adc_promotion_ends_at' => (string) get_option( 'adc_promotion_ends_at', '' ),
			'adc_reservation_hours' => absint( get_option( 'adc_reservation_hours', 24 ) ),
			'adc_reservation_deposit_type' => sanitize_key( (string) get_option( 'adc_reservation_deposit_type', 'none' ) ),
			'adc_reservation_deposit_value' => absint( get_option( 'adc_reservation_deposit_value', 0 ) ),
			'adc_sales_manager_discount_limit' => absint( get_option( 'adc_sales_manager_discount_limit', 0 ) ),
			'adc_general_manager_discount_limit' => absint( get_option( 'adc_general_manager_discount_limit', PHP_INT_MAX ) ),
			'adc_seller_name' => sanitize_text_field( (string) get_option( 'adc_seller_name', get_bloginfo( 'name' ) ) ),
			'adc_seller_tax_number' => sanitize_text_field( (string) get_option( 'adc_seller_tax_number', '' ) ),
			'adc_seller_address' => sanitize_textarea_field( (string) get_option( 'adc_seller_address', '' ) ),
			'adc_seller_phone' => sanitize_text_field( (string) get_option( 'adc_seller_phone', '' ) ),
			'adc_delivery_required_documents' => array_values( array_intersect( self::DELIVERY_DOCUMENTS, (array) get_option( 'adc_delivery_required_documents', array() ) ) ),
			'adc_default_branch_id' => absint( get_option( 'adc_default_branch_id', 0 ) ),
			'adc_privacy_retention_days' => absint( get_option( 'adc_privacy_retention_days', 0 ) ),
			'adc_public_catalog_mode' => PublicCatalog::mode(),
		);
	}

	private static function date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) { return ''; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : false;
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
