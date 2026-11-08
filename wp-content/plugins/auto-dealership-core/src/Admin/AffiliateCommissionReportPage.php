<?php
namespace AutoDealership\Admin;

use AutoDealership\Pricing\Money;
use AutoDealership\Reports\AffiliateCommissionExport;
use AutoDealership\Reports\AffiliateCommissionReport;
defined( 'ABSPATH' ) || exit;

final class AffiliateCommissionReportPage {
	public static function boot(): void { add_action( 'admin_post_adc_affiliate_commission_report_export', array( self::class, 'export' ) ); add_action( 'admin_post_adc_affiliate_commission_report_excel', array( self::class, 'export_excel' ) ); }

	public static function render(): void {
		if ( ! self::can_manage() ) { wp_die( esc_html__( 'لا تملك صلاحية عرض تقرير العمولات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$filters = array( 'from'=>sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) ), 'to'=>sanitize_text_field( wp_unslash( $_GET['to'] ?? '' ) ), 'status'=>sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ), 'affiliate_user_id'=>absint( $_GET['affiliate_user_id'] ?? 0 ), 'branch_id'=>absint( $_GET['branch_id'] ?? 0 ), 'period'=>sanitize_key( wp_unslash( $_GET['period'] ?? 'month' ) ) );
			$result = AffiliateCommissionReport::report( $filters ); if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response'=>(int) ( $result->get_error_data()['status'] ?? 500 ) ) ); }
		$summary = $result['summary'];
		echo '<div class="wrap" dir="rtl"><h1>تقارير عمولات المسوقين</h1><p>يُحتسب التقرير من سجل العمولات المالي، مع تثبيت العملة والمنطقة الزمنية المستخدمة في التصدير.</p>';
		echo '<form method="get"><input type="hidden" name="page" value="adc-affiliate-reports"><label>من <input type="date" name="from" value="' . esc_attr( $result['filters']['from'] ) . '" required></label> <label>إلى <input type="date" name="to" value="' . esc_attr( $result['filters']['to'] ) . '" required></label> <label>الحالة <select name="status"><option value="">الكل</option>';
		foreach ( array( 'pending_review'=>'بانتظار المراجعة','approved'=>'معتمدة للصرف','paid'=>'مدفوعة','void'=>'ملغاة','paid_sale_returned'=>'صفقة مرتجعة' ) as $value=>$label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $result['filters']['status'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label> <label>المسوق <input type="number" min="0" name="affiliate_user_id" value="' . esc_attr( $result['filters']['affiliate_user_id'] ) . '"></label> <label>الفرع <input type="number" min="0" name="branch_id" value="' . esc_attr( $result['filters']['branch_id'] ) . '"></label> <label>التجميع <select name="period"><option value="month"' . selected( $result['filters']['period'], 'month', false ) . '>شهري</option><option value="day"' . selected( $result['filters']['period'], 'day', false ) . '>يومي</option></select></label> <button class="button button-primary">تصفية</button></form>';
		echo '<p><small>الفترة: ' . esc_html( $result['filters']['from'] . ' إلى ' . $result['filters']['to'] . ' · ' . $result['filters']['timezone'] . ' · SAR' ) . '</small></p>' . self::export_forms( $result['filters'] );
		ReportView::print_button();
		echo '<div class="adc-affiliate-summary">'; foreach ( array( 'gross'=>'الإجمالي','reversals'=>'العكوسات','net_ledger'=>'صافي السجل','pending_review'=>'قيد المراجعة','approved'=>'معتمد','paid'=>'مدفوع' ) as $key=>$label ) { echo '<div class="card"><strong>' . esc_html( Money::display( (int) $summary[ $key ] ) ) . '</strong><br>' . esc_html( $label ) . '</div>'; } echo '</div>';
		echo '<h2>المؤشر الزمني</h2>' . self::chart( $result['by_period'] ) . '<h2>حسب المسوق</h2>' . self::table( $result['by_affiliate'] ) . '<h2>حسب الفرع</h2>' . self::table( $result['by_branch'] ) . '<h2>حسب الفترة</h2>' . self::table( $result['by_period'] ) . '<h2>التسوية والفروقات</h2><p>' . esc_html( wp_json_encode( $result['reconciliation']['summary'], JSON_UNESCAPED_UNICODE ) ) . '</p>' . self::issues( $result['reconciliation']['items'] ) . '</div>';
	}

	public static function export(): void { self::download( 'csv' ); }
	public static function export_excel(): void { self::download( 'excel' ); }
	private static function download( string $format ): void {
		if ( ! self::can_manage() ) { wp_die( esc_html__( 'لا تملك صلاحية تصدير تقرير العمولات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$action = 'csv' === $format ? 'adc_affiliate_commission_report_export' : 'adc_affiliate_commission_report_excel'; check_admin_referer( $action );
		$filters=array();foreach(array('from','to','status','period') as $key){$filters[$key]=sanitize_text_field(wp_unslash($_POST[$key]??''));}foreach(array('affiliate_user_id','branch_id') as $key){$filters[$key]=absint($_POST[$key]??0);}
		$result=AffiliateCommissionReport::export($filters,$format);if(is_wp_error($result)){wp_die(esc_html($result->get_error_message()),'',array('response'=>(int)($result->get_error_data()['status']??500)));}nocache_headers();$filename='affiliate-commissions-'.$result['filters']['from'].'-'.$result['filters']['to'];
		if('csv'===$format){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'.csv"');echo $result['csv'];exit;}// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if('excel'===$format){$binary=AffiliateCommissionExport::xlsx($result['data'],$result['filters']);if(is_wp_error($binary)){wp_die(esc_html($binary->get_error_message()),'',array('response'=>503));}header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');echo $binary;exit;}// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	private static function export_forms( array $filters ): string {
		$html = '<div class="adc-export-actions no-print" style="display:flex;gap:8px;margin:10px 0">';
		foreach ( array( 'csv'=>array('تنزيل CSV','adc_affiliate_commission_report_export'), 'excel'=>array('تنزيل Excel','adc_affiliate_commission_report_excel') ) as $format => $data ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $data[1] ) . '">' . wp_nonce_field( $data[1], '_wpnonce', true, false );
			foreach ( array( 'from', 'to', 'status', 'affiliate_user_id', 'branch_id', 'period' ) as $key ) {
				$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $filters[ $key ] ?? '' ) . '">';
			}
			$html .= '<button class="button">' . esc_html( $data[0] ) . '</button></form>';
		}
		return $html . '</div>';
	}
	private static function table(array $rows):string{$html='<table class="widefat striped"><thead><tr><th>التصنيف</th><th>الفترة</th><th>العدد</th><th>الإجمالي</th><th>العكوسات</th><th>الصافي</th><th>المدفوع</th></tr></thead><tbody>';if(!$rows){return $html.'<tr><td colspan="7">لا توجد نتائج.</td></tr></tbody></table>';}foreach($rows as $row){$html.='<tr><td>'.esc_html((string)($row['group_label']??'' )).'</td><td>'.esc_html((string)($row['period']??'' )).'</td><td>'.absint($row['commission_count']).'</td><td>'.esc_html(Money::display((int)$row['gross'])).'</td><td>'.esc_html(Money::display((int)$row['reversals'])).'</td><td>'.esc_html(Money::display((int)$row['net_ledger'])).'</td><td>'.esc_html(Money::display((int)$row['paid'])).'</td></tr>';}return $html.'</tbody></table>';}
	private static function chart(array $rows):string{$max=1;foreach($rows as $row){$max=max($max,abs((int)$row['gross']));}$html='<div class="adc-affiliate-chart" style="display:grid;gap:7px;max-width:760px">';foreach($rows as $row){$width=(int)round(abs((int)$row['gross'])*100/$max);$html.='<div><span>'.esc_html((string)$row['period']).'</span><div style="height:14px;background:#2271b1;width:'.$width.'%;min-width:2px" title="'.esc_attr(Money::display((int)$row['gross'])).'"></div></div>';}return $html.'</div>';}
	private static function issues(array $rows):string{$html='<table class="widefat striped"><thead><tr><th>المعرّف</th><th>النوع</th><th>السبب</th></tr></thead><tbody>';foreach(array_slice($rows,0,100) as $row){$html.='<tr><td>'.absint($row['id']).'</td><td>'.esc_html($row['issue_type']).'</td><td>'.esc_html($row['reason']).'</td></tr>';}return $html.'</tbody></table>';}
	private static function can_manage():bool{return current_user_can('adc_manage_affiliate_commissions')||current_user_can('manage_options');}
}
