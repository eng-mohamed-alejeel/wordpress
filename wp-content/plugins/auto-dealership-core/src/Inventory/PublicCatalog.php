<?php
namespace AutoDealership\Inventory;

use AutoDealership\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Public read model and gradual cutover boundary for operational inventory. */
final class PublicCatalog {
	public const MODE_COMPATIBILITY = 'compatibility';
	public const MODE_AUTHORITATIVE = 'authoritative';
	public const OPTION_MODE = 'adc_public_catalog_mode';

	private const TEXT_FILTERS = array(
		'brand'          => 'brand',
		'model'          => 'model',
		'trim'           => 'trim_name',
		'body_type'      => 'body_type',
		'fuel_type'      => 'fuel_type',
		'transmission'   => 'transmission',
		'engine_size'    => 'engine_size',
		'drivetrain'      => 'drivetrain',
		'exterior_color' => 'exterior_color',
		'interior_color' => 'interior_color',
		'condition'      => 'condition_key',
	);

	private const SORTS = array(
		'newest'      => 'v.created_at DESC, v.id DESC',
		'price_asc'   => 'v.retail_price ASC, v.id DESC',
		'price_desc'  => 'v.retail_price DESC, v.id DESC',
		'year_desc'   => 'v.model_year DESC, v.id DESC',
		'mileage_asc' => 'v.mileage ASC, v.id DESC',
	);

	public static function boot(): void {
		add_action( 'pre_get_posts', array( self::class, 'prepare_theme_query' ), 12 );
		add_filter( 'posts_clauses', array( self::class, 'filter_car_queries' ), 20, 2 );
	}

	public static function mode(): string {
		$mode = sanitize_key( (string) get_option( self::OPTION_MODE, self::MODE_COMPATIBILITY ) );
		return self::MODE_AUTHORITATIVE === $mode ? self::MODE_AUTHORITATIVE : self::MODE_COMPATIBILITY;
	}

	/** Authoritative reads require an explicitly selected mode and a verified schema. */
	public static function is_authoritative(): bool {
		return self::MODE_AUTHORITATIVE === self::mode() && get_option( 'adc_db_version' ) === Schema::VERSION;
	}

	/** Marks the public car archive for database-backed filtering and sorting. */
	public static function prepare_theme_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'car' ) || ! self::is_authoritative() ) {
			return;
		}

		$raw = wp_unslash( $_GET );
		$filters = self::normalize_filters( is_array( $raw ) ? $raw : array(), true );
		$query->set( 'adc_public_catalog', true );
		$query->set( 'adc_catalog_filters', $filters );
		$query->set( 'posts_per_page', min( 48, max( 1, absint( get_option( 'posts_per_page', 12 ) ) ) ) );

		// Avoid legacy taxonomy and WordPress search constraints in authoritative mode.
		$query->set( 'car_brand', '' );
		$query->set( 'car_category', '' );
		$query->set( 's', '' );
	}

	/** Applies central availability to every car query and full filters to opted-in queries. */
	public static function filter_car_queries( array $clauses, \WP_Query $query ): array {
		if ( is_admin() || $query->is_preview() || ! self::is_car_query( $query ) || get_option( 'adc_db_version' ) !== Schema::VERSION ) {
			return $clauses;
		}

		global $wpdb;
		$vehicles = Schema::table( 'vehicles' );
		$branches = Schema::table( 'branches' );
		$posts = $wpdb->posts;
		$authoritative = self::is_authoritative();

		if ( ! $authoritative ) {
			$clauses['where'] .= " AND NOT EXISTS (
				SELECT 1 FROM $vehicles adc_vehicle
				WHERE adc_vehicle.public_post_id = $posts.ID
				AND ( adc_vehicle.status <> 'available' OR NOT EXISTS (
					SELECT 1 FROM $branches adc_branch
					WHERE adc_branch.id = adc_vehicle.branch_id AND adc_branch.active = 1
				) )
			)";
			return $clauses;
		}

		$clauses['join'] .= " INNER JOIN $vehicles adc_catalog_vehicle ON adc_catalog_vehicle.public_post_id = $posts.ID INNER JOIN $branches adc_catalog_branch ON adc_catalog_branch.id = adc_catalog_vehicle.branch_id";
		$clauses['where'] .= " AND adc_catalog_vehicle.status = 'available' AND adc_catalog_branch.active = 1 AND NOT EXISTS (SELECT 1 FROM $vehicles adc_catalog_duplicate WHERE adc_catalog_duplicate.public_post_id=adc_catalog_vehicle.public_post_id AND adc_catalog_duplicate.id<>adc_catalog_vehicle.id)";
		$clauses['distinct'] = 'DISTINCT';

		if ( (bool) $query->get( 'adc_public_catalog' ) ) {
			$filters = (array) $query->get( 'adc_catalog_filters' );
			list( $where, $args ) = self::where( $filters, 'adc_catalog_vehicle' );
			if ( $where ) {
				$clauses['where'] .= $wpdb->prepare( ' AND ' . implode( ' AND ', $where ), $args );
			}
			$sort = isset( self::SORTS[ $filters['sort'] ?? '' ] ) ? $filters['sort'] : 'newest';
			$clauses['orderby'] = str_replace( 'v.', 'adc_catalog_vehicle.', self::SORTS[ $sort ] );
		}

		return $clauses;
	}

	/** Returns an eligible public vehicle by its WordPress post mapping. */
	public static function vehicle_for_post( int $post_id ): ?array {
		static $cache = array();
		if ( $post_id < 1 || get_option( 'adc_db_version' ) !== Schema::VERSION ) {
			return null;
		}
		if ( array_key_exists( $post_id, $cache ) ) {
			return $cache[ $post_id ];
		}

		global $wpdb;
		$fields = 'v.id,v.stock_number,v.brand,v.model,v.trim_name,v.model_year,v.condition_key,v.branch_id,v.status,v.body_type,v.fuel_type,v.transmission,v.mileage,v.retail_price,v.currency';
		$fields .= ',v.' . implode( ',v.', VehicleSpecifications::fields() );
		$vehicles = Schema::table( 'vehicles' );
		$sql = 'SELECT ' . $fields . ',b.name branch_name,b.city branch_city,v.public_post_id FROM ' . $vehicles . ' v INNER JOIN ' . Schema::table( 'branches' ) . " b ON b.id=v.branch_id AND b.active=1 INNER JOIN {$wpdb->posts} p ON p.ID=v.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE v.public_post_id=%d AND v.status='available' AND NOT EXISTS (SELECT 1 FROM $vehicles adc_duplicate WHERE adc_duplicate.public_post_id=v.public_post_id AND adc_duplicate.id<>v.id) LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $post_id ), ARRAY_A );
		$cache[ $post_id ] = $row ? self::cast_item( $row ) : null;
		return $cache[ $post_id ];
	}

	/** Public REST/read-model list. Monetary filters use stored minor units. */
	public static function catalog( array $raw_filters = array() ): array {
		global $wpdb;
		$filters = self::normalize_filters( $raw_filters );
		$page = max( 1, absint( $filters['page'] ?? 1 ) );
		$limit = min( 48, max( 1, absint( $filters['per_page'] ?? 12 ) ) );
		list( $where, $args ) = self::where( $filters );
		$fields = 'v.' . implode( ',v.', VehicleSpecifications::fields() );
		$fields .= ',v.id,v.stock_number,v.brand,v.model,v.trim_name,v.model_year,v.condition_key,v.branch_id,v.status,v.body_type,v.fuel_type,v.transmission,v.mileage,v.retail_price,v.currency';
		$sort = isset( self::SORTS[ $filters['sort'] ?? '' ] ) ? $filters['sort'] : 'newest';
		$vehicles = Schema::table( 'vehicles' );
		$sql = 'SELECT ' . $fields . ',b.name branch_name,b.city branch_city,p.ID public_post_id FROM ' . $vehicles . ' v INNER JOIN ' . Schema::table( 'branches' ) . " b ON b.id=v.branch_id AND b.active=1 INNER JOIN {$wpdb->posts} p ON p.ID=v.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE v.status='available' AND NOT EXISTS (SELECT 1 FROM $vehicles adc_duplicate WHERE adc_duplicate.public_post_id=v.public_post_id AND adc_duplicate.id<>v.id)";
		if ( $where ) {
			$sql .= ' AND ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY ' . self::SORTS[ $sort ] . ' LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = ( $page - 1 ) * $limit;
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
		$post_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $items, 'public_post_id' ) ) ) );
		if ( $post_ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, true, true );
		}

		foreach ( $items as &$item ) {
			$item = self::cast_item( $item );
			$post_id = $item['public_post_id'];
			$item['title'] = get_the_title( $post_id );
			$item['url'] = get_permalink( $post_id );
			$item['image'] = get_the_post_thumbnail_url( $post_id, 'large' ) ?: '';
			unset( $item['public_post_id'] );
		}
		unset( $item );
		return $items;
	}

	public static function catalog_total( array $raw_filters = array() ): int {
		global $wpdb;
		$filters = self::normalize_filters( $raw_filters );
		list( $where, $args ) = self::where( $filters );
		$vehicles = Schema::table( 'vehicles' );
		$sql = 'SELECT COUNT(DISTINCT v.id) FROM ' . $vehicles . ' v INNER JOIN ' . Schema::table( 'branches' ) . " b ON b.id=v.branch_id AND b.active=1 INNER JOIN {$wpdb->posts} p ON p.ID=v.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE v.status='available' AND NOT EXISTS (SELECT 1 FROM $vehicles adc_duplicate WHERE adc_duplicate.public_post_id=v.public_post_id AND adc_duplicate.id<>v.id)";
		if ( $where ) {
			$sql .= ' AND ' . implode( ' AND ', $where );
		}
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	/** Values for public filter controls, bounded to eligible catalog records. */
	public static function filter_options(): array {
		global $wpdb;
		$options = array();
		$table = Schema::table( 'vehicles' );
		$branches = Schema::table( 'branches' );
		$base = " FROM $table v INNER JOIN $branches b ON b.id=v.branch_id AND b.active=1 INNER JOIN {$wpdb->posts} p ON p.ID=v.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE v.status='available' AND NOT EXISTS (SELECT 1 FROM $table adc_duplicate WHERE adc_duplicate.public_post_id=v.public_post_id AND adc_duplicate.id<>v.id)";
		foreach ( array( 'brand', 'body_type', 'fuel_type', 'transmission', 'condition_key' ) as $field ) {
			$options[ $field ] = $wpdb->get_col( "SELECT DISTINCT v.$field" . $base . " AND v.$field<>'' ORDER BY v.$field ASC LIMIT 250" ) ?: array();
		}
		$options['branches'] = $wpdb->get_results( 'SELECT DISTINCT b.id,b.name,b.city' . $base . ' ORDER BY b.name ASC LIMIT 250', ARRAY_A ) ?: array();
		return $options;
	}

	/** Administrative cutover counts; no customer or financial fields are returned. */
	public static function readiness(): array {
		$schema_ready = get_option( 'adc_db_version' ) === Schema::VERSION;
		$result = array(
			'schema_ready' => $schema_ready,
			'operational' => 0,
			'eligible' => 0,
			'published_posts' => 0,
			'unmapped_published_posts' => 0,
			'duplicate_mappings' => 0,
			'invalid_mappings' => 0,
			'ready' => false,
		);
		if ( ! $schema_ready ) {
			return $result;
		}

		global $wpdb;
		$vehicles = Schema::table( 'vehicles' );
		$branches = Schema::table( 'branches' );
		$error = false;
		$count = static function ( string $sql ) use ( $wpdb, &$error ): int {
			$value = $wpdb->get_var( $sql );
			if ( $wpdb->last_error ) {
				$error = true;
			}
			return (int) $value;
		};

		$result['operational'] = $count( "SELECT COUNT(*) FROM $vehicles" );
		$result['published_posts'] = $count( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='car' AND post_status='publish'" );
		$result['unmapped_published_posts'] = $count( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='car' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM $vehicles v WHERE v.public_post_id=p.ID)" );
		$result['duplicate_mappings'] = $count( "SELECT COUNT(*) FROM (SELECT public_post_id FROM $vehicles WHERE public_post_id>0 GROUP BY public_post_id HAVING COUNT(*)>1) adc_duplicates" );
		$result['invalid_mappings'] = $count( "SELECT COUNT(*) FROM $vehicles v LEFT JOIN {$wpdb->posts} p ON p.ID=v.public_post_id WHERE v.public_post_id>0 AND (p.ID IS NULL OR p.post_type<>'car' OR p.post_status<>'publish')" );
		$result['eligible'] = $count( "SELECT COUNT(*) FROM $vehicles v INNER JOIN $branches b ON b.id=v.branch_id AND b.active=1 INNER JOIN {$wpdb->posts} p ON p.ID=v.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE v.status='available' AND NOT EXISTS (SELECT 1 FROM $vehicles adc_duplicate WHERE adc_duplicate.public_post_id=v.public_post_id AND adc_duplicate.id<>v.id)" );
		$result['ready'] = ! $error
			&& $result['operational'] > 0
			&& $result['published_posts'] > 0
			&& $result['eligible'] > 0
			&& 0 === $result['unmapped_published_posts']
			&& 0 === $result['duplicate_mappings']
			&& 0 === $result['invalid_mappings'];
		return $result;
	}

	/** Normalizes shared REST or theme URL filters. */
	public static function normalize_filters( array $raw, bool $major_price_units = false ): array {
		$filters = array();
		$aliases = array( 'fuel' => 'fuel_type', 'car_category' => 'body_type' );
		foreach ( $aliases as $old => $new ) {
			if ( ! isset( $raw[ $new ] ) && isset( $raw[ $old ] ) ) {
				$raw[ $new ] = $raw[ $old ];
			}
		}
		foreach ( self::TEXT_FILTERS as $key => $column ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				$limit = in_array( $key, array( 'brand', 'model', 'trim' ), true ) ? 120 : ( in_array( $key, array( 'exterior_color', 'interior_color' ), true ) ? 80 : 40 );
				$filters[ $key ] = mb_substr( sanitize_text_field( (string) $raw[ $key ] ), 0, $limit );
			}
		}
		foreach ( array( 'min_year', 'max_year', 'min_price', 'max_price', 'min_mileage', 'max_mileage', 'branch_id' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== (string) $raw[ $key ] ) {
				$filters[ $key ] = max( 0, absint( $raw[ $key ] ) );
			}
		}
		if ( isset( $raw['model_year'] ) && '' !== (string) $raw['model_year'] ) {
			$filters['min_year'] = $filters['max_year'] = absint( $raw['model_year'] );
		}
		if ( $major_price_units ) {
			foreach ( array( 'min_price', 'max_price' ) as $key ) {
				if ( isset( $filters[ $key ] ) ) {
					$filters[ $key ] *= 100;
				}
			}
		}
		if ( isset( $raw['s'] ) && is_scalar( $raw['s'] ) ) {
			$filters['search'] = mb_substr( sanitize_text_field( (string) $raw['s'] ), 0, 120 );
		} elseif ( isset( $raw['search'] ) && is_scalar( $raw['search'] ) ) {
			$filters['search'] = mb_substr( sanitize_text_field( (string) $raw['search'] ), 0, 120 );
		}
		$sort = sanitize_key( (string) ( $raw['sort'] ?? 'newest' ) );
		$filters['sort'] = isset( self::SORTS[ $sort ] ) ? $sort : 'newest';
		$filters['page'] = max( 1, absint( $raw['page'] ?? 1 ) );
		$filters['per_page'] = min( 48, max( 1, absint( $raw['per_page'] ?? 12 ) ) );
		return $filters;
	}

	private static function where( array $filters, string $alias = 'v' ): array {
		global $wpdb;
		$where = array();
		$args = array();
		foreach ( self::TEXT_FILTERS as $key => $column ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				$where[] = "$alias.$column = %s";
				$args[] = $filters[ $key ];
			}
		}
		foreach ( array( 'min_year' => array( 'model_year', '>=' ), 'max_year' => array( 'model_year', '<=' ), 'min_price' => array( 'retail_price', '>=' ), 'max_price' => array( 'retail_price', '<=' ), 'min_mileage' => array( 'mileage', '>=' ), 'max_mileage' => array( 'mileage', '<=' ), 'branch_id' => array( 'branch_id', '=' ) ) as $key => $rule ) {
			if ( isset( $filters[ $key ] ) && $filters[ $key ] > 0 ) {
				$where[] = "$alias.{$rule[0]} {$rule[1]} %d";
				$args[] = $filters[ $key ];
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = "($alias.brand LIKE %s OR $alias.model LIKE %s OR $alias.trim_name LIKE %s OR $alias.stock_number LIKE %s)";
			array_push( $args, $like, $like, $like, $like );
		}
		return array( $where, $args );
	}

	private static function cast_item( array $item ): array {
		foreach ( array( 'id', 'model_year', 'branch_id', 'mileage', 'retail_price', 'doors', 'seats', 'horsepower', 'cylinders', 'public_post_id' ) as $key ) {
			if ( array_key_exists( $key, $item ) && null !== $item[ $key ] ) {
				$item[ $key ] = (int) $item[ $key ];
			}
		}
		if ( array_key_exists( 'gallery_media_ids', $item ) ) {
			$ids = json_decode( (string) $item['gallery_media_ids'], true );
			$item['gallery_media_ids'] = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
		}
		return $item;
	}

	private static function is_car_query( \WP_Query $query ): bool {
		if ( $query->is_singular( 'car' ) || $query->is_post_type_archive( 'car' ) ) {
			return true;
		}
		$post_type = $query->get( 'post_type' );
		return 'car' === $post_type || ( is_array( $post_type ) && in_array( 'car', $post_type, true ) );
	}
}
