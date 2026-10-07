<?php
namespace AutoDealership\Tools;

use AutoDealership\Security\PublicRequestGuard;

defined( 'ABSPATH' ) || exit;

/** Owns public comparison changes and provider-neutral finance estimates. */
final class PublicTools {
	private static bool $booted = false;

	public static function enabled(): bool {
		return (bool) apply_filters( 'adc_core_public_tools_enabled', true );
	}

	public static function owns_theme_actions(): bool {
		return self::enabled();
	}

	public static function boot(): void {
		if ( self::$booted || ! self::enabled() ) {
			return;
		}
		self::$booted = true;
		add_action( 'wp_ajax_car_dealer_comparison', array( self::class, 'handle_comparison' ) );
		add_action( 'wp_ajax_nopriv_car_dealer_comparison', array( self::class, 'handle_comparison' ) );
		add_action( 'wp_ajax_car_dealer_loan_calculator', array( self::class, 'handle_calculator' ) );
		add_action( 'wp_ajax_nopriv_car_dealer_loan_calculator', array( self::class, 'handle_calculator' ) );
	}

	public static function enqueue(): void {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_script( 'adc-public-tools', plugins_url( 'assets/js/public-tools.js', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/js/public-tools.js' ), true );
		wp_enqueue_style( 'adc-finance-calculator', plugins_url( 'assets/css/finance-calculator.css', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/finance-calculator.css' ) );
		wp_localize_script( 'adc-public-tools', 'adcPublicTools', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'adc_public_tools' ),
			'language' => \AutoDealership\Core\Localization::language(),
			'currencyLabel' => __( 'SAR', 'auto-dealership-core' ),
			'addLabel' => __( 'أضف للمقارنة', 'auto-dealership-core' ),
			'removeLabel' => __( 'إزالة من المقارنة', 'auto-dealership-core' ),
			'comparisonError' => __( 'تعذر تحديث المقارنة.', 'auto-dealership-core' ),
			'calculatingLabel' => __( 'جارٍ الحساب…', 'auto-dealership-core' ),
			'calculatorError' => __( 'تعذر حساب التقدير. راجع القيم وحاول مجددًا.', 'auto-dealership-core' ),
		) );
	}

	public static function handle_comparison(): void {
		if ( ! self::valid_request() ) {
			wp_send_json_error( array( 'message'=>__( 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مجددًا.', 'auto-dealership-core' ) ), 403 );
		}
		$rate = PublicRequestGuard::consume( 'public_read' );
		if ( is_wp_error( $rate ) ) {
			self::send_error( $rate );
		}
		$result = VehicleComparison::change( absint( $_POST['car_id'] ?? 0 ), sanitize_key( self::field( 'compare_action', 'toggle' ) ) );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}
		wp_send_json_success( array_merge( $result, array( 'message'=>__( 'تم تحديث المقارنة.', 'auto-dealership-core' ) ) ) );
	}

	public static function handle_calculator(): void {
		if ( ! self::valid_request() ) {
			wp_send_json_error( array( 'message'=>__( 'انتهت صلاحية الطلب. حدّث الصفحة وحاول مجددًا.', 'auto-dealership-core' ) ), 403 );
		}
		$rate = PublicRequestGuard::consume( 'public_read' );
		if ( is_wp_error( $rate ) ) {
			self::send_error( $rate );
		}
		$input = array();
		foreach ( array( 'price', 'salary', 'months', 'sector', 'nationality', 'transfer', 'provider', 'campaign', 'category', 'chinese', 'employer', 'insurance_rate', 'obligations', 'brand', 'assumed_down', 'assumed_balloon', 'assumed_admin' ) as $key ) {
			$input[ $key ] = self::field( $key );
		}
		$result = LoanCalculator::calculate( $input );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}
		wp_send_json_success( $result );
	}

	private static function valid_request(): bool {
		return isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) && wp_verify_nonce( wp_unslash( $_POST['nonce'] ), 'adc_public_tools' );
	}

	private static function field( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( wp_unslash( (string) $_POST[ $key ] ) ) : $default;
	}

	private static function send_error( \WP_Error $error ): void {
		$data = $error->get_error_data();
		wp_send_json_error( array( 'message'=>$error->get_error_message(), 'code'=>$error->get_error_code() ), (int) ( is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ) );
	}
}
