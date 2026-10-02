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

/** Attach appearance to a durable shortcode registered by the core plugin. */
function car_dealer_register_shortcode_adapter( $tag, $callback, $accepts_atts = false, $accepts_model = false ) {
	add_filter( 'adc_shortcode_' . sanitize_key( $tag ) . '_html', static function ( $fallback, $model, $atts, $content, $registered_tag ) use ( $callback, $accepts_atts, $accepts_model ) {
		if ( ! is_callable( $callback ) ) { return $fallback; }
		$args = array();
		if ( $accepts_atts ) { $args[] = is_array( $atts ) ? $atts : array(); }
		if ( $accepts_model ) { $args[] = is_array( $model ) ? $model : array(); }
		$rendered = call_user_func_array( $callback, $args );
		return is_string( $rendered ) ? $rendered : $fallback;
	}, 10, 5 );
}

// تحميل ملفات صفحات من نحن وتواصل معنا (يجب تحميل contact-form-manager أولاً حتى يعمل الـ shortcode)
require_once get_template_directory() . '/inc/contact-form-manager.php';
require_once get_template_directory() . '/inc/about-contact-pages.php';

// واجهة الحساب والكتالوج تستخدم واجهات الإضافة العامة فقط.
require_once get_template_directory() . '/inc/accounts.php';
require_once get_template_directory() . '/inc/public-catalog.php';
require_once get_template_directory() . '/inc/customization-manager.php';

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
	
	// Cookie Consent
	wp_enqueue_style( 'car-dealer-cookies', $uri . '/assets/css/components/_cookies.css', array( 'car-dealer-main' ), filemtime( get_template_directory() . '/assets/css/components/_cookies.css' ) );
	wp_enqueue_script( 'car-dealer-cookie-consent', $uri . '/assets/js/cookie-consent.js', array(), filemtime( get_template_directory() . '/assets/js/cookie-consent.js' ), true );
	wp_localize_script( 'car-dealer-cookie-consent', 'cdCookieConfig', array( 'templateUri' => $uri ) );
	
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

function car_dealer_format_price( $price ) {
	if ( ! $price ) { return ''; }
	$amount = (float) $price;
	return number_format( $amount, floor( $amount ) === $amount ? 0 : 2 ) . ' ريال';
}

function car_dealer_whatsapp_url( $message = '' ) {
	$options = car_dealer_theme_options();
	$phone = preg_replace( '/\D+/', '', (string) ( $options['whatsapp'] ?: get_option( 'car_dealer_whatsapp', '' ) ) );
	if ( ! $phone ) { return '#'; }
	return 'https://wa.me/' . $phone . '?text=' . urlencode( $message );
}

/** Resolve editorial links by slug; an intentionally empty site has no fixed page IDs. */
function car_dealer_page_url( string $slug ): string {
	$page = get_page_by_path( sanitize_title( $slug ), OBJECT, 'page' );
	return $page instanceof WP_Post && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
}

/** Return an archive link only while its plugin-owned content type is registered. */
function car_dealer_archive_url( string $post_type ): string {
	$url = post_type_exists( $post_type ) ? get_post_type_archive_link( $post_type ) : false;
	return is_string( $url ) ? $url : '';
}

function car_dealer_footer_social_links(): void {
	$options = car_dealer_theme_options();
	$links = array_filter( array( 'facebook' => $options['facebook'], 'instagram' => $options['instagram'] ) );
	$phone = preg_replace( '/\D+/', '', (string) $options['whatsapp'] );
	if ( ! $links && ! $phone ) { return; }
	echo '<div class="cd-social-links">';
	foreach ( $links as $label => $url ) {
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( ucfirst( $label ) ) . '</a>';
	}
	if ( $phone ) { echo '<a href="' . esc_url( 'https://wa.me/' . $phone ) . '" target="_blank" rel="noopener">WhatsApp</a>'; }
	echo '</div>';
}

/** Public offers and editorial previews use plugin read models. */
function car_dealer_offer_view( int $post_id, bool $preview = false ): ?array {
	if ( $preview && function_exists( 'adc_public_offer_preview' ) ) {
		return adc_public_offer_preview( $post_id );
	}
	return function_exists( 'adc_public_offer_view' ) ? adc_public_offer_view( $post_id ) : null;
}

function car_dealer_offer_card( $post_id = null ) {
	if ( ! $post_id ) { $post_id = get_the_ID(); }
	$offer = car_dealer_offer_view( (int) $post_id );
	if ( ! $offer ) { return; }
	$monthly = $offer['monthly_payment'];
	$new_price = $offer['price'];
	$old_price = $offer['old_price'];
	$url = $offer['url'];
	$title = $offer['title'];
	$image = get_the_post_thumbnail( $post_id, 'medium_large' );
	if ( ! $image ) { $image = get_the_post_thumbnail( $offer['car_id'], 'medium_large' ); }
	?>
	<article class="offer-card">
		<?php if ( $image ) : ?>
			<a class="offer-image" href="<?php echo esc_url( $url ); ?>">
				<?php echo $image; ?>
			</a>
		<?php endif; ?>
		<div class="offer-content">
			<h3><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a></h3>
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
	$comparison = car_dealer_get_comparison();
	$in_comparison = in_array( absint( $car_id ), $comparison, true );
	$add_label = __( 'أضف للمقارنة', 'car-dealer' );
	$remove_label = __( 'إزالة من المقارنة', 'car-dealer' );
	?>
	<button class="btn btn-outline btn-sm cd-compare-button <?php echo $in_comparison ? 'is-active' : ''; ?>" type="button" data-car-id="<?php echo absint( $car_id ); ?>" data-add-label="<?php echo esc_attr( $add_label ); ?>" data-remove-label="<?php echo esc_attr( $remove_label ); ?>" aria-pressed="<?php echo $in_comparison ? 'true' : 'false'; ?>">
		<?php echo esc_html( $in_comparison ? $remove_label : $add_label ); ?>
	</button>
	<?php
}

function car_dealer_social_share_buttons() {
	$share_url = function_exists( 'car_dealer_catalog_localized_url' ) ? car_dealer_catalog_localized_url( get_permalink() ) : get_permalink();
	$url = urlencode( $share_url );
	$title = urlencode( get_the_title() );
	?>
	<div class="cd-social-share">
		<span><?php esc_html_e( 'مشاركة:', 'car-dealer' ); ?></span>
		<a href="https://wa.me/?text=<?php echo $title . ' ' . $url; ?>" target="_blank" rel="noopener"><?php esc_html_e( 'واتساب', 'car-dealer' ); ?></a>
		<a href="https://twitter.com/intent/tweet?text=<?php echo $title; ?>&url=<?php echo $url; ?>" target="_blank" rel="noopener"><?php esc_html_e( 'تويتر', 'car-dealer' ); ?></a>
		<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $url; ?>" target="_blank" rel="noopener"><?php esc_html_e( 'فيسبوك', 'car-dealer' ); ?></a>
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

function car_dealer_render_shortcode_car_ids( $post_ids, $classes = 'car-grid' ) {
	$post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
	if ( ! $post_ids ) { return ''; }
	global $post;
	ob_start();
	echo '<div class="' . esc_attr( $classes ) . '">';
	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'car' !== $post->post_type || 'publish' !== $post->post_status ) { continue; }
		setup_postdata( $post );
		get_template_part( 'templates/components/car-card' );
	}
	echo '</div>';
	wp_reset_postdata();
	return ob_get_clean();
}

function car_dealer_shortcode_cars( $atts, $model = array() ) {
	if ( isset( $model['post_ids'] ) && is_array( $model['post_ids'] ) ) {
		return car_dealer_render_shortcode_car_ids( $model['post_ids'] );
	}
	return '';
}
car_dealer_register_shortcode_adapter( 'car_dealer_cars', 'car_dealer_shortcode_cars', true, true );

function car_dealer_shortcode_loan_calculator( $atts, $model = array() ) {
	$atts = shortcode_atts( array( 'price' => 0 ), $atts, 'car_dealer_loan_calculator' );
	$price = absint( $atts['price'] );
	if ( ! isset( $model['price'], $model['down_payment'], $model['annual_rate'], $model['months'], $model['max_amount'], $model['max_months'], $model['max_rate'] ) ) {
		return '';
	}
	ob_start();
	?>
	<div class="cd-tool cd-loan-calculator">
		<h3><?php esc_html_e( 'حاسبة التمويل', 'car-dealer' ); ?></h3>
		<?php if ( $price ) : ?><p><?php printf( esc_html__( 'سعر السيارة: %s', 'car-dealer' ), esc_html( function_exists( 'car_dealer_catalog_format_price' ) ? car_dealer_catalog_format_price( $price ) : car_dealer_format_price( $price ) ) ); ?></p><?php endif; ?>
		<form data-loan-calculator>
			<div class="cd-form-grid">
				<label><?php esc_html_e( 'سعر السيارة', 'car-dealer' ); ?><input type="number" name="price" data-loan-price value="<?php echo esc_attr( $model['price'] ); ?>" min="1" max="<?php echo esc_attr( $model['max_amount'] ); ?>" required></label>
				<label><?php esc_html_e( 'الدفعة الأولى', 'car-dealer' ); ?><input type="number" name="down_payment" data-loan-down value="<?php echo esc_attr( $model['down_payment'] ); ?>" min="0" max="<?php echo esc_attr( $model['max_amount'] ); ?>" required></label>
				<label><?php esc_html_e( 'النسبة السنوية التقديرية %', 'car-dealer' ); ?><input type="number" name="annual_rate" data-loan-rate value="<?php echo esc_attr( $model['annual_rate'] ); ?>" min="0" max="<?php echo esc_attr( $model['max_rate'] ); ?>" step="0.01" required></label>
				<label><?php esc_html_e( 'المدة بالأشهر', 'car-dealer' ); ?><input type="number" name="months" data-loan-months value="<?php echo esc_attr( $model['months'] ); ?>" min="1" max="<?php echo esc_attr( $model['max_months'] ); ?>" required></label>
			</div>
			<button class="btn btn-primary" type="submit"><?php esc_html_e( 'احسب', 'car-dealer' ); ?></button>
			<p class="cd-loan-result"><?php esc_html_e( 'القسط الشهري التقديري:', 'car-dealer' ); ?> <strong data-loan-result>0 SAR</strong></p>
			<p data-loan-status role="status"></p>
			<small><?php esc_html_e( 'هذا تقدير إرشادي وليس عرض تمويل أو موافقة. تعتمد الشروط النهائية على مزود التمويل.', 'car-dealer' ); ?></small>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'car_dealer_loan_calculator', 'car_dealer_shortcode_loan_calculator', true, true );

function car_dealer_shortcode_testimonials( $atts ) {
	// Keep the shortcode contract without presenting invented customer endorsements.
	return '';
}
car_dealer_register_shortcode_adapter( 'car_dealer_testimonials', 'car_dealer_shortcode_testimonials', true, false );

function car_dealer_get_comparison() {
	return function_exists( 'adc_public_comparison_ids' ) ? adc_public_comparison_ids() : array();
}

function car_dealer_shortcode_comparison( $model = array() ) {
	$ids = isset( $model['post_ids'] ) && is_array( $model['post_ids'] ) ? $model['post_ids'] : car_dealer_get_comparison();
	if ( ! $ids ) { return '<p class="empty-state">' . esc_html__( 'لم تضف سيارات للمقارنة بعد.', 'car-dealer' ) . '</p>'; }
	return car_dealer_render_shortcode_car_ids( $ids, 'car-grid cd-comparison-grid' );
}
car_dealer_register_shortcode_adapter( 'car_dealer_comparison', 'car_dealer_shortcode_comparison', false, true );
