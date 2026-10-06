<?php
namespace AutoDealership\Admin;

use AutoDealership\Integrations\AcknowledgementService;
use AutoDealership\Integrations\IntegrationActivation;
use AutoDealership\Integrations\IntegrationRegistry;

defined( 'ABSPATH' ) || exit;

/** Restricted readiness, activation and acknowledgement workspace. */
final class IntegrationPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_set_integration_route', array( self::class, 'set_route' ) );
		add_action( 'admin_post_adc_reconcile_integration', array( self::class, 'reconcile' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'adc-audit', __( 'جاهزية التكاملات', 'auto-dealership-core' ), __( 'جاهزية التكاملات', 'auto-dealership-core' ), 'adc_view_integrations', 'adc-integrations', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_integrations' ) ) { wp_die( esc_html__( 'لا تملك صلاحية عرض التكاملات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$counts = AcknowledgementService::counts();
		$records = AcknowledgementService::records( $status, $page, 50 );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<h1><?php esc_html_e( 'جاهزية التكاملات ومطابقة المزودين', 'auto-dealership-core' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'لا تعرض هذه الصفحة بيانات الاعتماد أو محتوى الرسائل أو مرجع المزود الكامل. تبقى جميع المسارات معطلة حتى ينجح فحص الجاهزية ويتم تفعيلها بإجراء مدقق.', 'auto-dealership-core' ); ?></p>
			<?php self::render_health(); ?>
			<?php self::render_routes(); ?>
			<?php if ( is_wp_error( $counts ) || is_wp_error( $records ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'مخطط سجل المطابقة غير جاهز.', 'auto-dealership-core' ); ?></p></div>
			<?php else : ?>
				<?php self::render_counts( $counts, $status ); ?>
				<?php self::render_records( $records ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function set_route(): void {
		if ( ! current_user_can( 'adc_manage_integrations' ) ) { wp_die( esc_html__( 'لا تملك صلاحية إدارة التكاملات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$event_key = sanitize_key( wp_unslash( $_POST['event_key'] ?? '' ) );
		check_admin_referer( 'adc_set_integration_route_' . $event_key );
		$enabled = '1' === (string) ( $_POST['enabled'] ?? '0' );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$result = IntegrationActivation::set_enabled( $event_key, $enabled, $reason );
		self::redirect( is_wp_error( $result ) ? $result->get_error_code() : 'route_saved' );
	}

	public static function reconcile(): void {
		if ( ! current_user_can( 'adc_manage_integrations' ) ) { wp_die( esc_html__( 'لا تملك صلاحية إدارة التكاملات.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$id = absint( $_POST['receipt_id'] ?? 0 );
		check_admin_referer( 'adc_reconcile_integration_' . $id );
		$result = AcknowledgementService::request_reconciliation( $id, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		self::redirect( is_wp_error( $result ) ? $result->get_error_code() : 'reconcile_queued' );
	}

	private static function render_health(): void {
		$next = wp_next_scheduled( 'adc_process_outbox' );
		$health = get_option( 'adc_outbox_health', array() );
		$last = is_array( $health ) ? (string) ( $health['finished_at'] ?? '' ) : '';
		$last_time = $last ? strtotime( $last . ' UTC' ) : false;
		$worker = ! $next ? 'not_scheduled' : ( ! $last_time || $last_time < time() - 15 * MINUTE_IN_SECONDS ? 'stale' : 'healthy' );
		?>
		<h2><?php esc_html_e( 'صحة التشغيل', 'auto-dealership-core' ); ?></h2>
		<table class="widefat striped"><tbody>
			<tr><th><?php esc_html_e( 'عامل الطابور', 'auto-dealership-core' ); ?></th><td><code><?php echo esc_html( $worker ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'التشغيل التالي UTC', 'auto-dealership-core' ); ?></th><td><?php echo esc_html( $next ? gmdate( 'Y-m-d H:i:s', $next ) : '—' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'آخر تشغيل UTC', 'auto-dealership-core' ); ?></th><td><?php echo esc_html( $last ?: '—' ); ?></td></tr>
		</tbody></table>
		<?php
	}

	private static function render_routes(): void {
		?>
		<h2><?php esc_html_e( 'مسارات الأحداث', 'auto-dealership-core' ); ?></h2>
		<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'الحدث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المحول', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'البيئة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الجاهزية', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'التفعيل', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'إجراء', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php foreach ( IntegrationRegistry::catalogue() as $route ) : ?>
			<tr>
				<td><code><?php echo esc_html( $route['event_key'] ); ?></code></td>
				<td><code><?php echo esc_html( $route['adapter_id'] ?: '—' ); ?></code></td>
				<td><?php echo esc_html( $route['environment'] ?: '—' ); ?></td>
				<td><?php echo esc_html( \AutoDealership\Core\Localization::label( $route['ready'] ? 'ready' : 'blocked' ) ); ?><?php if ( $route['missing'] ) : ?><br><small><code><?php echo esc_html( implode( ', ', $route['missing'] ) ); ?></code></small><?php endif; ?></td>
				<td><strong><?php echo esc_html( \AutoDealership\Core\Localization::label( $route['enabled'] ? 'enabled' : 'disabled' ) ); ?></strong></td>
				<td><?php if ( current_user_can( 'adc_manage_integrations' ) && ( $route['enabled'] || $route['ready'] ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="adc_set_integration_route"><input type="hidden" name="event_key" value="<?php echo esc_attr( $route['event_key'] ); ?>"><input type="hidden" name="enabled" value="<?php echo $route['enabled'] ? '0' : '1'; ?>">
						<?php wp_nonce_field( 'adc_set_integration_route_' . $route['event_key'] ); ?>
						<textarea name="reason" required minlength="5" maxlength="500" rows="2" placeholder="<?php esc_attr_e( 'سبب التفعيل أو التعطيل', 'auto-dealership-core' ); ?>"></textarea><br>
						<button class="button <?php echo $route['enabled'] ? '' : 'button-primary'; ?>"><?php echo esc_html( $route['enabled'] ? __( 'تعطيل', 'auto-dealership-core' ) : __( 'تفعيل', 'auto-dealership-core' ) ); ?></button>
					</form>
				<?php else : ?>—<?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	private static function render_counts( array $counts, string $selected ): void {
		echo '<h2>' . esc_html__( 'إقرارات المزود', 'auto-dealership-core' ) . '</h2><ul class="subsubsub">';
		$total = array_sum( array_intersect_key( $counts, array_fill_keys( AcknowledgementService::STATUSES, true ) ) );
		$links = array( ''=>__( 'الكل', 'auto-dealership-core' ) ) + array_combine( AcknowledgementService::STATUSES, AcknowledgementService::STATUSES );
		foreach ( $links as $status=>$label ) {
			$count = '' === $status ? $total : $counts[ $status ];
			$url = add_query_arg( array_filter( array( 'page'=>'adc-integrations', 'status'=>$status ) ), admin_url( 'admin.php' ) );
			printf( '<li><a class="%1$s" href="%2$s">%3$s <span class="count">(%4$d)</span></a> | </li>', $selected === $status ? 'current' : '', esc_url( $url ), esc_html__( $label, 'auto-dealership-core' ), absint( $count ) );
		}
		echo '</ul><div class="clear"></div><p>' . esc_html__( 'إقرارات غير مرتبطة:', 'auto-dealership-core' ) . ' <strong>' . absint( $counts['unmatched'] ) . '</strong></p>';
	}

	private static function render_records( array $records ): void {
		?>
		<table class="widefat striped"><thead><tr><th><?php echo esc_html__( 'ID', 'auto-dealership-core' ); ?></th><th><?php echo esc_html__( 'Outbox', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المحول / الحدث', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'بصمة المرجع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'آخر فحص UTC', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'آخر رمز', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'مطابقة', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
		<?php if ( $records['rows'] ) : foreach ( $records['rows'] as $row ) : $readiness = IntegrationRegistry::readiness( $row['event_key'] ); ?>
			<tr><td><?php echo absint( $row['id'] ); ?></td><td><?php echo $row['outbox_id'] ? absint( $row['outbox_id'] ) : '—'; ?></td><td><code><?php echo esc_html( $row['adapter_id'] ); ?></code><br><code><?php echo esc_html( $row['event_key'] ); ?></code></td><td><code><?php echo esc_html( $row['reference_hint'] ); ?></code></td><td><?php echo esc_html( \AutoDealership\Core\Localization::label( (string) $row['status'] ) ); ?></td><td><?php echo esc_html( $row['last_checked_at'] ?: '—' ); ?></td><td><code><?php echo esc_html( $row['last_error'] ?: '—' ); ?></code></td><td>
			<?php if ( current_user_can( 'adc_manage_integrations' ) && $row['outbox_id'] && in_array( $row['status'], array( 'pending','mismatch' ), true ) && $readiness['reconciliation'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_reconcile_integration"><input type="hidden" name="receipt_id" value="<?php echo absint( $row['id'] ); ?>"><?php wp_nonce_field( 'adc_reconcile_integration_' . absint( $row['id'] ) ); ?><textarea name="reason" required minlength="5" maxlength="500" rows="2" placeholder="<?php esc_attr_e( 'سبب طلب المطابقة', 'auto-dealership-core' ); ?>"></textarea><br><button class="button"><?php esc_html_e( 'طلب مطابقة', 'auto-dealership-core' ); ?></button></form>
			<?php else : ?>—<?php endif; ?></td></tr>
		<?php endforeach; else : ?><tr><td colspan="8"><?php esc_html_e( 'لا توجد إقرارات مطابقة.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
		</tbody></table>
		<?php
		$total_pages = max( 1, (int) ceil( $records['total'] / $records['limit'] ) );
		echo wp_kses_post( paginate_links( array( 'base'=>add_query_arg( 'paged', '%#%' ), 'format'=>'', 'current'=>$records['page'], 'total'=>$total_pages ) ) );
	}

	private static function notice(): void {
		$code = isset( $_GET['adc_notice'] ) ? sanitize_key( wp_unslash( $_GET['adc_notice'] ) ) : '';
		if ( ! $code ) { return; }
		$success = in_array( $code, array( 'route_saved','reconcile_queued' ), true );
		echo '<div class="notice ' . ( $success ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $success ? __( 'تم حفظ الإجراء وتدقيقه.', 'auto-dealership-core' ) : __( 'تعذر تنفيذ الإجراء.', 'auto-dealership-core' ) ) . ' <code>' . esc_html( $code ) . '</code></p></div>';
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page'=>'adc-integrations', 'adc_notice'=>$notice ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
