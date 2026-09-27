<?php
namespace AutoDealership\Pricing;

use AutoDealership\Database\Schema;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Append-only financial revisions; callers own the transaction and quote row lock. */
final class QuoteHistory {
	private const FIELDS = 'quote_number,customer_id,vehicle_id,branch_id,owner_user_id,version,base_amount,discount_amount,tax_rate_bps,tax_amount,final_amount,valid_until,status';

	private static function insert_sql(): string {
		$columns = implode( ',', array_map( static fn( $field ) => 'q.' . $field, explode( ',', self::FIELDS ) ) );
		return 'INSERT INTO ' . Schema::table( 'quotation_versions' ) . ' (quotation_id,' . self::FIELDS . ',customer_name,vehicle_stock_number,vehicle_description,currency,event_key,actor_user_id,reason,created_at) SELECT q.id,' . $columns . ",c.full_name,v.stock_number,TRIM(CONCAT_WS(' ',v.model_year,v.brand,v.model,NULLIF(v.trim_name,''))),'SAR',%s,%d,%s,%s FROM " . Schema::table( 'quotations' ) . ' q INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=q.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=q.vehicle_id';
	}

	public static function capture( int $quote_id, string $event, string $reason = '' ): bool {
		global $wpdb;
		if ( ! in_array( $event, array( 'quotation.created', 'discount.requested', 'discount.approved', 'discount.rejected' ), true ) ) { return false; }
		return 1 === $wpdb->query( $wpdb->prepare( self::insert_sql() . ' WHERE q.id = %d', $event, get_current_user_id(), sanitize_textarea_field( $reason ), current_time( 'mysql', true ), $quote_id ) );
	}

	/** Preserve only the current legacy revision; never invent missing historical states. */
	public static function backfill(): bool {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
		$assigned = $wpdb->query( 'UPDATE ' . Schema::table( 'quotations' ) . ' q INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=q.vehicle_id SET q.branch_id=v.branch_id WHERE q.branch_id=0' );
		if ( false === $assigned ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$snapshots = $wpdb->query( 'UPDATE ' . Schema::table( 'quotation_versions' ) . ' h INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=h.quotation_id INNER JOIN ' . Schema::table( 'customers' ) . ' c ON c.id=h.customer_id INNER JOIN ' . Schema::table( 'vehicles' ) . " v ON v.id=h.vehicle_id SET h.branch_id=IF(h.branch_id=0,q.branch_id,h.branch_id),h.customer_name=IF(h.customer_name='',c.full_name,h.customer_name),h.vehicle_stock_number=IF(h.vehicle_stock_number='',v.stock_number,h.vehicle_stock_number),h.vehicle_description=IF(h.vehicle_description='',TRIM(CONCAT_WS(' ',v.model_year,v.brand,v.model,NULLIF(v.trim_name,''))),h.vehicle_description) WHERE h.branch_id=0 OR h.customer_name='' OR h.vehicle_stock_number='' OR h.vehicle_description=''" );
		if ( false === $snapshots ) { $wpdb->query( 'ROLLBACK' ); return false; }
		$count = $wpdb->query( $wpdb->prepare( self::insert_sql() . ' WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table( 'quotation_versions' ) . ' h WHERE h.quotation_id = q.id AND h.version = q.version)', 'legacy.captured', 0, 'Current legacy revision captured; earlier revisions and original tax rate may be unknown.', current_time( 'mysql', true ) ) );
		$changes = (int) $assigned + (int) $snapshots + (int) $count;
		if ( false === $count || ( $changes > 0 && ! AuditLog::record( 'schema.quote_history_captured', 'schema', 0, 'Legacy current revisions and documentary identity preserved', null, array( 'quotes_assigned' => (int) $assigned, 'snapshots_enriched' => (int) $snapshots, 'revisions_captured' => (int) $count ) ) ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return true;
	}

	public static function can_read(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'adc_view_finance' );
	}

	private static function scope(): array {
		list( $scope, $args ) = BranchScope::predicate( 'q.branch_id' );
		if ( ! BranchScope::is_global() && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_finance' ) ) {
			$scope .= ' AND q.owner_user_id = %d';
			$args[] = get_current_user_id();
		}
		return array( $scope, $args );
	}

	public static function list_for_current_user( int $page = 1 ): array {
		global $wpdb;
		if ( ! self::can_read() ) { return array(); }
		list( $scope, $args ) = self::scope();
		$args[] = ( max( 1, $page ) - 1 ) * 50;
		$sql = 'SELECT q.id,q.quote_number,q.customer_id,q.vehicle_id,q.version,q.status,q.final_amount,q.valid_until,v.brand,v.model FROM ' . Schema::table( 'quotations' ) . ' q INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=q.vehicle_id WHERE ' . $scope . ' ORDER BY q.id DESC LIMIT 50 OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public static function versions( int $quote_id, int $page = 1 ) {
		global $wpdb;
		if ( ! self::can_read() ) { return new \WP_Error( 'adc_quote_forbidden', __( 'لا تملك صلاحية عرض عروض الأسعار.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		list( $scope, $args ) = self::scope();
		array_unshift( $args, $quote_id );
		$quote = $wpdb->get_var( $wpdb->prepare( 'SELECT q.id FROM ' . Schema::table( 'quotations' ) . ' q WHERE q.id = %d AND ' . $scope, $args ) );
		if ( ! $quote ) { return new \WP_Error( 'adc_quote_not_found', __( 'العرض غير موجود أو خارج نطاق صلاحياتك.', 'auto-dealership-core' ), array( 'status' => 404 ) ); }
		// Authorization and data reads share the same predicate, including during reassignment.
		$args[] = ( max( 1, $page ) - 1 ) * 50;
		$sql = 'SELECT h.* FROM ' . Schema::table( 'quotation_versions' ) . ' h INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=h.quotation_id WHERE q.id = %d AND ' . $scope . ' ORDER BY h.version DESC LIMIT 50 OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public static function version( int $quote_id, int $version ) {
		global $wpdb;
		if ( $version < 1 || ! self::can_read() ) { return new \WP_Error( 'adc_quote_forbidden', __( 'لا تملك صلاحية عرض عرض السعر.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		list( $scope, $args ) = self::scope();
		array_unshift( $args, $quote_id, $version );
		$sql = 'SELECT h.* FROM ' . Schema::table( 'quotation_versions' ) . ' h INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=h.quotation_id WHERE h.quotation_id=%d AND h.version=%d AND ' . $scope . ' LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return $row ?: new \WP_Error( 'adc_quote_not_found', __( 'نسخة العرض غير موجودة أو خارج نطاق صلاحياتك.', 'auto-dealership-core' ), array( 'status' => 404 ) );
	}
}
