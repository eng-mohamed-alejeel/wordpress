<?php
namespace AutoDealership\Sales;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Cancels an undelivered sale and reconciles its operational records atomically. */
final class SaleCancellationService {
	public static function cancel( int $sale_id, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_cancel_sales' ) ) { return self::error( 'adc_sale_cancel_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( $sale_id < 1 || '' === trim( $reason ) ) { return self::error( 'adc_sale_cancel_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$sale = $wpdb->get_row( $wpdb->prepare( 'SELECT s.id,s.owner_user_id,s.reservation_id,s.vehicle_id,s.status,v.branch_id,v.location_id,v.status vehicle_status FROM '.Schema::table('sales').' s INNER JOIN '.Schema::table('vehicles').' v ON v.id=s.vehicle_id WHERE s.id=%d FOR UPDATE', $sale_id ), ARRAY_A );
		$expected = array( 'pending_approval'=>'reserved', 'approved'=>'sold', 'ready_for_delivery'=>'ready_for_delivery' );
		if ( ! $sale || ! isset($expected[$sale['status']]) || $expected[$sale['status']] !== $sale['vehicle_status'] || ! BranchScope::allows((int)$sale['branch_id']) ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_state'); }
		if ( (int) $sale['owner_user_id'] === get_current_user_id() ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_self'); }
		if ( $wpdb->get_var($wpdb->prepare('SELECT id FROM '.Schema::table('sale_cancellations').' WHERE sale_id=%d',$sale_id)) ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_exists'); }
		$delivery = $wpdb->get_row($wpdb->prepare('SELECT id,status FROM '.Schema::table('deliveries').' WHERE sale_id=%d FOR UPDATE',$sale_id),ARRAY_A);
		if ( $wpdb->last_error ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_failed',500); }
		if ( $delivery && ! in_array($delivery['status'],array('preparing','vin_confirmed','approved'),true) ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_delivery'); }
		$verified = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM ".Schema::table('payment_confirmations')." WHERE sale_id=%d AND status='verified' AND currency='SAR'",$sale_id));
		$verified = \AutoDealership\Pricing\Money::parse( $verified );
		if ( null === $verified ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_balance_failed',500); }
		$financial = $verified > 0 ? 'pending_refund' : 'no_refund_due'; $vehicle_status = $verified > 0 ? 'hold' : 'available'; $now=current_time('mysql',true);
		$inserted=$wpdb->insert(Schema::table('sale_cancellations'),array('sale_id'=>$sale_id,'reservation_id'=>(int)$sale['reservation_id'],'delivery_id'=>(int)($delivery['id']??0),'vehicle_id'=>(int)$sale['vehicle_id'],'branch_id'=>(int)$sale['branch_id'],'previous_sale_status'=>$sale['status'],'verified_amount'=>$verified,'financial_status'=>$financial,'reason'=>$reason,'cancelled_by'=>get_current_user_id(),'created_at'=>$now),array('%d','%d','%d','%d','%d','%s','%d','%s','%s','%d','%s')); $id=(int)$wpdb->insert_id;
		$sale_updated=1===$inserted?$wpdb->update(Schema::table('sales'),array('status'=>'cancelled','updated_at'=>$now),array('id'=>$sale_id,'status'=>$sale['status']),array('%s','%s'),array('%d','%s')):false;
		$reservation_updated=1===$sale_updated?$wpdb->update(Schema::table('reservations'),array('status'=>'cancelled','updated_at'=>$now),array('id'=>(int)$sale['reservation_id'],'status'=>'converted_to_sale'),array('%s','%s'),array('%d','%s')):false;
		$delivery_updated=$delivery&&1===$reservation_updated?$wpdb->update(Schema::table('deliveries'),array('status'=>'cancelled','updated_at'=>$now),array('id'=>(int)$delivery['id'],'status'=>$delivery['status']),array('%s','%s'),array('%d','%s')):($delivery?false:1);
		$vehicle_updated=1===$delivery_updated?$wpdb->update(Schema::table('vehicles'),array('status'=>$vehicle_status,'updated_at'=>$now),array('id'=>(int)$sale['vehicle_id'],'status'=>$sale['vehicle_status']),array('%s','%s'),array('%d','%s')):false;
		$payments_updated = $wpdb->update(Schema::table('payment_confirmations'),array('status'=>'cancelled','decided_by'=>get_current_user_id(),'decision_reason'=>$reason,'decided_at'=>$now),array('sale_id'=>$sale_id,'status'=>'pending'),array('%s','%d','%s','%s'),array('%d','%s'));
		$finance_updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table('finance_requests') . " SET status='cancelled',updated_at=%s WHERE sale_id=%d AND status IN ('submitted','under_review')", $now, $sale_id ) );
		if ( false === $payments_updated || false === $finance_updated ) { $wpdb->query('ROLLBACK'); return self::error('adc_sale_cancel_failed',500); }
		$movement=1===$vehicle_updated?$wpdb->insert(Schema::table('vehicle_movements'),array('vehicle_id'=>(int)$sale['vehicle_id'],'from_branch_id'=>(int)$sale['branch_id'],'to_branch_id'=>(int)$sale['branch_id'],'from_location_id'=>(int)$sale['location_id'],'to_location_id'=>(int)$sale['location_id'],'from_status'=>$sale['vehicle_status'],'to_status'=>$vehicle_status,'actor_user_id'=>get_current_user_id(),'reason'=>$reason,'created_at'=>$now),array('%d','%d','%d','%d','%d','%s','%s','%d','%s','%s')):false;
		$issue=1; if($verified>0&&1===$movement){$issue=$wpdb->insert(Schema::table('vehicle_issues'),array('vehicle_id'=>(int)$sale['vehicle_id'],'inspection_id'=>0,'cancellation_id'=>$id,'issue_type'=>'hold','status'=>'open','reason'=>$reason,'assigned_user_id'=>0,'resolution'=>'','opened_by'=>get_current_user_id(),'created_at'=>$now),array('%d','%d','%d','%s','%s','%s','%d','%s','%d','%s'));}
		$audit=static fn()=>AuditLog::record('sale.cancelled','sale_cancellation',$id,$reason,array('sale_status'=>$sale['status'],'vehicle_status'=>$sale['vehicle_status']),array('sale_status'=>'cancelled','vehicle_status'=>$vehicle_status,'verified_amount'=>$verified,'financial_status'=>$financial))&&AuditLog::record('vehicle.status_changed','vehicle',(int)$sale['vehicle_id'],$reason,array('status'=>$sale['vehicle_status']),array('status'=>$vehicle_status));
		if(1!==$inserted||1!==$sale_updated||1!==$reservation_updated||1!==$delivery_updated||1!==$vehicle_updated||1!==$movement||1!==$issue||!Transaction::commit($audit)){$wpdb->query('ROLLBACK');return self::error('adc_sale_cancel_failed',500);}
		return array('id'=>$id,'sale_id'=>$sale_id,'status'=>'cancelled','vehicle_status'=>$vehicle_status,'financial_status'=>$financial,'verified_amount'=>$verified);
	}
	public static function list_for_current_user():array{global $wpdb;if(!current_user_can('adc_cancel_sales')){return array();}list($scope,$args)=BranchScope::predicate('c.branch_id');$sql='SELECT c.*,v.stock_number FROM '.Schema::table('sale_cancellations').' c INNER JOIN '.Schema::table('vehicles').' v ON v.id=c.vehicle_id WHERE '.$scope.' ORDER BY c.id DESC LIMIT 200';return $wpdb->get_results($args?$wpdb->prepare($sql,$args):$sql,ARRAY_A)?:array();}
	public static function cancellable_sales():array{global $wpdb;if(!current_user_can('adc_cancel_sales')){return array();}list($scope,$args)=BranchScope::predicate('v.branch_id');$sql="SELECT s.id,s.status,v.stock_number FROM ".Schema::table('sales')." s INNER JOIN ".Schema::table('vehicles')." v ON v.id=s.vehicle_id WHERE s.status IN ('pending_approval','approved','ready_for_delivery') AND ".$scope.' ORDER BY s.id DESC LIMIT 200';return $wpdb->get_results($args?$wpdb->prepare($sql,$args):$sql,ARRAY_A)?:array();}
	private static function error(string $code,int $status=409):\WP_Error{return new \WP_Error($code,__('Sale cancellation could not be completed.','auto-dealership-core'),array('status'=>$status));}
}
