<?php
namespace AutoDealership\Admin;

use AutoDealership\Audit\AuditLog;

defined( 'ABSPATH' ) || exit;

/** Read-only audit interface. */
final class AuditPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	public static function menu(): void {
		add_menu_page( __( 'Audit Log', 'auto-dealership-core' ), __( 'Audit Log', 'auto-dealership-core' ), 'adc_view_audit', 'adc-audit', array( self::class, 'render' ), 'dashicons-visibility', 59 );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_audit' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض سجل التدقيق.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		global $wpdb;
		$page        = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$limit       = 50;
		$table       = AuditLog::table_name();
		$users_table = $wpdb->users;
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$rows        = $wpdb->get_results( $wpdb->prepare( "SELECT a.id,a.actor_user_id,u.display_name,a.event_key,a.subject_type,a.subject_id,a.reason,a.before_data,a.after_data,a.correlation_id,a.created_at FROM $table a LEFT JOIN {$users_table} u ON u.ID=a.actor_user_id ORDER BY a.id DESC LIMIT %d OFFSET %d", $limit, ( $page - 1 ) * $limit ), ARRAY_A );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'سجل التدقيق', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'عرض للقراءة فقط. لا تتضمن هذه الصفحة أدوات تعديل أو حذف للسجل.', 'auto-dealership-core' ); ?></p>
			<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الوقت (UTC)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المستخدم', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحدث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'السجل', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'السبب', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'التغييرات', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'معرّف الارتباط', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php if ( $rows ) : foreach ( $rows as $row ) : ?>
				<tr><td><?php echo absint( $row['id'] ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td><td><?php echo esc_html( $row['display_name'] ?: __( 'System', 'auto-dealership-core' ) ); ?></td><td><code><?php echo esc_html( $row['event_key'] ); ?></code></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $row['subject_type'] ) . ' #' . $row['subject_id'] ); ?></td><td><?php echo esc_html( $row['reason'] ); ?></td><td><details><summary><?php esc_html_e( 'عرض', 'auto-dealership-core' ); ?></summary><pre><?php echo esc_html( wp_json_encode( array( 'before' => json_decode( (string) $row['before_data'], true ), 'after' => json_decode( (string) $row['after_data'], true ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre></details></td><td><code><?php echo esc_html( $row['correlation_id'] ); ?></code></td></tr>
			<?php endforeach; else : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد أحداث مسجلة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => max( 1, (int) ceil( $total / $limit ) ) ) ) ); ?>
		</div>
		<?php
	}
}
