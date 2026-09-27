<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Audited operational hold and maintenance cases. */
final class VehicleIssueService {
	public static function open( int $vehicle_id, string $type, string $reason, int $assigned_user_id = 0, string $review_at = '' ) {
		global $wpdb;
		$type = sanitize_key( $type ); $reason = sanitize_textarea_field( $reason ); $review_at = self::date( $review_at );
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { return self::error( 'adc_issue_forbidden', 403 ); }
		if ( ! in_array( $type, array( 'hold','maintenance' ), true ) || '' === trim( $reason ) || false === $review_at ) { return self::error( 'adc_invalid_issue', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT id,branch_id,location_id,status FROM ' . Schema::table( 'vehicles' ) . ' WHERE id=%d FOR UPDATE', $vehicle_id ), ARRAY_A );
		if ( ! $v || ! VehicleService::user_can_access_branch( (int) $v['branch_id'] ) || ! in_array( $v['status'], array( 'in_transit','received','inspection','available','returned','hold','maintenance' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_issue_state', 409 ); }
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'vehicle_issues' ) . " WHERE vehicle_id=%d AND status='open' LIMIT 1", $vehicle_id ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_issue_exists', 409 ); }
		if ( $assigned_user_id && ! user_can( $assigned_user_id, 'adc_manage_inventory' ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_issue_assignee', 400 ); }
		$now=current_time('mysql',true); $issues=Schema::table('vehicle_issues');
		$ok=$wpdb->insert($issues,array('vehicle_id'=>$vehicle_id,'inspection_id'=>0,'issue_type'=>$type,'status'=>'open','reason'=>$reason,'assigned_user_id'=>$assigned_user_id,'review_at'=>$review_at?:null,'resolution'=>'','opened_by'=>get_current_user_id(),'created_at'=>$now),array('%d','%d','%s','%s','%s','%d','%s','%s','%d','%s')); $id=(int)$wpdb->insert_id;
		$updated=1===$ok?$wpdb->update(Schema::table('vehicles'),array('status'=>$type,'updated_at'=>$now),array('id'=>$vehicle_id),array('%s','%s'),array('%d')):false;
		$movement=1===$updated?$wpdb->insert(Schema::table('vehicle_movements'),array('vehicle_id'=>$vehicle_id,'from_branch_id'=>(int)$v['branch_id'],'to_branch_id'=>(int)$v['branch_id'],'from_location_id'=>(int)$v['location_id'],'to_location_id'=>(int)$v['location_id'],'from_status'=>$v['status'],'to_status'=>$type,'actor_user_id'=>get_current_user_id(),'reason'=>$reason,'created_at'=>$now),array('%d','%d','%d','%d','%d','%s','%s','%d','%s','%s')):false;
		if(1!==$ok||1!==$updated||1!==$movement||!Transaction::commit(static fn()=>AuditLog::record('vehicle.issue_opened','vehicle_issue',$id,$reason,null,array('vehicle_id'=>$vehicle_id,'type'=>$type,'assigned_user_id'=>$assigned_user_id)))){$wpdb->query('ROLLBACK');return self::error('adc_issue_failed',500);} return array('id'=>$id,'vehicle_id'=>$vehicle_id,'status'=>'open','type'=>$type);
	}

	public static function resolve( int $issue_id, string $resolution ) {
		global $wpdb; $resolution=sanitize_textarea_field($resolution);
		if(!current_user_can('adc_manage_inventory')){return self::error('adc_issue_forbidden',403);} if(''===trim($resolution)){return self::error('adc_issue_resolution',400);} if(!Transaction::begin()){return self::error('adc_transaction_failed',500);}
		$i=$wpdb->get_row($wpdb->prepare('SELECT i.*,v.branch_id,v.location_id,v.status vehicle_status FROM '.Schema::table('vehicle_issues').' i INNER JOIN '.Schema::table('vehicles').' v ON v.id=i.vehicle_id WHERE i.id=%d FOR UPDATE',$issue_id),ARRAY_A);
		if(!$i||'open'!==$i['status']||!VehicleService::user_can_access_branch((int)$i['branch_id'])||$i['issue_type']!==$i['vehicle_status']){$wpdb->query('ROLLBACK');return self::error('adc_issue_state',409);}
		if((int)$i['cancellation_id']>0&&'refunded'!==$wpdb->get_var($wpdb->prepare('SELECT financial_status FROM '.Schema::table('sale_cancellations').' WHERE id=%d',(int)$i['cancellation_id']))){$wpdb->query('ROLLBACK');return self::error('adc_issue_refund_required',409);}
		$baseline=$wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(id),0) FROM '.Schema::table('vehicle_inspections').' WHERE vehicle_id=%d',(int)$i['vehicle_id']));
		if(null===$baseline){$wpdb->query('ROLLBACK');return self::error('adc_issue_failed',500);}
		$now=current_time('mysql',true);$updated=$wpdb->update(Schema::table('vehicle_issues'),array('status'=>'resolved','resolution'=>$resolution,'resolved_by'=>get_current_user_id(),'resolved_at'=>$now,'inspection_baseline_id'=>(int)$baseline),array('id'=>$issue_id,'status'=>'open'),array('%s','%s','%d','%s','%d'),array('%d','%s'));
		$vehicle=1===$updated?$wpdb->update(Schema::table('vehicles'),array('status'=>'inspection','updated_at'=>$now),array('id'=>(int)$i['vehicle_id'],'status'=>$i['issue_type']),array('%s','%s'),array('%d','%s')):false;
		$movement=1===$vehicle?$wpdb->insert(Schema::table('vehicle_movements'),array('vehicle_id'=>(int)$i['vehicle_id'],'from_branch_id'=>(int)$i['branch_id'],'to_branch_id'=>(int)$i['branch_id'],'from_location_id'=>(int)$i['location_id'],'to_location_id'=>(int)$i['location_id'],'from_status'=>$i['issue_type'],'to_status'=>'inspection','actor_user_id'=>get_current_user_id(),'reason'=>$resolution,'created_at'=>$now),array('%d','%d','%d','%d','%d','%s','%s','%d','%s','%s')):false;
		if(1!==$updated||1!==$vehicle||1!==$movement||!Transaction::commit(static fn()=>AuditLog::record('vehicle.issue_resolved','vehicle_issue',$issue_id,$resolution,array('status'=>'open'),array('status'=>'resolved','vehicle_id'=>(int)$i['vehicle_id'])))){$wpdb->query('ROLLBACK');return self::error('adc_issue_failed',500);} return array('id'=>$issue_id,'status'=>'resolved','vehicle_id'=>(int)$i['vehicle_id']);
	}
	public static function list_open(): array { global $wpdb; list($scope,$args)=BranchScope::predicate('v.branch_id'); $sql='SELECT i.id,i.vehicle_id,i.issue_type,i.reason,i.assigned_user_id,i.review_at,i.created_at,v.stock_number,v.brand,v.model FROM '.Schema::table('vehicle_issues').' i INNER JOIN '.Schema::table('vehicles').' v ON v.id=i.vehicle_id WHERE i.status=\'open\' AND '.$scope.' ORDER BY i.created_at ASC LIMIT 200'; return $wpdb->get_results($args?$wpdb->prepare($sql,$args):$sql,ARRAY_A)?:array(); }
	private static function date(string $value){if(''===trim($value)){return null;}$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));return $d&&$d->format('Y-m-d')===$value?$value.' 00:00:00':false;}
	private static function error(string $code,int $status):\WP_Error{return new \WP_Error($code,__('Vehicle issue operation could not be completed.','auto-dealership-core'),array('status'=>$status));}
}
