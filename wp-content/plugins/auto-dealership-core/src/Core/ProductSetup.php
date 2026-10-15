<?php
namespace AutoDealership\Core;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Single-site product onboarding. Never imports operational data or secrets. */
final class ProductSetup {
	private const PROFILE = 'adc_product_profile';
	private const MAIL = 'adc_product_mail';
	private const SECTIONS = array( 'identity', 'appearance', 'policy', 'mail', 'pages', 'readiness' );
	private const PROFILE_KEYS = array( 'brand_ar', 'brand_en', 'description_ar', 'description_en', 'hero_ar', 'hero_en', 'language', 'timezone' );
	private const THEME_KEYS = array( 'primary_color', 'accent_color', 'description_en', 'phone', 'email', 'address', 'address_en', 'facebook', 'instagram', 'whatsapp' );
	private const POLICY_KEYS = array( 'vat_percent', 'reservation_hours', 'reservation_refund_hours', 'reservation_deposit_type', 'reservation_deposit_value', 'privacy_retention_days' );
	private const MAIL_KEYS = array( 'ADC_MAIL_MODE', 'ADC_SMTP_HOST', 'ADC_SMTP_PORT', 'ADC_SMTP_ENCRYPTION', 'ADC_SMTP_USERNAME', 'ADC_SMTP_PASSWORD', 'ADC_MAIL_FROM', 'ADC_MAIL_FROM_NAME' );

	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'prepare_storage' ), 25 );
		add_action( 'admin_init', array( self::class, 'prepare_site' ), 30 );
		add_action( 'admin_menu', static function (): void { add_submenu_page( 'options-general.php', self::t( 'إعداد المنصة', 'Platform setup' ), self::t( 'إعداد المنصة', 'Platform setup' ), 'manage_options', 'adc-platform-setup', array( self::class, 'render' ) ); } );
		add_action( 'admin_post_adc_platform_setup', array( self::class, 'submit' ) );
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( 'manage_options' ) || get_option( self::PROFILE, false ) || get_option( 'car_dealer_public_setup_version', false ) ) { return; }
			echo '<div class="notice notice-info"><p><a href="' . esc_url( self::url() ) . '">' . esc_html( self::t( 'ابدأ إعداد منصتك: الهوية والبريد والصفحات.', 'Set up your platform: identity, email and pages.' ) ) . '</a></p></div>';
		} );
		add_shortcode( 'adc_brand_name', static fn(): string => esc_html( self::brand( Localization::language() ) ) );
		add_shortcode( 'adc_legal_contact', array( self::class, 'legal_contact' ) );
	}

	/** Prepare shared WordPress storage once, with bounded retries on restricted hosts. */
	public static function prepare_storage(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Schema::is_ready() || '1' === get_option( 'adc_product_storage_prepared' ) || get_transient( 'adc_product_storage_retry' ) ) { return; }
		if ( \AutoDealership\Inventory\OnyxModelDrafts::ensure_transactional_tables() ) {
			update_option( 'adc_product_storage_prepared', '1', false );
		} else { set_transient( 'adc_product_storage_retry', 1, 5 * MINUTE_IN_SECONDS ); }
	}

	/** Runs after schema installation and theme activation, including either install order. */
	public static function prepare_site(): void {
		if ( ! current_user_can( 'manage_options' ) || 'car-dealer' !== get_template() || get_option( 'adc_db_version' ) !== Schema::VERSION || '5' === get_option( 'adc_product_site_prepared' ) ) { return; }
		$result = self::create_pages();
		if ( ! is_wp_error( $result ) ) { update_option( 'adc_product_site_prepared', '5', false ); }
	}

	public static function t( string $ar, string $en ): string { return 'en' === Localization::language() ? $en : $ar; }
	public static function url( string $section = 'identity' ): string { return add_query_arg( array( 'page'=>'adc-platform-setup', 'section'=>$section ), admin_url( 'options-general.php' ) ); }
	public static function profile(): array { return (array) get_option( self::PROFILE, array() ); }
	public static function legacy(): bool {
		if ( self::profile() || ! get_option( 'car_dealer_public_setup_version', false ) ) { return false; }
		return (bool) preg_match( '/أوتو\s+براندز|auto\s*brands/iu', (string) get_option( 'blogname', '' ) );
	}
	public static function brand( string $lang = 'ar' ): string {
		$profile = self::profile();
		if ( ! empty( $profile['brand_' . ( 'en' === $lang ? 'en' : 'ar' )] ) ) { return $profile['brand_' . ( 'en' === $lang ? 'en' : 'ar' )]; }
		if ( self::legacy() && 'en' === $lang ) { return 'AUTO BRANDS'; }
		return wp_specialchars_decode( (string) get_option( 'blogname', 'Dealership' ), ENT_QUOTES );
	}
	/** Replace only product UI copy; stored customer/editorial content is untouched. */
	public static function copy( string $text, string $lang ): string {
		if ( self::legacy() ) { return $text; }
		return str_replace( array( 'أوتو براندز', 'AUTO BRANDS', 'Auto Brands', 'AutoBrands' ), self::brand( $lang ), $text );
	}
	public static function hero_description( string $lang ): string {
		$p = self::profile();
		if ( ! empty( $p['hero_' . $lang] ) ) { return $p['hero_' . $lang]; }
		if ( self::legacy() ) { return 'en' === $lang ? 'New vehicle sales in Al Jawharah, Jeddah, with after-sales services, polishing and window tinting. Explore your options and contact our team.' : 'مبيعات السيارات الجديدة في جدة، حي الجوهرة، وخدمات ما بعد البيع والتلميع والتظليل. استعرض الخيارات وتواصل مع فريقنا.'; }
		return 'en' === $lang ? 'Explore available vehicles, compare your options and contact our team.' : 'استعرض السيارات المتاحة وقارن الخيارات وتواصل مع فريقنا.';
	}
	private static function profile_defaults(): array {
		$appearance = (array) get_option( 'car_dealer_theme_settings', array() );
		return array( 'brand_ar'=>self::brand(), 'brand_en'=>self::brand( 'en' ), 'description_ar'=>(string) get_option( 'blogdescription', '' ), 'description_en'=>(string) ( $appearance['description_en'] ?? '' ), 'hero_ar'=>self::hero_description( 'ar' ), 'hero_en'=>self::hero_description( 'en' ), 'language'=>'ar', 'timezone'=>wp_timezone_string() );
	}

	public static function mail_setting( string $name, string $default ): string {
		if ( ! in_array( $name, self::MAIL_KEYS, true ) ) { return $default; }
		$data = (array) get_option( self::MAIL, array() );
		if ( 'ADC_SMTP_PASSWORD' === $name ) {
			if ( empty( $data['password'] ) || ! function_exists( 'openssl_decrypt' ) ) { return $default; }
			$raw = base64_decode( $data['password'], true );
			if ( false === $raw || strlen( $raw ) < 29 ) { return ''; }
			$clear = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'secure_auth' ), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'adc-product-mail-v1' );
			return false === $clear ? '' : $clear;
		}
		return isset( $data[$name] ) ? (string) $data[$name] : $default;
	}

	/** All input is validated before the first write. Existing unowned settings survive. */
	public static function normalize( array $data ) {
		if ( array_diff( array_keys( $data ), array( 'profile', 'appearance', 'policy' ) ) ) { return new \WP_Error( 'adc_setup_keys', self::t( 'الملف يحتوي أقسامًا غير مسموحة.', 'The file contains unsupported sections.' ) ); }
		$clean = array();
		foreach ( array( 'profile'=>self::PROFILE_KEYS, 'appearance'=>self::THEME_KEYS, 'policy'=>self::POLICY_KEYS ) as $section=>$keys ) {
			if ( ! isset( $data[$section] ) ) { continue; }
			if ( ! is_array( $data[$section] ) || array_diff( array_keys( $data[$section] ), $keys ) ) { return new \WP_Error( 'adc_setup_keys', self::t( 'حقول الإعدادات غير صالحة.', 'Invalid settings fields.' ) ); }
			$clean[$section] = array();
			foreach ( $data[$section] as $key=>$value ) {
				if ( ! is_scalar( $value ) || strlen( (string) $value ) > 4000 ) { return new \WP_Error( 'adc_setup_value', self::t( 'قيمة إعداد غير صالحة.', 'Invalid setting value.' ) ); }
				$value = (string) $value;
				if ( in_array( $key, array( 'primary_color', 'accent_color' ), true ) ) {
					$value = sanitize_hex_color( $value ); if ( ! $value ) { return new \WP_Error( 'adc_setup_color', self::t( 'اختر لونًا صالحًا.', 'Choose a valid color.' ) ); }
				} elseif ( in_array( $key, array( 'facebook', 'instagram' ), true ) ) {
					if ( $value && ! wp_http_validate_url( $value ) ) { return new \WP_Error( 'adc_setup_url', self::t( 'أدخل رابطًا عامًا صالحًا.', 'Enter a valid public URL.' ) ); } $value = esc_url_raw( $value );
				} elseif ( 'email' === $key ) {
					if ( $value && ! is_email( $value ) ) { return new \WP_Error( 'adc_setup_email', self::t( 'البريد غير صالح.', 'Invalid email address.' ) ); } $value = sanitize_email( $value );
				} elseif ( 'language' === $key ) {
					if ( ! in_array( $value, array( 'ar', 'en' ), true ) ) { return new \WP_Error( 'adc_setup_language', 'Invalid language.' ); }
				} elseif ( 'timezone' === $key ) {
					if ( ! in_array( $value, timezone_identifiers_list(), true ) && 'UTC' !== $value ) { return new \WP_Error( 'adc_setup_timezone', self::t( 'المنطقة الزمنية غير صالحة.', 'Invalid timezone.' ) ); }
				} else { $value = in_array( $key, array( 'description_ar', 'description_en', 'hero_ar', 'hero_en', 'address', 'address_en' ), true ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ); }
				$clean[$section][$key] = $value;
			}
		}
		if ( isset( $clean['profile']['brand_ar'] ) && '' === trim( $clean['profile']['brand_ar'] ) ) { return new \WP_Error( 'adc_setup_brand', self::t( 'اسم المنشأة مطلوب.', 'Business name is required.' ) ); }
		return $clean;
	}

	public static function apply( array $data ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_setup_forbidden', 'Forbidden.' ); }
		$data = self::normalize( $data ); if ( is_wp_error( $data ) ) { return $data; }
		global $wpdb;
		if ( ! \AutoDealership\Database\Transaction::begin() ) { return new \WP_Error( 'adc_setup_storage', 'Storage unavailable.' ); }
		$changed = array( self::PROFILE, 'car_dealer_theme_settings', 'blogname', 'blogdescription', 'timezone_string' );
		foreach ( array( 'vat_rate_bps', 'reservation_hours', 'reservation_refund_hours', 'reservation_deposit_type', 'reservation_deposit_value', 'privacy_retention_days' ) as $key ) { $changed[] = 'adc_' . $key; }
		// Serialize all product saves so profile merges cannot overwrite another administrator.
		$wpdb->get_results( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN ('adc_product_profile','car_dealer_theme_settings','blogname') FOR UPDATE" );
		self::clear_cache( $changed );
		if ( isset( $data['policy'] ) ) {
			$result = ConfigurationService::update_section( $data['policy'], self::POLICY_KEYS );
			if ( is_wp_error( $result ) ) { $wpdb->query( 'ROLLBACK' ); self::clear_cache( $changed ); return $result; }
		}
		if ( isset( $data['profile'] ) ) {
			$p = array_replace( self::profile(), $data['profile'] ); update_option( self::PROFILE, $p, false );
			if ( isset( $p['brand_ar'] ) ) { update_option( 'blogname', $p['brand_ar'] ); }
			if ( isset( $p['description_ar'] ) ) { update_option( 'blogdescription', $p['description_ar'] ); }
			if ( isset( $p['timezone'] ) ) { update_option( 'timezone_string', $p['timezone'] ); }
			if ( isset( $data['profile']['description_en'] ) ) { $data['appearance']['description_en'] = $data['profile']['description_en']; }
		}
		if ( isset( $data['appearance'] ) ) { update_option( 'car_dealer_theme_settings', array_replace( (array) get_option( 'car_dealer_theme_settings', array() ), $data['appearance'] ), false ); }
		$ok = ! $wpdb->last_error && AuditLog::record( 'platform.settings_updated', 'settings', 0, 'Product settings saved', null, array( 'sections'=>array_keys( $data ) ) );
		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); self::clear_cache( $changed ); return new \WP_Error( 'adc_setup_storage', self::t( 'تعذر حفظ الإعدادات.', 'Could not save settings.' ) ); }
		return true;
	}
	private static function clear_cache( array $keys ): void { foreach ( $keys as $key ) { wp_cache_delete( $key, 'options' ); } ConfigurationService::clear_option_cache(); }

	public static function export(): array {
		$policy = array();
		foreach ( self::POLICY_KEYS as $key ) { $policy[$key] = 'vat_percent' === $key ? ( (int) get_option( 'adc_vat_rate_bps', 1500 ) / 100 ) : get_option( 'adc_' . $key, in_array( $key, array( 'reservation_deposit_type' ), true ) ? 'none' : ( 'reservation_hours' === $key ? 24 : ( 'reservation_refund_hours' === $key ? 72 : 0 ) ) ); }
		return array( 'product'=>'auto-dealership', 'format'=>1, 'settings'=>array( 'profile'=>array_intersect_key( array_replace( self::profile_defaults(), self::profile() ), array_flip( self::PROFILE_KEYS ) ), 'appearance'=>array_intersect_key( (array) get_option( 'car_dealer_theme_settings', array() ), array_flip( self::THEME_KEYS ) ), 'policy'=>$policy ) );
	}
	public static function import_preview( string $json ) {
		if ( strlen( $json ) > 262144 ) { return new \WP_Error( 'adc_setup_size', 'Maximum file size is 256 KB.' ); }
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || array_diff( array_keys( $data ), array( 'product', 'format', 'settings' ) ) || 'auto-dealership' !== ( $data['product'] ?? '' ) || 1 !== ( $data['format'] ?? 0 ) || ! is_array( $data['settings'] ?? null ) ) { return new \WP_Error( 'adc_setup_format', self::t( 'صيغة الملف غير مدعومة.', 'Unsupported settings file.' ) ); }
		return self::normalize( $data['settings'] );
	}

	public static function blueprints(): array { return json_decode( (string) file_get_contents( ADC_PATH . 'resources/site-defaults.json' ), true )['pages'] ?? array(); }
	public static function contact_address( string $language = '' ): string {
		$settings = (array) get_option( 'car_dealer_theme_settings', array() );
		$address = (string) ( $settings['address'] ?? '' );
		if ( 'en' === ( $language ?: Localization::language() ) ) {
			$address = trim( (string) ( $settings['address_en'] ?? '' ) ) ?: ( preg_match( '/\p{Arabic}/u', $address ) ? '' : $address );
		}
		return trim( $address );
	}
	public static function legal_contact(): string {
		$settings = (array) get_option( 'car_dealer_theme_settings', array() );
		$parts = array( esc_html( self::brand( Localization::language() ) ) );
		$address = self::contact_address();
		if ( '' !== trim( $address ) ) { $parts[] = esc_html( $address ); }
		$email = sanitize_email( (string) ( $settings['email'] ?? '' ) );
		if ( $email ) { $parts[] = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>'; }
		$phone = (string) ( $settings['phone'] ?? '' ); $dial = preg_replace( '/[^+0-9]/', '', $phone );
		if ( $dial ) { $parts[] = '<a href="' . esc_url( 'tel:' . $dial ) . '" dir="ltr">' . esc_html( $phone ) . '</a>'; }
		return '<p>' . implode( ' · ', $parts ) . '</p><p>' . esc_html( self::t( 'يرجى عدم إرسال كلمات المرور أو بيانات الدفع الكاملة عبر البريد أو نماذج التواصل.', 'Please do not send passwords or complete payment details by email or contact forms.' ) ) . '</p>';
	}
	/** Replace only recognizable shipped defaults, never arbitrary editorial text. */
	private static function upgrade_legal_page( \WP_Post $existing, array $page ) {
		$old = $existing->post_content;
		$replace = in_array( trim( $old ), array( '', '<p>يرجى استكمال هذه السياسة ومراجعتها واعتمادها قبل النشر.</p>', '<p>Complete, review and approve this policy before publication.</p>' ), true );
		if ( ! $replace && 'privacy-policy' === $page['slug'] ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
			foreach ( array_unique( array( get_locale(), 'en_US', 'ar' ) ) as $locale ) {
				$switched = switch_to_locale( $locale );
				try { if ( trim( $old ) === trim( \WP_Privacy_Policy_Content::get_default_content() ) ) { $replace = true; } }
				finally { if ( $switched ) { restore_previous_locale(); } }
			}
		}
		if ( $replace ) {
			$result = wp_update_post( wp_slash( array( 'ID'=>$existing->ID, 'post_title'=>$page['title'], 'post_content'=>$page['content'] ) ), true );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		$source_matches = $replace || $old === $page['content'];
		foreach ( array( 'title', 'content' ) as $field ) {
			$key = '_adc_' . $field . '_en'; $english = (string) get_post_meta( $existing->ID, $key, true );
			$placeholder = in_array( trim( $english ), array( '', '<p>Complete, review and approve this policy before publication.</p>' ), true );
			if ( $source_matches && ( $placeholder || ( 'title' === $field && $replace ) ) ) {
				update_post_meta( $existing->ID, $key, wp_slash( $page[$field . '_en'] ) );
				$source = 'title' === $field ? get_post( $existing->ID )->post_title : $page['content'];
				update_post_meta( $existing->ID, $key . '_source_hash', hash( 'sha256', $source ) );
			}
		}
		$template = (string) get_post_meta( $existing->ID, '_wp_page_template', true );
		if ( in_array( $template, array( '', 'default' ), true ) && isset( wp_get_theme()->get_page_templates()['page-legal.php'] ) ) { update_post_meta( $existing->ID, '_wp_page_template', 'page-legal.php' ); }
		return true;
	}
	public static function create_pages() {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_setup_forbidden', 'Forbidden.' ); }
		global $wpdb; $lock = 'adc_product_pages_' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { return new \WP_Error( 'adc_setup_busy', 'Please try again.' ); }
		try {
			$ids = array();
			foreach ( self::blueprints() as $page ) {
				$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
				if ( $existing ) {
					if ( 'about' === $page['slug'] ) {
						$upgrade = AboutPage::upgrade($existing, $page); if (is_wp_error($upgrade)) { return $upgrade; }
						$template = (string)get_post_meta($existing->ID,'_wp_page_template',true);
						if (str_contains(get_post($existing->ID)->post_content,'adc-about-page') && in_array($template,array('','default'),true) && isset(wp_get_theme()->get_page_templates()['page-about.php'])) { update_post_meta($existing->ID,'_wp_page_template','page-about.php'); }
					}
					if ( ! empty( $page['legal'] ) ) { $upgrade = self::upgrade_legal_page( $existing, $page ); if ( is_wp_error( $upgrade ) ) { return $upgrade; } }
					if ( ! empty( $page['legal'] ) && 'draft' === $existing->post_status ) {
						$published = wp_update_post( array( 'ID'=>$existing->ID, 'post_status'=>'publish' ), true );
						if ( is_wp_error( $published ) ) { return $published; }
					}
					$ids[$page['slug']] = $existing->ID; continue;
				}
				$id = wp_insert_post( wp_slash( array( 'post_type'=>'page', 'post_name'=>$page['slug'], 'post_title'=>$page['title'], 'post_content'=>$page['content'], 'post_status'=>'publish', 'post_author'=>get_current_user_id(), 'comment_status'=>'closed' ) ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				if ('about' === $page['slug'] && isset(wp_get_theme()->get_page_templates()['page-about.php'])) { update_post_meta($id,'_wp_page_template','page-about.php'); }
				if ( ! empty( $page['legal'] ) && isset( wp_get_theme()->get_page_templates()['page-legal.php'] ) ) { update_post_meta( $id, '_wp_page_template', 'page-legal.php' ); }
				foreach ( array( 'title', 'content' ) as $field ) { update_post_meta( $id, '_adc_' . $field . '_en', wp_slash( $page[$field . '_en'] ) ); update_post_meta( $id, '_adc_' . $field . '_en_source_hash', hash( 'sha256', $page[$field] ) ); }
				$ids[$page['slug']] = $id;
			}
			$locations = get_theme_mod( 'nav_menu_locations', array() );
			foreach ( array( 'primary'=>array( 'cars', 'about', 'finance', 'faq', 'buying-guide', 'contact' ), 'footer'=>array( 'about', 'contact', 'faq', 'buying-guide', 'privacy-policy', 'terms', 'reservation-policy', 'cookie-policy' ) ) as $slot=>$slugs ) {
				$assigned = ! empty( $locations[$slot] ) ? wp_get_nav_menu_object( $locations[$slot] ) : false;
				$legacy_primary = false;
				if ( $assigned && 'primary' === $slot && self::brand() . ' — primary' === $assigned->name ) {
					$old_items = wp_get_nav_menu_items( $assigned->term_id ) ?: array();
					$old_slugs = array();
					foreach ( $old_items as $old_item ) {
						$post = get_post( (int) $old_item->object_id );
						$old_slugs[] = 'post_type' === $old_item->type && 'page' === $old_item->object && ! $old_item->menu_item_parent && $post && in_array( $old_item->post_title, array( '', $post->post_title ), true ) ? $post->post_name : '';
					}
					$legacy_primary = array( 'about', 'contact', 'finance', 'comparison' ) === $old_slugs;
					if ( $legacy_primary ) { $assigned = false; }
				}
				if ( $assigned && 'footer' !== $slot ) { continue; }
				if ( $assigned ) { $slugs = array( 'privacy-policy', 'terms', 'reservation-policy', 'cookie-policy' ); }
				$name = self::brand() . ' — ' . $slot . ( $legacy_primary ? ' v2' : '' );
				$existing_menu = $assigned ?: wp_get_nav_menu_object( $name );
				$menu = $existing_menu ? $existing_menu->term_id : wp_create_nav_menu( $name ); if ( is_wp_error( $menu ) ) { return $menu; }
				$items = wp_get_nav_menu_items( $menu ) ?: array();
				$linked = array_map( static fn( $item ): int => 'post_type' === $item->type && 'page' === $item->object ? (int) $item->object_id : 0, $items );
				if ( 'primary' === $slot && ! array_filter( $items, static fn( $item ): bool => 'post_type_archive' === $item->type && 'car' === $item->object ) ) {
					$item = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title'=>'كل السيارات', 'menu-item-object'=>'car', 'menu-item-type'=>'post_type_archive', 'menu-item-status'=>'publish', 'menu-item-position'=>1 ) );
					if ( is_wp_error( $item ) ) { return $item; }
				}
				foreach ( $slugs as $slug ) { if ( isset( $ids[$slug] ) && ! in_array( (int) $ids[$slug], $linked, true ) && 'publish' === get_post_status( $ids[$slug] ) ) { $item = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object-id'=>$ids[$slug], 'menu-item-object'=>'page', 'menu-item-type'=>'post_type', 'menu-item-status'=>'publish' ) ); if ( is_wp_error( $item ) ) { return $item; } } }
				$locations[$slot] = $menu;
			}
			set_theme_mod( 'nav_menu_locations', $locations );
			if ( ! get_option( 'wp_page_for_privacy_policy' ) && isset( $ids['privacy-policy'] ) ) { update_option( 'wp_page_for_privacy_policy', $ids['privacy-policy'] ); }
			flush_rewrite_rules( false );
			AuditLog::record( 'platform.pages_prepared', 'settings', 0, 'Missing neutral pages created; existing pages preserved', null, array( 'pages'=>array_keys( $ids ) ) );
			return $ids;
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	public static function readiness(): array {
		$mail = function_exists( 'adc_product_mail_config' ) ? adc_product_mail_config() : array();
		$local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$pages = true; $legal = true;
		foreach ( self::blueprints() as $row ) { $p = get_page_by_path( $row['slug'], OBJECT, 'page' ); if ( ! $p || 'publish' !== $p->post_status ) { if ( ! empty( $row['legal'] ) ) { $legal = false; } else { $pages = false; } } }
		$theme = wp_get_theme();
		return array(
			self::t( 'هوية المنشأة', 'Business identity' )=>! empty( self::profile()['brand_ar'] ) || self::legacy(),
			self::t( 'الثيم المتوافق وإصداره', 'Matching theme version' )=>'car-dealer' === get_template() && ADC_VERSION === $theme->get( 'Version' ),
			self::t( 'مخطط قاعدة البيانات', 'Database schema' )=>Schema::is_ready(),
			self::t( 'دعم استيراد المخزون الآمن', 'Transactional inventory storage' )=>\AutoDealership\Inventory\OnyxModelDrafts::transactional_tables(),
			self::t( 'اتصال HTTPS', 'HTTPS' )=>'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			self::t( 'SMTP حقيقي مهيّأ', 'Real SMTP configured' )=>'smtp' === ( $mail['mode'] ?? '' ) && ! empty( $mail['valid'] ),
			self::t( 'الصفحات الأساسية', 'Essential pages' )=>$pages,
			self::t( 'الصفحات القانونية منشورة', 'Legal pages published' )=>$legal,
			self::t( 'مهمة معالجة البريد مجدولة', 'Mail worker scheduled' )=>(bool) wp_next_scheduled( 'adc_process_outbox' ),
			self::t( 'بيانات التواصل', 'Contact details' )=>! empty( get_option( 'car_dealer_theme_settings', array() )['email'] ),
			self::t( 'بيئة إنتاج', 'Production environment' )=>! $local && 'production' === wp_get_environment_type(),
		);
	}

	private static function render_legal_pages(): void {
		echo '<h2>' . esc_html( self::t( 'صفحات السياسات القانونية', 'Legal policy pages' ) ) . '</h2><p>' . esc_html( self::t( 'تُنشر تلقائيًا وتُضاف إلى قائمة التذييل. يمكنك تعديل النصوص العربية والإنجليزية من محرر الصفحة.', 'Published automatically and added to the footer menu. Edit Arabic and English text in the page editor.' ) ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html( self::t( 'الصفحة', 'Page' ) ) . '</th><th>' . esc_html( self::t( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( self::t( 'الإجراءات', 'Actions' ) ) . '</th></tr></thead><tbody>';
		foreach ( self::blueprints() as $blueprint ) {
			if ( empty( $blueprint['legal'] ) ) { continue; }
			$page = get_page_by_path( $blueprint['slug'], OBJECT, 'page' );
			echo '<tr><td>' . esc_html( self::t( $blueprint['title'], $blueprint['title_en'] ) ) . '</td><td>' . esc_html( $page ? ( get_post_status_object( $page->post_status )->label ?? $page->post_status ) : self::t( 'غير موجودة', 'Missing' ) ) . '</td><td>';
			if ( $page && current_user_can( 'edit_post', $page->ID ) ) { echo '<a class="button" href="' . esc_url( get_edit_post_link( $page->ID, 'raw' ) ) . '">' . esc_html( self::t( 'تعديل الصفحة', 'Edit page' ) ) . '</a> '; }
			if ( $page && 'publish' === $page->post_status ) { echo '<a class="button" href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html( self::t( 'عرض الصفحة', 'View page' ) ) . '</a>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function save_mail( array $input ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'adc_setup_forbidden', 'Forbidden.' ); }
		$data = (array) get_option( self::MAIL, array() );
		if ( array_diff( array_keys( $input ), self::MAIL_KEYS ) ) { return new \WP_Error( 'adc_setup_mail', 'Unsupported mail fields.' ); }
		$mode = (string) ( $input['ADC_MAIL_MODE'] ?? 'disabled' );
		if ( ! in_array( $mode, array( 'disabled', 'smtp', 'mailpit' ), true ) || ( 'mailpit' === $mode && ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) ) { return new \WP_Error( 'adc_setup_mail', 'Invalid mail mode.' ); }
		foreach ( self::MAIL_KEYS as $key ) {
			if ( 'ADC_SMTP_PASSWORD' === $key ) { continue; }
			$value = $input[$key] ?? ''; if ( ! is_scalar( $value ) || strlen( (string) $value ) > 255 ) { return new \WP_Error( 'adc_setup_mail', 'Invalid mail value.' ); }
			$data[$key] = sanitize_text_field( (string) $value );
		}
		$password = $input['ADC_SMTP_PASSWORD'] ?? ''; if ( ! is_string( $password ) || strlen( $password ) > 4096 ) { return new \WP_Error( 'adc_setup_mail', 'Invalid password.' ); }
		if ( '' !== $password ) {
			if ( ! function_exists( 'openssl_encrypt' ) ) { return new \WP_Error( 'adc_setup_crypto', 'OpenSSL is required.' ); }
			$iv = random_bytes( 12 ); $tag = ''; $encrypted = openssl_encrypt( $password, 'aes-256-gcm', hash( 'sha256', wp_salt( 'secure_auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag, 'adc-product-mail-v1' );
			if ( false === $encrypted ) { return new \WP_Error( 'adc_setup_crypto', 'Encryption failed.' ); }
			$data['password'] = base64_encode( $iv . $tag . $encrypted );
		}
		$external_password = ( false !== getenv( 'ADC_SMTP_PASSWORD' ) || defined( 'ADC_SMTP_PASSWORD' ) ) && '' !== adc_product_mail_setting( 'ADC_SMTP_PASSWORD' );
		if ( 'smtp' === $mode && ( ! is_email( $data['ADC_MAIL_FROM'] ) || ! preg_match( '/\A[a-zA-Z0-9.-]+\z/', $data['ADC_SMTP_HOST'] ) || ! in_array( $data['ADC_SMTP_ENCRYPTION'], array( 'tls', 'ssl' ), true ) || ! ctype_digit( $data['ADC_SMTP_PORT'] ) || (int) $data['ADC_SMTP_PORT'] < 1 || (int) $data['ADC_SMTP_PORT'] > 65535 || ! $data['ADC_SMTP_USERNAME'] || ( empty( $data['password'] ) && ! $external_password ) ) ) { return new \WP_Error( 'adc_setup_mail', self::t( 'أكمل إعدادات SMTP الصحيحة.', 'Complete valid SMTP settings.' ) ); }
		$old = get_option( self::MAIL, null ); update_option( self::MAIL, $data, false );
		if ( get_option( self::MAIL ) !== $data || ! AuditLog::record( 'platform.mail_configured', 'settings', 0, 'Mail settings updated; credentials excluded from audit', null, array( 'mode'=>$mode ) ) ) { null === $old ? delete_option( self::MAIL ) : update_option( self::MAIL, $old, false ); return new \WP_Error( 'adc_setup_storage', 'Could not save.' ); }
		return true;
	}

	public static function submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_platform_setup' );
		$operation = sanitize_key( $_POST['operation'] ?? '' ); $section = sanitize_key( $_POST['section'] ?? 'identity' );
		if ( ! in_array( $section, self::SECTIONS, true ) ) { $section = 'identity'; }
		$result = true;
		if ( 'export' === $operation ) { nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="dealership-settings.json"' ); echo wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); exit; }
		if ( 'preview' === $operation ) {
			$file = $_FILES['settings_file'] ?? array();
			$result = empty( $file['error'] ) && ! empty( $file['tmp_name'] ) && is_uploaded_file( $file['tmp_name'] ) && (int) $file['size'] <= 262144 ? self::import_preview( (string) file_get_contents( $file['tmp_name'] ) ) : new \WP_Error( 'adc_setup_upload', self::t( 'اختر ملف JSON أصغر من 256 كيلوبايت.', 'Choose a JSON file smaller than 256 KB.' ) );
			if ( ! is_wp_error( $result ) ) { set_transient( 'adc_setup_preview_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS ); wp_safe_redirect( add_query_arg( 'preview', 1, self::url( 'readiness' ) ) ); exit; }
		} elseif ( 'import' === $operation ) {
			$data = get_transient( 'adc_setup_preview_' . get_current_user_id() );
			$preview_hash = isset( $_POST['preview_hash'] ) && is_string( $_POST['preview_hash'] ) ? $_POST['preview_hash'] : '';
			$result = is_array( $data ) && hash_equals( hash( 'sha256', wp_json_encode( $data ) ), $preview_hash ) ? self::apply( $data ) : new \WP_Error( 'adc_setup_expired', self::t( 'انتهت المعاينة أو تغيرت. ارفع الملف وراجعه مجددًا.', 'Preview expired or changed. Upload and review again.' ) );
			if ( ! is_wp_error( $result ) ) { delete_transient( 'adc_setup_preview_' . get_current_user_id() ); }
		} elseif ( 'pages' === $operation ) { $result = self::create_pages();
		} elseif ( 'mail_test' === $operation ) {
			$to = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
			$result = is_email( $to ) && wp_mail( $to, self::brand() . ' | SMTP test', self::t( 'هذه رسالة اختبار لإعداد البريد من لوحة الإدارة.', 'This is an administrator-requested mail configuration test.' ) ) ? true : new \WP_Error( 'adc_setup_test', self::t( 'تعذر إرسال رسالة الاختبار.', 'Test mail could not be sent.' ) );
		} elseif ( 'save' === $operation ) {
			$input = wp_unslash( $_POST['settings'] ?? array() );
			if ( ! is_array( $input ) ) { $input = array(); }
			$invalid_amount = false;
			if ( 'policy' === $section && isset( $input['reservation_deposit_value'] ) ) {
				$amount = \AutoDealership\Pricing\Money::from_sar( $input['reservation_deposit_value'] );
				$invalid_amount = null === $amount;
				$input['reservation_deposit_value'] = $amount;
			}
			$result = $invalid_amount ? new \WP_Error( 'adc_setup_amount', self::t( 'أدخل مبلغًا أو نسبة صحيحة بحد أقصى منزلتين عشريتين.', 'Enter a valid amount or percentage with up to two decimal places.' ) ) : ( 'mail' === $section ? self::save_mail( $input ) : self::apply( array( 'identity' === $section ? 'profile' : ( 'policy' === $section ? 'policy' : 'appearance' )=>$input ) ) );
		} else { $result = new \WP_Error( 'adc_setup_operation', 'Invalid operation.' ); }
		if ( is_wp_error( $result ) ) { set_transient( 'adc_setup_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS ); }
		else { set_transient( 'adc_setup_success_' . get_current_user_id(), true, MINUTE_IN_SECONDS ); }
		wp_safe_redirect( self::url( $section ) ); exit;
	}

	private static function form( string $section, string $operation = 'save', bool $upload = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="adc_platform_setup"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="section" value="' . esc_attr( $section ) . '">'; wp_nonce_field( 'adc_platform_setup' );
	}
	private static function field( string $key, string $label, string $value, string $type = 'text', array $choices = array() ): void {
		if ( $choices ) {
			echo '<tr><th><label for="setup-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="setup-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']">';
			foreach ( $choices as $choice=>$text ) { echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( (string) $choice, $value, false ) . '>' . esc_html( $text ) . '</option>'; }
			echo '</select></td></tr>'; return;
		}
		echo '<tr><th><label for="setup-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="setup-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( 'password' === $type ? ' autocomplete="new-password"' : '' ) . '></td></tr>';
	}
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$section = sanitize_key( $_GET['section'] ?? 'identity' ); if ( ! in_array( $section, self::SECTIONS, true ) ) { $section = 'identity'; }
		$labels = array( 'identity'=>self::t( 'الهوية واللغة', 'Identity and language' ), 'appearance'=>self::t( 'المظهر والتواصل', 'Appearance and contact' ), 'policy'=>self::t( 'السياسات', 'Policies' ), 'mail'=>self::t( 'البريد', 'Email' ), 'pages'=>self::t( 'الصفحات والقوائم', 'Pages and menus' ), 'readiness'=>self::t( 'الجاهزية ونقل الإعدادات', 'Readiness and settings transfer' ) );
		echo '<div class="wrap"><h1>' . esc_html( self::t( 'إعداد المنصة', 'Platform setup' ) ) . '</h1><p>' . esc_html( self::t( 'إعداد مستقل لكل منشأة. تُحفظ البيانات والتخصيصات الحالية، ولا تُضاف بيانات تشغيل تجريبية.', 'Independent setup for each business. Existing data and customizations are preserved; no demo business data is created.' ) ) . '</p>';
		$error = get_transient( 'adc_setup_error_' . get_current_user_id() ); if ( $error ) { delete_transient( 'adc_setup_error_' . get_current_user_id() ); echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
		if ( get_transient( 'adc_setup_success_' . get_current_user_id() ) ) { delete_transient( 'adc_setup_success_' . get_current_user_id() ); echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'تم تنفيذ الإجراء بنجاح.', 'Action completed successfully.' ) ) . '</p></div>'; }
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr( self::t( 'خطوات الإعداد', 'Setup steps' ) ) . '">'; foreach ( $labels as $key=>$label ) { echo '<a class="nav-tab' . ( $key === $section ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $key ) ) . '">' . esc_html( $label ) . '</a>'; } echo '</nav>';
		if ( in_array( $section, array( 'identity', 'appearance', 'policy', 'mail' ), true ) ) {
			self::form( $section ); echo '<table class="form-table" role="presentation">';
			if ( 'identity' === $section ) {
				$p = array_replace( self::profile_defaults(), self::profile() ); $fields = array( 'brand_ar'=>array( 'اسم المنشأة بالعربية', 'Business name (Arabic)' ), 'brand_en'=>array( 'اسم المنشأة بالإنجليزية', 'Business name (English)' ), 'description_ar'=>array( 'وصف الموقع بالعربية', 'Site description (Arabic)' ), 'description_en'=>array( 'وصف الموقع بالإنجليزية', 'Site description (English)' ), 'hero_ar'=>array( 'وصف الواجهة بالعربية', 'Homepage description (Arabic)' ), 'hero_en'=>array( 'وصف الواجهة بالإنجليزية', 'Homepage description (English)' ), 'language'=>array( 'اللغة الافتراضية', 'Default language' ), 'timezone'=>array( 'المنطقة الزمنية، مثل Asia/Riyadh', 'Timezone, e.g. Asia/Riyadh' ) );
				foreach ( $fields as $key=>$label ) { $default = 'brand_ar' === $key ? self::brand() : ( 'brand_en' === $key ? self::brand( 'en' ) : ( 'timezone' === $key ? wp_timezone_string() : ( 'language' === $key ? 'ar' : '' ) ) ); self::field( $key, self::t( ...$label ), (string) ( $p[$key] ?? $default ), 'text', 'language' === $key ? array( 'ar'=>'العربية', 'en'=>'English' ) : ( 'timezone' === $key ? array_combine( timezone_identifiers_list(), timezone_identifiers_list() ) : array() ) ); }
			} elseif ( 'appearance' === $section ) {
				$p = (array) get_option( 'car_dealer_theme_settings', array() ); foreach ( array( 'primary_color'=>array( 'اللون الرئيسي', 'Primary color' ), 'accent_color'=>array( 'لون الأزرار', 'Accent color' ), 'phone'=>array( 'الهاتف', 'Phone' ), 'email'=>array( 'بريد التواصل', 'Contact email' ), 'address'=>array( 'العنوان بالعربية', 'Address (Arabic)' ), 'address_en'=>array( 'العنوان بالإنجليزية', 'Address (English)' ), 'whatsapp'=>array( 'واتساب مع رمز الدولة', 'WhatsApp with country code' ), 'facebook'=>array( 'فيسبوك', 'Facebook' ), 'instagram'=>array( 'إنستغرام', 'Instagram' ) ) as $key=>$label ) { self::field( $key, self::t( ...$label ), (string) ( $p[$key] ?? ( 'primary_color' === $key ? '#0E2A4A' : ( 'accent_color' === $key ? '#2563EB' : '' ) ) ), str_contains( $key, 'color' ) ? 'color' : ( 'email' === $key ? 'email' : 'text' ) ); }
			} elseif ( 'policy' === $section ) {
				$p = self::export()['settings']['policy']; foreach ( array( 'vat_percent'=>array( 'الضريبة (%)', 'VAT (%)' ), 'reservation_hours'=>array( 'مدة الحجز بالساعات', 'Reservation hours' ), 'reservation_refund_hours'=>array( 'مدة الاسترداد بالساعات', 'Refund hours' ), 'reservation_deposit_type'=>array( 'نوع العربون', 'Deposit type' ), 'reservation_deposit_value'=>array( 'قيمة العربون (ريال) أو النسبة (%)', 'Deposit amount (SAR) or percentage (%)' ), 'privacy_retention_days'=>array( 'الاحتفاظ بالأيام؛ 0 لتعطيل الحذف', 'Retention days; 0 disables erasure' ) ) as $key=>$label ) { self::field( $key, self::t( ...$label ), (string) ( 'reservation_deposit_value' === $key ? $p[$key] / 100 : $p[$key] ), 'text', 'reservation_deposit_type' === $key ? array( 'none'=>self::t( 'بدون عربون', 'No deposit' ), 'fixed'=>self::t( 'مبلغ ثابت', 'Fixed amount' ), 'percentage'=>self::t( 'نسبة مئوية', 'Percentage' ) ) : array() ); }
			} else {
				$mail_labels = array( 'ADC_MAIL_MODE'=>self::t( 'طريقة الإرسال', 'Mail transport' ), 'ADC_SMTP_HOST'=>self::t( 'خادم SMTP', 'SMTP host' ), 'ADC_SMTP_PORT'=>self::t( 'المنفذ', 'Port' ), 'ADC_SMTP_ENCRYPTION'=>self::t( 'تشفير الاتصال', 'Connection encryption' ), 'ADC_SMTP_USERNAME'=>self::t( 'اسم المستخدم', 'Username' ), 'ADC_SMTP_PASSWORD'=>self::t( 'كلمة مرور SMTP', 'SMTP password' ), 'ADC_MAIL_FROM'=>self::t( 'بريد المرسل', 'Sender email' ), 'ADC_MAIL_FROM_NAME'=>self::t( 'اسم المرسل', 'Sender name' ) );
				foreach ( self::MAIL_KEYS as $key ) {
					$external = false !== getenv( $key ) || defined( $key );
					$value = 'ADC_SMTP_PASSWORD' === $key ? '' : ( $external ? adc_product_mail_setting( $key ) : self::mail_setting( $key, 'ADC_MAIL_MODE' === $key ? 'disabled' : ( 'ADC_SMTP_PORT' === $key ? '587' : ( 'ADC_SMTP_ENCRYPTION' === $key ? 'tls' : '' ) ) ) );
					$choices = array();
					if ( 'ADC_MAIL_MODE' === $key ) { $choices = array( 'disabled'=>self::t( 'متوقف', 'Disabled' ), 'smtp'=>self::t( 'SMTP حقيقي', 'Real SMTP' ) ); if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { $choices['mailpit'] = 'Mailpit'; } }
					if ( 'ADC_SMTP_ENCRYPTION' === $key ) { $choices = array( 'tls'=>'STARTTLS', 'ssl'=>'TLS / SSL' ); }
					self::field( $key, $mail_labels[$key] . ( $external ? self::t( ' (محدد من الخادم)', ' (server override)' ) : '' ), $value, 'ADC_SMTP_PASSWORD' === $key ? 'password' : ( 'ADC_MAIL_FROM' === $key ? 'email' : 'text' ), $choices );
				}
			}
			echo '</table>'; submit_button( self::t( 'حفظ الإعدادات', 'Save settings' ) ); echo '</form>';
			if ( 'appearance' === $section ) { echo '<p><a class="button" href="' . esc_url( admin_url( 'options-general.php?page=adc-branch-locations' ) ) . '">' . esc_html( self::t( 'إدارة الفروع والخرائط', 'Manage branches and maps' ) ) . '</a></p>'; echo '<p><a class="button" href="' . esc_url( admin_url( 'customize.php?autofocus[control]=custom_logo' ) ) . '">' . esc_html( self::t( 'اختيار الشعار وأيقونة الموقع', 'Choose logo and site icon' ) ) . '</a></p>'; }
			if ( 'policy' === $section ) { echo '<p>' . esc_html( self::t( 'العملة الحالية: الريال السعودي (SAR). إعداد الفروع والسياسات المتقدمة متاح في إعدادات النظام.', 'Current currency: Saudi Riyal (SAR). Branches and advanced policies are available in system settings.' ) ) . '</p>'; self::render_legal_pages(); }
			if ( 'mail' === $section ) { echo '<p>' . esc_html( self::t( 'الأوضاع: smtp / disabled / mailpit محليًا فقط. ترك كلمة المرور فارغة يحافظ عليها. إعدادات الخادم الخارجية لها الأولوية، والأسرار لا تدخل ملف التصدير. تغيير مفاتيح ووردبريس يتطلب إعادة إدخال كلمة مرور SMTP.', 'Modes: smtp / disabled / mailpit (local only). Leave password blank to keep it. Server environment/configuration overrides these settings. Secrets are never exported. Changing WordPress salts requires re-entering SMTP credentials.' ) ) . '</p>'; self::form( 'mail', 'mail_test' ); echo '<label>' . esc_html( self::t( 'عنوان رسالة الاختبار', 'Test recipient' ) ) . ' <input type="email" name="test_email" required></label>'; submit_button( self::t( 'إرسال اختبار الآن', 'Send test now' ), 'secondary' ); echo '</form>'; }
		} elseif ( 'pages' === $section ) {
			echo '<p>' . esc_html( self::t( 'تُجهّز الصفحات وتُنشر السياسات تلقائيًا مع إضافتها إلى التذييل. لا تُستبدل نصوص الصفحات أو عناصر القوائم الحالية.', 'Pages are prepared and policies published automatically with footer links. Existing page text and menu items are preserved.' ) ) . '</p>'; self::form( 'pages', 'pages' ); submit_button( self::t( 'تجهيز الصفحات والقوائم', 'Prepare pages and menus' ) ); echo '</form>'; self::render_legal_pages();
		} else {
			echo '<table class="widefat striped"><tbody>'; foreach ( self::readiness() as $label=>$ok ) { echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $ok ? self::t( 'مكتمل', 'Ready' ) : self::t( 'يحتاج مراجعة', 'Needs review' ) ) . '</td></tr>'; } echo '</tbody></table><p>' . esc_html( self::t( 'الجاهزية الآلية لا تثبت تسليم البريد أو تشغيل جدولة الخادم أو نجاح استعادة النسخ الاحتياطية؛ يجب اختبارها على الاستضافة.', 'Automatic checks do not prove email delivery, server scheduling or backup restoration; verify these on the host.' ) ) . '</p>';
			self::form( 'readiness', 'export' ); submit_button( self::t( 'تصدير الإعدادات فقط', 'Export settings only' ), 'secondary' ); echo '</form>';
			self::form( 'readiness', 'preview', true ); echo '<label>' . esc_html( self::t( 'ملف إعدادات JSON', 'Settings JSON file' ) ) . ' <input type="file" name="settings_file" accept=".json,application/json" required></label>'; submit_button( self::t( 'معاينة الاستيراد', 'Preview import' ), 'secondary' ); echo '</form>';
			if ( isset( $_GET['preview'] ) ) { $preview = get_transient( 'adc_setup_preview_' . get_current_user_id() ); if ( is_array( $preview ) ) { echo '<h2>' . esc_html( self::t( 'مراجعة التغييرات قبل التطبيق', 'Review changes before applying' ) ) . '</h2><pre style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>'; self::form( 'readiness', 'import' ); echo '<input type="hidden" name="preview_hash" value="' . esc_attr( hash( 'sha256', wp_json_encode( $preview ) ) ) . '">'; submit_button( self::t( 'تطبيق الإعدادات المعروضة', 'Apply displayed settings' ) ); echo '</form>'; } }
		}
		echo '</div>';
	}
}
