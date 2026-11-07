<?php
namespace AutoDealership\Marketing;

use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/** Localized, PII-minimized affiliate commission notification content. */
final class AffiliateCommissionNotificationTemplate {
	public static function build( array $commission, string $event, int $recipient_id ): array {
		$language = AffiliateNotificationPreferences::language( $recipient_id );
		$ar = 'ar' === $language;
		$status = (string) ( $commission['status'] ?? '' );
		$status_labels = array(
			'pending_review'=>array( 'ar'=>'بانتظار المراجعة', 'en'=>'Pending review' ),
			'approved'=>array( 'ar'=>'معتمدة للصرف', 'en'=>'Approved for payout' ),
			'paid'=>array( 'ar'=>'تم صرفها', 'en'=>'Paid' ),
			'void'=>array( 'ar'=>'ملغاة', 'en'=>'Voided' ),
			'paid_sale_returned'=>array( 'ar'=>'الصفقة مرتجعة وتتطلب تسوية مالية', 'en'=>'Sale returned; financial reconciliation required' ),
		);
		$status_label = $status_labels[ $status ][ $ar ? 'ar' : 'en' ] ?? $status;
		$amount = Money::decimal( (int) ( $commission['amount'] ?? 0 ) ) . ' SAR';
		$url = admin_url( 'admin.php?page=adc-affiliate-commissions&commission_id=' . absint( $commission['id'] ?? 0 ) );
		if ( $ar ) {
			$title = 'تحديث عمولة المسوق';
			$body = '<p>تم تحديث حالة العمولة رقم <strong>#' . absint( $commission['id'] ?? 0 ) . '</strong>.</p><p>الحالة: <strong>' . esc_html( $status_label ) . '</strong><br>المبلغ: <strong>' . esc_html( $amount ) . '</strong></p>';
			$button = 'عرض تفاصيل العمولة';
			$subject = 'تحديث عمولة المسوق #' . absint( $commission['id'] ?? 0 );
		} else {
			$title = 'Affiliate commission update';
			$body = '<p>Commission <strong>#' . absint( $commission['id'] ?? 0 ) . '</strong> has been updated.</p><p>Status: <strong>' . esc_html( $status_label ) . '</strong><br>Amount: <strong>' . esc_html( $amount ) . '</strong></p>';
			$button = 'View commission details';
			$subject = 'Affiliate commission update #' . absint( $commission['id'] ?? 0 );
		}
		return array( 'language'=>$language, 'title'=>$title, 'body'=>$body, 'button'=>$button, 'subject'=>$subject, 'url'=>$url, 'event'=>$event );
	}
}
