<?php
namespace AutoDealership\Inventory;

defined( 'ABSPATH' ) || exit;

/** URL-bound catalog language and search-index policy, independent of the active theme. */
final class CatalogPresentation {
	private const FILTER_KEYS = array(
		'search', 'brand', 'model', 'trim', 'min_year', 'max_year', 'min_price', 'max_price',
		'min_mileage', 'max_mileage', 'body_type', 'fuel_type', 'transmission', 'engine_size',
		'drivetrain', 'exterior_color', 'interior_color', 'branch_id', 'condition', 'sort',
	);

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_catalog_presentation_enabled', true );
	}

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_filter( 'get_canonical_url', array( self::class, 'singular_canonical' ), 10, 2 );
		add_action( 'wp_head', array( self::class, 'print_links' ), 9 );
		add_filter( 'gettext', array( self::class, 'public_gettext' ), 10, 3 );
	}

	/** Translate plugin-owned public shortcode copy without changing the dashboard locale. */
	public static function public_gettext( string $translation, string $text, string $domain ): string {
		if ( 'auto-dealership-core' !== $domain || 'en' !== self::language() || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $translation;
		}
		$copy = array(
			'حاسبة التمويل' => 'Finance Calculator', 'سعر السيارة' => 'Vehicle price',
			'الدفعة الأولى' => 'Down payment', 'النسبة السنوية التقديرية %' => 'Estimated annual rate %',
			'المدة بالأشهر' => 'Term in months', 'احسب' => 'Calculate',
			'القسط الشهري التقديري:' => 'Estimated monthly payment:',
			'هذا تقدير إرشادي وليس عرض تمويل أو موافقة.' => 'This is an estimate, not a financing offer or approval.',
			'راجع مبلغ السيارة والدفعة والنسبة والمدة ثم حاول مجددًا.' => 'Check the vehicle price, down payment, rate and term, then try again.',
			'تعذر حساب التقدير حاليًا.' => 'The estimate is currently unavailable.',
			'انتهت صلاحية الطلب. حدّث الصفحة وحاول مجددًا.' => 'This request has expired. Refresh the page and try again.',
			'جارٍ الحساب…' => 'Calculating…',
			'تعذر حساب التقدير. راجع القيم وحاول مجددًا.' => 'Could not calculate the estimate. Check the values and try again.',
			'نموذج التواصل غير متاح حاليًا.' => 'The contact form is currently unavailable.',
			'الاسم' => 'Name', 'البريد الإلكتروني' => 'Email address', 'الهاتف' => 'Phone',
			'رسالتك' => 'Your message', 'إرسال' => 'Send', 'تحديث بيانات حسابي' => 'Update my account details',
		);
		return $copy[ $text ] ?? $translation;
	}

	public static function language(): string {
		$source = wp_doing_ajax() ? $_REQUEST : $_GET;
		$value = isset( $source['lang'] ) && is_scalar( $source['lang'] ) ? sanitize_key( wp_unslash( (string) $source['lang'] ) ) : '';
		return 'en' === $value ? 'en' : 'ar';
	}

	public static function is_catalog_request(): bool {
		if ( wp_doing_ajax() ) {
			return isset( $_REQUEST['lang'] ) && 'en' === self::language();
		}
		return did_action( 'wp' ) && ( is_post_type_archive( 'car' ) || is_singular( 'car' ) );
	}

	public static function localized_url( string $url, string $language = '' ): string {
		$language = in_array( $language, array( 'ar', 'en' ), true ) ? $language : self::language();
		return 'en' === $language ? add_query_arg( 'lang', 'en', $url ) : remove_query_arg( 'lang', $url );
	}

	/** Switch language on the current public view without carrying arbitrary request values. */
	public static function language_url( string $language ): string {
		if ( isset( $_GET['cd_account'] ) && is_scalar( $_GET['cd_account'] ) && in_array( sanitize_key( wp_unslash( (string) $_GET['cd_account'] ) ), array( 'login', 'register', 'dashboard' ), true ) ) {
			$base = home_url( '/' );
		} elseif ( is_singular() ) {
			$base = get_permalink();
		} elseif ( is_post_type_archive( 'car' ) || is_post_type_archive( 'car_offer' ) ) {
			$base = get_post_type_archive_link( is_post_type_archive( 'car' ) ? 'car' : 'car_offer' );
		} else {
			$base = home_url( '/' );
		}
		if ( ! is_string( $base ) || '' === $base ) {
			return '';
		}
		if ( is_post_type_archive( 'car' ) ) {
			foreach ( array_merge( self::FILTER_KEYS, array( 'paged' ) ) as $key ) {
				if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] ) {
					$base = add_query_arg( $key, sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ), $base );
				}
			}
		}
		if ( is_search() && isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ) {
			$base = add_query_arg( 's', mb_substr( sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ), 0, 120 ), $base );
		}
		if ( isset( $_GET['cd_account'] ) && is_scalar( $_GET['cd_account'] ) ) {
			$view = sanitize_key( wp_unslash( (string) $_GET['cd_account'] ) );
			if ( in_array( $view, array( 'login', 'register', 'dashboard' ), true ) ) {
				$base = add_query_arg( 'cd_account', $view, $base );
			}
		}
		return self::localized_url( $base, $language );
	}

	public static function has_active_filters(): bool {
		if ( ! is_post_type_archive( 'car' ) ) {
			return false;
		}
		foreach ( self::FILTER_KEYS as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) && '' !== (string) $_GET[ $key ] && ! ( 'sort' === $key && 'newest' === $_GET[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function robots( array $robots ): array {
		if ( PublicCatalog::is_authoritative() && self::has_active_filters() ) {
			$robots['noindex'] = true;
			$robots['follow'] = true;
		}
		return $robots;
	}

	public static function singular_canonical( string $url, \WP_Post $post ): string {
		return in_array( $post->post_type, array( 'page', 'car', 'car_offer' ), true ) ? self::localized_url( $url ) : $url;
	}

	public static function print_links(): void {
		if ( isset( $_GET['cd_account'] ) ) {
			return;
		}
		if ( ! ( is_front_page() || is_home() || is_singular( array( 'page', 'car', 'car_offer' ) ) || is_post_type_archive( array( 'car', 'car_offer' ) ) ) ) {
			return;
		}
		$archive = is_post_type_archive( 'car' );
		$base = $archive ? get_post_type_archive_link( 'car' ) : ( is_post_type_archive( 'car_offer' ) ? get_post_type_archive_link( 'car_offer' ) : ( is_singular() ? get_permalink() : home_url( '/' ) ) );
		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}
		if ( $archive && PublicCatalog::is_authoritative() && ( self::has_active_filters() || 'en' === self::language() ) ) {
			echo '<link rel="canonical" href="' . esc_url( self::localized_url( $base ) ) . '">' . "\n";
		} elseif ( ! is_singular() && ! $archive ) {
			echo '<link rel="canonical" href="' . esc_url( self::localized_url( $base ) ) . '">' . "\n";
		}
		foreach ( array( 'ar' => 'ar', 'en' => 'en', 'x-default' => 'ar' ) as $hreflang => $language ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $hreflang ) . '" href="' . esc_url( self::localized_url( $base, $language ) ) . '">' . "\n";
		}
	}
}
