<?php
namespace AutoDealership\SEO;

use AutoDealership\Content\PublicOfferView;
use AutoDealership\Content\PublicVehicleView;
use AutoDealership\Core\ProductSetup;
use AutoDealership\Inventory\CatalogPresentation;
use AutoDealership\Inventory\PublicCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Installation-local SEO contract for the public website.
 *
 * The platform is single-site per company, so these settings intentionally live
 * in the current WordPress options table and are generated from that site's
 * identity, catalog and offers. No company data is shared between installs.
 */
final class PublicSeo {
	private const OPTION = 'adc_seo_settings';
	private const TITLE_LIMIT = 70;
	private const DESCRIPTION_LIMIT = 170;

	public static function boot(): void {
		// Core's rel_canonical does not cover every archive/front-page combination.
		// This class owns one canonical contract for the public surface.
		remove_action( 'wp_head', 'rel_canonical' );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_meta' ), 3 );
		add_action( 'wp_head', array( self::class, 'print_links' ), 8 );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post', array( self::class, 'save_meta_box' ), 20, 2 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 20 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 20, 2 );
		add_action( 'template_redirect', array( self::class, 'serve_robots_fallback' ), 0 );
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'sitemap_provider' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'sitemap_posts_query_args' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies', array( self::class, 'sitemap_taxonomies' ) );
		add_filter( 'wp_sitemaps_users_pre_url_list', '__return_empty_array' );
	}

	/** @return array<string,string> */
	public static function defaults(): array {
		$location_ar = ProductSetup::country( 'ar' );
		$location_en = ProductSetup::country( 'en' );
		return array(
			'location_ar' => $location_ar,
			'location_en' => $location_en,
			'home_title_ar' => '{site_name} | سيارات جديدة وعروض وتمويل',
			'home_title_en' => '{site_name} | New Cars, Offers and Finance',
			'home_description_ar' => ProductSetup::hero_description( 'ar' ),
			'home_description_en' => ProductSetup::hero_description( 'en' ),
			'vehicle_title_ar' => '{vehicle_title} | {site_name}',
			'vehicle_title_en' => '{vehicle_title} | {site_name}',
			'vehicle_description_ar' => 'اكتشف {vehicle_title} لدى {site_name}{location_suffix}. تعرّف على المواصفات والسعر واطلب تجربة القيادة.',
			'vehicle_description_en' => 'Explore {vehicle_title} from {site_name}{location_suffix}. See specifications, price and request a test drive.',
			'offer_title_ar' => '{offer_title} | {site_name}',
			'offer_title_en' => '{offer_title} | {site_name}',
			'offer_description_ar' => 'استفد من عرض {offer_title} لدى {site_name}{location_suffix}. اطّلع على السعر والتفاصيل وتواصل مع فريق المبيعات.',
			'offer_description_en' => 'View the {offer_title} offer from {site_name}{location_suffix}. Check the price and contact the sales team.',
			'catalog_title_ar' => 'سيارات للبيع | {site_name}',
			'catalog_title_en' => 'Cars for Sale | {site_name}',
			'catalog_description_ar' => 'تصفح السيارات المتاحة للبيع لدى {site_name}{location_suffix}. قارن المواصفات والأسعار واختر سيارتك بثقة.',
			'catalog_description_en' => 'Browse vehicles available from {site_name}{location_suffix}. Compare specifications and prices with confidence.',
			'offers_archive_title_ar' => 'عروض السيارات | {site_name}',
			'offers_archive_title_en' => 'Car Offers | {site_name}',
			'offers_archive_description_ar' => 'اكتشف أحدث عروض السيارات لدى {site_name}{location_suffix} وتواصل مع فريق المبيعات لمعرفة التفاصيل.',
			'offers_archive_description_en' => 'Discover the latest vehicle offers from {site_name}{location_suffix} and contact the sales team for details.',
			'default_image_url' => '',
		);
	}

	/** @return array<string,string> */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		return array_replace( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/** Save only the SEO fields owned by this class. */
	public static function save_settings( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_seo_forbidden', 'Forbidden.' );
		}
		$clean = array();
		foreach ( array_keys( self::defaults() ) as $key ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( 'default_image_url' === $key ) {
				if ( '' !== $value && ! wp_http_validate_url( $value ) ) {
					return new \WP_Error( 'adc_seo_image_url', 'Enter a valid public image URL.' );
				}
				$clean[ $key ] = esc_url_raw( $value );
				continue;
			}
			$limit = str_contains( $key, 'title' ) ? 240 : ( str_contains( $key, 'description' ) ? 600 : 160 );
			if ( strlen( $value ) > $limit ) {
				$value = self::truncate( $value, $limit );
			}
			$clean[ $key ] = str_contains( $key, 'description' ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}
		update_option( self::OPTION, $clean, false );
		wp_cache_delete( self::OPTION, 'options' );
		// Clear known full-page cache integrations after a metadata change.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			// W3 Total Cache is optional; call the integration dynamically so the
			// core plugin remains valid when that plugin is not installed.
			call_user_func( 'w3tc_flush_all' );
		}
		do_action( 'adc_seo_settings_saved' );
		return true;
	}

	public static function document_title( string $title ): string {
		$data = self::page_data();
		return $data ? $data['title'] : $title;
	}

	/** Print description, social previews and one consistent social URL. */
	public static function print_meta(): void {
		$data = self::page_data();
		if ( ! $data ) {
			return;
		}
		$description = $data['description'];
		$url = self::canonical_url();
		$image = self::image_url();
		$locale = 'en' === $data['language'] ? 'en_US' : 'ar_SA';
		$alternate_locale = 'en' === $data['language'] ? 'ar_SA' : 'en_US';
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '">' . "\n";
		echo '<meta property="og:locale:alternate" content="' . esc_attr( $alternate_locale ) . '">' . "\n";
		echo '<meta property="og:type" content="' . esc_attr( $data['type'] ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $data['title'] ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( ProductSetup::brand( $data['language'] ) ) . '">' . "\n";
		echo '<meta name="twitter:card" content="' . esc_attr( $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $data['title'] ) . '">' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta name="twitter:url" content="' . esc_url( $url ) . '">' . "\n";
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
			echo '<meta property="og:image:alt" content="' . esc_attr( $data['title'] ) . '">' . "\n";
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		}
	}

	/** Print canonical for archive views and language alternates for all indexable views. */
	public static function print_links(): void {
		$data = self::page_data();
		if ( ! $data ) {
			return;
		}
		$base = self::base_url();
		if ( ! $base ) {
			$base = home_url( '/' );
		}
		echo '<link rel="canonical" href="' . esc_url( self::canonical_url() ) . '">' . "\n";
		foreach ( array( 'ar' => 'ar', 'en' => 'en', 'x-default' => 'ar' ) as $hreflang => $language ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( CatalogPresentation::localized_url( $base, $language ) ) . '">' . "\n";
		}
	}

	public static function robots( array $robots ): array {
		$blocked = self::is_account_request() || is_search() || is_404() || is_page( 'comparison' ) || CatalogPresentation::has_active_filters();
		if ( ( is_singular( 'car' ) && ! PublicCatalog::is_post_publicly_eligible( get_queried_object_id() ) ) || ( is_singular( 'car_offer' ) && ! PublicOfferView::for_post( get_queried_object_id() ) ) ) {
			$blocked = true;
		}
		if ( $blocked ) {
			$robots['noindex'] = true;
			$robots['follow'] = true;
			unset( $robots['index'], $robots['nofollow'] );
		}
		$robots['max-image-preview'] = 'large';
		return $robots;
	}

	/**
	 * Keep the public robots contract deterministic across hosts and rewrite modes.
	 *
	 * WordPress normally serves this response through its rewrite endpoint. The
	 * filter also covers that normal path, while serve_robots_fallback() below
	 * handles local installations where the endpoint is still returning a 404.
	 */
	public static function robots_txt( string $output, bool $public ): string {
		if ( ! $public ) {
			return "User-agent: *\nDisallow: /\n";
		}

		$lines = array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Sitemap: ' . esc_url_raw( home_url( '/wp-sitemap.xml' ) ),
		);

		return implode( "\n", $lines ) . "\n";
	}

	/** Serve robots.txt when the web server has not registered WordPress rewrites. */
	public static function serve_robots_fallback(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? wp_unslash( $_SERVER['REQUEST_URI'] )
			: '';
		$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? untrailingslashit( $home_path ) : '';
		$expected_path = ( '' === $home_path ? '' : $home_path ) . '/robots.txt';

		if ( ! is_string( $request_path ) || untrailingslashit( $request_path ) !== untrailingslashit( $expected_path ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo self::robots_txt( '', (bool) get_option( 'blog_public', 1 ) );
		exit;
	}

	/** Do not expose author/user URLs in a public dealership sitemap. */
	public static function sitemap_provider( $provider, string $name ) {
		return 'users' === $name ? false : $provider;
	}

	/** Exclude private/utility pages until they have a public SEO contract. */
	public static function sitemap_posts_query_args( array $args, string $post_type ): array {
		if ( 'car_offer' === $post_type ) {
			// PublicOfferView applies the same availability, price and expiry
			// contract to the sitemap query as it does to the public archive.
			$args['adc_public_sitemap'] = '1';
			return $args;
		}

		if ( 'page' !== $post_type ) {
			return $args;
		}

		$excluded = array();
		$comparison = get_page_by_path( 'comparison', OBJECT, 'page' );
		if ( $comparison instanceof \WP_Post ) {
			$excluded[] = $comparison->ID;
		}

		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $pages as $page_id ) {
			$slug = (string) get_post_field( 'post_name', $page_id );
			if ( preg_match( '/(?:sample|example|مثال)/iu', $slug ) ) {
				$excluded[] = absint( $page_id );
			}
		}

		if ( $excluded ) {
			$args['post__not_in'] = array_values( array_unique( array_map( 'absint', $excluded ) ) );
		}
		return $args;
	}

	/** Taxonomy pages do not yet expose a complete public SEO contract. */
	public static function sitemap_taxonomies( array $taxonomies ): array {
		return array();
	}

	/** @return array<string,string>|null */
	public static function page_data(): ?array {
		if ( is_admin() || self::is_account_request() || is_search() || is_404() || is_page( 'comparison' ) ) {
			return null;
		}
		$language = CatalogPresentation::language();
		$settings = self::settings();
		$context = '';
		$tokens = array(
			'site_name' => ProductSetup::brand( $language ),
			'legal_name' => ProductSetup::legal_name( $language ),
			'location' => self::location( $settings, $language ),
			'location_suffix' => self::location_suffix( $settings, $language ),
			'vehicle_title' => '',
			'brand' => '',
			'model' => '',
			'year' => '',
			'condition' => '',
			'price' => '',
			'currency' => 'SAR',
			'offer_title' => '',
			'offer_price' => '',
		);

		if ( is_singular( 'car' ) ) {
			$post_id = get_queried_object_id();
			$view = PublicVehicleView::for_post( $post_id );
			if ( ! $view ) {
				return null;
			}
			$context = 'vehicle';
			$tokens['vehicle_title'] = self::post_title( $post_id, $language );
			$tokens['brand'] = self::plain( (string) ( $view['brand_name'] ?? '' ) );
			$tokens['model'] = self::plain( (string) ( $view['model'] ?? '' ) );
			$tokens['year'] = self::plain( (string) ( $view['year'] ?? '' ) );
			$tokens['condition'] = self::condition_label( (string) ( $view['condition'] ?? '' ), $language );
			$tokens['price'] = self::format_price( (string) ( $view['price'] ?? '' ) );
		} elseif ( is_singular( 'car_offer' ) ) {
			$model = PublicOfferView::for_post( get_queried_object_id() );
			if ( ! $model ) {
				return null;
			}
			$context = 'offer';
			$tokens['offer_title'] = self::post_title( (int) $model['post_id'], $language ) ?: self::post_title( (int) $model['car_id'], $language );
			$tokens['vehicle_title'] = self::post_title( (int) $model['car_id'], $language );
			$tokens['offer_price'] = self::format_price( (string) $model['price'] );
			$tokens['price'] = $tokens['offer_price'];
			$tokens['currency'] = self::plain( (string) $model['currency'] ) ?: 'SAR';
		} elseif ( is_post_type_archive( 'car' ) ) {
			$context = 'catalog';
		} elseif ( is_post_type_archive( 'car_offer' ) ) {
			$context = 'offers_archive';
		} elseif ( is_front_page() || is_home() ) {
			$context = 'home';
		} elseif ( is_singular( 'page' ) ) {
			$context = 'page';
		} elseif ( is_singular( 'post' ) ) {
			$context = 'post';
		} else {
			return null;
		}

		if ( in_array( $context, array( 'page', 'post' ), true ) ) {
			$title = self::post_title( get_queried_object_id(), $language );
			$title = $title ? $title . ' | ' . $tokens['site_name'] : $tokens['site_name'];
			$description = self::page_description();
		} else {
			$title_key = $context . '_title_' . $language;
			$description_key = $context . '_description_' . $language;
			$title = self::replace_tokens( (string) ( $settings[ $title_key ] ?? '' ), $tokens );
			$description = self::replace_tokens( (string) ( $settings[ $description_key ] ?? '' ), $tokens );
		}
		$override_id = is_singular( array( 'page', 'post', 'car', 'car_offer' ) ) ? get_queried_object_id() : 0;
		$override_title = $override_id ? self::plain( (string) get_post_meta( $override_id, '_adc_seo_title', true ) ) : '';
		$override_description = $override_id ? self::plain( (string) get_post_meta( $override_id, '_adc_seo_description', true ) ) : '';
		if ( $override_title ) { $title = self::replace_tokens( $override_title, $tokens ); }
		if ( $override_description ) { $description = self::replace_tokens( $override_description, $tokens ); }
		$title = self::truncate( self::plain( $title ), self::TITLE_LIMIT );
		$description = self::truncate( self::plain( $description ), self::DESCRIPTION_LIMIT );
		if ( '' === $title ) {
			$title = $tokens['site_name'];
		}
		if ( '' === $description ) {
			$description = self::truncate( ProductSetup::hero_description( $language ), self::DESCRIPTION_LIMIT );
		}
		return array(
			'context' => $context,
			'language' => $language,
			'title' => $title,
			'description' => $description,
			'type' => in_array( $context, array( 'vehicle', 'offer' ), true ) ? 'product' : 'website',
		);
	}

	private static function base_url(): string {
		if ( is_post_type_archive( array( 'car', 'car_offer' ) ) ) {
			$url = get_post_type_archive_link( is_post_type_archive( 'car' ) ? 'car' : 'car_offer' );
		} elseif ( is_singular( array( 'page', 'post', 'car', 'car_offer' ) ) ) {
			$url = get_permalink();
		} elseif ( is_front_page() || is_home() ) {
			$url = home_url( '/' );
		} else {
			$url = '';
		}
		return is_string( $url ) ? $url : '';
	}

	private static function canonical_url(): string {
		$base = self::base_url();
		return $base ? CatalogPresentation::localized_url( $base ) : home_url( '/' );
	}

	private static function image_url(): string {
		if ( is_singular( array( 'car', 'car_offer' ) ) ) {
			$image = get_the_post_thumbnail_url( get_queried_object_id(), 'full' );
			if ( is_string( $image ) && '' !== $image ) {
				return $image;
			}
			if ( is_singular( 'car_offer' ) ) {
				$model = PublicOfferView::for_post( get_queried_object_id() );
				if ( $model && '' !== (string) ( $model['image_url'] ?? '' ) ) {
					return (string) $model['image_url'];
				}
			}
		}
		$settings = self::settings();
		if ( ! empty( $settings['default_image_url'] ) ) {
			return esc_url_raw( (string) $settings['default_image_url'] );
		}
		$logo_id = absint( get_theme_mod( 'custom_logo' ) );
		return $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
	}

	private static function page_description(): string {
		$content = get_the_excerpt();
		if ( '' === trim( (string) $content ) ) {
			$content = get_post_field( 'post_content', get_queried_object_id() );
		}
		return self::plain( (string) $content );
	}

	private static function post_title( int $post_id, string $language ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( 'en' === $language && class_exists( '\AutoDealership\Content\PublicEditorialTranslations' ) ) {
			$translated = \AutoDealership\Content\PublicEditorialTranslations::approved( $post_id, '_adc_title_en' );
			if ( '' !== trim( $translated ) ) {
				return self::plain( $translated );
			}
		}
		if ( 'en' === $language && 'car' === $post->post_type ) {
			$view = PublicVehicleView::for_post( $post_id );
			$vehicle = is_array( $view['vehicle'] ?? null ) ? $view['vehicle'] : array();
			$parts = array_filter( array(
				(string) ( $view['brand_name'] ?? $vehicle['brand'] ?? '' ),
				(string) ( $view['model'] ?? $vehicle['model'] ?? '' ),
				(string) ( $vehicle['trim_name'] ?? '' ),
				(string) ( $view['year'] ?? $vehicle['model_year'] ?? '' ),
			), static function ( $value ): bool { return '' !== trim( (string) $value ); } );
			if ( $parts ) {
				return self::plain( implode( ' ', $parts ) );
			}
			return 'Vehicle #' . $post_id;
		}
		if ( 'en' === $language && 'car_offer' === $post->post_type ) {
			$car_id = absint( get_post_meta( $post_id, '_offer_car_id', true ) );
			$vehicle_title = $car_id ? self::post_title( $car_id, 'en' ) : '';
			return $vehicle_title ? 'Offer for ' . $vehicle_title : 'Vehicle offer #' . $post_id;
		}
		return self::plain( (string) $post->post_title );
	}

	private static function location( array $settings, string $language ): string {
		$value = trim( (string) ( $settings[ 'location_' . $language ] ?? '' ) );
		return $value ?: ProductSetup::country( $language );
	}

	private static function location_suffix( array $settings, string $language ): string {
		$location = self::location( $settings, $language );
		return $location ? ( 'en' === $language ? ' in ' : ' في ' ) . $location : '';
	}

	/** @param array<string,string> $tokens */
	private static function replace_tokens( string $template, array $tokens ): string {
		$replacements = array();
		foreach ( $tokens as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = $value;
		}
		return strtr( $template, $replacements );
	}

	private static function condition_label( string $condition, string $language ): string {
		if ( 'new' === $condition ) {
			return 'en' === $language ? 'new' : 'جديدة';
		}
		if ( 'used' === $condition ) {
			return 'en' === $language ? 'used' : 'مستعملة';
		}
		return self::plain( $condition );
	}

	private static function format_price( string $price ): string {
		$price = preg_replace( '/[^0-9.]/', '', $price );
		return $price && (float) $price > 0 ? number_format_i18n( (float) $price, 0 ) : '';
	}

	private static function is_account_request(): bool {
		if ( ! isset( $_GET['cd_account'] ) || ! is_scalar( $_GET['cd_account'] ) ) {
			return false;
		}
		return in_array( sanitize_key( wp_unslash( (string) $_GET['cd_account'] ) ), array( 'login', 'register', 'dashboard' ), true );
	}

	private static function plain( string $value ): string {
		$value = wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
		return trim( preg_replace( '/\s+/u', ' ', $value ) ?: '' );
	}

	private static function truncate( string $value, int $limit ): string {
		$value = trim( $value );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value, 'UTF-8' ) > $limit ) {
			return rtrim( mb_substr( $value, 0, $limit - 1, 'UTF-8' ) ) . '…';
		}
		if ( ! function_exists( 'mb_strlen' ) && strlen( $value ) > $limit ) {
			return rtrim( substr( $value, 0, max( 1, $limit - 3 ) ) ) . '...';
		}
		return $value;
	}

	public static function add_meta_box(): void {
		foreach ( array( 'page', 'car', 'car_offer' ) as $post_type ) {
			add_meta_box( 'adc-seo-meta', ProductSetup::t( 'تحسين محركات البحث', 'Search engine optimization' ), array( self::class, 'render_meta_box' ), $post_type, 'normal', 'default' );
		}
	}

	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'adc_save_seo_meta', 'adc_seo_meta_nonce' );
		echo '<p>' . esc_html( ProductSetup::t( 'اختياري: اترك الحقول فارغة لاستخدام التوليد التلقائي. يمكن استخدام الرموز نفسها الموجودة في صفحة إعدادات SEO.', 'Optional: leave fields empty to use automatic generation. The same tokens from the SEO settings page are supported.' ) ) . '</p>';
		echo '<p><label for="adc-seo-meta-title"><strong>' . esc_html( ProductSetup::t( 'عنوان SEO مخصص', 'Custom SEO title' ) ) . '</strong></label><br><input class="widefat" id="adc-seo-meta-title" name="adc_seo_title" type="text" maxlength="240" value="' . esc_attr( (string) get_post_meta( $post->ID, '_adc_seo_title', true ) ) . '"></p>';
		echo '<p><label for="adc-seo-meta-description"><strong>' . esc_html( ProductSetup::t( 'وصف SEO مخصص', 'Custom SEO description' ) ) . '</strong></label><br><textarea class="widefat" id="adc-seo-meta-description" name="adc_seo_description" rows="4" maxlength="600">' . esc_textarea( (string) get_post_meta( $post->ID, '_adc_seo_description', true ) ) . '</textarea></p>';
	}

	public static function save_meta_box( int $post_id, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, array( 'page', 'car', 'car_offer' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$nonce = isset( $_POST['adc_seo_meta_nonce'] ) && is_scalar( $_POST['adc_seo_meta_nonce'] ) ? (string) wp_unslash( $_POST['adc_seo_meta_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'adc_save_seo_meta' ) ) { return; }
		foreach ( array( 'title' => 240, 'description' => 600 ) as $key => $limit ) {
			$input_key = 'adc_seo_' . $key;
			$value = isset( $_POST[ $input_key ] ) && is_scalar( $_POST[ $input_key ] ) ? trim( (string) wp_unslash( $_POST[ $input_key ] ) ) : '';
			$value = 'description' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			$value = self::truncate( $value, $limit );
			if ( '' === $value ) { delete_post_meta( $post_id, '_adc_seo_' . $key ); } else { update_post_meta( $post_id, '_adc_seo_' . $key, $value ); }
		}
	}
}
