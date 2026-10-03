<?php
/** Front-end authentication and capability-aware account workspace. */
defined( 'ABSPATH' ) || exit;
function car_dealer_account_url( $view = 'dashboard' ) { return car_dealer_catalog_localized_url( add_query_arg( 'cd_account', $view, home_url( '/' ) ) ); }
add_filter( 'adc_customer_account_url', function ( $url, $fragment = '' ) {
	$url = car_dealer_account_url();
	return $fragment ? $url . '#' . sanitize_html_class( $fragment ) : $url;
}, 10, 2 );
function car_dealer_account_view() { return isset( $_GET['cd_account'] ) && is_string( $_GET['cd_account'] ) ? sanitize_key( $_GET['cd_account'] ) : ''; }
function car_dealer_account_field( string $name ) { return isset( $_POST[$name] ) && is_string( $_POST[$name] ) ? wp_unslash( $_POST[$name] ) : ''; }
function car_dealer_account_kind( WP_User $user ) {
 return function_exists( 'adc_customer_account_kind' ) ? adc_customer_account_kind( $user ) : 'customer';
}
function car_dealer_account_links() {
  if ( is_user_logged_in() ) {
   return '<li class="menu-item cd-account-link"><a href="' . esc_url( car_dealer_account_url() ) . '">' . esc_html( car_dealer_text( 'حسابي', 'My account' ) ) . '</a></li><li class="menu-item cd-logout-link"><a href="' . esc_url( wp_logout_url( car_dealer_site_url() ) ) . '">' . esc_html( car_dealer_text( 'تسجيل الخروج', 'Log out' ) ) . '</a></li>';
  }
  return '<li class="menu-item cd-account-link"><a href="' . esc_url( car_dealer_account_url( 'login' ) ) . '">' . esc_html( car_dealer_text( 'تسجيل الدخول', 'Log in' ) ) . '</a></li>';
}
add_filter( 'wp_nav_menu_items', function ( $items, $args ) {
  if ( 'primary' === $args->theme_location ) {
    $home_link = '<li class="menu-item menu-item-home"><a href="' . esc_url( car_dealer_site_url() ) . '">' . esc_html( car_dealer_text( 'الرئيسية', 'Home' ) ) . '</a></li>';
    if ( false === strpos( $items, 'menu-item-home' ) ) {
      $items = $home_link . $items;
    }
    return $items . car_dealer_account_links();
  }
  return $items;
}, 20, 2 );
function car_dealer_account_menu_fallback() {
  $catalog_url = car_dealer_archive_url( 'car' );
  echo '<ul id="primary-menu"><li><a href="' . esc_url( car_dealer_site_url() ) . '">' . esc_html( car_dealer_text( 'الرئيسية', 'Home' ) ) . '</a></li>';
  if ( $catalog_url ) { echo '<li><a href="' . esc_url( $catalog_url ) . '">' . esc_html( car_dealer_text( 'السيارات', 'Vehicles' ) ) . '</a></li>'; }
  echo car_dealer_account_links() . '</ul>';
}
add_action( 'wp_enqueue_scripts', function () { wp_enqueue_style( 'car-dealer-account', get_template_directory_uri() . '/assets/css/account.css', array( 'car-dealer-main' ), filemtime( __DIR__ . '/../assets/css/account.css' ) ); } );
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
 return $user instanceof WP_User ? car_dealer_account_url() : $redirect;
}, 10, 3 );
add_filter( 'show_admin_bar', function ( $show ) { return is_user_logged_in() && 'customer' === car_dealer_account_kind( wp_get_current_user() ) ? false : $show; } );
add_action( 'admin_init', function () {
 if ( ! is_user_logged_in() || wp_doing_ajax() || ( $GLOBALS['pagenow'] ?? '' ) === 'admin-post.php' ) { return; }
 $user = wp_get_current_user();
 $redirect = function_exists( 'adc_customer_account_should_redirect_admin' ) && adc_customer_account_should_redirect_admin( $user );
 if ( $redirect ) { wp_safe_redirect( car_dealer_account_url() ); exit; }
} );

function car_dealer_account_process( string $view ) {
 return function_exists( 'adc_core_owns_customer_account_actions' ) && adc_core_owns_customer_account_actions() && function_exists( 'adc_customer_account_process' )
  ? adc_customer_account_process( $view )
  : __( 'خدمة الحساب غير متاحة حاليًا. يرجى المحاولة لاحقًا.', 'car-dealer' );
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

function car_dealer_account_request_table( string $type ): void { car_dealer_account_request_table_core( $type ); }

/** The plugin supplies account-scoped rows and action eligibility; this theme renders them. */
function car_dealer_account_request_table_core( string $type ): void {
 if ( ! function_exists( 'adc_customer_request_page' ) || ! function_exists( 'adc_core_owns_customer_request_view' ) || ! adc_core_owns_customer_request_view() ) {
  echo '<section class="cd-account-panel"><h2>' . esc_html__( 'طلباتي', 'car-dealer' ) . '</h2><p role="status">' . esc_html__( 'سجل الطلبات غير متاح حاليًا.', 'car-dealer' ) . '</p></section>';
  return;
 }
 $bookings = 'bookings' === $type;
 $param = $bookings ? 'booking_page' : 'message_page';
 $page = max( 1, absint( $_GET[ $param ] ?? 1 ) );
 $result = adc_customer_request_page( $type, $page );
 echo '<section class="cd-account-panel" id="' . ( $bookings ? 'customer-bookings' : 'customer-messages' ) . '"><h2>' . esc_html( $bookings ? 'حجوزات تجربة القيادة' : 'طلباتي ورسائلي' ) . '</h2>';
 if ( is_wp_error( $result ) ) {
  echo '<p role="alert">' . esc_html( $result->get_error_message() ) . '</p></section>';
  return;
 }
 $items = $result['items'];
 if ( ! $items ) {
  echo '<p>' . esc_html__( 'لا توجد طلبات في هذه الصفحة حاليًا.', 'car-dealer' ) . '</p>';
 } else {
  echo '<p class="cd-account-table-hint">' . esc_html__( 'مرّر الجدول أفقيًا لعرض بقية التفاصيل.', 'car-dealer' ) . '</p>';
  echo '<div class="cd-account-table" role="region" tabindex="0" aria-label="' . esc_attr( $bookings ? 'جدول حجوزات تجربة القيادة' : 'جدول الطلبات والرسائل' ) . '"><table><thead><tr><th scope="col">الطلب</th><th scope="col">السيارة</th><th scope="col">التاريخ</th><th scope="col">الحالة</th></tr></thead><tbody>';
  foreach ( $items as $item ) {
   echo '<tr><td>#' . absint( $item['id'] ) . '<br>';
   echo $bookings ? esc_html( trim( $item['requested_date'] . ' ' . $item['requested_time'] ) ) : nl2br( esc_html( $item['message'] ) );
   echo '</td><td>';
   if ( $item['car_url'] ) {
    echo '<a href="' . esc_url( car_dealer_catalog_localized_url( $item['car_url'] ) ) . '">' . esc_html( $item['car_title'] ) . '</a>';
   } else {
    echo esc_html( $item['car_id'] ? 'السيارة غير متاحة حاليًا' : 'طلب عام' );
   }
   echo '</td><td>' . esc_html( $item['created_at'] ) . '</td><td>' . esc_html( $item['status_label'] );
   if ( $item['customer_reply'] ) {
    echo '<p><strong>رد المعرض:</strong><br>' . nl2br( esc_html( $item['customer_reply'] ) ) . '</p>';
   }
   if ( $item['updated_at'] ) {
    echo '<small>آخر تحديث: ' . esc_html( $item['updated_at'] ) . '</small>';
   }
   if ( $item['can_cancel'] ) {
    echo '<form method="post" action="' . esc_url( car_dealer_account_url() ) . '">';
    wp_nonce_field( 'cd_account_dashboard' );
    echo '<input type="hidden" name="account_action" value="cancel_booking"><input type="hidden" name="request_id" value="' . absint( $item['id'] ) . '"><button class="btn" type="submit">إلغاء الحجز</button></form>';
   }
   echo '</td></tr>';
  }
  echo '</tbody></table></div>';
 }
 echo '<div class="cd-account-actions">';
 if ( $result['page'] > 1 ) {
  echo '<a href="' . esc_url( add_query_arg( $param, $result['page'] - 1, car_dealer_account_url() ) ) . '">السابق</a>';
 }
 if ( $result['has_more'] ) {
  echo '<a href="' . esc_url( add_query_arg( $param, $result['page'] + 1, car_dealer_account_url() ) ) . '">التالي</a>';
 }
 echo '</div></section>';
}
