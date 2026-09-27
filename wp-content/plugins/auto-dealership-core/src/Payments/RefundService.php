<?php
namespace AutoDealership\Payments;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Independent attestations of external refunds; no funds are moved by this service. */
final class RefundService {
	private const METHODS = array( 'cash_refund', 'bank_transfer', 'finance_reversal' );

	public static function request( int $return_id, int $amount, string $method, string $reference ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_record_refunds' ) ) { return self::error( 'adc_refund_forbidden', 403 ); }
		$method = sanitize_key( $method );
		$reference = trim( sanitize_text_field( $reference ) );
		if ( $return_id < 1 || $amount < 1 || ! in_array( $method, self::METHODS, true ) || '' === $reference || strlen( $reference ) > 100 ) { return self::error( 'adc_refund_invalid', 400 ); }
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$return = self::return_row( $return_id );
		if ( ! $return || ! BranchScope::allows( (int) $return['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_refund_return_state' ); }
		$table = Schema::table( 'payment_refunds' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,return_id,amount,status,requested_by FROM $table WHERE method=%s AND reference=%s FOR UPDATE", $method, $reference ), ARRAY_A );
		if ( $wpdb->last_error ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_refund_request_failed', 500 ); }
		if ( $existing ) {
			$wpdb->query( 'ROLLBACK' );
			if ( (int) $existing['return_id'] === $return_id && (int) $existing['amount'] === $amount && (int) $existing['requested_by'] === get_current_user_id() ) { return array( 'id'=>(int)$existing['id'], 'status'=>$existing['status'] ); }
			return self::error( 'adc_refund_reference_conflict' );
		}
		if ( ! in_array( $return['financial_status'], array( 'pending_refund','partially_refunded' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_refund_return_state' ); }
		$paid = self::paid_amount( (int) $return['sale_id'] );
		$committed = self::refund_amount( $return_id, array( 'pending','verified' ) );
		if ( null === $paid || null === $committed ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_refund_balance_failed', 500 ); }
		if ( $paid < 1 || $amount > $paid - $committed ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_refund_exceeds_balance' ); }
		$ok = $wpdb->insert( $table, array( 'return_id'=>$return_id, 'sale_id'=>(int)$return['sale_id'], 'amount'=>$amount, 'currency'=>'SAR', 'method'=>$method, 'reference'=>$reference, 'status'=>'pending', 'requested_by'=>get_current_user_id(), 'decision_reason'=>'', 'created_at'=>current_time('mysql',true) ), array( '%d','%d','%d','%s','%s','%s','%s','%d','%s','%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( 1 !== $ok || ! Transaction::commit( static fn()=>AuditLog::record( 'refund.requested', 'payment_refund', $id, '', null, array( 'return_id'=>$return_id, 'sale_id'=>(int)$return['sale_id'], 'amount'=>$amount, 'method'=>$method, 'status'=>'pending' ) ) ) ) { $wpdb->query('ROLLBACK'); return self::error( 'adc_refund_request_failed', 500 ); }
		return array( 'id'=>$id, 'status'=>'pending' );
	}

	public static function request_cancellation( int $cancellation_id, int $amount, string $method, string $reference ) {
		global $wpdb;
		if(!current_user_can('adc_record_refunds')){return self::error('adc_refund_forbidden',403);} $method=sanitize_key($method);$reference=trim(sanitize_text_field($reference));
		if($cancellation_id<1||$amount<1||!in_array($method,self::METHODS,true)||''===$reference||strlen($reference)>100){return self::error('adc_refund_invalid',400);} if(!Transaction::begin()){return self::error('adc_transaction_failed',500);}
		$c=self::cancellation_row($cancellation_id); if(!$c||!BranchScope::allows((int)$c['branch_id'])){$wpdb->query('ROLLBACK');return self::error('adc_refund_cancellation_state');}
		$table=Schema::table('payment_refunds');$existing=$wpdb->get_row($wpdb->prepare("SELECT id,cancellation_id,amount,status,requested_by FROM $table WHERE method=%s AND reference=%s FOR UPDATE",$method,$reference),ARRAY_A);
		if($wpdb->last_error){$wpdb->query('ROLLBACK');return self::error('adc_refund_request_failed',500);}
		if($existing){$wpdb->query('ROLLBACK');if((int)$existing['cancellation_id']===$cancellation_id&&(int)$existing['amount']===$amount&&(int)$existing['requested_by']===get_current_user_id()){return array('id'=>(int)$existing['id'],'status'=>$existing['status']);}return self::error('adc_refund_reference_conflict');}
		if(!in_array($c['financial_status'],array('pending_refund','partially_refunded'),true)){$wpdb->query('ROLLBACK');return self::error('adc_refund_cancellation_state');}
		$paid=self::paid_amount((int)$c['sale_id']);$committed=self::refund_amount_for('cancellation_id',$cancellation_id,array('pending','verified'));
		if(null===$paid||null===$committed){$wpdb->query('ROLLBACK');return self::error('adc_refund_balance_failed',500);}
		if($paid<1||$amount>$paid-$committed){$wpdb->query('ROLLBACK');return self::error('adc_refund_exceeds_balance');}
		$ok=$wpdb->insert($table,array('return_id'=>0,'cancellation_id'=>$cancellation_id,'sale_id'=>(int)$c['sale_id'],'amount'=>$amount,'currency'=>'SAR','method'=>$method,'reference'=>$reference,'status'=>'pending','requested_by'=>get_current_user_id(),'decision_reason'=>'','created_at'=>current_time('mysql',true)),array('%d','%d','%d','%d','%s','%s','%s','%s','%d','%s','%s'));$id=(int)$wpdb->insert_id;
		if(1!==$ok||!Transaction::commit(static fn()=>AuditLog::record('refund.requested','payment_refund',$id,'',null,array('cancellation_id'=>$cancellation_id,'sale_id'=>(int)$c['sale_id'],'amount'=>$amount,'method'=>$method,'status'=>'pending')))){$wpdb->query('ROLLBACK');return self::error('adc_refund_request_failed',500);}return array('id'=>$id,'status'=>'pending');
	}

	public static function decide( int $refund_id, bool $approve, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_verify_refunds' ) ) { return self::error( 'adc_refund_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( '' === trim( $reason ) ) { return self::error( 'adc_refund_reason_required', 400 ); }
		$table = Schema::table( 'payment_refunds' );
		$subject = $wpdb->get_row( $wpdb->prepare( "SELECT return_id,cancellation_id FROM $table WHERE id=%d", $refund_id ), ARRAY_A );
		$return_id = (int)($subject['return_id']??0); $cancellation_id=(int)($subject['cancellation_id']??0);
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$return = $return_id ? self::return_row( $return_id ) : self::cancellation_row( $cancellation_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d FOR UPDATE", $refund_id ), ARRAY_A );
		if ( ! $return || ! $row || (int)$row['return_id'] !== $return_id || (int)$row['cancellation_id'] !== $cancellation_id || ! BranchScope::allows( (int)$return['branch_id'] ) || 'pending' !== $row['status'] || (int)$row['requested_by'] === get_current_user_id() ) { $wpdb->query('ROLLBACK'); return self::error( 'adc_refund_decision_denied' ); }
		$paid = self::paid_amount( (int)$return['sale_id'] );
		$verified = $return_id ? self::refund_amount( $return_id, array( 'verified' ) ) : self::refund_amount_for('cancellation_id',$cancellation_id,array('verified'));
		if ( null === $paid || null === $verified ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'adc_refund_balance_failed', 500 ); }
		if ( $approve && (int)$row['amount'] > $paid - $verified ) { $wpdb->query('ROLLBACK'); return self::error( 'adc_refund_exceeds_balance' ); }
		$status = $approve ? 'verified' : 'rejected';
		$changed = $wpdb->update( $table, array( 'status'=>$status, 'decided_by'=>get_current_user_id(), 'decision_reason'=>$reason, 'decided_at'=>current_time('mysql',true) ), array( 'id'=>$refund_id, 'status'=>'pending' ), array( '%s','%d','%s','%s' ), array( '%d','%s' ) );
		$new_verified = $verified + ( $approve ? (int)$row['amount'] : 0 );
		$financial_status = $new_verified >= $paid ? 'refunded' : ( $new_verified > 0 ? 'partially_refunded' : 'pending_refund' );
		$subject_table=$return_id?Schema::table('vehicle_returns'):Schema::table('sale_cancellations');$subject_id=$return_id?:$cancellation_id;
		$return_changed = 1 === $changed ? $wpdb->update( $subject_table, array( 'financial_status'=>$financial_status ), array( 'id'=>$subject_id ), array('%s'), array('%d') ) : false;
		if ( 1 !== $changed || false === $return_changed || ! Transaction::commit( static fn()=>AuditLog::record( 'refund.'.$status, 'payment_refund', $refund_id, $reason, array('status'=>'pending'), array('status'=>$status,'return_id'=>$return_id,'cancellation_id'=>$cancellation_id,'sale_id'=>(int)$return['sale_id'],'amount'=>(int)$row['amount'],'financial_status'=>$financial_status) ) ) ) { $wpdb->query('ROLLBACK'); return self::error( 'adc_refund_decision_failed', 500 ); }
		return array( 'id'=>$refund_id, 'status'=>$status, 'financial_status'=>$financial_status );
	}

	public static function list_for_current_user(): array {
		global $wpdb;
		if ( ! current_user_can('adc_view_finance') ) { return array(); }
		list($return_scope,$return_args)=BranchScope::predicate('r.branch_id');
		$return_sql='SELECT f.*,r.financial_status,v.stock_number FROM '.Schema::table('payment_refunds').' f INNER JOIN '.Schema::table('vehicle_returns').' r ON r.id=f.return_id INNER JOIN '.Schema::table('vehicles').' v ON v.id=r.vehicle_id WHERE '.$return_scope.' ORDER BY f.id DESC LIMIT 200';
		list($cancel_scope,$cancel_args)=BranchScope::predicate('c.branch_id');
		$cancel_sql='SELECT f.*,c.financial_status,v.stock_number FROM '.Schema::table('payment_refunds').' f INNER JOIN '.Schema::table('sale_cancellations').' c ON c.id=f.cancellation_id INNER JOIN '.Schema::table('vehicles').' v ON v.id=c.vehicle_id WHERE '.$cancel_scope.' ORDER BY f.id DESC LIMIT 200';
		$rows=array_merge($wpdb->get_results($return_args?$wpdb->prepare($return_sql,$return_args):$return_sql,ARRAY_A)?:array(),$wpdb->get_results($cancel_args?$wpdb->prepare($cancel_sql,$cancel_args):$cancel_sql,ARRAY_A)?:array());
		usort($rows,static fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);return array_slice($rows,0,200);
	}

	public static function refundable_returns(): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_finance' ) ) { return array(); }
		list($scope,$args)=BranchScope::predicate('r.branch_id');
		$sql="SELECT r.id,r.sale_id,r.vehicle_id,r.financial_status,v.stock_number FROM ".Schema::table('vehicle_returns')." r INNER JOIN ".Schema::table('vehicles')." v ON v.id=r.vehicle_id WHERE r.financial_status IN ('pending_refund','partially_refunded') AND ".$scope.' ORDER BY r.id DESC LIMIT 200';
		return $wpdb->get_results($args?$wpdb->prepare($sql,$args):$sql,ARRAY_A)?:array();
	}
	public static function refundable_cancellations():array{global $wpdb;if(!current_user_can('adc_view_finance')){return array();}list($scope,$args)=BranchScope::predicate('c.branch_id');$sql="SELECT c.id,c.sale_id,c.vehicle_id,c.financial_status,v.stock_number FROM ".Schema::table('sale_cancellations')." c INNER JOIN ".Schema::table('vehicles')." v ON v.id=c.vehicle_id WHERE c.financial_status IN ('pending_refund','partially_refunded') AND ".$scope.' ORDER BY c.id DESC LIMIT 200';return $wpdb->get_results($args?$wpdb->prepare($sql,$args):$sql,ARRAY_A)?:array();}

	private static function return_row( int $id ): ?array { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT r.*,v.branch_id vehicle_branch FROM '.Schema::table('vehicle_returns').' r INNER JOIN '.Schema::table('vehicles').' v ON v.id=r.vehicle_id WHERE r.id=%d FOR UPDATE',$id),ARRAY_A)?:null; }
	private static function cancellation_row(int $id):?array{global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('sale_cancellations').' WHERE id=%d FOR UPDATE',$id),ARRAY_A)?:null;}
	private static function paid_amount( int $sale_id ): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Schema::table( 'payment_confirmations' ) . " WHERE sale_id=%d AND status='verified' AND currency='SAR'", $sale_id ) );
		return \AutoDealership\Pricing\Money::parse( $value );
	}
	private static function refund_amount( int $return_id, array $statuses ): ?int {
		return self::refund_amount_for( 'return_id', $return_id, $statuses );
	}
	private static function refund_amount_for( string $column, int $id, array $statuses ): ?int {
		global $wpdb;
		if ( ! in_array( $column, array( 'return_id','cancellation_id' ), true ) ) { return null; }
		$allowed = array_values( array_intersect( $statuses, array( 'pending','verified' ) ) );
		if ( ! $allowed ) { return null; }
		$marks = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Schema::table( 'payment_refunds' ) . " WHERE $column=%d AND currency='SAR' AND status IN ($marks)", array_merge( array( $id ), $allowed ) ) );
		return \AutoDealership\Pricing\Money::parse( $value );
	}
	private static function error( string $code, int $status=409 ): \WP_Error { return new \WP_Error($code,__('Refund operation could not be completed.','auto-dealership-core'),array('status'=>$status)); }
}
