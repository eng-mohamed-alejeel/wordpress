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
	add_theme_support( 'page-templates' );
}
add_action( 'after_setup_theme', 'car_dealer_setup' );

// تسجيل قوالب الصفحات المخصصة
function car_dealer_register_page_templates( $templates ) {
	$templates['page-about.php'] = 'صفحة من نحن';
	$templates['page-contact.php'] = 'صفحة تواصل معنا';
	return $templates;
}
add_filter( 'theme_page_templates', 'car_dealer_register_page_templates', 10, 4 );

// تحميل ملفات صفحات من نحن وتواصل معنا (يجب تحميل contact-form-manager أولاً حتى يعمل الـ shortcode)
require_once get_template_directory() . '/inc/contact-form-manager.php';
require_once get_template_directory() . '/inc/about-contact-pages.php';

// تحميل نظام تسجيل الدخول والتسجيل (حساب المستخدم)
require_once get_template_directory() . '/inc/accounts.php';
require_once get_template_directory() . '/inc/crm.php';
require_once get_template_directory() . '/inc/customer-workflow.php';

function car_dealer_get_setting( $setting, $default = '', $type = 'display' ) {
	$settings = get_option( 'car_dealer_settings', array() );
	return isset( $settings[ $setting ] ) ? $settings[ $setting ] : $default;
}

function car_dealer_assets() {
	$version = wp_get_theme()->get( 'Version' ); $uri = get_template_directory_uri();
	wp_enqueue_style( 'car-dealer-font', 'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap', array(), null );
	wp_enqueue_style( 'car-dealer-style', get_stylesheet_uri(), array( 'car-dealer-font' ), $version );
	wp_enqueue_style( 'car-dealer-main', $uri . '/assets/css/main.css', array( 'car-dealer-style' ), $version );
	wp_enqueue_style( 'car-dealer-home-v2', $uri . '/assets/css/home-v2.css', array( 'car-dealer-main' ), $version );
	wp_enqueue_style( 'car-dealer-floating', $uri . '/assets/css/floating-buttons.css', array( 'car-dealer-main' ), $version );
	wp_enqueue_style( 'car-dealer-about', $uri . '/assets/css/pages/_about.css', array( 'car-dealer-main' ), $version );
	wp_enqueue_style( 'car-dealer-contact', $uri . '/assets/css/pages/_contact.css', array( 'car-dealer-main' ), $version );
	wp_enqueue_script( 'car-dealer-main-js', $uri . '/assets/js/main.js', array(), filemtime( get_template_directory() . '/assets/js/main.js' ), true );
	wp_localize_script( 'car-dealer-main-js', 'carDealer', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'car_dealer_frontend' ) ) );
	
	// Cookie Consent
	wp_enqueue_style( 'car-dealer-cookies', $uri . '/assets/css/components/_cookies.css', array( 'car-dealer-main' ), filemtime( get_template_directory() . '/assets/css/components/_cookies.css' ) );
	wp_enqueue_script( 'car-dealer-cookie-consent', $uri . '/assets/js/cookie-consent.js', array(), filemtime( get_template_directory() . '/assets/js/cookie-consent.js' ), true );
	wp_localize_script( 'car-dealer-cookie-consent', 'cdCookieConfig', array( 'templateUri' => $uri ) );
	
	// Footer Pro
	wp_enqueue_style( 'car-dealer-footer', $uri . '/assets/css/layout/_footer.css', array( 'car-dealer-main' ), filemtime( get_template_directory() . '/assets/css/layout/_footer.css' ) );
}
add_action( 'wp_enqueue_scripts', 'car_dealer_assets' );
add_filter( 'wp_nav_menu_args', function ( $args ) {
  if ( isset( $args['theme_location'] ) && 'primary' === $args['theme_location'] ) {
    $args['show_home'] = true;
    if ( ! isset( $args['menu_class'] ) ) {
      $args['menu_class'] = '';
    }
  }
  return $args;
} );

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
add_action( 'save_post', 'car_dealer_save_car_meta' );

function car_dealer_format_price( $price ) {
	if ( ! $price ) { return ''; }
	return number_format( $price ) . ' ريال';
}

function car_dealer_whatsapp_url( $message = '' ) {
	$phone = get_option( 'car_dealer_whatsapp', '' );
	if ( ! $phone ) { return '#'; }
	return 'https://wa.me/' . $phone . '?text=' . urlencode( $message );
}

function car_dealer_offer_card( $post_id = null ) {
	if ( ! $post_id ) { $post_id = get_the_ID(); }
	$monthly = get_post_meta( $post_id, '_offer_monthly_payment', true );
	$new_price = get_post_meta( $post_id, '_offer_new_price', true );
	$old_price = get_post_meta( $post_id, '_offer_old_price', true );
	?>
	<article class="offer-card">
		<?php if ( has_post_thumbnail( $post_id ) ) : ?>
			<a class="offer-image" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
				<?php echo get_the_post_thumbnail( $post_id, 'medium_large' ); ?>
			</a>
		<?php endif; ?>
		<div class="offer-content">
			<h3><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a></h3>
			<?php if ( $old_price ) : ?><p class="offer-old"><?php echo esc_html( car_dealer_format_price( $old_price ) ); ?></p><?php endif; ?>
			<?php if ( $new_price ) : ?><p class="offer-price"><?php echo esc_html( car_dealer_format_price( $new_price ) ); ?></p><?php endif; ?>
			<?php if ( $monthly ) : ?><p class="car-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
		</div>
	</article>
	<?php
}

function car_dealer_inventory_status_label( $status ) {
	$labels = array(
		'available' => __( 'متاحة', 'car-dealer' ),
		'reserved' => __( 'محجوزة', 'car-dealer' ),
		'sold' => __( 'مباعة', 'car-dealer' ),
	);
	return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
}

function car_dealer_comparison_button( $car_id ) {
	if ( ! function_exists( 'car_dealer_get_comparison' ) ) { return; }
	$comparison = car_dealer_get_comparison();
	$in_comparison = in_array( $car_id, $comparison );
	?>
	<a class="btn btn-outline btn-sm <?php echo $in_comparison ? 'is-active' : ''; ?>" href="#" data-compare="<?php echo esc_attr( $car_id ); ?>">
		<?php echo $in_comparison ? esc_html__( 'إزالة من المقارنة', 'car-dealer' ) : esc_html__( 'أضف للمقارنة', 'car-dealer' ); ?>
	</a>
	<?php
}

function car_dealer_social_share_buttons() {
	$url = urlencode( get_permalink() );
	$title = urlencode( get_the_title() );
	?>
	<div class="cd-social-share">
		<span><?php esc_html_e( 'مشاركة:', 'car-dealer' ); ?></span>
		<a href="https://wa.me/?text=<?php echo $title . ' ' . $url; ?>" target="_blank" rel="noopener">واتساب</a>
		<a href="https://twitter.com/intent/tweet?text=<?php echo $title; ?>&url=<?php echo $url; ?>" target="_blank" rel="noopener">تويتر</a>
		<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $url; ?>" target="_blank" rel="noopener">فيسبوك</a>
	</div>
	<?php
}

function car_dealer_lead_form( $car_id, $type = 'price_request', $title = '', $button = '' ) {
	if ( ! $title ) { $title = __( 'اطلب السعر', 'car-dealer' ); }
	if ( ! $button ) { $button = __( 'إرسال الطلب', 'car-dealer' ); }
	car_dealer_render_lead_form( absint( $car_id ), $type, $title, $button );
}

function car_dealer_booking_form( $car_id ) {
	car_dealer_render_booking_form( absint( $car_id ) );
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
add_action( 'wp_ajax_car_dealer_lead', 'car_dealer_ajax_handler' );
add_action( 'wp_ajax_nopriv_car_dealer_lead', 'car_dealer_ajax_handler' );

function car_dealer_shortcode_cars( $atts ) {
	$atts = shortcode_atts( array( 'count' => 6, 'featured' => '' ), $atts );
	$query = new WP_Query( array( 'post_type' => 'car', 'posts_per_page' => $atts['count'] ) );
	ob_start();
	if ( $query->have_posts() ) : ?>
		<div class="car-grid"><?php while ( $query->have_posts() ) { $query->the_post(); get_template_part( 'templates/components/car-card' ); } wp_reset_postdata(); ?></div>
	<?php endif;
	return ob_get_clean();
}
add_shortcode( 'car_dealer_cars', 'car_dealer_shortcode_cars' );

function car_dealer_shortcode_loan_calculator( $atts ) {
	$atts = shortcode_atts( array( 'price' => 0 ), $atts );
	$price = absint( $atts['price'] );
	ob_start();
	?>
	<div class="cd-tool">
		<h3><?php esc_html_e( 'حاسبة التمويل', 'car-dealer' ); ?></h3>
		<?php if ( $price ) : ?><p><?php printf( esc_html__( 'سعر السيارة: %s', 'car-dealer' ), esc_html( car_dealer_format_price( $price ) ) ); ?></p><?php endif; ?>
		<div class="cd-ajax-form" data-form-type="loan_calculator">
			<label><?php esc_html_e( 'مبلغ التمويل', 'car-dealer' ); ?><input type="number" name="amount" value="<?php echo esc_attr( $price ); ?>"></label>
			<label><?php esc_html_e( 'المدة (سنوات)', 'car-dealer' ); ?><select name="years"><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select></label>
			<label><?php esc_html_e( 'الدفعة الأولى', 'car-dealer' ); ?><input type="number" name="down_payment" value="0"></label>
			<button class="btn btn-primary" type="submit"><?php esc_html_e( 'احسب', 'car-dealer' ); ?></button>
			<div class="cd-loan-result"></div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'car_dealer_loan_calculator', 'car_dealer_shortcode_loan_calculator' );

function car_dealer_shortcode_testimonials( $atts ) {
	$atts = shortcode_atts( array( 'count' => 3 ), $atts );
	ob_start();
	?>
	<div class="cd-testimonials">
		<div class="container">
			<div class="cd-testimonial-grid">
				<div class="cd-testimonial">
					<p><?php esc_html_e( 'تجربة شراء ممتازة، فريق محترف وسيارات بجودة عالية.', 'car-dealer' ); ?></p>
					<strong><?php esc_html_e( 'أحمد محمد', 'car-dealer' ); ?></strong>
				</div>
				<div class="cd-testimonial">
					<p><?php esc_html_e( 'أفضل وكالة سيارات تعاملت معها، أنصح بها بشدة.', 'car-dealer' ); ?></p>
					<strong><?php esc_html_e( 'سارة علي', 'car-dealer' ); ?></strong>
				</div>
				<div class="cd-testimonial">
					<p><?php esc_html_e( 'خدمة ما بعد البيع ممتازة وضمان حقيقي.', 'car-dealer' ); ?></p>
					<strong><?php esc_html_e( 'خالد عبدالله', 'car-dealer' ); ?></strong>
				</div>
			</div>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'car_dealer_testimonials', 'car_dealer_shortcode_testimonials' );

function car_dealer_get_comparison() {
	return isset( $_COOKIE['car_dealer_comparison'] ) ? json_decode( stripslashes( $_COOKIE['car_dealer_comparison'] ), true ) : array();
}

// تحميل ملف enhanced-stats.css
function add_enhanced_stats_css() {
	wp_enqueue_style('enhanced-stats', get_template_directory_uri() . '/enhanced-stats.css');
}
add_action('wp_enqueue_scripts', 'add_enhanced_stats_css');
