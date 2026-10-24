<?php
namespace AutoDealership\Admin;

use AutoDealership\Marketing\AffiliateCommissionService;
use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

final class AffiliateCommissionPage {
	public static function boot(): void {
		add_action( 'admin_post_adc_affiliate_commission_rate', array( self::class, 'save_rate' ) );
		add_action( 'admin_post_adc_affiliate_commission_transition', array( self::class, 'transition' ) );
	}

	private static function text( string $ar, string $en ): string {
		return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar;
	}

	public static function render(): void {
		$can_manage = current_user_can( 'adc_manage_affiliate_commissions' ) || current_user_can( 'manage_options' );
		$can_view_own = current_user_can( 'adc_view_own_affiliate_commissions' );
		if ( ! $can_manage && ! $can_view_own ) { wp_die( esc_html__( 'لا تملك صلاحية عرض العمولات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		echo '<div class="wrap" dir="' . ( 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl' ) . '"><h1>' . esc_html( self::text( 'صفقات المسوقين بالعمولة', 'Affiliate sales and commissions' ) ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( self::text( 'تم حفظ العملية.', 'Operation saved.' ) ) . '</p></div>'; }
		if ( $can_manage ) { self::render_management(); }
		if ( $can_view_own ) { self::render_own_sales(); }
		echo '</div>';
	}

	private static function render_management(): void {
		$amount = AffiliateCommissionService::configured_amount();
		echo '<h2>' . esc_html( self::text( 'إعداد ومراجعة المالية', 'Finance configuration and review' ) ) . '</h2>';
		echo '<p>' . esc_html( self::text( 'قيمة ثابتة لكل صفقة مكتملة. تغيير القيمة لا يؤثر على العروض التي أُنشئت سابقًا. القيمة صفر توقف ربط إحالات جديدة.', 'A fixed amount per completed sale. Changes do not affect existing quotations. Zero disables new referral attribution.' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_affiliate_commission_rate' );
		echo '<input type="hidden" name="action" value="adc_affiliate_commission_rate"><input type="hidden" name="adc_money_unit" value="SAR"><label>' . esc_html( self::text( 'العمولة الثابتة (ريال)', 'Fixed commission (SAR)' ) ) . ' <input type="number" min="0" step="0.01" name="amount" value="' . esc_attr( Money::decimal( $amount ) ) . '" required></label> <button class="button button-primary">' . esc_html( self::text( 'حفظ الإعداد', 'Save setting' ) ) . '</button></form>';
		$rows = AffiliateCommissionService::all_commissions();
		if ( is_wp_error( $rows ) ) { wp_die( esc_html( $rows->get_error_message() ), '', array( 'response'=>500 ) ); }
		echo '<h3>' . esc_html( self::text( 'سجل الاستحقاقات', 'Commission ledger' ) ) . '</h3>';
		if ( ! $rows ) { echo '<p>' . esc_html( self::text( 'لا توجد استحقاقات حتى الآن.', 'No commission records yet.' ) ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'المسوّق', 'Affiliate' ) ) . '</th><th>' . esc_html( self::text( 'الصفقة', 'Sale' ) ) . '</th><th>' . esc_html( self::text( 'الإحالة', 'Lead' ) ) . '</th><th>' . esc_html( self::text( 'الفرع', 'Branch' ) ) . '</th><th>' . esc_html( self::text( 'العمولة', 'Commission' ) ) . '</th><th>' . esc_html( self::text( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( self::text( 'الإجراء', 'Action' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['display_name'] ?: ( '#' . $row['affiliate_user_id'] ) ) . '</td><td>#' . absint( $row['sale_id'] ) . ' / ' . esc_html( $row['quotation_id'] ) . '</td><td>#' . absint( $row['lead_id'] ) . '</td><td>#' . absint( $row['branch_id'] ) . '</td><td>' . esc_html( Money::display( (int) $row['amount'] ) ) . '</td><td>' . esc_html( self::status_label( $row['status'] ) ) . ( $row['payment_reference'] ? '<br>' . esc_html( $row['payment_reference'] ) : '' ) . '</td><td>';
			if ( 'pending_review' === $row['status'] ) {
				self::action_form( (int) $row['id'], 'approved', self::text( 'اعتماد للاستحقاق', 'Approve as payable' ) );
				self::action_form( (int) $row['id'], 'void', self::text( 'رفض', 'Void' ), true );
			} elseif ( 'approved' === $row['status'] ) {
				self::action_form( (int) $row['id'], 'paid', self::text( 'تسجيل الصرف', 'Record payout' ), false, true );
				self::action_form( (int) $row['id'], 'void', self::text( 'إلغاء الاستحقاق', 'Void payable' ), true );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_own_sales(): void {
		$rows = AffiliateCommissionService::own_sales();
		if ( is_wp_error( $rows ) ) { wp_die( esc_html( $rows->get_error_message() ), '', array( 'response'=>500 ) ); }
		echo '<h2>' . esc_html( self::text( 'صفقاتي المحالة', 'My referred sales' ) ) . '</h2>';
		echo '<p>' . esc_html( self::text( 'تُنشأ مطالبة العمولة بعد إتمام التسليم والسداد الكامل، ولا يستطيع المسوّق اعتمادها أو تسجيل صرفها.', 'A commission claim is created only after delivery and full settlement. Affiliates cannot approve or record their own payouts.' ) ) . '</p>';
		if ( ! $rows ) { echo '<p>' . esc_html( self::text( 'لا توجد صفقات مرتبطة بإحالاتك بعد.', 'No sales linked to your referrals yet.' ) ) . '</p>'; return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'عرض السعر', 'Quotation' ) ) . '</th><th>' . esc_html( self::text( 'حالة البيع', 'Sale status' ) ) . '</th><th>' . esc_html( self::text( 'العمولة', 'Commission' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$sale = $row['sale_id'] ? '#' . absint( $row['sale_id'] ) . ' / ' . self::status_label( $row['sale_status'] ) : self::text( 'لم تبدأ صفقة بعد', 'No sale started' );
			$commission = $row['commission_status'] ? self::status_label( $row['commission_status'] ) . ( null !== $row['commission_amount'] ? ' / ' . Money::display( (int) $row['commission_amount'] ) : '' ) : self::text( 'قيد متابعة البيع', 'Sale in progress' );
			echo '<tr><td>' . esc_html( $row['quote_number'] ) . '</td><td>' . esc_html( $sale ) . '</td><td>' . esc_html( $commission ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function action_form( int $id, string $target, string $label, bool $reason = false, bool $reference = false ): void {
		echo '<form style="display:inline-block;margin:2px" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adc_affiliate_commission_' . $target . '_' . $id );
		echo '<input type="hidden" name="action" value="adc_affiliate_commission_transition"><input type="hidden" name="commission_id" value="' . $id . '"><input type="hidden" name="target" value="' . esc_attr( $target ) . '">';
		if ( $reason ) { echo '<input type="text" name="reason" maxlength="1000" placeholder="' . esc_attr( self::text( 'سبب القرار مطلوب', 'Reason required' ) ) . '" required>'; }
		if ( $reference ) { echo '<input type="text" name="payment_reference" maxlength="190" placeholder="' . esc_attr( self::text( 'مرجع الصرف', 'Payout reference' ) ) . '" required>'; }
		echo '<button class="button">' . esc_html( $label ) . '</button></form> ';
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'pending_review'=>self::text( 'بانتظار المراجعة', 'Pending review' ),
			'approved'=>self::text( 'مستحقة للصرف', 'Approved for payout' ),
			'paid'=>self::text( 'مصروفة', 'Paid' ),
			'paid_sale_returned'=>self::text( 'مصروفة؛ البيع مرتجع ويتطلب مراجعة مالية', 'Paid; sale returned, financial review required' ),
			'void'=>self::text( 'ملغاة', 'Voided' ),
			'delivered'=>self::text( 'تم التسليم', 'Delivered' ),
		);
		return $labels[$status] ?? $status;
	}

	public static function save_rate(): void {
		check_admin_referer( 'adc_affiliate_commission_rate' );
		Money::require_sar_form();
		$amount = Money::from_sar( wp_unslash( $_POST['amount'] ?? '' ) );
		if ( null === $amount ) { wp_die( esc_html__( 'أدخل قيمة ريال صحيحة بمنزلتين عشريتين كحد أقصى.', 'auto-dealership-core' ), '', array( 'response'=>400 ) ); }
		$result = AffiliateCommissionService::set_configured_amount( $amount );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ) ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-affiliate-commissions', 'saved'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function transition(): void {
		$id = absint( $_POST['commission_id'] ?? 0 );
		$target = sanitize_key( wp_unslash( $_POST['target'] ?? '' ) );
		check_admin_referer( 'adc_affiliate_commission_' . $target . '_' . $id );
		$result = AffiliateCommissionService::transition( $id, $target, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['payment_reference'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 400 ) ) ); }
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-affiliate-commissions', 'saved'=>1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
