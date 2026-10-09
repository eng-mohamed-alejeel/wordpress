<?php
namespace AutoDealership\Admin;

use AutoDealership\Security\AccessPolicy;
use AutoDealership\Security\BranchScope;
use AutoDealership\Core\Capabilities;
use AutoDealership\Branches\BranchService;

defined( 'ABSPATH' ) || exit;

/** Paginated account review, effective access report and audited account operations. */
final class AccessReviewPage {
	public static function boot(): void { add_action( 'admin_post_adc_change_access', array( self::class, 'handle' ) ); }
	public static function handle(): void {
		if ( ! RoleManager::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_change_access' );
		$input = wp_unslash( $_POST );
		$id = absint( is_scalar( $input['user_id'] ?? null ) ? $input['user_id'] : 0 );
		$operation = is_string( $input['operation'] ?? null ) ? $input['operation'] : '';
		$reason = is_string( $input['reason'] ?? null ) ? $input['reason'] : '';
		$result = AccessPolicy::change( $id, $operation, $input, $reason );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400, 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'adc-access-review', 'user_id' => $id, 'saved' => '1' ), admin_url( 'admin.php' ) ) ); exit;
	}

	public static function report( \WP_User $user ): array {
		$catalog = UserPermissions::catalog(); $result = array();
		$caps = array_unique( array_merge( array_keys( $catalog ), array_keys( $user->allcaps ) ) );
		$temporary = AccessPolicy::temporary( $user->ID );
		foreach ( $caps as $cap ) {
			if ( get_role( $cap ) || preg_match( '/^level_\d+$/', $cap ) || in_array( $cap, array( 'edit_car', 'read_car', 'delete_car', 'edit_car_offer', 'read_car_offer', 'delete_car_offer' ), true ) ) { continue; }
			$source = array_key_exists( $cap, $user->caps ) ? ( $user->caps[$cap] ? 'Individual grant' : 'Individual denial' ) : 'Inherited role permission';
			$expires = (int) ( $temporary[$cap]['expires'] ?? 0 );
			if ( $expires > time() ) { $source = 'Temporary grant'; }
			if ( AccessPolicy::suspended( $user->ID ) ) { $source = 'Blocked by account suspension'; }
			$allowed = $user->has_cap( $cap );
			$result[$cap] = array( 'label' => __( $catalog[$cap][1] ?? UserPermissions::native_label( $cap ), 'auto-dealership-core' ), 'source' => $source, 'allowed' => $allowed, 'expires' => $expires, 'reason' => $allowed ? '' : self::denial_reason( $user, $cap ) );
		}
		return $result;
	}

	private static function denial_reason( \WP_User $user, string $cap ): string {
		if ( AccessPolicy::suspended( $user->ID ) ) { return 'Blocked by account suspension'; }
		if ( 'manage_links' === $cap && ! get_option( 'link_manager_enabled' ) ) { return 'The legacy link manager is disabled in site settings.'; }
		if ( 'unfiltered_upload' === $cap ) {
			if ( ! defined( 'ALLOW_UNFILTERED_UPLOADS' ) || ! ALLOW_UNFILTERED_UPLOADS ) { return 'Unrestricted uploads are disabled in site settings. Allowed file types can still be uploaded with upload permission.'; }
			if ( is_multisite() && ! is_super_admin( $user->ID ) ) { return 'Unrestricted uploads require a network super administrator.'; }
		}
		if ( array_key_exists( $cap, $user->caps ) && ! $user->caps[$cap] ) { return 'Denied by an individual permission override.'; }
		if ( empty( $user->allcaps[$cap] ) ) { return 'Not granted by roles or individual permissions.'; }
		return 'Blocked by WordPress or an active access policy.';
	}

	public static function render(): void {
		if ( ! RoleManager::authorized() ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>' . esc_html__( 'Account access review', 'auto-dealership-core' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html__( 'Access settings saved.', 'auto-dealership-core' ) . '</p></div>'; }
		$id = isset( $_GET['user_id'] ) && is_scalar( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user = $id ? get_userdata( $id ) : false;
		if ( $id && ! $user ) { echo '<p>' . esc_html__( 'User not found.', 'auto-dealership-core' ) . '</p></div>'; return; }
		if ( $user ) { self::user_report( $user ); } else { self::accounts(); }
		echo '</div>';
	}

	private static function accounts(): void {
		$summary = get_option( 'adc_access_review_summary', array() );
		if ( ! empty( $summary['generated_at'] ) ) { echo '<p>' . esc_html__( 'Last scheduled review', 'auto-dealership-core' ) . ': ' . esc_html( wp_date( 'Y-m-d H:i', $summary['generated_at'] ) ) . ' — ' . absint( $summary['accounts'] ) . ' / ' . absint( $summary['flagged'] ) . ' ' . esc_html__( 'Accounts needing review', 'auto-dealership-core' ) . '</p>'; }
		echo '<p>' . esc_html__( 'Reviews run daily. Flags indicate items to review, not confirmed violations. Last login tracking starts when this feature is enabled.', 'auto-dealership-core' ) . '</p>';
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page = max( 1, absint( is_scalar( $_GET['paged'] ?? null ) ? $_GET['paged'] : 1 ) );
		echo '<form method="get"><input type="hidden" name="page" value="adc-access-review"><label>' . esc_html__( 'Search users', 'auto-dealership-core' ) . ' <input type="search" name="s" value="' . esc_attr( $search ) . '"></label> '; submit_button( __( 'Search', 'auto-dealership-core' ), 'secondary', '', false ); echo '</form>';
		$args = array( 'number' => 25, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true );
		if ( $search ) { $args['search'] = '*' . $search . '*'; $args['search_columns'] = array( 'user_login', 'display_name' ); }
		$query = new \WP_User_Query( $args );
		echo '<div class="adc-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'User', 'Roles', 'Account status', 'Last login', 'Review flags', 'Actions' ) as $title ) { echo '<th>' . esc_html__( $title, 'auto-dealership-core' ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( $query->get_results() as $user ) {
			$flags = array_map( static fn( $label ) => __( $label, 'auto-dealership-core' ), AccessPolicy::review_flags( $user ) );
			$last = (int) get_user_meta( $user->ID, 'adc_last_login', true );
			echo '<tr><td>' . esc_html( $user->display_name . ' (' . $user->user_login . ')' ) . '</td><td>' . esc_html( implode( ', ', array_map( static fn( $role ) => translate_user_role( wp_roles()->role_names[$role] ?? $role ), $user->roles ) ) ) . '</td><td>' . esc_html__( AccessPolicy::suspended( $user->ID ) ? 'Suspended' : 'Active', 'auto-dealership-core' ) . '</td><td>' . esc_html( $last ? wp_date( 'Y-m-d H:i', $last ) : __( 'Not tracked yet', 'auto-dealership-core' ) ) . '</td><td>' . esc_html( implode( ' • ', $flags ) ) . '</td><td><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'adc-access-review', 'user_id' => $user->ID ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Effective permissions', 'auto-dealership-core' ) . '</a></td></tr>';
		}
		if ( ! $query->get_results() ) { echo '<tr><td colspan="6">' . esc_html__( 'No users found.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $page, 'total' => max( 1, (int) ceil( $query->get_total() / 25 ) ) ) ) );
		echo '<h2>' . esc_html__( 'Financial separation of duties', 'auto-dealership-core' ) . '</h2><p>' . esc_html__( 'Payment recorders cannot verify their own payments. Refund requesters cannot approve their own refunds. Discount requesters cannot decide their own requests. These restrictions also apply to administrators.', 'auto-dealership-core' ) . '</p>';
	}

	private static function user_report( \WP_User $user ): void {
		echo '<h2>' . esc_html( $user->display_name ) . '</h2><p><a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'Edit user', 'auto-dealership-core' ) . '</a> | <a href="' . esc_url( admin_url( 'admin.php?page=adc-access-review' ) ) . '">' . esc_html__( 'All accounts', 'auto-dealership-core' ) . '</a></p>';
		$branches = array();
		foreach ( BranchService::public_list() as $branch ) { if ( in_array( (int) $branch['id'], BranchScope::assigned_branches( $user->ID ), true ) ) { $branches[] = $branch['name']; } }
		echo '<p><strong>' . esc_html__( 'Branch scope', 'auto-dealership-core' ) . ':</strong> ' . esc_html( BranchScope::is_global( $user->ID ) ? __( 'All branches', 'auto-dealership-core' ) : ( $branches ? implode( ', ', $branches ) : __( 'No active branch assigned', 'auto-dealership-core' ) ) ) . '</p>';
		echo '<p>' . esc_html__( 'Effective permissions include role defaults, individual overrides, temporary grants and suspension. Object-specific and financial restrictions still apply.', 'auto-dealership-core' ) . '</p>';
		echo '<section class="adc-permission-report"><div class="adc-table-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( array( 'Permission', 'Source', 'Effective access', 'Expires' ) as $title ) { echo '<th>' . esc_html__( $title, 'auto-dealership-core' ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( self::report( $user ) as $cap => $row ) {
			echo '<tr data-granted="' . ( $row['allowed'] ? '1' : '0' ) . '" data-custom="' . ( array_key_exists( $cap, $user->caps ) ? '1' : '0' ) . '"><td>' . esc_html( $row['label'] ) . '</td><td>' . esc_html__( $row['source'], 'auto-dealership-core' ) . '</td><td>' . esc_html__( $row['allowed'] ? 'Granted' : 'Denied', 'auto-dealership-core' );
			if ( $row['reason'] ) { echo '<p class="description">' . esc_html__( $row['reason'], 'auto-dealership-core' ) . '</p>'; }
			echo '</td><td>' . esc_html( $row['expires'] ? wp_date( 'Y-m-d H:i T', $row['expires'] ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
		if ( ! AccessPolicy::protected_user( $user ) ) {
			self::operation_form( $user, AccessPolicy::suspended( $user->ID ) ? 'resume' : 'suspend', AccessPolicy::suspended( $user->ID ) ? 'Resume account' : 'Suspend account' );
			self::operation_form( $user, 'sessions', 'End all sessions' );
			self::operation_form( $user, 'grant', 'Grant a temporary permission' );
			foreach ( AccessPolicy::temporary( $user->ID ) as $cap => $grant ) { self::operation_form( $user, 'revoke', 'Revoke temporary permission', $cap ); }
		}
	}

	private static function operation_form( \WP_User $user, string $operation, string $title, string $cap = '' ): void {
		echo '<form class="adc-access-operation" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><h3>' . esc_html__( $title, 'auto-dealership-core' ) . '</h3>';
		wp_nonce_field( 'adc_change_access' );
		echo '<input type="hidden" name="action" value="adc_change_access"><input type="hidden" name="user_id" value="' . absint( $user->ID ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		if ( $cap ) { echo '<input type="hidden" name="cap" value="' . esc_attr( $cap ) . '"><p>' . esc_html__( UserPermissions::catalog()[$cap][1] ?? $cap, 'auto-dealership-core' ) . '</p>'; }
		if ( 'grant' === $operation ) {
			echo '<p><label>' . esc_html__( 'Permission', 'auto-dealership-core' ) . ' <select name="cap">';
			foreach ( UserPermissions::catalog() as $key => $entry ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html__( $entry[1], 'auto-dealership-core' ) . '</option>'; } echo '</select></label></p><p><label>' . esc_html__( 'Expires', 'auto-dealership-core' ) . ' <input type="datetime-local" name="expires" required></label> ' . esc_html( wp_timezone_string() ) . '</p><p class="description">' . esc_html__( 'Temporary grants override individual denials until expiration, then the original permission applies again.', 'auto-dealership-core' ) . '</p>';
		}
		echo '<p><label>' . esc_html__( 'Change reason', 'auto-dealership-core' ) . '<br><textarea name="reason" required maxlength="2000" rows="2" class="large-text"></textarea></label></p>'; submit_button( __( $title, 'auto-dealership-core' ), 'secondary' ); echo '</form>';
	}
}
