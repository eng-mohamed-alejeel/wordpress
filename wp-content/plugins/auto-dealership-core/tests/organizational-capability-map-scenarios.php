<?php
/** Organizational function/capability reference checks; isolated database only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Admin\OrganizationalCapabilityMap;
use AutoDealership\Admin\RoleManager;
use AutoDealership\Admin\UserPermissions;
use AutoDealership\Core\Capabilities;

$mapping_roles_before = get_option( wp_roles()->role_key );
$mapping_definitions_before = get_option( 'adc_role_definitions', null );
$mapping_report = OrganizationalCapabilityMap::report();
$mapping_coverage_before = OrganizationalCapabilityMap::coverage_report();
adc_check( 'reference_only' === $mapping_report['mapping_mode'] && false === $mapping_report['automatic_assignment'], 'Organizational capability map is explicitly reference-only and never grants permissions.' );
adc_check( array() === $mapping_report['unmapped_capabilities'], 'Every documented function mapping exists in the assignable capability catalog.' );
adc_check( array( 'customer_relationships', 'finance_operations', 'asset_control', 'procurement', 'delivery_operations', 'marketing_communications', 'independent_assurance', 'website_content' ) === array_keys( $mapping_report['functions'] ), 'Reference map exposes stable identifiers for supported organizational functions.' );
$mapping_capability_functions = OrganizationalCapabilityMap::capability_function_ids();
adc_check( in_array( 'asset_control', $mapping_capability_functions['adc_view_inventory'], true ) && in_array( 'delivery_operations', $mapping_capability_functions['adc_view_inventory'], true ), 'A capability can be referenced by multiple organizational functions without duplicating grants.' );
$mapping_coverage = OrganizationalCapabilityMap::coverage_report();
$mapping_catalog = UserPermissions::catalog();
$mapping_assignable = Capabilities::assignable_capabilities();
$mapping_keys = array();
foreach ( $mapping_report['functions'] as $function ) {
	foreach ( $function['capabilities'] as $capability ) {
		$mapping_keys[] = $capability['key'];
		adc_check( isset( $mapping_catalog[$capability['key']] ) && in_array( $capability['key'], $mapping_assignable, true ), 'Mapped capability is catalogued and assignable: ' . $capability['key'] );
	}
}
adc_check( count( $mapping_assignable ) === $mapping_coverage['total'] && $mapping_coverage['total'] === $mapping_coverage['mapped_count'] + $mapping_coverage['unmapped_count'], 'Coverage report partitions the full assignable capability catalog into mapped and unmapped entries.' );
adc_check( count( array_unique( array_merge( array_column( $mapping_coverage['mapped'], 'key' ), array_column( $mapping_coverage['unmapped'], 'key' ) ) ) ) === $mapping_coverage['total'], 'Coverage report lists each assignable capability exactly once across mapped and unmapped lists.' );
adc_check( count( array_unique( array_column( $mapping_coverage['mapped'], 'key' ) ) ) === $mapping_coverage['mapped_count'] && count( $mapping_coverage['shared'] ) === $mapping_coverage['shared_count'], 'Coverage totals match unique capability and shared-function detail rows.' );
adc_check( false === $mapping_coverage['permission_recommendations'] && 'preliminary_needs_human_review' === $mapping_coverage['classifications'] && true === $mapping_coverage['classification_review_required'], 'Coverage classification is preliminary, requires human review, and makes no permission recommendations.' );
foreach ( array_merge( $mapping_coverage['mapped'], $mapping_coverage['unmapped'] ) as $mapping_coverage_row ) {
	adc_check( true === $mapping_coverage_row['classification_review_required'], 'Capability classification is individually marked for human review: ' . $mapping_coverage_row['key'] );
}
$mapping_coverage_classifications = array_column( array_merge( $mapping_coverage['mapped'], $mapping_coverage['unmapped'] ), 'classification', 'key' );
adc_check( 'read_reference' === $mapping_coverage_classifications['adc_view_inventory'] && 'approval_verification' === $mapping_coverage_classifications['adc_approve_purchase_orders'] && 'change_operation' === $mapping_coverage_classifications['adc_manage_inventory'], 'Description-based classification distinguishes reading, approval, and operational changes.' );
adc_check( in_array( 'adc_view_inventory', array_column( $mapping_coverage['shared'], 'key' ), true ), 'Coverage report identifies capabilities shared by multiple mapped functions.' );
adc_check( $mapping_coverage_before === $mapping_coverage, 'Coverage report output is stable across repeated reads.' );
adc_check( ! in_array( 'manage_options', $mapping_keys, true ) && ! in_array( 'administrator', $mapping_keys, true ), 'Reference map does not suggest WordPress system-administration privileges.' );
adc_check( $mapping_report === OrganizationalCapabilityMap::report(), 'Reference mapping output is stable across repeated reads.' );
ob_start(); OrganizationalCapabilityMap::render_reference_panel(); $mapping_html = ob_get_clean();
adc_check( str_contains( $mapping_html, 'data-reference-only="true"' ), 'Role-management reference panel exposes a stable marker for its non-granting boundary.' );
ob_start(); RoleManager::render(); $role_manager_html = ob_get_clean();
adc_check( str_contains( $role_manager_html, 'data-reference-only="true"' ), 'Role-management page displays the read-only organization-to-capability reference.' );
adc_check( str_contains( $role_manager_html, 'name="organizational_functions_present" value="1"' ) && str_contains( $role_manager_html, 'data-organizational-functions="asset_control,delivery_operations"' ), 'Role editor offers explicit function references and capability-to-function context.' );
adc_check( str_contains( $role_manager_html, 'adc-organizational-functions' ), 'Organizational function controls provide a stable location for permission-filter status.' );
adc_check( str_contains( $role_manager_html, 'data-function-review="asset_control"' ) && str_contains( $role_manager_html, 'data-review-capability="adc_manage_inventory"' ), 'Role editor renders a capability status review panel for each organizational function.' );
adc_check( str_contains( $role_manager_html, 'data-function-comparison' ) && str_contains( $role_manager_html, 'data-function-comparison-summary' ) && str_contains( $role_manager_html, 'data-function-comparison-outside' ), 'Role editor renders a read-only summary comparing manual role grants with selected functions.' );
adc_check( str_contains( $role_manager_html, 'data-capability-coverage-report="read-only"' ) && str_contains( $role_manager_html, 'data-capability-classification-review="required"' ) && str_contains( $role_manager_html, 'data-coverage-category="unmapped"' ) && str_contains( $role_manager_html, 'data-coverage-category="shared"' ), 'Role-management reference panel renders coverage details and discloses preliminary classification review.' );
adc_check( $mapping_roles_before === get_option( wp_roles()->role_key ) && $mapping_definitions_before === get_option( 'adc_role_definitions', null ), 'Generating and displaying the mapping does not modify role grants or role definitions.' );

$mapping_slug = 'adc_role_function_mapping_test';
$mapping_input = array(
	'slug' => $mapping_slug,
	'ar' => 'دور ربط وظيفي',
	'en' => 'Function mapping test',
	'caps' => array( 'adc_view_inventory' ),
	'organizational_functions' => array( 'asset_control' ),
	'organizational_functions_present' => '1',
	'new' => '1',
	'reason' => 'Isolated role-function mapping scenario',
);
$mapping_preview = RoleManager::save_role( $mapping_input, true );
adc_check( is_array( $mapping_preview ) && ! get_role( $mapping_slug ) && ! isset( get_option( 'adc_role_definitions', array() )[$mapping_slug] ), 'Previewing function alignment does not create a role or change stored role definitions.' );
$mapping_variant = $mapping_input;
$mapping_variant['organizational_functions'] = array( 'delivery_operations' );
$mapping_variant_preview = RoleManager::save_role( $mapping_variant, true );
adc_check( $mapping_preview['revision'] !== $mapping_variant_preview['revision'], 'Impact preview revision binds the selected organizational function references.' );
$mapping_input['revision'] = $mapping_preview['revision'];
$mapping_saved = RoleManager::save_role( $mapping_input );
$mapping_definitions = get_option( 'adc_role_definitions', array() );
adc_check( $mapping_saved === $mapping_slug && array( 'asset_control' ) === $mapping_definitions[$mapping_slug]['organizational_functions'], 'Reviewed role changes persist selected organizational functions as reference metadata.' );
adc_check( get_role( $mapping_slug )->has_cap( 'adc_view_inventory' ) && ! get_role( $mapping_slug )->has_cap( 'adc_manage_inventory' ) && ! get_role( $mapping_slug )->has_cap( 'manage_options' ), 'Function references never grant mapped or WordPress administration capabilities automatically.' );
$mapping_audit_after = $wpdb->get_var( $wpdb->prepare( 'SELECT after_data FROM ' . \AutoDealership\Audit\AuditLog::table_name() . ' WHERE event_key = %s AND after_data LIKE %s ORDER BY id DESC LIMIT 1', 'security.role_created', '%' . $wpdb->esc_like( $mapping_slug ) . '%' ) );
$mapping_audit_data = is_string( $mapping_audit_after ) ? json_decode( $mapping_audit_after, true ) : null;
adc_check( is_array( $mapping_audit_data ) && array( 'asset_control' ) === ( $mapping_audit_data['organizational_functions'] ?? null ), 'Role creation audit records the selected organizational function references.' );
$mapping_legacy_update = array( 'slug' => $mapping_slug, 'ar' => 'دور ربط وظيفي', 'en' => 'Function mapping test', 'caps' => array( 'adc_view_inventory' ), 'new' => '0', 'reason' => 'Compatibility update without function fields' );
adc_check( $mapping_slug === RoleManager::save_role( $mapping_legacy_update ) && array( 'asset_control' ) === get_option( 'adc_role_definitions', array() )[$mapping_slug]['organizational_functions'], 'Older role-update callers preserve function metadata when they omit the new fields.' );
$invalid_mapping_input = $mapping_input;
$invalid_mapping_input['slug'] = 'adc_role_function_mapping_invalid';
$invalid_mapping_input['new'] = '1';
$invalid_mapping_input['organizational_functions'] = array( 'unknown_function' );
adc_check( is_wp_error( RoleManager::save_role( $invalid_mapping_input, true ) ), 'Reject organizational function identifiers outside the reference catalog.' );
remove_role( $mapping_slug );
unset( $mapping_definitions[$mapping_slug] );
update_option( 'adc_role_definitions', $mapping_definitions, false );
wp_roles()->for_site();
