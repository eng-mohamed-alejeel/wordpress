<?php
/** Theme bootstrap for Car Dealer. */
defined( 'ABSPATH' ) || exit;

function car_dealer_setup() {
	load_theme_textdomain( 'car-dealer', get_template_directory() . '/languages' );
	add_theme_support( 'title-tag' ); add_theme_support( 'post-thumbnails' ); add_theme_support( 'responsive-embeds' ); add_theme_support( 'align-wide' );
	add_theme_support( 'custom-logo', array( 'height' => 60, 'width' => 240, 'flex-height' => true, 'flex-width' => true ) );
	add_theme_support( 'html5', array( 'comment-list', 'comment-form', 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_image_size( 'car-card', 720, 460, true );
	register_nav_menus( array( 'primary' => __( 'القائمة الرئيسية', 'car-dealer' ), 'footer' => __( 'قائمة التذييل', 'car-dealer' ) ) );
}
add_action( 'after_setup_theme', 'car_dealer_setup' );

function car_dealer_assets() {
	$version = wp_get_theme()->get( 'Version' ); $uri = get_template_directory_uri();
	wp_enqueue_style( 'car-dealer-font', 'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap', array(), null );
	wp_enqueue_style( 'car-dealer-style', get_stylesheet_uri(), array( 'car-dealer-font' ), $version );
	wp_enqueue_style( 'car-dealer-enhancements', $uri . '/assets/css/main.css', array( 'car-dealer-style' ), $version );
	wp_enqueue_script( 'car-dealer-main', $uri . '/assets/js/main.js', array(), filemtime( get_template_directory() . '/assets/js/main.js' ), true );
	wp_localize_script( 'car-dealer-main', 'carDealer', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'car_dealer_frontend' ) ) );
}
add_action( 'wp_enqueue_scripts', 'car_dealer_assets' );

function car_dealer_register_content() {
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
	$version = wp_get_theme()->get( 'Version' ) . '-autobrands-2';
	if ( get_option( 'car_dealer_rewrite_version' ) === $version ) { return; }
	car_dealer_register_content();
	flush_rewrite_rules( false );
	update_option( 'car_dealer_rewrite_version', $version );
}
add_action( 'admin_init', 'car_dealer_maybe_flush_rewrites' );
add_action( 'after_switch_theme', 'car_dealer_maybe_flush_rewrites' );

function car_dealer_car_meta_box() { add_meta_box( 'car-details', __( 'تفاصيل السيارة', 'car-dealer' ), 'car_dealer_car_meta_box_html', 'car', 'normal', 'high' ); }
add_action( 'add_meta_boxes', 'car_dealer_car_meta_box' );

function car_dealer_car_meta_box_html( $post ) {
	wp_nonce_field( 'car_dealer_save_car', 'car_dealer_car_nonce' );
	$text_fields = array(
		'_car_make' => 'الشركة المصنعة',
		'_car_model' => 'الموديل',
		'_car_trim' => 'الفئة / الإصدار',
		'_car_color' => 'اللون الخارجي',
		'_car_interior_color' => 'اللون الداخلي',
		'_car_vin' => 'رقم الهيكل VIN',
		'_car_stock_number' => 'رقم المخزون',
		'_car_engine' => 'المحرك',
		'_car_drivetrain' => 'نظام الدفع',
		'_car_warranty' => 'الضمان',
		'_car_location' => 'موقع السيارة',
		'_car_video_url' => 'رابط فيديو',
	);
	$number_fields = array(
		'_car_price' => 'السعر (ر.س)',
		'_car_old_price' => 'السعر قبل العرض',
		'_car_monthly_payment' => 'قسط يبدأ من (ر.س)',
		'_car_year' => 'سنة الصنع',
		'_car_kilometers' => 'الممشى (كم)',
		'_car_doors' => 'عدد الأبواب',
		'_car_seats' => 'عدد المقاعد',
	);
	$select_fields = array(
		'_car_transmission' => array( 'ناقل الحركة', array( '' => '—', 'automatic' => 'أوتوماتيكي', 'manual' => 'يدوي' ) ),
		'_car_fuel_type' => array( 'نوع الوقود', array( '' => '—', 'gasoline' => 'بنزين', 'diesel' => 'ديزل', 'hybrid' => 'هجين', 'electric' => 'كهربائي' ) ),
		'_car_condition' => array( 'الحالة', array( '' => '—', 'new' => 'جديدة', 'used' => 'مستعملة' ) ),
		'_car_body_type' => array( 'نوع السيارة', array( '' => '—', 'sedan' => 'سيدان', 'suv' => 'SUV', 'pickup' => 'بيك أب', 'van' => 'فان', 'coupe' => 'كوبيه', 'hatchback' => 'هاتشباك' ) ),
		'_car_inventory_status' => array( 'حالة المخزون', array( 'available' => 'متوفر', 'reserved' => 'محجوز', 'sold' => 'مباع', 'pending' => 'قيد التجهيز' ) ),
		'_car_demand' => array( 'الأكثر طلباً', array( '' => 'لا', 'yes' => 'نعم' ) ),
	);
	echo '<div class="cd-car-fields">';
	echo '<h3>بيانات السيارة الأساسية</h3><div class="cd-car-field-grid">';
	foreach ( $text_fields as $key => $label ) {
		printf( '<label><span>%2$s</span><input type="text" name="%1$s" value="%3$s"></label>', esc_attr( $key ), esc_html( $label ), esc_attr( get_post_meta( $post->ID, $key, true ) ) );
	}
	foreach ( $number_fields as $key => $label ) {
		printf( '<label><span>%2$s</span><input type="number" min="0" name="%1$s" value="%3$s"></label>', esc_attr( $key ), esc_html( $label ), esc_attr( get_post_meta( $post->ID, $key, true ) ) );
	}
	foreach ( $select_fields as $key => $data ) {
		$value = get_post_meta( $post->ID, $key, true );
		echo '<label><span>' . esc_html( $data[0] ) . '</span><select name="' . esc_attr( $key ) . '">';
		foreach ( $data[1] as $option => $text ) { echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $text ) . '</option>'; }
		echo '</select></label>';
	}
	echo '</div>';
	echo '<h3>بيانات التسويق والتمويل</h3><div class="cd-car-field-grid">';
	foreach ( array( '_car_finance_provider' => 'جهة التمويل', '_car_down_payment' => 'الدفعة الأولى', '_car_offer_text' => 'نص العرض المختصر' ) as $key => $label ) {
		printf( '<label><span>%2$s</span><input type="text" name="%1$s" value="%3$s"></label>', esc_attr( $key ), esc_html( $label ), esc_attr( get_post_meta( $post->ID, $key, true ) ) );
	}
	echo '</div>';
	printf( '<label class="cd-car-wide"><span>%1$s</span><textarea name="_car_gallery_urls" rows="4" placeholder="%2$s">%3$s</textarea></label>', esc_html__( 'روابط صور إضافية، كل رابط في سطر', 'car-dealer' ), esc_attr__( 'https://example.com/image.jpg', 'car-dealer' ), esc_textarea( get_post_meta( $post->ID, '_car_gallery_urls', true ) ) );
	echo '</div>';
}

function car_dealer_save_car_meta( $post_id ) {
	if ( ! isset( $_POST['car_dealer_car_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['car_dealer_car_nonce'] ) ), 'car_dealer_save_car' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
	foreach ( array( '_car_price', '_car_old_price', '_car_monthly_payment', '_car_year', '_car_kilometers', '_car_doors', '_car_seats' ) as $key ) { if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, absint( $_POST[ $key ] ) ); } }
	foreach ( array( '_car_make', '_car_model', '_car_trim', '_car_color', '_car_interior_color', '_car_vin', '_car_stock_number', '_car_engine', '_car_drivetrain', '_car_warranty', '_car_location', '_car_video_url', '_car_finance_provider', '_car_down_payment', '_car_offer_text' ) as $key ) { if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ); } }
	foreach ( array( '_car_transmission', '_car_fuel_type', '_car_condition', '_car_body_type', '_car_inventory_status', '_car_demand' ) as $key ) { if ( isset( $_POST[ $key ] ) ) { update_post_meta( $post_id, $key, sanitize_key( wp_unslash( $_POST[ $key ] ) ) ); } }
	if ( isset( $_POST['_car_gallery_urls'] ) ) { update_post_meta( $post_id, '_car_gallery_urls', sanitize_textarea_field( wp_unslash( $_POST['_car_gallery_urls'] ) ) ); }
}
add_action( 'save_post_car', 'car_dealer_save_car_meta' );

function car_dealer_format_price( $price ) { return '' === (string) $price ? '' : number_format_i18n( (float) $price ) . ' ' . __( 'ر.س', 'car-dealer' ); }
function car_dealer_whatsapp_url( $message = '' ) {
	$options = function_exists( 'car_dealer_theme_options' ) ? car_dealer_theme_options() : array();
	$phone = preg_replace( '/\D+/', '', $options['whatsapp'] ?? '' );
	if ( ! $phone ) { return '#'; }
	return 'https://wa.me/' . $phone . ( $message ? '?text=' . rawurlencode( $message ) : '' );
}
function car_dealer_setting( $key, $default = '' ) { return get_theme_mod( 'car_dealer_' . $key, $default ); }
/** Backward-compatible accessor for settings used by existing page templates. */
function car_dealer_get_setting( $key, $default = '', $group = 'general' ) { return car_dealer_setting( $key, $default ); }

function car_dealer_pre_get_posts( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'car' ) ) { return; }
	$query->set( 'posts_per_page', 12 ); $meta_query = array();
	foreach ( array( 'min_price' => '>=', 'max_price' => '<=' ) as $param => $compare ) { if ( isset( $_GET[ $param ] ) && '' !== $_GET[ $param ] ) { $meta_query[] = array( 'key' => '_car_price', 'value' => absint( $_GET[ $param ] ), 'type' => 'NUMERIC', 'compare' => $compare ); } }
	if ( isset( $_GET['min_year'] ) && '' !== $_GET['min_year'] ) { $meta_query[] = array( 'key' => '_car_year', 'value' => absint( $_GET['min_year'] ), 'type' => 'NUMERIC', 'compare' => '>=' ); }
	if ( isset( $_GET['model'] ) && '' !== $_GET['model'] ) { $meta_query[] = array( 'key' => '_car_model', 'value' => sanitize_text_field( wp_unslash( $_GET['model'] ) ), 'compare' => 'LIKE' ); }
	if ( isset( $_GET['fuel'] ) && '' !== $_GET['fuel'] ) { $meta_query[] = array( 'key' => '_car_fuel_type', 'value' => sanitize_key( wp_unslash( $_GET['fuel'] ) ) ); }
	if ( isset( $_GET['transmission'] ) && '' !== $_GET['transmission'] ) { $meta_query[] = array( 'key' => '_car_transmission', 'value' => sanitize_key( wp_unslash( $_GET['transmission'] ) ) ); }
	if ( $meta_query ) { $query->set( 'meta_query', $meta_query ); }
}
add_action( 'pre_get_posts', 'car_dealer_pre_get_posts' );

require_once get_template_directory() . '/inc/admin-dashboard.php';
require_once get_template_directory() . '/inc/vehicle-editor.php';
require_once get_template_directory() . '/inc/vehicle-list.php';
require_once get_template_directory() . '/inc/white-label.php';
require_once get_template_directory() . '/inc/advanced-features.php';
require_once get_template_directory() . '/inc/testimonials.php';
require_once get_template_directory() . '/inc/contact-form-manager.php';
require_once get_template_directory() . '/inc/crm.php';
require_once get_template_directory() . '/inc/accounts.php';
require_once get_template_directory() . '/inc/customer-workflow.php';
require_once get_template_directory() . '/inc/vehicle-comparison.php';
require_once get_template_directory() . '/inc/loan-calculator.php';
require_once get_template_directory() . '/inc/schema-markup.php';
require_once get_template_directory() . '/inc/customization-manager.php';
require_once get_template_directory() . '/inc/inventory-management.php';
require_once get_template_directory() . '/inc/advanced-search.php';
require_once get_template_directory() . '/inc/social-media-integration.php';
require_once get_template_directory() . '/inc/performance-optimization.php';
require_once get_template_directory() . '/inc/offers.php';
require_once get_template_directory() . '/inc/auto-pages.php';
