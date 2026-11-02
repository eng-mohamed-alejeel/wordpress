<?php
namespace AutoDealership\SEO;

use AutoDealership\Core\BranchLocations;
use AutoDealership\Core\ProductSetup;
use AutoDealership\Core\SocialChannels;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only SEO readiness checks for the current single-site installation.
 *
 * The checks intentionally avoid remote requests and writes. Production-only
 * checks such as Search Console, PageSpeed and HTTPS redirects remain visible
 * as explicit follow-up items instead of being reported as locally complete.
 */
final class SeoHealth {
	/** @return array<int,array<string,string|int>> */
	public static function snapshot(): array {
		$settings = PublicSeo::settings();
		$checks   = array();
		$home     = home_url( '/' );
		$site     = site_url( '/' );

		$home_https = 'https' === strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
		$site_https = 'https' === strtolower( (string) wp_parse_url( $site, PHP_URL_SCHEME ) );
		$checks[]   = self::check(
			'site.https',
			ProductSetup::t( 'عنوان الموقع العام', 'Public site address' ),
			$home_https && $site_https ? 'pass' : 'warning',
			$home_https && $site_https
				? ProductSetup::t( 'عنوان الموقع وعنوان ووردبريس يستخدمان HTTPS.', 'The site and WordPress addresses use HTTPS.' )
				: ProductSetup::t( 'يستخدم الموقع HTTP حاليًا؛ هذا مقبول محليًا، ويجب تحويله إلى HTTPS قبل النشر.', 'The site currently uses HTTP; this is acceptable locally but must be HTTPS before production.' ),
			$home
		);

		$brand = trim( ProductSetup::brand( 'ar' ) );
		$checks[] = self::check(
			'site.identity',
			ProductSetup::t( 'هوية الموقع', 'Site identity' ),
			$brand ? 'pass' : 'fail',
			$brand
				? ProductSetup::t( 'اسم المنشأة متاح للتوليد الديناميكي.', 'The business name is available for dynamic generation.' )
				: ProductSetup::t( 'اسم المنشأة غير مضبوط؛ ستظهر عناوين عامة.', 'The business name is missing; generic titles may be generated.' ),
			admin_url( 'admin.php?page=adc-platform-setup' )
		);

		$template_keys = array_filter(
			array_keys( $settings ),
			static fn( $key ): bool => is_string( $key ) && ( str_contains( $key, '_title_' ) || str_contains( $key, '_description_' ) )
		);
		$empty_templates = array_filter( $template_keys, static fn( $key ): bool => '' === trim( (string) ( $settings[ $key ] ?? '' ) ) );
		$checks[] = self::check(
			'metadata.templates',
			ProductSetup::t( 'قوالب العناوين والأوصاف', 'Title and description templates' ),
			$empty_templates ? 'warning' : 'pass',
			$empty_templates
				? sprintf( ProductSetup::t( 'يوجد %d قالب فارغ؛ سيستخدم النظام fallback العام.', '%d template(s) are empty; the system will use the general fallback.' ), count( $empty_templates ) )
				: ProductSetup::t( 'كل قوالب الصفحات الأساسية تحتوي قيمة قابلة للتوليد.', 'All core page templates contain a generatable value.' ),
			admin_url( 'admin.php?page=adc-seo' )
		);

		$theme = (array) get_option( 'car_dealer_theme_settings', array() );
		$phone = trim( (string) ( $theme['phone'] ?? '' ) );
		$email = sanitize_email( (string) ( $theme['email'] ?? '' ) );
		$address = ProductSetup::contact_address( 'ar' ) ?: ProductSetup::contact_address( 'en' );
		$checks[] = self::check(
			'organization.contact',
			ProductSetup::t( 'بيانات المنشأة العامة', 'Public business contact' ),
			$phone || $email || $address ? 'pass' : 'warning',
			$phone || $email || $address
				? ProductSetup::t( 'توجد بيانات يمكن استخدامها في Organization وAutoDealer.', 'Public contact data is available for Organization and AutoDealer schema.' )
				: ProductSetup::t( 'أضف هاتفًا أو بريدًا أو عنوانًا عامًا لتحسين بيانات المنشأة.', 'Add a public phone, email or address to enrich business identity data.' ),
			$phone || $email || $address ? '' : admin_url( 'admin.php?page=adc-contact' )
		);

		$image = trim( (string) ( $settings['default_image_url'] ?? '' ) );
		$image_https = $image && 'https' === strtolower( (string) wp_parse_url( $image, PHP_URL_SCHEME ) );
		$checks[] = self::check(
			'social.default-image',
			ProductSetup::t( 'صورة المشاركة الافتراضية', 'Default social image' ),
			! $image ? 'info' : ( $image_https || ! $home_https ? 'pass' : 'warning' ),
			! $image
				? ProductSetup::t( 'غير محددة؛ يستخدم النظام صورة السيارة أو العرض أو الشعار كبديل.', 'Not configured; vehicle, offer or logo images are used as fallbacks.' )
				: ( $image_https || ! $home_https
					? ProductSetup::t( 'الصورة الافتراضية متاحة.', 'A default social image is configured.' )
					: ProductSetup::t( 'الصورة تستخدم HTTP بينما الموقع يستخدم HTTPS.', 'The image uses HTTP while the site uses HTTPS.' ) ),
			$image
		);

		$social_count = count( SocialChannels::links( 'contact' ) );
		$checks[] = self::check(
			'social.channels',
			ProductSetup::t( 'حسابات التواصل', 'Social accounts' ),
			$social_count ? 'pass' : 'info',
			sprintf(
				ProductSetup::t( 'الحسابات العامة المتاحة: %d.', 'Public accounts available: %d.' ),
				$social_count
			),
			$social_count ? '' : admin_url( 'admin.php?page=adc-social-channels' )
		);

		$active_branches = array_filter(
			BranchLocations::all(),
			static fn( $row ): bool => is_array( $row ) && ! empty( $row['active'] )
		);
		$checks[] = self::check(
			'organization.locations',
			ProductSetup::t( 'الفروع العامة', 'Public locations' ),
			$active_branches ? 'pass' : 'info',
			sprintf(
				ProductSetup::t( 'الفروع العامة النشطة: %d.', 'Active public locations: %d.' ),
				count( $active_branches )
			),
			$active_branches ? '' : admin_url( 'admin.php?page=adc-branch-locations' )
		);

		$sitemap_enabled = function_exists( 'wp_sitemaps_get_server' ) && (bool) apply_filters( 'wp_sitemaps_enabled', true );
		$checks[] = self::check(
			'sitemap.contract',
			ProductSetup::t( 'عقد Sitemap', 'Sitemap contract' ),
			$sitemap_enabled ? 'pass' : 'warning',
			$sitemap_enabled
				? ProductSetup::t( 'Sitemap وRobots مفعّلان داخل WordPress.', 'WordPress Sitemap and Robots contracts are enabled.' )
				: ProductSetup::t( 'تعذر تأكيد خدمة Sitemap داخل بيئة WordPress الحالية.', 'The WordPress Sitemap service could not be confirmed in this environment.' ),
			home_url( '/wp-sitemap.xml' )
		);

		$commercial = self::published_commercial_counts();
		$commercial_count = $commercial['car'] + $commercial['car_offer'];
		$checks[] = self::check(
			'commercial.content',
			ProductSetup::t( 'المحتوى التجاري العام', 'Public commercial content' ),
			$commercial_count ? 'pass' : 'info',
			sprintf(
				ProductSetup::t( 'صفحات سيارات منشورة: %d، عروض منشورة: %d.', 'Published vehicle pages: %d; published offers: %d.' ),
				$commercial['car'],
				$commercial['car_offer']
			),
			admin_url( 'admin.php?page=adc-seo' )
		);

		$quality = self::commercial_content_quality();
		$quality_issue_count = $quality['missing_description'] + $quality['missing_image'] + $quality['missing_image_alt'];
		$checks[] = self::check(
			'commercial.quality',
			ProductSetup::t( 'جودة محتوى السيارات والعروض', 'Vehicle and offer content quality' ),
			$quality_issue_count ? 'warning' : ( $quality['eligible'] ? 'pass' : 'info' ),
			$quality['eligible']
				? sprintf(
					ProductSetup::t( 'تمت مراجعة عينة من %d صفحة مؤهلة؛ بلا وصف: %d، بلا صورة: %d، وصورة بلا alt: %d.', 'Reviewed a sample of %d eligible pages; missing description: %d; missing image: %d; image without alt: %d.' ),
					$quality['eligible'],
					$quality['missing_description'],
					$quality['missing_image'],
					$quality['missing_image_alt']
				)
				: ProductSetup::t( 'لا توجد صفحات سيارات أو عروض مؤهلة للمراجعة حاليًا.', 'There are no eligible vehicle or offer pages to review yet.' ),
			admin_url( 'admin.php?page=adc-seo' )
		);

		$encoded_editorial = self::encoded_editorial_count();
		$checks[] = self::check(
			'editorial.encoded-slugs',
			ProductSetup::t( 'الروابط التحريرية القديمة', 'Legacy editorial slugs' ),
			$encoded_editorial ? 'warning' : 'pass',
			$encoded_editorial
				? sprintf( ProductSetup::t( 'يوجد %d رابطًا تحريريًا بسلَج مُشفّر؛ راجعها يدويًا قبل أي تحويل.', '%d editorial URL(s) use an encoded slug; review before any redirect.' ), $encoded_editorial )
				: ProductSetup::t( 'لا توجد روابط تحريرية مشفّرة ضمن العينة الحالية.', 'No encoded editorial URLs were found in the current sample.' ),
			admin_url( 'edit.php?post_type=post' )
		);

		$checks[] = self::check(
			'production.validation',
			ProductSetup::t( 'فحوص الإنتاج', 'Production validation' ),
			'info',
			ProductSetup::t( 'يلزم تنفيذ Search Console وRich Results وPageSpeed ومراقبة 404 على النطاق العام HTTPS.', 'Search Console, Rich Results, PageSpeed and 404 monitoring must be completed on the public HTTPS domain.' ),
			''
		);

		return $checks;
	}

	/** Render the read-only dashboard section. */
	public static function render(): void {
		$checks = self::snapshot();
		echo '<h2>' . esc_html( ProductSetup::t( 'فحص جاهزية SEO', 'SEO readiness check' ) ) . '</h2>';
		echo '<p class="description">' . esc_html( ProductSetup::t( 'هذا الفحص للقراءة فقط ولا يغيّر أي إعداد أو محتوى. الفحوص الخارجية تظهر صراحة كخطوات لاحقة.', 'This read-only check does not change settings or content. External checks are shown explicitly as follow-up steps.' ) ) . '</p>';
		echo '<div class="adc-table-scroll" role="region" aria-label="' . esc_attr( ProductSetup::t( 'فحص جاهزية SEO', 'SEO readiness check' ) ) . '" tabindex="0"><table class="widefat striped"><thead><tr><th>' . esc_html( ProductSetup::t( 'الفحص', 'Check' ) ) . '</th><th>' . esc_html( ProductSetup::t( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( ProductSetup::t( 'التفاصيل', 'Details' ) ) . '</th><th>' . esc_html( ProductSetup::t( 'الإجراء', 'Action' ) ) . '</th></tr></thead><tbody>';
		foreach ( $checks as $check ) {
			$status = (string) $check['status'];
			$status_label = 'pass' === $status ? ProductSetup::t( 'سليم', 'Ready' ) : ( 'warning' === $status ? ProductSetup::t( 'يحتاج مراجعة', 'Review' ) : ( 'fail' === $status ? ProductSetup::t( 'مشكلة', 'Issue' ) : ProductSetup::t( 'معلومة', 'Info' ) ) );
			$action = '' !== (string) $check['action'] ? '<a href="' . esc_url( (string) $check['action'] ) . '">' . esc_html( ProductSetup::t( 'فتح', 'Open' ) ) . '</a>' : '—';
			echo '<tr><th scope="row"><code>' . esc_html( (string) $check['id'] ) . '</code><br>' . esc_html( (string) $check['label'] ) . '</th><td><strong class="adc-seo-health-status adc-seo-health-status--' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</strong></td><td>' . esc_html( (string) $check['detail'] ) . '</td><td>' . $action . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** @return array<string,int> */
	private static function published_commercial_counts(): array {
		$counts = array( 'car' => 0, 'car_offer' => 0 );
		foreach ( array_keys( $counts ) as $post_type ) {
			$raw = wp_count_posts( $post_type );
			$counts[ $post_type ] = $raw instanceof \stdClass ? absint( $raw->publish ?? 0 ) : 0;
		}
		return $counts;
	}

	private static function encoded_editorial_count(): int {
		$posts = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$total = 0;
		foreach ( $posts as $post_id ) {
			$slug = (string) get_post_field( 'post_name', absint( $post_id ) );
			if ( preg_match( '/%[0-9a-f]{2}/i', $slug ) ) {
				$total++;
			}
		}
		return $total;
	}

	/** @return array<string,int> */
	private static function commercial_content_quality(): array {
		$posts = get_posts(
			array(
				'post_type'              => array( 'car', 'car_offer' ),
				'post_status'            => 'publish',
				'posts_per_page'         => 50,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$eligible = 0;
		$missing_description = 0;
		$missing_image = 0;
		$missing_image_alt = 0;
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			if ( 'car' === $post->post_type ) {
				if ( ! \AutoDealership\Inventory\PublicCatalog::is_post_publicly_eligible( $post->ID ) ) {
					continue;
				}
			} elseif ( ! \AutoDealership\Content\PublicOfferView::for_post( $post->ID ) ) {
				continue;
			}
			$eligible++;
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $post->post_excerpt . ' ' . (string) $post->post_content ) ) ?: '' );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) < 40 : strlen( $text ) < 40 ) {
				$missing_description++;
			}
			$image_id = get_post_thumbnail_id( $post->ID );
			$image_available = (bool) $image_id;
			if ( $image_id && '' === trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ) {
				$missing_image_alt++;
			}
			if ( ! $image_available && 'car_offer' === $post->post_type ) {
				$model = \AutoDealership\Content\PublicOfferView::for_post( $post->ID );
				$image_available = is_array( $model ) && '' !== trim( (string) ( $model['image_url'] ?? '' ) );
			}
			if ( ! $image_available ) {
				$missing_image++;
			}
		}
		return array( 'eligible' => $eligible, 'missing_description' => $missing_description, 'missing_image' => $missing_image, 'missing_image_alt' => $missing_image_alt );
	}

	/** @return array<string,string|int> */
	private static function check( string $id, string $label, string $status, string $detail, string $action ): array {
		return array( 'id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail, 'action' => $action );
	}
}
