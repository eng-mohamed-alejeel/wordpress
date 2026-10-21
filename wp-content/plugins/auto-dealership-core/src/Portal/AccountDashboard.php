<?php
namespace AutoDealership\Portal;

use AutoDealership\Admin\Navigation;

defined( 'ABSPATH' ) || exit;

/** Chooses a landing view; never grants data access or changes personal action scope. */
final class AccountDashboard {
	/** Used for account classification, including users with combined customer/work roles. */
	public static function has_work_capability( \WP_User $user ): bool {
		if ( ! $user->ID ) { return false; }
		if ( user_can( $user, 'adc_view_workspace' ) ) { return true; }
		foreach ( Navigation::groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				foreach ( $item['capabilities'] as $capability ) { if ( user_can( $user, $capability ) ) { return true; } }
			}
		}
		return false;
	}
	public static function groups(): array {
		$groups = array();
		if ( ! get_current_user_id() ) { return $groups; }
		foreach ( Navigation::groups() as $group ) {
			$group['items'] = array_values( array_filter( $group['items'], array( Navigation::class, 'allowed' ) ) );
			if ( $group['items'] ) { $groups[] = $group; }
		}
		return $groups;
	}
	public static function available(): bool {
		return get_current_user_id() > 0 && ( current_user_can( 'adc_view_workspace' ) || (bool) self::groups() );
	}
	private static function personal_target(): bool {
		// Account bookmarks and post-save destinations stay in the account view;
		// PortalView selects customer sections or work-account settings by capability.
		foreach ( array( 'portal', 'portal_return', 'thread', 'review', 'context', 'request', 'sale', 'saved', 'preferences_saved', 'request_updated', 'messages_page', 'bookings_page' ) as $key ) {
			if ( isset( $_GET[$key] ) ) { return true; }
		}
		return 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
	}
	public static function work(): bool {
		if ( self::personal_target() ) { return false; }
		if ( isset( $_GET['account_context'] ) ) {
			return is_string( $_GET['account_context'] ) && 'work' === $_GET['account_context'] && self::available();
		}
		return self::available();
	}
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( $args, array( 'cd_account'=>'dashboard', 'account_context'=>'work', 'lang'=>PortalAccess::language() ) ), home_url( '/' ) );
	}
	public static function validate_request(): void {
		if ( 'dashboard' !== ( $_GET['cd_account'] ?? '' ) || ! is_user_logged_in() ) { return; }
		nocache_headers();
		if ( self::personal_target() ) { return; }
		if ( isset( $_GET['account_context'] ) && ( ! is_string( $_GET['account_context'] ) || ! in_array( $_GET['account_context'], array( 'work', 'personal' ), true ) || ( 'work' === $_GET['account_context'] && ! self::available() ) ) ) {
			wp_die( esc_html( PortalAccess::t( 'مساحة العمل غير متاحة ضمن صلاحيات حسابك.', 'The work dashboard is not available with your account permissions.' ) ), '', array( 'response'=>403 ) );
		}
		if ( self::work() && isset( $_GET['workspace_branch'] ) ) {
			$branch = is_string( $_GET['workspace_branch'] ) ? filter_var( $_GET['workspace_branch'], FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>0 ) ) ) : false;
			if ( false === $branch || ( $branch && ( ! \AutoDealership\Security\BranchScope::allows( $branch ) || ! \AutoDealership\Security\BranchScope::is_active( $branch ) ) ) ) {
				wp_die( esc_html( PortalAccess::t( 'هذا الفرع غير متاح لحسابك.', 'This branch is not available to your account.' ) ), '', array( 'response'=>403 ) );
			}
		}
	}
}
