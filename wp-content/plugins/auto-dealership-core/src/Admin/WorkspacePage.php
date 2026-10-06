<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Role-aware entry point for every plugin-owned dealership administration area. */
final class WorkspacePage {
	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
	}

	private static function is_plugin_screen(): bool {
		$screen = get_current_screen();
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$owned = 0 === strpos( $page, 'adc-' ) || ( EngagementPages::enabled() && in_array( $page, array( 'car-dealer-messages', 'car-dealer-bookings', 'car-dealer-subscribers' ), true ) );
		return $screen && $owned && str_ends_with( $screen->id, '_page_' . $page );
	}

	public static function body_class( string $classes ): string {
		return self::is_plugin_screen() ? $classes . ' adc-admin' : $classes;
	}

	public static function enqueue(): void {
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		wp_enqueue_style( 'adc-admin', plugins_url( 'assets/css/admin.css', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin.css' ) );
		wp_enqueue_script( 'adc-admin-layout', plugins_url( 'assets/js/admin-layout.js', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/js/admin-layout.js' ), true );
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'adc-workspace' === $page ) {
			wp_enqueue_style( 'adc-admin-workspace', plugins_url( 'assets/css/admin-workspace.css', ADC_FILE ), array( 'adc-admin' ), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-workspace.css' ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'adc_view_workspace' ) ) {
			wp_die( esc_html__( 'ليست لديك صلاحية دخول مساحة عمليات المعرض.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}

		$user = wp_get_current_user();
		$groups = self::groups();
		?>
		<div class="wrap adc-workspace" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<header class="adc-workspace-hero">
				<div>
					<span><?php esc_html_e( 'مساحة تشغيل مستقلة عن الثيم', 'auto-dealership-core' ); ?></span>
					<h1><?php echo esc_html( sprintf( __( 'مرحبًا، %s', 'auto-dealership-core' ), $user->display_name ) ); ?></h1>
					<p><?php esc_html_e( 'تظهر الوحدات المتاحة وفق صلاحيات دورك ونطاق عملك الحالي.', 'auto-dealership-core' ); ?></p>
				</div>
				<span class="dashicons dashicons-car" aria-hidden="true"></span>
			</header>

			<?php foreach ( $groups as $group ) : ?>
				<?php $items = array_values( array_filter( $group['items'], static fn( array $item ): bool => self::allowed( $item ) ) ); ?>
				<?php if ( ! $items ) { continue; } ?>
				<section class="adc-workspace-section">
					<h2><?php echo esc_html( $group['title'] ); ?></h2>
					<div class="adc-workspace-grid">
						<?php foreach ( $items as $item ) : ?>
							<a class="adc-workspace-card" href="<?php echo esc_url( self::url( $item['path'] ) ); ?>">
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<small><?php echo esc_html( $item['description'] ); ?></small>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function allowed( array $item ): bool {
		foreach ( (array) $item['capabilities'] as $capability ) {
			if ( current_user_can( $capability ) ) {
				return true;
			}
		}
		return false;
	}

	private static function url( string $path ): string {
		return admin_url( $path );
	}

	private static function item( $capabilities, string $path, string $title, string $description, string $icon ): array {
		return compact( 'capabilities', 'path', 'title', 'description', 'icon' );
	}

	private static function groups(): array {
		$lead_caps = array( 'adc_view_own_leads', 'adc_view_branch_leads' );
		return array(
			array(
				'title' => __( 'المبيعات وخدمة العملاء', 'auto-dealership-core' ),
				'items' => array(
					self::item( $lead_caps, 'admin.php?page=adc-crm', __( 'فرص العملاء CRM', 'auto-dealership-core' ), __( 'متابعة العملاء والمراحل والأنشطة.', 'auto-dealership-core' ), 'dashicons-groups' ),
					self::item( $lead_caps, 'admin.php?page=car-dealer-messages', __( 'رسائل العملاء', 'auto-dealership-core' ), __( 'طلبات التواصل والبيع ضمن نطاقك.', 'auto-dealership-core' ), 'dashicons-email-alt2' ),
					self::item( $lead_caps, 'admin.php?page=car-dealer-bookings', __( 'حجوزات التجربة', 'auto-dealership-core' ), __( 'مواعيد تجربة القيادة وتحديثاتها.', 'auto-dealership-core' ), 'dashicons-calendar-alt' ),
					self::item( 'adc_view_workspace', 'admin.php?page=adc-quotes', __( 'عروض الأسعار', 'auto-dealership-core' ), __( 'إنشاء ومراجعة عروض الأسعار المسموح بها.', 'auto-dealership-core' ), 'dashicons-media-document' ),
				),
			),
			array(
				'title' => __( 'المخزون والمحتوى والمشتريات', 'auto-dealership-core' ),
				'items' => array(
					self::item( 'adc_view_inventory', 'admin.php?page=adc-inventory', __( 'المخزون التشغيلي', 'auto-dealership-core' ), __( 'المركبات وحالاتها التشغيلية حسب الفرع.', 'auto-dealership-core' ), 'dashicons-car' ),
					self::item( 'edit_cars', 'edit.php?post_type=car', __( 'السيارات المنشورة', 'auto-dealership-core' ), __( 'إدارة صفحات السيارات التحريرية.', 'auto-dealership-core' ), 'dashicons-admin-post' ),
					self::item( 'edit_car_offers', 'edit.php?post_type=car_offer', __( 'العروض المنشورة', 'auto-dealership-core' ), __( 'إدارة محتوى عروض السيارات.', 'auto-dealership-core' ), 'dashicons-megaphone' ),
					self::item( 'adc_transfer_inventory', 'admin.php?page=adc-transfers', __( 'طلب نقل مركبة', 'auto-dealership-core' ), __( 'إنشاء طلب نقل لمركبة متاحة إلى فرع آخر.', 'auto-dealership-core' ), 'dashicons-migrate' ),
					self::item( 'adc_transfer_inventory', 'admin.php?page=adc-transfer-queue', __( 'طابور نقل المركبات', 'auto-dealership-core' ), __( 'اعتماد وإرسال واستلام النقل بين الفروع.', 'auto-dealership-core' ), 'dashicons-randomize' ),
					self::item( array( 'adc_manage_inventory', 'adc_change_vehicle_vin' ), 'admin.php?page=adc-inventory-identity', __( 'المواقع وVIN', 'auto-dealership-core' ), __( 'الاستلام والفحص والموقع وهوية المركبة.', 'auto-dealership-core' ), 'dashicons-location-alt' ),
					self::item( 'adc_manage_inventory', 'admin.php?page=adc-vehicle-specifications', __( 'مواصفات المركبات', 'auto-dealership-core' ), __( 'الخصائص الفنية المقيدة للمركبات.', 'auto-dealership-core' ), 'dashicons-admin-tools' ),
					self::item( 'adc_manage_inventory', 'admin.php?page=adc-vehicle-issues', __( 'الاحتجاز والصيانة', 'auto-dealership-core' ), __( 'فتح ومعالجة حالات الاحتجاز والصيانة.', 'auto-dealership-core' ), 'dashicons-warning' ),
					self::item( 'adc_process_returns', 'admin.php?page=adc-vehicle-returns', __( 'مرتجعات المركبات', 'auto-dealership-core' ), __( 'معالجة المركبات المرتجعة وفق سير العمل.', 'auto-dealership-core' ), 'dashicons-undo' ),
					self::item( 'adc_view_suppliers', 'admin.php?page=adc-suppliers', __( 'الموردون', 'auto-dealership-core' ), __( 'دليل الموردين وبياناتهم المقيدة.', 'auto-dealership-core' ), 'dashicons-store' ),
					self::item( 'adc_view_vehicle_costs', 'admin.php?page=adc-vehicle-acquisition', __( 'اقتناء المركبات', 'auto-dealership-core' ), __( 'التكاليف والمراجع ومستندات الاقتناء.', 'auto-dealership-core' ), 'dashicons-money-alt' ),
				),
			),
			array(
				'title' => __( 'الاعتمادات والمالية والتسليم', 'auto-dealership-core' ),
				'items' => array(
					self::item( array( 'adc_review_discounts', 'adc_approve_sales' ), 'admin.php?page=adc-approvals', __( 'الاعتمادات', 'auto-dealership-core' ), __( 'الخصومات واعتماد المبيعات والإلغاءات.', 'auto-dealership-core' ), 'dashicons-yes-alt' ),
					self::item( array( 'adc_view_finance', 'adc_manage_finance' ), 'admin.php?page=adc-finance', __( 'التمويل', 'auto-dealership-core' ), __( 'طلبات التمويل وقراراتها ومراجعها.', 'auto-dealership-core' ), 'dashicons-bank' ),
					self::item( 'adc_view_finance', 'admin.php?page=adc-payments', __( 'تأكيدات السداد', 'auto-dealership-core' ), __( 'مراجعة دفعات المبيعات والعربون.', 'auto-dealership-core' ), 'dashicons-money' ),
					self::item( 'adc_view_finance', 'admin.php?page=adc-refunds', __( 'المبالغ المستردة', 'auto-dealership-core' ), __( 'طلبات الاسترداد وقراراتها.', 'auto-dealership-core' ), 'dashicons-image-rotate' ),
					self::item( array( 'adc_approve_delivery', 'adc_confirm_vehicle_vin' ), 'admin.php?page=adc-delivery', __( 'التسليم', 'auto-dealership-core' ), __( 'التجهيز والمستندات والمطابقة والإطلاق.', 'auto-dealership-core' ), 'dashicons-clipboard' ),
				),
			),
			array(
				'title' => __( 'التسويق والتقارير والإدارة', 'auto-dealership-core' ),
				'items' => array(
					self::item( 'adc_view_marketing_subscribers', 'admin.php?page=car-dealer-subscribers', __( 'اشتراكات النشرة', 'auto-dealership-core' ), __( 'موافقات التواصل التسويقي الحالية.', 'auto-dealership-core' ), 'dashicons-email' ),
					self::item( 'adc_view_reports', 'admin.php?page=adc-operational-reports', __( 'التقارير التشغيلية', 'auto-dealership-core' ), __( 'مؤشرات مجمعة ضمن نطاق الصلاحية.', 'auto-dealership-core' ), 'dashicons-chart-bar' ),
					self::item( 'adc_view_audit', 'admin.php?page=adc-audit', __( 'سجل التدقيق', 'auto-dealership-core' ), __( 'الأحداث الإدارية والتشغيلية للقراءة.', 'auto-dealership-core' ), 'dashicons-visibility' ),
					self::item( 'manage_options', 'admin.php?page=adc-settings', __( 'إعدادات المنصة', 'auto-dealership-core' ), __( 'الفروع والسياسات والإعدادات المركزية.', 'auto-dealership-core' ), 'dashicons-admin-multisite' ),
					self::item( 'manage_options', 'admin.php?page=adc-editorial-setup', __( 'إعداد صفحات الموقع', 'auto-dealership-core' ), __( 'مراجعة الصفحات التعريفية وإنشاء المسودات المفقودة صراحة.', 'auto-dealership-core' ), 'dashicons-admin-page' ),
					self::item( 'manage_options', 'users.php', __( 'المستخدمون', 'auto-dealership-core' ), __( 'حسابات الموظفين والأدوار والتعيينات.', 'auto-dealership-core' ), 'dashicons-admin-users' ),
				),
			),
		);
	}
}
