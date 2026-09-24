<?php
/**
 * معالج نماذج الاتصال لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

// إضافة AJAX handler لإرسال نموذج الاتصال
add_action( 'wp_ajax_send_contact_form', 'car_dealer_send_contact_form' );
add_action( 'wp_ajax_nopriv_send_contact_form', 'car_dealer_send_contact_form' );

/**
 * إرسال نموذج الاتصال
 */
function car_dealer_send_contact_form() {
	// التحقق من nonce
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'contact_form_nonce' ) ) {
		wp_send_json_error( array( 'message' => 'فشل التحقق من الأمان' ) );
	}

	// التحقق من البيانات المرسلة
	if ( empty( $_POST['name'] ) || empty( $_POST['email'] ) || empty( $_POST['phone'] ) || empty( $_POST['message'] ) ) {
		wp_send_json_error( array( 'message' => 'يرجى ملء جميع الحقول' ) );
	}

	// الحصول على البيانات
	$name = sanitize_text_field( $_POST['name'] );
	$email = sanitize_email( $_POST['email'] );
	$phone = sanitize_text_field( $_POST['phone'] );
	$message = sanitize_textarea_field( $_POST['message'] );

	// إعداد البريد الإلكتروني
	$to = get_option( 'admin_email' );
	$subject = 'رسالة جديدة من معرض السيارات';

	// محتوى البريد الإلكتروني
	$email_body = sprintf(
		"تم استلام رسالة جديدة من معرض السيارات:


" .
		"الاسم: %s
" .
		"البريد الإلكتروني: %s
" .
		"رقم الهاتف: %s

" .
		"الرسالة:
%s",
		$name,
		$email,
		$phone,
		$message
	);

	// إرسال البريد الإلكتروني
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	if ( wp_mail( $to, $subject, nl2br( $email_body ), $headers ) ) {
		wp_send_json_success( array( 'message' => 'تم إرسال رسالتك بنجاح. سنتواصل معك قريباً.' ) );
	} else {
		wp_send_json_error( array( 'message' => 'حدث خطأ أثناء إرسال الرسالة. يرجى المحاولة مرة أخرى.' ) );
	}
}
