<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Payments\PaymentService;
use AutoDealership\Reservations\ReservationService;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

final class PaymentPages {
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void {
			add_submenu_page( 'adc-finance', __( 'تأكيدات السداد', 'auto-dealership-core' ), __( 'تأكيدات السداد', 'auto-dealership-core' ), 'adc_view_finance', 'adc-payments', array( self::class, 'render' ) );
		} );
		add_action( 'admin_post_adc_record_payment', array( self::class, 'record' ) );
		add_action( 'admin_post_adc_decide_payment', array( self::class, 'decide' ) );
		add_action( 'admin_post_adc_record_reservation_deposit', array( self::class, 'record_deposit' ) );
		add_action( 'admin_post_adc_decide_reservation_deposit', array( self::class, 'decide_deposit' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_finance' ) ) { wp_die( esc_html__( 'لا تملك صلاحية الوصول.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		global $wpdb;
		list( $scope, $args ) = BranchScope::predicate( 'v.branch_id' );
		$sql = 'SELECT s.id,q.final_amount,v.brand,v.model FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . ' q ON q.id=s.quotation_id INNER JOIN ' . Schema::table( 'vehicles' ) . " v ON v.id=s.vehicle_id WHERE s.status IN ('pending_approval','approved') AND " . $scope . ' ORDER BY s.id DESC LIMIT 100';
		$sales = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
		$payments = PaymentService::list_for_current_user();
		$deposit_sql = 'SELECT r.id,r.deposit_required_amount,v.stock_number,v.brand,v.model FROM ' . Schema::table( 'reservations' ) . ' r INNER JOIN ' . Schema::table( 'vehicles' ) . " v ON v.id=r.vehicle_id WHERE r.status='confirmed' AND r.expires_at>%s AND r.deposit_required_amount>0 AND r.deposit_amount=0 AND NOT EXISTS (SELECT d.id FROM " . Schema::table( 'reservation_deposits' ) . " d WHERE d.reservation_id=r.id AND d.status IN ('pending','verified')) AND " . $scope . ' ORDER BY r.id DESC LIMIT 100';
		$deposit_args = array_merge( array( current_time( 'mysql', true ) ), $args );
		$deposit_reservations = current_user_can( 'adc_record_payments' ) ? ( $wpdb->get_results( $wpdb->prepare( $deposit_sql, $deposit_args ), ARRAY_A ) ?: array() ) : array();
		$deposits = ReservationService::deposit_queue();
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'تأكيدات السداد', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'سجّل المبلغ المستلم ومرجع الإيصال، ثم يراجعه موظف مالي آخر بعد مطابقته مع المصدر. يلزم اعتماد كامل قيمة البيع قبل التسليم.', 'auto-dealership-core' ); ?></p>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ العملية.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'تعذر حفظ العملية. تحقق من المبلغ والمرجع والفرع والصلاحيات وفصل مهام الاعتماد.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<h2><?php esc_html_e( 'Reservation deposits', 'auto-dealership-core' ); ?></h2>
			<?php if ( current_user_can( 'adc_record_payments' ) && $deposit_reservations ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
				<input type="hidden" name="action" value="adc_record_reservation_deposit"><?php wp_nonce_field( 'adc_record_reservation_deposit' ); ?>
				<p><label><?php esc_html_e( 'Reservation', 'auto-dealership-core' ); ?> <select name="reservation_id" required><option value=""><?php esc_html_e( 'Select reservation', 'auto-dealership-core' ); ?></option><?php foreach ( $deposit_reservations as $reservation ) : ?><option value="<?php echo absint( $reservation['id'] ); ?>"><?php echo esc_html( '#' . $reservation['id'] . ' — ' . $reservation['stock_number'] . ' — ' . number_format_i18n( (int) $reservation['deposit_required_amount'] ) ); ?></option><?php endforeach; ?></select></label></p>
				<p><label><?php esc_html_e( 'Required amount (halalas)', 'auto-dealership-core' ); ?> <input name="amount" type="number" min="1" step="1" required></label></p>
				<p><label><?php esc_html_e( 'Source', 'auto-dealership-core' ); ?> <select name="source"><option value="bank_transfer">bank transfer</option><option value="cash">cash</option><option value="card">card</option><option value="finance">finance</option></select></label></p>
				<p><label><?php esc_html_e( 'External reference', 'auto-dealership-core' ); ?> <input name="reference" maxlength="100" required></label></p>
				<button class="button button-primary"><?php esc_html_e( 'Submit deposit evidence', 'auto-dealership-core' ); ?></button>
			</form><?php endif; ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Reservation', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Vehicle', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Reference', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Amount', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Status', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'Review', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $deposits as $deposit ) : ?><tr><td><?php echo absint( $deposit['reservation_id'] ); ?></td><td><?php echo esc_html( $deposit['stock_number'] ); ?></td><td><?php echo esc_html( $deposit['reference'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $deposit['amount'] ) ); ?></td><td><?php echo esc_html( $deposit['status'] ); ?></td><td><?php if ( current_user_can( 'adc_verify_payments' ) && 'pending' === $deposit['status'] && (int) $deposit['recorded_by'] !== get_current_user_id() && (int) $deposit['owner_user_id'] !== get_current_user_id() ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_decide_reservation_deposit"><input type="hidden" name="id" value="<?php echo absint( $deposit['id'] ); ?>"><?php wp_nonce_field( 'adc_decide_reservation_deposit_' . (int) $deposit['id'] ); ?><select name="decision"><option value="verify">verify</option><option value="reject">reject</option></select> <input name="reason" placeholder="Review note"> <button class="button"><?php esc_html_e( 'Save decision', 'auto-dealership-core' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $deposits ) : ?><tr><td colspan="6"><?php esc_html_e( 'No reservation deposit evidence is available in your branch scope.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
			<h2><?php esc_html_e( 'Sale receipts', 'auto-dealership-core' ); ?></h2>
			<?php if ( current_user_can( 'adc_record_payments' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="card">
				<h2><?php esc_html_e( 'تسجيل إيصال سداد', 'auto-dealership-core' ); ?></h2>
				<input type="hidden" name="action" value="adc_record_payment"><?php wp_nonce_field( 'adc_record_payment' ); ?>
				<p><label><?php esc_html_e( 'عملية البيع', 'auto-dealership-core' ); ?> <select name="sale_id" required><option value=""><?php esc_html_e( 'اختر البيع', 'auto-dealership-core' ); ?></option><?php foreach ( $sales as $sale ) : ?><option value="<?php echo absint( $sale['id'] ); ?>"><?php echo esc_html( '#' . $sale['id'] . ' — ' . $sale['brand'] . ' ' . $sale['model'] ); ?></option><?php endforeach; ?></select></label></p>
				<p><label><?php esc_html_e( 'المبلغ المستلم (هللة سعودية)', 'auto-dealership-core' ); ?> <input name="amount" type="number" min="1" step="1" required></label></p>
				<p><label><?php esc_html_e( 'نوع الإيصال', 'auto-dealership-core' ); ?> <select name="source"><option value="cash_receipt"><?php esc_html_e( 'إيصال نقدي', 'auto-dealership-core' ); ?></option><option value="bank_transfer"><?php esc_html_e( 'حوالة بنكية', 'auto-dealership-core' ); ?></option><option value="finance_disbursement"><?php esc_html_e( 'دفعة من جهة التمويل', 'auto-dealership-core' ); ?></option></select></label></p>
				<p><label><?php esc_html_e( 'مرجع الإيصال', 'auto-dealership-core' ); ?> <input name="reference" maxlength="100" required></label></p>
				<p><?php esc_html_e( 'استخدم رقم المرجع فقط. لا تدخل بيانات الحساب البنكي أو البطاقة.', 'auto-dealership-core' ); ?></p>
				<button class="button button-primary"><?php esc_html_e( 'إرسال للمراجعة', 'auto-dealership-core' ); ?></button>
			</form><?php endif; ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'البيع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المرجع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المبلغ (هللة)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المراجعة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $payments as $payment ) : ?>
			<tr><td><?php echo absint( $payment['sale_id'] ); ?></td><td><?php echo esc_html( $payment['reference'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $payment['amount'] ) ); ?></td><td><?php echo esc_html( array( 'pending' => __( 'بانتظار التحقق', 'auto-dealership-core' ), 'verified' => __( 'تم التحقق', 'auto-dealership-core' ), 'rejected' => __( 'مرفوض', 'auto-dealership-core' ) )[ $payment['status'] ] ?? $payment['status'] ); ?></td><td>
			<?php if ( current_user_can( 'adc_verify_payments' ) && 'pending' === $payment['status'] && (int) $payment['recorded_by'] !== get_current_user_id() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_decide_payment"><input type="hidden" name="id" value="<?php echo absint( $payment['id'] ); ?>"><?php wp_nonce_field( 'adc_decide_payment_' . (int) $payment['id'] ); ?><label><?php esc_html_e( 'نتيجة المراجعة', 'auto-dealership-core' ); ?> <select name="decision"><option value="verify"><?php esc_html_e( 'تمت المطابقة', 'auto-dealership-core' ); ?></option><option value="reject"><?php esc_html_e( 'رفض', 'auto-dealership-core' ); ?></option></select></label> <label><?php esc_html_e( 'ملاحظة التحقق', 'auto-dealership-core' ); ?> <input name="reason" required></label> <button class="button"><?php esc_html_e( 'حفظ القرار', 'auto-dealership-core' ); ?></button></form>
			<?php endif; ?></td></tr><?php endforeach; ?>
			<?php if ( ! $payments ) : ?><tr><td colspan="5"><?php esc_html_e( 'لا توجد تأكيدات سداد في نطاق فروعك.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	private static function input( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	public static function record(): void {
		check_admin_referer( 'adc_record_payment' );
		$sale = filter_var( self::input( 'sale_id' ), FILTER_VALIDATE_INT );
		$amount = filter_var( self::input( 'amount' ), FILTER_VALIDATE_INT );
		self::redirect( PaymentService::record( false === $sale ? 0 : $sale, false === $amount ? 0 : $amount, self::input( 'source' ), self::input( 'reference' ) ) );
	}

	public static function decide(): void {
		$id = absint( self::input( 'id' ) );
		check_admin_referer( 'adc_decide_payment_' . $id );
		$decision = self::input( 'decision' );
		if ( ! in_array( $decision, array( 'verify', 'reject' ), true ) ) { self::redirect( new \WP_Error( 'adc_invalid_decision' ) ); }
		self::redirect( PaymentService::decide( $id, 'verify' === $decision, self::input( 'reason' ) ) );
	}

	public static function record_deposit(): void {
		check_admin_referer( 'adc_record_reservation_deposit' );
		$reservation_id = filter_var( self::input( 'reservation_id' ), FILTER_VALIDATE_INT );
		$amount = filter_var( self::input( 'amount' ), FILTER_VALIDATE_INT );
		self::redirect( ReservationService::record_deposit( false === $reservation_id ? 0 : $reservation_id, false === $amount ? 0 : $amount, self::input( 'source' ), self::input( 'reference' ) ) );
	}

	public static function decide_deposit(): void {
		$id = absint( self::input( 'id' ) );
		check_admin_referer( 'adc_decide_reservation_deposit_' . $id );
		$decision = self::input( 'decision' );
		if ( ! in_array( $decision, array( 'verify', 'reject' ), true ) ) { self::redirect( new \WP_Error( 'adc_invalid_decision' ) ); }
		self::redirect( ReservationService::decide_deposit( $id, 'verify' === $decision, self::input( 'reason' ) ) );
	}

	private static function redirect( $result ): void {
		wp_safe_redirect( add_query_arg( is_wp_error( $result ) ? 'error' : 'saved', '1', admin_url( 'admin.php?page=adc-payments' ) ) );
		exit;
	}
}
