<?php
namespace AutoDealership\Accounts;

use AutoDealership\Database\Schema;
use AutoDealership\Inventory\PublicCatalog;
use AutoDealership\Leads\LegacyEngagementStore;
use AutoDealership\Leads\RequestWorkflow;

defined( 'ABSPATH' ) || exit;

/** Bounded account history read model, scoped only by the authenticated account ID. */
final class CustomerRequestView {
	public const PER_PAGE = 10;

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_customer_request_view_enabled', true );
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function page( string $type, int $page = 1 ) {
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! $user_id || ! in_array( $type, array( 'messages', 'bookings' ), true ) ) {
			return self::error( 'adc_customer_requests_forbidden', 403 );
		}
		if ( ! Schema::is_ready() || ! LegacyEngagementStore::is_ready() ) {
			return self::error( 'adc_customer_requests_unavailable', 503 );
		}
		$page = min( 10000, max( 1, $page ) );
		$table = $wpdb->prefix . ( 'bookings' === $type ? 'car_dealer_bookings' : 'car_dealer_messages' );
		$fields = 'r.id,r.car_id,r.status,r.customer_reply,r.created_at,r.updated_at';
		if ( 'bookings' === $type ) {
			$fields .= ',r.requested_date,r.requested_time';
			$fields .= ',EXISTS (SELECT 1 FROM ' . Schema::table( 'leads' ) . ' linked INNER JOIN ' . Schema::table( 'customers' ) . " owner ON owner.id=linked.customer_id AND owner.account_user_id=%d AND owner.merged_into_id IS NULL WHERE linked.legacy_request_type='booking' AND linked.legacy_request_id=r.id) AS linked";
		} else {
			$fields .= ',r.message';
		}
		$args = 'bookings' === $type
			? array( $user_id, $user_id, self::PER_PAGE + 1, ( $page - 1 ) * self::PER_PAGE )
			: array( $user_id, self::PER_PAGE + 1, ( $page - 1 ) * self::PER_PAGE );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT $fields FROM $table r WHERE r.user_id=%d ORDER BY r.id DESC LIMIT %d OFFSET %d",
				$args
			),
			ARRAY_A
		);
		if ( null === $rows || $wpdb->last_error ) {
			return self::error( 'adc_customer_requests_unavailable', 503 );
		}
		$more = count( $rows ) > self::PER_PAGE;
		$items = array();
		$statuses = RequestWorkflow::statuses( 'bookings' === $type ? 'booking' : 'message' );
		foreach ( array_slice( $rows, 0, self::PER_PAGE ) as $row ) {
			$car_id = (int) $row['car_id'];
			$car_visible = $car_id && PublicCatalog::is_post_publicly_eligible( $car_id )
				&& ( PublicCatalog::vehicle_for_post( $car_id ) || in_array( get_post_meta( $car_id, '_car_inventory_status', true ), array( '', 'available' ), true ) );
			$items[] = array(
				'id' => (int) $row['id'],
				'car_id' => $car_id,
				'car_title' => $car_visible ? (string) get_the_title( $car_id ) : '',
				'car_url' => $car_visible ? (string) get_permalink( $car_id ) : '',
				'status' => (string) $row['status'],
				'status_label' => $statuses[ $row['status'] ] ?? __( 'قيد المتابعة', 'auto-dealership-core' ),
				'message' => 'messages' === $type ? (string) $row['message'] : '',
				'customer_reply' => (string) $row['customer_reply'],
				'requested_date' => 'bookings' === $type ? (string) $row['requested_date'] : '',
				'requested_time' => 'bookings' === $type ? (string) $row['requested_time'] : '',
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) ( $row['updated_at'] ?? '' ),
				'can_cancel' => 'bookings' === $type && ! empty( $row['linked'] ) && in_array( $row['status'], array( 'pending', 'confirmed' ), true ),
		);
		}
		return array( 'items' => $items, 'page' => $page, 'has_more' => $more );
	}

	private static function error( string $code, int $status ): \WP_Error {
		return new \WP_Error( $code, __( 'تعذر تحميل طلبات الحساب حاليًا. حاول مجددًا لاحقًا.', 'auto-dealership-core' ), array( 'status' => $status ) );
	}
}
