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

	public static function save_role( array $input, bool $preview = false ) {
		if ( ! self::authorized() ) { return new \WP_Error( 'adc_role_forbidden', __( 'Administrator access is required.', 'auto-dealership-core' ) ); }
		$slug = $input['slug'] ?? '';
		$ar = $input['ar'] ?? '';
		$en = $input['en'] ?? '';
		$caps = $input['caps'] ?? array();
		$new = ! empty( $input['new'] );
		if ( $new && ! empty( $input['clone_source'] ) ) {
			$source = $input['clone_source'];
			if ( ! is_string( $source ) || 'administrator' === $source || ! isset( get_editable_roles()[$source] ) ) { return new \WP_Error( 'adc_role_invalid', __( 'The role identifier is unavailable.', 'auto-dealership-core' ) ); }
			if ( '1' !== ( $input['clone_loaded'] ?? '0' ) ) { $caps = array_values( array_intersect( array_keys( array_filter( get_role( $source )->capabilities ) ), Capabilities::assignable_capabilities() ) ); }
		}
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
		$impact = self::impact( $slug, $next );
		if ( $preview ) { return $impact; }
		if ( isset( $input['revision'] ) && ( ! is_string( $input['revision'] ) || ! hash_equals( $impact['revision'], $input['revision'] ) ) ) { return new \WP_Error( 'adc_role_stale', __( 'Access changed since the preview. Review the impact again.', 'auto-dealership-core' ) ); }
		$definitions = get_option( 'adc_role_definitions', array() );
		$original = $definitions[$slug]['original'] ?? ( $new ? $next : $before );
		$definitions[$slug] = array( 'ar' => $ar, 'en' => $en, 'caps' => array_values( array_unique( $caps ) ), 'original' => $original );
		if ( ! \AutoDealership\Database\Transaction::begin() ) { return new \WP_Error( 'adc_role_write_failed', __( 'The access change could not be saved.', 'auto-dealership-core' ) ); }
		if ( $new ) {
			$role = add_role( $slug, $en, $next );
			if ( ! $role ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_role_exists', __( 'The role identifier is unavailable.', 'auto-dealership-core' ) ); }
		} else {
			$roles = wp_roles();
			$roles->roles[$slug]['capabilities'] = $next;
			$roles->role_objects[$slug]->capabilities = $next;
			update_option( $roles->role_key, $roles->roles );
		}
		update_option( 'adc_role_definitions', $definitions, false );
		Capabilities::localize_roles();
		$reason = isset( $input['reason'] ) && is_string( $input['reason'] ) ? sanitize_textarea_field( $input['reason'] ) : '';
		$stored_roles = get_option( wp_roles()->role_key, array() );
		$verified = ( $stored_roles[$slug]['capabilities'] ?? null ) === $next && get_option( 'adc_role_definitions', array() ) === $definitions;
		if ( ! \AutoDealership\Database\Transaction::commit( static fn() => $verified && AuditLog::record( $new ? 'security.role_created' : 'security.role_updated', 'role', 0, $reason ?: $slug, array( 'role' => $slug, 'capabilities' => $before ), array( 'role' => $slug, 'ar' => $ar, 'en' => $en, 'capabilities' => $next ) ) ) ) { self::refresh_caches(); return new \WP_Error( 'adc_role_write_failed', __( 'The access change could not be saved.', 'auto-dealership-core' ) ); }
		return $slug;
	}

	/** Project changes against all of each user's roles and individual overrides. */
	public static function impact( string $slug, array $next, ?string $transfer = null ): array {
		$rows = array(); $snapshot = array(); $catalog = UserPermissions::catalog();
		foreach ( get_users( array( 'role' => $slug, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $user ) {
			$projected = array();
			$planned_roles = $user->roles;
			if ( null !== $transfer ) { $planned_roles = array_values( array_diff( $planned_roles, array( $slug ) ) ); if ( ! in_array( $transfer, $planned_roles, true ) ) { $planned_roles[] = $transfer; } }
			foreach ( $planned_roles as $role ) {
				$caps = $role === $slug ? $next : ( get_role( $role )->capabilities ?? array() );
				$projected = array_merge( $projected, $caps );
			}
			$projected = array_merge( $projected, $user->caps );
			$temp = \AutoDealership\Security\AccessPolicy::temporary( $user->ID );
			foreach ( $temp as $cap => $grant ) { if ( (int) ( $grant['expires'] ?? 0 ) > time() ) { $projected[$cap] = true; } }
			$added = array(); $removed = array();
			foreach ( array_unique( array_merge( array_keys( $catalog ), array_keys( $user->allcaps ), array_keys( $projected ) ) ) as $cap ) {
				if ( get_role( $cap ) || preg_match( '/^level_\d+$/', $cap ) || in_array( $cap, array( 'edit_car', 'read_car', 'delete_car', 'edit_car_offer', 'read_car_offer', 'delete_car_offer' ), true ) ) { continue; }
				$entry = $catalog[$cap] ?? array( '', UserPermissions::native_label( $cap ) );
				$before = $user->has_cap( $cap );
				$after = ! \AutoDealership\Security\AccessPolicy::suspended( $user->ID ) && ! empty( $projected[$cap] );
				if ( $after && ! $before ) { $added[] = __( $entry[1], 'auto-dealership-core' ); }
				if ( ! $after && $before ) { $removed[] = __( $entry[1], 'auto-dealership-core' ); }
			}
			$rows[] = array( 'id' => $user->ID, 'name' => $user->display_name, 'added' => $added, 'removed' => $removed, 'overrides' => count( array_intersect( array_keys( $user->caps ), Capabilities::assignable_capabilities() ) ), 'temporary' => count( $temp ) );
			$snapshot[] = array( $user->ID, $user->roles, $user->caps, $user->allcaps, $temp, get_user_meta( $user->ID, 'adc_suspended', true ) );
		}
		$role_added = array(); $role_removed = array(); $current = get_role( $slug )->capabilities ?? array();
		foreach ( array_unique( array_merge( array_keys( $current ), array_keys( $next ) ) ) as $cap ) {
			if ( preg_match( '/^level_\d+$/', $cap ) || in_array( $cap, array( 'edit_car', 'read_car', 'delete_car', 'edit_car_offer', 'read_car_offer', 'delete_car_offer' ), true ) ) { continue; }
			$label = __( $catalog[$cap][1] ?? UserPermissions::native_label( $cap ), 'auto-dealership-core' );
			if ( ! empty( $next[$cap] ) && empty( $current[$cap] ) ) { $role_added[] = $label; }
			if ( empty( $next[$cap] ) && ! empty( $current[$cap] ) ) { $role_removed[] = $label; }
		}
		return array( 'users' => $rows, 'role_added' => $role_added, 'role_removed' => $role_removed, 'revision' => hash( 'sha256', wp_json_encode( array( $slug, $current, $next, $transfer, $snapshot, $rows, get_option( 'adc_role_definitions', array() ) ) ) ) );
	}

	public static function delete_role( string $slug, string $target, string $reason, bool $preview = false, string $revision = '' ) {
		$definitions = get_option( 'adc_role_definitions', array() );
		if ( ! self::authorized() || ! str_starts_with( $slug, 'adc_role_' ) || ! isset( $definitions[$slug] ) || ! get_role( $slug ) || 'administrator' === $target || $target === $slug || ! isset( get_editable_roles()[$target] ) ) { return new \WP_Error( 'adc_delete_denied', __( 'Only custom roles can be deleted. Choose a different replacement role.', 'auto-dealership-core' ) ); }
		$impact = self::impact( $slug, get_role( $target )->capabilities, $target );
		if ( $preview ) { return $impact; }
		if ( '' === trim( $reason ) || ! hash_equals( $impact['revision'], $revision ) ) { return new \WP_Error( 'adc_role_stale', __( 'Access changed since the preview. Review the impact again.', 'auto-dealership-core' ) ); }
		$before = get_role( $slug )->capabilities;
		if ( ! \AutoDealership\Database\Transaction::begin() ) { return new \WP_Error( 'adc_role_write_failed', __( 'The access change could not be saved.', 'auto-dealership-core' ) ); }
		foreach ( $impact['users'] as $row ) {
			$user = get_userdata( $row['id'] );
			$user->add_role( $target ); $user->remove_role( $slug );
		}
		if ( get_users( array( 'role' => $slug, 'number' => 1, 'fields' => 'ID' ) ) ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); self::refresh_caches( array_column( $impact['users'], 'id' ) ); return new \WP_Error( 'adc_delete_failed', __( 'Users could not be transferred. The role was retained.', 'auto-dealership-core' ) ); }
		if ( get_option( 'default_role' ) === $slug ) { update_option( 'default_role', $target ); }
		remove_role( $slug ); unset( $definitions[$slug] ); update_option( 'adc_role_definitions', $definitions, false );
		if ( ! \AutoDealership\Database\Transaction::commit( static fn() => AuditLog::record( 'security.role_deleted', 'role', 0, $reason, array( 'role' => $slug, 'capabilities' => $before ), array( 'replacement' => $target, 'transferred_users' => array_column( $impact['users'], 'id' ) ) ) ) ) { self::refresh_caches( array_column( $impact['users'], 'id' ) ); return new \WP_Error( 'adc_role_write_failed', __( 'The access change could not be saved.', 'auto-dealership-core' ) ); }
		foreach ( $impact['users'] as $row ) { \WP_Session_Tokens::get_instance( $row['id'] )->destroy_all(); }
		return $target;
	}

	private static function refresh_caches( array $users = array() ): void {
		foreach ( array( 'alloptions', wp_roles()->role_key, 'adc_role_definitions', 'default_role' ) as $key ) { wp_cache_delete( $key, 'options' ); }
		foreach ( $users as $id ) { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); }
		wp_roles()->for_site(); Capabilities::localize_roles();
	}

	public static function handle(): void {
		if ( ! self::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_save_role' );
		$input = wp_unslash( $_POST );
		$confirmed = isset( $input['confirmed'] ) && '1' === $input['confirmed'];
		$preview_token = '';
		if ( $confirmed ) {
			$preview_token = is_string( $input['preview_token'] ?? null ) ? $input['preview_token'] : '';
			$stored = preg_match( '/^[a-f0-9]{32}$/D', $preview_token ) ? get_transient( 'adc_role_preview_' . $preview_token ) : false;
			if ( ! $stored || (int) $stored['actor'] !== get_current_user_id() || ! is_string( $input['revision'] ?? null ) || ! hash_equals( $stored['impact']['revision'], $input['revision'] ) ) { wp_die( esc_html__( 'Access changed since the preview. Review the impact again.', 'auto-dealership-core' ), '', array( 'response' => 409, 'back_link' => true ) ); }
			$input = $stored['input']; $input['revision'] = $stored['impact']['revision'];
		}
		$operation = $input['operation'] ?? 'save';
		if ( ! in_array( $operation, array( 'save', 'restore', 'delete' ), true ) ) { wp_die( esc_html__( 'Unsupported operation.', 'auto-dealership-core' ), '', array( 'response' => 400 ) ); }
		if ( ! is_string( $input['reason'] ?? null ) || '' === trim( $input['reason'] ) || mb_strlen( $input['reason'] ) > 2000 ) { wp_die( esc_html__( 'A change reason is required.', 'auto-dealership-core' ), '', array( 'response' => 400, 'back_link' => true ) ); }
		if ( 'restore' === $operation && is_string( $input['slug'] ?? null ) ) {
			$slug = $input['slug']; $defs = get_option( 'adc_role_definitions', array() );
			$defaults = Capabilities::role_matrix()[$slug] ?? array_keys( array_filter( $defs[$slug]['original'] ?? array() ) );
			if ( ! $defaults ) { wp_die( esc_html__( 'No saved defaults are available for this role.', 'auto-dealership-core' ), '', array( 'response' => 400 ) ); }
			$input['caps'] = array_values( array_intersect( $defaults, Capabilities::assignable_capabilities() ) );
		}
		if ( $confirmed && ( ! is_string( $input['revision'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input['revision'] ) ) ) { wp_die( esc_html__( 'Access changed since the preview. Review the impact again.', 'auto-dealership-core' ), '', array( 'response' => 409 ) ); }
		if ( 'delete' === $operation ) {
			$result = self::delete_role( is_string( $input['slug'] ?? null ) ? $input['slug'] : '', is_string( $input['target'] ?? null ) ? $input['target'] : '', $input['reason'], ! $confirmed, is_string( $input['revision'] ?? null ) ? $input['revision'] : '' );
		} else { $result = self::save_role( $input, ! $confirmed ); }
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		if ( ! $confirmed ) {
			$token = bin2hex( random_bytes( 16 ) );
			set_transient( 'adc_role_preview_' . $token, array( 'actor' => get_current_user_id(), 'input' => $input, 'impact' => $result ), 15 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( array( 'page' => 'adc-roles', 'preview' => $token ), admin_url( 'admin.php' ) ) ); exit;
		}
		delete_transient( 'adc_role_preview_' . $preview_token );
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-roles', 'role' => $result, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! self::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		if ( isset( $_GET['preview'] ) ) {
			$token = is_string( $_GET['preview'] ) ? $_GET['preview'] : '';
			$stored = preg_match( '/^[a-f0-9]{32}$/D', $token ) ? get_transient( 'adc_role_preview_' . $token ) : false;
			if ( ! $stored || (int) $stored['actor'] !== get_current_user_id() ) { wp_die( esc_html__( 'Access changed since the preview. Review the impact again.', 'auto-dealership-core' ), '', array( 'response' => 409 ) ); }
			self::confirmation( $stored['input'], $stored['impact'], $token ); return;
		}
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
		if ( $new ) {
			echo '<input type="hidden" name="clone_loaded" value="0"><p><label>' . esc_html__( 'Copy permissions from role', 'auto-dealership-core' ) . ' <select name="clone_source"><option value="">—</option>';
			foreach ( get_editable_roles() as $key => $item ) { if ( 'administrator' !== $key ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( translate_user_role( $item['name'] ) ) . '</option>'; } } echo '</select></label></p>';
		}
		$groups = array(); foreach ( UserPermissions::catalog() as $cap => $entry ) { $groups[$entry[0]][$cap] = $entry[1]; }
		foreach ( $groups as $group => $caps ) {
			echo '<fieldset class="adc-permission-group"><legend>' . esc_html__( $group, 'auto-dealership-core' ) . '</legend><div class="adc-permission-grid">';
			foreach ( $caps as $cap => $label ) { echo '<label><input type="checkbox" name="caps[]" value="' . esc_attr( $cap ) . '" ' . checked( $role && $role->has_cap( $cap ), true, false ) . '> ' . esc_html__( $label, 'auto-dealership-core' ) . '</label>'; }
			echo '</div></fieldset>';
		}
		echo '<p><label>' . esc_html__( 'Change reason', 'auto-dealership-core' ) . '<br><textarea name="reason" required maxlength="2000" class="large-text" rows="2"></textarea></label></p>';
		submit_button( __( 'Preview impact before saving', 'auto-dealership-core' ) );
		$defs = get_option( 'adc_role_definitions', array() );
		if ( ! $new && ( isset( Capabilities::role_matrix()[$slug] ) || ! empty( $defs[$slug]['original'] ) ) ) { echo '<button class="button" name="operation" value="restore">' . esc_html__( 'Restore original defaults', 'auto-dealership-core' ) . '</button>'; }
		if ( ! $new && str_starts_with( $slug, 'adc_role_' ) && isset( $defs[$slug] ) ) {
			echo '<p><label>' . esc_html__( 'Replacement role for users', 'auto-dealership-core' ) . ' <select name="target"><option value="">—</option>';
			foreach ( get_editable_roles() as $key => $item ) { if ( ! in_array( $key, array( $slug, 'administrator' ), true ) ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( translate_user_role( $item['name'] ) ) . '</option>'; } } echo '</select></label> <button class="button" name="operation" value="delete">' . esc_html__( 'Preview transfer and deletion', 'auto-dealership-core' ) . '</button></p>';
		}
		echo '</form>';
	}

	private static function confirmation( array $input, array $impact, string $token ): void {
		$title = __( 'Review role impact', 'auto-dealership-core' );
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html__( 'Review these changes before applying them. Users with individual overrides may be unaffected. Role transfers also end active sessions.', 'auto-dealership-core' ) . '</p><p><strong>' . esc_html__( 'Role identifier', 'auto-dealership-core' ) . ':</strong> ' . esc_html( $input['slug'] ) . '</p><p><strong>' . esc_html__( 'Permissions added', 'auto-dealership-core' ) . ':</strong> ' . esc_html( implode( ', ', $impact['role_added'] ) ?: '—' ) . '</p><p><strong>' . esc_html__( 'Permissions removed', 'auto-dealership-core' ) . ':</strong> ' . esc_html( implode( ', ', $impact['role_removed'] ) ?: '—' ) . '</p><div style="overflow:auto"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'User', 'Permissions added', 'Permissions removed', 'Individual overrides', 'Temporary permissions' ) as $label ) { echo '<th>' . esc_html__( $label, 'auto-dealership-core' ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( $impact['users'] as $row ) { echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( implode( ', ', $row['added'] ) ?: '—' ) . '</td><td>' . esc_html( implode( ', ', $row['removed'] ) ?: '—' ) . '</td><td>' . absint( $row['overrides'] ) . '</td><td>' . absint( $row['temporary'] ) . '</td></tr>'; }
		if ( ! $impact['users'] ) { echo '<tr><td colspan="5">' . esc_html__( 'No users currently assigned to this role.', 'auto-dealership-core' ) . '</td></tr>'; } echo '</tbody></table></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_save_role' );
		foreach ( array( 'slug', 'ar', 'en', 'new', 'operation', 'target', 'reason' ) as $key ) { if ( isset( $input[$key] ) && is_string( $input[$key] ) ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $input[$key] ) . '">'; } }
		foreach ( $input['caps'] ?? array() as $cap ) { echo '<input type="hidden" name="caps[]" value="' . esc_attr( $cap ) . '">'; }
		echo '<input type="hidden" name="action" value="adc_save_role"><input type="hidden" name="confirmed" value="1"><input type="hidden" name="preview_token" value="' . esc_attr( $token ) . '"><input type="hidden" name="revision" value="' . esc_attr( $impact['revision'] ) . '">';
		submit_button( __( 'Apply reviewed changes', 'auto-dealership-core' ) ); echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=adc-roles' ) ) . '">' . esc_html__( 'Cancel', 'auto-dealership-core' ) . '</a></form></div>';
	}
}
