<?php
namespace AutoDealership\Admin;

use AutoDealership\Content\EditorialPageSetup;

defined( 'ABSPATH' ) || exit;

/** Administrator-only, explicit setup screen for missing editorial pages. */
final class EditorialSetupPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_create_editorial_pages', array( self::class, 'create' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'adc-settings', __( 'إعداد صفحات الموقع', 'auto-dealership-core' ), __( 'إعداد صفحات الموقع', 'auto-dealership-core' ), 'manage_options', 'adc-editorial-setup', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}

		$rows = EditorialPageSetup::report();
		$error = isset( $_GET['error'] ) && is_scalar( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		$created = isset( $_GET['created'] ) && is_scalar( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
		$preserved = isset( $_GET['preserved'] ) && is_scalar( $_GET['preserved'] ) ? absint( $_GET['preserved'] ) : 0;
		$missing = array_values( array_filter( $rows, static fn( array $row ): bool => ! $row['exists'] ) );
		?>
		<div class="wrap" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<h1><?php esc_html_e( 'إعداد صفحات الموقع', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'تنشئ هذه الأداة الصفحات المحددة والمفقودة كمسودات للمراجعة. لا تعدّل عنوانًا أو محتوى أو قالبًا لأي صفحة موجودة.', 'auto-dealership-core' ); ?></p>
			<?php if ( '' !== $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
			<?php if ( $created > 0 ) : ?><div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'تم إنشاء %d صفحة كمسودة وتوثيق العملية.', 'auto-dealership-core' ), $created ) ); ?></p></div><?php endif; ?>
			<?php if ( $preserved > 0 ) : ?><div class="notice notice-info"><p><?php echo esc_html( sprintf( __( 'تم الإبقاء على %d صفحة موجودة دون أي تعديل.', 'auto-dealership-core' ), $preserved ) ); ?></p></div><?php endif; ?>

			<?php if ( ! $rows ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'القالب النشط لا يقدّم تعريفات صفحات لهذه الأداة.', 'auto-dealership-core' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'الصفحة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'المسار', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'القالب', 'auto-dealership-core' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['exists'] && '' !== $row['current_title'] ? $row['current_title'] : $row['title'] ); ?></strong><?php if ( $row['exists'] && get_edit_post_link( $row['post_id'], 'raw' ) ) : ?> <a href="<?php echo esc_url( get_edit_post_link( $row['post_id'], 'raw' ) ); ?>"><?php esc_html_e( 'تحرير', 'auto-dealership-core' ); ?></a><?php endif; ?></td>
							<td><code>/<?php echo esc_html( $row['slug'] ); ?>/</code></td>
							<td><?php echo esc_html( $row['exists'] ? sprintf( __( 'موجودة (%s)', 'auto-dealership-core' ), $row['post_status'] ) : __( 'مفقودة', 'auto-dealership-core' ) ); ?></td>
							<td><?php echo esc_html( '' !== $row['template'] ? $row['template'] : __( 'القالب الافتراضي', 'auto-dealership-core' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $missing ) : ?>
				<h2><?php esc_html_e( 'إنشاء المسودات المفقودة', 'auto-dealership-core' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="adc_create_editorial_pages">
					<?php wp_nonce_field( 'adc_create_editorial_pages' ); ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'الصفحات المطلوب إنشاؤها', 'auto-dealership-core' ); ?></legend>
						<?php foreach ( $missing as $row ) : ?>
							<label class="adc-check"><input type="checkbox" name="pages[]" value="<?php echo esc_attr( $row['slug'] ); ?>" checked> <?php echo esc_html( $row['title'] . ' — /' . $row['slug'] . '/' ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<p><label for="adc-editorial-reason"><strong><?php esc_html_e( 'سبب الإعداد', 'auto-dealership-core' ); ?></strong></label><br><textarea id="adc-editorial-reason" name="reason" rows="3" class="large-text" minlength="5" maxlength="500" required></textarea></p>
					<p><?php submit_button( __( 'إنشاء المسودات المحددة', 'auto-dealership-core' ), 'primary', 'submit', false ); ?></p>
				</form>
			<?php elseif ( $rows ) : ?>
				<p><strong><?php esc_html_e( 'كل الصفحات المعرفة موجودة، ولم تُجرَ عليها أي تعديلات.', 'auto-dealership-core' ); ?></strong></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function create(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'adc_create_editorial_pages' );

		$pages = isset( $_POST['pages'] ) && is_array( $_POST['pages'] ) ? wp_unslash( $_POST['pages'] ) : array();
		$pages = array_values( array_filter( $pages, 'is_scalar' ) );
		$reason = isset( $_POST['reason'] ) && is_scalar( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		$result = EditorialPageSetup::create_missing( array_map( 'strval', $pages ), $reason );
		$args = is_wp_error( $result )
			? array( 'error' => $result->get_error_message() )
			: array( 'created' => count( $result['created'] ), 'preserved' => count( $result['preserved'] ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=adc-editorial-setup' ) ) );
		exit;
	}
}
