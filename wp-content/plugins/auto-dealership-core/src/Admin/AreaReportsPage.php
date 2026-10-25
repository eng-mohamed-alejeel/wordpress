<?php
namespace AutoDealership\Admin;

use AutoDealership\Branches\BranchService;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Organized CRM performance reports for management. */
final class AreaReportsPage {
	public static function boot(): void { add_action( 'admin_post_adc_export_area_report', array( self::class, 'export_request' ) ); }

	public static function export_request(): void {
		if ( ! current_user_can( 'adc_view_reports' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية تصدير التقارير.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		check_admin_referer( 'adc_export_area_report' );
		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) ); $to = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) ); $branch_id = absint( $_POST['branch_id'] ?? 0 ); $owner_id = absint( $_POST['owner_id'] ?? 0 ); $source = sanitize_key( (string) ( $_POST['source'] ?? '' ) ); $commission_status = sanitize_key( (string) ( $_POST['commission_status'] ?? '' ) ); $report_type = sanitize_key( (string) ( $_POST['report_type'] ?? 'employees' ) );
		$report = self::query( $from, $to, $branch_id, $owner_id, $source, $commission_status );
		if ( is_wp_error( $report ) ) { wp_die( esc_html( $report->get_error_message() ), '', array( 'response'=>400 ) ); }
		self::export_csv( $report, $from, $to, $report_type );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_reports' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض التقارير.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$today = current_time( 'Y-m-d' );
		$from = isset( $_GET['from'] ) && is_scalar( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d', strtotime( $today . ' -29 days' ) );
		$to = isset( $_GET['to'] ) && is_scalar( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $today;
		$branch_id = absint( $_GET['branch_id'] ?? 0 ); $owner_id = absint( $_GET['owner_id'] ?? 0 ); $source = sanitize_key( (string) ( $_GET['source'] ?? '' ) ); $commission_status = sanitize_key( (string) ( $_GET['commission_status'] ?? '' ) );
		$report = self::query( $from, $to, $branch_id, $owner_id, $source, $commission_status );
		$branches = array_values( array_filter( BranchService::public_list(), static fn( $branch ) => BranchScope::allows( (int) $branch['id'] ) ) );
		$staff = get_users( array( 'role'=>'dealership_sales', 'number'=>100, 'orderby'=>'display_name' ) );
		$sources = array( 'website'=>'الموقع', 'whatsapp'=>'واتساب', 'phone'=>'هاتف', 'walk_in'=>'زيارة مباشرة', 'instagram'=>'إنستغرام', 'tiktok'=>'تيك توك', 'snapchat'=>'سناب شات', 'google'=>'جوجل', 'referral'=>'إحالة', 'campaign'=>'حملة', 'other'=>'أخرى' );
		$dir = 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl';
		echo '<div class="wrap" dir="' . esc_attr( $dir ) . '"><h1>' . esc_html__( 'تقارير CRM التفصيلية', 'auto-dealership-core' ) . '</h1><p>' . esc_html__( 'تحليل أداء الفرص حسب الموظف والفرع والمصدر وأسباب الخسارة.', 'auto-dealership-core' ) . '</p>';
		echo '<form method="get" class="adc-marketing-filters"><input type="hidden" name="page" value="adc-crm-reports"><label>' . esc_html__( 'من تاريخ', 'auto-dealership-core' ) . ' <input type="date" name="from" value="' . esc_attr( $from ) . '" required></label> <label>' . esc_html__( 'إلى تاريخ', 'auto-dealership-core' ) . ' <input type="date" name="to" value="' . esc_attr( $to ) . '" required></label> <label>' . esc_html__( 'الفرع', 'auto-dealership-core' ) . ' <select name="branch_id"><option value="0">' . esc_html__( 'كل الفروع', 'auto-dealership-core' ) . '</option>';
		foreach ( $branches as $branch ) { echo '<option value="' . absint( $branch['id'] ) . '" ' . selected( $branch_id, (int) $branch['id'], false ) . '>' . esc_html( $branch['name'] ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'الموظف', 'auto-dealership-core' ) . ' <select name="owner_id"><option value="0">' . esc_html__( 'كل الموظفين', 'auto-dealership-core' ) . '</option>';
		foreach ( $staff as $user ) { echo '<option value="' . absint( $user->ID ) . '" ' . selected( $owner_id, (int) $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'المصدر', 'auto-dealership-core' ) . ' <select name="source"><option value="">' . esc_html__( 'كل المصادر', 'auto-dealership-core' ) . '</option>';
		foreach ( $sources as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $source, $key, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></label> <label>' . esc_html__( 'حالة العمولة', 'auto-dealership-core' ) . ' <select name="commission_status"><option value="">' . esc_html__( 'كل الحالات', 'auto-dealership-core' ) . '</option><option value="pending_review" ' . selected( $commission_status, 'pending_review', false ) . '>' . esc_html__( 'بانتظار المراجعة', 'auto-dealership-core' ) . '</option><option value="approved" ' . selected( $commission_status, 'approved', false ) . '>' . esc_html__( 'معتمدة', 'auto-dealership-core' ) . '</option><option value="paid" ' . selected( $commission_status, 'paid', false ) . '>' . esc_html__( 'مصروفة', 'auto-dealership-core' ) . '</option><option value="void" ' . selected( $commission_status, 'void', false ) . '>' . esc_html__( 'ملغاة', 'auto-dealership-core' ) . '</option></select></label> <button class="button button-primary">' . esc_html__( 'تطبيق التقرير', 'auto-dealership-core' ) . '</button></form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0"><input type="hidden" name="action" value="adc_export_area_report"><input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><input type="hidden" name="branch_id" value="' . absint( $branch_id ) . '"><input type="hidden" name="owner_id" value="' . absint( $owner_id ) . '"><input type="hidden" name="source" value="' . esc_attr( $source ) . '"><input type="hidden" name="commission_status" value="' . esc_attr( $commission_status ) . '">' . wp_nonce_field( 'adc_export_area_report', '_wpnonce', true, false ) . '<button class="button" name="report_type" value="employees">' . esc_html__( 'تصدير الموظفين CSV', 'auto-dealership-core' ) . '</button><button class="button" name="report_type" value="branches">' . esc_html__( 'تصدير الفروع CSV', 'auto-dealership-core' ) . '</button><button class="button" name="report_type" value="sources">' . esc_html__( 'تصدير المصادر CSV', 'auto-dealership-core' ) . '</button><button class="button" name="report_type" value="campaigns">' . esc_html__( 'تصدير الحملات CSV', 'auto-dealership-core' ) . '</button><button class="button" name="report_type" value="losses">' . esc_html__( 'تصدير أسباب الخسارة CSV', 'auto-dealership-core' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:8px 0"><input type="hidden" name="action" value="adc_export_area_report"><input type="hidden" name="report_type" value="duplicates"><input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><input type="hidden" name="branch_id" value="' . absint( $branch_id ) . '"><input type="hidden" name="owner_id" value="' . absint( $owner_id ) . '"><input type="hidden" name="source" value="' . esc_attr( $source ) . '"><input type="hidden" name="commission_status" value="' . esc_attr( $commission_status ) . '">' . wp_nonce_field( 'adc_export_area_report', '_wpnonce', true, false ) . '<button class="button" type="submit">' . esc_html__( 'تصدير التكرارات CSV', 'auto-dealership-core' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:8px 0"><input type="hidden" name="action" value="adc_export_area_report"><input type="hidden" name="report_type" value="intake_types"><input type="hidden" name="from" value="' . esc_attr( $from ) . '"><input type="hidden" name="to" value="' . esc_attr( $to ) . '"><input type="hidden" name="branch_id" value="' . absint( $branch_id ) . '"><input type="hidden" name="owner_id" value="' . absint( $owner_id ) . '"><input type="hidden" name="source" value="' . esc_attr( $source ) . '"><input type="hidden" name="commission_status" value="' . esc_attr( $commission_status ) . '">' . wp_nonce_field( 'adc_export_area_report', '_wpnonce', true, false ) . '<button class="button" type="submit">' . esc_html__( 'تصدير التحويل حسب نوع الطلب CSV', 'auto-dealership-core' ) . '</button></form>';
		if ( is_wp_error( $report ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $report->get_error_message() ) . '</p></div></div>'; return; }
		$totals = $report['totals'];
		$previous = self::previous_period( $from, $to ); $previous_report = $previous ? self::query( $previous[0], $previous[1], $branch_id, $owner_id, $source, $commission_status ) : new \WP_Error( 'adc_area_report_previous' );
		echo '<div class="adc-crm-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0">';
		foreach ( array( 'total'=>'إجمالي الفرص', 'won'=>'ناجحة', 'lost'=>'خاسرة', 'sales_amount'=>'قيمة المبيعات', 'commission_amount'=>'إجمالي العمولات', 'avg_close_days'=>'متوسط مدة الإغلاق', 'duplicate_count'=>'إعادات الطلب' ) as $key => $label ) { $value = in_array( $key, array( 'sales_amount', 'commission_amount' ), true ) ? number_format_i18n( $totals[ $key ] / 100, 2 ) . ' SAR' : ( 'avg_close_days' === $key ? number_format_i18n( $totals[ $key ], 1 ) . ' يوم' : number_format_i18n( $totals[ $key ] ) ); echo '<div class="card"><strong>' . esc_html( $value ) . '</strong><br>' . esc_html__( $label, 'auto-dealership-core' ) . '</div>'; }
		echo '</div>';
		if ( ! is_wp_error( $previous_report ) ) { self::comparison_cards( $totals, $previous_report['totals'], $previous[0], $previous[1] ); }
		self::render_charts( $report );
		self::table( 'أداء الموظفين', array( 'الموظف', 'الفرص', 'ناجحة', 'خاسرة', 'قيمة المبيعات', 'العمولات', 'متوسط الإغلاق' ), $report['employees'], 'employee_name', false, false, true );
		self::table( 'أداء الفروع', array( 'الفرع', 'الفرص', 'ناجحة', 'خاسرة', 'قيمة المبيعات', 'متوسط الإغلاق' ), $report['branches'], 'branch_name' );
		self::table( 'أداء المصادر', array( 'المصدر', 'الفرص', 'ناجحة', 'خاسرة', 'قيمة المبيعات', 'نسبة الفوز' ), $report['sources'], 'source_name', true );
		self::duplicate_table( $report['duplicates'] );
		self::intake_table( $report['intake_types'] );
		self::campaign_table( $report['campaigns'] );
		self::market_loss_table( $report['loss_market'] );
		self::table( 'أسباب الخسارة', array( 'السبب', 'العدد', 'النسبة' ), $report['losses'], 'reason', false, true );
		echo '</div>';
	}

	private static function query( string $from, string $to, int $branch_id, int $owner_id, string $source, string $commission_status = '' ) {
		global $wpdb;
		$start_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from ); $end_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to ); $start_errors = \DateTimeImmutable::getLastErrors(); $end_errors = \DateTimeImmutable::getLastErrors();
		$invalid_start = ! $start_date || ( is_array( $start_errors ) && ( $start_errors['warning_count'] || $start_errors['error_count'] ) ) || ( $start_date && $start_date->format( 'Y-m-d' ) !== $from ); $invalid_end = ! $end_date || ( is_array( $end_errors ) && ( $end_errors['warning_count'] || $end_errors['error_count'] ) ) || ( $end_date && $end_date->format( 'Y-m-d' ) !== $to );
		if ( $invalid_start || $invalid_end || $start_date > $end_date || $start_date->diff( $end_date )->days > 365 ) { return new \WP_Error( 'adc_area_report_dates', __( 'اختر فترة صحيحة لا تتجاوز 366 يومًا.', 'auto-dealership-core' ) ); }
		$start = $start_date->format( 'Y-m-d 00:00:00' ); $end = $end_date->modify( '+1 day' )->format( 'Y-m-d 00:00:00' );
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		if ( $branch_id > 0 ) { if ( ! BranchScope::allows( $branch_id ) ) { return new \WP_Error( 'adc_area_report_branch' ); } $scope .= ' AND l.branch_id=%d'; $args[] = $branch_id; }
		if ( $owner_id > 0 ) { $scope .= ' AND l.owner_user_id=%d'; $args[] = $owner_id; }
		if ( '' !== $source ) { $scope .= ' AND l.source=%s'; $args[] = $source; }
		$commission_status = in_array( $commission_status, array( 'pending_review', 'approved', 'paid', 'void' ), true ) ? $commission_status : '';
		$lead = Schema::table( 'leads' ); $sales = Schema::table( 'sales' ); $branches = Schema::table( 'branches' );
		$base_args = array_merge( $args, array( $start, $end ) ); $employee_args = $commission_status ? array_merge( array( $commission_status ), $base_args ) : $base_args; $commission_join = $commission_status ? ' AND s.employee_commission_status=%s' : '';
		$employee_sql = "SELECT l.owner_user_id,COALESCE(u.display_name,'غير مسندة') employee_name,COUNT(*) total,SUM(l.stage='won') won,SUM(l.stage='lost') lost,COALESCE(SUM(CASE WHEN l.stage='won' THEN l.sale_amount ELSE 0 END),0) sales_amount,COALESCE(SUM(CASE WHEN l.stage='won' THEN s.employee_commission_amount ELSE 0 END),0) commission_amount,AVG(CASE WHEN l.stage='won' AND l.closed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,l.created_at,l.closed_at)/86400 ELSE NULL END) avg_close_days FROM $lead l LEFT JOIN {$wpdb->users} u ON u.ID=l.owner_user_id LEFT JOIN $sales s ON s.lead_id=l.id$commission_join WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY l.owner_user_id,u.display_name ORDER BY sales_amount DESC";
		$branch_sql = "SELECT l.branch_id,COALESCE(b.name,'بدون فرع') branch_name,COUNT(*) total,SUM(l.stage='won') won,SUM(l.stage='lost') lost,COALESCE(SUM(CASE WHEN l.stage='won' THEN l.sale_amount ELSE 0 END),0) sales_amount,AVG(CASE WHEN l.stage='won' AND l.closed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,l.created_at,l.closed_at)/86400 ELSE NULL END) avg_close_days FROM $lead l LEFT JOIN $branches b ON b.id=l.branch_id WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY l.branch_id,b.name ORDER BY sales_amount DESC";
		$source_sql = "SELECT l.source source_name,COUNT(*) total,SUM(l.stage='won') won,SUM(l.stage='lost') lost,COALESCE(SUM(CASE WHEN l.stage='won' THEN l.sale_amount ELSE 0 END),0) sales_amount FROM $lead l WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY l.source ORDER BY total DESC";
		$campaign_sql = "SELECT COALESCE(NULLIF(l.campaign,''),'غير محددة') campaign_name,COALESCE(NULLIF(l.platform,''),'غير محددة') platform,COUNT(*) total,SUM(l.stage='won') won,SUM(l.stage='lost') lost,COALESCE(SUM(CASE WHEN l.stage='won' THEN l.sale_amount ELSE 0 END),0) sales_amount FROM $lead l WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY campaign_name,platform ORDER BY total DESC";
		$loss_sql = "SELECT COALESCE(NULLIF(l.lost_reason,''),'غير محدد') reason,COUNT(*) total FROM $lead l WHERE $scope AND l.stage='lost' AND l.created_at>=%s AND l.created_at<%s GROUP BY reason ORDER BY total DESC";
		$employees = $wpdb->get_results( $wpdb->prepare( $employee_sql, $employee_args ), ARRAY_A ); $branch_rows = $wpdb->get_results( $wpdb->prepare( $branch_sql, $base_args ), ARRAY_A ); $source_rows = $wpdb->get_results( $wpdb->prepare( $source_sql, $base_args ), ARRAY_A ); $campaigns = $wpdb->get_results( $wpdb->prepare( $campaign_sql, $base_args ), ARRAY_A ); $losses = $wpdb->get_results( $wpdb->prepare( $loss_sql, $base_args ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_area_report_query', __( 'تعذر تحميل التقرير.', 'auto-dealership-core' ) ); }
		$totals = array( 'total'=>0, 'won'=>0, 'lost'=>0, 'sales_amount'=>0, 'commission_amount'=>0, 'avg_close_days'=>0 ); $weighted=0; $count=0;
		foreach ( $employees as &$row ) { foreach ( array( 'total','won','lost','sales_amount','commission_amount' ) as $key ) { $row[$key]=(int)$row[$key]; } $row['avg_close_days']=(float)$row['avg_close_days']; foreach(array('total','won','lost','sales_amount','commission_amount') as $key){$totals[$key]+=$row[$key];} if($row['won']>0){$weighted+=$row['avg_close_days']*$row['won'];$count+=$row['won'];} }
		$totals['avg_close_days']=$count>0?$weighted/$count:0;
		foreach($source_rows as &$row){$row['source_name']=$source===''?($row['source_name']?:'غير محدد'):$row['source_name'];$row['total']=(int)$row['total'];$row['won']=(int)$row['won'];$row['lost']=(int)$row['lost'];$row['sales_amount']=(int)$row['sales_amount'];$row['win_percent']=$row['total']>0?round(100*$row['won']/$row['total'],1):0;}
		foreach($losses as &$row){$row['total']=(int)$row['total'];$row['percent']=$totals['lost']>0?round(100*$row['total']/$totals['lost'],1):0;}
		foreach($campaigns as &$row){$row['total']=(int)$row['total'];$row['won']=(int)$row['won'];$row['lost']=(int)$row['lost'];$row['sales_amount']=(int)$row['sales_amount'];$row['win_percent']=$row['total']>0?round(100*$row['won']/$row['total'],1):0;}
		$duplicate_sql = "SELECT COALESCE(NULLIF(l.intake_type,''),'contact') intake_type_name,COALESCE(NULLIF(l.source,''),'other') source_name,COUNT(a.id) duplicate_count,COUNT(DISTINCT a.subject_id) affected_leads FROM " . \AutoDealership\Audit\AuditLog::table_name() . " a INNER JOIN $lead l ON l.id=a.subject_id WHERE $scope AND a.event_key='lead.duplicate_intake' AND a.subject_type='lead' AND a.created_at>=%s AND a.created_at<%s GROUP BY intake_type_name,source_name ORDER BY duplicate_count DESC,affected_leads DESC";
		$duplicates = $wpdb->get_results( $wpdb->prepare( $duplicate_sql, $base_args ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_area_report_duplicate_query', __( 'تعذر تحميل تحليل الطلبات المكررة.', 'auto-dealership-core' ) ); }
		$duplicate_total = 0; $duplicate_leads = 0;
		foreach ( $duplicates as &$duplicate ) { $duplicate['duplicate_count'] = (int) $duplicate['duplicate_count']; $duplicate['affected_leads'] = (int) $duplicate['affected_leads']; $duplicate_total += $duplicate['duplicate_count']; $duplicate_leads += $duplicate['affected_leads']; }
		$totals['duplicate_count'] = $duplicate_total; $totals['duplicate_leads'] = $duplicate_leads;
		$intake_sql = "SELECT COALESCE(NULLIF(l.intake_type,''),'contact') intake_type_name,COUNT(*) total,SUM(l.stage='won') won,SUM(l.stage='lost') lost,COALESCE(SUM(CASE WHEN l.stage='won' THEN l.sale_amount ELSE 0 END),0) sales_amount,AVG(CASE WHEN l.stage='won' AND l.closed_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND,l.created_at,l.closed_at)/86400 ELSE NULL END) avg_close_days FROM $lead l WHERE $scope AND l.created_at>=%s AND l.created_at<%s GROUP BY intake_type_name ORDER BY total DESC";
		$intake_types = $wpdb->get_results( $wpdb->prepare( $intake_sql, $base_args ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_area_report_intake_query', __( 'تعذر تحميل تحليل أنواع الطلبات.', 'auto-dealership-core' ) ); }
		foreach ( $intake_types as &$intake ) { foreach ( array( 'total','won','lost','sales_amount' ) as $key ) { $intake[$key] = (int) $intake[$key]; } $intake['avg_close_days'] = (float) $intake['avg_close_days']; $intake['win_percent'] = $intake['total'] > 0 ? round( 100 * $intake['won'] / $intake['total'], 1 ) : 0; }
		$loss_market_sql = "SELECT COALESCE(NULLIF(l.lost_competitor,''),'Unspecified') competitor,COUNT(*) total,COALESCE(AVG(NULLIF(l.lost_comparative_price,0)),0) comparative_price FROM $lead l WHERE $scope AND l.stage='lost' AND l.created_at>=%s AND l.created_at<%s GROUP BY competitor ORDER BY total DESC";
		$loss_market = $wpdb->get_results( $wpdb->prepare( $loss_market_sql, $base_args ), ARRAY_A );
		if ( $wpdb->last_error ) { return new \WP_Error( 'adc_area_report_market_query', __( 'Unable to load competitive loss analysis.', 'auto-dealership-core' ) ); }
		foreach ( $loss_market as &$market_row ) { $market_row['total'] = (int) $market_row['total']; $market_row['comparative_price'] = (int) round( (float) $market_row['comparative_price'] ); }
		return array( 'totals'=>$totals, 'employees'=>$employees, 'branches'=>$branch_rows, 'sources'=>$source_rows, 'campaigns'=>$campaigns, 'losses'=>$losses, 'loss_market'=>$loss_market, 'duplicates'=>$duplicates, 'intake_types'=>$intake_types );
	}

	private static function previous_period( string $from, string $to ): ?array {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from ); $end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
		if ( ! $start || ! $end || $start > $end ) { return null; }
		$days = $start->diff( $end )->days + 1; $previous_end = $start->modify( '-1 day' ); $previous_start = $previous_end->modify( '-' . ( $days - 1 ) . ' days' );
		return array( $previous_start->format( 'Y-m-d' ), $previous_end->format( 'Y-m-d' ) );
	}

	private static function comparison_cards( array $current, array $previous, string $from, string $to ): void {
		echo '<section class="card"><h2>' . esc_html__( 'مقارنة الفترة السابقة', 'auto-dealership-core' ) . '</h2><p class="description">' . esc_html( $from . ' — ' . $to ) . '</p><div class="adc-crm-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px">';
		foreach ( array( 'total'=>'إجمالي الفرص', 'won'=>'الفرص الناجحة', 'lost'=>'الفرص الخاسرة', 'sales_amount'=>'قيمة المبيعات' ) as $key => $label ) { $now = 'sales_amount' === $key ? $current[$key] / 100 : $current[$key]; $old = 'sales_amount' === $key ? $previous[$key] / 100 : $previous[$key]; $change = 0 != $old ? round( 100 * ( $now - $old ) / abs( $old ), 1 ) : ( $now > 0 ? 100 : 0 ); $value = 'sales_amount' === $key ? number_format_i18n( $now, 2 ) . ' SAR' : number_format_i18n( $now ); echo '<div><strong>' . esc_html( $value ) . '</strong><br>' . esc_html__( $label, 'auto-dealership-core' ) . '<br><small>' . esc_html( ( $change >= 0 ? '+' : '' ) . number_format_i18n( $change, 1 ) . '%' ) . ' ' . esc_html__( 'مقابل الفترة السابقة', 'auto-dealership-core' ) . '</small></div>'; }
		echo '</div></section>';
	}

	private static function render_charts( array $report ): void {
		$max = max( 1, ...array_map( static fn( $row ) => (int) $row['won'], $report['employees'] ) );
		echo '<section class="card"><h2>' . esc_html__( 'مؤشرات مرئية', 'auto-dealership-core' ) . '</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px">';
		echo '<div><h3>' . esc_html__( 'الفوز حسب الموظف', 'auto-dealership-core' ) . '</h3>';
		foreach ( array_slice( $report['employees'], 0, 10 ) as $row ) { $width = round( 100 * (int) $row['won'] / $max, 1 ); echo '<div style="margin:8px 0"><small>' . esc_html( $row['employee_name'] ) . ' (' . esc_html( number_format_i18n( $row['won'] ) ) . ')</small><div style="height:10px;background:#e5e7eb;border-radius:5px;overflow:hidden"><span style="display:block;height:100%;width:' . esc_attr( $width ) . '%;background:#2271b1"></span></div></div>'; }
		echo '</div><div><h3>' . esc_html__( 'المصادر حسب نسبة الفوز', 'auto-dealership-core' ) . '</h3>';
		foreach ( array_slice( $report['sources'], 0, 10 ) as $row ) { echo '<div style="margin:8px 0"><small>' . esc_html( \AutoDealership\Core\Localization::label( (string) $row['source_name'] ) ) . ' (' . esc_html( number_format_i18n( $row['win_percent'], 1 ) ) . '%)</small><div style="height:10px;background:#e5e7eb;border-radius:5px;overflow:hidden"><span style="display:block;height:100%;width:' . esc_attr( min( 100, (float) $row['win_percent'] ) ) . '%;background:#46b450"></span></div></div>'; }
		echo '</div></div></section>';
	}

	private static function campaign_table( array $rows ): void {
		echo '<section class="card"><h2>' . esc_html__( 'أداء الحملات والمنصات', 'auto-dealership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'الحملة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المنصة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الفرص', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'ناجحة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'خاسرة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المبيعات', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'نسبة الفوز', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['campaign_name'] ) . '</td><td>' . esc_html( $row['platform'] ) . '</td><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['won'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['lost'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['sales_amount'] / 100, 2 ) ) . ' SAR</td><td>' . esc_html( number_format_i18n( $row['win_percent'], 1 ) ) . '%</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="7">' . esc_html__( 'لا توجد بيانات ضمن الفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; } echo '</tbody></table></section>';
	}

	private static function export_csv( array $report, string $from, string $to, string $type = 'employees' ): void {
		$allowed = array( 'employees', 'branches', 'sources', 'campaigns', 'losses', 'duplicates', 'intake_types' ); $type = in_array( $type, $allowed, true ) ? $type : 'employees';
		nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename=adc-crm-' . $type . '-' . sanitize_file_name( $from . '-' . $to ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); fwrite( $out, "\xEF\xBB\xBF" ); $csv = static function ( array $row ) use ( $out ): void { fputcsv( $out, $row, ',', '"', '\\', "\r\n" ); };
		if ( 'employees' === $type ) { $csv( array( 'الموظف', 'إجمالي الفرص', 'الفرص الناجحة', 'الفرص الخاسرة', 'قيمة المبيعات (SAR)', 'العمولات (SAR)', 'متوسط الإغلاق (يوم)' ) ); foreach ( $report['employees'] as $row ) { $csv( array( $row['employee_name'], $row['total'], $row['won'], $row['lost'], number_format( $row['sales_amount'] / 100, 2, '.', '' ), number_format( $row['commission_amount'] / 100, 2, '.', '' ), number_format( $row['avg_close_days'], 1, '.', '' ) ) ); } }
		if ( 'branches' === $type ) { $csv( array( 'الفرع', 'إجمالي الفرص', 'الفرص الناجحة', 'الفرص الخاسرة', 'قيمة المبيعات (SAR)', 'متوسط الإغلاق (يوم)' ) ); foreach ( $report['branches'] as $row ) { $csv( array( $row['branch_name'], $row['total'], $row['won'], $row['lost'], number_format( $row['sales_amount'] / 100, 2, '.', '' ), number_format( (float) $row['avg_close_days'], 1, '.', '' ) ) ); } }
		if ( 'sources' === $type ) { $csv( array( 'المصدر', 'إجمالي الفرص', 'الفرص الناجحة', 'الفرص الخاسرة', 'قيمة المبيعات (SAR)', 'نسبة الفوز (%)' ) ); foreach ( $report['sources'] as $row ) { $csv( array( \AutoDealership\Core\Localization::label( (string) $row['source_name'] ), $row['total'], $row['won'], $row['lost'], number_format( $row['sales_amount'] / 100, 2, '.', '' ), number_format( $row['win_percent'], 1, '.', '' ) ) ); } }
		if ( 'campaigns' === $type ) { $csv( array( 'الحملة', 'المنصة', 'إجمالي الفرص', 'الفرص الناجحة', 'الفرص الخاسرة', 'قيمة المبيعات (SAR)', 'نسبة الفوز (%)' ) ); foreach ( $report['campaigns'] as $row ) { $csv( array( $row['campaign_name'], $row['platform'], $row['total'], $row['won'], $row['lost'], number_format( $row['sales_amount'] / 100, 2, '.', '' ), number_format( $row['win_percent'], 1, '.', '' ) ) ); } }
		if ( 'losses' === $type ) { $csv( array( 'سبب الخسارة', 'المنافس', 'العدد', 'السعر المقارن (SAR)', 'النسبة (%)' ) ); foreach ( $report['losses'] as $row ) { $csv( array( $row['reason'], 'غير محدد', $row['total'], '', number_format( $row['percent'], 1, '.', '' ) ) ); } foreach ( $report['loss_market'] as $row ) { $csv( array( 'تحليل المنافسة', $row['competitor'], $row['total'], number_format( $row['comparative_price'] / 100, 2, '.', '' ), '' ) ); } }
		if ( 'duplicates' === $type ) { $csv( array( 'نوع الطلب', 'المصدر', 'عدد الإعادات', 'الفرص المتأثرة' ) ); foreach ( $report['duplicates'] as $row ) { $csv( array( $row['intake_type_name'], \AutoDealership\Core\Localization::label( (string) $row['source_name'] ), $row['duplicate_count'], $row['affected_leads'] ) ); } }
		if ( 'intake_types' === $type ) { $csv( array( 'نوع الطلب', 'إجمالي الطلبات', 'الفرص الناجحة', 'الفرص الخاسرة', 'قيمة المبيعات (SAR)', 'نسبة الفوز (%)', 'متوسط الإغلاق (يوم)' ) ); foreach ( $report['intake_types'] as $row ) { $csv( array( $row['intake_type_name'], $row['total'], $row['won'], $row['lost'], number_format( $row['sales_amount'] / 100, 2, '.', '' ), number_format( $row['win_percent'], 1, '.', '' ), number_format( $row['avg_close_days'], 1, '.', '' ) ) ); } }
		fclose( $out ); exit;
	}

	private static function duplicate_table( array $rows ): void {
		echo '<section class="card"><h2>' . esc_html__( 'تحليل الطلبات المكررة', 'auto-dealership-core' ) . '</h2><p class="description">' . esc_html__( 'يعرض الإعادات المسجلة برقم خارجي صريح، مع إبقاء الطلبات المختلفة فرصًا مستقلة.', 'auto-dealership-core' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'نوع الطلب', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'المصدر', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'عدد الإعادات', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'الفرص المتأثرة', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['intake_type_name'] ) . '</td><td>' . esc_html( \AutoDealership\Core\Localization::label( (string) $row['source_name'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['duplicate_count'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['affected_leads'] ) ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="4">' . esc_html__( 'لا توجد إعادات مسجلة ضمن الفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table></section>';
	}

	private static function intake_table( array $rows ): void {
		echo '<section class="card"><h2>' . esc_html__( 'تحليل التحويل حسب نوع الطلب', 'auto-dealership-core' ) . '</h2><p class="description">' . esc_html__( 'مقارنة أداء طلبات السعر والتمويل والشراء وتجربة القيادة وغيرها.', 'auto-dealership-core' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'نوع الطلب', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'إجمالي الطلبات', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'ناجحة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'خاسرة', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'قيمة المبيعات', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'نسبة الفوز', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'متوسط الإغلاق', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['intake_type_name'] ) . '</td><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['won'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['lost'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['sales_amount'] / 100, 2 ) ) . ' SAR</td><td>' . esc_html( number_format_i18n( $row['win_percent'], 1 ) ) . '%</td><td>' . esc_html( number_format_i18n( $row['avg_close_days'], 1 ) ) . ' ' . esc_html__( 'يوم', 'auto-dealership-core' ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="7">' . esc_html__( 'لا توجد بيانات ضمن الفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table></section>';
	}

	private static function market_loss_table( array $rows ): void {
		echo '<section class="card"><h2>' . esc_html__( 'تحليل المنافسة والأسعار المقارنة', 'auto-dealership-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'المنافس', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'عدد الفرص', 'auto-dealership-core' ) . '</th><th>' . esc_html__( 'متوسط السعر المقارن', 'auto-dealership-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $row['competitor'] ) . '</td><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['comparative_price'] / 100, 2 ) ) . ' SAR</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="3">' . esc_html__( 'لا توجد بيانات منافسة ضمن الفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; }
		echo '</tbody></table></section>';
	}

	private static function table( string $title, array $headers, array $rows, string $name_key, bool $source = false, bool $loss = false, bool $commission = false ): void {
		echo '<section class="card"><h2>' . esc_html__( $title, 'auto-dealership-core' ) . '</h2><table class="widefat striped"><thead><tr>'; foreach ( $headers as $header ) { echo '<th>' . esc_html__( $header, 'auto-dealership-core' ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr><td>' . esc_html( $source ? \AutoDealership\Core\Localization::label( (string) $row[$name_key] ) : $row[$name_key] ) . '</td>'; if ( $loss ) { echo '<td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['percent'], 1 ) ) . '%</td>'; } else { echo '<td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['won'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['lost'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['sales_amount'] / 100, 2 ) ) . ' SAR</td>' . ( $commission ? '<td>' . esc_html( number_format_i18n( $row['commission_amount'] / 100, 2 ) ) . ' SAR</td>' : '' ) . '<td>' . esc_html( $source ? number_format_i18n( $row['win_percent'], 1 ) . '%' : number_format_i18n( (float) $row['avg_close_days'], 1 ) . ' يوم' ) . '</td>'; } echo '</tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="' . count( $headers ) . '">' . esc_html__( 'لا توجد بيانات ضمن الفترة المحددة.', 'auto-dealership-core' ) . '</td></tr>'; } echo '</tbody></table></section>';
	}
}
