<?php
namespace AutoDealership\Leads;

defined( 'ABSPATH' ) || exit;

/** Read-only, capability-scoped access to the retained engagement stores. */
final class EngagementQuery {
	public const PER_PAGE = 20;

	public static function can_view( string $type ): bool {
		if ( 'subscriber' === $type ) {
			return current_user_can( 'adc_view_marketing_subscribers' );
		}

		return in_array( $type, array( 'message', 'booking' ), true )
			&& ( current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) );
	}

	/**
	 * Returns one bounded page without exposing records outside the current staff scope.
	 *
	 * @return array|\WP_Error
	 */
	public static function page( string $type, int $page = 1, int $request_id = 0 ) {
		global $wpdb;

		$tables = array(
			'message'    => 'car_dealer_messages',
			'booking'    => 'car_dealer_bookings',
			'subscriber' => 'car_dealer_subscribers',
		);
		if ( ! isset( $tables[ $type ] ) || ! self::can_view( $type ) ) {
			return new \WP_Error( 'adc_engagement_forbidden', __( 'ليست لديك صلاحية لعرض هذه السجلات.', 'auto-dealership-core' ), array( 'status' => 403 ) );
		}
		if ( ! LegacyEngagementStore::is_ready() ) {
			return new \WP_Error( 'adc_engagement_unavailable', __( 'مخزن سجلات التفاعل غير متاح حاليًا.', 'auto-dealership-core' ), array( 'status' => 503 ) );
		}

		$page  = max( 1, $page );
		$table = $wpdb->prefix . $tables[ $type ];
		$where = '1=1';
		if ( 'subscriber' !== $type ) {
			$where = RequestWorkflow::staff_predicate( $type, 'r.id' );
			if ( $request_id > 0 ) {
				$where .= $wpdb->prepare( ' AND r.id=%d', $request_id );
			}
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table r WHERE $where" );
		if ( $wpdb->last_error ) {
			return new \WP_Error( 'adc_engagement_unavailable', __( 'تعذر قراءة سجلات التفاعل.', 'auto-dealership-core' ), array( 'status' => 503 ) );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.* FROM $table r WHERE $where ORDER BY r.id DESC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);
		if ( null === $rows || $wpdb->last_error ) {
			return new \WP_Error( 'adc_engagement_unavailable', __( 'تعذر قراءة سجلات التفاعل.', 'auto-dealership-core' ), array( 'status' => 503 ) );
		}

		return array(
			'items'       => $rows,
			'total'       => $total,
			'page'        => $page,
			'pages'       => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			'request_id'  => 'subscriber' === $type ? 0 : $request_id,
		);
	}
}
