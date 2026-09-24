<?php
/** Front-end authentication and capability-aware account workspace. */
defined( 'ABSPATH' ) || exit;
function car_dealer_account_url( $view = 'dashboard' ) { return add_query_arg( 'cd_account', $view, home_url( '/' ) ); }
function car_dealer_account_view() { return isset( $_GET['cd_account'] ) && is_string( $_GET['cd_account'] ) ? sanitize_key( $_GET['cd_account'] ) : ''; }
function car_dealer_account_field( $name ) { return isset( $_POST[$name] ) && is_string( $_POST[$name] ) ? wp_unslash( $_POST[$name] ) : ''; }
add_action( 'init', function () { add_role( 'car_dealer_customer', 'عميل المعرض', array( 'read' => true ) ); } );
function car_dealer_account_kind( $user ) {
 if ( user_can( $user, 'manage_options' ) ) { return 'administrator'; }
 if ( user_can( $user, 'edit_others_cars' ) && user_can( $user, 'manage_car_dealer' ) ) { return 'manager'; }
 if ( user_can( $user, 'manage_car_dealer' ) ) { return 'sales'; }
 return 'customer';
}
function car_dealer_account_links() {
 if ( is_user_logged_in() ) {
  return '<li class="menu-item cd-account-link"><a href="' . esc_url( car_dealer_account_url() ) . '">حسابي</a></li><li class="menu-item cd-account-link"><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">تسجيل الخروج</a></li>';
 }
 return '<li class="menu-item cd-account-link"><a href="' . esc_url( car_dealer_account_url( 'login' ) ) . '">تسجيل الدخول</a></li><li class="menu-item cd-account-link cd-register-link"><a href="' . esc_url( car_dealer_account_url( 'register' ) ) . '">إنشاء حساب</a></li>';
}
add_filter( 'wp_nav_menu_items', function ( $items, $args ) { return 'primary' === $args->theme_location ? $items . car_dealer_account_links() : $items; }, 20, 2 );
function car_dealer_account_menu_fallback() {
 echo '<ul id="primary-menu"><li><a href="' . esc_url( home_url( '/' ) ) . '">الرئيسية</a></li><li><a href="' . esc_url( get_post_type_archive_link( 'car' ) ) . '">السيارات</a></li>' . car_dealer_account_links() . '</ul>';
}
add_action( 'wp_enqueue_scripts', function () { wp_enqueue_style( 'car-dealer-account', get_template_directory_uri() . '/assets/css/account.css', array( 'car-dealer-enhancements' ), filemtime( __DIR__ . '/../assets/css/account.css' ) ); } );
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
 return $user instanceof WP_User ? car_dealer_account_url() : $redirect;
}, 10, 3 );
add_filter( 'show_admin_bar', function ( $show ) { return is_user_logged_in() && 'customer' === car_dealer_account_kind( wp_get_current_user() ) ? false : $show; } );
add_action( 'admin_init', function () {
 if ( ! is_user_logged_in() || wp_doing_ajax() || ( $GLOBALS['pagenow'] ?? '' ) === 'admin-post.php' ) { return; }
 $user = wp_get_current_user();
 if ( array_intersect( array( 'car_dealer_customer', 'subscriber' ), $user->roles ) && ! current_user_can( 'edit_posts' ) && ! current_user_can( 'manage_car_dealer' ) ) { wp_safe_redirect( car_dealer_account_url() ); exit; }
} );

function car_dealer_account_process( $view ) {
 if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { return ''; }
 if ( ! wp_verify_nonce( car_dealer_account_field( '_wpnonce' ), 'cd_account_' . $view ) ) { return 'انتهت صلاحية النموذج. حدّث الصفحة وحاول مجدداً.'; }
 if ( 'dashboard' === $view && is_user_logged_in() ) {
  if ( 'cancel_booking' === car_dealer_account_field( 'account_action' ) ) {
   $result = car_dealer_update_request( 'booking', absint( car_dealer_account_field( 'request_id' ) ), array(), true );
   if ( is_wp_error( $result ) ) { return $result->get_error_message(); }
   wp_safe_redirect( add_query_arg( 'request_updated', 1, car_dealer_account_url() ) ); exit;
  }
  $name = sanitize_text_field( car_dealer_account_field( 'display_name' ) );
  if ( ! $name ) { return 'يرجى إدخال الاسم.'; }
  $result = wp_update_user( array( 'ID' => get_current_user_id(), 'display_name' => $name ) );
  if ( is_wp_error( $result ) ) { return 'تعذر تحديث البيانات.'; }
  update_user_meta( get_current_user_id(), 'car_dealer_phone', sanitize_text_field( car_dealer_account_field( 'phone' ) ) );
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
  if ( ! $name || ! is_email( $email ) || strlen( $password ) < 10 || strlen( $password ) > 4096 || $password !== car_dealer_account_field( 'password_confirm' ) ) { return 'أدخل اسماً وبريداً صحيحاً وكلمة مرور من 10 أحرف على الأقل مع تأكيد مطابق.'; }
  if ( email_exists( $email ) ) { return 'تعذر استخدام هذا البريد. جرّب تسجيل الدخول أو استعادة كلمة المرور.'; }
  $id = wp_insert_user( array( 'user_login' => 'customer_' . wp_generate_password( 20, false ), 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'role' => 'car_dealer_customer' ) );
  if ( is_wp_error( $id ) ) { return 'تعذر إنشاء الحساب. حاول مجدداً أو تواصل مع المعرض.'; }
  update_user_meta( $id, 'car_dealer_phone', sanitize_text_field( car_dealer_account_field( 'phone' ) ) );
  wp_set_current_user( $id ); wp_set_auth_cookie( $id, false, is_ssl() );
  do_action( 'wp_login', get_userdata( $id )->user_login, get_userdata( $id ) );
 }
 if ( 'login' === $view ) { delete_transient( $limit_key ); }
 wp_safe_redirect( car_dealer_account_url() ); exit;
}
add_action( 'template_redirect', function () {
 $view = car_dealer_account_view();
 if ( ! $view ) { return; }
 if ( ! in_array( $view, array( 'login', 'register', 'dashboard' ), true ) ) { wp_safe_redirect( car_dealer_account_url() ); exit; }
 if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
 nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow', true );
 if ( 'dashboard' === $view && ! is_user_logged_in() ) { wp_safe_redirect( car_dealer_account_url( 'login' ) ); exit; }
 if ( 'dashboard' !== $view && is_user_logged_in() ) { wp_safe_redirect( car_dealer_account_url() ); exit; }
 $GLOBALS['cd_account_error'] = car_dealer_account_process( $view );
 status_header( 200 );
 include get_template_directory() . '/templates/account.php'; exit;
} );
add_filter( 'pre_get_document_title', function ( $title ) { return car_dealer_account_view() ? 'حسابي — ' . get_bloginfo( 'name' ) : $title; } );

/** Ownership uses authenticated user IDs, never a submitted email address. */
function car_dealer_account_requests( $type, $page = 1 ) {
 global $wpdb;
 if ( ! get_current_user_id() ) { return array(); }
 $suffix = 'bookings' === $type ? 'bookings' : 'messages';
 return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}car_dealer_{$suffix} WHERE user_id = %d ORDER BY id DESC LIMIT 11 OFFSET %d", get_current_user_id(), ( max( 1, $page ) - 1 ) * 10 ) );
}
function car_dealer_account_request_table( $type ) {
 $param = 'bookings' === $type ? 'booking_page' : 'message_page';
 $page = max( 1, absint( $_GET[$param] ?? 1 ) );
 $rows = car_dealer_account_requests( $type, $page ); $more = count( $rows ) > 10;
 $statuses = car_dealer_request_statuses( 'bookings' === $type ? 'booking' : 'message' );
 echo '<section class="cd-account-panel" id="' . ( 'bookings' === $type ? 'customer-bookings' : 'customer-messages' ) . '"><h2>' . ( 'bookings' === $type ? 'حجوزات تجربة القيادة' : 'طلباتي ورسائلي' ) . '</h2>';
 if ( ! $rows ) { echo '<p>لا توجد طلبات حتى الآن. ستظهر هنا الطلبات التي ترسلها أثناء تسجيل الدخول.</p>'; }
 else {
  echo '<div class="cd-account-table"><table><thead><tr><th>الطلب</th><th>السيارة</th><th>التاريخ</th><th>الحالة</th></tr></thead><tbody>';
  foreach ( array_slice( $rows, 0, 10 ) as $row ) {
   $car = $row->car_id && 'publish' === get_post_status( $row->car_id ) ? '<a href="' . esc_url( get_permalink( $row->car_id ) ) . '">' . esc_html( get_the_title( $row->car_id ) ) . '</a>' : esc_html( $row->car_id ? 'السيارة غير متاحة حالياً' : 'طلب عام' );
   echo '<tr><td>#' . absint( $row->id ) . ( 'bookings' === $type ? '<br>' . esc_html( $row->requested_date . ' ' . $row->requested_time ) : '<br>' . nl2br( esc_html( $row->message ) ) ) . '</td><td>' . $car . '</td><td>' . esc_html( $row->created_at ) . '</td><td>' . esc_html( $statuses[$row->status] ?? 'قيد المتابعة' );
   if ( ! empty( $row->customer_reply ) ) { echo '<p><strong>رد المعرض:</strong><br>' . nl2br( esc_html( $row->customer_reply ) ) . '</p>'; }
   if ( ! empty( $row->updated_at ) ) { echo '<small>آخر تحديث: ' . esc_html( $row->updated_at ) . '</small>'; }
   if ( 'bookings' === $type && in_array( $row->status, array( 'pending', 'confirmed' ), true ) ) {
    echo '<form method="post" action="' . esc_url( car_dealer_account_url() ) . '">'; wp_nonce_field( 'cd_account_dashboard' );
    echo '<input type="hidden" name="account_action" value="cancel_booking"><input type="hidden" name="request_id" value="' . absint( $row->id ) . '"><button class="btn" type="submit">إلغاء الحجز</button></form>';
   }
   echo '</td></tr>';
  }
  echo '</tbody></table></div>';
 }
 echo '<div class="cd-account-actions">';
 if ( $page > 1 ) { echo '<a href="' . esc_url( add_query_arg( $param, $page - 1, car_dealer_account_url() ) ) . '">السابق</a>'; }
 if ( $more ) { echo '<a href="' . esc_url( add_query_arg( $param, $page + 1, car_dealer_account_url() ) ) . '">التالي</a>'; }
 echo '</div></section>';
}
