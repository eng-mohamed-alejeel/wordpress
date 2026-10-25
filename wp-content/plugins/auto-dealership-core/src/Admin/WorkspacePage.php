<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Role-aware entry point for every plugin-owned dealership administration area. */
final class WorkspacePage {
	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'customize_controls_enqueue_scripts', array( self::class, 'enqueue_customizer' ) );
	}

	public static function enqueue_customizer(): void {
		wp_enqueue_style( 'adc-admin-customizer', plugins_url( 'assets/css/admin-customizer.css', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-customizer.css' ) );
	}

	private static function is_plugin_screen(): bool {
		$screen = get_current_screen();
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$owned = 0 === strpos( $page, 'adc-' ) || ( EngagementPages::enabled() && in_array( $page, array( 'car-dealer-messages', 'car-dealer-bookings', 'car-dealer-subscribers' ), true ) );
		return $screen && $owned && str_ends_with( $screen->id, '_page_' . $page );
	}

	public static function body_class( string $classes ): string {
		return $classes . ' adc-admin-shell' . ( self::is_plugin_screen() ? ' adc-admin' : ( self::is_content_screen() ? ' adc-admin-native' : '' ) );
	}

	private static function is_content_screen(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) { return false; }
		if ( in_array( $screen->post_type, array( 'car', 'car_offer' ), true ) && in_array( $screen->base, array( 'edit', 'edit-tags', 'term', 'post' ), true ) ) { return true; }
		$system_screens = array( 'dashboard', 'edit', 'edit-comments', 'comment', 'edit-tags', 'term', 'post', 'upload', 'users', 'user-edit', 'profile', 'plugins', 'plugin-install', 'themes', 'theme-install', 'tools', 'import', 'export', 'options-general', 'options-writing', 'options-reading', 'options-discussion', 'options-media', 'options-permalink', 'options-privacy', 'update-core' );
		return current_user_can( 'manage_options' ) && ( in_array( $screen->base, $system_screens, true ) || 'settings_page_wpsupercache' === $screen->id );
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'adc-admin-tokens', plugins_url( 'assets/css/admin-tokens.css', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-tokens.css' ) );
		wp_enqueue_style( 'adc-admin-shell', plugins_url( 'assets/css/admin-shell.css', ADC_FILE ), array( 'adc-admin-tokens' ), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-shell.css' ) );
		if ( self::is_content_screen() ) {
			wp_enqueue_style( 'adc-admin-native', plugins_url( 'assets/css/admin-native.css', ADC_FILE ), array( 'adc-admin-tokens' ), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-native.css' ) );
		}
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		wp_enqueue_style( 'adc-admin', plugins_url( 'assets/css/admin.css', ADC_FILE ), array( 'adc-admin-tokens' ), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin.css' ) );
		wp_enqueue_script( 'adc-admin-layout', plugins_url( 'assets/js/admin-layout.js', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/js/admin-layout.js' ), true );
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'adc-workspace' === $page || str_starts_with( $page, 'adc-area-' ) ) {
			wp_enqueue_style( 'adc-admin-workspace', plugins_url( 'assets/css/admin-workspace.css', ADC_FILE ), array( 'adc-admin' ), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-workspace.css' ) );
		}
	}

	public static function render(): void {
		$groups = self::groups();
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$area_allowed = str_starts_with( $page, 'adc-area-' ) && current_user_can( 'read' ) && $groups && array_filter( $groups[0]['items'], array( Navigation::class, 'allowed' ) );
		if ( ! $area_allowed && ( str_starts_with( $page, 'adc-area-' ) || ! current_user_can( 'adc_view_workspace' ) ) ) {
			wp_die( esc_html__( 'ليست لديك صلاحية دخول مساحة عمليات المعرض.', 'auto-dealership-core' ), '', array( 'response' => 403 ) );
		}

		$user = wp_get_current_user();
		$context = self::context( $user );
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$logo = $logo_id ? wp_get_attachment_image( $logo_id, 'medium', false, array( 'class' => 'adc-workspace-logo', 'alt' => get_bloginfo( 'name' ), 'loading' => 'eager' ) ) : '';
		if ( ! $logo && get_site_icon_url( 128 ) ) {
			$logo = '<img class="adc-workspace-logo adc-workspace-logo--site-icon" width="100" height="100" src="' . esc_url( get_site_icon_url( 128 ) ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '">';
		}
		?>
		<div class="wrap adc-workspace" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<div class="adc-workspace-hero">
				<div>
					<span><?php echo esc_html( sprintf( \AutoDealership\Core\ProductSetup::t( 'مساحة العمل · %s', 'Workspace · %s' ), $context['role'] ) ); ?></span>
					<h1><?php echo esc_html( $area_allowed ? $groups[0]['title'] : sprintf( __( 'مرحبًا، %s', 'auto-dealership-core' ), $user->display_name ) ); ?></h1>
					<p><?php echo esc_html( sprintf( \AutoDealership\Core\ProductSetup::t( 'تظهر الوحدات المتاحة وفق صلاحيات دورك. نطاق العمل الحالي: %s.', 'Available modules follow your role permissions. Current scope: %s.' ), $context['scope'] ) ); ?></p>
				</div>
				<?php if ( $logo ) : ?><div class="adc-workspace-brand"><?php echo $logo; // WordPress attachment markup or escaped site icon. ?></div><?php endif; ?>
			</div>

			<?php \AutoDealership\Portal\StaffPresence::control('workspace'); ?>
			<?php if ( ! $area_allowed ) { WorkspaceOverviewView::render(); } ?>
			<?php if ( $area_allowed && isset( $groups[0] ) ) : $area_meta = self::area_meta( $groups[0]['id'] ); ?>
				<?php if ( $area_meta ) : ?><section class="adc-center-intro" aria-labelledby="adc-center-heading"><span class="adc-center-kicker"><?php echo esc_html( \AutoDealership\Core\ProductSetup::t( 'مركز العمل', 'Work center' ) ); ?></span><h2 id="adc-center-heading"><?php echo esc_html( $area_meta['title'] ); ?></h2><p><?php echo esc_html( $area_meta['description'] ); ?></p></section><?php endif; ?>
			<?php endif; ?>
			<?php if ( ! $area_allowed ) : ?><section class="adc-area-directory" aria-labelledby="adc-area-directory-heading"><header class="adc-area-directory-header"><div><h2 id="adc-area-directory-heading"><?php echo esc_html( \AutoDealership\Core\ProductSetup::t( 'الوحدات المسموحة لدورك', 'Modules available to your role' ) ); ?></h2><p><?php echo esc_html( \AutoDealership\Core\ProductSetup::t( 'اختر قسمًا لعرض أدواته المسموحة فقط. الصلاحيات الفعلية تُراجع داخل كل إجراء.', 'Choose an area to see only its permitted tools. Effective permissions are checked again for every action.' ) ); ?></p></div><span class="adc-scope-badge"><?php echo esc_html( $context['scope'] ); ?></span></header><?php endif; ?>
			<?php foreach ( $groups as $group ) : ?>
				<?php $items = array_values( array_filter( $group['items'], static fn( array $item ): bool => self::allowed( $item ) ) ); ?>
				<?php if ( ! $items ) { continue; } ?>
                <?php if ( ! $area_allowed ) : ?>
                    <a class="adc-area-card" href="<?php echo esc_url( admin_url( 'admin.php?page=adc-area-' . $group['id'] ) ); ?>">
                        <span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>" aria-hidden="true"></span>
                        <span><strong><?php echo esc_html( $group['title'] ); ?></strong><small><?php echo esc_html( \AutoDealership\Core\ProductSetup::t( 'فتح القسم', 'Open area' ) ); ?></small></span>
                        <span aria-hidden="true">&#8250;</span>
                    </a>
                    <?php continue; endif; ?>
				<section class="adc-workspace-section">
					<?php if ( ! $area_allowed ) : ?><h2><?php echo esc_html( $group['title'] ); ?></h2><?php endif; ?>
					<?php
					$sections = array( '' => $items );
					if ( 'inventory' === $group['id'] ) {
						$sections = array(
							\AutoDealership\Core\ProductSetup::t( 'المركبات التشغيلية', 'Operational vehicles' ) => array_filter( $items, static fn( array $item ): bool => ! str_contains( $item['path'], 'post_type=car' ) ),
							\AutoDealership\Core\ProductSetup::t( 'كتالوج الموقع', 'Website catalog' ) => array_filter( $items, static fn( array $item ): bool => str_contains( $item['path'], 'post_type=car' ) ),
						);
					}
					if ( 'customers' === $group['id'] ) { $sections = self::customer_sections( $items ); }
					if ( 'purchasing' === $group['id'] ) { $sections = self::purchasing_sections( $items ); }
					if ( 'finance' === $group['id'] ) { $sections = self::finance_sections( $items ); }
					if ( 'reports' === $group['id'] ) { $sections = self::report_sections( $items ); }
					foreach ( $sections as $section_title => $section_items ) :
						if ( ! $section_items ) { continue; }
					?>
					<?php if ( $section_title ) : ?>
						<?php if ( $area_allowed ) : ?><h2><?php echo esc_html__( $section_title, 'auto-dealership-core' ); ?></h2>
						<?php else : ?><h3><?php echo esc_html__( $section_title, 'auto-dealership-core' ); ?></h3><?php endif; ?>
					<?php endif; ?>
					<div class="adc-workspace-grid">
						<?php foreach ( $section_items as $item ) : ?>
							<a class="adc-workspace-card" href="<?php echo esc_url( self::url( $item['path'] ) ); ?>">
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<?php if ( $item['description'] ) : ?><small><?php echo esc_html( $item['description'] ); ?></small><?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>
            <?php if ( ! $area_allowed ) : ?></section><?php endif; ?>
		</div>
		<?php
	}

	private static function allowed( array $item ): bool {
		return Navigation::allowed( $item );
	}

	private static function url( string $path ): string {
		return admin_url( $path );
	}

	private static function context( \WP_User $user ): array {
		$role_names = array();
		foreach ( (array) $user->roles as $role ) {
			if ( is_string( $role ) ) { $role_names[] = translate_user_role( wp_roles()->role_names[ $role ] ?? ucwords( str_replace( '_', ' ', $role ) ) ); }
		}
		$role = implode( ', ', array_filter( $role_names ) ) ?: \AutoDealership\Core\ProductSetup::t( 'موظف تشغيلي', 'Operations staff' );
		if ( \AutoDealership\Security\BranchScope::is_global( $user->ID ) ) { $scope = \AutoDealership\Core\ProductSetup::t( 'كل الفروع', 'All branches' ); }
		else {
			$assigned = array_map( 'absint', (array) \AutoDealership\Security\BranchScope::assigned_branches( $user->ID ) );
			$names = array();
			foreach ( \AutoDealership\Branches\BranchService::public_list() as $branch ) { if ( in_array( (int) $branch['id'], $assigned, true ) ) { $names[] = $branch['name']; } }
			$scope = $names ? implode( ', ', $names ) : \AutoDealership\Core\ProductSetup::t( 'لا يوجد فرع نشط', 'No active branch' );
		}
		return array( 'role' => $role, 'scope' => $scope );
	}

	private static function area_meta( string $id ): array {
		$meta = array(
			'customers' => array(
				'title' => \AutoDealership\Core\ProductSetup::t( 'مركز العملاء والمبيعات', 'Customers and sales center' ),
				'description' => \AutoDealership\Core\ProductSetup::t( 'تابع الفرص والرسائل والحجوزات وعروض الأسعار ودورة البيع من مسارات مرتبطة دون خلط السجلات التجارية.', 'Follow opportunities, messages, bookings, quotations and the sales journey through connected work lanes without merging business records.' ),
			),
			'inventory' => array(
				'title' => \AutoDealership\Core\ProductSetup::t( 'مركز المركبات والمخزون', 'Vehicles and inventory center' ),
				'description' => \AutoDealership\Core\ProductSetup::t( 'افصل بين تشغيل المركبة وكتالوج الموقع، مع إبقاء الاستلام والفحص والنقل وVIN والصيانة ضمن سياق واحد.', 'Separate operational vehicle work from the website catalog while keeping receiving, inspection, transfers, VIN and maintenance in one context.' ),
			),
			'purchasing' => array(
				'title' => \AutoDealership\Core\ProductSetup::t( 'مركز المشتريات والتوريد', 'Purchasing and sourcing center' ),
				'description' => \AutoDealership\Core\ProductSetup::t( 'تابع أوامر الشراء والموردين واقتناء المركبات وتكاليفها من مسار واحد مع بقاء الاعتمادات مستقلة.', 'Follow purchase orders, suppliers, vehicle acquisition and costs in one workflow while keeping approvals independent.' ),
			),
			'finance' => array(
				'title' => \AutoDealership\Core\ProductSetup::t( 'مركز المالية والتحصيل', 'Finance and collections center' ),
				'description' => \AutoDealership\Core\ProductSetup::t( 'افصل التمويل والمستندات والمدفوعات والاستردادات والعمولات والتصدير حسب الصلاحية الفعلية.', 'Separate finance requests, documents, payments, refunds, commissions and exports according to effective access.' ),
			),
			'reports' => array(
				'title' => \AutoDealership\Core\ProductSetup::t( 'مركز التقارير والتحليل', 'Reporting and analytics center' ),
				'description' => \AutoDealership\Core\ProductSetup::t( 'ابدأ بالملخص التشغيلي ثم انتقل إلى تقارير CRM التفصيلية دون خلط القراءة التحليلية بإجراءات التشغيل.', 'Start with the operational summary, then move to detailed CRM reporting without mixing analytical reading with operational actions.' ),
			),
		);
		return $meta[ $id ] ?? array();
	}

	private static function customer_sections( array $items ): array {
		$frontline = array( 'adc-crm', 'car-dealer-messages', 'car-dealer-bookings', 'adc-quotes', 'adc-sales-journey', 'adc-customer-workspace', 'adc-follow-ups' );
		$service = array( 'adc-conversations', 'adc-portal-operations', 'adc-customer-reviews', 'adc-customer-identities' );
		$sections = array(
			\AutoDealership\Core\ProductSetup::t( 'الفرص والبيع', 'Opportunities and sales' ) => array_filter( $items, static fn( array $item ): bool => in_array( $item['slug'], $frontline, true ) ),
			\AutoDealership\Core\ProductSetup::t( 'التواصل وخدمة العميل', 'Communication and customer service' ) => array_filter( $items, static fn( array $item ): bool => in_array( $item['slug'], $service, true ) ),
		);
		$known = array_merge( $frontline, $service );
		$remaining = array_filter( $items, static fn( array $item ): bool => ! in_array( $item['slug'], $known, true ) );
		if ( $remaining ) { $sections[\AutoDealership\Core\ProductSetup::t( 'أدوات عملاء إضافية', 'Additional customer tools' )] = $remaining; }
		return array_filter( $sections );
	}

	private static function purchasing_sections( array $items ): array {
		$sourcing = array( 'adc-purchase-orders', 'adc-suppliers' );
		$costs = array( 'adc-vehicle-acquisition' );
		return self::sections_by_slugs( $items, array(
			\AutoDealership\Core\ProductSetup::t( 'الشراء والتوريد', 'Purchasing and suppliers' ) => $sourcing,
			\AutoDealership\Core\ProductSetup::t( 'اقتناء المركبات والتكاليف', 'Vehicle acquisition and costs' ) => $costs,
		), \AutoDealership\Core\ProductSetup::t( 'أدوات مشتريات إضافية', 'Additional purchasing tools' ) );
	}

	private static function finance_sections( array $items ): array {
		$money = array( 'adc-onyx-documents', 'adc-payments', 'adc-refunds', 'adc-financial-export' );
		$planning = array( 'adc-finance', 'adc-employee-commissions', 'adc-affiliate-commissions' );
		return self::sections_by_slugs( $items, array(
			\AutoDealership\Core\ProductSetup::t( 'المستندات والمدفوعات', 'Documents and payments' ) => $money,
			\AutoDealership\Core\ProductSetup::t( 'التمويل والعمولات', 'Finance and commissions' ) => $planning,
		), \AutoDealership\Core\ProductSetup::t( 'أدوات مالية إضافية', 'Additional finance tools' ) );
	}

	private static function report_sections( array $items ): array {
		$overview = array( 'adc-operational-reports', 'adc-area-reports' );
		$analysis = array( 'adc-crm-reports' );
		return self::sections_by_slugs( $items, array(
			\AutoDealership\Core\ProductSetup::t( 'الملخص والتشغيل', 'Overview and operations' ) => $overview,
			\AutoDealership\Core\ProductSetup::t( 'تحليل CRM', 'CRM analytics' ) => $analysis,
		), \AutoDealership\Core\ProductSetup::t( 'تقارير إضافية', 'Additional reports' ) );
	}

	private static function sections_by_slugs( array $items, array $definitions, string $remaining_label ): array {
		$known = array();
		$sections = array();
		foreach ( $definitions as $label => $slugs ) {
			$known = array_merge( $known, $slugs );
			$sections[ $label ] = array_filter( $items, static fn( array $item ): bool => in_array( $item['slug'], $slugs, true ) );
		}
		$remaining = array_filter( $items, static fn( array $item ): bool => ! in_array( $item['slug'], $known, true ) );
		if ( $remaining ) { $sections[ $remaining_label ] = $remaining; }
		return array_filter( $sections );
	}

	private static function groups(): array {
		$groups = Navigation::groups();
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		if ( str_starts_with( $page, 'adc-area-' ) ) {
			$groups = array_values( array_filter( $groups, static fn( array $group ): bool => 'adc-area-' . $group['id'] === $page ) );
		}
		return $groups;
	}
}
