<?php
namespace AutoDealership\Admin;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Operations\AdminDiagnostics;
use AutoDealership\Operations\JobRuntime;

defined( 'ABSPATH' ) || exit;

/** Restricted, read-only operational health view with an opt-in diagnostic flag. */
final class DiagnosticsPage {
	public static function render(): void {
		if ( ! current_user_can( 'adc_view_audit' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض صحة الإدارة.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$snapshot = AdminDiagnostics::snapshot();
		$runtime  = JobRuntime::status();
		$signals  = self::audit_signals();
		$settings = $snapshot['settings'];
		?>
		<div class="wrap adc-admin" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<h1><?php esc_html_e( 'صحة الإدارة والتدقيق التشغيلي', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'عرض تشخيصي محدود يساعد على تحديد الصفحة والطلب والوقت عند المشكلة. لا تُحفظ الروابط أو الاستعلامات أو الرسائل الخام أو بيانات العملاء.', 'auto-dealership-core' ); ?></p>
			<?php self::notice(); ?>
			<div class="adc-table-scroll" role="region" aria-label="<?php esc_attr_e( 'ملخص صحة الإدارة', 'auto-dealership-core' ); ?>" tabindex="0">
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'المؤشر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'القيمة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المعنى', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
					<?php self::metric( __( 'القياس التشخيصي', 'auto-dealership-core' ), $settings['enabled'] ? __( 'مفعّل اختياريًا', 'auto-dealership-core' ) : __( 'متوقف افتراضيًا', 'auto-dealership-core' ), __( 'لا يبدأ حفظ الأزمنة إلا بعد تفعيله من مسؤول النظام.', 'auto-dealership-core' ) ); ?>
					<?php self::metric( __( 'أخطاء PHP', 'auto-dealership-core' ), (string) absint( $snapshot['php_errors'] ), __( 'أخطاء/تحذيرات مرتبطة بطلبات الإدارة خلال مدة الاحتفاظ.', 'auto-dealership-core' ) ); ?>
					<?php self::metric( __( 'أخطاء قاعدة البيانات', 'auto-dealership-core' ), (string) absint( $snapshot['db_errors'] ), __( 'يُحفظ بصمة أحادية فقط دون SQL أو نص الخطأ.', 'auto-dealership-core' ) ); ?>
					<?php self::metric( __( 'صفحات بطيئة', 'auto-dealership-core' ), (string) absint( $snapshot['slow_pages'] ), sprintf( __( 'الحد الحالي: %d مللي ثانية.', 'auto-dealership-core' ), absint( $settings['slow_page_ms'] ) ) ); ?>
					<?php self::metric( __( 'فشل حفظ/تصدير مرصود', 'auto-dealership-core' ), (string) absint( $snapshot['operation_failures'] ), __( 'إعادة توجيه إدارية تحمل حالة فشل، مع حفظ بصمة الرمز فقط.', 'auto-dealership-core' ) ); ?>
					<?php self::metric( __( 'المهام الفاشلة', 'auto-dealership-core' ), (string) absint( $runtime['failed_jobs'] ?? 0 ), __( 'من حالة العامل المرصودة دون تغيير طريقة تشغيل المهام.', 'auto-dealership-core' ) ); ?>
					<?php self::metric( __( 'إشارات فشل مدققة', 'auto-dealership-core' ), (string) absint( $signals['total'] ), __( 'أحداث رفض/فشل مسجلة أصلًا في سجل التدقيق؛ لا تمثل عمليات غير مدققة.', 'auto-dealership-core' ) ); ?>
				</tbody></table>
			</div>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div class="card">
					<h2><?php esc_html_e( 'إعداد القياس الاختياري', 'auto-dealership-core' ); ?></h2>
					<p><?php printf( esc_html__( 'الاحتفاظ: %d أيام، والحد الأقصى: %d سجل. لا يتم تسجيل بيانات العملاء أو كلمات المرور أو الرموز أو SQL.', 'auto-dealership-core' ), absint( $settings['retention_days'] ), absint( $settings['max_records'] ) ); ?></p>
					<?php if ( $settings['constant_lock'] ) : ?><p class="description"><?php esc_html_e( 'هذا الإعداد مقفول من إعدادات الخادم عبر ADC_ADMIN_DIAGNOSTICS.', 'auto-dealership-core' ); ?></p><?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="adc_save_diagnostics">
						<?php wp_nonce_field( 'adc_save_diagnostics' ); ?>
						<label class="adc-check"><input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> <?php disabled( $settings['constant_lock'] ); ?>> <?php esc_html_e( 'تفعيل قياس أزمنة طلبات الإدارة وحفظ البصمات التشخيصية المنقّحة', 'auto-dealership-core' ); ?></label>
						<p class="submit"><button class="button button-primary" type="submit" <?php disabled( $settings['constant_lock'] ); ?>><?php esc_html_e( 'حفظ إعداد القياس', 'auto-dealership-core' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'آخر القياسات التشخيصية', 'auto-dealership-core' ); ?></h2>
			<?php if ( ! $settings['enabled'] && ! $snapshot['records'] ) : ?><div class="notice notice-info"><p><?php esc_html_e( 'لا توجد قياسات محفوظة. فعّل القياس عند الحاجة ثم أعد تحميل صفحات الإدارة المراد فحصها.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<div class="adc-table-scroll" role="region" aria-label="<?php esc_attr_e( 'آخر القياسات التشخيصية', 'auto-dealership-core' ); ?>" tabindex="0">
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'الوقت (UTC)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الصفحة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المدة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'معرّف الطلب', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'إشارة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
					<?php if ( $snapshot['records'] ) : foreach ( array_slice( $snapshot['records'], 0, 20 ) as $record ) : ?>
						<tr><td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', absint( $record['created_at'] ) ) ); ?></td><td><code><?php echo esc_html( $record['page'] ); ?></code></td><td><?php echo absint( $record['duration_ms'] ); ?> ms</td><td><?php echo absint( $record['status'] ); ?></td><td><code><?php echo esc_html( $record['request_id'] ); ?></code></td><td><?php echo esc_html( self::signal_label( $record ) ); ?></td></tr>
					<?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'لا توجد سجلات ضمن مدة الاحتفاظ.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
				</tbody></table>
			</div>

			<h2><?php esc_html_e( 'حالة التشغيل الحالية', 'auto-dealership-core' ); ?></h2>
			<div class="adc-table-scroll" role="region" aria-label="<?php esc_attr_e( 'حالة التشغيل الحالية', 'auto-dealership-core' ); ?>" tabindex="0">
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'المصدر', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الوقت أو التفاصيل الآمنة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
					<tr><td><?php esc_html_e( 'العامل المجدول', 'auto-dealership-core' ); ?></td><td><code><?php echo esc_html( $runtime['worker_observed'] ? 'ok' : 'attention' ); ?></code></td><td><?php echo esc_html( $runtime['last_worker'] ? gmdate( 'Y-m-d H:i:s', absint( $runtime['last_worker'] ) ) : __( 'لم يُرصد تشغيل بعد', 'auto-dealership-core' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'طابور البريد والأحداث', 'auto-dealership-core' ); ?></td><td><code><?php echo esc_html( ! empty( $runtime['held_mail'] ) || ! empty( $runtime['failed_jobs'] ) ? 'attention' : 'ok' ); ?></code></td><td><?php printf( esc_html__( 'معلّق قديم: %d، مهام فاشلة: %d', 'auto-dealership-core' ), absint( $runtime['held_mail'] ?? 0 ), absint( $runtime['failed_jobs'] ?? 0 ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'آخر طلب تشخيصي', 'auto-dealership-core' ); ?></td><td><code>request_id</code></td><td><code><?php echo esc_html( $snapshot['current_request_id'] ); ?></code></td></tr>
				</tbody></table>
			</div>

			<h2><?php esc_html_e( 'آخر إشارات الفشل المدققة', 'auto-dealership-core' ); ?></h2>
			<p class="description"><?php esc_html_e( 'تُعرض المفاتيح والوقت ومعرّف الارتباط فقط. التفاصيل والبيانات الوصفية محجوبة.', 'auto-dealership-core' ); ?></p>
			<div class="adc-table-scroll" role="region" aria-label="<?php esc_attr_e( 'آخر إشارات الفشل المدققة', 'auto-dealership-core' ); ?>" tabindex="0">
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'الوقت (UTC)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحدث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'معرّف الارتباط', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
					<?php if ( $signals['rows'] ) : foreach ( $signals['rows'] as $row ) : ?><tr><td><?php echo esc_html( $row['created_at'] ); ?></td><td><code><?php echo esc_html( $row['event_key'] ); ?></code></td><td><code><?php echo esc_html( $row['correlation_id'] ); ?></code></td></tr><?php endforeach; else : ?><tr><td colspan="3"><?php esc_html_e( 'لا توجد إشارات فشل مدققة حديثة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
				</tbody></table>
			</div>
		</div>
		<?php
	}

	private static function notice(): void {
		if ( ! isset( $_GET['saved'] ) ) {
			return;
		}
		$saved = sanitize_key( wp_unslash( $_GET['saved'] ) );
		if ( '1' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'تم حفظ إعداد القياس التشخيصي.', 'auto-dealership-core' ) . '</p></div>';
		} elseif ( 'locked' === $saved ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'إعداد القياس مقفول من إعدادات الخادم ولم يتغير.', 'auto-dealership-core' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'تعذر حفظ إعداد القياس التشخيصي.', 'auto-dealership-core' ) . '</p></div>';
		}
	}

	private static function metric( string $label, string $value, string $description ): void {
		echo '<tr><th>' . esc_html( $label ) . '</th><td><strong>' . esc_html( $value ) . '</strong></td><td>' . esc_html( $description ) . '</td></tr>';
	}

	private static function signal_label( array $record ): string {
		$signals = array();
		if ( ! empty( $record['slow'] ) ) { $signals[] = __( 'بطيء', 'auto-dealership-core' ); }
		if ( ! empty( $record['php_error'] ) ) { $signals[] = __( 'PHP', 'auto-dealership-core' ); }
		if ( ! empty( $record['db_error'] ) ) { $signals[] = __( 'قاعدة البيانات', 'auto-dealership-core' ); }
		if ( ! empty( $record['operation_failure'] ) ) { $signals[] = __( 'فشل حفظ/تصدير', 'auto-dealership-core' ); }
		return $signals ? implode( '، ', $signals ) : __( 'طبيعي', 'auto-dealership-core' );
	}

	private static function audit_signals(): array {
		global $wpdb;
		$table = AuditLog::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $exists ) {
			return array( 'total' => 0, 'rows' => array() );
		}
		$where = $wpdb->prepare( '(event_key LIKE %s OR event_key LIKE %s OR event_key LIKE %s)', '%failed%', '%rejected%', '%error%' );
		$rows = $wpdb->get_results( "SELECT event_key,correlation_id,created_at FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 20", ARRAY_A ) ?: array();
		if ( $wpdb->last_error ) {
			return array( 'total' => 0, 'rows' => array() );
		}
		return array( 'total' => count( $rows ), 'rows' => $rows );
	}
}
