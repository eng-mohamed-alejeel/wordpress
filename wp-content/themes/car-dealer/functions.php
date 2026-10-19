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
	add_theme_support( 'adc-color-configurator' );
}
add_action( 'after_setup_theme', 'car_dealer_setup' );

// تسجيل قوالب الصفحات المخصصة
function car_dealer_register_page_templates( $templates ) {
	$templates['page-about.php'] = __( 'صفحة من نحن', 'car-dealer' );
	$templates['page-contact.php'] = __( 'صفحة تواصل معنا', 'car-dealer' );
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
require_once get_template_directory() . '/inc/localization.php';
require_once get_template_directory() . '/inc/script-compatibility.php';
require_once get_template_directory() . '/inc/customization-manager.php';
require_once get_template_directory() . '/inc/public-site-setup.php';
require_once get_template_directory() . '/inc/page-cache-integration.php';

/** Small first-paint fallback; logos stay bounded even if an external stylesheet fails. */
add_action('wp_head',static function(): void {
	$light='#FFFFFF';$dark='#0B1220';
	if(class_exists('\AutoDealership\Core\BrandPalette')){$light=\AutoDealership\Core\BrandPalette::resolve('light')['bg'];$dark=\AutoDealership\Core\BrandPalette::resolve('dark')['bg'];}
	echo '<style id="car-dealer-critical-layout">:root{--cd-initial-bg:'.esc_html($light).'}:root[data-ab-theme="dark"]{--cd-initial-bg:'.esc_html($dark).'}html{background:var(--ab-bg,var(--cd-initial-bg))}body{margin:0}.custom-logo{display:block;width:auto;max-width:100%;max-height:48px}.footer-brand .custom-logo{max-height:40px}.adc-about-site-logo{max-width:100%;max-height:180px}.adc-workspace-logo{max-width:148px;max-height:100px}[hidden]{display:none!important}</style>';
},0);

function car_dealer_assets() {
	$version = wp_get_theme()->get( 'Version' ); $uri = get_template_directory_uri();
	if ( class_exists( '\AutoDealership\Core\BrandIdentity' ) ) {
		\AutoDealership\Core\BrandIdentity::tokens();
	} elseif ( is_readable( WP_PLUGIN_DIR . '/auto-dealership-core/assets/css/brand-tokens.css' ) ) {
		wp_enqueue_style( 'autobrands-tokens', plugins_url( 'auto-dealership-core/assets/css/brand-tokens.css' ), array(), $version );
	}
	if ( class_exists( '\AutoDealership\Core\Typography' ) ) {
		\AutoDealership\Core\Typography::enqueue();
	} elseif ( is_readable( WP_PLUGIN_DIR . '/auto-dealership-core/assets/css/typography.css' ) ) {
		wp_enqueue_style( 'autobrands-tajawal', plugins_url( 'auto-dealership-core/assets/css/typography.css' ), array(), $version );
	}
	wp_enqueue_style( 'car-dealer-style', get_stylesheet_uri(), wp_style_is( 'autobrands-tokens', 'registered' ) ? array( 'autobrands-tokens' ) : array(), $version );
	wp_enqueue_style( 'car-dealer-main', $uri . '/assets/css/main.css', array( 'car-dealer-style' ), filemtime( get_template_directory() . '/assets/css/main.css' ) );
	wp_enqueue_style( 'car-dealer-home-v2', $uri . '/assets/css/home-v2.css', array( 'car-dealer-main' ), filemtime( get_template_directory() . '/assets/css/home-v2.css' ) );
	wp_enqueue_style( 'car-dealer-floating', $uri . '/assets/css/floating-buttons.css', array( 'car-dealer-main' ), $version );
	wp_enqueue_style( 'car-dealer-site-shell', $uri . '/assets/css/site-shell.css', array( 'car-dealer-floating' ), filemtime( get_template_directory() . '/assets/css/site-shell.css' ) );
	wp_enqueue_style( 'car-dealer-about', $uri . '/assets/css/pages/_about.css', array( 'car-dealer-main' ), $version );
	if ( is_page( 'about' ) || is_page_template( 'page-about.php' ) || ( is_singular() && has_block( 'auto-dealership/component' ) ) ) { wp_enqueue_style( 'car-dealer-about-modern', $uri . '/assets/css/about-modern.css', array( 'car-dealer-about', 'car-dealer-site-shell' ), filemtime( get_template_directory() . '/assets/css/about-modern.css' ) ); }
	wp_enqueue_style( 'car-dealer-contact', $uri . '/assets/css/pages/_contact.css', array( 'car-dealer-main' ), $version );
	wp_enqueue_script( 'car-dealer-main-js', $uri . '/assets/js/main.js', array(), filemtime( get_template_directory() . '/assets/js/main.js' ), true );
	if(is_singular('car')) {
		wp_enqueue_style('car-dealer-car-dialogs',$uri.'/assets/css/car-dialogs.css',array('car-dealer-site-shell'),filemtime(get_template_directory().'/assets/css/car-dialogs.css'));
		wp_enqueue_script('car-dealer-car-dialogs',$uri.'/assets/js/car-dialogs.js',array('car-dealer-main-js'),filemtime(get_template_directory().'/assets/js/car-dialogs.js'),true);
	}
	
	// Cookie Consent
	wp_enqueue_style( 'car-dealer-cookies', $uri . '/assets/css/components/_cookies.css', array( 'car-dealer-main' ), filemtime( get_template_directory() . '/assets/css/components/_cookies.css' ) );
	wp_enqueue_script( 'car-dealer-cookie-consent', $uri . '/assets/js/cookie-consent.js', array(), filemtime( get_template_directory() . '/assets/js/cookie-consent.js' ), true );
	wp_localize_script( 'car-dealer-cookie-consent', 'cdCookieConfig', array( 'templateUri' => $uri, 'language' => car_dealer_ui_language(), 'customizePreview' => is_customize_preview() ) );
	
}
add_action( 'wp_enqueue_scripts', 'car_dealer_assets' );

/** Add a consistent accessible password visibility control across public and WordPress screens. */
function car_dealer_password_toggle_script() {
	$path = get_template_directory() . '/assets/js/password-toggle.js';
	wp_enqueue_script( 'car-dealer-password-toggle', get_template_directory_uri() . '/assets/js/password-toggle.js', array(), filemtime( $path ), true );
}
add_action( 'wp_enqueue_scripts', 'car_dealer_password_toggle_script', 20 );

function car_dealer_password_toggle_wordpress_assets() {
	$style_path = get_template_directory() . '/assets/css/components/_password-toggle.css';
	wp_enqueue_style( 'car-dealer-password-toggle', get_template_directory_uri() . '/assets/css/components/_password-toggle.css', array(), filemtime( $style_path ) );
	car_dealer_password_toggle_script();
}
add_action( 'login_enqueue_scripts', 'car_dealer_password_toggle_wordpress_assets', 20 );
add_action( 'admin_enqueue_scripts', 'car_dealer_password_toggle_wordpress_assets', 20 );

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
	return 'en' === car_dealer_catalog_language()
		? 'SAR ' . number_format( $amount, floor( $amount ) === $amount ? 0 : 2 )
		: number_format( $amount, floor( $amount ) === $amount ? 0 : 2 ) . ' ريال';
}

/** SVG fallback when a vehicle has no approved public image. */
function car_dealer_vehicle_placeholder(): string {
	return '<svg viewBox="0 0 160 80" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 54h130l-8-20c-2-5-7-8-12-8H42c-5 0-9 2-12 7L15 54Z"/><path d="M45 27l-12 27M115 27l12 27M16 54v8h13m102 0h14v-8M58 54h44"/><circle cx="43" cy="60" r="10"/><circle cx="117" cy="60" r="10"/></svg>';
}

/** Fixed local vector shared by WhatsApp calls to action. */
function car_dealer_whatsapp_icon(): string {
	return '<svg class="btn-icon btn-icon-whatsapp" viewBox="0 0 64 64" fill="currentColor" aria-hidden="true" focusable="false"><path d="M32 4C16.536 4 4 16.536 4 32c0 5.336 1.392 10.36 3.84 14.72L4 60l13.76-3.68A27.84 27.84 0 0032 60c15.464 0 28-12.536 28-28S47.464 4 32 4zm14.08 38.56c-.64 1.28-2.88 2.4-4 2.56-1.12.16-2.56.16-4.16-.48-1.6-.64-3.52-1.44-5.92-2.88-5.12-3.04-8.32-8.16-8.64-8.64-.32-.48-2.56-3.36-2.56-6.4 0-3.04 1.6-4.48 2.24-5.12.64-.64 1.28-.64 1.76-.64h1.28c.48 0 1.12 0 1.6 1.12.48 1.28 1.76 4.32 1.92 4.64.16.32.16.64 0 .96-.16.32-.32.64-.64.96-.32.32-.64.8-.96 1.12-.32.32-.64.64-.32 1.28.32.64 1.6 2.88 3.52 4.8 2.24 2.24 4 3.04 4.64 3.36.64.32 1.12.32 1.44-.16.32-.48 1.6-1.92 2.08-2.56.48-.64.96-.48 1.6-.16.64.32 4 1.92 4.64 2.24.64.32 1.12.48 1.28.8.16.32.16 1.6-.48 2.88z"/></svg>';
}

function car_dealer_whatsapp_number(): string {
	$options = car_dealer_theme_options();
	$phone = preg_replace( '/\D+/', '', (string) ( $options['whatsapp'] ?: get_option( 'car_dealer_whatsapp', '' ) ) );
	if ( str_starts_with( $phone, '00' ) ) { $phone = substr( $phone, 2 ); }
	if ( preg_match( '/^05\d{8}$/', $phone ) ) { $phone = '966' . substr( $phone, 1 ); }
	return preg_match( '/^[1-9]\d{8,14}$/', $phone ) ? $phone : '';
}

function car_dealer_whatsapp_url( $message = '' ) {
	$phone = car_dealer_whatsapp_number();
	if ( '' === $phone ) { return '#'; }
	return 'https://wa.me/' . $phone . ( '' !== $message ? '?text=' . rawurlencode( $message ) : '' );
}

function car_dealer_site_url(): string {
	$url = home_url( '/' );
	return function_exists( 'car_dealer_catalog_localized_url' ) ? car_dealer_catalog_localized_url( $url ) : $url;
}

/** Resolve editorial links by slug; an intentionally empty site has no fixed page IDs. */
function car_dealer_legal_links(): array {
	$links = array();
	foreach ( array(
		'privacy-policy' => array( 'سياسة الخصوصية', 'Privacy policy' ),
		'terms' => array( 'شروط الاستخدام', 'Terms of use' ),
		'cookie-policy' => array( 'سياسة ملفات الارتباط', 'Cookie policy' ),
		'reservation-policy' => array( 'سياسة الحجز والإلغاء', 'Reservations and cancellations' ),
	) as $slug => $labels ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page || 'publish' !== $page->post_status ) { continue; }
		$links[] = array( 'id' => (int) $page->ID, 'url' => car_dealer_catalog_localized_url( get_permalink( $page ) ), 'label' => car_dealer_text( $labels[0], $labels[1] ) );
	}
	return $links;
}

function car_dealer_page_url( string $slug ): string {
	$page = get_page_by_path( sanitize_title( $slug ), OBJECT, 'page' );
	return $page instanceof WP_Post && 'publish' === $page->post_status ? car_dealer_catalog_localized_url( (string) get_permalink( $page ) ) : '';
}

/** Return an archive link only while its plugin-owned content type is registered. */
function car_dealer_archive_url( string $post_type ): string {
	$url = post_type_exists( $post_type ) ? get_post_type_archive_link( $post_type ) : false;
	return is_string( $url ) ? car_dealer_catalog_localized_url( $url ) : '';
}

add_filter( 'nav_menu_link_attributes', static function ( array $atts ): array {
	if ( ! is_admin() && isset( $atts['href'] ) && is_string( $atts['href'] ) && function_exists( 'car_dealer_catalog_localized_url' ) ) {
		$atts['href'] = car_dealer_catalog_localized_url( $atts['href'] );
	}
	return $atts;
} );

add_filter( 'nav_menu_item_title', static function ( string $title, $item ): string {
	if ( is_admin() || 'en' !== car_dealer_catalog_language() ) { return $title; }
	if ( isset( $item->object_id ) && in_array( get_post_type( (int) $item->object_id ), array( 'page', 'car', 'car_offer' ), true ) ) {
		$translated = class_exists( '\AutoDealership\Content\PublicEditorialTranslations' ) ? \AutoDealership\Content\PublicEditorialTranslations::approved( (int) $item->object_id, '_adc_title_en' ) : (string) get_post_meta( (int) $item->object_id, '_adc_title_en', true );
		if ( '' !== trim( $translated ) ) { return $translated; }
	}
	$labels = array(
		'الرئيسية' => 'Home', 'السيارات' => 'Vehicles', 'كل السيارات' => 'All vehicles', 'العروض' => 'Offers',
		'التمويل' => 'Finance Calculator', 'حاسبة التمويل' => 'Finance Calculator', 'من نحن' => 'About us', 'تواصل معنا' => 'Contact us',
		'حسابي' => 'My account', 'تسجيل الدخول' => 'Log in',
	);
	return $labels[ trim( $title ) ] ?? $title;
}, 10, 2 );

add_filter( 'the_title', static function ( string $title, int $post_id ): string {
	if ( is_admin() || 'en' !== car_dealer_catalog_language() || 'page' !== get_post_type( $post_id ) ) { return $title; }
	$pages = array( 'من نحن' => 'About us', 'تواصل معنا' => 'Contact us', 'التمويل' => 'Finance Calculator', 'حاسبة التمويل' => 'Finance Calculator', 'سياسة الخصوصية' => 'Privacy policy', 'شروط الاستخدام' => 'Terms of use' );
	return $pages[ trim( $title ) ] ?? $title;
}, 10, 2 );

add_filter( 'body_class', static function ( array $classes ): array {
	if ( is_page( 'finance' ) ) { $classes[] = 'cd-finance-page'; }
	return $classes;
} );

add_filter( 'get_custom_logo', static function ( string $html ): string {
	if ( is_admin() || 'en' !== car_dealer_catalog_language() ) { return $html; }
	return str_replace( 'href="' . esc_url( home_url( '/' ) ) . '"', 'href="' . esc_url( car_dealer_site_url() ) . '"', $html );
} );

function car_dealer_footer_social_links(): void {
	$options = car_dealer_theme_options();
	$links = array_filter( array( 'facebook' => $options['facebook'], 'instagram' => $options['instagram'] ) );
	$phone = preg_replace( '/\D+/', '', (string) $options['whatsapp'] );
	if ( ! $links && ! $phone ) { return; }
	echo '<div class="cd-social-links">';
	foreach ( $links as $label => $url ) {
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( ucfirst( $label ) ) . '</a>';
	}
	if ( $phone ) { echo '<a href="' . esc_url( 'https://wa.me/' . $phone ) . '" target="_blank" rel="noopener">' . esc_html__( 'واتساب', 'car-dealer' ) . '</a>'; }
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
	$expires = isset( $offer['expires'] ) && is_string( $offer['expires'] ) ? $offer['expires'] : '';
	$discount_percent = $old_price > $new_price && $new_price > 0 ? (int) round( ( ( $old_price - $new_price ) / $old_price ) * 100 ) : 0;
	$url = car_dealer_catalog_localized_url( $offer['url'] );
	$title = $offer['title'];
	$image = get_the_post_thumbnail( $post_id, 'medium_large' );
	if ( ! $image ) { $image = get_the_post_thumbnail( $offer['car_id'], 'medium_large' ); }
	if ( ! $image ) { $image = '<span class="offer-placeholder">' . car_dealer_vehicle_placeholder() . '</span>'; }
	?>
	<article class="offer-card">
		<?php if ( $image ) : ?>
			<a class="offer-image" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( car_dealer_text( 'عرض تفاصيل ', 'View details for ' ) . $title ); ?>">
				<?php echo $image; ?>
			</a>
		<?php endif; ?>
		<?php if ( $discount_percent > 0 ) : ?><span class="offer-discount-badge"><?php echo esc_html( car_dealer_text( 'وفّر ', 'Save ' ) . $discount_percent . '%' ); ?></span><?php endif; ?>
		<div class="offer-content">
			<p class="offer-kicker"><?php echo esc_html( car_dealer_text( 'عرض مميز', 'Featured offer' ) ); ?></p>
			<h3><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a></h3>
			<div class="offer-pricing">
				<?php if ( $old_price ) : ?><p class="offer-old"><span><?php echo esc_html( car_dealer_text( 'السعر السابق', 'Previous price' ) ); ?></span><del><?php echo esc_html( car_dealer_format_price( $old_price ) ); ?></del></p><?php endif; ?>
				<?php if ( $new_price ) : ?><p class="offer-price"><span><?php echo esc_html( car_dealer_text( 'سعر العرض', 'Offer price' ) ); ?></span><strong><?php echo esc_html( car_dealer_format_price( $new_price ) ); ?></strong></p><?php endif; ?>
			</div>
			<?php if ( $monthly ) : ?><p class="car-monthly"><?php printf( esc_html__( 'قسط يبدأ من %s', 'car-dealer' ), esc_html( car_dealer_format_price( $monthly ) ) ); ?></p><?php endif; ?>
			<?php if ( '' !== $expires ) : ?><p class="offer-validity"><?php echo esc_html( car_dealer_text( 'ساري حتى ', 'Valid until ' ) . $expires ); ?></p><?php endif; ?>
			<div class="offer-card-actions">
				<a class="btn btn-primary" href="<?php echo esc_url( $url . '#offer-lead' ); ?>"><?php esc_html_e( 'احصل على العرض', 'car-dealer' ); ?></a>
				<a class="btn btn-outline" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( car_dealer_text( 'تفاصيل العرض', 'Offer details' ) ); ?></a>
			</div>
		</div>
	</article>
	<?php
}

/** Shared, keyboard-accessible horizontal offer rail for home and archive. */
function car_dealer_offer_rail( string $cards ): void {
	if ( trim( $cards ) === '' ) { return; }
	$id = wp_unique_id( 'website-offers-' );
	?>
	<div class="offer-rail" data-horizontal-row>
		<div class="offer-rail-controls">
			<button type="button" data-scroll-direction="left" aria-controls="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( car_dealer_text( 'تحريك العروض لليسار', 'Scroll offers left' ) ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M19 12H5m7-7-7 7 7 7"/></svg></button>
			<button type="button" data-scroll-direction="right" aria-controls="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( car_dealer_text( 'تحريك العروض لليمين', 'Scroll offers right' ) ); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M5 12h14m-7-7 7 7-7 7"/></svg></button>
		</div>
		<div id="<?php echo esc_attr( $id ); ?>" class="offer-rail-track" data-horizontal-track tabindex="0" role="region" aria-label="<?php echo esc_attr( car_dealer_text( 'العروض الحالية', 'Current offers' ) ); ?>"><?php echo $cards; ?></div>
	</div>
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
 if ( ! class_exists( '\AutoDealership\Tools\LoanCalculator' ) ) { return ''; }
 $atts = shortcode_atts( array( 'price'=>0 ), $atts, 'car_dealer_loan_calculator' );
 if ( ! isset( $model['price'] ) ) { $model = \AutoDealership\Tools\LoanCalculator::view_model( max( 0, (float) $atts['price'] ) ); }
 return \AutoDealership\Tools\LoanCalculator::render( $model );
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

function car_dealer_comparison_url(): string {
    $page = get_page_by_path( 'comparison' );
    return $page && $page->post_status === 'publish' ? car_dealer_catalog_localized_url( get_permalink( $page ) ) : '';
}

add_action( 'wp_footer', static function (): void {
    $url = car_dealer_comparison_url(); if ( ! $url ) { return; }
    $count = count( car_dealer_get_comparison() );
    echo '<aside class="cd-comparison-bar" data-comparison-bar' . ( $count ? '' : ' hidden' ) . '><a class="btn btn-primary" href="' . esc_url( $url ) . '">' . esc_html( car_dealer_text( 'عرض المقارنة', 'View comparison' ) ) . ' (<span data-comparison-count>' . $count . '</span>)</a></aside>';
} );

add_action( 'template_redirect', static function (): void {
    if ( is_page( 'comparison' ) ) { if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } nocache_headers(); }
} );

function car_dealer_shortcode_comparison( $model = array() ) {
    $ids = isset( $model['post_ids'] ) && is_array( $model['post_ids'] ) ? $model['post_ids'] : car_dealer_get_comparison();
    $views = array(); foreach ( $ids as $id ) { $view = car_dealer_vehicle_view( (int) $id ); if ( $view ) { $views[(int)$id] = $view; } }
    ob_start();
    echo '<section class="cd-comparison-workspace" data-comparison-page><p>' . esc_html( car_dealer_text( 'قارن حتى 4 سيارات، واختر الأنسب لك. تظهر البيانات المتوفرة فقط.', 'Compare up to 4 vehicles and choose your best fit. Only available data is shown.' ) ) . '</p>';
    if ( ! $views ) { echo '<p class="empty-state">' . esc_html( car_dealer_text( 'لم تضف سيارات للمقارنة بعد.', 'No vehicles selected for comparison yet.' ) ) . '</p>'; }
    else {
        echo '<div class="cd-comparison-scroll" tabindex="0" role="region" aria-label="' . esc_attr( car_dealer_text( 'جدول مقارنة السيارات', 'Vehicle comparison table' ) ) . '"><table class="cd-comparison-table"><thead><tr><th scope="col">' . esc_html( car_dealer_text( 'المواصفات', 'Specifications' ) ) . '</th>';
        foreach ( $views as $id => $view ) {
            $url = car_dealer_catalog_localized_url( get_permalink( $id ) );
            echo '<th scope="col"><a href="' . esc_url( $url ) . '">' . ( get_the_post_thumbnail( $id, 'medium', array( 'loading' => 'lazy' ) ) ?: '<span class="cd-comparison-placeholder">' . car_dealer_vehicle_placeholder() . '</span>' ) . '<span>' . esc_html( get_the_title( $id ) ) . '</span></a>';
            car_dealer_comparison_button( $id ); echo '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( array( 'brand_name'=>array('الماركة','Brand'), 'model'=>array('الطراز','Model'), 'trim'=>array('الفئة','Trim'), 'year'=>array('الموديل','Model year'), 'color'=>array('الألوان المتاحة','Available colors'), 'price'=>array('السعر','Price'), 'price_label'=>array('تفاصيل السعر','Price inclusions'), 'monthly'=>array('القسط الشهري','Monthly payment'), 'fuel'=>array('الوقود','Fuel'), 'transmission'=>array('ناقل الحركة','Transmission') ) as $key => $labels ) {
            $values = array(); foreach ( $views as $id => $view ) {
                $value = $key === 'trim' ? ( isset( $view['catalog_model'] ) ? implode( ' / ', array_unique( array_filter( wp_list_pluck( $view['catalog_model']['options'], 'trim_name' ) ) ) ) : ( $view['vehicle']['trim_name'] ?? '' ) ) : ( $view[$key] ?? '' );
                $values[$id] = in_array( $key, array( 'price', 'monthly' ), true ) ? car_dealer_catalog_format_price( $value ) : car_dealer_catalog_value_label( (string)$value );
                if ( $key === 'price' && $values[$id] !== '' && ! empty( $view['price_from'] ) ) { $values[$id] = car_dealer_text( 'يبدأ من ', 'From ' ) . $values[$id]; }
            }
            if ( ! array_filter( $values ) ) { continue; }
            echo '<tr><th scope="row">' . esc_html( car_dealer_text( $labels[0], $labels[1] ) ) . '</th>'; foreach ( $values as $value ) { echo '<td>' . esc_html( $value !== '' ? $value : '—' ) . '</td>'; } echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    $catalog = car_dealer_archive_url( 'car' ); if ( $catalog ) { echo '<p><a class="btn btn-outline" href="' . esc_url( $catalog ) . '">' . esc_html( car_dealer_text( 'اختيار سيارات للمقارنة', 'Choose vehicles to compare' ) ) . '</a></p>'; }
    echo '</section>'; return ob_get_clean();
}
car_dealer_register_shortcode_adapter( 'car_dealer_comparison', 'car_dealer_shortcode_comparison', false, true );
