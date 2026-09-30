<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Registers scoped dealership roles and capabilities. */
final class Capabilities {
	private const ROLE_CAPABILITIES = array(
		'dealership_sales' => array( 'read', 'adc_view_workspace', 'adc_view_own_leads', 'adc_manage_own_leads', 'adc_create_reservations' ),
		'dealership_sales_manager' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_create_reservations', 'adc_review_discounts', 'adc_manage_reservations', 'adc_approve_sales', 'adc_cancel_sales', 'adc_approve_delivery', 'adc_process_returns', 'adc_view_reports' ),
		'dealership_general_manager' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_view_inventory', 'adc_change_vehicle_vin', 'adc_create_reservations', 'adc_review_discounts', 'adc_approve_high_discounts', 'adc_manage_reservations', 'adc_approve_sales', 'adc_cancel_sales', 'adc_approve_delivery', 'adc_process_returns', 'adc_manage_pricing', 'adc_view_finance', 'adc_view_audit', 'adc_view_outbox', 'adc_manage_outbox', 'adc_view_reports' ),
		'dealership_inventory' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_manage_inventory', 'adc_transfer_inventory', 'adc_confirm_vehicle_vin' ),
		'dealership_finance' => array( 'read', 'adc_view_workspace', 'adc_view_finance', 'adc_manage_finance', 'adc_record_payments', 'adc_verify_payments', 'adc_record_refunds', 'adc_verify_refunds' ),
		'dealership_auditor' => array( 'read', 'adc_view_workspace', 'adc_view_audit', 'adc_view_outbox', 'adc_view_reports' ),
	);

	public static function activate(): void {
		foreach ( self::ROLE_CAPABILITIES as $slug => $capabilities ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				$role = add_role( $slug, self::role_label( $slug ), array( 'read' => true ) );
			}
			if ( $role ) {
				foreach ( $capabilities as $capability ) {
					$role->add_cap( $capability );
				}
			}
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::all_capabilities() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
		update_option( 'adc_roles_version', ADC_VERSION, false );
	}

	private static function role_label( string $slug ): string {
		$labels = array(
			'dealership_sales' => __( 'Dealership Sales', 'auto-dealership-core' ),
			'dealership_sales_manager' => __( 'Dealership Sales Manager', 'auto-dealership-core' ),
			'dealership_general_manager' => __( 'Dealership General Manager', 'auto-dealership-core' ),
			'dealership_inventory' => __( 'Dealership Inventory', 'auto-dealership-core' ),
			'dealership_finance' => __( 'Dealership Finance', 'auto-dealership-core' ),
			'dealership_auditor' => __( 'Dealership Auditor', 'auto-dealership-core' ),
		);
		return $labels[ $slug ] ?? $slug;
	}

	private static function all_capabilities(): array {
		$caps = array();
		foreach ( self::ROLE_CAPABILITIES as $role_caps ) {
			$caps = array_merge( $caps, $role_caps );
		}
		return array_values( array_unique( $caps ) );
	}
}
