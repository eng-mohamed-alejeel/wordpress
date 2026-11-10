<?php
namespace AutoDealership\Admin;

use AutoDealership\Reports\FinancialExport;

defined( 'ABSPATH' ) || exit;

final class FinancialExportPage {
	public static function boot(): void { add_action( 'admin_menu', array( self::class, 'menu' ) ); add_action( 'admin_post_adc_financial_export', array( self::class, 'download' ) ); }
	public static function menu(): void { add_submenu_page( 'adc-finance', __( 'Financial export', 'auto-dealership-core' ), __( 'Financial export', 'auto-dealership-core' ), 'adc_view_finance', 'adc-financial-export', array( self::class, 'render' ) ); }
	public static function render(): void {
		if ( ! current_user_can( 'adc_view_finance' ) ) { wp_die( esc_html__( 'Finance viewing permission is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$to = gmdate( 'Y-m-d' ); $from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		if ( isset( $_GET['from'] ) && is_scalar( $_GET['from'] ) && '' !== (string) $_GET['from'] ) { $from = sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ); }
		if ( isset( $_GET['to'] ) && is_scalar( $_GET['to'] ) && '' !== (string) $_GET['to'] ) { $to = sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ); }
		$report = FinancialExport::report( $from, $to ); ?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'تقرير التسويات المالية', 'auto-dealership-core' ); ?></h1><p><?php esc_html_e( 'عرض تفصيلي لعمليات البيع والتسوية ضمن فروعك فقط، دون بيانات اتصال العميل أو VIN أو مراجع مزودي التمويل.', 'auto-dealership-core' ); ?></p>
		<form method="get"><input type="hidden" name="page" value="adc-financial-export"><label><?php esc_html_e( 'من', 'auto-dealership-core' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" required></label> <label><?php esc_html_e( 'إلى', 'auto-dealership-core' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" required></label> <?php submit_button( __( 'عرض التقرير', 'auto-dealership-core' ), 'secondary', 'submit', false ); ?></form>
		<?php if ( is_wp_error( $report ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div>
		<?php else : ?>
			<?php ReportView::print_button(); $rows = $report['rows']; $gross = 0; $verified = 0; foreach ( $rows as $row ) { $gross += (int) $row['final_amount']; $verified += (int) $row['verified_amount']; } ?>
			<?php ReportView::summary_cards( array( array( 'label'=>__( 'عدد المبيعات', 'auto-dealership-core' ), 'value'=>number_format_i18n( count( $rows ) ) ), array( 'label'=>__( 'إجمالي العقود', 'auto-dealership-core' ), 'value'=>\AutoDealership\Pricing\Money::display( $gross ) ), array( 'label'=>__( 'المبلغ المتحقق', 'auto-dealership-core' ), 'value'=>\AutoDealership\Pricing\Money::display( $verified ) ), array( 'label'=>__( 'المتبقي', 'auto-dealership-core' ), 'value'=>\AutoDealership\Pricing\Money::display( max( 0, $gross - $verified ) ) ) ) ); ?>
			<?php ReportView::bar_chart( __( 'مقارنة العقود والتحصيل', 'auto-dealership-core' ), array( array( 'label'=>__( 'إجمالي العقود', 'auto-dealership-core' ), 'value'=>$gross / \AutoDealership\Core\CurrencySettings::minor_factor() ), array( 'label'=>__( 'المبلغ المتحقق', 'auto-dealership-core' ), 'value'=>$verified / \AutoDealership\Core\CurrencySettings::minor_factor() ) ) ); ?>
			<p class="description"><?php echo esc_html( sprintf( __( 'الفترة: %1$s إلى %2$s · المنطقة الزمنية: UTC · العملة: %3$s · أُنشئ في: %4$s UTC.', 'auto-dealership-core' ), $report['from'], $report['to'], $report['currency'], $report['generated_at'] ) ); ?></p>
			<?php if ( $report['truncated'] ) : ?><div class="notice notice-warning inline"><p><?php esc_html_e( 'تم عرض أول 5000 سجل فقط وفق الحد الفني للتقرير.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adc-export-actions no-print"><input type="hidden" name="action" value="adc_financial_export"><?php wp_nonce_field( 'adc_financial_export' ); ?><input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>"><input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>"><?php submit_button( __( 'تنزيل CSV مدقق', 'auto-dealership-core' ), 'primary', 'submit', false ); ?></form>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'البيع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الفرع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php echo esc_html( 'الإجمالي ' . $report['currency'] ); ?></th><th><?php echo esc_html( 'المتحقق ' . $report['currency'] ); ?></th><th><?php esc_html_e( 'الفاتورة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'التاريخ UTC', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( array_slice( $rows, 0, 100 ) as $row ) : ?><tr><td>#<?php echo absint( $row['sale_id'] ); ?><br><small><?php echo esc_html( $row['quote_number'] ); ?></small></td><td>#<?php echo absint( $row['branch_id'] ); ?></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $row['status'] ) ); ?></td><td><?php echo esc_html( \AutoDealership\Pricing\Money::display( (int) $row['final_amount'] ) ); ?></td><td><?php echo esc_html( \AutoDealership\Pricing\Money::display( (int) $row['verified_amount'] ) ); ?></td><td><?php echo esc_html( $row['invoice_reference'] ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td></tr><?php endforeach; ?>
			<?php if ( ! $rows ) : ?><tr><td colspan="7"><?php esc_html_e( 'لا توجد مبيعات ضمن الفترة المحددة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?></tbody></table>
			<?php if ( count( $rows ) > 100 ) : ?><p class="description"><?php esc_html_e( 'يعرض الجدول أول 100 سجل فقط؛ يبقى التصدير المدقق متاحًا لكامل الحد الفني.', 'auto-dealership-core' ); ?></p><?php endif; ?>
		<?php endif; ?></div><?php
	}
	public static function download(): void {
		if ( ! current_user_can( 'adc_view_finance' ) ) { wp_die( esc_html__( 'Finance viewing permission is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_financial_export' ); $from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ); $to = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ); $result = FinancialExport::create( $from, $to );
		if ( is_wp_error( $result ) ) { $data = $result->get_error_data(); wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $data['status'] ?? 500 ) ) ); }
		nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="dealership-financial-' . $from . '-' . $to . '.csv"' ); header( 'X-Content-Type-Options: nosniff' ); echo $result['csv']; exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
