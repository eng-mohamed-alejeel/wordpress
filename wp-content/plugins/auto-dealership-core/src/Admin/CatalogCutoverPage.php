<?php
namespace AutoDealership\Admin;

use AutoDealership\Inventory\CatalogMappingService;

defined( 'ABSPATH' ) || exit;

/** Reviewable setup and one-to-one vehicle/post mapping workspace for catalog cutover. */
final class CatalogCutoverPage {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_adc_save_catalog_mapping', array( self::class, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'adc-settings', __( 'Catalog cutover', 'auto-dealership-core' ), __( 'Catalog cutover', 'auto-dealership-core' ), 'manage_options', 'adc-catalog-cutover', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Administrator access is required.', 'auto-dealership-core' ), '', array( 'response'=>403 ) ); }
		$report = CatalogMappingService::report();
		$readiness = $report['readiness'];
		$vehicles = ! empty( $readiness['schema_ready'] ) ? CatalogMappingService::vehicles() : array();
		$posts = CatalogMappingService::posts();
		$error = isset( $_GET['error'] ) && is_scalar( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'مطابقة وتفعيل الكتالوج', 'auto-dealership-core' ); ?></h1>
			<p><?php esc_html_e( 'اربط كل سيارة تشغيلية بمنشور سيارة واحد. لا يغيّر الربط حالة السيارة أو ينشر المنشور، وكل تغيير يتطلب سببًا ويُسجّل في سجل التدقيق.', 'auto-dealership-core' ); ?></p>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'تم حفظ ربط الكتالوج وتوثيقه.', 'auto-dealership-core' ); ?></p></div><?php endif; ?>
			<?php if ( '' !== $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
			<h2><?php esc_html_e( 'بوابة الجاهزية', 'auto-dealership-core' ); ?></h2>
			<table class="widefat striped"><tbody>
				<?php self::metric( 'بنية قاعدة البيانات', ! empty( $readiness['schema_ready'] ), $readiness['schema_ready'] ? 'جاهزة' : 'غير جاهزة' ); ?>
				<?php self::metric( 'الفروع النشطة', ! empty( $report['setup']['active_branches'] ), (string) ( $report['setup']['active_branches'] ?? 0 ) ); ?>
				<?php self::metric( 'الماركات النشطة', ! empty( $report['setup']['active_brands'] ), (string) ( $report['setup']['active_brands'] ?? 0 ) ); ?>
				<?php self::metric( 'المواقع النشطة', ! empty( $report['setup']['active_locations'] ), (string) ( $report['setup']['active_locations'] ?? 0 ) ); ?>
				<?php self::metric( 'السيارات التشغيلية', ! empty( $readiness['operational'] ), (string) $readiness['operational'] ); ?>
				<?php self::metric( 'منشورات السيارات المنشورة', ! empty( $readiness['published_posts'] ), (string) $readiness['published_posts'] ); ?>
				<?php self::metric( 'المنشورات غير المرتبطة', 0 === (int) $readiness['unmapped_published_posts'], (string) $readiness['unmapped_published_posts'] ); ?>
				<?php self::metric( 'الروابط المكررة', 0 === (int) $readiness['duplicate_mappings'], (string) $readiness['duplicate_mappings'] ); ?>
				<?php self::metric( 'الروابط غير الصالحة', 0 === (int) $readiness['invalid_mappings'], (string) $readiness['invalid_mappings'] ); ?>
				<?php self::metric( 'جاهزية التحويل', ! empty( $readiness['ready'] ), $readiness['ready'] ? 'جاهز للمراجعة النهائية' : 'محظور' ); ?>
			</tbody></table>
			<p><code><?php echo esc_html( $report['fingerprint'] ?: 'schema-unavailable' ); ?></code></p>
			<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adc-settings' ) ); ?>"><?php esc_html_e( 'إعدادات التفعيل', 'auto-dealership-core' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adc-reference' ) ); ?>"><?php esc_html_e( 'الفروع والماركات والمواقع', 'auto-dealership-core' ); ?></a> <a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=car' ) ); ?>"><?php esc_html_e( 'إنشاء منشور سيارة', 'auto-dealership-core' ); ?></a></p>

			<h2><?php esc_html_e( 'ربط السيارات التشغيلية', 'auto-dealership-core' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'السيارة', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'الحالة والفرع', 'auto-dealership-core' ); ?></th><th><?php esc_html_e( 'منشور الكتالوج', 'auto-dealership-core' ); ?></th></tr></thead><tbody>
			<?php foreach ( $vehicles as $vehicle ) : $vehicle_id = (int) $vehicle['id']; ?>
				<tr><td><strong><?php echo esc_html( $vehicle['stock_number'] ); ?></strong><br><?php echo esc_html( $vehicle['brand'] . ' ' . $vehicle['model'] . ' ' . $vehicle['model_year'] ); ?></td><td><?php echo esc_html( $vehicle['status'] . ' — ' . $vehicle['branch_name'] ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adc_save_catalog_mapping"><input type="hidden" name="vehicle_id" value="<?php echo absint( $vehicle_id ); ?>"><?php wp_nonce_field( 'adc_save_catalog_mapping_' . $vehicle_id ); ?><label class="screen-reader-text" for="adc-catalog-post-<?php echo absint( $vehicle_id ); ?>"><?php esc_html_e( 'منشور الكتالوج', 'auto-dealership-core' ); ?></label><select id="adc-catalog-post-<?php echo absint( $vehicle_id ); ?>" name="post_id"><option value="0"><?php esc_html_e( 'بدون ربط', 'auto-dealership-core' ); ?></option><?php foreach ( $posts as $post ) : ?><option value="<?php echo absint( $post['ID'] ); ?>" <?php selected( (int) $vehicle['public_post_id'], (int) $post['ID'] ); ?>><?php echo esc_html( '#' . $post['ID'] . ' — ' . $post['post_title'] . ' (' . $post['post_status'] . ')' ); ?></option><?php endforeach; ?></select> <label class="screen-reader-text" for="adc-catalog-reason-<?php echo absint( $vehicle_id ); ?>"><?php esc_html_e( 'سبب الربط أو تغييره', 'auto-dealership-core' ); ?></label><input id="adc-catalog-reason-<?php echo absint( $vehicle_id ); ?>" name="reason" maxlength="500" required placeholder="<?php esc_attr_e( 'سبب الربط أو تغييره', 'auto-dealership-core' ); ?>"> <button type="submit" class="button"><?php esc_html_e( 'حفظ الربط', 'auto-dealership-core' ); ?></button></form></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $vehicles ) : ?><tr><td colspan="3"><?php esc_html_e( 'لا توجد سيارات تشغيلية بعد. أنشئ البيانات المرجعية ثم أضف السيارات من مساحة العمل.', 'auto-dealership-core' ); ?></td></tr><?php endif; ?>
			</tbody></table>
			<?php self::issues( 'منشورات منشورة غير مرتبطة', $report['unmapped_posts'], array( 'post_id','post_title','post_status' ), $report['issue_totals']['unmapped_posts'] ); ?>
			<?php self::issues( 'سيارات تشغيلية غير مرتبطة', $report['unmapped_vehicles'], array( 'vehicle_id','stock_number','brand','model','status','branch_name' ), $report['issue_totals']['unmapped_vehicles'] ); ?>
			<?php self::issues( 'روابط مكررة', $report['duplicate_mappings'], array( 'post_id','post_title','mapping_count','vehicle_ids','stock_numbers' ), $report['issue_totals']['duplicate_mappings'] ); ?>
			<?php self::issues( 'روابط إلى منشورات غير منشورة أو غير صالحة', $report['invalid_mappings'], array( 'vehicle_id','stock_number','post_id','post_title','post_type','post_status' ), $report['issue_totals']['invalid_mappings'] ); ?>
		</div>
		<?php
	}

	private static function metric( string $label, bool $pass, string $value ): void { ?><tr><th><?php echo esc_html( $label ); ?></th><td><strong style="color:<?php echo esc_attr( $pass ? '#16794b' : '#b32d2e' ); ?>"><?php echo esc_html( $pass ? '✓' : '✕' ); ?></strong> <?php echo esc_html( $value ); ?></td></tr><?php }

	private static function issues( string $title, array $rows, array $columns, int $total ): void {
		echo '<h2>' . esc_html( $title ) . ' <span class="count">(' . absint( $total ) . ')</span></h2>';
		if ( ! $rows ) { echo '<p>' . esc_html__( 'لا توجد عناصر ضمن هذه الفئة.', 'auto-dealership-core' ) . '</p>'; return; }
		if ( $total > count( $rows ) ) { echo '<p>' . esc_html( sprintf( __( 'يعرض التقرير أول %1$d من أصل %2$d عنصرًا.', 'auto-dealership-core' ), count( $rows ), $total ) ) . '</p>'; }
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $columns as $column ) { echo '<th>' . esc_html( $column ) . '</th>'; }
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) { echo '<tr>'; foreach ( $columns as $column ) { echo '<td>' . esc_html( (string) ( $row[ $column ] ?? '' ) ) . '</td>'; } echo '</tr>'; }
		echo '</tbody></table>';
	}

	public static function save(): void {
		$vehicle_id = isset( $_POST['vehicle_id'] ) && is_scalar( $_POST['vehicle_id'] ) ? absint( $_POST['vehicle_id'] ) : 0;
		$post_id = isset( $_POST['post_id'] ) && is_scalar( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$reason = isset( $_POST['reason'] ) && is_scalar( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		check_admin_referer( 'adc_save_catalog_mapping_' . $vehicle_id );
		$result = CatalogMappingService::assign( $vehicle_id, $post_id, $reason );
		$args = is_wp_error( $result ) ? array( 'error'=>$result->get_error_message() ) : array( 'saved'=>'1' );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=adc-catalog-cutover' ) ) );
		exit;
	}
}
