<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** One navigation contract for sidebar pages and workspace cards. */
final class Navigation {
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 99 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ) );
		add_action( 'admin_menu', array( self::class, 'hide_wordpress_menu' ), 999 );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( self::class, 'menu_order' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 999 );
		add_action( 'load-index.php', array( self::class, 'dashboard_redirect' ) );
	}

	private static function is_system_admin(): bool {
		return in_array( 'administrator', wp_get_current_user()->roles, true ) || ( is_multisite() && is_super_admin() );
	}

	private static function wordpress_pages(): array {
		return array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php' );
	}

	public static function hide_wordpress_menu(): void {
		if ( self::is_system_admin() ) { return; }
		foreach ( self::wordpress_pages() as $page ) { remove_menu_page( $page ); }
	}

	public static function menu_order( $order ): array {
		$order = is_array( $order ) ? $order : array();
		$dealership = array( 'adc-workspace' );
		foreach ( self::groups() as $group ) { $dealership[] = 'adc-area-' . $group['id']; }
		// Preserve every remaining plugin/system destination and its relative order.
		return array_merge( array_values( array_intersect( $dealership, $order ) ), array_values( array_diff( $order, $dealership ) ) );
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( self::is_system_admin() ) { return; }
		foreach ( array( 'wp-logo', 'updates', 'comments', 'new-post', 'new-page', 'new-media', 'new-user', 'appearance', 'themes', 'widgets', 'menus', 'customize', 'dashboard' ) as $node ) { $bar->remove_node( $node ); }
	}

	public static function dashboard_redirect(): void {
		if ( ! self::is_system_admin() && current_user_can( 'adc_view_workspace' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=adc-workspace' ) );
			exit;
		}
	}

	public static function parent_file( string $parent ): string {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->base, array( 'edit-tags', 'term' ), true ) && in_array( $screen->taxonomy, array( 'car_brand', 'car_category' ), true ) ) {
			$GLOBALS['submenu_file'] = 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=car';
			return 'adc-area-inventory';
		}
		return $parent;
	}

	public static function groups(): array {
		$leads = array( 'adc_view_own_leads', 'adc_view_branch_leads' );
		$definitions = array(
			'customers' => array( 'العملاء والمبيعات', 'dashicons-groups', array(
				array( 'adc-crm', 'فرص العملاء', $leads, OperationsPages::class, 'render_leads' ),
				array( 'car-dealer-messages', 'رسائل العملاء', $leads, EngagementPages::class, 'render_messages' ),
				array( 'car-dealer-bookings', 'حجوزات تجربة القيادة', $leads, EngagementPages::class, 'render_bookings' ),
				array( 'adc-quotes', 'عروض أسعار العملاء', array( 'manage_options', 'adc_view_own_leads', 'adc_view_branch_leads', 'adc_view_finance' ), QuotePages::class, 'render' ),
				array( 'adc-customer-identities', 'مراجعة ملفات العملاء', 'manage_options', CustomerIdentityPage::class, 'render' ),
			) ),
			'inventory' => array( 'المخزون', 'dashicons-car', array(
				array( 'adc-inventory', 'المركبات التشغيلية', 'adc_view_inventory', OperationsPages::class, 'render' ),
				array( 'adc-inventory-identity', 'الاستلام والفحص وحركة المواقع', 'adc_manage_inventory', InventoryIdentityPage::class, 'render' ),
				array( 'adc-vehicle-vin', 'تصحيح رقم الهيكل VIN', 'adc_change_vehicle_vin', InventoryIdentityPage::class, 'render' ),
				array( 'adc-vehicle-specifications', 'مواصفات المركبات', 'adc_manage_inventory', VehicleSpecificationsPage::class, 'render' ),
				array( 'adc-transfers', 'إنشاء طلب نقل', 'adc_transfer_inventory', OperationsPages::class, 'render_transfers' ),
				array( 'adc-transfer-queue', 'متابعة نقل المركبات', 'adc_transfer_inventory', TransferPages::class, 'render' ),
				array( 'adc-vehicle-issues', 'الاحتجاز والصيانة', 'adc_manage_inventory', VehicleIssuePage::class, 'render' ),
				array( 'adc-vehicle-returns', 'مرتجعات المركبات', 'adc_process_returns', VehicleReturnPage::class, 'render' ),
				array( 'edit.php?post_type=car', 'صفحات السيارات', 'edit_cars' ),
				array( 'edit-tags.php?taxonomy=car_brand&post_type=car', 'ماركات السيارات', 'manage_car_brands' ),
				array( 'edit-tags.php?taxonomy=car_category&post_type=car', 'فئات السيارات', 'manage_car_categories' ),
			) ),
			'purchasing' => array( 'المشتريات', 'dashicons-store', array(
				array( 'adc-suppliers', 'الموردون', 'adc_view_suppliers', SupplierPage::class, 'render' ),
				array( 'adc-vehicle-acquisition', 'اقتناء المركبات وتكاليفها', 'adc_view_vehicle_costs', VehicleAcquisitionPage::class, 'render' ),
			) ),
			'approvals' => array( 'الاعتمادات', 'dashicons-yes-alt', array(
				array( 'adc-approvals', 'اعتماد الخصومات والمبيعات', array( 'adc_review_discounts', 'adc_approve_sales' ), WorkflowPages::class, 'approvals' ),
				array( 'adc-sale-cancellations', 'إلغاء المبيعات', 'adc_cancel_sales', SaleCancellationPage::class, 'render' ),
			) ),
			'finance' => array( 'المالية', 'dashicons-money-alt', array(
				array( 'adc-finance', 'طلبات التمويل', array( 'adc_view_finance', 'adc_manage_finance' ), WorkflowPages::class, 'finance' ),
				array( 'adc-payments', 'تأكيدات السداد', 'adc_view_finance', PaymentPages::class, 'render' ),
				array( 'adc-refunds', 'المبالغ المستردة', 'adc_view_finance', RefundPage::class, 'render' ),
				array( 'adc-financial-export', 'التصدير المالي', 'adc_view_finance', FinancialExportPage::class, 'render' ),
			) ),
			'delivery' => array( 'التسليم', 'dashicons-clipboard', array(
				array( 'adc-delivery', 'تجهيز وتسليم المركبات', array( 'adc_approve_delivery', 'adc_confirm_vehicle_vin' ), WorkflowPages::class, 'delivery' ),
			) ),
			'content' => array( 'محتوى الموقع', 'dashicons-admin-page', array(
				array( 'edit.php?post_type=car_offer', 'العروض الترويجية', 'edit_car_offers' ),
				array( 'edit.php?post_type=page', 'صفحات الموقع', 'edit_pages' ),
				array( 'upload.php', 'مكتبة الوسائط', 'upload_files' ),
				array( 'customize.php', 'مظهر الموقع وبيانات التواصل', 'customize' ),
				array( 'adc-editorial-setup', 'إعداد صفحات الموقع', 'manage_options', EditorialSetupPage::class, 'render' ),
			) ),
			'marketing' => array( 'التسويق', 'dashicons-megaphone', array(
				array( 'car-dealer-subscribers', 'مشتركو النشرة البريدية', 'adc_view_marketing_subscribers', EngagementPages::class, 'render_subscribers' ),
			) ),
			'reports' => array( 'التقارير', 'dashicons-chart-bar', array(
				array( 'adc-operational-reports', 'التقارير التشغيلية', 'adc_view_reports', OperationalReportPage::class, 'render' ),
			) ),
			'settings' => array( 'إعدادات المنصة', 'dashicons-admin-settings', array(
				array( 'adc-settings', 'سياسات وإعدادات المنصة', 'manage_options', SettingsPage::class, 'render' ),
				array( 'adc-reference', 'العلامات التجارية والمواقع', 'manage_options', ReferencePage::class, 'render' ),
				array( 'adc-finance-calculator', 'إعدادات حاسبة التمويل', 'manage_options', FinanceCalculatorPage::class, 'render' ),
				array( 'adc-catalog-cutover', 'مطابقة الكتالوج', 'manage_options', CatalogCutoverPage::class, 'render' ),
				array( 'adc-stored-translations', 'ترجمات البيانات', 'manage_options', StoredTranslationsPage::class, 'render' ),
				array( 'users.php', 'المستخدمون والصلاحيات', 'list_users' ),
				array( 'adc-roles', 'Roles and permissions', 'manage_options', RoleManager::class, 'render' ),
			) ),
			'audit' => array( 'الرقابة والتكاملات', 'dashicons-visibility', array(
				array( 'adc-audit', 'سجل التدقيق', 'adc_view_audit', AuditPage::class, 'render' ),
				array( 'adc-public-security', 'أمن الواجهة العامة', 'adc_view_audit', SecurityPage::class, 'render' ),
				array( 'adc-integrations', 'جاهزية التكاملات', 'adc_view_integrations', IntegrationPage::class, 'render' ),
				array( 'adc-outbox', 'مراقبة المهام', 'adc_view_outbox', OutboxPage::class, 'render' ),
			) ),
		);
		$descriptions = self::descriptions();
		$groups = array();
		foreach ( $definitions as $id => $definition ) {
			$items = array();
			foreach ( $definition[2] as $entry ) {
				$native = str_contains( $entry[0], '.php' );
				$items[] = array( 'slug' => $entry[0], 'path' => $native ? $entry[0] : 'admin.php?page=' . $entry[0], 'title' => __( $entry[1], 'auto-dealership-core' ), 'description' => $descriptions[$native ? $entry[0] : 'admin.php?page=' . $entry[0]] ?? '', 'icon' => $definition[1], 'capabilities' => (array) $entry[2], 'callback' => $native ? '' : array( $entry[3], $entry[4] ) );
			}
			$groups[] = array( 'id' => $id, 'title' => __( $definition[0], 'auto-dealership-core' ), 'icon' => $definition[1], 'items' => $items );
		}
		return $groups;
	}

	private static function descriptions(): array {
		return array(
			'admin.php?page=adc-crm' => __( 'متابعة العملاء والمراحل والأنشطة.', 'auto-dealership-core' ),
			'admin.php?page=car-dealer-messages' => __( 'طلبات التواصل والبيع ضمن نطاقك.', 'auto-dealership-core' ),
			'admin.php?page=car-dealer-bookings' => __( 'مواعيد تجربة القيادة وتحديثاتها.', 'auto-dealership-core' ),
			'admin.php?page=adc-quotes' => __( 'إنشاء ومراجعة عروض الأسعار المسموح بها.', 'auto-dealership-core' ),
			'admin.php?page=adc-inventory' => __( 'المركبات وحالاتها التشغيلية حسب الفرع.', 'auto-dealership-core' ),
			'edit.php?post_type=car' => __( 'إدارة صفحات السيارات التحريرية.', 'auto-dealership-core' ),
			'edit-tags.php?taxonomy=car_brand&post_type=car' => __( 'إدارة ماركات السيارات في كتالوج الموقع.', 'auto-dealership-core' ),
			'edit-tags.php?taxonomy=car_category&post_type=car' => __( 'إدارة فئات السيارات في كتالوج الموقع.', 'auto-dealership-core' ),
			'edit.php?post_type=car_offer' => __( 'إدارة محتوى عروض السيارات.', 'auto-dealership-core' ),
			'admin.php?page=adc-transfers' => __( 'إنشاء طلب نقل لمركبة متاحة إلى فرع آخر.', 'auto-dealership-core' ),
			'admin.php?page=adc-transfer-queue' => __( 'اعتماد وإرسال واستلام النقل بين الفروع.', 'auto-dealership-core' ),
			'admin.php?page=adc-inventory-identity' => __( 'الاستلام والفحص والموقع وهوية المركبة.', 'auto-dealership-core' ),
			'admin.php?page=adc-vehicle-specifications' => __( 'الخصائص الفنية المقيدة للمركبات.', 'auto-dealership-core' ),
			'admin.php?page=adc-vehicle-issues' => __( 'فتح ومعالجة حالات الاحتجاز والصيانة.', 'auto-dealership-core' ),
			'admin.php?page=adc-vehicle-returns' => __( 'معالجة المركبات المرتجعة وفق سير العمل.', 'auto-dealership-core' ),
			'admin.php?page=adc-suppliers' => __( 'دليل الموردين وبياناتهم المقيدة.', 'auto-dealership-core' ),
			'admin.php?page=adc-vehicle-acquisition' => __( 'التكاليف والمراجع ومستندات الاقتناء.', 'auto-dealership-core' ),
			'admin.php?page=adc-approvals' => __( 'الخصومات واعتماد المبيعات والإلغاءات.', 'auto-dealership-core' ),
			'admin.php?page=adc-finance' => __( 'طلبات التمويل وقراراتها ومراجعها.', 'auto-dealership-core' ),
			'admin.php?page=adc-payments' => __( 'مراجعة دفعات المبيعات والعربون.', 'auto-dealership-core' ),
			'admin.php?page=adc-refunds' => __( 'طلبات الاسترداد وقراراتها.', 'auto-dealership-core' ),
			'admin.php?page=adc-delivery' => __( 'التجهيز والمستندات والمطابقة والإطلاق.', 'auto-dealership-core' ),
			'admin.php?page=car-dealer-subscribers' => __( 'موافقات التواصل التسويقي الحالية.', 'auto-dealership-core' ),
			'admin.php?page=adc-operational-reports' => __( 'مؤشرات مجمعة ضمن نطاق الصلاحية.', 'auto-dealership-core' ),
			'admin.php?page=adc-audit' => __( 'الأحداث الإدارية والتشغيلية للقراءة.', 'auto-dealership-core' ),
			'admin.php?page=adc-settings' => __( 'الفروع والسياسات والإعدادات المركزية.', 'auto-dealership-core' ),
			'admin.php?page=adc-editorial-setup' => __( 'مراجعة الصفحات التعريفية وإنشاء المسودات المفقودة صراحة.', 'auto-dealership-core' ),
			'admin.php?page=adc-stored-translations' => __( 'Original text', 'auto-dealership-core' ) . ' / ' . __( 'Approved translation', 'auto-dealership-core' ),
			'users.php' => __( 'حسابات الموظفين والأدوار والتعيينات.', 'auto-dealership-core' ),
		);
	}

	public static function allowed( array $item ): bool {
		if ( in_array( $item['slug'], array_merge( self::wordpress_pages(), array( 'customize.php' ) ), true ) && ! self::is_system_admin() ) { return false; }
		if ( in_array( $item['slug'], array( 'car-dealer-messages', 'car-dealer-bookings', 'car-dealer-subscribers' ), true ) && ! EngagementPages::enabled() ) { return false; }
		if ( 'adc-quotes' === $item['slug'] ) { return \AutoDealership\Pricing\QuoteHistory::can_read(); }
		if ( in_array( $item['slug'], array( 'adc-inventory-identity', 'adc-vehicle-vin' ), true ) && ! current_user_can( 'adc_view_inventory' ) && ! current_user_can( 'manage_options' ) ) { return false; }
		foreach ( $item['capabilities'] as $capability ) {
			if ( current_user_can( $capability ) ) { return true; }
		}
		return false;
	}

	public static function tabs( string $page, array $tabs, string $current ): void {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'أقسام الصفحة', 'auto-dealership-core' ) . '">';
		foreach ( $tabs as $key => $title ) {
			echo '<a class="nav-tab' . ( $key === $current ? ' nav-tab-active' : '' ) . '"' . ( $key === $current ? ' aria-current="page"' : '' ) . ' href="' . esc_url( add_query_arg( array( 'page' => $page, 'section' => $key ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( $title, 'auto-dealership-core' ) . '</a>';
		}
		echo '</nav>';
	}

	/** Keep navigation available even when a phase has no rows on this page. */
	public static function pagination( string $slug, string $section, int $page, int $count ): void {
		echo '<nav class="tablenav" aria-label="' . esc_attr__( 'صفحات المركبات', 'auto-dealership-core' ) . '">';
		foreach ( array( $page - 1 => 'السابق', $page + 1 => 'التالي' ) as $target => $label ) {
			if ( $target < 1 || ( $target > $page && $count < 50 ) ) { continue; }
			echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => $slug, 'section' => $section, 'paged' => $target ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( $label, 'auto-dealership-core' ) . '</a> ';
		}
		echo '</nav>';
	}

	public static function menu(): void {
		// Re-register existing page callbacks under their new parents. WordPress also
		// retains the original hooks, so old bookmarked admin.php URLs still work.
		$groups = self::groups();
		$slugs = array( 'adc-workspace' );
		foreach ( $groups as $group ) { foreach ( $group['items'] as $item ) { if ( $item['callback'] ) { $slugs[] = $item['slug']; } } }
		global $submenu;
		foreach ( $slugs as $slug ) { remove_menu_page( $slug ); }
		foreach ( array_keys( (array) $submenu ) as $parent ) {
			foreach ( $submenu[$parent] as $entry ) {
				if ( in_array( $entry[2], $slugs, true ) || in_array( $entry[2], array( 'edit.php?post_type=car', 'edit.php?post_type=car_offer' ), true ) ) { remove_submenu_page( $parent, $entry[2] ); }
			}
		}
		if ( current_user_can( 'adc_view_workspace' ) ) {
			add_menu_page( __( 'مساحة العمل', 'auto-dealership-core' ), __( 'مساحة العمل', 'auto-dealership-core' ), 'adc_view_workspace', 'adc-workspace', array( WorkspacePage::class, 'render' ), 'dashicons-dashboard', 56 );
		}
		foreach ( $groups as $index => $group ) {
			$items = array_filter( $group['items'], array( self::class, 'allowed' ) );
			if ( ! $items ) { continue; }
			$parent = 'adc-area-' . $group['id'];
			add_menu_page( $group['title'], $group['title'], 'read', $parent, array( WorkspacePage::class, 'render' ), $group['icon'], 57 + $index / 10 );
			add_submenu_page( $parent, $group['title'], __( 'نظرة عامة', 'auto-dealership-core' ), 'read', $parent, array( WorkspacePage::class, 'render' ) );
			foreach ( $items as $item ) {
				// Native WordPress pages keep their existing sidebar entry. Cards provide
				// contextual shortcuts without duplicating those entries in another menu.
				if ( ! $item['callback'] && ! in_array( $item['slug'], array( 'edit.php?post_type=car', 'edit.php?post_type=car_offer' ), true ) && ! str_starts_with( $item['slug'], 'edit-tags.php?' ) ) { continue; }
				$capability = 'read';
				foreach ( $item['capabilities'] as $candidate ) { if ( current_user_can( $candidate ) ) { $capability = $candidate; break; } }
				add_submenu_page( $parent, $item['title'], $item['title'], $capability, $item['slug'], $item['callback'] );
			}
		}
		// Preserve bookmarked inventory URLs for managers whose inventory action is VIN correction.
		if ( current_user_can( 'adc_change_vehicle_vin' ) && ! current_user_can( 'adc_manage_inventory' ) && current_user_can( 'adc_view_inventory' ) ) {
			add_submenu_page( null, __( 'Locations and VIN', 'auto-dealership-core' ), __( 'Locations and VIN', 'auto-dealership-core' ), 'adc_change_vehicle_vin', 'adc-inventory-identity', array( InventoryIdentityPage::class, 'render' ) );
		}
	}
}
