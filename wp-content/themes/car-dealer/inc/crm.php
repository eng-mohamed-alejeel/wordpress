<?php
/** Private CRM workspace: customers, sales pipeline and follow-up history. */
defined( 'ABSPATH' ) || exit;

function car_dealer_crm_stages() {
 return array( 'new' => 'عميل جديد', 'contacted' => 'تم التواصل', 'visit' => 'زيارة / تجربة قيادة', 'proposal' => 'عرض سعر', 'negotiation' => 'تفاوض', 'won' => 'تم البيع', 'lost' => 'فرصة مغلقة' );
}
function car_dealer_crm_url( $args = array() ) { return add_query_arg( $args, admin_url( 'admin.php?page=car-dealer-crm' ) ); }
add_action( 'init', function () {
 register_post_type( 'cd_crm', array( 'public' => false, 'publicly_queryable' => false, 'show_ui' => false, 'show_in_rest' => false, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title' ), 'capability_type' => 'post', 'map_meta_cap' => false, 'capabilities' => array( 'edit_post' => 'manage_car_dealer', 'read_post' => 'manage_car_dealer', 'delete_post' => 'manage_car_dealer', 'edit_posts' => 'manage_car_dealer', 'edit_others_posts' => 'manage_car_dealer', 'publish_posts' => 'manage_car_dealer', 'read_private_posts' => 'manage_car_dealer', 'delete_posts' => 'manage_car_dealer', 'create_posts' => 'do_not_allow' ) ) );
} );
add_action( 'admin_menu', function () {
 add_submenu_page( 'car-dealer-dashboard', 'إدارة العملاء CRM', 'إدارة العملاء CRM', 'manage_car_dealer', 'car-dealer-crm', 'car_dealer_crm_page' );
} );
add_action( 'admin_enqueue_scripts', function ( $hook ) {
 if ( false !== strpos( $hook, 'car-dealer' ) ) { wp_enqueue_style( 'car-dealer-crm', get_template_directory_uri() . '/assets/css/crm.css', array(), filemtime( __DIR__ . '/../assets/css/crm.css' ) ); }
} );
function car_dealer_crm_meta( $id, $key ) { return get_post_meta( $id, '_crm_' . $key, true ); }
function car_dealer_crm_record( $id ) {
 $post = get_post( $id );
 if ( ! $post || 'cd_crm' !== $post->post_type || 'private' !== $post->post_status ) { wp_die( 'ملف العميل غير موجود.', '', array( 'response' => 404 ) ); }
 return $post;
}
function car_dealer_crm_log( $id, $text ) {
 return wp_insert_comment( array( 'comment_post_ID' => $id, 'comment_content' => $text, 'comment_type' => 'crm_activity', 'comment_approved' => 1, 'user_id' => get_current_user_id(), 'comment_author' => 'CRM' ) );
}
function car_dealer_crm_input( $key ) {
 return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
}
function car_dealer_crm_save() {
 if ( ! current_user_can( 'manage_car_dealer' ) ) { wp_die( 'ليست لديك صلاحية.', '', array( 'response' => 403 ) ); }
 check_admin_referer( 'car_dealer_crm' );
 $id = absint( car_dealer_crm_input( 'id' ) );
 if ( $id ) { car_dealer_crm_record( $id ); }
 $action = car_dealer_crm_input( 'crm_action' );
 if ( 'import' === $action ) {
  $count = car_dealer_crm_import();
  wp_safe_redirect( car_dealer_crm_url( array( 'imported' => $count ) ) ); exit;
 }
 if ( 'note' === $action && $id ) {
  $note = isset( $_POST['note'] ) && is_string( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
  if ( '' === trim( $note ) || ! car_dealer_crm_log( $id, $note ) ) { wp_die( 'تعذر حفظ الملاحظة. تحقق من النص وحاول مجدداً.' ); }
 } elseif ( 'complete' === $action && $id ) {
  $due = car_dealer_crm_meta( $id, 'due' );
  if ( $due ) { car_dealer_crm_log( $id, 'تم إنجاز المتابعة: ' . $due . ' — ' . car_dealer_crm_meta( $id, 'task' ) ); delete_post_meta( $id, '_crm_due' ); delete_post_meta( $id, '_crm_task' ); }
 } elseif ( 'save' === $action ) {
  $name = car_dealer_crm_input( 'name' ); $email = car_dealer_crm_input( 'email' ); $phone = car_dealer_crm_input( 'phone' );
  $stage = car_dealer_crm_input( 'stage' ); $owner = absint( car_dealer_crm_input( 'owner' ) ); $car = absint( car_dealer_crm_input( 'car' ) );
  $due = car_dealer_crm_input( 'due' ); $value = car_dealer_crm_input( 'value' );
  if ( ! $name || ( ! $phone && ! $email ) || ( $email && ! is_email( $email ) ) || ! isset( car_dealer_crm_stages()[ $stage ] ) ) { wp_die( 'أدخل الاسم ورقم الهاتف أو بريداً صحيحاً وحدد مرحلة البيع. ارجع للصفحة السابقة لتصحيح البيانات.' ); }
  if ( ( $owner && ! user_can( $owner, 'manage_car_dealer' ) ) || ( $car && 'car' !== get_post_type( $car ) ) || ( '' !== $value && ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > 9999999999 ) ) ) { wp_die( 'تحقق من المسؤول والسيارة وقيمة الفرصة.' ); }
  if ( $due ) { $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $due, wp_timezone() ); if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $due ) { wp_die( 'موعد المتابعة غير صالح.' ); } }
  $old = $id ? car_dealer_crm_meta( $id, 'stage' ) : '';
  $linked_user = $id ? get_userdata( absint( car_dealer_crm_meta( $id, 'user_id' ) ) ) : false;
  if ( $linked_user ) { $name = $linked_user->display_name; $email = $linked_user->user_email; $phone = get_user_meta( $linked_user->ID, 'car_dealer_phone', true ); }
  $result = wp_insert_post( array( 'ID' => $id, 'post_type' => 'cd_crm', 'post_status' => 'private', 'post_title' => $name ), true );
  if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
  $id = $result;
  foreach ( array( 'email' => $email, 'phone' => $phone, 'stage' => $stage, 'owner' => $owner, 'car' => $car, 'due' => $due, 'value' => $value, 'source' => car_dealer_crm_input( 'source' ), 'task' => car_dealer_crm_input( 'task' ) ) as $key => $val ) { update_post_meta( $id, '_crm_' . $key, $val ); }
  car_dealer_crm_log( $id, $old ? ( $old !== $stage ? 'تغيير المرحلة إلى: ' . car_dealer_crm_stages()[ $stage ] : 'تحديث بيانات العميل والمتابعة' ) : 'إنشاء ملف العميل' );
 } else { wp_die( 'الإجراء غير صالح.' ); }
 wp_safe_redirect( car_dealer_crm_url( array( 'customer' => $id, 'saved' => 1 ) ) ); exit;
}
add_action( 'admin_post_car_dealer_crm', 'car_dealer_crm_save' );

add_action( 'admin_post_car_dealer_crm_export', function () {
 if ( ! current_user_can( 'manage_car_dealer' ) ) { wp_die( 'ليست لديك صلاحية.', '', array( 'response' => 403 ) ); }
 check_admin_referer( 'car_dealer_crm' );
 nocache_headers();
 header( 'Content-Type: text/csv; charset=utf-8' );
 header( 'Content-Disposition: attachment; filename="crm-customers.csv"' );
 $stream = fopen( 'php://output', 'w' );
 fwrite( $stream, "\xEF\xBB\xBF" );
 fputcsv( $stream, array( 'العميل', 'الهاتف', 'البريد', 'المصدر', 'المرحلة', 'قيمة الفرصة', 'المتابعة', 'المهمة' ) );
 $page = 1;
 do {
  $rows = get_posts( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'posts_per_page' => 200, 'paged' => $page++, 'orderby' => 'ID', 'order' => 'ASC' ) );
  foreach ( $rows as $row ) {
   $cells = array( $row->post_title );
   foreach ( array( 'phone', 'email', 'source', 'stage', 'value', 'due', 'task' ) as $key ) {
    $value = car_dealer_crm_meta( $row->ID, $key );
    $cells[] = 'stage' === $key ? ( car_dealer_crm_stages()[ $value ] ?? $value ) : $value;
   }
   // Neutralize spreadsheet formulas, including ones prefixed with whitespace.
   $cells = array_map( function ( $value ) { return preg_match( '/^[\s]*[=+@-]/u', (string) $value ) ? "'" . $value : $value; }, $cells );
   fputcsv( $stream, $cells );
  }
 } while ( count( $rows ) === 200 );
 fclose( $stream ); exit;
} );

/** Source IDs make repeated imports safe; identical emails share a customer file. */
function car_dealer_crm_capture( $type, $row ) {
 $key = '_crm_origin_' . $type . '_' . absint( $row->id );
 $exists = get_posts( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'meta_key' => $key, 'meta_value' => '1', 'numberposts' => 1, 'fields' => 'ids' ) );
 $account_crm = ! empty( $row->user_id ) ? car_dealer_customer_crm( $row->user_id ) : 0;
 if ( $exists && ( ! $account_crm || (int) $exists[0] === (int) $account_crm ) ) { return false; }
 $matches = $account_crm ? array( $account_crm ) : ( empty( $row->email ) ? array() : get_posts( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'meta_query' => array( array( 'key' => '_crm_email', 'value' => $row->email ), array( 'key' => '_crm_user_id', 'compare' => 'NOT EXISTS' ) ), 'numberposts' => 1, 'fields' => 'ids' ) ) );
 $id = $matches ? $matches[0] : wp_insert_post( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'post_title' => $row->name ), true );
 if ( is_wp_error( $id ) || ! $id ) { return false; }
 if ( ! $matches ) {
  foreach ( array( 'email' => $row->email, 'phone' => $row->phone, 'car' => $row->car_id, 'stage' => 'new', 'source' => 'booking' === $type ? 'حجز تجربة قيادة' : 'نموذج الموقع' ) as $k => $v ) { update_post_meta( $id, '_crm_' . $k, $v ); }
 }
 if ( $row->car_id && ! car_dealer_crm_meta( $id, 'car' ) ) { update_post_meta( $id, '_crm_car', $row->car_id ); }
 $text = 'booking' === $type ? 'حجز تجربة قيادة: ' . $row->requested_date . ' ' . $row->requested_time : 'طلب من الموقع (' . $row->lead_type . '): ' . $row->message;
 if ( $row->car_id ) { $text .= "\nالسيارة: " . get_the_title( $row->car_id ); }
 $text .= "\nتاريخ الطلب: " . $row->created_at;
 if ( ! car_dealer_crm_log( $id, $text ) ) { return false; }
 update_post_meta( $id, $key, '1' );
 if ( $exists && (int) $exists[0] !== (int) $id ) { delete_post_meta( $exists[0], $key ); }
 return true;
}
function car_dealer_crm_import() {
 global $wpdb;
 $count = 0;
 foreach ( array( 'message' => 'messages', 'booking' => 'bookings' ) as $type => $suffix ) {
  $table = $wpdb->prefix . 'car_dealer_' . $suffix;
  $cursor = absint( get_option( 'car_dealer_crm_cursor_' . $type, 0 ) );
  $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE id > %d ORDER BY id ASC LIMIT 100", $cursor ) );
  foreach ( $rows as $row ) {
   if ( car_dealer_crm_capture( $type, $row ) ) { $count++; }
   else {
    $done = get_posts( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'meta_key' => '_crm_origin_' . $type . '_' . $row->id, 'meta_value' => '1', 'numberposts' => 1, 'fields' => 'ids' ) );
    if ( ! $done ) { break; }
   }
   update_option( 'car_dealer_crm_cursor_' . $type, $row->id, false );
  }
 }
 return $count;
}
add_action( 'car_dealer_engagement_created', function ( $type, $id ) {
 global $wpdb;
 $suffix = 'booking' === $type ? 'bookings' : 'messages';
 $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}car_dealer_{$suffix} WHERE id = %d", $id ) );
 if ( $row ) { car_dealer_crm_capture( $type, $row ); }
}, 10, 2 );
function car_dealer_crm_form_start( $action, $id = 0 ) {
 echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="car_dealer_crm"><input type="hidden" name="crm_action" value="' . esc_attr( $action ) . '"><input type="hidden" name="id" value="' . absint( $id ) . '">';
 wp_nonce_field( 'car_dealer_crm' );
}
function car_dealer_crm_summary() {
 if ( ! current_user_can( 'manage_car_dealer' ) ) { return; }
 global $wpdb;
 $counts = $wpdb->get_results( "SELECT m.meta_value stage, COUNT(*) total FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON p.ID=m.post_id AND m.meta_key='_crm_stage' WHERE p.post_type='cd_crm' AND p.post_status='private' GROUP BY m.meta_value", OBJECT_K );
 $overdue = new WP_Query( array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_crm_due', 'value' => '', 'compare' => '!=' ), array( 'key' => '_crm_due', 'value' => current_time( 'Y-m-d\TH:i' ), 'compare' => '<=' ) ) ) );
 echo '<section class="cd-crm-summary"><h2><a href="' . esc_url( car_dealer_crm_url() ) . '">إدارة علاقات العملاء CRM</a></h2><div class="cd-crm-stats">';
 foreach ( car_dealer_crm_stages() as $key => $label ) { echo '<a href="' . esc_url( car_dealer_crm_url( array( 'stage' => $key ) ) ) . '"><strong>' . absint( $counts[ $key ]->total ?? 0 ) . '</strong>' . esc_html( $label ) . '</a>'; }
 echo '<a href="' . esc_url( car_dealer_crm_url( array( 'due' => '1' ) ) ) . '"><strong>' . absint( $overdue->found_posts ) . '</strong>متابعات مستحقة</a></div></section>';
}
function car_dealer_crm_page() {
 if ( ! current_user_can( 'manage_car_dealer' ) ) { wp_die( 'ليست لديك صلاحية.' ); }
 echo '<div class="wrap cd-admin cd-crm" dir="rtl"><h1>إدارة علاقات العملاء CRM</h1><p>ملفات العملاء، فرص البيع، ومواعيد المتابعة. جميع موظفي المعرض المخولين يمكنهم إدارة هذه الملفات.</p>';
 if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>تم حفظ التغييرات.</p></div>'; }
 if ( isset( $_GET['imported'] ) ) { echo '<div class="notice notice-success"><p>تمت مزامنة ' . absint( $_GET['imported'] ) . ' طلب. كرر المزامنة لتحميل الدفعة التالية إن وجدت.</p></div>'; }
 if ( isset( $_GET['customer'] ) ) { car_dealer_crm_editor( absint( $_GET['customer'] ) ); echo '</div>'; return; }
 car_dealer_crm_summary();
 echo '<div class="cd-crm-toolbar"><a class="button button-primary" href="' . esc_url( car_dealer_crm_url( array( 'customer' => 0 ) ) ) . '">إضافة عميل / فرصة</a>';
 car_dealer_crm_form_start( 'import' ); echo '<button class="button">مزامنة الطلبات السابقة (حتى 200 طلب)</button></form>';
 echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="car_dealer_crm_export">';
 wp_nonce_field( 'car_dealer_crm' );
 echo '<button class="button">تصدير جميع العملاء CSV</button></form></div>';
 $search = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ); $stage = sanitize_key( $_GET['stage'] ?? '' ); $due = ! empty( $_GET['due'] ); $mine = ! empty( $_GET['mine'] );
 echo '<form method="get" class="cd-crm-toolbar"><input type="hidden" name="page" value="car-dealer-crm"><input name="q" aria-label="بحث العملاء" placeholder="الاسم أو الهاتف أو البريد" value="' . esc_attr( $search ) . '"><select name="stage" aria-label="مرحلة البيع"><option value="">كل المراحل</option>';
 foreach ( car_dealer_crm_stages() as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $stage, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
 echo '</select><label><input type="checkbox" name="due" value="1" ' . checked( $due, true, false ) . '> المستحقة</label><label><input type="checkbox" name="mine" value="1" ' . checked( $mine, true, false ) . '> المسندة إليّ</label><button class="button">بحث وتصفية</button><a href="' . esc_url( car_dealer_crm_url() ) . '">إلغاء التصفية</a></form>';
 $meta = array();
 if ( isset( car_dealer_crm_stages()[ $stage ] ) ) { $meta[] = array( 'key' => '_crm_stage', 'value' => $stage ); }
 if ( $mine ) { $meta[] = array( 'key' => '_crm_owner', 'value' => get_current_user_id() ); }
 if ( $due ) { $meta[] = array( 'key' => '_crm_due', 'value' => '', 'compare' => '!=' ); $meta[] = array( 'key' => '_crm_due', 'value' => current_time( 'Y-m-d\TH:i' ), 'compare' => '<=' ); }
 $args = array( 'post_type' => 'cd_crm', 'post_status' => 'private', 'posts_per_page' => 20, 'paged' => max( 1, absint( $_GET['paged'] ?? 1 ) ), 'meta_query' => $meta );
 // Restrict the search filter to this query and use prepared LIKE values.
 $filter = function ( $where, $query ) use ( $search ) {
  if ( ! $query->get( 'cd_crm_search' ) || '' === $search ) { return $where; }
  global $wpdb; $like = '%' . $wpdb->esc_like( $search ) . '%';
  return $where . $wpdb->prepare( " AND ({$wpdb->posts}.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} cm WHERE cm.post_id={$wpdb->posts}.ID AND cm.meta_key IN ('_crm_email','_crm_phone') AND cm.meta_value LIKE %s))", $like, $like );
 };
 $args['cd_crm_search'] = true; add_filter( 'posts_where', $filter, 10, 2 ); $query = new WP_Query( $args ); remove_filter( 'posts_where', $filter, 10 );
 echo '<div class="cd-crm-table"><table class="widefat striped"><thead><tr><th>العميل</th><th>التواصل</th><th>المرحلة / المسؤول</th><th>السيارة / قيمة الفرصة</th><th>المتابعة القادمة</th></tr></thead><tbody>';
 foreach ( $query->posts as $post ) {
  $id = $post->ID; $owner = get_userdata( absint( car_dealer_crm_meta( $id, 'owner' ) ) ); $date = car_dealer_crm_meta( $id, 'due' );
  echo '<tr><td><a href="' . esc_url( car_dealer_crm_url( array( 'customer' => $id ) ) ) . '"><strong>' . esc_html( $post->post_title ) . '</strong></a><br>' . esc_html( car_dealer_crm_meta( $id, 'source' ) ) . '</td><td><span dir="ltr">' . esc_html( car_dealer_crm_meta( $id, 'phone' ) ) . '</span><br>' . esc_html( car_dealer_crm_meta( $id, 'email' ) ) . '</td><td>' . esc_html( car_dealer_crm_stages()[ car_dealer_crm_meta( $id, 'stage' ) ] ?? 'جديد' ) . '<br>' . esc_html( $owner ? $owner->display_name : 'غير مسند' ) . '</td><td>' . esc_html( car_dealer_crm_meta( $id, 'car' ) ? get_the_title( car_dealer_crm_meta( $id, 'car' ) ) : '—' ) . '<br>' . esc_html( car_dealer_format_price( car_dealer_crm_meta( $id, 'value' ) ) ) . '</td><td><span class="' . ( $date && $date <= current_time( 'Y-m-d\TH:i' ) ? 'cd-crm-overdue' : '' ) . '">' . esc_html( str_replace( 'T', ' ', $date ?: 'غير محدد' ) ) . '</span><br>' . esc_html( car_dealer_crm_meta( $id, 'task' ) ) . '</td></tr>';
 }
 if ( ! $query->posts ) { echo '<tr><td colspan="5">لا توجد ملفات مطابقة. أضف عميلاً أو زامن الطلبات السابقة.</td></tr>'; }
 echo '</tbody></table></div><div class="tablenav">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $args['paged'], 'total' => $query->max_num_pages ) ) ) . '</div></div>';
}
function car_dealer_crm_editor( $id ) {
 $post = $id ? car_dealer_crm_record( $id ) : null;
 echo '<p><a href="' . esc_url( car_dealer_crm_url() ) . '">← العودة إلى العملاء</a></p><h2>' . esc_html( $post ? $post->post_title : 'إضافة عميل وفرصة بيع' ) . '</h2>';
 car_dealer_crm_form_start( 'save', $id ); echo '<div class="cd-crm-fields">';
 foreach ( array( 'name' => array( 'اسم العميل *', 'text' ), 'phone' => array( 'الهاتف', 'tel' ), 'email' => array( 'البريد الإلكتروني', 'email' ), 'source' => array( 'مصدر العميل', 'text' ), 'value' => array( 'قيمة الفرصة (ر.س)', 'number' ), 'due' => array( 'موعد المتابعة (توقيت الموقع)', 'datetime-local' ), 'task' => array( 'المتابعة المطلوبة', 'text' ) ) as $key => $field ) {
  $value = 'name' === $key ? ( $post ? $post->post_title : '' ) : car_dealer_crm_meta( $id, $key );
  echo '<label>' . esc_html( $field[0] ) . '<input name="' . esc_attr( $key ) . '" type="' . esc_attr( $field[1] ) . '" value="' . esc_attr( $value ) . '"' . ( 'name' === $key ? ' required' : '' ) . ( 'value' === $key ? ' min="0" max="9999999999" step="0.01"' : '' ) . ( car_dealer_crm_meta( $id, 'user_id' ) && in_array( $key, array( 'name', 'email', 'phone' ), true ) ? ' readonly' : '' ) . '></label>';
 }
 $owners = array( 0 => 'غير مسند' ); foreach ( get_users() as $user ) { if ( user_can( $user, 'manage_car_dealer' ) ) { $owners[ $user->ID ] = $user->display_name; } }
 $cars = array( 0 => 'بدون سيارة محددة' ); foreach ( get_posts( array( 'post_type' => 'car', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'numberposts' => -1 ) ) as $car ) { $cars[ $car->ID ] = $car->post_title; }
 foreach ( array( 'stage' => array( 'مرحلة البيع', car_dealer_crm_stages() ), 'owner' => array( 'مسؤول المتابعة', $owners ), 'car' => array( 'السيارة المطلوبة', $cars ) ) as $key => $field ) {
  echo '<label>' . esc_html( $field[0] ) . '<select name="' . esc_attr( $key ) . '">';
  foreach ( $field[1] as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( car_dealer_crm_meta( $id, $key ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }
  echo '</select></label>';
 }
 echo '</div><p>أدخل رقم الهاتف أو البريد الإلكتروني على الأقل.</p><button class="button button-primary">حفظ ملف العميل</button></form>';
 if ( ! $id ) { return; }
 car_dealer_crm_related_requests( $id );
 if ( car_dealer_crm_meta( $id, 'due' ) ) { car_dealer_crm_form_start( 'complete', $id ); echo '<p><button class="button">تم إنجاز المتابعة الحالية</button></p></form>'; }
 echo '<h2>سجل التواصل والملاحظات</h2>';
 car_dealer_crm_form_start( 'note', $id ); echo '<label for="crm-note">تسجيل مكالمة، زيارة، أو ملاحظة</label><p><textarea id="crm-note" name="note" rows="4" class="large-text" required></textarea></p><button class="button">إضافة للسجل</button></form>';
 $page = max( 1, absint( $_GET['history_page'] ?? 1 ) );
 $base = array( 'post_id' => $id, 'type' => 'crm_activity', 'status' => 'approve' );
 $notes = get_comments( array_merge( $base, array( 'number' => 20, 'offset' => ( $page - 1 ) * 20, 'orderby' => 'comment_ID', 'order' => 'DESC' ) ) );
 echo '<ul class="cd-crm-history">';
 foreach ( $notes as $note ) { $author = get_userdata( $note->user_id ); echo '<li><small>' . esc_html( $note->comment_date . ' — ' . ( $author ? $author->display_name : 'الموقع' ) ) . '</small><p>' . nl2br( esc_html( $note->comment_content ) ) . '</p></li>'; }
 if ( ! $notes ) { echo '<li>لا توجد ملاحظات بعد.</li>'; }
 echo '</ul>' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'history_page', '%#%' ), 'format' => '', 'current' => $page, 'total' => ceil( get_comments( array_merge( $base, array( 'count' => true ) ) ) / 20 ) ) ) );
}
