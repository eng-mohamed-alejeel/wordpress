<?php
namespace AutoDealership\Database;

defined( 'ABSPATH' ) || exit;

/** Reject dealership API/admin writes and scheduled expiry after an incomplete upgrade. */
final class SchemaGuard {
	public static function boot(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest' ), 10, 3 );
		add_action( 'admin_init', array( self::class, 'admin' ) );
	}

	public static function rest( $result, $server, \WP_REST_Request $request ) {
		if ( null === $result && preg_match( '#\A/auto-dealership/v1(?:/|$)#', $request->get_route() ) && ! Schema::is_ready() ) {
			return new \WP_Error( 'adc_schema_unavailable', __( 'المنصة غير متاحة مؤقتًا أثناء التحقق من قاعدة البيانات.', 'auto-dealership-core' ), array( 'status' => 503 ) );
		}
		return $result;
	}

	public static function admin(): void {
		$action = $_POST['action'] ?? '';
		$branch_assignment = isset( $_POST['adc_branch_id'] );
		if ( ( ( is_string( $action ) && str_starts_with( $action, 'adc_' ) ) || $branch_assignment ) && ! Schema::is_ready() ) {
			wp_die( esc_html__( 'تعذر التحقق من قاعدة بيانات المنصة. حاول بعد معالجة الخطأ.', 'auto-dealership-core' ), '', array( 'response' => 503 ) );
		}
	}
}
