<?php
namespace AutoDealership\Admin;

use AutoDealership\Reports\FinancialExport;

defined( 'ABSPATH' ) || exit;

final class FinancialExportPage {
	public static function boot(): void { add_action( 'admin_menu', array( self::class, 'menu' ) ); add_action( 'admin_post_adc_financial_export', array( self::class, 'download' ) ); }
	public static function menu(): void { add_submenu_page( 'adc-finance', __( 'Financial export', 'auto-dealership-core' ), __( 'Financial export', 'auto-dealership-core' ), 'adc_view_finance', 'adc-financial-export', array( self::class, 'render' ) ); }
	public static function render(): void {
		if ( ! current_user_can( 'adc_view_finance' ) ) { wp_die( esc_html__( 'Finance viewing permission is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$to = gmdate( 'Y-m-d' ); $from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ); ?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'تصدير التسويات المالية', 'auto-dealership-core' ); ?></h1><p><?php esc_html_e( 'يتضمن الملف بيانات البيع والتسوية ضمن فروعك فقط، ولا يتضمن بيانات اتصال العميل أو VIN أو مراجع السداد ومزودي التمويل.', 'auto-dealership-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_financial_export"><?php wp_nonce_field( 'adc_financial_export' ); ?><label><?php esc_html_e( 'من', 'auto-dealership-core' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" required></label> <label><?php esc_html_e( 'إلى', 'auto-dealership-core' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" required></label> <?php submit_button( __( 'تنزيل CSV', 'auto-dealership-core' ), 'primary', 'submit', false ); ?></form></div><?php
	}
	public static function download(): void {
		if ( ! current_user_can( 'adc_view_finance' ) ) { wp_die( esc_html__( 'Finance viewing permission is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_financial_export' ); $from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ); $to = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ); $result = FinancialExport::create( $from, $to );
		if ( is_wp_error( $result ) ) { $data = $result->get_error_data(); wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 500 ) ) ); }
		nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="dealership-financial-' . $from . '-' . $to . '.csv"' ); header( 'X-Content-Type-Options: nosniff' ); echo $result['csv']; exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
