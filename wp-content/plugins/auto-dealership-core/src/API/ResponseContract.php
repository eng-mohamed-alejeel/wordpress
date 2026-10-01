<?php
namespace AutoDealership\API;

defined( 'ABSPATH' ) || exit;

/** Adds correlation headers and the stable v2 response envelope. */
final class ResponseContract {
	private static string $current_request_id = '';

	public static function boot(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'begin' ), 1, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'format' ), 20, 3 );
	}

	public static function begin( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( preg_match( '#\A/auto-dealership/v[12](?:/|$)#', $request->get_route() ) ) {
			self::$current_request_id = self::resolve_request_id( $request );
		}
		return $result;
	}

	public static function current_request_id(): string { return self::$current_request_id; }

	public static function format( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		$route = $request->get_route();
		if ( ! preg_match( '#\A/auto-dealership/v([12])(?:/|$)#', $route, $match ) ) { return $response; }
		if ( is_wp_error( $response ) ) { $response = $server->error_to_response( $response ); }
		$response = rest_ensure_response( $response );
		$request_id = self::$current_request_id ?: self::resolve_request_id( $request );
		self::$current_request_id = $request_id;
		$response->header( 'X-Request-ID', $request_id );
		if ( '2' !== $match[1] ) { return $response; }
		$data = $response->get_data();
		$status = $response->get_status();
		$meta = array( 'request_id'=>$request_id );
		if ( $status >= 400 ) {
			$code = is_array( $data ) && isset( $data['code'] ) ? sanitize_key( (string) $data['code'] ) : 'adc_request_failed';
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : __( 'The request could not be completed.', 'auto-dealership-core' );
			$details = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
			unset( $details['status'] );
			$response->set_data( array( 'success'=>false, 'error'=>array( 'code'=>$code, 'message'=>$message, 'details'=>$details ), 'meta'=>$meta ) );
			return $response;
		}
		$response->set_data( array( 'success'=>true, 'data'=>$data, 'meta'=>$meta ) );
		return $response;
	}

	private static function resolve_request_id( \WP_REST_Request $request ): string {
		$value = trim( (string) $request->get_header( 'x-request-id' ) );
		return preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value ) ? strtolower( $value ) : wp_generate_uuid4();
	}
}
