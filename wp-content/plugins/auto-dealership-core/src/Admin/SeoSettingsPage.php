<?php
namespace AutoDealership\Admin;

use AutoDealership\Core\Localization;
use AutoDealership\Core\ProductSetup;
use AutoDealership\SEO\PublicSeo;
use AutoDealership\SEO\SeoHealth;

defined( 'ABSPATH' ) || exit;

/** Dashboard-owned SEO templates for the current single-site installation. */
final class SeoSettingsPage {
	public static function boot(): void {
		add_action( 'admin_post_adc_save_seo_settings', array( self::class, 'save' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( ProductSetup::t( 'غير مسموح.', 'Access denied.' ) ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_save_seo_settings' );
		$result = PublicSeo::save_settings( isset( $_POST['seo'] ) && is_array( $_POST['seo'] ) ? wp_unslash( $_POST['seo'] ) : array() );
		$args = array( 'page' => 'adc-seo' );
		if ( is_wp_error( $result ) ) {
			$args['error'] = 1;
		} else {
			$args['updated'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( ProductSetup::t( 'غير مسموح.', 'Access denied.' ) ), '', array( 'response' => 403 ) );
		}
		$settings = PublicSeo::settings();
		$dir = 'en' === Localization::language() ? 'ltr' : 'rtl';
		$groups = array(
			'home' => array( ProductSetup::t( 'الصفحة الرئيسية', 'Homepage' ), 'home' ),
			'vehicle' => array( ProductSetup::t( 'صفحة السيارة', 'Vehicle detail page' ), 'vehicle' ),
			'offer' => array( ProductSetup::t( 'صفحة العرض', 'Offer detail page' ), 'offer' ),
			'catalog' => array( ProductSetup::t( 'أرشيف السيارات', 'Vehicle archive' ), 'catalog' ),
			'offers_archive' => array( ProductSetup::t( 'أرشيف العروض', 'Offers archive' ), 'offers_archive' ),
		);
		echo '<div class="wrap" dir="' . esc_attr( $dir ) . '">';
		echo '<h1>' . esc_html( ProductSetup::t( 'تحسين محركات البحث', 'Search engine optimization' ) ) . '</h1>';
		echo '<p>' . esc_html( ProductSetup::t( 'تُولّد العناوين والوصف وبيانات المشاركة تلقائياً من هوية هذا الموقع وبيانات السيارات والعروض. هذه الإعدادات تخص قاعدة بيانات الشركة الحالية فقط.', 'Titles, descriptions and social previews are generated from this site identity and its vehicle and offer data. These settings belong only to the current company database.' ) ) . '</p>';
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( ProductSetup::t( 'تم حفظ إعدادات SEO.', 'SEO settings saved.' ) ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( ProductSetup::t( 'تعذر حفظ الإعدادات. تحقق من رابط الصورة والقيم المدخلة.', 'The settings could not be saved. Check the image URL and entered values.' ) ) . '</p></div>';
		}
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html( ProductSetup::t( 'الوضع التلقائي:', 'Automatic mode:' ) ) . '</strong> ' . esc_html( ProductSetup::t( 'عند ترك الحقول فارغة أو استخدام الرموز، يأخذ النظام اسم المنشأة والموقع وبيانات السيارة والسعر من النظام مباشرة.', 'When fields are empty or use tokens, the system reads the business name, location, vehicle data and price directly from the platform.' ) ) . '</p></div>';
		SeoHealth::render();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="adc_save_seo_settings">';
		wp_nonce_field( 'adc_save_seo_settings' );
		echo '<h2>' . esc_html( ProductSetup::t( 'السوق والهوية', 'Market and identity' ) ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::input_row( $settings, 'location_ar', ProductSetup::t( 'الموقع بالعربية', 'Location in Arabic' ), 'text' );
		self::input_row( $settings, 'location_en', ProductSetup::t( 'الموقع بالإنجليزية', 'Location in English' ), 'text' );
		self::input_row( $settings, 'default_image_url', ProductSetup::t( 'الصورة الافتراضية للمشاركة', 'Default social image' ), 'url', ProductSetup::t( 'اختياري؛ يمكن استخدام الصورة البارزة للسيارة أو العرض تلقائياً.', 'Optional; vehicle or offer featured images are used automatically when available.' ) );
		echo '</tbody></table>';
		echo '<h2>' . esc_html( ProductSetup::t( 'قوالب العناوين والأوصاف', 'Title and description templates' ) ) . '</h2>';
		echo '<p class="description">' . esc_html( ProductSetup::t( 'استخدم الرموز التالية: {site_name}، {legal_name}، {location}، {location_suffix}، {vehicle_title}، {brand}، {model}، {year}، {condition}، {price}، {currency}، {offer_title}، {offer_price}.', 'Available tokens: {site_name}, {legal_name}, {location}, {location_suffix}, {vehicle_title}, {brand}, {model}, {year}, {condition}, {price}, {currency}, {offer_title}, {offer_price}.' ) ) . '</p>';
		foreach ( $groups as $key => $group ) {
			echo '<h3>' . esc_html( $group[0] ) . '</h3>';
			echo '<table class="form-table" role="presentation"><tbody>';
			self::textarea_row( $settings, $key . '_title_ar', ProductSetup::t( 'العنوان بالعربية', 'Title in Arabic' ), 2 );
			self::textarea_row( $settings, $key . '_title_en', ProductSetup::t( 'العنوان بالإنجليزية', 'Title in English' ), 2 );
			self::textarea_row( $settings, $key . '_description_ar', ProductSetup::t( 'الوصف بالعربية', 'Description in Arabic' ), 4 );
			self::textarea_row( $settings, $key . '_description_en', ProductSetup::t( 'الوصف بالإنجليزية', 'Description in English' ), 4 );
			echo '</tbody></table>';
		}
		submit_button( ProductSetup::t( 'حفظ إعدادات SEO', 'Save SEO settings' ) );
		echo '</form>';
		echo '<h2>' . esc_html( ProductSetup::t( 'نطاق التطبيق', 'Implementation scope' ) ) . '</h2><p>' . esc_html( ProductSetup::t( 'تُطبق الإعدادات على الصفحة الرئيسية، أرشيف السيارات والعروض، صفحات السيارات والعروض، والصفحات العامة. صفحات الحساب والبحث والفلاتر تُوسم noindex تلقائياً ولا تُضاف إلى الروابط الأساسية أو خريطة الموقع.', 'Settings apply to the homepage, vehicle and offer archives, vehicle and offer detail pages, and public pages. Account, search and filtered pages are automatically marked noindex and are not treated as canonical destinations.' ) ) . '</p>';
		echo '</div>';
	}

	private static function input_row( array $settings, string $key, string $label, string $type, string $description = '' ): void {
		echo '<tr><th scope="row"><label for="adc-seo-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="adc-seo-' . esc_attr( $key ) . '" name="seo[' . esc_attr( $key ) . ']" type="' . esc_attr( $type ) . '" value="' . esc_attr( (string) ( $settings[ $key ] ?? '' ) ) . '">';
		if ( $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function textarea_row( array $settings, string $key, string $label, int $rows ): void {
		echo '<tr><th scope="row"><label for="adc-seo-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text" id="adc-seo-' . esc_attr( $key ) . '" name="seo[' . esc_attr( $key ) . ']" rows="' . absint( $rows ) . '" maxlength="600">' . esc_textarea( (string) ( $settings[ $key ] ?? '' ) ) . '</textarea></td></tr>';
	}
}
