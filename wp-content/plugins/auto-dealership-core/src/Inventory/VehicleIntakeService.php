<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

/** Audited physical receipt and mandatory inspection evidence. */
final class VehicleIntakeService {
	private const CHECKS = array( 'exterior','interior','engine','tires','vin' );

	public static function receive( int $vehicle_id, array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { return self::error( 'adc_intake_forbidden', 403 ); }
		$condition = sanitize_key( $input['condition'] ?? '' ); $document = sanitize_text_field( $input['document_reference'] ?? '' ); $notes = sanitize_textarea_field( $input['notes'] ?? '' ); $odometer = absint( $input['odometer'] ?? 0 );
		$media = self::media_ids( $input['evidence_media_ids'] ?? array() );
		if ( ! in_array( $condition, array( 'good','damaged','incomplete' ), true ) || '' === $document || is_wp_error( $media ) ) { return self::error( 'adc_invalid_receipt', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,location_id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d FOR UPDATE', $vehicle_id ), ARRAY_A );
		if ( ! $v || 'received' !== $v['status'] || ! VehicleService::user_can_access_branch( (int) $v['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_receipt_state', 409 ); }
		$ok = $wpdb->insert( Schema::table( 'vehicle_receipts' ), array( 'vehicle_id' => $vehicle_id, 'location_id' => (int) $v['location_id'], 'received_by' => get_current_user_id(), 'odometer' => $odometer, 'condition_key' => $condition, 'document_reference' => $document, 'notes' => $notes, 'evidence_media_ids' => wp_json_encode( $media ), 'created_at' => current_time( 'mysql', true ) ), array( '%d','%d','%d','%d','%s','%s','%s','%s','%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( 1 !== $ok || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.receipt_recorded', 'vehicle_receipt', $id, '', null, array( 'vehicle_id' => $vehicle_id, 'condition' => $condition, 'evidence_count' => count( $media ) ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_receipt_failed', 500 ); }
		return array( 'id' => $id, 'vehicle_id' => $vehicle_id );
	}

	public static function inspect( int $vehicle_id, array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { return self::error( 'adc_intake_forbidden', 403 ); }
		$raw = is_array( $input['checklist'] ?? null ) ? $input['checklist'] : array(); $checklist = array();
		foreach ( self::CHECKS as $key ) { $value = sanitize_key( $raw[ $key ] ?? '' ); if ( ! in_array( $value, array( 'pass','fail' ), true ) ) { return self::error( 'adc_invalid_inspection', 400 ); } $checklist[ $key ] = $value; }
		$media = self::media_ids( $input['evidence_media_ids'] ?? array() ); if ( is_wp_error( $media ) ) { return $media; }
		$status = in_array( 'fail', $checklist, true ) ? 'failed' : 'passed'; $notes = sanitize_textarea_field( $input['notes'] ?? '' );
		if ( 'failed' === $status && '' === trim( $notes ) ) { return self::error( 'adc_inspection_notes_required', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,location_id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d FOR UPDATE', $vehicle_id ), ARRAY_A );
		$receipt = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicle_receipts' ) . ' WHERE vehicle_id=%d', $vehicle_id ) );
		if ( ! $v || 'inspection' !== $v['status'] || ! $receipt || ! VehicleService::user_can_access_branch( (int) $v['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_inspection_state', 409 ); }
		$now=current_time('mysql',true); $ok = $wpdb->insert( Schema::table( 'vehicle_inspections' ), array( 'vehicle_id' => $vehicle_id, 'inspector_user_id' => get_current_user_id(), 'status' => $status, 'checklist' => wp_json_encode( $checklist ), 'notes' => $notes, 'evidence_media_ids' => wp_json_encode( $media ), 'created_at' => $now ), array( '%d','%d','%s','%s','%s','%s','%s' ) ); $id = (int) $wpdb->insert_id; $issue_id=0;
		if ( 1 === $ok && 'failed' === $status ) {
			$wpdb->insert(Schema::table('vehicle_issues'),array('vehicle_id'=>$vehicle_id,'inspection_id'=>$id,'issue_type'=>'maintenance','status'=>'open','reason'=>$notes,'assigned_user_id'=>get_current_user_id(),'resolution'=>'','opened_by'=>get_current_user_id(),'created_at'=>$now),array('%d','%d','%s','%s','%s','%d','%s','%d','%s')); $issue_id=(int)$wpdb->insert_id;
			$vehicle_update=$issue_id?$wpdb->update(Schema::table('vehicles'),array('status'=>'maintenance','updated_at'=>$now),array('id'=>$vehicle_id,'status'=>'inspection'),array('%s','%s'),array('%d','%s')):false;
			$movement=1===$vehicle_update?$wpdb->insert(Schema::table('vehicle_movements'),array('vehicle_id'=>$vehicle_id,'from_branch_id'=>(int)$v['branch_id'],'to_branch_id'=>(int)$v['branch_id'],'from_location_id'=>(int)$v['location_id'],'to_location_id'=>(int)$v['location_id'],'from_status'=>'inspection','to_status'=>'maintenance','actor_user_id'=>get_current_user_id(),'reason'=>$notes,'created_at'=>$now),array('%d','%d','%d','%d','%d','%s','%s','%d','%s','%s')):false;
			if(!$issue_id||1!==$vehicle_update||1!==$movement){$wpdb->query('ROLLBACK');return self::error('adc_inspection_failed',500);}
		}
		$audit=static function()use($id,$vehicle_id,$status,$media,$issue_id,$notes){$first=AuditLog::record('vehicle.inspected','vehicle_inspection',$id,'',null,array('vehicle_id'=>$vehicle_id,'status'=>$status,'evidence_count'=>count($media)));return $first&&(!$issue_id||AuditLog::record('vehicle.issue_opened','vehicle_issue',$issue_id,$notes,null,array('vehicle_id'=>$vehicle_id,'type'=>'maintenance','inspection_id'=>$id)));};
		if ( 1 !== $ok || ! Transaction::commit( $audit ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_inspection_failed', 500 ); }
		return array( 'id' => $id, 'vehicle_id' => $vehicle_id, 'status' => $status, 'issue_id'=>$issue_id );
	}

	public static function has_receipt( int $vehicle_id ): bool { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicle_receipts' ) . ' WHERE vehicle_id=%d', $vehicle_id ) ); }
	public static function passed( int $vehicle_id ): bool {
		global $wpdb;
		$inspection = $wpdb->get_row( $wpdb->prepare( 'SELECT id,status FROM ' . Schema::table( 'vehicle_inspections' ) . ' WHERE vehicle_id=%d ORDER BY id DESC LIMIT 1', $vehicle_id ), ARRAY_A );
		if ( ! $inspection || 'passed' !== $inspection['status'] ) { return false; }
		$baseline = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(inspection_baseline_id),0) FROM ' . Schema::table( 'vehicle_returns' ) . ' WHERE vehicle_id=%d', $vehicle_id ) );
		$issue_baseline = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(inspection_baseline_id),0) FROM ' . Schema::table( 'vehicle_issues' ) . " WHERE vehicle_id=%d AND status='resolved'", $vehicle_id ) );
		return null !== $baseline && null !== $issue_baseline && (int) $inspection['id'] > max( (int)$baseline, (int)$issue_baseline );
	}
	private static function media_ids( $values ) { if ( ! is_array( $values ) || count( $values ) > 10 ) { return self::error( 'adc_invalid_evidence', 400 ); } $ids=array(); foreach($values as $value){$id=absint($value); if($id<1 || 'attachment'!==get_post_type($id) || !str_starts_with((string)get_post_mime_type($id),'image/')){return self::error('adc_invalid_evidence',400);} $ids[$id]=$id;} return array_values($ids); }
	private static function error( string $code, int $status ): \WP_Error { return new \WP_Error( $code, __( 'Vehicle intake operation could not be completed.', 'auto-dealership-core' ), array( 'status' => $status ) ); }
}
