<?php
namespace AutoDealership\Admin;

use AutoDealership\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Read-only reference from organizational functions to existing product capabilities. */
final class OrganizationalCapabilityMap {
	private const FUNCTIONS = array(
		'customer_relationships'=>array(
			'label'=>'Customer relationships',
			'purpose'=>'Customer records, conversations, quotations, and reservations.',
			'capabilities'=>array( 'adc_view_own_leads', 'adc_manage_own_leads', 'adc_view_branch_leads', 'adc_manage_branch_leads', 'adc_view_conversations', 'adc_reply_conversations', 'adc_assign_conversations', 'adc_create_quotes', 'adc_request_discounts', 'adc_create_reservations' ),
		),
		'finance_operations'=>array(
			'label'=>'Finance operations',
			'purpose'=>'Financial records, payment recording and verification, and refunds.',
			'capabilities'=>array( 'adc_view_finance', 'adc_manage_finance', 'adc_record_payments', 'adc_verify_payments', 'adc_record_refunds', 'adc_verify_refunds' ),
		),
		'asset_control'=>array(
			'label'=>'Asset and inventory control',
			'purpose'=>'Inventory visibility, movement, identification, and controlled corrections.',
			'capabilities'=>array( 'adc_view_inventory', 'adc_manage_inventory', 'adc_transfer_inventory', 'adc_confirm_vehicle_vin', 'adc_change_vehicle_vin' ),
		),
		'procurement'=>array(
			'label'=>'Procurement',
			'purpose'=>'Purchase requests, approvals, receiving, suppliers, and associated costs.',
			'capabilities'=>array( 'adc_view_purchase_orders', 'adc_create_purchase_orders', 'adc_approve_purchase_orders', 'adc_approve_high_purchase_orders', 'adc_receive_purchase_orders', 'adc_cancel_purchase_orders', 'adc_view_suppliers', 'adc_manage_suppliers', 'adc_view_vehicle_costs', 'adc_manage_vehicle_costs' ),
		),
		'delivery_operations'=>array(
			'label'=>'Delivery operations',
			'purpose'=>'Delivery readiness, asset identity checks, and returns.',
			'capabilities'=>array( 'adc_approve_delivery', 'adc_confirm_vehicle_vin', 'adc_process_returns', 'adc_view_inventory' ),
		),
		'marketing_communications'=>array(
			'label'=>'Marketing and communications',
			'purpose'=>'Marketing lead workflows, campaign measurement, communication activity, and subscriber operations.',
			'capabilities'=>array( 'adc_create_marketing_leads', 'adc_view_marketing_leads', 'adc_edit_marketing_lead_identity', 'adc_view_campaign_metrics', 'adc_add_marketing_activity', 'adc_view_marketing_subscribers', 'adc_manage_newsletter' ),
		),
		'independent_assurance'=>array(
			'label'=>'Independent assurance',
			'purpose'=>'Read-only access to audit and operational oversight information.',
			'capabilities'=>array( 'adc_view_audit', 'adc_view_reports', 'adc_view_outbox', 'adc_view_integrations' ),
		),
		'website_content'=>array(
			'label'=>'Website content',
			'purpose'=>'Editing and publishing product catalog content and related offers.',
			'capabilities'=>array( 'edit_cars', 'edit_others_cars', 'publish_cars', 'edit_car_offers', 'edit_others_car_offers', 'publish_car_offers' ),
		),
	);

	public static function function_ids(): array {
		return array_keys( self::FUNCTIONS );
	}

	/** Return capability identifiers keyed by their related organizational functions. */
	public static function capability_function_ids(): array {
		$catalog = UserPermissions::catalog();
		$assignable = Capabilities::assignable_capabilities();
		$mapping = array();
		foreach ( self::FUNCTIONS as $id => $definition ) {
			foreach ( $definition['capabilities'] as $capability ) {
				if ( isset( $catalog[$capability] ) && in_array( $capability, $assignable, true ) ) {
					$mapping[$capability][] = $id;
				}
			}
		}
		return $mapping;
	}

	/** Report how the assignable catalog is covered by the reference map. */
	public static function coverage_report(): array {
		$catalog = UserPermissions::catalog();
		$assignable = Capabilities::assignable_capabilities();
		$function_mapping = self::capability_function_ids();
		$functions = self::report()['functions'];
		$mapped = array();
		$unmapped = array();
		$shared = array();
		foreach ( $assignable as $capability ) {
			$entry = $catalog[$capability] ?? array( '', UserPermissions::native_label( $capability ) );
			$related_functions = $function_mapping[$capability] ?? array();
			$row = array(
				'key' => $capability,
				'group' => $entry[0],
				'label' => $entry[1],
				'classification' => self::classify_capability( $entry[1] ),
				'classification_review_required' => true,
				'functions' => array_map(
					static fn( string $function_id ): string => $functions[$function_id]['label'],
					$related_functions
				),
			);
			if ( ! $related_functions ) {
				$unmapped[] = $row;
				continue;
			}
			$mapped[] = $row;
			if ( count( $related_functions ) > 1 ) {
				$shared[] = $row;
			}
		}
		return array(
			'total' => count( $assignable ),
			'mapped_count' => count( $mapped ),
			'unmapped_count' => count( $unmapped ),
			'shared_count' => count( $shared ),
			'mapped' => $mapped,
			'unmapped' => $unmapped,
			'shared' => $shared,
			'classifications' => 'preliminary_needs_human_review',
			'classification_review_required' => true,
			'permission_recommendations' => false,
		);
	}

	private static function classify_capability( string $label ): string {
		$description = strtolower( $label );
		if ( preg_match( '/\b(approve|approval|verify|verification|moderate)\b/', $description ) ) {
			return 'approval_verification';
		}
		if ( preg_match( '/\b(view|read|access)\b/', $description ) ) {
			return 'read_reference';
		}
		if ( preg_match( '/\b(create|manage|edit|assign|publish|record|transfer|receive|process|reply|close|reopen|change|add|share|update|request|confirm|cancel)\b/', $description ) ) {
			return 'change_operation';
		}
		return 'unclassified';
	}

	/** Return documented candidate mappings; this method never changes grants. */
	public static function report(): array {
		$catalog = UserPermissions::catalog();
		$assignable = Capabilities::assignable_capabilities();
		$functions = array();
		$unmapped = array();
		foreach ( self::FUNCTIONS as $id => $definition ) {
			$items = array();
			foreach ( $definition['capabilities'] as $capability ) {
				if ( ! isset( $catalog[$capability] ) || ! in_array( $capability, $assignable, true ) ) {
					$unmapped[$capability] = $id;
					continue;
				}
				$items[] = array(
					'key'=>$capability,
					'group'=>$catalog[$capability][0],
					'label'=>$catalog[$capability][1],
				);
			}
			$functions[$id] = array(
				'label'=>__( $definition['label'], 'auto-dealership-core' ),
				'purpose'=>__( $definition['purpose'], 'auto-dealership-core' ),
				'capabilities'=>$items,
			);
		}
		return array(
			'mapping_mode'=>'reference_only',
			'automatic_assignment'=>false,
			'functions'=>$functions,
			'unmapped_capabilities'=>$unmapped,
		);
	}

	public static function render_reference_panel(): void {
		$report = self::report();
		echo '<section class="adc-organizational-reference" data-reference-only="true">';
		echo '<h2>' . esc_html__( 'Organizational function reference map', 'auto-dealership-core' ) . '</h2>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This is a read-only reference, not a role template or permission recommendation. Selecting a function here does not grant capabilities. Choose only task-required capabilities for each role, review conflicts and data scope, and test before changing access.', 'auto-dealership-core' ) . '</p></div>';
		echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Organizational function', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Related existing capabilities (reference only)', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $report['functions'] as $function ) {
			$capabilities = array_map(
				static fn( array $capability ): string => sprintf( '%1$s (%2$s)', __( $capability['label'], 'auto-dealership-core' ), $capability['key'] ),
				$function['capabilities']
			);
			echo '<tr><th scope="row">' . esc_html( $function['label'] ) . '<br><small>' . esc_html( $function['purpose'] ) . '</small></th><td>' . esc_html( implode( '; ', $capabilities ) ?: '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $report['unmapped_capabilities'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Some reference entries do not exist in the assignable capability catalog and require review:', 'auto-dealership-core' ) . ' ' . esc_html( implode( ', ', array_keys( $report['unmapped_capabilities'] ) ) ) . '</p></div>';
		}
		self::render_coverage_report();
	}

	private static function render_coverage_report(): void {
		$coverage = self::coverage_report();
		echo '<h3>' . esc_html__( 'Assignable capability coverage (reference audit)', 'auto-dealership-core' ) . '</h3>';
		echo '<section data-capability-coverage-report="read-only" data-capability-classification-review="required">';
		echo '<p>' . esc_html__( 'This catalog-coverage report identifies documented links and gaps only. Catalog groups are shown as provided by the product; they do not determine job fit or permission policy.', 'auto-dealership-core' ) . ' ' . esc_html__( 'Preliminary classifications are based on capability descriptions, require human review, and do not recommend granting permissions.', 'auto-dealership-core' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Assignable capabilities', 'auto-dealership-core' ) . ':</strong> ' . absint( $coverage['total'] ) . ' — <strong>' . esc_html__( 'Mapped to at least one function', 'auto-dealership-core' ) . ':</strong> ' . absint( $coverage['mapped_count'] ) . ' — <strong>' . esc_html__( 'Not mapped to a function', 'auto-dealership-core' ) . ':</strong> ' . absint( $coverage['unmapped_count'] ) . ' — <strong>' . esc_html__( 'Shared by multiple functions', 'auto-dealership-core' ) . ':</strong> ' . absint( $coverage['shared_count'] ) . '</p>';
		foreach (
			array(
				'mapped' => __( 'Capabilities mapped to functions', 'auto-dealership-core' ),
				'unmapped' => __( 'Capabilities not mapped to any function', 'auto-dealership-core' ),
				'shared' => __( 'Capabilities shared by multiple functions', 'auto-dealership-core' ),
			) as $key => $title
		) {
			echo '<details class="adc-capability-coverage" data-coverage-category="' . esc_attr( $key ) . '"><summary>' . esc_html( $title ) . ' (' . absint( $coverage[$key . '_count'] ?? count( $coverage[$key] ) ) . ')</summary>';
			if ( ! $coverage[$key] ) {
				echo '<p>' . esc_html__( 'None.', 'auto-dealership-core' ) . '</p></details>';
				continue;
			}
			echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Product catalog group', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Preliminary classification (human review required)', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'Related functions', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
			foreach ( $coverage[$key] as $capability ) {
				$classifications = array(
					'read_reference' => __( 'Read / reference', 'auto-dealership-core' ),
					'approval_verification' => __( 'Approval / verification', 'auto-dealership-core' ),
					'change_operation' => __( 'Change / operation', 'auto-dealership-core' ),
					'unclassified' => __( 'Unclassified from description', 'auto-dealership-core' ),
				);
				echo '<tr><td><code>' . esc_html( $capability['key'] ) . '</code><br>' . esc_html__( $capability['label'], 'auto-dealership-core' ) . '</td><td>' . esc_html__( $capability['group'], 'auto-dealership-core' ) . '</td><td>' . esc_html( $classifications[$capability['classification']] ) . '</td><td>' . esc_html( implode( ', ', $capability['functions'] ) ?: '—' ) . '</td></tr>';
			}
			echo '</tbody></table></div></details>';
		}
		echo '</section>';
		echo '</section>';
	}
}
