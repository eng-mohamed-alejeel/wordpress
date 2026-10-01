<?php
namespace AutoDealership\Purchasing;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Global supplier directory. Purchase approvals remain disabled until their policy is approved. */
final class SupplierService {
	public static function all( bool $active_only = false ): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_suppliers' ) && ! current_user_can( 'adc_manage_suppliers' ) ) {
			return array();
		}
		$where = $active_only ? ' WHERE active=1' : '';
		$restricted = current_user_can( 'adc_manage_suppliers' ) || current_user_can( 'adc_view_vehicle_costs' ) || current_user_can( 'manage_options' );
		$fields = $restricted ? 'id,supplier_code,display_name,legal_name,country,tax_number,contact_name,contact_email,contact_phone,notes,active,created_at,updated_at' : 'id,supplier_code,display_name,country,active';
		return $wpdb->get_results( 'SELECT ' . $fields . ' FROM ' . Schema::table( 'suppliers' ) . $where . ' ORDER BY active DESC,display_name ASC,id ASC LIMIT 500', ARRAY_A ) ?: array();
	}

	public static function active( int $id ): ?array {
		global $wpdb;
		if ( $id < 1 ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,supplier_code,display_name,country FROM ' . Schema::table( 'suppliers' ) . ' WHERE id=%d AND active=1', $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function create( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_suppliers' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$data = self::validate( $input );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$now = current_time( 'mysql', true );
		$data['active'] = 1;
		$data['created_by'] = get_current_user_id();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$inserted = $wpdb->insert( Schema::table( 'suppliers' ), $data );
		$id = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || ! Transaction::commit( static fn() => AuditLog::record( 'supplier.created', 'supplier', $id, '', null, array( 'supplier_code'=>$data['supplier_code'], 'display_name'=>$data['display_name'], 'country'=>$data['country'], 'active'=>true ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( false === $inserted ? 'adc_supplier_conflict' : 'adc_supplier_failed', false === $inserted ? 409 : 500 );
		}
		return array( 'id'=>$id, 'supplier_code'=>$data['supplier_code'], 'display_name'=>$data['display_name'], 'active'=>true );
	}

	public static function set_active( int $id, bool $active, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_suppliers' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( $id < 1 || '' === trim( $reason ) || mb_strlen( $reason ) > 1000 ) { return self::error( 'adc_supplier_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$table = Schema::table( 'suppliers' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,active FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_supplier_not_found', 404 ); }
		$before = (bool) $row['active'];
		if ( $before === $active ) { $wpdb->query( 'ROLLBACK' ); return array( 'id'=>$id, 'active'=>$active, 'updated'=>false ); }
		$updated = $wpdb->update( $table, array( 'active'=>$active ? 1 : 0, 'updated_at'=>current_time( 'mysql', true ) ), array( 'id'=>$id, 'active'=>$before ? 1 : 0 ), array( '%d','%s' ), array( '%d','%d' ) );
		if ( 1 !== $updated || ! Transaction::commit( static fn() => AuditLog::record( 'supplier.status_changed', 'supplier', $id, $reason, array( 'active'=>$before ), array( 'active'=>$active ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_supplier_failed', 500 );
		}
		return array( 'id'=>$id, 'active'=>$active, 'updated'=>true );
	}

	private static function validate( array $input ) {
		$code = strtoupper( sanitize_text_field( (string) ( $input['supplier_code'] ?? '' ) ) );
		$name = sanitize_text_field( (string) ( $input['display_name'] ?? '' ) );
		$email_raw = trim( (string) ( $input['contact_email'] ?? '' ) );
		$email = '' === $email_raw ? '' : sanitize_email( $email_raw );
		if ( ! preg_match( '/\A[A-Z0-9][A-Z0-9._-]{1,63}\z/', $code ) || '' === $name || mb_strlen( $name ) > 190 || ( '' !== $email_raw && '' === $email ) ) {
			return self::error( 'adc_supplier_invalid', 400 );
		}
		$data = array(
			'supplier_code'=>$code,
			'display_name'=>$name,
			'legal_name'=>sanitize_text_field( (string) ( $input['legal_name'] ?? '' ) ),
			'country'=>sanitize_text_field( (string) ( $input['country'] ?? '' ) ),
			'tax_number'=>sanitize_text_field( (string) ( $input['tax_number'] ?? '' ) ),
			'contact_name'=>sanitize_text_field( (string) ( $input['contact_name'] ?? '' ) ),
			'contact_email'=>$email,
			'contact_phone'=>sanitize_text_field( (string) ( $input['contact_phone'] ?? '' ) ),
			'notes'=>sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
		);
		$limits = array( 'legal_name'=>190, 'country'=>80, 'tax_number'=>100, 'contact_name'=>190, 'contact_email'=>190, 'contact_phone'=>40, 'notes'=>4000 );
		foreach ( $limits as $field=>$limit ) { if ( mb_strlen( $data[$field] ) > $limit ) { return self::error( 'adc_supplier_invalid', 400 ); } }
		return $data;
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Supplier data could not be saved. Check the values, permissions and unique supplier code.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
