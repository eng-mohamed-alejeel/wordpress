<?php
/** Compatibility-only account history renderer. */
defined( 'ABSPATH' ) || exit;
/** Ownership uses authenticated user IDs, never a submitted email address. */
function car_dealer_account_requests(string $type, int $page = 1 ): array {
 global $wpdb;
 if ( ! get_current_user_id() ) { return array(); }
 $suffix = 'bookings' === $type ? 'bookings' : 'messages';
 return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}car_dealer_{$suffix} WHERE user_id = %d ORDER BY id DESC LIMIT 11 OFFSET %d", get_current_user_id(), ( max( 1, $page ) - 1 ) * 10 ) ) ?: array();
}
function car_dealer_account_request_table(string $type ) {
 $param = 'bookings' === $type ? 'booking_page' : 'message_page';
 $page = max( 1, absint( $_GET[$param] ?? 1 ) );
 $rows = car_dealer_account_requests( $type, $page ); $more = count( $rows ) > 10;
 $statuses = car_dealer_request_statuses( 'bookings' === $type ? 'booking' : 'message' );
 echo '<section class="cd-account-panel" id="' . ( 'bookings' === $type ? 'customer-bookings' : 'customer-messages' ) . '"><h2>' . ( 'bookings' === $type ? 'حجوزات تجربة القيادة' : 'طلباتي ورسائلي' ) . '</h2>';
 if ( ! $rows ) { echo '<p>لا توجد طلبات حتى الآن. ستظهر هنا الطلبات التي ترسلها أثناء تسجيل الدخول.</p>'; }
 else {
  echo '<p class="cd-account-table-hint">مرّر الجدول أفقيًا لعرض بقية التفاصيل.</p>';
  echo '<div class="cd-account-table" role="region" tabindex="0" aria-label="' . esc_attr( 'bookings' === $type ? 'جدول حجوزات تجربة القيادة' : 'جدول الطلبات والرسائل' ) . '"><table><thead><tr><th scope="col">الطلب</th><th scope="col">السيارة</th><th scope="col">التاريخ</th><th scope="col">الحالة</th></tr></thead><tbody>';
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
