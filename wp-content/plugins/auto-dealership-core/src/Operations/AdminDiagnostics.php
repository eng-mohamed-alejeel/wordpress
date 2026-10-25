<?php
namespace AutoDealership\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * Optional, bounded diagnostics for administrator requests.
 *
 * Diagnostics are disabled by default. When enabled, only page identifiers,
 * timings, correlation IDs and one-way error fingerprints are retained.
 * Request URLs, SQL, messages, credentials and customer data are never stored.
 */
final class AdminDiagnostics {
	public const OPTION = 'adc_admin_diagnostics';
	public const ENABLED_OPTION = 'adc_admin_diagnostics_enabled';
	public const MAX_RECORDS = 100;
	public const RETENTION_DAYS = 7;
	public const SLOW_PAGE_MS = 800;

	private static bool $booted = false;
	private static float $started_at = 0.0;
	private static string $request_id = '';
	private static ?array $operation_signal = null;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		if ( ! self::is_admin_request() ) {
			return;
		}
		self::$started_at = microtime( true );
		self::$request_id = wp_generate_uuid4();
		add_action( 'admin_post_adc_save_diagnostics', array( self::class, 'save' ) );
		add_filter( 'wp_redirect', array( self::class, 'redirect' ), 10, 2 );
		add_action( 'shutdown', array( self::class, 'shutdown' ), PHP_INT_MAX );
	}

	/** Return the in-memory request ID; it is never persisted by this method. */
	public static function current_request_id(): string {
		if ( '' === self::$request_id && self::is_admin_request() ) {
			self::$request_id = wp_generate_uuid4();
		}
		return self::$request_id;
	}

	public static function enabled(): bool {
		if ( defined( 'ADC_ADMIN_DIAGNOSTICS' ) ) {
			return (bool) constant( 'ADC_ADMIN_DIAGNOSTICS' );
		}
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function settings(): array {
		return array(
			'enabled'        => self::enabled(),
			'retention_days' => self::RETENTION_DAYS,
			'max_records'    => self::MAX_RECORDS,
			'slow_page_ms'   => self::SLOW_PAGE_MS,
			'constant_lock'  => defined( 'ADC_ADMIN_DIAGNOSTICS' ),
		);
	}

	/** Return a redacted snapshot for the health page. */
	public static function snapshot(): array {
		$state   = self::read_state();
		$records = array_values( array_filter( $state['records'], array( self::class, 'valid_record' ) ) );
		$php_errors = 0;
		$db_errors  = 0;
		$slow_pages = 0;
		foreach ( $records as $record ) {
			if ( ! empty( $record['php_error'] ) ) {
				++$php_errors;
			}
			if ( ! empty( $record['db_error'] ) ) {
				++$db_errors;
			}
			if ( ! empty( $record['slow'] ) ) {
				++$slow_pages;
			}
		}
		return array(
			'settings'       => self::settings(),
			'records'        => array_slice( $records, 0, 50 ),
			'total_records'  => count( $records ),
			'php_errors'     => $php_errors,
			'db_errors'      => $db_errors,
			'slow_pages'     => $slow_pages,
			'operation_failures' => count( array_filter( $records, static fn( array $record ): bool => ! empty( $record['operation_failure'] ) ) ),
			'current_request_id' => self::current_request_id(),
		);
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية تعديل إعدادات التشخيص.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_save_diagnostics' );
		$redirect = admin_url( 'admin.php?page=adc-diagnostics' );
		if ( defined( 'ADC_ADMIN_DIAGNOSTICS' ) ) {
			wp_safe_redirect( add_query_arg( 'saved', 'locked', $redirect ) );
			exit;
		}
		$enabled = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );
		update_option( self::ENABLED_OPTION, $enabled, false );
		wp_safe_redirect( add_query_arg( 'saved', get_option( self::ENABLED_OPTION ) === $enabled ? '1' : '0', $redirect ) );
		exit;
	}

	/** Shutdown is deliberately defensive: diagnostics must never break a request. */
	public static function shutdown(): void {
		if ( ! self::enabled() || self::$started_at <= 0 ) {
			return;
		}
		try {
			$duration = max( 0, (int) round( ( microtime( true ) - self::$started_at ) * 1000 ) );
			$record = array(
				'created_at' => time(),
				'time_utc'   => gmdate( 'Y-m-d H:i:s' ),
				'request_id' => self::current_request_id(),
				'page'       => self::page_key(),
				'method'     => self::request_method(),
				'duration_ms'=> $duration,
				'status'     => self::status_code(),
				'slow'       => $duration >= self::SLOW_PAGE_MS,
			);
			if ( self::$operation_signal ) {
				$record['operation_failure'] = self::$operation_signal;
			}
			$last_error = error_get_last();
			if ( is_array( $last_error ) && self::is_diagnostic_error( (int) ( $last_error['type'] ?? 0 ) ) ) {
				$record['php_error'] = array(
					'type'        => self::error_type( (int) ( $last_error['type'] ?? 0 ) ),
					'file'        => sanitize_file_name( basename( (string) ( $last_error['file'] ?? '' ) ) ),
					'line'        => absint( $last_error['line'] ?? 0 ),
					'message_hash'=> self::fingerprint( (string) ( $last_error['message'] ?? '' ) ),
				);
			}
			global $wpdb;
			if ( isset( $wpdb ) && ! empty( $wpdb->last_error ) ) {
				$record['db_error'] = array( 'message_hash' => self::fingerprint( (string) $wpdb->last_error ) );
			}
			self::append( $record );
		} catch ( \Throwable $ignored ) {
			// Observability is best effort and must not alter the original response.
		}
	}

	/** Capture only a sanitized failure marker from an administrative redirect. */
	public static function redirect( $location, $status = 302 ) {
		if ( ! self::enabled() || ! is_string( $location ) ) {
			return $location;
		}
		$query = wp_parse_url( $location, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return $location;
		}
		parse_str( $query, $params );
		$error = isset( $params['error'] ) && is_scalar( $params['error'] ) ? (string) $params['error'] : '';
		if ( '' === $error ) {
			return $location;
		}
		$page = isset( $params['page'] ) && is_scalar( $params['page'] ) ? sanitize_key( (string) $params['page'] ) : '';
		self::$operation_signal = array(
			'page'        => substr( $page ?: 'admin-redirect', 0, 80 ),
			'code_hash'   => self::fingerprint( $error ),
			'status_code' => absint( $status ),
		);
		return $location;
	}

	private static function append( array $record ): void {
		$state = self::read_state();
		$now   = time();
		$min   = $now - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$records = array_values( array_filter( $state['records'], static function ( $item ) use ( $min ): bool {
			return is_array( $item ) && (int) ( $item['created_at'] ?? 0 ) >= $min;
		} ) );
		array_unshift( $records, $record );
		$state['version'] = 1;
		$state['records'] = array_slice( $records, 0, self::MAX_RECORDS );
		update_option( self::OPTION, $state, false );
	}

	private static function read_state(): array {
		$state = get_option( self::OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		$records = isset( $state['records'] ) && is_array( $state['records'] ) ? $state['records'] : array();
		return array( 'version' => 1, 'records' => $records );
	}

	private static function valid_record( $record ): bool {
		return is_array( $record ) && preg_match( '/\A[0-9a-f-]{36}\z/i', (string) ( $record['request_id'] ?? '' ) ) && '' !== (string) ( $record['page'] ?? '' );
	}

	private static function is_admin_request(): bool {
		$is_cli = defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' );
		return function_exists( 'is_admin' ) && is_admin() && ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) && ! $is_cli;
	}

	private static function page_key(): string {
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( '' !== $page ) {
			return substr( 'page:' . $page, 0, 100 );
		}
		$action = isset( $_POST['action'] ) && is_scalar( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
		if ( str_starts_with( $action, 'adc_' ) ) {
			return substr( 'admin-post:' . $action, 0, 100 );
		}
		global $pagenow;
		$base = sanitize_key( basename( (string) ( $pagenow ?? $_SERVER['PHP_SELF'] ?? 'admin' ) ) );
		$post_type = isset( $_GET['post_type'] ) && is_scalar( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';
		return substr( $base . ( $post_type ? ':' . $post_type : '' ), 0, 100 );
	}

	private static function request_method(): string {
		$method = strtoupper( sanitize_key( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
		return in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ? $method : 'OTHER';
	}

	private static function status_code(): int {
		$status = function_exists( 'http_response_code' ) ? http_response_code() : 0;
		return is_int( $status ) && $status >= 100 && $status <= 599 ? $status : 200;
	}

	private static function is_diagnostic_error( int $type ): bool {
		return in_array( $type, array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR ), true );
	}

	private static function error_type( int $type ): string {
		$types = array( E_ERROR=>'fatal', E_PARSE=>'parse', E_CORE_ERROR=>'core', E_COMPILE_ERROR=>'compile', E_USER_ERROR=>'user_fatal', E_WARNING=>'warning', E_USER_WARNING=>'user_warning', E_RECOVERABLE_ERROR=>'recoverable' );
		return $types[ $type ] ?? 'unknown';
	}

	private static function fingerprint( string $value ): string {
		return substr( hash_hmac( 'sha256', $value, wp_salt( 'auth' ) ), 0, 16 );
	}
}
