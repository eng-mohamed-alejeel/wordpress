<?php
/** Messages, subscribers and test-drive bookings migrated from legacy forms. */
defined( 'ABSPATH' ) || exit;

function car_dealer_engagement_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$wpdb->prefix}car_dealer_messages (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		KEY user_id (user_id),
		lead_type varchar(40) DEFAULT 'contact',
		car_id BIGINT UNSIGNED DEFAULT 0,
		name varchar(120) NOT NULL,
		email varchar(190) NOT NULL,
		phone varchar(60) DEFAULT '',
		subject varchar(190) DEFAULT '',
		message longtext NOT NULL,
		status varchar(20) DEFAULT 'new',
		customer_reply text NOT NULL,
		updated_at datetime DEFAULT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY status (status)
	) $charset;" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}car_dealer_bookings (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		KEY user_id (user_id),
		car_id BIGINT UNSIGNED NOT NULL,
		name varchar(120) NOT NULL,
		email varchar(190) NOT NULL,
		phone varchar(60) DEFAULT '',
		requested_date date DEFAULT NULL,
		requested_time varchar(30) DEFAULT '',
		status varchar(20) DEFAULT 'pending',
		customer_reply text NOT NULL,
		updated_at datetime DEFAULT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY car_id (car_id),
		KEY status (status)
	) $charset;" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}car_dealer_subscribers (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email varchar(190) NOT NULL,
		status varchar(20) DEFAULT 'active',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email)
	) $charset;" );
}

function car_dealer_maybe_install_engagement_tables() {
	$version = '1.3.0';
	if ( get_option( 'car_dealer_engagement_tables_version' ) === $version ) { return; }
	car_dealer_engagement_tables();
	update_option( 'car_dealer_engagement_tables_version', $version );
}
add_action( 'init', 'car_dealer_maybe_install_engagement_tables', 1 );
add_action( 'after_switch_theme', 'car_dealer_maybe_install_engagement_tables' );

function car_dealer_register_engagement_menu() {
	add_submenu_page( 'car-dealer-dashboard', __( 'الرسائل', 'car-dealer' ), __( 'الرسائل', 'car-dealer' ), 'manage_car_dealer', 'car-dealer-messages', 'car_dealer_render_messages_page' );
	add_submenu_page( 'car-dealer-dashboard', __( 'حجوزات التجربة', 'car-dealer' ), __( 'حجوزات التجربة', 'car-dealer' ), 'manage_car_dealer', 'car-dealer-bookings', 'car_dealer_render_bookings_page' );
	add_submenu_page( 'car-dealer-dashboard', __( 'النشرة البريدية', 'car-dealer' ), __( 'النشرة البريدية', 'car-dealer' ), 'manage_car_dealer', 'car-dealer-subscribers', 'car_dealer_render_subscribers_page' );
}
add_action( 'admin_menu', 'car_dealer_register_engagement_menu', 20 );

function car_dealer_store_message() {
	check_ajax_referer( 'car_dealer_frontend', 'nonce' );
	global $wpdb;
	$lead_type = sanitize_key( wp_unslash( $_POST['lead_type'] ?? 'contact' ) );
	$car_id = absint( $_POST['car_id'] ?? 0 );
	if ( ! in_array( $lead_type, array( 'contact', 'finance', 'finance_request', 'price_request', 'test_drive', 'purchase' ), true ) || ( $car_id && ( 'car' !== get_post_type( $car_id ) || 'publish' !== get_post_status( $car_id ) ) ) ) { wp_send_json_error( array( 'message' => 'نوع الطلب أو السيارة غير صالح.' ) ); }
	$identity = car_dealer_submission_identity(); $name = $identity['name']; $email = $identity['email']; $phone = $identity['phone'];
	$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
	if ( ! $message && 'contact' !== $lead_type ) { $message = sprintf( __( 'طلب جديد: %s', 'car-dealer' ), $lead_type ); }
	if ( ! $name || ! is_email( $email ) || ! $message ) { wp_send_json_error( array( 'message' => __( 'تحقق من الاسم والبريد والرسالة.', 'car-dealer' ) ) ); }
	$wpdb->insert( $wpdb->prefix . 'car_dealer_messages', array( 'user_id' => get_current_user_id(), 'lead_type' => $lead_type, 'car_id' => $car_id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $message, 'status' => 'new', 'customer_reply' => '', 'created_at' => current_time( 'mysql' ) ) );
	if ( ! $wpdb->insert_id ) { wp_send_json_error( array( 'message' => 'تعذر حفظ الطلب، حاول مجدداً.' ) ); }
	do_action( 'car_dealer_engagement_created', 'message', $wpdb->insert_id );
	wp_send_json_success( array( 'message' => __( 'تم استلام رسالتك بنجاح.', 'car-dealer' ) ) );
}
add_action( 'wp_ajax_car_dealer_contact', 'car_dealer_store_message' );
add_action( 'wp_ajax_nopriv_car_dealer_contact', 'car_dealer_store_message' );

function car_dealer_store_booking() {
	check_ajax_referer( 'car_dealer_frontend', 'nonce' );
	global $wpdb;
	$car_id = absint( $_POST['car_id'] ?? 0 );
	$identity = car_dealer_submission_identity(); $name = $identity['name']; $email = $identity['email']; $phone = $identity['phone'];
	$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
	$time = sanitize_text_field( wp_unslash( $_POST['time'] ?? '' ) );
	if ( ! $car_id || 'car' !== get_post_type( $car_id ) || ! $name || ! is_email( $email ) ) { wp_send_json_error( array( 'message' => __( 'تحقق من بيانات الحجز.', 'car-dealer' ) ) ); }
 if ( ! car_dealer_valid_booking_date( $date, $time ) ) { wp_send_json_error( array( 'message' => 'لم يُحفظ الحجز: اختر تاريخاً ووقتاً في المستقبل حسب توقيت الموقع (' . wp_timezone_string() . ').' ) ); }
 if ( 'publish' !== get_post_status( $car_id ) || 'sold' === get_post_meta( $car_id, '_car_inventory_status', true ) ) { wp_send_json_error( array( 'message' => 'لم يُحفظ الحجز: هذه السيارة غير متاحة للحجز حالياً.' ) ); }
	$wpdb->insert( $wpdb->prefix . 'car_dealer_bookings', array( 'user_id' => get_current_user_id(), 'car_id' => $car_id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'requested_date' => $date ?: null, 'requested_time' => $time, 'status' => 'pending', 'customer_reply' => '', 'created_at' => current_time( 'mysql' ) ) );
	if ( ! $wpdb->insert_id ) { wp_send_json_error( array( 'message' => 'تعذر حفظ الحجز، حاول مجدداً.' ) ); }
	$booking_id = (int) $wpdb->insert_id;
	do_action( 'car_dealer_engagement_created', 'booking', $booking_id );
	wp_send_json_success( array( 'message' => 'تم حفظ حجز تجربة القيادة رقم #' . $booking_id . ( is_user_logged_in() ? '. يمكنك متابعته في حسابك.' : '. تم إرساله للمعرض كطلب زائر.' ), 'booking_id' => $booking_id, 'account_url' => is_user_logged_in() ? car_dealer_account_url() . '#customer-bookings' : '' ) );
}
add_action( 'wp_ajax_car_dealer_booking', 'car_dealer_store_booking' );
add_action( 'wp_ajax_nopriv_car_dealer_booking', 'car_dealer_store_booking' );

function car_dealer_store_subscriber() {
	check_ajax_referer( 'car_dealer_frontend', 'nonce' );
	global $wpdb;
	$identity = car_dealer_submission_identity(); $email = $identity['email'];
	if ( ! is_email( $email ) ) { wp_send_json_error( array( 'message' => __( 'البريد الإلكتروني غير صالح.', 'car-dealer' ) ) ); }
	$wpdb->replace( $wpdb->prefix . 'car_dealer_subscribers', array( 'email' => $email, 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
	wp_send_json_success( array( 'message' => __( 'تم الاشتراك بنجاح.', 'car-dealer' ) ) );
}
add_action( 'wp_ajax_car_dealer_subscribe', 'car_dealer_store_subscriber' );
add_action( 'wp_ajax_nopriv_car_dealer_subscribe', 'car_dealer_store_subscriber' );

/** One authoritative identity for every customer-facing submission. */
function car_dealer_submission_identity() {
 if ( is_user_logged_in() ) {
  $user = wp_get_current_user();
  return array( 'name' => $user->display_name, 'email' => $user->user_email, 'phone' => (string) get_user_meta( $user->ID, 'car_dealer_phone', true ) );
 }
 return array( 'name' => sanitize_text_field( car_dealer_account_field( 'name' ) ), 'email' => sanitize_email( car_dealer_account_field( 'email' ) ), 'phone' => sanitize_text_field( car_dealer_account_field( 'phone' ) ) );
}
function car_dealer_customer_form_fields( $email_only = false ) {
 $identity = is_user_logged_in() ? car_dealer_submission_identity() : array( 'name' => '', 'email' => '', 'phone' => '' );
 $html = '';
 foreach ( array( 'name' => array( 'الاسم', 'text', 'name' ), 'email' => array( 'البريد الإلكتروني', 'email', 'email' ), 'phone' => array( 'الهاتف', 'tel', 'tel' ) ) as $key => $field ) {
  if ( $email_only && 'email' !== $key ) { continue; }
  $html .= '<label>' . esc_html( $field[0] ) . '<input name="' . esc_attr( $key ) . '" type="' . esc_attr( $field[1] ) . '" autocomplete="' . esc_attr( $field[2] ) . '" value="' . esc_attr( $identity[$key] ) . '"' . ( 'phone' !== $key ? ' required' : '' ) . ( is_user_logged_in() ? ' readonly' : '' ) . '></label>';
 }
 if ( is_user_logged_in() ) {
  $html .= '<p class="cd-profile-form-note">تُرسل بيانات حسابك تلقائياً. <a href="' . esc_url( car_dealer_account_url() ) . '">تحديث بياناتي' . ( ! $email_only && ! $identity['phone'] ? ' وإضافة رقم الهاتف' : '' ) . '</a></p>';
 }
 return $html;
}
function car_dealer_contact_form_shortcode() {
 return '<form class="cd-ajax-form cd-contact-form" data-action="car_dealer_contact">' . car_dealer_customer_form_fields() . '<textarea name="message" required aria-label="رسالتك" placeholder="رسالتك"></textarea><button class="btn btn-primary" type="submit">إرسال</button><p class="cd-form-status" role="status"></p></form>';
}
add_shortcode( 'car_dealer_contact_form', 'car_dealer_contact_form_shortcode' );
function car_dealer_lead_form( $car_id, $type, $title, $button ) {
 echo '<form class="cd-ajax-form cd-lead-form" data-action="car_dealer_contact"><h2>' . esc_html( $title ) . '</h2><input type="hidden" name="car_id" value="' . absint( $car_id ) . '"><input type="hidden" name="lead_type" value="' . esc_attr( $type ) . '">' . car_dealer_customer_form_fields() . '<textarea name="message" aria-label="ملاحظات إضافية" placeholder="ملاحظات إضافية"></textarea><button class="btn btn-primary" type="submit">' . esc_html( $button ) . '</button><p class="cd-form-status" role="status"></p></form>';
}
function car_dealer_booking_form( $car_id ) {
 echo '<form class="cd-ajax-form cd-booking-form" data-action="car_dealer_booking"><h2>احجز تجربة قيادة</h2><input type="hidden" name="car_id" value="' . absint( $car_id ) . '">' . car_dealer_customer_form_fields() . '<label>اليوم<input name="date" type="date" min="' . esc_attr( current_time( 'Y-m-d' ) ) . '" required></label><label>الوقت<input name="time" type="time" required></label><button class="btn btn-primary" type="submit">إرسال الطلب</button><p class="cd-form-status" role="status"></p></form>';
}
function car_dealer_newsletter_form() {
 echo '<form class="cd-ajax-form cd-newsletter-form" data-action="car_dealer_subscribe">' . car_dealer_customer_form_fields( true ) . '<button class="btn btn-primary" type="submit">اشترك</button><p class="cd-form-status" role="status"></p></form>';
}
function car_dealer_render_table_page( $title, $table, $columns ) {
	global $wpdb;
	if ( ! current_user_can( 'manage_car_dealer' ) ) { wp_die( esc_html__( 'ليست لديك صلاحية لعرض السجلات.', 'car-dealer' ) ); }
	if ( ! in_array( $table, array( 'car_dealer_messages', 'car_dealer_bookings', 'car_dealer_subscribers' ), true ) ) { return; }
 $request_type = 'car_dealer_bookings' === $table ? 'booking' : 'message';
 $linked = 'car_dealer_subscribers' !== $table;
 if ( $linked ) { $columns['workflow'] = 'إدارة الطلب'; }
 $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
 $where = $linked && ! empty( $_GET['request_id'] ) ? $wpdb->prepare( ' WHERE id = %d', absint( $_GET['request_id'] ) ) : '';
 $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table}$where" );
 $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table}$where ORDER BY id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
 if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success"><p>تم تحديث الطلب وحساب العميل.</p></div>'; }
	echo '<div class="wrap cd-admin" dir="rtl"><h1>' . esc_html( $title ) . '</h1><p class="cd-page-description">' . esc_html__( 'طلبات العملاء مرتبة من الأحدث. تحديث الحالة والرد يظهران في حساب صاحب الطلب.', 'car-dealer' ) . '</p><table class="widefat striped"><thead><tr>';
	foreach ( $columns as $key => $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr>';
		foreach ( $columns as $key => $label ) {
			$value = (string) ( $row[ $key ] ?? '' );
			echo '<td>';
			if ( 'workflow' === $key ) { car_dealer_request_admin_actions( $request_type, $row ); } elseif ( 'status' === $key ) {
				$labels = array( 'new' => 'جديد', 'pending' => 'قيد الانتظار', 'active' => 'نشط', 'confirmed' => 'مؤكد', 'completed' => 'مكتمل', 'cancelled' => 'ملغى', 'read' => 'مقروء', 'inactive' => 'غير نشط' );
				echo '<span class="cd-status cd-status-' . esc_attr( sanitize_html_class( $value ) ) . '">' . esc_html( $labels[ $value ] ?? $value ) . '</span>';
			} elseif ( 'car_id' === $key && absint( $value ) && 'car' === get_post_type( absint( $value ) ) ) {
				if ( current_user_can( 'edit_post', absint( $value ) ) ) { echo '<a href="' . esc_url( get_edit_post_link( absint( $value ) ) ) . '">' . esc_html( get_the_title( absint( $value ) ) ) . '</a>'; }
				else { echo esc_html( get_the_title( absint( $value ) ) ); }
			} elseif ( 'email' === $key && is_email( $value ) ) {
				echo '<a dir="ltr" href="' . esc_url( 'mailto:' . $value ) . '">' . esc_html( $value ) . '</a>';
			} elseif ( 'phone' === $key && $value ) {
				echo '<a dir="ltr" href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $value ) ) . '">' . esc_html( $value ) . '</a>';
			} elseif ( 'message' === $key && wp_html_excerpt( $value, 100 ) !== $value ) {
				echo '<details class="cd-message-details"><summary>' . esc_html( wp_html_excerpt( $value, 80, '…' ) ) . '</summary><p>' . nl2br( esc_html( $value ) ) . '</p></details>';
			} elseif ( 'lead_type' === $key ) {
				$types = array( 'contact' => 'تواصل عام', 'finance' => 'طلب تمويل', 'test_drive' => 'تجربة قيادة', 'purchase' => 'طلب شراء' );
				echo esc_html( $types[ $value ] ?? $value );
			} else { echo esc_html( '' !== $value && ! ( 'car_id' === $key && '0' === $value ) ? $value : '—' ); }
			echo '</td>';
		}
		echo '</tr>';
	}
	if ( ! $rows ) { echo '<tr><td colspan="' . esc_attr( count( $columns ) ) . '"><div class="cd-empty-state"><span class="dashicons dashicons-inbox" aria-hidden="true"></span><h3>' . esc_html__( 'لا توجد سجلات حتى الآن', 'car-dealer' ) . '</h3><p>' . esc_html__( 'ستظهر هنا البيانات عند إرسال العملاء طلباتهم من الموقع.', 'car-dealer' ) . '</p></div></td></tr>'; }
	echo '</tbody></table><div class="tablenav">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => ceil( $total / 20 ) ) ) ) . '</div></div>';
}

function car_dealer_render_messages_page() { car_dealer_render_table_page( __( 'رسائل العملاء وطلبات البيع', 'car-dealer' ), 'car_dealer_messages', array( 'created_at' => __( 'التاريخ', 'car-dealer' ), 'lead_type' => __( 'نوع الطلب', 'car-dealer' ), 'car_id' => __( 'السيارة', 'car-dealer' ), 'name' => __( 'الاسم', 'car-dealer' ), 'email' => __( 'البريد', 'car-dealer' ), 'phone' => __( 'الهاتف', 'car-dealer' ), 'message' => __( 'الرسالة', 'car-dealer' ), 'status' => __( 'الحالة', 'car-dealer' ) ) ); }
function car_dealer_render_bookings_page() { car_dealer_render_table_page( __( 'حجوزات تجربة القيادة', 'car-dealer' ), 'car_dealer_bookings', array( 'created_at' => __( 'التاريخ', 'car-dealer' ), 'car_id' => __( 'السيارة', 'car-dealer' ), 'name' => __( 'الاسم', 'car-dealer' ), 'email' => __( 'البريد', 'car-dealer' ), 'phone' => __( 'الهاتف', 'car-dealer' ), 'requested_date' => __( 'اليوم', 'car-dealer' ), 'requested_time' => __( 'الوقت', 'car-dealer' ), 'status' => __( 'الحالة', 'car-dealer' ) ) ); }
function car_dealer_render_subscribers_page() { car_dealer_render_table_page( __( 'النشرة البريدية', 'car-dealer' ), 'car_dealer_subscribers', array( 'created_at' => __( 'التاريخ', 'car-dealer' ), 'email' => __( 'البريد', 'car-dealer' ), 'status' => __( 'الحالة', 'car-dealer' ) ) ); }
