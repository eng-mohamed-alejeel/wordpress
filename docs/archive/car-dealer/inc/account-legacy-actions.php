<?php
/** Compatibility-only account actions. */
defined( 'ABSPATH' ) || exit;
function car_dealer_account_kind_legacy( WP_User $user ): string {
 if ( user_can( $user, 'manage_options' ) ) { return 'administrator'; }
 if ( user_can( $user, 'adc_view_branch_leads' ) || ( user_can( $user, 'edit_others_cars' ) && user_can( $user, 'manage_car_dealer' ) ) ) { return 'manager'; }
 if ( user_can( $user, 'adc_view_own_leads' ) || user_can( $user, 'manage_car_dealer' ) ) { return 'sales'; }
 if ( user_can( $user, 'adc_view_workspace' ) ) { return 'staff'; }
 return 'customer';
}
function car_dealer_account_should_redirect_admin_legacy( WP_User $user ): bool {
 return (bool) array_intersect( array( 'car_dealer_customer', 'subscriber' ), (array) $user->roles )
  && ! user_can( $user, 'edit_posts' )
  && ! user_can( $user, 'manage_car_dealer' );
}
if ( ! function_exists( 'adc_core_owns_customer_account_actions' ) || ! adc_core_owns_customer_account_actions() ) {
 add_action( 'init', function () { add_role( 'car_dealer_customer', 'عميل المعرض', array( 'read' => true ) ); } );
}
function car_dealer_account_process_legacy( string $view ) {
  if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return ''; }
  if ( ! wp_verify_nonce( car_dealer_account_field( '_wpnonce' ), 'cd_account_' . $view ) ) { return 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مجدداً.'; }
  if ( 'dashboard' === $view && is_user_logged_in() ) {
	$account_action = car_dealer_account_field( 'account_action' );
   if ( 'cancel_booking' === car_dealer_account_field( 'account_action' ) ) {
    $result = car_dealer_update_request( 'booking', absint( car_dealer_account_field( 'request_id' ) ), array(), true );
    if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
    wp_safe_redirect( add_query_arg( 'request_updated', 1, car_dealer_account_url() ) ); exit;
   }
	if ( 'save_preferences' === $account_action ) {
	 if ( ! class_exists( '\\AutoDealership\\Leads\\CustomerIdentity' ) || ! in_array( car_dealer_account_field( 'consent_marketing' ), array( '0', '1' ), true ) ) { return 'تعذر حفظ التفضيلات. اختر أحد الخيارين وحاول مجدداً.'; }
	 $result = \AutoDealership\Leads\CustomerIdentity::update_preferences( '1' === car_dealer_account_field( 'consent_marketing' ) );
	 if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
	 wp_safe_redirect( add_query_arg( 'preferences_saved', 1, car_dealer_account_url() ) ); exit;
	}
   $name = sanitize_text_field( car_dealer_account_field( 'display_name' ) );
   if ( ! $name ) { return 'يرجى إدخال الاسم.'; }
	$phone = sanitize_text_field( car_dealer_account_field( 'phone' ) );
	if ( class_exists( '\\AutoDealership\\Leads\\ContactIdentity' ) ) {
	 $phone = \AutoDealership\Leads\ContactIdentity::normalize_mobile( $phone, false );
	 if ( is_wp_error( $phone ) ) { return 'أدخل رقم هاتف صحيحاً بصيغة محلية أو دولية.'; }
	}
   $result = wp_update_user( array( 'ID' => get_current_user_id(), 'display_name' => $name ) );
   if ( is_wp_error( $result ) ) { return 'تعذر تحديث البيانات.'; }
   update_user_meta( get_current_user_id(), 'car_dealer_phone', $phone );
	if ( class_exists( '\\AutoDealership\\Leads\\CustomerIdentity' ) ) {
	 $synced = \AutoDealership\Leads\CustomerIdentity::sync_account_profile( get_current_user_id() );
	 if ( is_wp_error( $synced ) ) { return 'تم تحديث الحساب، لكن تعذرت مزامنة ملف العميل. حاول الحفظ مجدداً.'; }
	}
   wp_safe_redirect( add_query_arg( 'saved', 1, car_dealer_account_url() ) ); exit;
  }
  if ( ! in_array( $view, array( 'login', 'register' ), true ) || is_user_logged_in() ) { return ''; }
  $limit_key = 'cd_auth_' . hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() );
  $attempts = (int) get_transient( $limit_key );
  if ( $attempts >= 10 ) { return 'محاولات كثيرة. يرجى المحاولة بعد 15 دقيقة.'; }
  set_transient( $limit_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
  if ( 'login' === $view ) {
   $user = wp_signon( array( 'user_login' => sanitize_text_field( car_dealer_account_field( 'login' ) ), 'user_password' => car_dealer_account_field( 'password' ), 'remember' => (bool) car_dealer_account_field( 'remember' ) ), is_ssl() );
   if ( is_wp_error( $user ) ) { return 'تعذر تسجيل الدخول. تحقق من بياناتك أو استخدم استعادة كلمة المرور.'; }
  } else {
   $name = sanitize_text_field( car_dealer_account_field( 'display_name' ) );
   $email = sanitize_email( car_dealer_account_field( 'email' ) );
   $password = car_dealer_account_field( 'password' );
   if ( car_dealer_account_field( 'company_website' ) ) { return 'تعذر إنشاء الحساب.'; }
    if ( empty( $_POST['privacy_consent'] ) ) { return 'يجب الموافقة على سياسة الخصوصية وشروط الاستخدام لإنشاء حساب.'; }
   if ( ! $name || ! is_email( $email ) || strlen( $password ) < 10 || strlen( $password ) > 4096 || $password !== car_dealer_account_field( 'password_confirm' ) ) { return 'أدخل اسماً وبريداً صحيحاً وكلمة مرور من 10 أحرف على الأقل مع تأكيد مطابق.'; }
   if ( email_exists( $email ) ) { return 'تعذر استخدام هذا البريد. جرّب تسجيل الدخول أو استعادة كلمة المرور.'; }
	$phone = sanitize_text_field( car_dealer_account_field( 'phone' ) );
	if ( class_exists( '\\AutoDealership\\Leads\\ContactIdentity' ) ) {
	 $phone = \AutoDealership\Leads\ContactIdentity::normalize_mobile( $phone, false );
	 if ( is_wp_error( $phone ) ) { return 'أدخل رقم هاتف صحيحاً بصيغة محلية أو دولية.'; }
	}
	$id = wp_insert_user( array( 'user_login' => 'customer_' . wp_generate_password( 20, false ), 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'role' => 'car_dealer_customer' ) );
   if ( is_wp_error( $id ) ) { return 'تعذر إنشاء الحساب. حاول مجدداً أو تواصل مع المعرض.'; }
	update_user_meta( $id, 'car_dealer_phone', $phone );
   wp_set_current_user( $id ); wp_set_auth_cookie( $id, false, is_ssl() );
   do_action( 'wp_login', get_userdata( $id )->user_login, get_userdata( $id ) );
  }
  if ( 'login' === $view ) { delete_transient( $limit_key ); }
  wp_safe_redirect( car_dealer_account_url() ); exit;
}
