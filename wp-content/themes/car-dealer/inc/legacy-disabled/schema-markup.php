<?php
/**
 * Schema Markup لتحسين محركات البحث لقالب معرض السيارات
 *
 * @package WordPress
 * @subpackage Car_Dealer
 * @since Car Dealer 1.0
 */

/**
 * إضافة Schema Markup لصفحة سيارة مفردة
 */
function car_dealer_vehicle_schema() {
	if ( ! is_singular( 'car' ) ) {
		return;
	}

	global $post;

	// الحصول على بيانات السيارة
	$price = get_post_meta( $post->ID, '_car_price', true );
	$year = get_post_meta( $post->ID, '_car_year', true );
	$kilometers = get_post_meta( $post->ID, '_car_kilometers', true );
	$transmission = get_post_meta( $post->ID, '_car_transmission', true );
	$fuel_type = get_post_meta( $post->ID, '_car_fuel_type', true );

	// الحصول على الماركة والفئة
	$brands = get_the_terms( $post->ID, 'car_brand' );
	$categories = get_the_terms( $post->ID, 'car_category' );
	$brand = ! empty( $brands ) ? $brands[0]->name : '';
	$category = ! empty( $categories ) ? $categories[0]->name : '';

	// إنشاء JSON-LD Schema
	$schema = array(
		'@context' => 'https://schema.org',
		'@type' => 'Product',
		'name' => get_the_title(),
		'image' => get_the_post_thumbnail_url( $post->ID, 'full' ),
		'description' => get_the_excerpt(),
		'brand' => array(
			'@type' => 'Brand',
			'name' => $brand,
		),
		'category' => $category,
		'offers' => array(
			'@type' => 'Offer',
			'priceCurrency' => 'SAR',
			'price' => $price,
			'priceValidUntil' => date( 'Y-m-d', strtotime( '+30 days' ) ),
			'itemCondition' => 'https://schema.org/UsedCondition',
			'availability' => 'https://schema.org/InStock',
			'seller' => array(
				'@type' => 'Organization',
				'name' => get_bloginfo( 'name' ),
			),
		),
		'additionalProperty' => array(
			array(
				'@type' => 'PropertyValue',
				'propertyID' => 'year',
				'value' => $year,
				'unitCode' => 'C62',
			),
			array(
				'@type' => 'PropertyValue',
				'propertyID' => 'mileage',
				'value' => $kilometers,
				'unitCode' => 'KMT',
			),
			array(
				'@type' => 'PropertyValue',
				'propertyID' => 'transmission',
				'value' => $transmission === 'manual' ? 'Manual' : 'Automatic',
			),
			array(
				'@type' => 'PropertyValue',
				'propertyID' => 'fuel_type',
				'value' => $fuel_type,
			),
		),
	);

	// تحويل JSON إلى سلسلة وإضافتها إلى الصفحة
	$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	echo '<script type="application/ld+json">' . $schema_json . '</script>';
}
add_action( 'wp_head', 'car_dealer_vehicle_schema' );

/**
 * إضافة Schema Markup لصفحة أرشيف السيارات
 */
function car_dealer_vehicle_listing_schema() {
	if ( ! is_post_type_archive( 'car' ) ) {
		return;
	}

	// الحصول على الاستعلام الحالي
	$query = new WP_Query( array(
		'post_type' => 'car',
		'posts_per_page' => -1,
	) );

	if ( ! $query->have_posts() ) {
		return;
	}

	$items = array();

	while ( $query->have_posts() ) {
		$query->the_post();

		// الحصول على بيانات السيارة
		$price = get_post_meta( get_the_ID(), '_car_price', true );
		$year = get_post_meta( get_the_ID(), '_car_year', true );
		$kilometers = get_post_meta( get_the_ID(), '_car_kilometers', true );
		$transmission = get_post_meta( get_the_ID(), '_car_transmission', true );
		$fuel_type = get_post_meta( get_the_ID(), '_car_fuel_type', true );

		// الحصول على الماركة والفئة
		$brands = get_the_terms( get_the_ID(), 'car_brand' );
		$categories = get_the_terms( get_the_ID(), 'car_category' );
		$brand = ! empty( $brands ) ? $brands[0]->name : '';
		$category = ! empty( $categories ) ? $categories[0]->name : '';

		// إضافة عنصر السيارة
		$items[] = array(
			'@type' => 'Product',
			'name' => get_the_title(),
			'image' => get_the_post_thumbnail_url( get_the_ID(), 'full' ),
			'description' => get_the_excerpt(),
			'brand' => array(
				'@type' => 'Brand',
				'name' => $brand,
			),
			'category' => $category,
			'offers' => array(
				'@type' => 'Offer',
				'priceCurrency' => 'SAR',
				'price' => $price,
				'priceValidUntil' => date( 'Y-m-d', strtotime( '+30 days' ) ),
				'itemCondition' => 'https://schema.org/UsedCondition',
				'availability' => 'https://schema.org/InStock',
				'seller' => array(
					'@type' => 'Organization',
					'name' => get_bloginfo( 'name' ),
				),
			),
			'additionalProperty' => array(
				array(
					'@type' => 'PropertyValue',
					'propertyID' => 'year',
					'value' => $year,
					'unitCode' => 'C62',
				),
				array(
					'@type' => 'PropertyValue',
					'propertyID' => 'mileage',
					'value' => $kilometers,
					'unitCode' => 'KMT',
				),
				array(
					'@type' => 'PropertyValue',
					'propertyID' => 'transmission',
					'value' => $transmission === 'manual' ? 'Manual' : 'Automatic',
				),
				array(
					'@type' => 'PropertyValue',
					'propertyID' => 'fuel_type',
					'value' => $fuel_type,
				),
			),
		);
	}

	wp_reset_postdata();

	// إنشاء JSON-LD Schema للقائمة
	$schema = array(
		'@context' => 'https://schema.org',
		'@type' => 'ItemList',
		'itemListElement' => $items,
	);

	// تحويل JSON إلى سلسلة وإضافتها إلى الصفحة
	$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	echo '<script type="application/ld+json">' . $schema_json . '</script>';
}
add_action( 'wp_head', 'car_dealer_vehicle_listing_schema' );

/**
 * إضافة Schema Markup لصفحة الشركة
 */
function car_dealer_organization_schema() {
	$schema = array(
		'@context' => 'https://schema.org',
		'@type' => 'AutoDealer',
		'name' => get_bloginfo( 'name' ),
		'description' => get_bloginfo( 'description' ),
		'logo' => has_custom_logo() ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '',
		'image' => has_custom_logo() ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' ) : '',
		'url' => home_url(),
		'address' => array(
			'@type' => 'PostalAddress',
			'streetAddress' => 'شارع الملك فهد', // يمكن تعديل هذه القيم من إعدادات القالب
			'addressLocality' => 'الرياض',
			'addressRegion' => 'الرياض',
			'postalCode' => '11564',
			'addressCountry' => 'SA',
		),
		'geo' => array(
			'@type' => 'GeoCoordinates',
			'latitude' => 24.7136, // يمكن تعديل هذه القيم من إعدادات القالب
			'longitude' => 46.6753, // يمكن تعديل هذه القيم من إعدادات القالب
		),
		'telephone' => '+966 50 123 4567', // يمكن تعديل هذه القيمة من إعدادات القالب
		'openingHours' => 'Mo-Sa 09:00-22:00', // يمكن تعديل هذه القيمة من إعدادات القالب
		'priceRange' => '$$$',
		'sameAs' => array(
			'https://facebook.com/cardealer', // يمكن إضافة روابط وسائل التواصل الاجتماعي
			'https://twitter.com/cardealer', // يمكن إضافة روابط وسائل التواصل الاجتماعي
			'https://instagram.com/cardealer', // يمكن إضافة روابط وسائل التواصل الاجتماعي
		),
	);

	// تحويل JSON إلى سلسلة وإضافتها إلى الصفحة
	$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	echo '<script type="application/ld+json">' . $schema_json . '</script>';
}
add_action( 'wp_head', 'car_dealer_organization_schema' );

/**
 * إضافة Schema Markup لصفحة الشهادات
 */
function car_dealer_review_schema() {
	if ( ! is_singular( 'testimonial' ) ) {
		return;
	}

	global $post;

	$customer_name = get_post_meta( $post->ID, '_customer_name', true );
	$customer_title = get_post_meta( $post->ID, '_customer_title', true );
	$company = get_post_meta( $post->ID, '_company', true );
	$rating = get_post_meta( $post->ID, '_rating', true );

	$schema = array(
		'@context' => 'https://schema.org',
		'@type' => 'Review',
		'itemReviewed' => array(
			'@type' => 'Organization',
			'name' => get_bloginfo( 'name' ),
		),
		'author' => array(
			'@type' => 'Person',
			'name' => $customer_name,
		),
		'reviewRating' => array(
			'@type' => 'Rating',
			'ratingValue' => $rating,
			'bestRating' => 5,
		),
		'reviewBody' => get_the_content(),
		'datePublished' => get_the_date( 'c' ),
	);

	// تحويل JSON إلى سلسلة وإضافتها إلى الصفحة
	$schema_json = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	echo '<script type="application/ld+json">' . $schema_json . '</script>';
}
add_action( 'wp_head', 'car_dealer_review_schema' );
