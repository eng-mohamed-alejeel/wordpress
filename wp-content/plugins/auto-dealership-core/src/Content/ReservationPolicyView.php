<?php
namespace AutoDealership\Content;

use AutoDealership\Pricing\Money;

defined( 'ABSPATH' ) || exit;

/** Public reservation terms derive duration and deposit from the operational settings. */
final class ReservationPolicyView {
	public static function boot(): void { add_shortcode( 'adc_reservation_policy', array( self::class, 'render' ) ); }
	public static function render(): string {
		$en = 'en' === \AutoDealership\Core\Localization::language();
		$hours = min( 168, max( 1, absint( get_option( 'adc_reservation_hours', 24 ) ) ) );
		$type = (string) get_option( 'adc_reservation_deposit_type', 'none' );
		$value = Money::parse( get_option( 'adc_reservation_deposit_value', 0 ) );
		$text = $en ? sprintf( 'A confirmed commercial reservation lasts up to %d hours from confirmation.', $hours ) : sprintf( 'مدة الحجز التجاري المؤكد لا تتجاوز %d ساعة من وقت التأكيد.', $hours );
		if ( 'fixed' === $type && null !== $value ) { $text .= ' ' . ( $en ? 'The required deposit is ' : 'العربون المطلوب ' ) . Money::display( $value ) . '.'; }
		elseif ( 'percentage' === $type && null !== $value && $value <= 10000 ) { $text .= ' ' . ( $en ? 'The required deposit is ' : 'العربون المطلوب بنسبة ' ) . Money::decimal( $value ) . ( $en ? '% of the vehicle price; the exact amount is shown before payment.' : '% من سعر المركبة، ويُوضح المبلغ قبل الدفع.' ); }
		else { $text .= ' ' . ( $en ? 'Any payment terms are documented before confirmation.' : 'تُوثق شروط أي دفعة قبل التأكيد.' ); }
		$text .= ' ' . ( $en ? 'Cancellation and refund eligibility, the refundable amount, payment method and processing deadline are documented in the reservation agreement. Keep the reservation number and payment receipt; contact the dealership to track the recorded refund status.' : 'تُوثق شروط الإلغاء واستحقاق الاسترداد والمبلغ وطريقة الدفع والمهلة في اتفاق الحجز. احتفظ برقم الحجز وإيصال الدفع وتواصل مع المعرض لمتابعة حالة الاسترداد المسجلة.' );
		$refund_hours = min( 720, max( 1, absint( get_option( 'adc_reservation_refund_hours', 72 ) ) ) );
		$text .= ' ' . ( $en ? sprintf( 'The processing target for an eligible deposit refund is %d hours from cancellation or expiry. Refund completion requires recorded financial verification.', $refund_hours ) : sprintf( 'مهلة معالجة استرداد العربون المستحق %d ساعة من الإلغاء أو الانتهاء. يُثبت اكتمال الاسترداد بعد التحقق المالي المسجل.', $refund_hours ) );
		return '<p class="adc-reservation-policy">' . esc_html( $text ) . '</p>';
	}
}
