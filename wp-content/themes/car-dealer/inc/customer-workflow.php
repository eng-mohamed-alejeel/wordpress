<?php
/** Shared customer/request relationships and updates for both workspaces. */
defined( 'ABSPATH' ) || exit;
function car_dealer_request_statuses( $type ) {
 return 'booking' === $type ? array( 'pending' => 'قيد الانتظار', 'confirmed' => 'مؤكد', 'completed' => 'مكتمل', 'cancelled' => 'ملغى' ) : array( 'new' => 'جديد', 'read' => 'قيد المتابعة', 'completed' => 'مكتمل', 'cancelled' => 'ملغى' );
}
function car_dealer_request_table( $type ) {
 global $wpdb;
 return in_array( $type, array( 'message', 'booking' ), true ) ? $wpdb->prefix . 'car_dealer_' . ( 'booking' === $type ? 'bookings' : 'messages' ) : false;
}
function car_dealer_customer_crm( $user_id, $create = true ) {
 $user = get_userdata( $user_id );
 if ( ! $user || 'customer' !== car_dealer_account_kind( $user ) ) { return 0; }
 $ids = get_posts( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'meta_key' => '_crm_user_id', 'meta_value' => $user_id, 'numberposts' => 1, 'fields' => 'ids' ) );
 if ( $ids ) { return $ids[0]; }
 if ( ! $create ) { return 0; }
 $id = wp_insert_post( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'post_title' => $user->display_name ), true );
 if ( is_wp_error( $id ) ) { return 0; }
 foreach ( array( 'user_id' => $user_id, 'email' => $user->user_email, 'phone' => get_user_meta( $user_id, 'car_dealer_phone', true ), 'stage' => 'new', 'source' => 'حساب العميل' ) as $key => $value ) { update_post_meta( $id, '_crm_' . $key, $value ); }
 car_dealer_crm_log( $id, 'ربط ملف العميل بالحساب #' . $user_id );
 return $id;
}
function car_dealer_sync_customer_profile( $user_id ) {
 $id = car_dealer_customer_crm( $user_id );
 if ( ! $id ) { return; }
 $user = get_userdata( $user_id );
 wp_update_post( array( 'ID' => $id, 'post_title' => $user->display_name ) );
 update_post_meta( $id, '_crm_email', $user->user_email );
 update_post_meta( $id, '_crm_phone', get_user_meta( $user_id, 'car_dealer_phone', true ) );
}
add_action( 'profile_update', 'car_dealer_sync_customer_profile' );
add_action( 'wp_login', function ( $login, $user ) { car_dealer_sync_customer_profile( $user->ID ); }, 10, 2 );
foreach ( array( 'added_user_meta', 'updated_user_meta' ) as $hook ) {
 add_action( $hook, function ( $meta_id, $user_id, $key ) { if ( 'car_dealer_phone' === $key ) { car_dealer_sync_customer_profile( $user_id ); } }, 10, 3 );
}
function car_dealer_request_crm( $type, $row ) {
 $ids = get_posts( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'meta_key' => '_crm_origin_' . $type . '_' . $row->id, 'meta_value' => '1', 'numberposts' => 1, 'fields' => 'ids' ) );
 return $ids ? $ids[0] : 0;
}
function car_dealer_valid_booking_date( $date, $time ) {
 $parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
 return $parsed && $parsed->format( 'Y-m-d H:i' ) === $date . ' ' . $time && $parsed->getTimestamp() > time();
}
/** A single update service enforces permissions even when called outside the UI. */
function car_dealer_update_request( $type, $id, $data, $customer = false ) {
 global $wpdb;
 $table = car_dealer_request_table( $type );
 if ( ! $table || ! is_user_logged_in() ) { return new WP_Error( 'forbidden', 'طلب غير مسموح.' ); }
 $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
 if ( ! $row ) { return new WP_Error( 'missing', 'الطلب غير موجود.' ); }
 if ( $customer ) {
  if ( 'booking' !== $type || (int) $row->user_id !== get_current_user_id() || ! in_array( $row->status, array( 'pending', 'confirmed' ), true ) ) { return new WP_Error( 'forbidden', 'لا يمكن إلغاء هذا الحجز.' ); }
  $changes = array( 'status' => 'cancelled' );
 } else {
  if ( ! current_user_can( 'manage_car_dealer' ) ) { return new WP_Error( 'forbidden', 'ليست لديك صلاحية.' ); }
  if ( ! isset( car_dealer_request_statuses( $type )[ $data['status'] ?? '' ] ) ) { return new WP_Error( 'invalid', 'حالة غير صالحة.' ); }
  $changes = array( 'status' => $data['status'], 'customer_reply' => sanitize_textarea_field( $data['customer_reply'] ?? '' ) );
  if ( 'booking' === $type ) {
   $date = sanitize_text_field( $data['requested_date'] ?? '' ); $time = sanitize_text_field( $data['requested_time'] ?? '' );
   if ( in_array( $changes['status'], array( 'pending', 'confirmed' ), true ) && ! car_dealer_valid_booking_date( $date, $time ) ) { return new WP_Error( 'date', 'حدد موعداً صحيحاً في المستقبل للحجز.' ); }
   if ( 'confirmed' === $changes['status'] && ( 'car' !== get_post_type( $row->car_id ) || 'publish' !== get_post_status( $row->car_id ) || 'sold' === get_post_meta( $row->car_id, '_car_inventory_status', true ) ) ) { return new WP_Error( 'car', 'لا يمكن تأكيد حجز سيارة غير متاحة.' ); }
   if ( $date !== (string) $row->requested_date || $time !== (string) $row->requested_time ) {
    if ( ! car_dealer_valid_booking_date( $date, $time ) ) { return new WP_Error( 'date', 'الموعد الجديد غير صالح.' ); }
    $changes['requested_date'] = $date; $changes['requested_time'] = $time;
   }
  }
 }
 $changes['updated_at'] = current_time( 'mysql' );
 $result = $wpdb->update( $table, $changes, array( 'id' => $id, 'status' => $row->status, 'updated_at' => $row->updated_at ) );
 if ( false === $result ) { return new WP_Error( 'save', 'تعذر حفظ التحديث.' ); }
 if ( 0 === $result ) { return new WP_Error( 'stale', 'لم يتغير الطلب أو تم تحديثه للتو. حدّث الصفحة وحاول مجدداً.' ); }
 $crm = car_dealer_request_crm( $type, $row );
 if ( ! $crm ) { car_dealer_crm_capture( $type, $row ); $crm = car_dealer_request_crm( $type, $row ); }
 if ( $crm ) {
  $text = ( $customer ? 'العميل ألغى الحجز' : 'تحديث ' . ( 'booking' === $type ? 'الحجز' : 'الطلب' ) ) . ' #' . $id . ': ' . car_dealer_request_statuses( $type )[ $changes['status'] ];
  if ( ! empty( $changes['requested_date'] ) ) { $text .= "\nالموعد: " . $changes['requested_date'] . ' ' . $changes['requested_time']; }
  if ( ! empty( $changes['customer_reply'] ) ) { $text .= "\nالرد الظاهر للعميل: " . $changes['customer_reply']; }
  car_dealer_crm_log( $crm, $text );
 }
 return true;
}
add_action( 'admin_post_car_dealer_request_update', function () {
 if ( ! current_user_can( 'manage_car_dealer' ) ) { wp_die( 'ليست لديك صلاحية.', '', array( 'response' => 403 ) ); }
 check_admin_referer( 'car_dealer_request_update' );
 $type = sanitize_key( car_dealer_account_field( 'request_type' ) );
 $result = car_dealer_update_request( $type, absint( car_dealer_account_field( 'request_id' ) ), array( 'status' => car_dealer_account_field( 'status' ), 'customer_reply' => car_dealer_account_field( 'customer_reply' ), 'requested_date' => car_dealer_account_field( 'requested_date' ), 'requested_time' => car_dealer_account_field( 'requested_time' ) ) );
 if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) ); }
 wp_safe_redirect( admin_url( 'admin.php?page=car-dealer-' . ( 'booking' === $type ? 'bookings' : 'messages' ) . '&updated=1' ) ); exit;
} );
function car_dealer_request_admin_actions( $type, $row ) {
 $row = (object) $row;
 $crm = car_dealer_request_crm( $type, $row );
 if ( $crm ) { echo '<p><a class="button" href="' . esc_url( car_dealer_crm_url( array( 'customer' => $crm ) ) ) . '">ملف العميل CRM</a></p>'; }
 if ( $row->user_id ) { echo '<p>حساب العميل #' . absint( $row->user_id ) . '</p>'; }
 echo '<details><summary>تحديث الحالة والرد</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
 wp_nonce_field( 'car_dealer_request_update' );
 echo '<input type="hidden" name="action" value="car_dealer_request_update"><input type="hidden" name="request_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="request_id" value="' . absint( $row->id ) . '"><p><label>الحالة <select name="status">';
 foreach ( car_dealer_request_statuses( $type ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $row->status, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
 echo '</select></label></p>';
 if ( 'booking' === $type ) { echo '<p><label>موعد الحجز <input type="date" name="requested_date" value="' . esc_attr( $row->requested_date ) . '"></label><label>الوقت <input type="time" name="requested_time" value="' . esc_attr( $row->requested_time ) . '"></label></p>'; }
 echo '<p><label>رد يظهر في حساب العميل<textarea name="customer_reply" rows="3" class="widefat">' . esc_textarea( $row->customer_reply ) . '</textarea></label></p><button class="button button-primary">حفظ وإظهار التحديث للعميل</button></form></details>';
}
function car_dealer_crm_related_requests( $id ) {
 global $wpdb;
 echo '<section class="cd-crm-summary"><h2>الطلبات والحجوزات المرتبطة</h2>';
 $user_id = absint( car_dealer_crm_meta( $id, 'user_id' ) );
 if ( $user_id ) { echo '<p>مرتبط بحساب العميل #' . $user_id . ' — الاسم والبريد والهاتف تتم مزامنتها من بيانات الحساب.</p>'; }
 $found = false;
 foreach ( array( 'message', 'booking' ) as $type ) {
  $prefix = '_crm_origin_' . $type . '_'; $ids = array();
  foreach ( get_post_meta( $id ) as $key => $values ) { if ( 0 === strpos( $key, $prefix ) ) { $ids[] = absint( substr( $key, strlen( $prefix ) ) ); } }
  if ( ! $ids ) { continue; }
  $table = car_dealer_request_table( $type );
  $rows = $wpdb->get_results( "SELECT * FROM $table WHERE id IN (" . implode( ',', $ids ) . ') ORDER BY id DESC LIMIT 20' );
  foreach ( $rows as $row ) {
   $found = true;
   echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=car-dealer-' . ( 'booking' === $type ? 'bookings' : 'messages' ) . '&request_id=' . $row->id ) ) . '">' . ( 'booking' === $type ? 'الحجز' : 'الطلب' ) . ' #' . absint( $row->id ) . '</a> — ' . esc_html( car_dealer_request_statuses( $type )[ $row->status ] ?? $row->status ) . ' — ' . esc_html( $row->created_at ) . '</p>';
  }
 }
 if ( ! $found ) { echo '<p>لا توجد طلبات مرتبطة بعد.</p>'; }
 echo '</section>';
}

// Reconcile existing authenticated requests by their recorded user ID, in bounded batches.
add_action( 'admin_init', function () {
 if ( ! current_user_can( 'manage_car_dealer' ) ) { return; }
 global $wpdb;
 foreach ( array( 'message', 'booking' ) as $type ) {
  $table = car_dealer_request_table( $type ); $option = 'cd_customer_link_cursor_' . $type;
  $cursor = absint( get_option( $option, 0 ) );
  $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE user_id > 0 AND id > %d ORDER BY id ASC LIMIT 100", $cursor ) );
  foreach ( $rows as $row ) {
   car_dealer_crm_capture( $type, $row );
   if ( ! car_dealer_request_crm( $type, $row ) ) { break; }
   update_option( $option, $row->id, false );
  }
 }
} );
add_action( 'wp_enqueue_scripts', function () {
 if ( ! is_user_logged_in() ) { return; }
 $user = wp_get_current_user();
 wp_localize_script( 'car-dealer-main', 'carDealerCustomer', array( 'name' => $user->display_name, 'email' => $user->user_email, 'phone' => get_user_meta( $user->ID, 'car_dealer_phone', true ) ) );
}, 20 );
