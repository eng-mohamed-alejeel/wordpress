<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Registers scoped dealership roles and capabilities. */
final class Capabilities {
	private const CONTENT_CAPABILITIES = array(
		'edit_car', 'read_car', 'delete_car', 'edit_cars', 'edit_others_cars', 'publish_cars',
		'read_private_cars', 'delete_cars', 'delete_private_cars', 'delete_published_cars',
		'delete_others_cars', 'edit_private_cars', 'edit_published_cars',
		'edit_car_offer', 'read_car_offer', 'delete_car_offer', 'edit_car_offers', 'edit_others_car_offers',
		'publish_car_offers', 'read_private_car_offers', 'delete_car_offers', 'delete_private_car_offers',
		'delete_published_car_offers', 'delete_others_car_offers', 'edit_private_car_offers', 'edit_published_car_offers',
		'manage_car_brands', 'assign_car_brands', 'manage_car_categories', 'assign_car_categories',
	);

	private const ROLE_CAPABILITIES = array(
		'car_dealer_customer' => array( 'read' ),
		'dealership_sales' => array( 'read', 'adc_view_workspace', 'adc_view_own_leads', 'adc_manage_own_leads', 'adc_create_reservations' ),
		'dealership_sales_manager' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_create_reservations', 'adc_review_discounts', 'adc_manage_reservations', 'adc_approve_sales', 'adc_cancel_sales', 'adc_approve_delivery', 'adc_process_returns', 'adc_view_reports' ),
		'dealership_general_manager' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_view_inventory', 'adc_change_vehicle_vin', 'adc_create_reservations', 'adc_review_discounts', 'adc_approve_high_discounts', 'adc_manage_reservations', 'adc_approve_sales', 'adc_cancel_sales', 'adc_approve_delivery', 'adc_process_returns', 'adc_manage_pricing', 'adc_view_finance', 'adc_view_vehicle_costs', 'adc_manage_vehicle_costs', 'adc_view_suppliers', 'adc_manage_suppliers', 'adc_view_audit', 'adc_view_outbox', 'adc_manage_outbox', 'adc_view_integrations', 'adc_manage_integrations', 'adc_view_reports', 'adc_view_marketing_subscribers' ),
		'dealership_inventory' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_manage_inventory', 'adc_transfer_inventory', 'adc_confirm_vehicle_vin', 'adc_view_suppliers' ),
		'dealership_finance' => array( 'read', 'adc_view_workspace', 'adc_view_finance', 'adc_manage_finance', 'adc_record_payments', 'adc_verify_payments', 'adc_record_refunds', 'adc_verify_refunds' ),
		'dealership_purchasing' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_view_suppliers', 'adc_manage_suppliers', 'adc_view_vehicle_costs', 'adc_manage_vehicle_costs' ),
		'dealership_delivery' => array( 'read', 'adc_view_workspace', 'adc_view_inventory', 'adc_confirm_vehicle_vin', 'adc_process_returns' ),
		'dealership_customer_service' => array( 'read', 'adc_view_workspace', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_create_reservations' ),
		'dealership_marketing' => array( 'read', 'adc_view_workspace', 'adc_view_reports', 'adc_view_marketing_subscribers' ),
		'dealership_auditor' => array( 'read', 'adc_view_workspace', 'adc_view_audit', 'adc_view_outbox', 'adc_view_integrations', 'adc_view_reports' ),
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
			'car_dealer_customer' => __( 'Dealership Customer', 'auto-dealership-core' ),
			'dealership_sales' => __( 'Dealership Sales', 'auto-dealership-core' ),
			'dealership_sales_manager' => __( 'Dealership Sales Manager', 'auto-dealership-core' ),
			'dealership_general_manager' => __( 'Dealership General Manager', 'auto-dealership-core' ),
			'dealership_inventory' => __( 'Dealership Inventory', 'auto-dealership-core' ),
			'dealership_finance' => __( 'Dealership Finance', 'auto-dealership-core' ),
			'dealership_purchasing' => __( 'Dealership Purchasing', 'auto-dealership-core' ),
			'dealership_delivery' => __( 'Dealership Delivery', 'auto-dealership-core' ),
			'dealership_customer_service' => __( 'Dealership Customer Service', 'auto-dealership-core' ),
			'dealership_marketing' => __( 'Dealership Marketing', 'auto-dealership-core' ),
			'dealership_auditor' => __( 'Dealership Auditor', 'auto-dealership-core' ),
		);
		return $labels[ $slug ] ?? $slug;
	}

	/** Read-only role matrix used by documentation and administrative diagnostics. */
	public static function role_matrix(): array {
		return self::ROLE_CAPABILITIES;
	}

	private static function all_capabilities(): array {
		$caps = self::CONTENT_CAPABILITIES;
		foreach ( self::ROLE_CAPABILITIES as $role_caps ) {
			$caps = array_merge( $caps, $role_caps );
		}
		return array_values( array_unique( $caps ) );
	}
}
