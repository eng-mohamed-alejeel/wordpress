<?php
/**
 * تحسين الأداء لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة دعم لصور WebP
 */
function car_dealer_add_webp_support() {
	// إضافة نوع MIME لصور WebP
	add_filter( 'upload_mimes', 'car_dealer_webp_mime_types' );

	// إعادة تحويل الصور إلى WebP عند التحميل
	add_filter( 'wp_generate_attachment_metadata', 'car_dealer_create_webp_images', 10, 2 );

	// عرض صور WebP عند توفرها
	add_filter( 'wp_calculate_image_srcset_meta', 'car_dealer_add_webp_to_srcset' );
}
add_action( 'after_setup_theme', 'car_dealer_add_webp_support' );

/**
 * إضافة أنواع MIME لصور WebP
 */
function car_dealer_webp_mime_types( array $mimes ) {
	$mimes['webp'] = 'image/webp';
	return $mimes;
}

/**
 * إنشاء نسخ WebP من الصور عند التحميل
 */
function car_dealer_create_webp_images( array $metadata, int $attachment_id ) {
	// التأكد من أن دالة WebP مدعومة
	if ( ! function_exists( 'imagewebp' ) ) {
		return $metadata;
	}

	$upload_dir = wp_upload_dir();
	$file_path = get_attached_file( $attachment_id );

	// التأكد من أن الملف موجود وأنه صورة
	if ( ! file_exists( $file_path ) || ! getimagesize( $file_path ) ) {
		return $metadata;
	}

	// إنشاء نسخة WebP
	$webp_path = $upload_dir['path'] . '/' . wp_basename( $file_path, '.' . pathinfo( $file_path, PATHINFO_EXTENSION ) ) . '.webp';

	// الحصول على نوع الصورة
	$image_type = exif_imagetype( $file_path );

	// معالجة الصورة حسب نوعها
	switch ( $image_type ) {
		case IMAGETYPE_JPEG:
			$image = imagecreatefromjpeg( $file_path );
			break;
		case IMAGETYPE_PNG:
			$image = imagecreatefrompng( $file_path );
			break;
		case IMAGETYPE_GIF:
			$image = imagecreatefromgif( $file_path );
			break;
		default:
			return $metadata;
	}

	// حفظ الصورة بصيغة WebP
	imagewebp( $image, $webp_path, 85 );
	imagedestroy( $image );

	// إضافة مسار WebP إلى البيانات الوصفية
	if ( ! isset( $metadata['webp'] ) ) {
		$metadata['webp'] = array();
	}

	$metadata['webp']['file'] = wp_basename( $webp_path );

	// إضافة أبعاد الصورة WebP إذا كانت متوفرة
	$webp_size = @getimagesize( $webp_path );
	if ( $webp_size ) {
		$metadata['webp']['width'] = $webp_size[0];
		$metadata['webp']['height'] = $webp_size[1];
	}

	return $metadata;
}

/**
 * إضافة صور WebP إلى srcset
 */
function car_dealer_add_webp_to_srcset( array $image_meta ) {
	if ( empty( $image_meta['webp'] ) ) {
		return $image_meta;
	}

	$webp = $image_meta['webp'];

	// إنشاء عنصر srcset جديد لصورة WebP
	$webp_srcset = array();

	foreach ( $image_meta['sizes'] as $size => $size_data ) {
		if ( isset( $webp['sizes'][ $size ] ) ) {
			$webp_srcset[] = $webp['sizes'][ $size ] . " {$size}w";
		}
	}

	// إضافة srcset للصورة الكاملة
	if ( ! empty( $webp['file'] ) ) {
		$webp_srcset[] = dirname( $image_meta['file'] ) . '/' . $webp['file'] . " {$image_meta['width']}w";
	}

	// دمج srcset الأصلي مع WebP
	$image_meta['srcset'] = implode( ', ', $webp_srcset );

	return $image_meta;
}

/**
- تحسين حجم الصور
 */
function car_dealer_optimize_image_sizes() {
	// إضافة أحجام صور مخصصة
	add_image_size( 'car-thumbnail', 400, 300, true );
	add_image_size( 'car-medium', 600, 450, true );
	add_image_size( 'car-large', 1200, 900, true );
	add_image_size( 'car-slider', 1920, 1080, true );

	// إيقاف أحجام الصور غير المستخدمة
	add_filter( 'intermediate_image_sizes', 'car_dealer_remove_unused_image_sizes' );
}

function car_dealer_remove_unused_image_sizes( array $sizes ) {
	// إزالة أحجام الصور غير المستخدمة
	return array_diff( $sizes, array( 'medium_large', '1536x1536', '2048x2048' ) );
}
add_action( 'after_setup_theme', 'car_dealer_optimize_image_sizes' );

/**
- تحسين استعلامات قاعدة البيانات
 */
function car_dealer_optimize_queries( WP_Query $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	// تحسين استعلامات صفحة أرشيف السيارات
	if ( is_post_type_archive( 'car' ) ) {
		// تحديد عدد النتائج
		$query->set( 'posts_per_page', 12 );

		// إضافة ترتيب عشوائي للتنوع
		$query->set( 'orderby', 'rand' );
	}

	// تحسين استعلامات صفحة البحث
	if ( $query->is_search() ) {
		// استبعاد أنواع المقالات غير المرغوبة من نتائج البحث
		$query->set( 'post_type', 'post' );
	}
}
add_action( 'pre_get_posts', 'car_dealer_optimize_queries' );

/**
- تحميل الأصول بشكل غير متزامن
 */
function car_dealer_add_async_attributes( string $tag, string $handle ) {
	// إضافة async للملفات النصية غير الحرجة
	if ( in_array( $handle, array( 'jquery', 'car-dealer-script' ) ) ) {
		return str_replace( ' src', ' async src', $tag );
	}

	// إضافة defer للملفات النصية غير المتزامنة
	if ( in_array( $handle, array( 'car-dealer-async-script' ) ) ) {
		return str_replace( ' src', ' defer src', $tag );
	}

	return $tag;
}
add_filter( 'script_loader_tag', 'car_dealer_add_async_attributes', 10, 2 );

/**
- تحميل الخطوط بشكل غير متزامن
 */
function car_dealer_optimize_font_loading() {
	?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet" media="print" onload="this.onload=null;this.removeAttribute('media');">
	<noscript><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet"></noscript>
	<?php
}
add_action( 'wp_head', 'car_dealer_optimize_font_loading', 1 );

/**
- تحسين ذاكرة التخزين المؤقت
 */
function car_dealer_enable_caching() {
	if ( ! defined( 'WP_CACHE' ) ) {
		define( 'WP_CACHE', true );
	}

	// إضافة رؤوس ذاكرة التخزين المؤقت
	if ( ! is_admin() ) {
		header( 'Cache-Control: max-age=3600, public' );
	}
}
add_action( 'init', 'car_dealer_enable_caching' );

/**
- تحسين قائمة الانتظار
 */
function car_dealer_optimize_queue() {
	global $wpdb;

	// تحسين قاعدة البيانات
	$wpdb->query( "SET SESSION wait_timeout = 300" );

	// تحسين الذاكرة
	ini_set( 'memory_limit', '256M' );
}
add_action( 'init', 'car_dealer_optimize_queue' );
