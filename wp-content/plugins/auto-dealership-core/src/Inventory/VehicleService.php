<?php
namespace AutoDealership\Inventory;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\BranchScope;
use AutoDealership\Reference\ReferenceService;

defined( 'ABSPATH' ) || exit;

/** Validated vehicle inventory operations. Prices are stored as SAR halalas. */
final class VehicleService {
	private const STATUSES = array( 'ordered', 'in_transit', 'received', 'inspection', 'available', 'reserved', 'sold', 'ready_for_delivery', 'delivered', 'maintenance', 'hold', 'returned', 'cancelled', 'transferred' );
	private const TRANSITIONS = array(
		'ordered' => array( 'in_transit', 'cancelled' ),
		'in_transit' => array( 'received', 'hold', 'cancelled' ),
		'received' => array( 'inspection', 'hold', 'returned' ),
		'inspection' => array( 'available', 'maintenance', 'hold', 'returned' ),
		'available' => array( 'hold', 'maintenance' ),
		'reserved' => array(),
		'sold' => array( 'returned' ),
		'ready_for_delivery' => array( 'hold' ),
		'delivered' => array( 'returned' ),
		'maintenance' => array( 'inspection', 'available', 'hold' ),
		'hold' => array( 'inspection', 'available', 'cancelled' ),
		'returned' => array( 'inspection', 'hold' ),
		'transferred' => array( 'available', 'hold' ),
		'cancelled' => array(),
	);

	public static function create( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_inventory' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إدارة المخزون.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$vin = strtoupper( sanitize_text_field( (string) ( $input['vin'] ?? '' ) ) );
		$stock = sanitize_text_field( (string) ( $input['stock_number'] ?? '' ) );
		$brand = sanitize_text_field( (string) ( $input['brand'] ?? '' ) );
		$model = sanitize_text_field( (string) ( $input['model'] ?? '' ) );
		$year = absint( $input['model_year'] ?? 0 );
		$minimum_price = current_user_can( 'adc_manage_pricing' ) || current_user_can( 'manage_options' ) ? absint( $input['minimum_price'] ?? 0 ) : 0;
		$branch_id = absint( $input['branch_id'] ?? 0 );
		$condition = sanitize_key( (string) ( $input['condition'] ?? '' ) );
		$retail_price = absint( $input['retail_price'] ?? 0 );
		$brand_id = absint( $input['brand_id'] ?? 0 );
		$location_id = absint( $input['location_id'] ?? 0 );
		$specifications = VehicleSpecifications::validate( $input );
		if ( is_wp_error( $specifications ) ) { return $specifications; }
		if ( ! preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/', $vin ) || '' === $stock || '' === $brand || '' === $model || $year < 1900 || $year > ( (int) gmdate( 'Y' ) + 2 ) || ! in_array( $condition, array( 'new', 'used' ), true ) || ! self::branch_exists( $branch_id ) ) {
			return new \WP_Error( 'adc_invalid_vehicle', __( 'بيانات السيارة غير مكتملة أو غير صالحة.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( $retail_price < 1 || ( $minimum_price && $minimum_price > $retail_price ) ) {
			return new \WP_Error( 'adc_invalid_vehicle_price', __( 'السعر أو الحد الأدنى المعتمد غير صالح.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$brand_row = $brand_id ? ReferenceService::active_brand( $brand_id ) : null;
		$location_row = $location_id ? ReferenceService::active_location( $location_id, $branch_id ) : null;
		if ( ( $brand_id && ! $brand_row ) || ( $location_id && ! $location_row ) ) {
			return new \WP_Error( 'adc_invalid_vehicle_reference', __( 'The selected brand or location is inactive or outside the vehicle branch.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( $brand_row ) { $brand = '' !== $brand_row['name_en'] ? $brand_row['name_en'] : $brand_row['name_ar']; }
		if ( ! self::user_can_access_branch( $branch_id ) ) {
			return new \WP_Error( 'adc_forbidden_branch', __( 'لا تملك صلاحية لهذا الفرع.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$now = current_time( 'mysql', true );
		$result = $wpdb->insert(
			Schema::table( 'vehicles' ),
			array(
				'vin' => $vin,
				'stock_number' => $stock,
				'brand' => $brand,
				'brand_id' => $brand_id,
				'model' => $model,
				'trim_name' => sanitize_text_field( (string) ( $input['trim'] ?? '' ) ),
				'model_year' => $year,
				'condition_key' => $condition,
				'branch_id' => $branch_id,
				'location_id' => $location_id,
				'status' => 'received',
				'body_type' => sanitize_key( (string) ( $input['body_type'] ?? '' ) ),
				'fuel_type' => sanitize_key( (string) ( $input['fuel_type'] ?? '' ) ),
				'transmission' => sanitize_key( (string) ( $input['transmission'] ?? '' ) ),
				'mileage' => absint( $input['mileage'] ?? 0 ),
				'retail_price' => $retail_price,
				'minimum_price' => $minimum_price ?: null,
				'purchase_cost' => current_user_can( 'manage_options' ) && isset( $input['purchase_cost'] ) ? absint( $input['purchase_cost'] ) : null,
				'currency' => 'SAR',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $result ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vehicle_conflict', __( 'تعذر إنشاء السيارة. تحقق من عدم تكرار رقم الهيكل أو المخزون.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( $specifications ) {
			$formats = array_map( static fn( $field ) => isset( VehicleSpecifications::NUMBER_LIMITS[$field] ) ? '%d' : '%s', array_keys( $specifications ) );
			if ( false === $wpdb->update( Schema::table( 'vehicles' ), $specifications, array( 'id'=>$id ), $formats, array( '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_specification_save_failed', '', array( 'status'=>500 ) );
			}
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.created', 'vehicle', $id, 'Vehicle received into inventory', null, array( 'vin' => $vin, 'branch_id' => $branch_id, 'status' => 'received' ) ) ) ) {
			return new \WP_Error( 'adc_vehicle_conflict', __( 'تعذر توثيق إنشاء السيارة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return self::get( $id, current_user_can( 'adc_view_inventory' ) );
	}

	public static function transition( int $id, string $to, string $reason ) {
		global $wpdb;
		if ( ! current_user_can( 'adc_manage_inventory' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إدارة المخزون.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$to = sanitize_key( $to );
		$reason = sanitize_textarea_field( $reason );
		if ( ! in_array( $to, self::STATUSES, true ) || '' === $reason ) {
			return new \WP_Error( 'adc_invalid_transition', __( 'الحالة أو سبب التغيير غير صالح.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$table = Schema::table( 'vehicles' );
		$vehicle = $wpdb->get_row( $wpdb->prepare( "SELECT id, branch_id, status FROM $table WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $vehicle || ! self::user_can_access_branch( (int) $vehicle['branch_id'] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_vehicle_not_found', __( 'السيارة غير موجودة أو لا تملك صلاحية الوصول إليها.', 'auto-dealership-core' ), array( 'status' => 404 ) );
		}
		$from = $vehicle['status'];
		$active_transfer = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicle_transfers' ) . " WHERE vehicle_id = %d AND status IN ('requested','approved','dispatched') LIMIT 1", $id ) );
		if ( $active_transfer ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_transfer_in_progress', __( 'Vehicle status is controlled by an active branch transfer.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( 'returned' === $to ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_return_record_required', __( 'Process the delivered vehicle through the documented return workflow.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( in_array( $from, array( 'hold', 'maintenance' ), true ) ) {
			$open_issue = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'vehicle_issues' ) . " WHERE vehicle_id = %d AND status = 'open' LIMIT 1", $id ) );
			if ( $open_issue ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'adc_issue_resolution_required', __( 'Resolve the open operational issue before changing the vehicle status.', 'auto-dealership-core' ), array( 'status' => 409 ) );
			}
		}
		if ( ! in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_transition_not_allowed', __( 'الانتقال بين حالتي السيارة غير مسموح.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		if ( in_array( $to, array( 'hold','maintenance' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_issue_record_required', __( 'Open a documented operational issue for hold or maintenance.', 'auto-dealership-core' ), array( 'status'=>409 ) ); }
		if ( ( 'received' === $from && 'inspection' === $to && ! VehicleIntakeService::has_receipt( $id ) ) || ( 'inspection' === $from && 'available' === $to && ! VehicleIntakeService::passed( $id ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_intake_incomplete', __( 'Receipt and a passed mandatory inspection are required before this transition.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$updated = $wpdb->update( $table, array( 'status' => $to, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		$movement = $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => $id, 'from_branch_id' => (int) $vehicle['branch_id'], 'to_branch_id' => (int) $vehicle['branch_id'], 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => get_current_user_id(), 'reason' => $reason, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $updated || false === $movement ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_transition_failed', __( 'تعذر حفظ تغيير حالة السيارة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.status_changed', 'vehicle', $id, $reason, array( 'status' => $from ), array( 'status' => $to ) ) ) ) {
			return new \WP_Error( 'adc_transition_failed', __( 'تعذر توثيق تغيير حالة السيارة.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return self::get( $id, current_user_can( 'adc_view_inventory' ) );
	}

	/** Move inventory between active physical locations in the same branch. */
	public static function move_location( int $id, int $location_id, string $reason ) {
		global $wpdb;
		$reason = sanitize_textarea_field( $reason );
		if ( ! current_user_can( 'adc_manage_inventory' ) ) { return new \WP_Error( 'adc_forbidden', __( 'Inventory management permission is required.', 'auto-dealership-core' ), array( 'status' => 403 ) ); }
		if ( $id < 1 || $location_id < 1 || '' === trim( $reason ) ) { return new \WP_Error( 'adc_invalid_location_move', __( 'Choose a location and provide a reason.', 'auto-dealership-core' ), array( 'status' => 400 ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', '', array( 'status' => 500 ) ); }
		$table = Schema::table( 'vehicles' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,branch_id,location_id,status FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || ! self::user_can_access_branch( (int) $row['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_vehicle_not_found', '', array( 'status' => 404 ) ); }
		if ( in_array( $row['status'], array( 'in_transit','transferred','delivered','cancelled' ), true ) || ! ReferenceService::active_location( $location_id, (int) $row['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_location_move_denied', '', array( 'status' => 409 ) ); }
		$from = (int) $row['location_id'];
		if ( $from === $location_id ) { $wpdb->query( 'ROLLBACK' ); return array( 'id' => $id, 'location_id' => $location_id, 'updated' => false ); }
		$now = current_time( 'mysql', true );
		$updated = $wpdb->update( $table, array( 'location_id' => $location_id, 'updated_at' => $now ), array( 'id' => $id, 'location_id' => $from ), array( '%d','%s' ), array( '%d','%d' ) );
		$movement = 1 === $updated ? $wpdb->insert( Schema::table( 'vehicle_movements' ), array( 'vehicle_id' => $id, 'from_branch_id' => (int) $row['branch_id'], 'to_branch_id' => (int) $row['branch_id'], 'from_location_id' => $from, 'to_location_id' => $location_id, 'from_status' => $row['status'], 'to_status' => $row['status'], 'actor_user_id' => get_current_user_id(), 'reason' => $reason, 'created_at' => $now ), array( '%d','%d','%d','%d','%d','%s','%s','%d','%s','%s' ) ) : false;
		if ( 1 !== $updated || 1 !== $movement || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.location_changed', 'vehicle', $id, $reason, array( 'location_id' => $from ), array( 'location_id' => $location_id ) ) ) ) { if ( false !== $wpdb->query( 'ROLLBACK' ) ) {} return new \WP_Error( 'adc_location_move_failed', '', array( 'status' => 500 ) ); }
		return array( 'id' => $id, 'location_id' => $location_id, 'updated' => true );
	}

	/** Correct a VIN before transactional sale/delivery states, with elevated permission. */
	public static function change_vin( int $id, string $vin, string $reason ) {
		global $wpdb;
		$vin = strtoupper( sanitize_text_field( $vin ) ); $reason = sanitize_textarea_field( $reason );
		if ( ! current_user_can( 'adc_change_vehicle_vin' ) ) { return new \WP_Error( 'adc_vin_forbidden', '', array( 'status' => 403 ) ); }
		if ( ! preg_match( '/^[A-HJ-NPR-Z0-9]{17}$/', $vin ) || '' === trim( $reason ) ) { return new \WP_Error( 'adc_invalid_vin_change', '', array( 'status' => 400 ) ); }
		if ( ! Transaction::begin() ) { return new \WP_Error( 'adc_transaction_failed', '', array( 'status' => 500 ) ); }
		$table = Schema::table( 'vehicles' ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT id,vin,branch_id,status FROM $table WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || ! self::user_can_access_branch( (int) $row['branch_id'] ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_vehicle_not_found', '', array( 'status' => 404 ) ); }
		if ( ! in_array( $row['status'], array( 'ordered','in_transit','received','inspection','hold','maintenance','returned' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_vin_state_locked', '', array( 'status' => 409 ) ); }
		if ( hash_equals( $row['vin'], $vin ) ) { $wpdb->query( 'ROLLBACK' ); return array( 'id' => $id, 'updated' => false ); }
		$changed = $wpdb->update( $table, array( 'vin' => $vin, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'vin' => $row['vin'] ), array( '%s','%s' ), array( '%d','%s' ) );
		$before_hash = hash( 'sha256', $row['vin'] ); $after_hash = hash( 'sha256', $vin );
		if ( 1 !== $changed || ! Transaction::commit( static fn() => AuditLog::record( 'vehicle.vin_changed', 'vehicle', $id, $reason, array( 'vin_sha256' => $before_hash ), array( 'vin_sha256' => $after_hash ) ) ) ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'adc_vin_change_failed', '', array( 'status' => 409 ) ); }
		return array( 'id' => $id, 'updated' => true );
	}

	public static function get( int $id, bool $include_private = false ): ?array {
		global $wpdb;
		$inventory_access = current_user_can( 'adc_view_inventory' ) || current_user_can( 'adc_manage_inventory' );
		if ( ! $inventory_access && ! current_user_can( 'adc_view_finance' ) && ! BranchScope::is_global() ) {
			return null;
		}
		$fields = 'id,stock_number,brand,brand_id,model,trim_name,model_year,condition_key,branch_id,location_id,status,body_type,fuel_type,transmission,mileage,retail_price,currency';
		$fields .= ',' . implode( ',', VehicleSpecifications::fields() );
		if ( $include_private && ( $inventory_access || BranchScope::is_global() ) ) {
			$fields .= ',vin';
		}
		if ( current_user_can( 'manage_options' ) || current_user_can( 'adc_view_finance' ) ) {
			$fields .= ',purchase_cost';
		}
		list( $scope, $args ) = BranchScope::predicate( 'branch_id' );
		array_unshift( $args, $id );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . $fields . ' FROM ' . Schema::table( 'vehicles' ) . ' WHERE id = %d AND ' . $scope, $args ), ARRAY_A );
		return $row ?: null;
	}

	/** Starts the staged transfer workflow. */
	public static function transfer( int $id, int $target_branch_id, string $reason ) {
		return TransferService::request( $id, $target_branch_id, $reason );
	}

	public static function catalog( array $filters = array() ): array {
		return PublicCatalog::catalog( $filters );
	}

	public static function catalog_total( array $filters = array() ): int {
		return PublicCatalog::catalog_total( $filters );
	}

	public static function list_for_current_user( int $page = 1 ): array {
		global $wpdb;
		if ( ! current_user_can( 'adc_view_inventory' ) && ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		$page = max( 1, $page );
		$table = Schema::table( 'vehicles' );
		list( $scope, $args ) = BranchScope::predicate( 'branch_id' );
		$where = ' WHERE ' . $scope;
		$args[] = 50;
		$args[] = ( $page - 1 ) * 50;
		$sql = 'SELECT id,vin,stock_number,brand,model,trim_name,model_year,condition_key,branch_id,status,mileage,retail_price,currency FROM ' . $table . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public static function branch_exists( int $branch_id ): bool {
		return BranchScope::is_active( $branch_id );
	}

	public static function user_can_access_branch( int $branch_id ): bool {
		return BranchScope::allows( $branch_id );
	}
}
