<?php
/** Compatibility-only theme registry, vehicle editor and public handlers. */
defined( 'ABSPATH' ) || exit;

/** The prior shortcode query is available only during controlled shortcode rollback. */
function car_dealer_shortcode_cars_legacy_ids( $atts ): array {
	$atts = shortcode_atts( array( 'count' => 6, 'featured' => '' ), $atts );
	$args = array( 'post_type' => 'car', 'post_status' => 'publish', 'posts_per_page' => min( 48, max( 1, absint( $atts['count'] ) ) ) );
	if ( function_exists( 'car_dealer_catalog_is_authoritative' ) && car_dealer_catalog_is_authoritative() ) {
		$args['adc_public_catalog'] = true;
		$args['adc_catalog_filters'] = array( 'sort' => 'newest' );
	} elseif ( '' !== $atts['featured'] ) {
		$args['meta_query'] = array( array( 'key' => '_car_featured', 'value' => absint( $atts['featured'] ) ) );
	}
	$query = new \WP_Query( $args );
	return wp_list_pluck( $query->posts, 'ID' );
}

function car_dealer_get_comparison_legacy(): array {
	$raw = isset( $_COOKIE['car_dealer_comparison'] ) && is_string( $_COOKIE['car_dealer_comparison'] ) ? wp_unslash( $_COOKIE['car_dealer_comparison'] ) : '';
	$ids = json_decode( $raw, true );
	if ( ! is_array( $ids ) ) { $ids = explode( ',', $raw ); }
	return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 4 );
}

function car_dealer_loan_calculator_legacy_model( int $price ): array {
	return array( 'price' => $price, 'down_payment' => 0, 'annual_rate' => '4.50', 'months' => 60, 'max_amount' => 100000000, 'max_months' => 120, 'max_rate' => '100.00' );
}

function car_dealer_register_content() {
	if ( function_exists( 'adc_core_owns_content_registry' ) && adc_core_owns_content_registry() ) {
		return;
	}

	register_post_type( 'car', array(
		'labels' => array(
			'name' => __( 'السيارات', 'car-dealer' ),
			'singular_name' => __( 'سيارة', 'car-dealer' ),
			'menu_name' => __( 'السيارات', 'car-dealer' ),
			'name_admin_bar' => __( 'سيارة', 'car-dealer' ),
			'add_new' => __( 'إضافة سيارة', 'car-dealer' ),
			'add_new_item' => __( 'إضافة سيارة جديدة', 'car-dealer' ),
			'new_item' => __( 'سيارة جديدة', 'car-dealer' ),
			'edit_item' => __( 'تعديل السيارة', 'car-dealer' ),
			'view_item' => __( 'عرض السيارة', 'car-dealer' ),
			'all_items' => __( 'كل السيارات', 'car-dealer' ),
			'search_items' => __( 'البحث في السيارات', 'car-dealer' ),
			'not_found' => __( 'لا توجد سيارات', 'car-dealer' ),
			'not_found_in_trash' => __( 'لا توجد سيارات في سلة المهملات', 'car-dealer' ),
			'featured_image' => __( 'صورة السيارة الرئيسية', 'car-dealer' ),
			'set_featured_image' => __( 'تعيين صورة السيارة', 'car-dealer' ),
			'remove_featured_image' => __( 'إزالة صورة السيارة', 'car-dealer' ),
			'use_featured_image' => __( 'استخدام كصورة السيارة', 'car-dealer' ),
			'item_published' => __( 'تم نشر السيارة', 'car-dealer' ),
			'item_updated' => __( 'تم تحديث السيارة', 'car-dealer' ),
		),
		'public' => true,
		'has_archive' => true,
		'rewrite' => array( 'slug' => 'cars' ),
		'menu_icon' => 'dashicons-car',
		'show_in_menu' => 'car-dealer-dashboard',
		'show_in_rest' => true,
		'capability_type' => array( 'car', 'cars' ),
		'map_meta_cap' => true,
		'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
	) );
	register_taxonomy( 'car_brand', 'car', array( 'labels' => array( 'name' => __( 'الماركات', 'car-dealer' ), 'singular_name' => __( 'ماركة', 'car-dealer' ), 'add_new_item' => __( 'إضافة ماركة جديدة', 'car-dealer' ), 'edit_item' => __( 'تعديل الماركة', 'car-dealer' ), 'search_items' => __( 'البحث في الماركات', 'car-dealer' ) ), 'public' => true, 'show_in_rest' => true, 'rewrite' => array( 'slug' => 'car-brand' ), 'capabilities' => array( 'manage_terms' => 'manage_car_brands', 'edit_terms' => 'manage_car_brands', 'delete_terms' => 'manage_car_brands', 'assign_terms' => 'assign_car_brands' ) ) );
	register_taxonomy( 'car_category', 'car', array( 'labels' => array( 'name' => __( 'الفئات', 'car-dealer' ), 'singular_name' => __( 'فئة', 'car-dealer' ), 'add_new_item' => __( 'إضافة فئة جديدة', 'car-dealer' ), 'edit_item' => __( 'تعديل الفئة', 'car-dealer' ), 'search_items' => __( 'البحث في الفئات', 'car-dealer' ) ), 'public' => true, 'hierarchical' => true, 'show_in_rest' => true, 'rewrite' => array( 'slug' => 'car-category' ), 'capabilities' => array( 'manage_terms' => 'manage_car_categories', 'edit_terms' => 'manage_car_categories', 'delete_terms' => 'manage_car_categories', 'assign_terms' => 'assign_car_categories' ) ) );
}
add_action( 'init', 'car_dealer_register_content' );

function car_dealer_maybe_flush_rewrites() {
	if ( function_exists( 'adc_core_owns_content_registry' ) && adc_core_owns_content_registry() ) {
		return;
	}

	$version = wp_get_theme()->get( 'Version' ) . '-autobrands-2';
	if ( get_option( 'car_dealer_rewrite_version' ) === $version ) { return; }
	car_dealer_register_content();
	flush_rewrite_rules( false );
	update_option( 'car_dealer_rewrite_version', $version );
}
add_action( 'admin_init', 'car_dealer_maybe_flush_rewrites' );
add_action( 'after_switch_theme', 'car_dealer_maybe_flush_rewrites' );

function car_dealer_car_meta_box() { add_meta_box( 'car-details', __( 'تفاصيل السيارة', 'car-dealer' ), 'car_dealer_car_meta_box_html', 'car', 'normal', 'high' ); }
if ( ! function_exists( 'adc_core_owns_vehicle_post_editor' ) || ! adc_core_owns_vehicle_post_editor() ) {
	add_action( 'add_meta_boxes', 'car_dealer_car_meta_box' );
}

function car_dealer_car_meta_box_html( $post ) {
	wp_nonce_field( 'car_dealer_save_car', 'car_dealer_car_nonce' );
	$text_fields = array(
		'_car_price' => __( 'السعر', 'car-dealer' ),
		'_car_monthly_payment' => __( 'القسط الشهري', 'car-dealer' ),
		'_car_year' => __( 'سنة الصنع', 'car-dealer' ),
		'_car_model' => __( 'الموديل', 'car-dealer' ),
		'_car_color' => __( 'اللون', 'car-dealer' ),
		'_car_kilometers' => __( 'الممشي (كم)', 'car-dealer' ),
		'_car_transmission' => __( 'ناقل الحركة', 'car-dealer' ),
		'_car_fuel_type' => __( 'نوع الوقود', 'car-dealer' ),
		'_car_condition' => __( 'الحالة', 'car-dealer' ),
		'_car_inventory_status' => __( 'حالة المخزون', 'car-dealer' ),
	);
	foreach ( $text_fields as $key => $label ) {
		$value = get_post_meta( $post->ID, $key, true );
		echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input type="text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="width:100%"></p>';
	}
	$features = get_post_meta( $post->ID, '_car_features', true );
	echo '<p><label for="_car_features">' . esc_html__( 'المزايا (سطر لكل ميزة)', 'car-dealer' ) . '</label><br><textarea id="_car_features" name="_car_features" rows="5" style="width:100%">' . esc_textarea( $features ) . '</textarea></p>';
	echo '<p><label for="_car_featured"><input type="checkbox" id="_car_featured" name="_car_featured" value="1" ' . checked( get_post_meta( $post->ID, '_car_featured', true ), '1', false ) . '> ' . esc_html__( 'سيارة مميزة', 'car-dealer' ) . '</label></p>';
	echo '<p><label for="_car_demand"><input type="checkbox" id="_car_demand" name="_car_demand" value="yes" ' . checked( get_post_meta( $post->ID, '_car_demand', true ), 'yes', false ) . '> ' . esc_html__( 'سيارة مطلوبة', 'car-dealer' ) . '</label></p>';
}

function car_dealer_save_car_meta( $post_id ) {
	if ( ! isset( $_POST['car_dealer_car_nonce'] ) || ! wp_verify_nonce( $_POST['car_dealer_car_nonce'], 'car_dealer_save_car' ) ) { return; }
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
	if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
	$fields = array( '_car_price', '_car_monthly_payment', '_car_year', '_car_model', '_car_color', '_car_kilometers', '_car_transmission', '_car_fuel_type', '_car_condition', '_car_inventory_status', '_car_features' );
	foreach ( $fields as $field ) {
		if ( isset( $_POST[ $field ] ) ) { update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) ); }
	}
	update_post_meta( $post_id, '_car_featured', isset( $_POST['_car_featured'] ) ? '1' : '' );
	update_post_meta( $post_id, '_car_demand', isset( $_POST['_car_demand'] ) ? 'yes' : '' );
}
if ( ! function_exists( 'adc_core_owns_vehicle_post_editor' ) || ! adc_core_owns_vehicle_post_editor() ) {
	add_action( 'save_post', 'car_dealer_save_car_meta' );
}

function car_dealer_ajax_handler() {
	check_ajax_referer( 'car_dealer_frontend', 'nonce' );
	$type = sanitize_text_field( $_POST['type'] ?? '' );
	$car_id = absint( $_POST['car_id'] ?? 0 );
	$name = sanitize_text_field( $_POST['name'] ?? '' );
	$phone = sanitize_text_field( $_POST['phone'] ?? '' );
	$message = sanitize_textarea_field( $_POST['message'] ?? '' );
	if ( ! $name || ! $phone ) { wp_send_json_error( array( 'message' => __( 'يرجى إدخال الاسم والجوال', 'car-dealer' ) ) ); }
	$to = get_option( 'admin_email' );
	$subject = sprintf( __( 'طلب جديد: %s', 'car-dealer' ), $type );
	$body = sprintf( "الاسم: %s\nالجوال: %s\nالسيارة: %s\nملاحظات: %s", $name, $phone, get_the_title( $car_id ), $message );
	wp_mail( $to, $subject, $body );
	wp_send_json_success( array( 'message' => __( 'تم إرسال طلبك بنجاح', 'car-dealer' ) ) );
}
if ( ! function_exists( 'adc_core_owns_public_intake_actions' ) || ! adc_core_owns_public_intake_actions() ) {
	add_action( 'wp_ajax_car_dealer_lead', 'car_dealer_ajax_handler' );
	add_action( 'wp_ajax_nopriv_car_dealer_lead', 'car_dealer_ajax_handler' );
}

/** Rollback handler used only when plugin ownership is disabled before bootstrap. */
function car_dealer_legacy_comparison_ajax() {
	check_ajax_referer( 'car_dealer_frontend', 'nonce' );
	$car_id = absint( $_POST['car_id'] ?? 0 );
	if ( ! $car_id || 'car' !== get_post_type( $car_id ) || 'publish' !== get_post_status( $car_id ) ) { wp_send_json_error( array( 'message'=>__( 'السيارة غير صالحة.', 'car-dealer' ) ), 400 ); }
	$ids = car_dealer_get_comparison();
	$action = isset( $_POST['compare_action'] ) ? sanitize_key( wp_unslash( $_POST['compare_action'] ) ) : 'add';
	if ( 'remove' === $action ) { $ids = array_values( array_diff( $ids, array( $car_id ) ) ); }
	elseif ( ! in_array( $car_id, $ids, true ) && count( $ids ) < 4 ) { $ids[] = $car_id; }
	$value = wp_json_encode( $ids );
	setcookie( 'car_dealer_comparison', $value, time() + MONTH_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	$_COOKIE['car_dealer_comparison'] = $value;
	wp_send_json_success( array( 'count'=>count( $ids ), 'active'=>in_array( $car_id, $ids, true ), 'message'=>__( 'تم تحديث المقارنة.', 'car-dealer' ) ) );
}
if ( ! function_exists( 'adc_core_owns_public_tools_actions' ) || ! adc_core_owns_public_tools_actions() ) {
	add_action( 'wp_ajax_car_dealer_comparison', 'car_dealer_legacy_comparison_ajax' );
	add_action( 'wp_ajax_nopriv_car_dealer_comparison', 'car_dealer_legacy_comparison_ajax' );
}

