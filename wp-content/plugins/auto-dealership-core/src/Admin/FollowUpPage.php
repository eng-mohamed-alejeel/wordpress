<?php
namespace AutoDealership\Admin;

use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;

defined( 'ABSPATH' ) || exit;

/** Live, permission-scoped reminder queues do not depend on mail delivery or cron. */
final class FollowUpPage {
	private static function text( string $ar, string $en ): string { return 'en' === \AutoDealership\Core\Localization::language() ? $en : $ar; }
	public static function boot(): void {
		add_action( 'admin_menu', static function (): void { if ( current_user_can( 'adc_view_workspace' ) ) { add_submenu_page( 'adc-workspace', self::text( 'المتابعات والتنبيهات', 'Follow-ups and alerts' ), self::text( 'المتابعات والتنبيهات', 'Follow-ups and alerts' ), 'adc_view_workspace', 'adc-follow-ups', array( self::class, 'render' ) ); } } );
	}
	public static function render(): void {
		if ( ! current_user_can( 'adc_view_workspace' ) ) { wp_die( 'Forbidden', '', array( 'response'=>403 ) ); }
		global $wpdb;
		list( $scope, $args ) = BranchScope::predicate( 'l.branch_id' );
		if ( ! BranchScope::is_global() && ! current_user_can( 'adc_view_branch_leads' ) ) { $scope .= ' AND l.owner_user_id=%d'; $args[] = get_current_user_id(); }
		echo '<div class="wrap"><h1>' . esc_html( self::text( 'المتابعات والتنبيهات', 'Follow-ups and alerts' ) ) . '</h1><p>' . esc_html( self::text( 'تظهر المتأخرات ضمن نطاقك، وتظهر جميع متأخرات الفرع للمسؤول المخوّل. الأوقات بتوقيت UTC.', 'Overdue work is visible within your scope; authorized managers see their branch backlog. Times are UTC.' ) ) . '</p>';
		echo '<h2>'.esc_html(self::text('تنبيهات تنتظر إقرار الاستلام','Alerts awaiting acknowledgement')).'</h2><p>'.esc_html(self::text('إقرار الاستلام يوثّق الاطلاع ولا يغلق التعامل. تُصعّد المتابعات المتأخرة للمسؤول المخوّل بعد 24 ساعة.','Acknowledgement records receipt and does not close the transaction. Overdue work escalates to authorized managers after 24 hours.')).'</p>';
		foreach(\AutoDealership\Operations\FollowUpNotifications::inbox() as $alert){
			echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_ack_followup"><input type="hidden" name="id" value="'.absint($alert['id']).'">';
			wp_nonce_field('adc_ack_followup_'.(int)$alert['id']);
			echo '<p><a href="'.esc_url(\AutoDealership\Operations\FollowUpNotifications::url($alert)).'">'.esc_html(\AutoDealership\Operations\FollowUpNotifications::label($alert['source_type']).' #'.$alert['source_id']).'</a> | '.esc_html(wp_date('Y-m-d H:i',strtotime($alert['due_at'].' UTC'))).((int)$alert['level']?' — '.esc_html(self::text('تصعيد','Escalated')):'').' <button class="button">'.esc_html(self::text('إقرار الاستلام','Acknowledge')).'</button></p></form>';
		}
		if(current_user_can('manage_options')){$health=get_option('adc_followup_health',array());echo '<p>'.esc_html(self::text('آخر فحص للتنبيهات: ','Last alert scan: ').($health['finished_at']??self::text('لم يعمل بعد','Not run yet'))).' | <a href="'.esc_url(admin_url('admin.php?page=adc-outbox')).'">'.esc_html(self::text('حالة الإرسال وإعادة المحاولة','Delivery status and retries')).'</a></p>';}
		if(current_user_can('manage_options')){
			$enabled=(bool)get_option('adc_followup_email_enabled',false);
			echo '<details><summary>'.esc_html(self::text('إعداد إرسال التنبيهات بالبريد','Alert email delivery settings')).'</summary><p>'.esc_html(self::text('الصندوق الداخلي يعمل دائمًا. فعّل البريد بعد اختبار إعداد الإرسال. نجاح مهمة البريد يعني قبول الإرسال، وإقرار الموظف يثبت الاطلاع.','The internal inbox is always active. Enable email after testing mail configuration. Mail success records acceptance; staff acknowledgement records receipt.')).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_followup_email"><input type="hidden" name="previous" value="'.($enabled?'1':'0').'">';wp_nonce_field('adc_followup_email');
			echo '<label><input type="checkbox" name="enabled" value="1" '.checked($enabled,true,false).'> '.esc_html(self::text('إرسال بريد للموظفين المسؤولين','Email responsible staff')).'</label> <button class="button">'.esc_html(self::text('حفظ إعداد الإرسال','Save delivery setting')).'</button></form></details>';
		}
		if ( current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'manage_options' ) ) {
			$sql = 'SELECT l.id,l.customer_id,l.owner_user_id,l.next_action_at,c.full_name FROM ' . Schema::table( 'leads' ) . ' l INNER JOIN ' . Schema::table( 'customers' ) . " c ON c.id=l.customer_id WHERE l.stage NOT IN ('won','lost') AND l.next_action_at IS NOT NULL AND l.next_action_at <= %s AND " . $scope . ' ORDER BY l.next_action_at ASC,l.id ASC LIMIT 100';
			$results = QueueTable::fetch( $sql, array_merge( array( current_time( 'mysql', true ) ), $args ), array( 'c.full_name' ) ); QueueTable::controls( 'adc-follow-ups' );
			echo '<h2>' . esc_html( self::text( 'متابعات مستحقة ومتأخرة', 'Due and overdue follow-ups' ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( self::text( 'العميل', 'Customer' ) ) . '</th><th>UTC</th><th>' . esc_html( self::text( 'المسؤول', 'Owner' ) ) . '</th><th>' . esc_html( self::text( 'الفرصة', 'Lead' ) ) . '</th></tr></thead><tbody>';
			foreach ( $results['rows'] as $row ) { echo '<tr><td><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-customer-workspace', 'customer_id'=>$row['customer_id'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $row['full_name'] ) . '</a></td><td>' . esc_html( $row['next_action_at'] ) . '</td><td>' . absint( $row['owner_user_id'] ) . '</td><td><a href="' . esc_url( add_query_arg( array( 'page'=>'adc-crm', 'lead_id'=>$row['id'] ), admin_url( 'admin.php' ) ) ) . '">#' . absint( $row['id'] ) . '</a></td></tr>'; }
			if ( ! $results['rows'] ) { echo '<tr><td colspan="4">' . esc_html( self::text( 'لا توجد متابعات متأخرة.', 'No overdue follow-ups.' ) ) . '</td></tr>'; }
			echo '</tbody></table>'; QueueTable::footer( $results, 'adc-follow-ups' );
		}
		list( $scope, $args ) = BranchScope::predicate( 'q.branch_id' );
		$queues = array();
		if ( current_user_can( 'adc_review_discounts' ) ) { $queues[] = array( self::text( 'خصومات تنتظر الاعتماد', 'Discounts awaiting approval' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'discount_requests' ) . ' d INNER JOIN ' . Schema::table( 'quotations' ) . " q ON q.id=d.quotation_id WHERE d.status='pending' AND " . $scope, $args, 'adc-approvals&section=discounts' ); }
		if ( current_user_can( 'adc_approve_sales' ) ) { $queues[] = array( self::text( 'مبيعات تنتظر الاعتماد', 'Sales awaiting approval' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'sales' ) . ' s INNER JOIN ' . Schema::table( 'quotations' ) . " q ON q.id=s.quotation_id WHERE s.status='pending_approval' AND " . $scope, $args, 'adc-approvals&section=sales' ); }
		if ( \AutoDealership\Pricing\QuoteHistory::can_read() ) {
			list( $rscope, $rargs ) = BranchScope::predicate( 'r.branch_id' );
			if ( ! BranchScope::is_global() && ! current_user_can( 'adc_view_branch_leads' ) && ! current_user_can( 'adc_view_finance' ) ) { $rscope .= ' AND r.owner_user_id=%d'; $rargs[] = get_current_user_id(); }
			$queues[] = array( self::text( 'حجوزات انتهت وتنتظر المعالجة', 'Expired reservations awaiting processing' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . " r WHERE r.status='confirmed' AND r.expires_at<=%s AND " . $rscope, array_merge( array( current_time( 'mysql', true ) ), $rargs ), 'adc-sales-journey&section=reservations' );
			$queues[] = array( self::text( 'حجوزات تنتهي خلال 6 ساعات', 'Reservations expiring within 6 hours' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . " r WHERE r.status='confirmed' AND r.expires_at>%s AND r.expires_at<=%s AND " . $rscope, array_merge( array( current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ) ), $rargs ), 'adc-sales-journey&section=reservations' );
		}
		if ( current_user_can( 'adc_view_finance' ) ) { $queues[] = array( self::text( 'عربون ينتظر الاسترداد', 'Deposits awaiting refund' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . " q WHERE q.deposit_refund_status IN ('pending_refund','partially_refunded') AND " . $scope, $args, 'adc-refunds' ); }
		if ( current_user_can( 'adc_view_finance' ) ) {
			$deadline = gmdate( 'Y-m-d H:i:s', time() - min( 720, max( 1, absint( get_option( 'adc_reservation_refund_hours', 72 ) ) ) ) * HOUR_IN_SECONDS );
			$queues[] = array( self::text( 'استردادات تجاوزت مهلة المعالجة', 'Refunds past processing target' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . " q WHERE q.deposit_refund_status IN ('pending_refund','partially_refunded') AND EXISTS (SELECT 1 FROM " . \AutoDealership\Audit\AuditLog::table_name() . " a WHERE a.subject_type='reservation' AND a.subject_id=q.id AND a.event_key IN ('reservation.cancelled','reservation.expired') AND a.created_at<=%s) AND " . $scope, array_merge( array( $deadline ), $args ), 'adc-refunds' );
		}
		echo '<h2>' . esc_html( self::text( 'تنبيهات سير العمل', 'Workflow alerts' ) ) . '</h2>';
		if(current_user_can('adc_view_inventory')){$conflicts=\AutoDealership\Inventory\InventoryWorkspace::list(array('conflicts'=>'1','availability'=>'all'));if(!is_wp_error($conflicts)){echo '<p><a href="'.esc_url(add_query_arg(array('page'=>'adc-inventory','conflicts'=>1,'availability'=>'all'),admin_url('admin.php'))).'">'.esc_html(self::text('سيارات غائبة عن أونكس ولها تعامل مفتوح','Vehicles absent from Onyx with open transactions')).'</a>: <strong>'.absint($conflicts['total']).'</strong></p>';}}
		if ( current_user_can( 'adc_view_outbox' ) ) { $queues[] = array( self::text( 'مهام آلية فاشلة أو عالقة', 'Failed or stalled background jobs' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'outbox' ) . " WHERE status='failed' OR (status='processing' AND locked_at IS NOT NULL AND locked_at<=%s)", array( gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ) ), 'adc-outbox' ); }
		foreach ( $queues as $queue ) {
			$count = $wpdb->get_var( $queue[2] ? $wpdb->prepare( $queue[1], $queue[2] ) : $queue[1] );
			if ( $wpdb->last_error ) { wp_die( 'Unable to load alerts', '', array( 'response'=>500 ) ); }
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . $queue[3] ) ) . '">' . esc_html( $queue[0] ) . '</a>: <strong>' . absint( $count ) . '</strong></p>';
		}
		echo '</div>';
	}
}
