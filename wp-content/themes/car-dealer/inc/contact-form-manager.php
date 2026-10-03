<?php
/** Presentation adapters for contact, newsletter and test-drive forms. */
defined( 'ABSPATH' ) || exit;

function car_dealer_intake_available(): bool {
 return function_exists( 'adc_core_owns_public_intake_actions' ) && adc_core_owns_public_intake_actions();
}
function car_dealer_form_unavailable(): string {
 return '<p class="cd-form-status" role="status">' . esc_html__( 'إرسال الطلبات غير متاح حاليًا. يرجى المحاولة لاحقًا.', 'car-dealer' ) . '</p>';
}
function car_dealer_customer_form_fields( $email_only = false, $model = array() ) {
	$has_model = is_array( $model ) && array_key_exists( 'authenticated', $model );
	$authenticated = $has_model ? ! empty( $model['authenticated'] ) : is_user_logged_in();
	$identity = $has_model
		? array( 'name' => (string) ( $model['name'] ?? '' ), 'email' => (string) ( $model['email'] ?? '' ), 'phone' => (string) ( $model['phone'] ?? '' ) )
		: ( $authenticated ? array( 'name' => wp_get_current_user()->display_name, 'email' => wp_get_current_user()->user_email, 'phone' => (string) get_user_meta( get_current_user_id(), 'car_dealer_phone', true ) ) : array( 'name' => '', 'email' => '', 'phone' => '' ) );
 $html = '';
 foreach ( array( 'name' => array( __( 'الاسم', 'car-dealer' ), 'text', 'name' ), 'email' => array( __( 'البريد الإلكتروني', 'car-dealer' ), 'email', 'email' ), 'phone' => array( __( 'الهاتف', 'car-dealer' ), 'tel', 'tel' ) ) as $key => $field ) {
  if ( $email_only && 'email' !== $key ) { continue; }
  $html .= '<label>' . esc_html( $field[0] ) . '<input name="' . esc_attr( $key ) . '" type="' . esc_attr( $field[1] ) . '"' . ( 'name' !== $key ? ' dir="ltr"' : '' ) . ' autocomplete="' . esc_attr( $field[2] ) . '" value="' . esc_attr( $identity[$key] ) . '" required' . ( $authenticated ? ' readonly' : '' ) . '></label>';
 }
 if ( $authenticated ) {
	$account_url = $has_model ? (string) ( $model['account_url'] ?? home_url( '/' ) ) : car_dealer_account_url();
  $html .= '<p class="cd-profile-form-note">' . esc_html( car_dealer_text( 'تُرسل بيانات حسابك تلقائياً.', 'Your account details are included automatically.' ) ) . ' <a href="' . esc_url( car_dealer_catalog_localized_url( $account_url ) ) . '">' . esc_html( car_dealer_text( 'تحديث بياناتي', 'Update my details' ) ) . ( ! $email_only && ! $identity['phone'] ? esc_html( car_dealer_text( ' وإضافة رقم الهاتف', ' and add a phone number' ) ) : '' ) . '</a></p>';
 }
 if ( ! $email_only ) {
  $html .= '<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" hidden>';
 }
 return $html;
}
function car_dealer_contact_form_shortcode( $model = array() ) {
	if ( ! car_dealer_intake_available() ) { return car_dealer_form_unavailable(); }
	$language = is_array( $model ) && 'en' === ( $model['language'] ?? '' ) ? 'en' : ( function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar' );
 $message_label = car_dealer_text( 'رسالتك', 'Your message' );
 return '<form method="post" class="cd-ajax-form cd-contact-form" data-action="car_dealer_contact"><input type="hidden" name="lang" value="' . esc_attr( $language ) . '">' . car_dealer_customer_form_fields( false, $model ) . '<textarea name="message" required aria-label="' . esc_attr( $message_label ) . '" placeholder="' . esc_attr( $message_label ) . '"></textarea><button class="btn btn-primary" type="submit">' . esc_html( car_dealer_text( 'إرسال', 'Send' ) ) . '</button><p class="cd-form-status" role="status"></p></form>';
}
car_dealer_register_shortcode_adapter( 'car_dealer_contact_form', 'car_dealer_contact_form_shortcode', false, true );
function car_dealer_render_lead_form( $car_id, $type, $title = '', $button = '' ) {
	if ( ! car_dealer_intake_available() ) { echo car_dealer_form_unavailable(); return; }
	$car_id = absint( $car_id ); $type = sanitize_key( $type ); $title = sanitize_text_field( $title ); $button = sanitize_text_field( $button );
		$language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar';
		echo '<form method="post" class="cd-ajax-form cd-lead-form" data-action="car_dealer_contact"><h2>' . esc_html( $title ) . '</h2><input type="hidden" name="car_id" value="' . absint( $car_id ) . '"><input type="hidden" name="lead_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="lang" value="' . esc_attr( $language ) . '">' . car_dealer_customer_form_fields() . '<textarea name="message" aria-label="' . esc_attr__( 'ملاحظات إضافية', 'car-dealer' ) . '" placeholder="' . esc_attr__( 'ملاحظات إضافية', 'car-dealer' ) . '"></textarea><button class="btn btn-primary" type="submit">' . esc_html( $button ) . '</button><p class="cd-form-status" role="status"></p></form>';
}
function car_dealer_render_booking_form( $car_id ) {
	if ( ! car_dealer_intake_available() ) { echo car_dealer_form_unavailable(); return; }
	$car_id = absint( $car_id );
		$language = function_exists( 'car_dealer_catalog_language' ) ? car_dealer_catalog_language() : 'ar';
		echo '<form method="post" class="cd-ajax-form cd-booking-form" data-action="car_dealer_booking"><h2>' . esc_html__( 'احجز تجربة قيادة', 'car-dealer' ) . '</h2><input type="hidden" name="car_id" value="' . absint( $car_id ) . '"><input type="hidden" name="lang" value="' . esc_attr( $language ) . '">' . car_dealer_customer_form_fields() . '<label>' . esc_html__( 'اليوم', 'car-dealer' ) . '<input name="date" type="date" min="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label><label>' . esc_html__( 'الوقت', 'car-dealer' ) . '<input name="time" type="time" required></label><button class="btn btn-primary" type="submit">' . esc_html__( 'إرسال الطلب', 'car-dealer' ) . '</button><p class="cd-form-status" role="status"></p></form>';
}
if ( ! function_exists( 'car_dealer_newsletter_form' ) ) {
	function car_dealer_newsletter_form() {
		if ( ! function_exists( 'adc_core_owns_marketing_subscription_actions' ) || ! adc_core_owns_marketing_subscription_actions() ) { echo car_dealer_form_unavailable(); return; }
		echo '<form method="post" class="cd-ajax-form cd-newsletter-form" data-action="car_dealer_subscribe">' . car_dealer_customer_form_fields( true ) . '<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" hidden><label class="cd-newsletter-consent"><input type="checkbox" name="consent_marketing" value="1" required> ' . esc_html__( 'أوافق على استلام رسائل وتسويق المعرض ويمكنني إلغاء الاشتراك لاحقًا.', 'car-dealer' ) . '</label><button class="btn btn-primary" type="submit">' . esc_html( car_dealer_text( 'اشترك', 'Subscribe' ) ) . '</button><p class="cd-form-status" role="status"></p></form>';
	}
}
