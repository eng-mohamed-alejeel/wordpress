<?php
namespace AutoDealership\Reference;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Audited brand and physical-location reference management. */
final class ReferenceService {
	public static function create_brand( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'adc_reference_forbidden', 403 ); }
		$key = sanitize_key( $input['key'] ?? '' ); $ar = sanitize_text_field( $input['name_ar'] ?? '' ); $en = sanitize_text_field( $input['name_en'] ?? '' );
		if ( '' === $key || '' === $ar || strlen( $key ) > 64 || strlen( $ar ) > 120 || strlen( $en ) > 120 ) { return self::error( 'adc_invalid_brand', 400 ); }
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'brands' ) . ' WHERE brand_key=%s', $key ) ) ) { return self::error( 'adc_brand_conflict', 409 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$now = current_time( 'mysql', true );
		if ( 1 !== $wpdb->insert( Schema::table( 'brands' ), array( 'brand_key' => $key, 'name_ar' => $ar, 'name_en' => $en, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ), array( '%s','%s','%s','%d','%s','%s' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_brand_conflict', 409 ); }
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'brand.created', 'brand', $id, '', null, array( 'key' => $key, 'active' => true ) ) ) ) { return self::error( 'adc_brand_failed', 500 ); }
		return array( 'id' => $id, 'key' => $key, 'name_ar' => $ar, 'name_en' => $en, 'active' => true );
	}

	public static function create_location( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'adc_reference_forbidden', 403 ); }
		$branch = absint( $input['branch_id'] ?? 0 ); $code = strtoupper( sanitize_key( $input['code'] ?? '' ) ); $name = sanitize_text_field( $input['name'] ?? '' ); $type = sanitize_key( $input['type'] ?? '' );
		if ( ! in_array( $type, array( 'showroom','warehouse','yard','service' ), true ) || '' === $code || '' === $name || ! self::active_branch( $branch ) ) { return self::error( 'adc_invalid_location', 400 ); }
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'locations' ) . ' WHERE code=%s', $code ) ) ) { return self::error( 'adc_location_conflict', 409 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$now = current_time( 'mysql', true );
		if ( 1 !== $wpdb->insert( Schema::table( 'locations' ), array( 'branch_id' => $branch, 'code' => $code, 'name' => $name, 'location_type' => $type, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ), array( '%d','%s','%s','%s','%d','%s','%s' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_location_conflict', 409 ); }
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'location.created', 'location', $id, '', null, array( 'branch_id' => $branch, 'code' => $code, 'type' => $type, 'active' => true ) ) ) ) { return self::error( 'adc_location_failed', 500 ); }
		return array( 'id' => $id, 'branch_id' => $branch, 'code' => $code, 'name' => $name, 'type' => $type, 'active' => true );
	}

	public static function set_active( string $kind, int $id, bool $active ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $kind, array( 'brand','location' ), true ) || $id < 1 ) { return self::error( 'adc_reference_forbidden', 403 ); }
		$table = Schema::table( $kind . 's' );
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_reference_missing', 404 ); }
		if ( ! $active ) {
			$column = 'brand' === $kind ? 'brand_id' : 'location_id';
			$used = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicles' ) . " WHERE $column=%d AND status NOT IN ('delivered','cancelled') LIMIT 1", $id ) );
			if ( $used ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_reference_in_use', 409 ); }
		}
		$before = (bool) $row['active'];
		if ( $before === $active ) { $wpdb->query( 'ROLLBACK' ); return array( 'id' => $id, 'active' => $active, 'updated' => false ); }
		if ( 1 !== $wpdb->update( $table, array( 'active' => $active ? 1 : 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%d','%s' ), array( '%d' ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_reference_failed', 500 ); }
		if ( ! Transaction::commit( static fn() => AuditLog::record( $kind . '.status_changed', $kind, $id, '', array( 'active' => $before ), array( 'active' => $active ) ) ) ) { return self::error( 'adc_reference_failed', 500 ); }
		return array( 'id' => $id, 'active' => $active, 'updated' => true );
	}

	public static function active_brand( int $id ): ?array { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT id,name_ar,name_en FROM ' . Schema::table( 'brands' ) . ' WHERE id=%d AND active=1', $id ), ARRAY_A ) ?: null; }
	public static function active_location( int $id, int $branch ): ?array { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,name,location_type FROM ' . Schema::table( 'locations' ) . ' WHERE id=%d AND branch_id=%d AND active=1', $id, $branch ), ARRAY_A ) ?: null; }
	public static function brands(): array { global $wpdb; return $wpdb->get_results( 'SELECT id,brand_key,name_ar,name_en,active FROM ' . Schema::table( 'brands' ) . ' ORDER BY active DESC,name_ar ASC', ARRAY_A ) ?: array(); }
	public static function locations(): array { global $wpdb; return $wpdb->get_results( 'SELECT l.id,l.branch_id,l.code,l.name,l.location_type,l.active,b.name branch_name FROM ' . Schema::table( 'locations' ) . ' l INNER JOIN ' . Schema::table( 'branches' ) . ' b ON b.id=l.branch_id ORDER BY l.active DESC,b.name,l.name', ARRAY_A ) ?: array(); }
	private static function active_branch( int $id ): bool { global $wpdb; return $id > 0 && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT active FROM ' . Schema::table( 'branches' ) . ' WHERE id=%d', $id ) ); }
	private static function error( string $code, int $status ): \WP_Error { return new \WP_Error( $code, __( 'Reference data operation could not be completed.', 'auto-dealership-core' ), array( 'status' => $status ) ); }
}
