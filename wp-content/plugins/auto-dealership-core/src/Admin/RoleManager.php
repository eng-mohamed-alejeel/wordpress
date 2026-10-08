<?php
namespace AutoDealership\Admin;

use AutoDealership\Core\Capabilities;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Manage dealership permissions without granting WordPress administration privileges. */
final class RoleManager {
	public static function boot(): void {
		add_action( 'admin_post_adc_save_role', array( self::class, 'handle' ) );
	}

	public static function authorized(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'promote_users' ) && ( in_array( 'administrator', wp_get_current_user()->roles, true ) || ( is_multisite() && is_super_admin() ) );
	}

	public static function save_role( array $input ) {
		if ( ! self::authorized() ) { return new \WP_Error( 'adc_role_forbidden', __( 'Administrator access is required.', 'auto-dealership-core' ) ); }
		$slug = $input['slug'] ?? '';
		$ar = $input['ar'] ?? '';
		$en = $input['en'] ?? '';
		$caps = $input['caps'] ?? array();
		$new = ! empty( $input['new'] );
		if ( ! is_string( $slug ) || ! preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $slug ) || 'administrator' === $slug || ! is_string( $ar ) || ! is_string( $en ) || '' === trim( $ar ) || '' === trim( $en ) || mb_strlen( $ar ) > 80 || mb_strlen( $en ) > 80 || ! is_array( $caps ) ) {
			return new \WP_Error( 'adc_role_invalid', __( 'Enter a valid role identifier and both Arabic and English names.', 'auto-dealership-core' ) );
		}
		foreach ( $caps as $cap ) {
			if ( ! is_string( $cap ) || ! in_array( $cap, Capabilities::assignable_capabilities(), true ) ) { return new \WP_Error( 'adc_role_cap', __( 'Unsupported permission.', 'auto-dealership-core' ) ); }
		}
		$role = get_role( $slug );
		if ( $new ? ( $role || ! str_starts_with( $slug, 'adc_role_' ) ) : ! $role ) { return new \WP_Error( 'adc_role_exists', __( 'The role identifier is unavailable.', 'auto-dealership-core' ) ); }
		$ar = sanitize_text_field( $ar ); $en = sanitize_text_field( $en );
		if ( '' === $ar || '' === $en ) { return new \WP_Error( 'adc_role_invalid', __( 'Enter a valid role identifier and both Arabic and English names.', 'auto-dealership-core' ) ); }
		$before = $role ? $role->capabilities : array();
		$next = $before;
		foreach ( Capabilities::assignable_capabilities() as $cap ) { unset( $next[$cap] ); }
		$next['read'] = true;
		foreach ( array_unique( $caps ) as $cap ) { $next[$cap] = true; }
		$definitions = get_option( 'adc_role_definitions', array() );
		$definitions[$slug] = array( 'ar' => $ar, 'en' => $en, 'caps' => array_values( array_unique( $caps ) ) );
		if ( $new ) {
			$role = add_role( $slug, $en, $next );
			if ( ! $role ) { return new \WP_Error( 'adc_role_exists', __( 'The role identifier is unavailable.', 'auto-dealership-core' ) ); }
		} else {
			$roles = wp_roles();
			$roles->roles[$slug]['capabilities'] = $next;
			$roles->role_objects[$slug]->capabilities = $next;
			update_option( $roles->role_key, $roles->roles );
		}
		update_option( 'adc_role_definitions', $definitions, false );
		Capabilities::localize_roles();
		if ( Schema::is_ready() ) { AuditLog::record( $new ? 'security.role_created' : 'security.role_updated', 'role', 0, $slug, $before, array( 'ar' => $ar, 'en' => $en, 'capabilities' => $next ) ); }
		return $slug;
	}

	public static function handle(): void {
		if ( ! self::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_save_role' );
		$result = self::save_role( wp_unslash( $_POST ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-roles', 'role' => $result, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! self::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$roles = get_editable_roles(); unset( $roles['administrator'] );
		$selected = isset( $_GET['role'] ) && is_string( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : 'dealership_sales';
		if ( ! isset( $roles[$selected] ) ) { $selected = array_key_first( $roles ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Roles and permissions', 'auto-dealership-core' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Role saved successfully.', 'auto-dealership-core' ) . '</p></div>'; }
		echo '<p>' . esc_html__( 'Changes apply to all users of this role. Individual permission overrides remain in effect. WordPress administration permissions are preserved.', 'auto-dealership-core' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="adc-roles"><label>' . esc_html__( 'Select a role', 'auto-dealership-core' ) . ' <select name="role">';
		foreach ( $roles as $slug => $role ) { echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $slug, $selected, false ) . '>' . esc_html( translate_user_role( $role['name'] ) ) . '</option>'; }
		echo '</select></label> '; submit_button( __( 'View role', 'auto-dealership-core' ), 'secondary', '', false ); echo '</form>';
		if ( $selected ) { self::form( $selected, false ); }
		echo '<hr>'; self::form( '', true ); echo '</div>';
	}

	private static function form( string $slug, bool $new ): void {
		$definitions = get_option( 'adc_role_definitions', array() );
		$role = $slug ? get_role( $slug ) : null;
		$name = $slug ? ( wp_roles()->role_names[$slug] ?? $slug ) : '';
		$names = $definitions[$slug] ?? array();
		if ( $slug && ! $names ) {
			$source = array( 'car_dealer_customer' => 'Dealership Customer', 'dealership_sales' => 'Dealership Sales', 'dealership_sales_manager' => 'Dealership Sales Manager', 'dealership_general_manager' => 'Dealership General Manager', 'dealership_inventory' => 'Dealership Inventory', 'dealership_finance' => 'Dealership Finance', 'dealership_purchasing' => 'Dealership Purchasing', 'dealership_delivery' => 'Dealership Delivery', 'dealership_customer_service' => 'Dealership Customer Service', 'dealership_marketing' => 'Dealership Marketing', 'dealership_auditor' => 'Dealership Auditor' );
			$catalog = require __DIR__ . '/../../languages/ui.php';
			$en = $source[$slug] ?? ucwords( str_replace( '_', ' ', $slug ) );
			$names = array( 'en' => $en, 'ar' => $catalog['ar'][$en] ?? $name );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="adc-user-permissions"><h2>' . esc_html__( $new ? 'Create a new role' : 'Edit role defaults', 'auto-dealership-core' ) . '</h2>';
		wp_nonce_field( 'adc_save_role' );
		echo '<input type="hidden" name="action" value="adc_save_role"><input type="hidden" name="new" value="' . ( $new ? '1' : '0' ) . '">';
		foreach ( array( 'slug' => 'Role identifier', 'ar' => 'Arabic role name', 'en' => 'English role name' ) as $key => $label ) {
			$value = 'slug' === $key ? ( $new ? 'adc_role_' : $slug ) : ( $names[$key] ?? '' );
			echo '<p><label>' . esc_html__( $label, 'auto-dealership-core' ) . '<br><input class="regular-text" required name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" maxlength="' . ( 'slug' === $key ? '64' : '80' ) . '" ' . ( 'slug' === $key && ! $new ? 'readonly' : '' ) . '></label></p>';
		}
		if ( $new ) { echo '<p class="description">' . esc_html__( 'New role identifiers must begin with adc_role_. Basic account access is always included.', 'auto-dealership-core' ) . '</p>'; }
		$groups = array(); foreach ( UserPermissions::catalog() as $cap => $entry ) { $groups[$entry[0]][$cap] = $entry[1]; }
		foreach ( $groups as $group => $caps ) {
			echo '<fieldset class="adc-permission-group"><legend>' . esc_html__( $group, 'auto-dealership-core' ) . '</legend><div class="adc-permission-grid">';
			foreach ( $caps as $cap => $label ) { echo '<label><input type="checkbox" name="caps[]" value="' . esc_attr( $cap ) . '" ' . checked( $role && $role->has_cap( $cap ), true, false ) . '> ' . esc_html__( $label, 'auto-dealership-core' ) . '</label>'; }
			echo '</div></fieldset>';
		}
		submit_button( __( $new ? 'Create role' : 'Save role', 'auto-dealership-core' ) ); echo '</form>';
	}
}
