<?php
/** Representative synthetic catalog query budget. Runs only inside the random isolated database. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\PublicCatalog;

$performance_posts = array();
$performance_vehicles = array();
$performance_mapping_id = 0;
$performance_table = Schema::table( 'vehicles' );
$performance_now = current_time( 'mysql', true );
$performance_home_sentinel = '__adc_missing_home__';
$performance_home = get_option( 'home', $performance_home_sentinel );
$performance_home_url = $performance_home_sentinel === $performance_home ? 'http://catalog-performance.example.invalid' : (string) $performance_home;
$performance_home_filter = static fn() => $performance_home_url;
$performance_installing = wp_installing();

try {
	// The suite keeps installation mode on. Measure ordinary requests, where option caches are used.
	wp_installing( false );
	add_filter( 'pre_option_home', $performance_home_filter );
	for ( $index = 1; $index <= 240; $index++ ) {
		$post_id = wp_insert_post( array(
			'post_type'=>'car', 'post_status'=>'publish',
			'post_title'=>'Performance Catalog ' . $index,
		) );
		if ( is_wp_error( $post_id ) || $post_id < 1 ) { throw new RuntimeException( 'Performance catalog post fixture failed.' ); }
		$performance_posts[] = (int) $post_id;
		$inserted = $wpdb->insert( $performance_table, array(
			'vin'=>'PERF120' . str_pad( (string) $index, 10, '0', STR_PAD_LEFT ),
			'stock_number'=>'PERF-120-' . str_pad( (string) $index, 4, '0', STR_PAD_LEFT ),
			'brand'=>'Performance Brand', 'model'=>'Load Model ' . ( $index % 12 ), 'trim_name'=>'Load Trim',
			'model_year'=>2020 + ( $index % 7 ), 'condition_key'=>$index % 2 ? 'new' : 'used',
			'branch_id'=>$branch_a['id'], 'status'=>'available', 'body_type'=>'suv', 'fuel_type'=>'hybrid',
			'transmission'=>'automatic', 'mileage'=>$index * 100, 'retail_price'=>7000000 + ( $index * 1000 ),
			'currency'=>'SAR', 'public_post_id'=>$post_id, 'created_at'=>$performance_now, 'updated_at'=>$performance_now,
		) );
		if ( false === $inserted ) { throw new RuntimeException( 'Performance catalog vehicle fixture failed: ' . $wpdb->last_error ); }
		$performance_vehicles[] = (int) $wpdb->insert_id;
		clean_post_cache( $post_id );
	}

	$performance_filters = array( 'brand'=>'Performance Brand', 'sort'=>'price_asc', 'page'=>1, 'per_page'=>48 );
	$performance_queries = array();
	$capture_performance_query = static function ( $query ) use ( &$performance_queries ) { $performance_queries[] = $query; return $query; };
	add_filter( 'query', $capture_performance_query );
	$query_start = (int) $wpdb->num_queries;
	$time_start = microtime( true );
	$page_one = PublicCatalog::catalog( $performance_filters );
	$total = PublicCatalog::catalog_total( $performance_filters );
	$options = PublicCatalog::filter_options();
	$elapsed = microtime( true ) - $time_start;
	$query_count = (int) $wpdb->num_queries - $query_start;
	remove_filter( 'query', $capture_performance_query );
	$page_two = PublicCatalog::catalog( array_merge( $performance_filters, array( 'page'=>2 ) ) );

	adc_check( 48 === count( $page_one ) && 240 === $total && 'PERF-120-0001' === $page_one[0]['stock_number'], 'Representative catalog load returns the bounded first page and exact total in stable order.' );
	adc_check( 48 === count( $page_two ) && empty( array_intersect( wp_list_pluck( $page_one, 'id' ), wp_list_pluck( $page_two, 'id' ) ) ), 'Representative catalog pagination returns a distinct second page.' );
	$query_sources = array_count_values( array_map( static function ( $query ) {
		if ( preg_match( "/option_name\s*=\s*'([^']+)'/i", $query, $option ) ) { return 'option:' . $option[1]; }
		return preg_match( '/\bFROM\s+([^\s]+)/i', $query, $match ) ? $match[1] : strtoupper( strtok( ltrim( $query ), " \t\r\n" ) );
	}, $performance_queries ) );
	adc_check( $query_count <= 25 && $elapsed < 3.0 && in_array( 'Performance Brand', $options['brand'], true ), sprintf( 'Cold catalog page, total and filter options stay within local budgets (%d queries, %.3f seconds; %s).', $query_count, $elapsed, wp_json_encode( $query_sources ) ) );
	foreach ( array( 12, 24, 48 ) as $page_size ) {
		$query_start = (int) $wpdb->num_queries;
		$bounded = PublicCatalog::catalog( array_merge( $performance_filters, array( 'per_page'=>$page_size ) ) );
		adc_check( $bounded === array_slice( $page_one, 0, $page_size ) && $wpdb->num_queries - $query_start <= 25, "Catalog page size $page_size preserves every public field within the query budget." );
	}

	// Cache positive and negative mappings only for this read, including failed reads.
	$mapped_post = $performance_posts[0]; $unmapped_post = $performance_posts[1];
	$performance_mapping_id = $wpdb->insert( Schema::table('catalog_models'), array('model_key'=>hash('sha256','performance-mapping'),'brand'=>'Performance Brand','model'=>'Mapping fixture','public_post_id'=>$mapped_post,'created_at'=>$performance_now) ) ? (int)$wpdb->insert_id : 0;
	adc_check( $performance_mapping_id > 0, 'Create a disposable positive mapping fixture.' );
	$query_start = (int)$wpdb->num_queries;
	$mapping_results = \AutoDealership\Inventory\GroupedModelCatalog::with_mappings( array($mapped_post,$unmapped_post), static function() use($mapped_post,$unmapped_post): array {
		return array(\AutoDealership\Inventory\GroupedModelCatalog::is_mapped($mapped_post),\AutoDealership\Inventory\GroupedModelCatalog::is_mapped($unmapped_post),\AutoDealership\Inventory\GroupedModelCatalog::is_mapped($unmapped_post));
	} );
	adc_check( array(true,false,false) === $mapping_results && 1 === $wpdb->num_queries - $query_start, 'One batch query caches both mapped and unmapped posts.' );
	try {
		\AutoDealership\Inventory\GroupedModelCatalog::with_mappings( array($mapped_post), static function(): array { throw new RuntimeException('mapping fixture exception'); } );
		adc_check( false, 'Mapping exception must propagate.' );
	} catch ( RuntimeException $exception ) {
		adc_check( 'mapping fixture exception' === $exception->getMessage(), 'Mapping callback exceptions are not swallowed.' );
	}
	$wpdb->delete( Schema::table('catalog_models'), array('id'=>$performance_mapping_id) ); $performance_mapping_id = 0;
	adc_check( !\AutoDealership\Inventory\GroupedModelCatalog::is_mapped($mapped_post), 'Mapping snapshot is discarded after callbacks and exceptions; later edits are visible.' );
} finally {
	if ( $performance_mapping_id ) { $wpdb->delete( Schema::table('catalog_models'), array('id'=>$performance_mapping_id) ); }
	if ( $performance_vehicles ) {
		$wpdb->query( "DELETE FROM $performance_table WHERE id IN (" . implode( ',', array_map( 'absint', $performance_vehicles ) ) . ')' );
	}
	foreach ( $performance_posts as $post_id ) { wp_delete_post( $post_id, true ); }
	remove_filter( 'pre_option_home', $performance_home_filter );
	wp_installing( $performance_installing );
}
