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
		echo '<div class="wrap"><h1>' . esc_html( self::text( 'المتابعات والتنبيهات', 'Follow-ups and alerts' ) ) . '</h1><p>' . esc_html( self::text( 'صندوق الإشعارات والتصعيد والاستثناءات التشغيلية. قوائم الأعمال اليومية والاعتمادات موجودة في الرئيسية.', 'Your notification inbox, escalations and operational exceptions. Daily work and approval queues are on the workspace home.' ) ) . '</p>';
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
		list( $scope, $args ) = BranchScope::predicate( 'q.branch_id' );
		$queues = array();
		if ( current_user_can( 'adc_view_finance' ) ) { $queues[] = array( self::text( 'عربون ينتظر الاسترداد', 'Deposits awaiting refund' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . " q WHERE q.deposit_refund_status IN ('pending_refund','partially_refunded') AND " . $scope, $args, 'adc-refunds' ); }
		if ( current_user_can( 'adc_view_finance' ) ) {
			$deadline = gmdate( 'Y-m-d H:i:s', time() - min( 720, max( 1, absint( get_option( 'adc_reservation_refund_hours', 72 ) ) ) ) * HOUR_IN_SECONDS );
			$queues[] = array( self::text( 'استردادات تجاوزت مهلة المعالجة', 'Refunds past processing target' ), 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . " q WHERE q.deposit_refund_status IN ('pending_refund','partially_refunded') AND EXISTS (SELECT 1 FROM " . \AutoDealership\Audit\AuditLog::table_name() . " a WHERE a.subject_type='reservation' AND a.subject_id=q.id AND a.event_key IN ('reservation.cancelled','reservation.expired') AND a.created_at<=%s) AND " . $scope, array_merge( array( $deadline ), $args ), 'adc-refunds' );
		}
		echo '<h2>' . esc_html( self::text( 'الاستثناءات التشغيلية', 'Operational exceptions' ) ) . '</h2>';
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
