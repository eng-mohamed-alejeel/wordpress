<?php
namespace AutoDealership\Admin;

use AutoDealership\Reports\OperationalReport;

defined( 'ABSPATH' ) || exit;

/** Aggregate operational report and audited CSV download. */
final class OperationalReportPage {
	private const LABELS = array(
		'inventory'=>'المخزون الحالي',
		'leads'=>'العملاء المحتملون الجدد',
		'reservations'=>'الحجوزات',
		'quotations'=>'عروض الأسعار',
		'sales'=>'المبيعات',
		'deliveries'=>'التسليم',
		'exceptions'=>'الاستثناءات التي تحتاج متابعة',
	);

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_operational_report_export', array( self::class, 'download' ) );
	}

	public static function menu(): void {
		add_menu_page( __( 'التقارير التشغيلية', 'auto-dealership-core' ), __( 'التقارير التشغيلية', 'auto-dealership-core' ), 'adc_view_reports', 'adc-operational-reports', array( self::class, 'render' ), 'dashicons-chart-bar', 63 );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_reports' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض التقارير التشغيلية.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$today = gmdate( 'Y-m-d' );
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		$to = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $today;
		$report = OperationalReport::summary( $from, $to );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>"><h1><?php esc_html_e( 'التقارير التشغيلية', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'مؤشرات مجمعة ضمن فروعك النشطة. لا يتضمن التقرير أسماء العملاء أو وسائل الاتصال أو VIN أو أرقام المخزون.', 'auto-dealership-core' ); ?></p>
			<form method="get"><input type="hidden" name="page" value="adc-operational-reports"><label><?php esc_html_e( 'من', 'auto-dealership-core' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" required></label> <label><?php esc_html_e( 'إلى', 'auto-dealership-core' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" required></label> <?php submit_button( __( 'عرض', 'auto-dealership-core' ), 'secondary', 'submit', false ); ?></form>
			<?php if ( is_wp_error( $report ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $report->get_error_message() ); ?> <code><?php echo esc_html( $report->get_error_code() ); ?></code></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0"><input type="hidden" name="action" value="adc_operational_report_export"><input type="hidden" name="from" value="<?php echo esc_attr( $from ); ?>"><input type="hidden" name="to" value="<?php echo esc_attr( $to ); ?>"><?php wp_nonce_field( 'adc_operational_report_export' ); ?><?php submit_button( __( 'تنزيل CSV مدقق', 'auto-dealership-core' ), 'primary', 'submit', false ); ?></form>
				<p><small><?php echo esc_html( sprintf( __( 'أُنشئ في %s UTC. المخزون والاستثناءات لقطة حالية، وبقية الأقسام ضمن الفترة المختارة.', 'auto-dealership-core' ), $report['generated_at'] ) ); ?></small></p>
				<?php foreach ( OperationalReport::SECTIONS as $section ) { self::table( $section, $report['sections'][ $section ] ); } ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function download(): void {
		if ( ! current_user_can( 'adc_view_reports' ) ) { wp_die( esc_html__( 'لا تملك صلاحية تصدير التقارير التشغيلية.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_operational_report_export' );
		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$to = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
		$result = OperationalReport::export( $from, $to );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $data['status'] ?? 500 ) ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="dealership-operational-' . $from . '-' . $to . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $result['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function table( string $section, array $rows ): void {
		?>
		<h2><?php echo esc_html__( self::LABELS[ $section ] ?? $section, 'auto-dealership-core' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'الفرع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة/المؤشر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'العدد', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المبلغ المجمع (ريال)', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php if ( $rows ) : foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( \AutoDealership\Content\StoredTranslations::text( 'branches', (int) $row['branch_id'], 'name', (string) $row['branch_name'] ) ); ?> <small>#<?php echo absint( $row['branch_id'] ); ?></small></td><td><code><?php echo esc_html( \AutoDealership\Core\Localization::label( (string ) $row['status'] ) ); ?></code></td><td><?php echo absint( $row['total'] ); ?></td><td><?php echo in_array( $section, array( 'quotations','sales' ), true ) ? esc_html( \AutoDealership\Pricing\Money::display( $row['amount'] ) ) : '—'; ?></td></tr><?php endforeach; else : ?><tr><td colspan="4"><?php esc_html_e( 'لا توجد نتائج ضمن النطاق المسموح.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php
	}
}
