<?php
namespace AutoDealership\Portal;

use AutoDealership\Admin\WorkspaceOverview;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Read-only projections. No customer-portal scope or workflow authority is expanded. */
final class WorkDashboard {
	private static function count( string $from, string $where, array $args ): ?int {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM $from WHERE $where";
		$value = $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
		return $wpdb->last_error || null === $value ? null : (int) $value;
	}
	public static function snapshot() {
		if ( ! AccountDashboard::available() ) { return PortalAccess::error( 'adc_portal_forbidden', 403 ); }
		if ( ! Schema::is_ready() ) { return PortalAccess::error( 'adc_portal_unavailable', 503 ); }
		global $wpdb;
		$groups = AccountDashboard::groups(); $modules = array();
		foreach ( $groups as $group ) { foreach ( $group['items'] as $item ) { $modules[$item['slug']] = true; } }
		$overview = current_user_can( 'adc_view_workspace' ) ? WorkspaceOverview::snapshot() : null;
		if ( is_wp_error( $overview ) ) { return $overview; }
		if ( null !== $overview ) { $branches = $overview['branches']; }
		else {
			list( $scope, $args ) = BranchScope::predicate( 'id' );
			$sql = 'SELECT id,name FROM ' . Schema::table( 'branches' ) . " WHERE active=1 AND $scope ORDER BY name,id";
			$branches = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
			if ( $wpdb->last_error ) { return PortalAccess::error( 'adc_portal_unavailable', 503 ); }
		}
		$cards = array();
		$add = static function ( string $key, string $ar, string $en, string $from, string $where, array $args, string $slug, array $extra = array() ) use ( &$cards, $modules ): void {
			if ( ! isset( $modules[$slug] ) ) { return; }
			$cards[$key] = array( 'title'=>PortalAccess::t( $ar, $en ), 'count'=>self::count( $from, $where, $args ), 'url'=>admin_url( 'admin.php?' . http_build_query( array_merge( array( 'page'=>$slug ), $extra ), '', '&' ) ) );
		};
		// These unit summaries always describe all permitted branches, independently
		// of the operational queue's branch/search filters shown in the next section.
		if ( isset( $modules['adc-inventory-identity'] ) ) {
			list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
			foreach ( array( 'received'=>array( 'مركبات تنتظر توثيق الاستلام', 'Vehicles awaiting receipt', 'receipt' ), 'inspection'=>array( 'مركبات تنتظر الفحص', 'Vehicles awaiting inspection', 'inspection' ) ) as $status=>$labels ) {
				$add( $status, $labels[0], $labels[1], Schema::table( 'vehicles' ) . ' v', "$scope AND v.status=%s", array_merge( $args, array( $status ) ), 'adc-inventory-identity', array( 'section'=>$labels[2] ) );
			}
		}
		if ( isset( $modules['adc-purchase-orders'] ) ) {
			list( $scope, $args ) = BranchScope::predicate( 'p.branch_id' );
			$add( 'purchase_orders', 'أوامر شراء قيد العمل', 'Purchase orders in progress', Schema::table( 'purchase_orders' ) . ' p JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=p.vehicle_id JOIN ' . Schema::table( 'suppliers' ) . ' s ON s.id=p.supplier_id', "$scope AND p.status IN ('draft','pending','approved')", $args, 'adc-purchase-orders' );
		}
		if ( PortalAccess::ready() && isset( $modules['adc-customer-reviews'] ) ) {
			list( $scope, $args ) = BranchScope::predicate( 'r.branch_id' );
			$add( 'reviews', 'تقييمات تنتظر المراجعة', 'Reviews awaiting moderation', PortalAccess::table( 'reviews' ) . ' r JOIN ' . PortalAccess::table( 'review_versions' ) . ' v ON v.id=r.current_version', "$scope AND r.status='pending'", $args, 'adc-customer-reviews', array( 'status'=>'pending' ) );
		}
		if ( PortalAccess::ready() && isset( $modules['adc-conversations'] ) ) {
			$source = '(SELECT raw.*,CASE WHEN raw.lead_id=0 THEN raw.branch_id ELSE COALESCE(lead.branch_id,0) END scope_branch,CASE WHEN raw.lead_id=0 THEN raw.owner_id ELSE COALESCE(lead.owner_user_id,0) END scope_owner FROM ' . PortalAccess::table( 'threads' ) . ' raw LEFT JOIN ' . Schema::table( 'leads' ) . ' lead ON lead.id=raw.lead_id) t';
			list( $scope, $args ) = BranchScope::predicate( 't.scope_branch' );
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'adc_view_branch_leads' ) ) { $scope .= ' AND t.scope_owner=%d'; $args[] = get_current_user_id(); }
			$add( 'conversations', 'محادثات العملاء المفتوحة', 'Open customer conversations', $source, "$scope AND t.status<>'closed'", $args, 'adc-conversations', array( 'status'=>'open' ) );
		}
		// Outbox is a system permission, not customer or branch ownership.
		if ( isset( $modules['adc-outbox'] ) ) {
			$add( 'outbox', 'مهام إرسال فاشلة على مستوى النظام', 'Failed system delivery jobs', \AutoDealership\Operations\OutboxService::table(), "status='failed'", array(), 'adc-outbox', array( 'status'=>'failed' ) );
		}
		return array( 'groups'=>$groups, 'branches'=>$branches ?: array(), 'overview'=>$overview, 'cards'=>$cards );
	}
}
