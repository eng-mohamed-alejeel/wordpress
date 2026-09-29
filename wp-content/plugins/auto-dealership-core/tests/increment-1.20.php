<?php
/** Operational public-catalog acceptance for 1.20. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Core\ConfigurationService;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\PublicCatalog;

wp_set_current_user( $admin );
$catalog_options = array(
	'adc_vat_rate_bps', 'adc_pricing_fee_amount', 'adc_promotion_code', 'adc_promotion_type',
	'adc_promotion_value', 'adc_promotion_starts_at', 'adc_promotion_ends_at',
	'adc_reservation_hours', 'adc_reservation_deposit_type', 'adc_reservation_deposit_value',
	'adc_sales_manager_discount_limit', 'adc_general_manager_discount_limit',
	'adc_seller_name', 'adc_seller_tax_number', 'adc_seller_address', 'adc_seller_phone',
	'adc_delivery_required_documents', 'adc_default_branch_id', 'adc_privacy_retention_days',
	PublicCatalog::OPTION_MODE,
);
$catalog_option_state = array();
foreach ( $catalog_options as $key ) {
	$sentinel = new stdClass();
	$value = get_option( $key, $sentinel );
	$catalog_option_state[ $key ] = array( 'exists' => $sentinel !== $value, 'value' => $sentinel === $value ? null : $value );
}
$original_car_posts = $wpdb->get_results( "SELECT ID,post_status FROM {$wpdb->posts} WHERE post_type='car'", ARRAY_A ) ?: array();
$catalog_operational_baseline = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'vehicles' ) );
$catalog_post_ids = array();
$catalog_vehicle_ids = array();
$catalog_hooks_added = false;

$restore_catalog_fixture = static function () use ( $wpdb, $catalog_option_state, $original_car_posts, &$catalog_post_ids, &$catalog_vehicle_ids, &$catalog_hooks_added ): void {
	if ( $catalog_hooks_added ) {
		remove_action( 'pre_get_posts', array( PublicCatalog::class, 'prepare_theme_query' ), 12 );
		remove_filter( 'posts_clauses', array( PublicCatalog::class, 'filter_car_queries' ), 20 );
	}
	if ( $catalog_vehicle_ids ) {
		$ids = implode( ',', array_map( 'absint', $catalog_vehicle_ids ) );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'vehicles' ) . " WHERE id IN ($ids)" );
	}
	foreach ( $catalog_post_ids as $post_id ) { wp_delete_post( $post_id, true ); }
	foreach ( $original_car_posts as $post ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => $post['post_status'] ), array( 'ID' => (int) $post['ID'] ), array( '%s' ), array( '%d' ) );
		clean_post_cache( (int) $post['ID'] );
	}
	foreach ( $catalog_option_state as $key => $state ) {
		$state['exists'] ? update_option( $key, $state['value'], false ) : delete_option( $key );
	}
};

try {
	foreach ( $original_car_posts as $post ) {
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => (int) $post['ID'] ), array( '%s' ), array( '%d' ) );
		clean_post_cache( (int) $post['ID'] );
	}
	delete_option( PublicCatalog::OPTION_MODE );
	if ( ! post_type_exists( 'car' ) ) { register_post_type( 'car', array( 'public' => true, 'has_archive' => true ) ); }

	foreach ( array( 'Alpha', 'Beta', 'Mapped hold' ) as $title ) {
		$post_id = wp_insert_post( array( 'post_type' => 'car', 'post_status' => 'publish', 'post_title' => 'Catalog 1.20 ' . $title ) );
		if ( is_wp_error( $post_id ) || $post_id < 1 ) { throw new RuntimeException( 'Catalog post fixture failed.' ); }
		$catalog_post_ids[] = (int) $post_id;
	}

	$now = current_time( 'mysql', true );
	$rows = array(
		array( 'vin'=>'1HGCM82633A120001', 'stock_number'=>'CAT-120-ALPHA', 'brand'=>'Alpha Motors', 'model'=>'Atlas', 'trim_name'=>'Premium', 'model_year'=>2026, 'condition_key'=>'new', 'branch_id'=>$branch_a['id'], 'status'=>'available', 'body_type'=>'suv', 'fuel_type'=>'hybrid', 'transmission'=>'automatic', 'exterior_color'=>'Pearl', 'interior_color'=>'Black', 'engine_size'=>'2.5 L', 'drivetrain'=>'awd', 'doors'=>5, 'seats'=>7, 'horsepower'=>245, 'mileage'=>1000, 'retail_price'=>12000000, 'minimum_price'=>11000000, 'purchase_cost'=>9000000, 'currency'=>'SAR', 'public_post_id'=>$catalog_post_ids[0] ),
		array( 'vin'=>'1HGCM82633A120002', 'stock_number'=>'CAT-120-BETA', 'brand'=>'Beta Auto', 'model'=>'City', 'trim_name'=>'Standard', 'model_year'=>2024, 'condition_key'=>'used', 'branch_id'=>$branch_a['id'], 'status'=>'available', 'body_type'=>'sedan', 'fuel_type'=>'gasoline', 'transmission'=>'manual', 'exterior_color'=>'Blue', 'interior_color'=>'Gray', 'engine_size'=>'1.6 L', 'drivetrain'=>'fwd', 'doors'=>4, 'seats'=>5, 'horsepower'=>130, 'mileage'=>5000, 'retail_price'=>8000000, 'minimum_price'=>7500000, 'purchase_cost'=>6000000, 'currency'=>'SAR', 'public_post_id'=>$catalog_post_ids[1] ),
	);
	foreach ( $rows as $row ) {
		$row['created_at'] = $now; $row['updated_at'] = $now;
		if ( false === $wpdb->insert( Schema::table( 'vehicles' ), $row ) ) { throw new RuntimeException( 'Catalog vehicle fixture failed.' ); }
		$catalog_vehicle_ids[] = (int) $wpdb->insert_id;
	}

	$not_ready = PublicCatalog::readiness();
	adc_check( ! $not_ready['ready'] && 1 === $not_ready['unmapped_published_posts'] && 0 === $not_ready['duplicate_mappings'], 'Catalog readiness detects an unmapped published vehicle post.' );
	$blocked = ConfigurationService::update( array( 'public_catalog_mode' => PublicCatalog::MODE_AUTHORITATIVE ) );
	adc_check( is_wp_error( $blocked ) && 'adc_catalog_not_ready' === $blocked->get_error_code() && PublicCatalog::MODE_COMPATIBILITY === PublicCatalog::mode(), 'Authoritative activation is blocked until published mappings are reconciled.' );

	$hold = array( 'vin'=>'1HGCM82633A120003', 'stock_number'=>'CAT-120-HOLD', 'brand'=>'Alpha Motors', 'model'=>'Held', 'trim_name'=>'Base', 'model_year'=>2025, 'condition_key'=>'new', 'branch_id'=>$branch_a['id'], 'status'=>'hold', 'body_type'=>'suv', 'fuel_type'=>'electric', 'transmission'=>'automatic', 'mileage'=>0, 'retail_price'=>9000000, 'currency'=>'SAR', 'public_post_id'=>$catalog_post_ids[2], 'created_at'=>$now, 'updated_at'=>$now );
	if ( false === $wpdb->insert( Schema::table( 'vehicles' ), $hold ) ) { throw new RuntimeException( 'Catalog held fixture failed.' ); }
	$catalog_vehicle_ids[] = (int) $wpdb->insert_id;
	$ready = PublicCatalog::readiness();
	adc_check( $ready['ready'] && $catalog_operational_baseline + 3 === $ready['operational'] && 2 === $ready['eligible'] && 3 === $ready['published_posts'], 'Catalog readiness separates mapped inventory from currently eligible public inventory.' );
	$enabled = ConfigurationService::update( array( 'public_catalog_mode' => PublicCatalog::MODE_AUTHORITATIVE ) );
	adc_check( is_array( $enabled ) && PublicCatalog::is_authoritative(), 'Reconciled catalog can be enabled through the audited configuration service.' );

	$normalized_theme = PublicCatalog::normalize_filters( array( 'min_price'=>'80000', 'max_price'=>'120000', 'fuel'=>'hybrid', 'sort'=>'unsafe', 'search'=>str_repeat( 'x', 150 ) ), true );
	adc_check( 8000000 === $normalized_theme['min_price'] && 12000000 === $normalized_theme['max_price'] && 'hybrid' === $normalized_theme['fuel_type'] && 'newest' === $normalized_theme['sort'] && 120 === mb_strlen( $normalized_theme['search'] ), 'Theme catalog normalization converts SAR to halalas and bounds aliases, sort and search input.' );
	$normalized_api = PublicCatalog::normalize_filters( array( 'min_price'=>8000000, 'max_price'=>12000000 ) );
	adc_check( 8000000 === $normalized_api['min_price'] && 12000000 === $normalized_api['max_price'], 'REST catalog normalization keeps documented minor-unit price filters unchanged.' );

	$catalog = PublicCatalog::catalog( array( 'sort'=>'price_asc', 'per_page'=>48 ) );
	adc_check( 2 === count( $catalog ) && 'CAT-120-BETA' === $catalog[0]['stock_number'] && 'CAT-120-ALPHA' === $catalog[1]['stock_number'], 'Public catalog returns only eligible rows in allowlisted ascending-price order.' );
	adc_check( ! array_key_exists( 'vin', $catalog[0] ) && ! array_key_exists( 'purchase_cost', $catalog[0] ) && ! array_key_exists( 'minimum_price', $catalog[0] ) && ! array_key_exists( 'location_id', $catalog[0] ) && ! array_key_exists( 'brand_id', $catalog[0] ), 'Public catalog omits identity, cost, price-floor and internal reference fields.' );
	adc_check( 1 === PublicCatalog::catalog_total( array( 'brand'=>'Alpha Motors', 'model'=>'Atlas', 'trim'=>'Premium', 'min_year'=>2026, 'max_year'=>2026, 'min_price'=>12000000, 'max_price'=>12000000, 'min_mileage'=>1000, 'max_mileage'=>1000, 'body_type'=>'suv', 'fuel_type'=>'hybrid', 'transmission'=>'automatic', 'engine_size'=>'2.5 L', 'drivetrain'=>'awd', 'exterior_color'=>'Pearl', 'interior_color'=>'Black', 'branch_id'=>$branch_a['id'], 'condition'=>'new' ) ), 'Every documented operational filter composes into one bounded catalog result.' );
	adc_check( 1 === PublicCatalog::catalog_total( array( 'search'=>'CAT-120-BETA' ) ) && 0 === PublicCatalog::catalog_total( array( 'search'=>'private-vin-120001' ) ), 'Catalog search matches public identity fields without searching private VIN data.' );
	$public_vehicle = PublicCatalog::vehicle_for_post( $catalog_post_ids[0] );
	adc_check( is_array( $public_vehicle ) && 12000000 === $public_vehicle['retail_price'] && 7 === $public_vehicle['seats'] && 'Synthetic A' === $public_vehicle['branch_name'], 'Mapped theme read model returns cast public inventory and active branch presentation fields.' );

	$legacy_post = wp_insert_post( array( 'post_type'=>'car', 'post_status'=>'publish', 'post_title'=>'Catalog 1.20 legacy compatibility' ) );
	if ( is_wp_error( $legacy_post ) || $legacy_post < 1 ) { throw new RuntimeException( 'Legacy catalog post fixture failed.' ); }
	$catalog_post_ids[] = (int) $legacy_post;
	PublicCatalog::boot(); $catalog_hooks_added = true;
	$authoritative_query = new WP_Query( array( 'post_type'=>'car', 'post__in'=>$catalog_post_ids, 'posts_per_page'=>20, 'fields'=>'ids' ) );
	$authoritative_ids = array_map( 'intval', $authoritative_query->posts );
	adc_check( 2 === count( $authoritative_ids ) && in_array( $catalog_post_ids[0], $authoritative_ids, true ) && in_array( $catalog_post_ids[1], $authoritative_ids, true ), 'Authoritative WordPress car queries hide unmapped and unavailable posts.' );

	update_option( PublicCatalog::OPTION_MODE, PublicCatalog::MODE_COMPATIBILITY, false );
	$compatibility_query = new WP_Query( array( 'post_type'=>'car', 'post__in'=>$catalog_post_ids, 'posts_per_page'=>20, 'fields'=>'ids' ) );
	$compatibility_ids = array_map( 'intval', $compatibility_query->posts );
	adc_check( 3 === count( $compatibility_ids ) && in_array( (int) $legacy_post, $compatibility_ids, true ) && ! in_array( $catalog_post_ids[2], $compatibility_ids, true ), 'Compatibility mode retains unmapped legacy posts while suppressing mapped unavailable inventory.' );

	update_option( PublicCatalog::OPTION_MODE, PublicCatalog::MODE_AUTHORITATIVE, false );
	$duplicate = $rows[0];
	$duplicate['vin'] = '1HGCM82633A120004'; $duplicate['stock_number'] = 'CAT-120-DUPLICATE'; $duplicate['created_at'] = $now; $duplicate['updated_at'] = $now;
	if ( false === $wpdb->insert( Schema::table( 'vehicles' ), $duplicate ) ) { throw new RuntimeException( 'Duplicate mapping fixture failed.' ); }
	$catalog_vehicle_ids[] = (int) $wpdb->insert_id;
	$duplicate_readiness = PublicCatalog::readiness();
	adc_check( 1 === $duplicate_readiness['duplicate_mappings'] && ! $duplicate_readiness['ready'] && 1 === PublicCatalog::catalog_total(), 'Duplicate post mappings fail readiness and are withheld from public catalog results.' );
} finally {
	$restore_catalog_fixture();
}
