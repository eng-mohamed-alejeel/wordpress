<?php
namespace AutoDealership\Admin;

use AutoDealership\Operations\AdministrativeAlert;

defined( 'ABSPATH' ) || exit;

/** Independent history and retry screen for administrator delivery alerts. */
final class AdministrativeAlertPage {
	public static function boot(): void { add_action( 'admin_post_adc_administrative_alert_retry', array( self::class, 'retry' ) ); }

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض تنبيهات الإدارة.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$filters = array( 'page' => max( 1, absint( $_GET['paged'] ?? 1 ) ), 'per_page' => 25, 'status' => sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ), 'event' => sanitize_key( wp_unslash( $_GET['event'] ?? '' ) ), 'search' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		$data = AdministrativeAlert::list( $filters );
		if ( is_wp_error( $data ) ) { wp_die( esc_html( $data->get_error_message() ), '', array( 'response' => (int) ( $data->get_error_data()['status'] ?? 500 ) ) ); }
		echo '<div class="wrap" dir="rtl"><h1>سجل تنبيهات الإدارة</h1><p>سجل مستقل لمحاولات إرسال تنبيهات فشل المهام والترحيل والتسوية. لا يعرض عناوين البريد أو بيانات العملاء.</p>';
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="adc-administrative-alerts"><label>الحالة <select name="status"><option value="">الكل</option><option value="sent"' . selected( $filters['status'], 'sent', false ) . '>تم الإرسال</option><option value="failed"' . selected( $filters['status'], 'failed', false ) . '>فشل</option></select></label> <label>النوع <input name="event" value="' . esc_attr( $filters['event'] ) . '" maxlength="80"></label> <label>بحث <input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" maxlength="120"></label> <button class="button">تصفية</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>الوقت</th><th>النوع</th><th>الحالة</th><th>المحاولات</th><th>المستلمون</th><th>آخر محاولة</th><th>الإجراء</th></tr></thead><tbody>';
		if ( ! $data['items'] ) { echo '<tr><td colspan="7">لا توجد تنبيهات مطابقة.</td></tr>'; }
		foreach ( $data['items'] as $row ) {
			$key = (string) $row['key']; $status = (string) $row['status'];
			echo '<tr><td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $row['event'] ?? '' ) ) . '</code><br><small>' . esc_html( $key ) . '</small></td><td>' . esc_html( 'sent' === $status ? 'تم الإرسال' : ( 'pending' === $status ? 'قيد الإرسال' : 'فشل' ) ) . ( ! empty( $row['last_error'] ) ? '<br><code>' . esc_html( (string) $row['last_error'] ) . '</code>' : '' ) . '</td><td>' . absint( $row['attempts'] ?? 0 ) . '</td><td>' . absint( $row['recipient_count'] ?? $row['sent'] ?? 0 ) . '</td><td>' . esc_html( (string) ( $row['last_attempt_at'] ?? '' ) ) . '</td><td>';
			if ( 'sent' !== $status || ! empty( $row['last_error'] ) ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_administrative_alert_retry"><input type="hidden" name="alert_key" value="' . esc_attr( $key ) . '">' . wp_nonce_field( 'adc_administrative_alert_retry_' . $key, '_wpnonce', true, false ) . '<button class="button">إعادة المحاولة</button></form>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( (int) $data['pages'] > 1 ) {
			$base = remove_query_arg( 'paged' ); echo '<p class="tablenav bottom">';
			if ( (int) $data['page'] > 1 ) { echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', (int) $data['page'] - 1, $base ) ) . '">السابق</a> '; }
			echo '<span style="padding:0 10px">صفحة ' . absint( $data['page'] ) . ' من ' . absint( $data['pages'] ) . ' (' . absint( $data['total'] ) . ')</span>';
			if ( (int) $data['page'] < (int) $data['pages'] ) { echo ' <a class="button" href="' . esc_url( add_query_arg( 'paged', (int) $data['page'] + 1, $base ) ) . '">التالي</a>'; }
			echo '</p>';
		}
		echo '</div>';
	}

	public static function retry(): void {
		$key = sanitize_text_field( wp_unslash( $_POST['alert_key'] ?? '' ) ); check_admin_referer( 'adc_administrative_alert_retry_' . $key );
		$result = AdministrativeAlert::retry( $key ); if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => (int) ( $result->get_error_data()['status'] ?? 400 ) ) ); }
		wp_safe_redirect( admin_url( 'admin.php?page=adc-administrative-alerts&retried=1' ) ); exit;
	}
}
