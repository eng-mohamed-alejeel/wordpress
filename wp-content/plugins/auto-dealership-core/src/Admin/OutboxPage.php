<?php
namespace AutoDealership\Admin;

use AutoDealership\Operations\OutboxService;

defined( 'ABSPATH' ) || exit;

/** Restricted operational monitor for the local outbox and scheduled jobs. */
final class OutboxPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_retry_outbox', array( self::class, 'retry' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'adc-audit',
			__( 'مراقبة المهام', 'auto-dealership-core' ),
			__( 'مراقبة المهام', 'auto-dealership-core' ),
			'adc_view_outbox',
			'adc-outbox',
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_outbox' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية عرض مراقبة المهام.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status = in_array( $status, OutboxService::STATUSES, true ) ? $status : '';
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$counts = OutboxService::counts();
		$events = OutboxService::events( $status, $page, 50 );
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'مراقبة المهام والطابور', 'auto-dealership-core' ); ?></h1>
			<?php self::notice(); ?>
			<?php if ( is_wp_error( $counts ) || is_wp_error( $events ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'المخطط التشغيلي غير جاهز. أكمل ترقية قاعدة البيانات ثم أعد فتح الصفحة.', 'auto-dealership-core' ); ?></p></div>
			<?php else : ?>
				<p><?php esc_html_e( 'تعرض هذه الصفحة البيانات التشغيلية الآمنة فقط. لا يتم عرض محتوى الرسائل أو مفاتيح منع التكرار.', 'auto-dealership-core' ); ?></p>
				<?php self::render_counts( $counts, $status ); ?>
				<?php self::render_jobs(); ?>
				<?php self::render_events( $events ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function retry(): void {
		if ( ! current_user_can( 'adc_manage_outbox' ) ) {
			wp_die( esc_html__( 'لا تملك صلاحية إعادة محاولة المهمة.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		$id = absint( $_POST['event_id'] ?? 0 );
		check_admin_referer( 'adc_retry_outbox_' . $id );
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = OutboxService::retry_failed( $id, $reason );
		$notice = is_wp_error( $result ) ? $result->get_error_code() : 'retried';
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-outbox', 'adc_notice'=>$notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function render_counts( array $counts, string $selected ): void {
		echo '<h2>' . esc_html__( 'حالة الطابور', 'auto-dealership-core' ) . '</h2><ul class="subsubsub">';
		$total = array_sum( $counts );
		$links = array( '' => __( 'الكل', 'auto-dealership-core' ) ) + array_combine( OutboxService::STATUSES, OutboxService::STATUSES );
		foreach ( $links as $status => $label ) {
			$count = '' === $status ? $total : $counts[ $status ];
			$url = add_query_arg( array_filter( array( 'page'=>'adc-outbox', 'status'=>$status ) ), admin_url( 'admin.php' ) );
			printf( '<li><a class="%1$s" href="%2$s">%3$s <span class="count">(%4$d)</span></a> | </li>', $selected === $status ? 'current' : '', esc_url( $url ), esc_html( $label ), absint( $count ) );
		}
		echo '</ul><div class="clear"></div>';
	}

	private static function render_jobs(): void {
		$jobs = array(
			'adc_process_outbox'       => __( 'معالجة طابور الأحداث', 'auto-dealership-core' ),
			'adc_expire_reservations'  => __( 'إنهاء الحجوزات المنتهية', 'auto-dealership-core' ),
			'adc_privacy_retention'     => __( 'سياسة الاحتفاظ والخصوصية', 'auto-dealership-core' ),
		);
		$health = get_option( 'adc_outbox_health', array() );
		$health = is_array( $health ) ? $health : array();
		?>
		<h2><?php esc_html_e( 'المهام المجدولة', 'auto-dealership-core' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'المهمة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'التشغيل التالي (UTC)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'آخر تشغيل معروف (UTC)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'النتيجة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( $jobs as $hook => $label ) :
			$next = wp_next_scheduled( $hook );
			$is_outbox = 'adc_process_outbox' === $hook;
			$last = $is_outbox && is_array( $health ) ? (string) ( $health['finished_at'] ?? '' ) : '';
			$summary = $is_outbox && is_array( $health['summary'] ?? null ) ? wp_json_encode( $health['summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
		?>
			<tr><td><strong><?php echo esc_html( $label ); ?></strong><br><code><?php echo esc_html( $hook ); ?></code></td><td><?php echo esc_html( $next ? gmdate( 'Y-m-d H:i:s', $next ) : __( 'غير مجدولة', 'auto-dealership-core' ) ); ?></td><td><?php echo esc_html( $last ?: '—' ); ?></td><td><code><?php echo esc_html( $summary ?: '—' ); ?></code></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	private static function render_events( array $events ): void {
		?>
		<h2><?php esc_html_e( 'الأحداث', 'auto-dealership-core' ); ?></h2>
		<table class="widefat striped"><thead><tr><th>ID</th><th><?php esc_html_e( 'الحدث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المحاولات', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الموعد التالي (UTC)', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'آخر رمز خطأ', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'التوقيتات', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'إجراء', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php if ( $events['rows'] ) : foreach ( $events['rows'] as $row ) : ?>
			<tr>
				<td><?php echo absint( $row['id'] ); ?></td><td><code><?php echo esc_html( $row['event_key'] ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo absint( $row['attempts'] ); ?></td><td><?php echo esc_html( $row['next_attempt_at'] ); ?></td><td><code><?php echo esc_html( $row['last_error'] ?: '—' ); ?></code></td>
				<td><small><?php echo esc_html( 'created: ' . $row['created_at'] ); ?><br><?php echo esc_html( 'locked: ' . ( $row['locked_at'] ?: '—' ) ); ?><br><?php echo esc_html( 'completed: ' . ( $row['completed_at'] ?: '—' ) ); ?><br><?php echo esc_html( 'failed: ' . ( $row['failed_at'] ?: '—' ) ); ?></small></td>
				<td><?php if ( 'failed' === $row['status'] && current_user_can( 'adc_manage_outbox' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_retry_outbox"><input type="hidden" name="event_id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_retry_outbox_' . absint( $row['id'] ) ); ?><textarea name="reason" required maxlength="500" rows="2" placeholder="<?php esc_attr_e( 'سبب إعادة المحاولة', 'auto-dealership-core' ); ?>"></textarea><br><button class="button button-secondary"><?php esc_html_e( 'إعادة المحاولة', 'auto-dealership-core' ); ?></button></form><?php else : ?>—<?php endif; ?></td>
			</tr>
		<?php endforeach; else : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد أحداث مطابقة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php
		$total_pages = max( 1, (int) ceil( $events['total'] / $events['limit'] ) );
		echo wp_kses_post( paginate_links( array( 'base'=>add_query_arg( 'paged', '%#%' ), 'format'=>'', 'current'=>$events['page'], 'total'=>$total_pages ) ) );
	}

	private static function notice(): void {
		$code = isset( $_GET['adc_notice'] ) ? sanitize_key( wp_unslash( $_GET['adc_notice'] ) ) : '';
		if ( ! $code ) { return; }
		if ( 'retried' === $code ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'أعيدت المهمة إلى الطابور وسُجل الإجراء في سجل التدقيق.', 'auto-dealership-core' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'تعذر إعادة المهمة إلى الطابور.', 'auto-dealership-core' ) . ' <code>' . esc_html( $code ) . '</code></p></div>';
		}
	}
}
