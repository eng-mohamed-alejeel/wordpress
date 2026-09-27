<?php
namespace AutoDealership\Payments;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Staff attestations of external receipts. This service never charges a customer. */
final class PaymentService {
	private static function error( string $code, int $status = 409 ): \WP_Error {
		return new \WP_Error( $code, __( 'تعذر تنفيذ تأكيد السداد. تحقق من الصلاحيات والمرجع والمبلغ وحالة البيع.', 'auto-dealership-core' ), array( 'status' => $status ) );
	}

	private static function sale( int $sale_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT s.id,s.status,s.owner_user_id,q.final_amount,v.branch_id FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE s.id = %d FOR UPDATE', $sale_id ), ARRAY_A ) ?: null;
	}

	public static function record( int $sale_id, int $amount, string $source, string $reference ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_record_payments' ) ) { return self::error( 'adc_payment_forbidden', 403 ); }
		$reference = trim( sanitize_text_field( $reference ) );
		if ( $sale_id < 1 || $amount < 1 || ! in_array( $source, array( 'cash_receipt', 'bank_transfer', 'finance_disbursement' ), true ) || '' === $reference || strlen( $reference ) > 100 ) {
			return self::error( 'adc_payment_invalid', 400 );
		}
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		$sale = self::sale( $sale_id );
		if ( ! $sale || ! BranchScope::allows( (int) $sale['branch_id'] ) || ! in_array( $sale['status'], array( 'pending_approval', 'approved' ), true ) || $amount > (int) $sale['final_amount'] ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_payment_sale_state' );
		}
		$table = Schema::table( 'payment_confirmations' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,sale_id,amount,status,recorded_by FROM $table WHERE source = %s AND reference = %s FOR UPDATE", $source, $reference ), ARRAY_A );
		if ( $existing ) {
			$wpdb->query( 'ROLLBACK' );
			if ( (int) $existing['sale_id'] === $sale_id && (int) $existing['amount'] === $amount && (int) $existing['recorded_by'] === get_current_user_id() ) {
				return array( 'id' => (int) $existing['id'], 'status' => $existing['status'] );
			}
			return self::error( 'adc_payment_reference_conflict' );
		}
		$ok = $wpdb->insert( $table, array( 'sale_id' => $sale_id, 'amount' => $amount, 'currency' => 'SAR', 'source' => $source, 'reference' => $reference, 'status' => 'pending', 'recorded_by' => get_current_user_id(), 'decision_reason' => '', 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_payment_record_failed', 500 );
		}
		$id = (int) $wpdb->insert_id;
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'payment.recorded', 'payment', $id, '', null, array( 'sale_id' => $sale_id, 'amount' => $amount, 'source' => $source, 'status' => 'pending' ) ) ) ) {
			return self::error( 'adc_payment_record_failed', 500 );
		}
		return array( 'id' => $id, 'status' => 'pending' );
	}

	public static function decide( int $payment_id, bool $approve, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_verify_payments' ) ) { return self::error( 'adc_payment_forbidden', 403 ); }
		$reason = sanitize_textarea_field( $reason );
		if ( '' === trim( $reason ) ) { return self::error( 'adc_payment_reason_required', 400 ); }
		$table = Schema::table( 'payment_confirmations' );
		$sale_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT sale_id FROM $table WHERE id = %d", $payment_id ) );
		if ( ! Transaction::begin() ) { return self::error( 'adc_transaction_failed', 500 ); }
		// Lock the sale before payment rows, serializing all confirmations for its balance.
		$sale = self::sale( $sale_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d FOR UPDATE", $payment_id ), ARRAY_A );
		if ( ! $sale || ! $row || (int) $row['sale_id'] !== $sale_id || ! BranchScope::allows( (int) $sale['branch_id'] ) || ! in_array( $sale['status'], array( 'pending_approval', 'approved' ), true ) || 'pending' !== $row['status'] || (int) $row['recorded_by'] === get_current_user_id() || (int) $sale['owner_user_id'] === get_current_user_id() ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_payment_decision_denied' );
		}
		if ( $approve ) {
			$paid = self::verified_amount( $sale_id );
			if ( null === $paid || (int) $row['amount'] > (int) $sale['final_amount'] - $paid ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( 'adc_payment_exceeds_balance' );
			}
		}
		$status = $approve ? 'verified' : 'rejected';
		$changed = $wpdb->update( $table, array( 'status' => $status, 'decided_by' => get_current_user_id(), 'decision_reason' => $reason, 'decided_at' => current_time( 'mysql', true ) ), array( 'id' => $payment_id, 'status' => 'pending' ), array( '%s', '%d', '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $changed ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( 'adc_payment_decision_failed', 500 );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'payment.' . $status, 'payment', $payment_id, $reason, array( 'status' => 'pending' ), array( 'status' => $status, 'sale_id' => $sale_id, 'amount' => (int) $row['amount'] ) ) ) ) {
			return self::error( 'adc_payment_decision_failed', 500 );
		}
		return array( 'id' => $payment_id, 'status' => $status );
	}

	private static function verified_amount( int $sale_id ): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . Schema::table( 'payment_confirmations' ) . " WHERE sale_id = %d AND status = 'verified' AND currency = 'SAR'", $sale_id ) );
		return null === $value ? null : (int) $value;
	}

	/** Internal precondition; caller holds the sale lock and checks its capability/scope. */
	public static function is_settled( int $sale_id ): bool {
		global $wpdb;
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT q.final_amount FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id WHERE s.id = %d', $sale_id ) );
		$paid = self::verified_amount( $sale_id );
		return null !== $total && (int) $total > 0 && null !== $paid && $paid >= (int) $total;
	}

	public static function list_for_current_user(): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_finance' ) ) { return array(); }
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		$sql = 'SELECT p.* FROM ' . Schema::table( 'payment_confirmations' ) . ' p INNER JOIN ' . Schema::table( 'sales' ) . ' s ON s.id=p.sale_id INNER JOIN ' . Schema::table( 'vehicles' ) . ' v ON v.id=s.vehicle_id WHERE ' . $scope . ' ORDER BY p.id DESC LIMIT 100';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}
}
