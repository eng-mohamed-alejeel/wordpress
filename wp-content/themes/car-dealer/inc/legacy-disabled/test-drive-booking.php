<?php
/**
 * نظام حجز تجربة القيادة لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة نوع المقال المخصص لطلبات تجربة القيادة
 */
function car_dealer_register_test_drive_cpt() {
	register_post_type( 'test_drive_request',
		array(
			'labels'      => array(
				'name'          => __( 'طلبات تجربة القيادة', 'car-dealer' ),
				'singular_name' => __( 'طلب تجربة القيادة', 'car-dealer' ),
				'add_new_item'  => __( 'إضافة طلب جديد', 'car-dealer' ),
				'edit_item'     => __( 'تعديل الطلب', 'car-dealer' ),
				'new_item'      => __( 'طلب جديد', 'car-dealer' ),
				'view_item'     => __( 'عرض الطلب', 'car-dealer' ),
				'search_items'  => __( 'بحث عن طلبات', 'car-dealer' ),
				'not_found'     => __( 'لم يتم العثور على طلبات', 'car-dealer' ),
				'not_found_in_trash' => __( 'لم يتم العثور على طلبات في المهملات', 'car-dealer' ),
			),
			'public'      => false,
			'show_ui'     => true,
			'capability_type' => 'post',
			'has_archive' => false,
			'menu_icon'   => 'dashicons-calendar-alt',
			'supports'    => array( 'title', 'editor' ),
		)
	);
}
add_action( 'init', 'car_dealer_register_test_drive_cpt' );

/**
 * إضافة الحقول المخصصة لطلبات تجربة القيادة
 */
function car_dealer_test_drive_custom_fields() {
	add_meta_box(
		'test_drive_details',
		'تفاصيل تجربة القيادة',
		'car_dealer_test_drive_details_callback',
		'test_drive_request',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'car_dealer_test_drive_custom_fields' );

/**
 * دالة رد الاتصال لعرض حقول تجربة القيادة المخصصة
 */
function car_dealer_test_drive_details_callback( WP_Post $post ) {
	// الحصول على القيم الحالية للحقول المخصصة
	$customer_name = get_post_meta( $post->ID, '_customer_name', true );
	$customer_email = get_post_meta( $post->ID, '_customer_email', true );
	$customer_phone = get_post_meta( $post->ID, '_customer_phone', true );
	$selected_car = get_post_meta( $post->ID, '_selected_car', true );
	$preferred_date = get_post_meta( $post->ID, '_preferred_date', true );
	$preferred_time = get_post_meta( $post->ID, '_preferred_time', true );
	$location = get_post_meta( $post->ID, '_location', true );
	$message = get_post_meta( $post->ID, '_message', true );

	// إضافة حقم nonce للتحقق من الأمان
	wp_nonce_field( 'car_dealer_save_test_drive_details', 'car_dealer_nonce' );

	// عرض حقول الإدخال
	echo '<table class="form-table">';
	echo '<tbody>';
	echo '<tr>';
	echo '<th><label for="_customer_name">الاسم الكامل</label></th>';
	echo '<td><input type="text" id="_customer_name" name="_customer_name" value="' . esc_attr( $customer_name ) . '" class="regular-text" required></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_customer_email">البريد الإلكتروني</label></th>';
	echo '<td><input type="email" id="_customer_email" name="_customer_email" value="' . esc_attr( $customer_email ) . '" class="regular-text" required></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_customer_phone">رقم الهاتف</label></th>';
	echo '<td><input type="tel" id="_customer_phone" name="_customer_phone" value="' . esc_attr( $customer_phone ) . '" class="regular-text" required></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_selected_car">السيارة المطلوبة</label></th>';
	echo '<td>';
	$car_args = array(
		'post_type'      => 'car',
		'posts_per_page' => -1,
	);
	$cars_query = new WP_Query( $car_args );

	echo '<select id="_selected_car" name="_selected_car" required>';
	echo '<option value="">' . __( 'اختر سيارة', 'car-dealer' ) . '</option>';

	if ( $cars_query->have_posts() ) {
		while ( $cars_query->have_posts() ) {
			$cars_query->the_post();
			echo '<option value="' . get_the_ID() . '" ' . selected( $selected_car, get_the_ID(), false ) . '>' . get_the_title() . '</option>';
		}
	}

	wp_reset_postdata();
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_preferred_date">التاريخ المفضل</label></th>';
	echo '<td><input type="date" id="_preferred_date" name="_preferred_date" value="' . esc_attr( $preferred_date ) . '" required></td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_preferred_time">الوقت المفضل</label></th>';
	echo '<td>';
	echo '<select id="_preferred_time" name="_preferred_time" required>';
	echo '<option value="">' . __( 'اختر وقت', 'car-dealer' ) . '</option>';
	echo '<option value="morning" ' . selected( $preferred_time, 'morning', false ) . '>' . __( 'صباحاً (9 ص - 12 م)', 'car-dealer' ) . '</option>';
	echo '<option value="afternoon" ' . selected( $preferred_time, 'afternoon', false ) . '>' . __( 'ظهراً (12 م - 4 م)', 'car-dealer' ) . '</option>';
	echo '<option value="evening" ' . selected( $preferred_time, 'evening', false ) . '>' . __( 'مساءً (4 م - 8 م)', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_location">مكان التجربة</label></th>';
	echo '<td>';
	echo '<select id="_location" name="_location" required>';
	echo '<option value="">' . __( 'اختر مكان', 'car-dealer' ) . '</option>';
	echo '<option value="showroom" ' . selected( $location, 'showroom', false ) . '>' . __( 'صالة العرض الرئيسية', 'car-dealer' ) . '</option>';
	echo '<option value="branch1" ' . selected( $location, 'branch1', false ) . '>' . __( 'فرع الرياض', 'car-dealer' ) . '</option>';
	echo '<option value="branch2" ' . selected( $location, 'branch2', false ) . '>' . __( 'فرع جدة', 'car-dealer' ) . '</option>';
	echo '<option value="branch3" ' . selected( $location, 'branch3', false ) . '>' . __( 'فرع الدمام', 'car-dealer' ) . '</option>';
	echo '</select>';
	echo '</td>';
	echo '</tr>';
	echo '<tr>';
	echo '<th><label for="_message">ملاحظات إضافية</label></th>';
	echo '<td><textarea id="_message" name="_message" rows="4">' . esc_textarea( $message ) . '</textarea></td>';
	echo '</tr>';
	echo '</tbody>';
	echo '</table>';
}

/**
 * حفظ حقول تجربة القيادة المخصصة
 */
function car_dealer_save_test_drive_details( int $post_id ) {
	// التحقق من nonce
	if ( ! isset( $_POST['car_dealer_nonce'] ) || ! wp_verify_nonce( $_POST['car_dealer_nonce'], 'car_dealer_save_test_drive_details' ) ) {
		return;
	}

	// التحقق من المستخدم لديه الصلاحيات
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// حفظ القيم
	if ( isset( $_POST['_customer_name'] ) ) {
		update_post_meta( $post_id, '_customer_name', sanitize_text_field( $_POST['_customer_name'] ) );
	}

	if ( isset( $_POST['_customer_email'] ) ) {
		update_post_meta( $post_id, '_customer_email', sanitize_email( $_POST['_customer_email'] ) );
	}

	if ( isset( $_POST['_customer_phone'] ) ) {
		update_post_meta( $post_id, '_customer_phone', sanitize_text_field( $_POST['_customer_phone'] ) );
	}

	if ( isset( $_POST['_selected_car'] ) ) {
		update_post_meta( $post_id, '_selected_car', intval( $_POST['_selected_car'] ) );
	}

	if ( isset( $_POST['_preferred_date'] ) ) {
		update_post_meta( $post_id, '_preferred_date', sanitize_text_field( $_POST['_preferred_date'] ) );
	}

	if ( isset( $_POST['_preferred_time'] ) ) {
		update_post_meta( $post_id, '_preferred_time', sanitize_text_field( $_POST['_preferred_time'] ) );
	}

	if ( isset( $_POST['_location'] ) ) {
		update_post_meta( $post_id, '_location', sanitize_text_field( $_POST['_location'] ) );
	}

	if ( isset( $_POST['_message'] ) ) {
		update_post_meta( $post_id, '_message', sanitize_textarea_field( $_POST['_message'] ) );
	}
}
add_action( 'save_post', 'car_dealer_save_test_drive_details' );

/**
 * إضافة نموذج حجز تجربة القيادة في صفحة السيارة
 */
function car_dealer_test_drive_booking_form() {
	global $post;

	if ( $post->post_type !== 'car' ) {
		return;
	}

	?>
	<div class="test-drive-booking">
		<h3><?php _e( 'حجز تجربة قيادة', 'car-dealer' ); ?></h3>
		<form id="test-drive-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="submit_test_drive">
			<input type="hidden" name="car_id" value="<?php echo esc_attr( $post->ID ); ?>">

			<div class="form-group">
				<label for="customer_name"><?php _e( 'الاسم الكامل', 'car-dealer' ); ?> *</label>
				<input type="text" id="customer_name" name="customer_name" required>
			</div>

			<div class="form-group">
				<label for="customer_email"><?php _e( 'البريد الإلكتروني', 'car-dealer' ); ?> *</label>
				<input type="email" id="customer_email" name="customer_email" required>
			</div>

			<div class="form-group">
				<label for="customer_phone"><?php _e( 'رقم الهاتف', 'car-dealer' ); ?> *</label>
				<input type="tel" id="customer_phone" name="customer_phone" required>
			</div>

			<div class="form-group">
				<label for="preferred_date"><?php _e( 'التاريخ المفضل', 'car-dealer' ); ?> *</label>
				<input type="date" id="preferred_date" name="preferred_date" required>
			</div>

			<div class="form-group">
				<label for="preferred_time"><?php _e( 'الوقت المفضل', 'car-dealer' ); ?> *</label>
				<select id="preferred_time" name="preferred_time" required>
					<option value=""><?php _e( 'اختر وقت', 'car-dealer' ); ?></option>
					<option value="morning"><?php _e( 'صباحاً (9 ص - 12 م)', 'car-dealer' ); ?></option>
					<option value="afternoon"><?php _e( 'ظهراً (12 م - 4 م)', 'car-dealer' ); ?></option>
					<option value="evening"><?php _e( 'مساءً (4 م - 8 م)', 'car-dealer' ); ?></option>
				</select>
			</div>

			<div class="form-group">
				<label for="location"><?php _e( 'مكان التجربة', 'car-dealer' ); ?> *</label>
				<select id="location" name="location" required>
					<option value=""><?php _e( 'اختر مكان', 'car-dealer' ); ?></option>
					<option value="showroom"><?php _e( 'صالة العرض الرئيسية', 'car-dealer' ); ?></option>
					<option value="branch1"><?php _e( 'فرع الرياض', 'car-dealer' ); ?></option>
					<option value="branch2"><?php _e( 'فرع جدة', 'car-dealer' ); ?></option>
					<option value="branch3"><?php _e( 'فرع الدمام', 'car-dealer' ); ?></option>
				</select>
			</div>

			<div class="form-group">
				<label for="message"><?php _e( 'ملاحظات إضافية', 'car-dealer' ); ?></label>
				textarea id="message" name="message" rows="4"></textarea>
			</div>

			<button type="submit" class="btn"><?php _e( 'إرسال الطلب', 'car-dealer' ); ?></button>
		</form>
	</div>
	<?php
}
add_action( 'single_car_after_details', 'car_dealer_test_drive_booking_form' );

/**
- معالجة إرسال نموذج تجربة القيادة
 */
function car_dealer_handle_test_drive_submission() {
	// التحقق من الصلاحيات
	if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'submit_test_drive' ) {
		return;
	}

	// التحقق من البيانات المطلوبة
	if ( empty( $_POST['customer_name'] ) || empty( $_POST['customer_email'] ) || 
		 empty( $_POST['customer_phone'] ) || empty( $_POST['preferred_date'] ) || 
		 empty( $_POST['preferred_time'] ) || empty( $_POST['location'] ) ) {
		wp_die( __( 'يرجى ملء جميع الحقول المطلوبة', 'car-dealer' ) );
	}

	// الحصول على البيانات
	$car_id = isset( $_POST['car_id'] ) ? intval( $_POST['car_id'] ) : 0;
	$customer_name = sanitize_text_field( $_POST['customer_name'] );
	$customer_email = sanitize_email( $_POST['customer_email'] );
	$customer_phone = sanitize_text_field( $_POST['customer_phone'] );
	$preferred_date = sanitize_text_field( $_POST['preferred_date'] );
	$preferred_time = sanitize_text_field( $_POST['preferred_time'] );
	$location = sanitize_text_field( $_POST['location'] );
	$message = sanitize_textarea_field( $_POST['message'] );

	// إنشاء منشور جديد لطلب تجربة القيادة
	$post_data = array(
		'post_title'   => sprintf( __( 'طلب تجربة قيادة لـ %s', 'car-dealer' ), get_the_title( $car_id ) ),
		'post_content' => $message,
		'post_status'  => 'pending',
		'post_type'    => 'test_drive_request',
	);

	$post_id = wp_insert_post( $post_data );

	if ( $post_id ) {
	 // حفظ البيانات المخصصة
		update_post_meta( $post_id, '_customer_name', $customer_name );
		update_post_meta( $post_id, '_customer_email', $customer_email );
		update_post_meta( $post_id, '_customer_phone', $customer_phone );
		update_post_meta( $post_id, '_selected_car', $car_id );
		update_post_meta( $post_id, '_preferred_date', $preferred_date );
		update_post_meta( $post_id, '_preferred_time', $preferred_time );
		update_post_meta( $post_id, '_location', $location );
		update_post_meta( $post_id, '_message', $message );

		// إرسال إشعار البريد الإلكتروني للعميل
		$customer_subject = __( 'تم استلام طلب تجربة القيادة', 'car-dealer' );
		$customer_message = sprintf(
			__( "مرحباً %s،

تم استلام طلبك لتجربة قيادة السيارة %s بنجاح. سيتواصل معك فريق قريباً لتأكيد موعدك.

مع تحيات فريق %s", 'car-dealer' ),
			$customer_name,
			get_the_title( $car_id ),
			get_bloginfo( 'name' )
		);

		wp_mail( $customer_email, $customer_subject, $customer_message );

		// إرسال إشعار البريد الإلكتروني للمدير
		$admin_subject = __( 'طلب جديد لتجربة القيادة', 'car-dealer' );
		$admin_message = sprintf(
			__( "تم استلام طلب جديد لتجربة القيادة:

العميل: %s
البريد الإلكتروني: %s
رقم الهاتف: %s
السيارة: %s
التاريخ المفضل: %s
الوقت المفضل: %s
المكان: %s

ملاحظات: %s", 'car-dealer' ),
			$customer_name,
			$customer_email,
			$customer_phone,
			get_the_title( $car_id ),
			$preferred_date,
			$preferred_time,
			$location,
			$message
		);

		wp_mail( get_option( 'admin_email' ), $admin_subject, $admin_message );

		// إظهار رسالة النجاح
		wp_redirect( add_query_arg( 'test-drive-success', '1', get_permalink( $car_id ) ) );
		exit;
	} else {
		wp_redirect( add_query_arg( 'test-drive-error', '1', get_permalink( $car_id ) ) );
		exit;
	}
}
add_action( 'admin_post_submit_test_drive', 'car_dealer_handle_test_drive_submission' );
add_action( 'admin_post_nopriv_submit_test_drive', 'car_dealer_handle_test_drive_submission' );

/**
- عرض رسائل النجاح والخطأ بعد تقديم النموذج
 */
function car_dealer_display_test_drive_messages() {
	if ( isset( $_GET['test-drive-success'] ) ) {
		echo '<div class="success-message">' . __( 'تم إرسال طلب تجربة القيادة بنجاح. سيتواصل معك فريقنا قريباً.', 'car-dealer' ) . '</div>';
	}

	if ( isset( $_GET['test-drive-error'] ) ) {
		echo '<div class="error-message">' . __( 'حدث خطأ أثناء إرسال طلبك. يرجى المحاولة مرة أخرى.', 'car-dealer' ) . '</div>';
	}
}
add_action( 'single_car_after_form', 'car_dealer_display_test_drive_messages' );
