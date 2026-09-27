<?php
namespace AutoDealership\Branches;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;

defined( 'ABSPATH' ) || exit;

final class BranchService {
	public static function create( array $input ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'adc_forbidden', __( 'لا تملك صلاحية إدارة الفروع.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		$code = strtoupper( sanitize_key( (string) ( $input['code'] ?? '' ) ) );
		$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( ! preg_match( '/^[A-Z0-9_-]{2,32}$/', $code ) || '' === $name ) {
			return new \WP_Error( 'adc_invalid_branch', __( 'رمز الفرع واسمه مطلوبان.', 'auto-dealership-core' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		if ( ! Transaction::begin() ) {
			return new \WP_Error( 'adc_transaction_failed', __( 'تعذر بدء العملية.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		$result = $wpdb->insert( Schema::table( 'branches' ), array( 'code' => $code, 'name' => $name, 'city' => sanitize_text_field( (string) ( $input['city'] ?? '' ) ), 'address' => sanitize_textarea_field( (string) ( $input['address'] ?? '' ) ), 'active' => 1, 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		if ( false === $result ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'adc_branch_conflict', __( 'تعذر إنشاء الفرع. قد يكون الرمز مستخدمًا.', 'auto-dealership-core' ), array( 'status' => 409 ) );
		}
		$id = (int) $wpdb->insert_id;
		$city = sanitize_text_field( (string) ( $input['city'] ?? '' ) );
		if ( ! Transaction::commit( static fn() => AuditLog::record( 'branch.created', 'branch', $id, '', null, array( 'code' => $code, 'city' => $city ) ) ) ) {
			return new \WP_Error( 'adc_branch_conflict', __( 'تعذر توثيق إنشاء الفرع.', 'auto-dealership-core' ), array( 'status' => 500 ) );
		}
		return array( 'id' => $id, 'code' => $code, 'name' => $name, 'city' => $city );
	}

	public static function public_list(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id,code,name,city FROM ' . Schema::table( 'branches' ) . ' WHERE active = 1 ORDER BY city,name', ARRAY_A ) ?: array();
	}
}
