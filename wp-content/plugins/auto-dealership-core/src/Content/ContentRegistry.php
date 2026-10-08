<?php
namespace AutoDealership\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the durable WordPress content contract used by the public dealership site.
 *
 * Operational inventory remains in the plugin tables. The car and offer posts are
 * the editorial/public projection and keep their existing identifiers and URLs.
 */
final class ContentRegistry {
	public const VERSION = '1.0.0';
	public const OPTION_VERSION = 'adc_content_registry_version';

	private static bool $booted = false;
	private static array $collisions = array();
	private static array $registered = array();

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		add_action( 'init', array( self::class, 'register' ), 5 );
		add_action( 'admin_init', array( self::class, 'maybe_upgrade_rewrites' ), 5 );
		add_action( 'admin_notices', array( self::class, 'render_collision_notice' ) );
	}

	/** Stable ownership signal used by replaceable themes. */
	public static function owns( string $object = '' ): bool {
		if ( ! self::$booted ) {
			return false;
		}

		return '' === $object || in_array( $object, array( 'car', 'car_offer', 'car_brand', 'car_category' ), true );
	}

	/** Registers public identifiers without creating or changing content records. */
	public static function register(): void {
		self::register_car();
		self::register_offer();
		self::register_taxonomies();
		self::register_meta_contracts();
	}

	/** Activation is the only unconditional rewrite flush for a fresh install. */
	public static function activate(): void {
		self::register();
		flush_rewrite_rules( false );
		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	/** Flush once after a registry contract upgrade, and only from an administrator request. */
	public static function maybe_upgrade_rewrites(): void {
		if ( ! current_user_can( 'manage_options' ) || self::VERSION === get_option( self::OPTION_VERSION ) ) {
			return;
		}

		self::register();
		flush_rewrite_rules( false );
		update_option( self::OPTION_VERSION, self::VERSION, false );
	}

	/** Machine-readable contract for diagnostics and future theme adapters. */
	public static function contract(): array {
		return array(
			'version'    => self::VERSION,
			'post_types' => array( 'car', 'car_offer' ),
			'taxonomies' => array( 'car_brand', 'car_category' ),
			'meta'       => self::meta_contracts(),
		);
	}

	public static function render_collision_notice(): void {
		if ( ! self::$collisions || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html(
			sprintf(
				/* translators: %s: comma-separated content object names. */
				__( 'Auto Dealership Core could not claim its content registry because these identifiers were already registered before it: %s', 'auto-dealership-core' ),
				implode( ', ', array_unique( self::$collisions ) )
			)
		) . '</p></div>';
	}

	private static function register_car(): void {
		if ( post_type_exists( 'car' ) ) {
			if ( empty( self::$registered['car'] ) ) {
				self::$collisions[] = 'car';
			}
			return;
		}

		register_post_type( 'car', array(
			'labels' => array(
				'name'                  => __( 'السيارات', 'auto-dealership-core' ),
				'singular_name'         => __( 'سيارة', 'auto-dealership-core' ),
				'menu_name'             => __( 'السيارات المنشورة', 'auto-dealership-core' ),
				'name_admin_bar'        => __( 'سيارة', 'auto-dealership-core' ),
				'add_new'               => __( 'إضافة سيارة', 'auto-dealership-core' ),
				'add_new_item'          => __( 'إضافة سيارة جديدة', 'auto-dealership-core' ),
				'new_item'              => __( 'سيارة جديدة', 'auto-dealership-core' ),
				'edit_item'             => __( 'تعديل السيارة', 'auto-dealership-core' ),
				'view_item'             => __( 'عرض السيارة', 'auto-dealership-core' ),
				'all_items'             => __( 'كل السيارات المنشورة', 'auto-dealership-core' ),
				'search_items'          => __( 'البحث في السيارات', 'auto-dealership-core' ),
				'not_found'             => __( 'لا توجد سيارات', 'auto-dealership-core' ),
				'not_found_in_trash'    => __( 'لا توجد سيارات في سلة المهملات', 'auto-dealership-core' ),
				'featured_image'        => __( 'صورة السيارة الرئيسية', 'auto-dealership-core' ),
				'set_featured_image'    => __( 'تعيين صورة السيارة', 'auto-dealership-core' ),
				'remove_featured_image' => __( 'إزالة صورة السيارة', 'auto-dealership-core' ),
				'use_featured_image'    => __( 'استخدام كصورة السيارة', 'auto-dealership-core' ),
				'item_published'        => __( 'تم نشر السيارة', 'auto-dealership-core' ),
				'item_updated'          => __( 'تم تحديث السيارة', 'auto-dealership-core' ),
			),
			'public'          => true,
			'has_archive'     => true,
			'rewrite'         => array( 'slug' => 'cars' ),
			'menu_icon'       => 'dashicons-car',
			'show_in_menu'    => 'adc-area-content',
			'show_in_rest'    => true,
			'capability_type' => array( 'car', 'cars' ),
			'map_meta_cap'    => true,
			'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
		) );
		self::$registered['car'] = true;
	}

	private static function register_offer(): void {
		if ( post_type_exists( 'car_offer' ) ) {
			if ( empty( self::$registered['car_offer'] ) ) {
				self::$collisions[] = 'car_offer';
			}
			return;
		}

		register_post_type( 'car_offer', array(
			'labels' => array(
				'name'                  => __( 'العروض', 'auto-dealership-core' ),
				'singular_name'         => __( 'عرض', 'auto-dealership-core' ),
				'menu_name'             => __( 'العروض الترويجية', 'auto-dealership-core' ),
				'name_admin_bar'        => __( 'عرض', 'auto-dealership-core' ),
				'add_new'               => __( 'إضافة عرض', 'auto-dealership-core' ),
				'add_new_item'          => __( 'إضافة عرض جديد', 'auto-dealership-core' ),
				'new_item'              => __( 'عرض جديد', 'auto-dealership-core' ),
				'edit_item'             => __( 'تعديل العرض', 'auto-dealership-core' ),
				'view_item'             => __( 'عرض التفاصيل', 'auto-dealership-core' ),
				'all_items'             => __( 'كل العروض', 'auto-dealership-core' ),
				'search_items'          => __( 'البحث في العروض', 'auto-dealership-core' ),
				'not_found'             => __( 'لا توجد عروض', 'auto-dealership-core' ),
				'not_found_in_trash'    => __( 'لا توجد عروض في سلة المهملات', 'auto-dealership-core' ),
				'featured_image'        => __( 'صورة العرض', 'auto-dealership-core' ),
				'set_featured_image'    => __( 'تعيين صورة العرض', 'auto-dealership-core' ),
				'remove_featured_image' => __( 'إزالة صورة العرض', 'auto-dealership-core' ),
				'use_featured_image'    => __( 'استخدام كصورة العرض', 'auto-dealership-core' ),
				'item_published'        => __( 'تم نشر العرض', 'auto-dealership-core' ),
				'item_updated'          => __( 'تم تحديث العرض', 'auto-dealership-core' ),
			),
			'public'       => true,
			'has_archive'  => true,
			'rewrite'      => array( 'slug' => 'offers' ),
			'show_in_rest' => true,
			'show_in_menu' => 'adc-area-content',
			'menu_icon'    => 'dashicons-megaphone',
			'capability_type' => array( 'car_offer', 'car_offers' ),
			'map_meta_cap'    => true,
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
		) );
		self::$registered['car_offer'] = true;
	}

	private static function register_taxonomies(): void {
		if ( taxonomy_exists( 'car_brand' ) ) {
			if ( empty( self::$registered['car_brand'] ) ) {
				self::$collisions[] = 'car_brand';
			}
		} else {
			register_taxonomy( 'car_brand', 'car', array(
				'labels' => array(
					'name'          => __( 'الماركات', 'auto-dealership-core' ),
					'singular_name' => __( 'ماركة', 'auto-dealership-core' ),
					'add_new_item'  => __( 'إضافة ماركة جديدة', 'auto-dealership-core' ),
					'edit_item'     => __( 'تعديل الماركة', 'auto-dealership-core' ),
					'search_items'  => __( 'البحث في الماركات', 'auto-dealership-core' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'car-brand' ),
				'capabilities' => array(
					'manage_terms' => 'manage_car_brands',
					'edit_terms'   => 'manage_car_brands',
					'delete_terms' => 'manage_car_brands',
					'assign_terms' => 'assign_car_brands',
				),
			) );
			self::$registered['car_brand'] = true;
		}

		if ( taxonomy_exists( 'car_category' ) ) {
			if ( empty( self::$registered['car_category'] ) ) {
				self::$collisions[] = 'car_category';
			}
		} else {
			register_taxonomy( 'car_category', 'car', array(
				'labels' => array(
					'name'          => __( 'الفئات', 'auto-dealership-core' ),
					'singular_name' => __( 'فئة', 'auto-dealership-core' ),
					'add_new_item'  => __( 'إضافة فئة جديدة', 'auto-dealership-core' ),
					'edit_item'     => __( 'تعديل الفئة', 'auto-dealership-core' ),
					'search_items'  => __( 'البحث في الفئات', 'auto-dealership-core' ),
				),
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'car-category' ),
				'capabilities' => array(
					'manage_terms' => 'manage_car_categories',
					'edit_terms'   => 'manage_car_categories',
					'delete_terms' => 'manage_car_categories',
					'assign_terms' => 'assign_car_categories',
				),
			) );
			self::$registered['car_category'] = true;
		}
	}

	private static function register_meta_contracts(): void {
		foreach ( self::meta_contracts() as $post_type => $keys ) {
			foreach ( $keys as $key ) {
				register_post_meta( $post_type, $key, array(
					'type'         => 'string',
					'single'       => true,
					'show_in_rest' => false,
					'description'  => __( 'Legacy-compatible dealership content metadata. Writes are owned by plugin services.', 'auto-dealership-core' ),
				) );
			}
		}
	}

	private static function meta_contracts(): array {
		return array(
			'car' => array(
				'_car_vin', '_car_stock_number', '_car_stock', '_car_make', '_car_model', '_car_trim',
				'_car_year', '_car_condition', '_car_body_type', '_car_fuel_type', '_car_transmission',
				'_car_mileage', '_car_kilometers', '_car_price', '_car_monthly_payment', '_car_down_payment',
				'_car_inventory_status', '_car_location', '_car_color', '_car_interior_color', '_car_engine_size',
				'_car_drivetrain', '_car_doors', '_car_seats', '_car_horsepower', '_car_video_url', '_car_warranty',
				'_car_features', '_car_interior_features', '_car_exterior_features', '_car_safety_features',
				'_car_featured', '_car_is_featured', '_car_demand', '_car_special_offer', '_car_is_offer',
			),
			'car_offer' => array(
				'_offer_car_id', '_offer_old_price', '_offer_new_price', '_offer_monthly_payment', '_offer_expires',
			),
		);
	}
}
