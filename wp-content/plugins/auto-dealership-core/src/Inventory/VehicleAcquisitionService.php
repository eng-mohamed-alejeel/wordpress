<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Purchasing\SupplierService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Restricted supplier, landed-cost and acquisition-document fields for a vehicle. */
final class VehicleAcquisitionService {
	private const EDITABLE_STATES = array( 'ordered','in_transit','received','inspection','available','hold','maintenance','returned' );
	private const FIELDS = array( 'supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price','customs_reference','arrival_date','internal_notes' );

	public static function get( int $vehicle_id ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_vehicle_costs' ) && ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		array_unshift( $args, $vehicle_id );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT v.id,v.stock_number,v.brand,v.model,v.branch_id,v.status,v.supplier_id,v.purchase_cost,v.additional_cost,v.total_cost,v.wholesale_price,v.customs_reference,v.arrival_date,v.document_media_ids,v.internal_notes,s.supplier_code,s.display_name supplier_name FROM ' . Schema::table( 'vehicles' ) . ' v LEFT JOIN ' . Schema::table( 'suppliers' ) . ' s ON s.id=v.supplier_id WHERE v.id=%d AND ' . $scope, $args ), ARRAY_A );
		if ( ! $row ) { return self::error( 'adc_vehicle_not_found', 404 ); }
		foreach ( array( 'id','branch_id','supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price' ) as $field ) { if ( null !== $row[$field] ) { $row[$field] = (int) $row[$field]; } }
		$row['legacy_public_document_count'] = count( self::decode_ids( $row['document_media_ids'] ) );
		unset( $row['document_media_ids'] );
		$documents = VehicleAcquisitionDocuments::all( $vehicle_id );
		if ( is_wp_error( $documents ) ) { return $documents; }
		$row['private_documents'] = $documents;
		$public_originals = VehicleAcquisitionDocuments::legacy_public_original_count( $vehicle_id );
		if ( is_wp_error( $public_originals ) ) { return $public_originals; }
		$row['migrated_public_original_count'] = $public_originals;
		return $row;
	}

	public static function update( int $vehicle_id, array $input, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_vehicle_costs' ) ) { return self::error( 'adc_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( array_key_exists( 'document_media_ids', $input ) ) {
			return new \WP_Error( 'adc_acquisition_public_document_unsupported', __( 'Public media-library IDs are no longer accepted for acquisition documents. Use the private upload section.', 'auto-dealership-core' ), array( 'status'=>400 ) );
		}
		if ( $vehicle_id < 1 || '' === trim( $reason ) || mb_strlen( $reason ) > 2000 || array_diff( array_keys( $input ), self::FIELDS ) ) { return self::error( 'adc_acquisition_invalid', 400 ); }
		$changes = self::validate( $input );
		if ( is_wp_error( $changes ) ) { return $changes; }
		if ( ! $changes ) { return self::error( 'adc_acquisition_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$table = Schema::table( 'vehicles' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,status,' . implode( ',', self::FIELDS ) . " FROM $table WHERE id=%d FOR UPDATE", $vehicle_id ), ARRAY_A );
		if ( ! $row || ! BranchScope::allows( (int) $row['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_vehicle_not_found', 404 ); }
		if ( ! in_array( $row['status'], self::EDITABLE_STATES, true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_acquisition_locked', 409 ); }
		if ( isset( $changes['supplier_id'] ) && $changes['supplier_id'] > 0 && ! SupplierService::active( $changes['supplier_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_supplier_inactive', 409 ); }
		$changed_fields = array();
		foreach ( $changes as $field=>$value ) {
			$old = $row[$field];
			if ( in_array( $field, array( 'supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price' ), true ) && null !== $old ) { $old = (int) $old; }
			if ( $old === $value ) { unset( $changes[$field] ); } else { $changed_fields[] = $field; }
		}
		if ( ! $changes ) { $wpdb->query( 'ROLLBACK' ); return array( 'id'=>$vehicle_id, 'updated'=>false ); }
		$data = $changes;
		$data['updated_at'] = current_time( 'mysql', true );
		$numeric = array( 'supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price' );
		$formats = array_map( static fn( $field ) => in_array( $field, $numeric, true ) ? '%d' : '%s', array_keys( $changes ) );
		$formats[] = '%s';
		$updated = $wpdb->update( $table, $data, array( 'id'=>$vehicle_id ), $formats, array( '%d' ) );
		if ( 1 !== $updated || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.acquisition_changed', 'vehicle', $vehicle_id, $reason, null, array( 'changed_fields'=>$changed_fields ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_acquisition_failed', 500 );
		}
		return array( 'id'=>$vehicle_id, 'updated'=>true, 'changed_fields'=>$changed_fields );
	}

	private static function validate( array $input ) {
		$result = array();
		foreach ( array( 'supplier_id','purchase_cost','additional_cost','total_cost','wholesale_price' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) { continue; }
			if ( in_array( $field, array( 'purchase_cost','total_cost','wholesale_price' ), true ) && ( null === $input[$field] || '' === $input[$field] ) ) { $result[$field] = null; continue; }
			if ( ! is_int( $input[$field] ) && ( ! is_string( $input[$field] ) || ! preg_match( '/\A[0-9]+\z/', $input[$field] ) ) ) { return self::error( 'adc_acquisition_invalid', 400 ); }
			$result[$field] = (int) $input[$field];
		}
		if ( array_key_exists( 'customs_reference', $input ) ) {
			$result['customs_reference'] = sanitize_text_field( (string) $input['customs_reference'] );
			if ( mb_strlen( $result['customs_reference'] ) > 100 ) { return self::error( 'adc_acquisition_invalid', 400 ); }
		}
		if ( array_key_exists( 'internal_notes', $input ) ) {
			$result['internal_notes'] = sanitize_textarea_field( (string) $input['internal_notes'] );
			if ( mb_strlen( $result['internal_notes'] ) > 5000 ) { return self::error( 'adc_acquisition_invalid', 400 ); }
		}
		if ( array_key_exists( 'arrival_date', $input ) ) {
			$value = trim( (string) $input['arrival_date'] );
			if ( '' === $value ) { $result['arrival_date'] = null; }
			else {
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) { return self::error( 'adc_acquisition_invalid', 400 ); }
				$result['arrival_date'] = $value;
			}
		}
		return $result;
	}

	private static function decode_ids( $value ): array {
		$ids = json_decode( (string) $value, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'Vehicle acquisition data could not be saved. Check permissions, supplier status, values and vehicle state.', 'auto-dealership-core' ), array( 'status'=>$status ) );
	}
}
