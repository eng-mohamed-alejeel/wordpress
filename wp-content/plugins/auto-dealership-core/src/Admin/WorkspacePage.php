<?php
namespace AutoDealership\Admin;

defined( 'ABSPATH' ) || exit;

/** Role-aware entry point for every plugin-owned dealership administration area. */
final class WorkspacePage {
	private const RECENT_META = 'adc_workspace_recent_pages';
	private const FAVORITE_META = 'adc_workspace_favorite_pages';
	private const RECENT_LIMIT = 5;
	private const FAVORITE_LIMIT = 6;
	private static function t( string $ar, string $en ): string { return \AutoDealership\Core\ProductSetup::t( $ar, $en ); }

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_init', array( self::class, 'track_recent' ) );
		add_action( 'admin_post_adc_workspace_favorite', array( self::class, 'handle_favorite' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		add_action( 'customize_controls_enqueue_scripts', array( self::class, 'enqueue_customizer' ) );
	}

	/** Record only an allowed destination slug; no record, customer, or filter data is stored. */
	public static function track_recent(): void {
		if ( ! is_user_logged_in() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) { return; }
		if ( 'admin.php' !== basename( (string) ( $_SERVER['PHP_SELF'] ?? '' ) ) ) { return; }
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'adc-' ) ) { return; }
		$item = self::find_item( $page );
		if ( ! $item || ! self::allowed( $item ) ) { return; }
		$recent = self::meta_list( self::RECENT_META );
		if ( ( $recent[0] ?? '' ) === $page ) { return; }
		$recent = array_values( array_diff( $recent, array( $page ) ) );
		array_unshift( $recent, $page );
		self::save_meta( self::RECENT_META, array_slice( $recent, 0, self::RECENT_LIMIT ) );
	}

	/** Toggle an allowed destination through a nonce-protected admin-post request. */
	public static function handle_favorite(): void {
		if ( ! current_user_can( 'read' ) ) { wp_die( esc_html__( 'You cannot change workspace favorites.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		$slug = isset( $_POST['slug'] ) && is_scalar( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['slug'] ) ) : '';
		$item = self::find_item( $slug );
		if ( ! $item || ! self::allowed( $item ) ) { wp_die( esc_html__( 'This workspace destination is not available to your account.', 'auto-dealership-core' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'adc_workspace_favorite_' . $slug );
		$favorites = self::meta_list( self::FAVORITE_META );
		$index = array_search( $slug, $favorites, true );
		if ( false !== $index ) {
			array_splice( $favorites, $index, 1 );
			$notice = 'favorite_removed';
		} else {
			array_unshift( $favorites, $slug );
			$favorites = array_slice( $favorites, 0, self::FAVORITE_LIMIT );
			$notice = 'favorite_added';
		}
		self::save_meta( self::FAVORITE_META, $favorites );
		$redirect = wp_get_referer() ?: WorkspaceOverview::url();
		$redirect = add_query_arg( 'workspace_notice', $notice, $redirect );
		wp_safe_redirect( $redirect );
		exit;
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
		$groups = self::prioritize_groups( self::groups() );
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
			<?php if ( ! $area_allowed ) : self::render_access_context( $context, $groups ); endif; ?>
			<?php if ( ! $area_allowed ) : self::render_workspace_tools( $groups ); endif; ?>
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
                        <span><strong><?php echo esc_html( $group['title'] ); ?></strong><small><?php echo esc_html( sprintf( \AutoDealership\Core\ProductSetup::t( '%d أداة مسموحة · فتح القسم', '%d permitted tools · Open area' ), count( $items ) ) ); ?></small></span>
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
					<?php $section_items = self::prioritize_items( $section_items ); ?>
					<?php foreach ( $section_items as $item ) : ?>
							<div class="adc-workspace-card-wrap">
							<a class="adc-workspace-card" href="<?php echo esc_url( self::url( $item['path'] ) ); ?>">
								<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
								<strong><?php echo esc_html( $item['title'] ); ?></strong>
								<?php if ( $item['description'] ) : ?><small><?php echo esc_html( $item['description'] ); ?></small><?php endif; ?>
								<small class="adc-access-mode"><?php echo esc_html( self::access_mode( $item ) ); ?></small>
							</a>
							<?php self::render_favorite_form( $item ); ?>
							</div>
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

	private static function render_workspace_tools( array $groups ): void {
		$items = self::available_items( $groups );
		$quick = self::quick_actions( $items );
		$favorites = self::items_for_slugs( self::meta_list( self::FAVORITE_META ), $items );
		$recent = self::items_for_slugs( self::meta_list( self::RECENT_META ), $items );
		$notice = isset( $_GET['workspace_notice'] ) && is_scalar( $_GET['workspace_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['workspace_notice'] ) ) : '';
		$messages = array(
			'favorite_added'   => self::t( 'أضيفت الصفحة إلى المفضلة.', 'Destination added to favorites.' ),
			'favorite_removed' => self::t( 'أزيلت الصفحة من المفضلة.', 'Destination removed from favorites.' ),
		);
		if ( $notice && isset( $messages[ $notice ] ) ) { echo '<div class="notice notice-success inline adc-workspace-notice" role="status"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>'; }
		if ( ! $quick && ! $favorites && ! $recent ) { return; }
		echo '<section class="adc-workspace-tools" aria-labelledby="adc-workspace-tools-heading"><div class="adc-workspace-tools-heading"><div><span class="adc-workspace-tools-kicker">' . esc_html( self::t( 'الوصول اليومي', 'Daily access' ) ) . '</span><h2 id="adc-workspace-tools-heading">' . esc_html( self::t( 'ابدأ من مكان واحد', 'Start from one place' ) ) . '</h2><p>' . esc_html( self::t( 'إجراءات مختصرة حسب صلاحيات حسابك، مع حفظ آخر الصفحات التي فتحتها دون تخزين بيانات العمل.', 'Permission-aware shortcuts and recent destinations. Only page identifiers are stored; work data is not.' ) ) . '</p></div></div>';
		if ( $quick ) {
			echo '<div class="adc-quick-actions" aria-labelledby="adc-quick-actions-heading"><h3 id="adc-quick-actions-heading">' . esc_html( self::t( 'إجراءات سريعة', 'Quick actions' ) ) . '</h3><div class="adc-quick-actions-grid">';
			foreach ( $quick as $action ) {
				echo '<a class="adc-quick-action" href="' . esc_url( $action['href'] ) . '"><span class="dashicons ' . esc_attr( $action['icon'] ) . '" aria-hidden="true"></span><span><strong>' . esc_html( $action['title'] ) . '</strong><small>' . esc_html( $action['description'] ) . '</small></span><span class="adc-quick-action-arrow" aria-hidden="true">&#8250;</span></a>';
			}
			echo '</div></div>';
		}
		if ( $favorites || $recent ) {
			echo '<div class="adc-workspace-shortcuts">';
			if ( $favorites ) { self::render_shortcut_group( self::t( 'المفضلة', 'Favorites' ), $favorites, 'favorites' ); }
			if ( $recent ) { self::render_shortcut_group( self::t( 'آخر ما استخدمته', 'Recently used' ), $recent, 'recent' ); }
			echo '</div>';
		}
		echo '</section>';
	}

	private static function render_shortcut_group( string $title, array $items, string $key ): void {
		echo '<section class="adc-workspace-shortcut-group" data-shortcut-group="' . esc_attr( $key ) . '" aria-labelledby="adc-shortcut-' . esc_attr( $key ) . '"><h3 id="adc-shortcut-' . esc_attr( $key ) . '">' . esc_html( $title ) . '</h3><div class="adc-workspace-shortcut-list">';
		foreach ( $items as $item ) {
			echo '<a class="adc-workspace-shortcut" href="' . esc_url( self::url( $item['path'] ) ) . '"><span class="dashicons ' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span><span><strong>' . esc_html( $item['title'] ) . '</strong><small>' . esc_html( $item['description'] ?: self::t( 'فتح الصفحة المسموحة لهذا الحساب.', 'Open the permitted destination for this account.' ) ) . '</small></span></a>';
		}
		echo '</div></section>';
	}

	private static function render_favorite_form( array $item ): void {
		$is_favorite = in_array( $item['slug'], self::meta_list( self::FAVORITE_META ), true );
		$label = $is_favorite ? self::t( 'إزالة من المفضلة', 'Remove from favorites' ) : self::t( 'إضافة إلى المفضلة', 'Add to favorites' );
		echo '<form class="adc-workspace-favorite-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adc_workspace_favorite"><input type="hidden" name="slug" value="' . esc_attr( $item['slug'] ) . '">';
		wp_nonce_field( 'adc_workspace_favorite_' . $item['slug'] );
		echo '<button type="submit" class="adc-workspace-favorite" aria-label="' . esc_attr( $label . ': ' . $item['title'] ) . '" aria-pressed="' . ( $is_favorite ? 'true' : 'false' ) . '" title="' . esc_attr( $label ) . '"><span aria-hidden="true">' . ( $is_favorite ? '★' : '☆' ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></button></form>';
	}

	private static function available_items( array $groups ): array {
		$items = array();
		foreach ( $groups as $group ) {
			foreach ( $group['items'] as $item ) {
				if ( self::allowed( $item ) && ! isset( $items[ $item['slug'] ] ) ) { $items[ $item['slug'] ] = $item; }
			}
		}
		return $items;
	}

	private static function items_for_slugs( array $slugs, array $items ): array {
		$out = array();
		foreach ( $slugs as $slug ) { if ( isset( $items[ $slug ] ) ) { $out[] = $items[ $slug ]; } }
		return $out;
	}

	private static function quick_actions( array $items ): array {
		$definitions = array(
			array( 'slug'=>'adc-follow-ups', 'ar'=>'المتابعات المستحقة', 'en'=>'Due follow-ups', 'description_ar'=>'ابدأ بالأعمال التي تحتاج متابعة الآن.', 'description_en'=>'Start with work that needs attention now.', 'href'=>WorkspaceOverview::url( array( 'workspace_task'=>'overdue' ) ) ),
			array( 'slug'=>'adc-crm', 'ar'=>'فرص العملاء', 'en'=>'Customer opportunities', 'description_ar'=>'تابع الفرص والأنشطة ضمن نطاقك.', 'description_en'=>'Follow opportunities and activities within your scope.' ),
			array( 'slug'=>'adc-approvals', 'ar'=>'الاعتمادات', 'en'=>'Approvals', 'description_ar'=>'راجع الخصومات والمبيعات التي تنتظر قرارك.', 'description_en'=>'Review discounts and sales waiting for your decision.' ),
			array( 'slug'=>'adc-inventory', 'ar'=>'إدارة السيارات', 'en'=>'Vehicle management', 'description_ar'=>'تابع حالة المركبات وبياناتها التشغيلية.', 'description_en'=>'Manage vehicle status and operational details.' ),
			array( 'slug'=>'adc-payments', 'ar'=>'تأكيدات السداد', 'en'=>'Payment confirmations', 'description_ar'=>'راجع الدفعات والعربون المسموحين لك.', 'description_en'=>'Review permitted payments and deposits.' ),
			array( 'slug'=>'adc-delivery', 'ar'=>'تجهيز وتسليم', 'en'=>'Preparation and delivery', 'description_ar'=>'تابع المركبات الجاهزة لخطوة التسليم.', 'description_en'=>'Follow vehicles moving through delivery.' ),
			array( 'slug'=>'adc-operational-reports', 'ar'=>'التقارير التشغيلية', 'en'=>'Operational reports', 'description_ar'=>'افتح المؤشرات المجمعة ضمن نطاقك.', 'description_en'=>'Open aggregated indicators within your scope.' ),
		);
		$actions = array();
		foreach ( $definitions as $definition ) {
			if ( ! isset( $items[ $definition['slug'] ] ) ) { continue; }
			$item = $items[ $definition['slug'] ];
			$actions[] = array(
				'href'        => $definition['href'] ?? self::url( $item['path'] ),
				'title'       => self::t( $definition['ar'], $definition['en'] ),
				'description' => self::t( $definition['description_ar'], $definition['description_en'] ),
				'icon'        => $item['icon'],
			);
			if ( count( $actions ) >= 4 ) { break; }
		}
		return $actions;
	}

	private static function meta_list( string $key ): array {
		$raw = get_user_meta( get_current_user_id(), $key, true );
		if ( ! is_array( $raw ) ) { return array(); }
		$out = array();
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) { continue; }
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value && ! in_array( $value, $out, true ) ) { $out[] = $value; }
		}
		$limit = self::FAVORITE_META === $key ? self::FAVORITE_LIMIT : self::RECENT_LIMIT;
		return array_slice( $out, 0, $limit );
	}

	private static function save_meta( string $key, array $values ): void {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) { update_user_meta( $user_id, $key, array_values( array_unique( array_filter( $values, 'is_string' ) ) ) ); }
	}

	private static function find_item( string $slug ): ?array {
		foreach ( Navigation::groups() as $group ) {
			foreach ( $group['items'] as $item ) { if ( $item['slug'] === $slug ) { return $item; } }
		}
		return null;
	}

	private static function prioritize_groups( array $groups ): array {
		$decorated = array();
		foreach ( $groups as $index => $group ) { $decorated[] = array( 'index'=>$index, 'score'=>self::group_priority( $group['id'], $index ), 'group'=>$group ); }
		usort( $decorated, static fn( array $a, array $b ): int => $a['score'] <=> $b['score'] ?: $a['index'] <=> $b['index'] );
		return array_values( array_map( static fn( array $entry ): array => $entry['group'], $decorated ) );
	}

	private static function group_priority( string $id, int $fallback ): int {
		$signals = array(
			'customers' => current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'adc_view_conversations' ),
			'inventory' => current_user_can( 'adc_view_inventory' ) || current_user_can( 'adc_manage_inventory' ),
			'purchasing' => current_user_can( 'adc_view_purchase_orders' ) || current_user_can( 'adc_view_suppliers' ) || current_user_can( 'adc_view_vehicle_costs' ),
			'approvals' => current_user_can( 'adc_review_discounts' ) || current_user_can( 'adc_approve_sales' ) || current_user_can( 'adc_cancel_sales' ),
			'finance' => current_user_can( 'adc_view_finance' ),
			'delivery' => current_user_can( 'adc_approve_delivery' ) || current_user_can( 'adc_confirm_vehicle_vin' ),
			'reports' => current_user_can( 'adc_view_reports' ) || current_user_can( 'adc_view_campaign_metrics' ),
			'content' => current_user_can( 'edit_pages' ) || current_user_can( 'edit_cars' ),
			'marketing' => current_user_can( 'adc_view_campaign_metrics' ) || current_user_can( 'adc_view_marketing_subscribers' ),
			'settings' => current_user_can( 'manage_options' ),
			'audit' => current_user_can( 'adc_view_audit' ) || current_user_can( 'adc_view_integrations' ),
		);
		return ! empty( $signals[ $id ] ) ? 10 : 100 + $fallback;
	}

	private static function prioritize_items( array $items ): array {
		$order = array( 'adc-follow-ups', 'adc-crm', 'adc-approvals', 'adc-inventory', 'adc-onyx', 'adc-inventory-identity', 'adc-payments', 'adc-finance', 'adc-delivery', 'adc-operational-reports' );
		$decorated = array();
		foreach ( $items as $index => $item ) { $decorated[] = array( 'index'=>$index, 'score'=>array_search( $item['slug'], $order, true ), 'item'=>$item ); }
		usort( $decorated, static function ( array $a, array $b ): int { $left = false === $a['score'] ? 999 : $a['score']; $right = false === $b['score'] ? 999 : $b['score']; return $left <=> $right ?: $a['index'] <=> $b['index']; } );
		return array_values( array_map( static fn( array $entry ): array => $entry['item'], $decorated ) );
	}

	private static function render_access_context( array $context, array $groups ): void {
		$status_class = 'active' === $context['status']['key'] ? 'is-active' : 'is-warning';
		echo '<section class="adc-access-context" data-adc-access-context="1" aria-labelledby="adc-access-context-heading">';
		echo '<div class="adc-access-context-heading"><div><span class="adc-access-context-kicker">' . esc_html( \AutoDealership\Core\ProductSetup::t( 'وضوح الوصول', 'Access clarity' ) ) . '</span><h2 id="adc-access-context-heading">' . esc_html( \AutoDealership\Core\ProductSetup::t( 'ما الذي يمكنك رؤيته وإدارته؟', 'What can you see and manage?' ) ) . '</h2><p>' . esc_html( \AutoDealership\Core\ProductSetup::t( 'هذه بطاقة قراءة فقط تلخص نطاق الحساب الحالي. الحماية الفعلية تُراجع مرة أخرى عند فتح كل صفحة وتنفيذ كل إجراء.', 'This read-only card summarizes the current account scope. Effective authorization is checked again when each page and action is used.' ) ) . '</p></div><span class="adc-access-context-status ' . esc_attr( $status_class ) . '">' . esc_html( $context['status']['label'] ) . '</span></div>';
		echo '<div class="adc-access-context-grid">';
		self::context_card( 'role', \AutoDealership\Core\ProductSetup::t( 'الدور الفعال', 'Effective role' ), $context['role'], \AutoDealership\Core\ProductSetup::t( 'الصلاحيات الموروثة من أدوار الحساب.', 'Permissions inherited from the account roles.' ) );
		self::context_card( 'primary-branch', \AutoDealership\Core\ProductSetup::t( 'الفرع الأساسي', 'Primary branch' ), $context['primary_branch'], \AutoDealership\Core\ProductSetup::t( 'يُستخدم كنقطة العمل الافتراضية عند توفره.', 'Used as the default work point when available.' ) );
		self::context_card( 'additional-branches', \AutoDealership\Core\ProductSetup::t( 'الفروع الإضافية', 'Additional branches' ), $context['additional_branches'], \AutoDealership\Core\ProductSetup::t( 'تظل خاضعة لحالة الفرع وسياسة السجل.', 'Still subject to branch status and record policy.' ) );
		self::context_card( 'ownership', \AutoDealership\Core\ProductSetup::t( 'ملكية السجلات', 'Record ownership' ), $context['ownership'], \AutoDealership\Core\ProductSetup::t( 'لا يمنح تعيين الفرع صلاحية إجراء بحد ذاته.', 'Branch assignment does not grant an action permission by itself.' ) );
		self::context_card( 'status', \AutoDealership\Core\ProductSetup::t( 'حالة الحساب', 'Account status' ), $context['status']['label'], $context['status']['detail'] );
		echo '</div>';
		echo '<details class="adc-access-permissions"><summary>' . esc_html( \AutoDealership\Core\ProductSetup::t( 'الصلاحيات الحالية حسب الوحدة', 'Current permissions by module' ) ) . '</summary><p class="description">' . esc_html( \AutoDealership\Core\ProductSetup::t( 'تظهر هنا الوحدات المسموحة فقط، مع وصف آمن لنوع الوصول دون عرض أسماء الصلاحيات الداخلية.', 'Only permitted modules are shown, with a safe access description instead of internal capability names.' ) ) . '</p><div class="adc-access-permission-groups">';
		foreach ( $groups as $group ) {
			$items = array_values( array_filter( $group['items'], static fn( array $item ): bool => self::allowed( $item ) ) );
			if ( ! $items ) { continue; }
			echo '<section class="adc-access-permission-group"><h3>' . esc_html( $group['title'] ) . '</h3><ul>';
			foreach ( $items as $item ) {
				echo '<li><span>' . esc_html( $item['title'] ) . '</span><strong class="adc-access-mode">' . esc_html( self::access_mode( $item ) ) . '</strong></li>';
			}
			echo '</ul></section>';
		}
		echo '</div></details></section>';
	}

	private static function context_card( string $key, string $label, string $value, string $detail ): void {
		echo '<div class="adc-access-context-card" data-context-key="' . esc_attr( $key ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong><small>' . esc_html( $detail ) . '</small></div>';
	}

	private static function access_mode( array $item ): string {
		if ( current_user_can( 'manage_options' ) ) { return \AutoDealership\Core\ProductSetup::t( 'إدارة كاملة', 'Full administration' ); }
		$capabilities = array_merge( (array) $item['capabilities'], self::related_capabilities( (string) $item['slug'] ) );
		$effective = array_values( array_filter( array_unique( $capabilities ), static fn( $capability ): bool => is_string( $capability ) && current_user_can( $capability ) ) );
		if ( ! $effective ) { return \AutoDealership\Core\ProductSetup::t( 'وصول مسموح', 'Permitted access' ); }
		$approves = false; $manages = false; $reads = false;
		foreach ( $effective as $capability ) {
			$name = strtolower( $capability );
			$approves = $approves || (bool) preg_match( '/approve|review|confirm|moderate|cancel/', $name );
			$manages = $manages || (bool) preg_match( '/manage|edit|publish|upload|assign|reply|close|process|change|delete/', $name );
			$reads = $reads || (bool) preg_match( '/view|read|list/', $name );
		}
		$labels = array();
		if ( $manages ) { $labels[] = \AutoDealership\Core\ProductSetup::t( 'إنشاء/تعديل', 'Create/edit' ); }
		if ( $approves ) { $labels[] = \AutoDealership\Core\ProductSetup::t( 'مراجعة/اعتماد', 'Review/approve' ); }
		if ( $reads && ! $manages && ! $approves ) { $labels[] = \AutoDealership\Core\ProductSetup::t( 'قراءة', 'Read' ); }
		return $labels ? implode( ' · ', $labels ) : \AutoDealership\Core\ProductSetup::t( 'وصول مسموح', 'Permitted access' );
	}

	private static function related_capabilities( string $slug ): array {
		$map = array(
			'adc-crm' => array( 'adc_manage_own_leads', 'adc_manage_branch_leads', 'adc_assign_marketing_leads', 'adc_export_crm_leads', 'adc_add_marketing_activity' ),
			'car-dealer-messages' => array( 'adc_reply_conversations', 'adc_close_conversations' ),
			'car-dealer-bookings' => array( 'adc_create_reservations', 'adc_manage_reservations' ),
			'adc-conversations' => array( 'adc_reply_conversations', 'adc_close_conversations' ),
			'adc-quotes' => array( 'adc_create_quotes', 'adc_request_discounts', 'adc_review_discounts', 'adc_approve_sales' ),
			'adc-sales-journey' => array( 'adc_create_quotes', 'adc_request_discounts', 'adc_create_reservations', 'adc_manage_reservations', 'adc_record_payments', 'adc_approve_sales', 'adc_start_sales' ),
			'adc-customer-workspace' => array( 'adc_manage_own_leads', 'adc_manage_branch_leads', 'adc_create_quotes', 'adc_create_reservations' ),
		);
		return $map[ $slug ] ?? array();
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
		$global = \AutoDealership\Security\BranchScope::is_global( $user->ID );
		$assigned = array_map( 'absint', (array) \AutoDealership\Security\BranchScope::assigned_branches( $user->ID ) );
		$primary_id = \AutoDealership\Security\BranchScope::assigned_branch( $user->ID );
		$branch_names = array();
		foreach ( \AutoDealership\Branches\BranchService::public_list() as $branch ) { $branch_names[ (int) $branch['id'] ] = (string) $branch['name']; }
		$label_for = static function ( int $branch_id ) use ( $branch_names ): string { return $branch_names[ $branch_id ] ?? sprintf( \AutoDealership\Core\ProductSetup::t( 'فرع غير نشط #%d', 'Inactive branch #%d' ), $branch_id ); };
		$active_assigned = array_values( array_filter( $assigned, static fn( int $branch_id ): bool => \AutoDealership\Security\BranchScope::is_active( $branch_id ) ) );
		$scope = $global ? \AutoDealership\Core\ProductSetup::t( 'كل الفروع', 'All branches' ) : ( $active_assigned ? implode( ', ', array_map( $label_for, $active_assigned ) ) : \AutoDealership\Core\ProductSetup::t( 'لا يوجد فرع نشط', 'No active branch' ) );
		$primary = $global ? \AutoDealership\Core\ProductSetup::t( 'كل الفروع', 'All branches' ) : ( $primary_id ? $label_for( $primary_id ) : \AutoDealership\Core\ProductSetup::t( 'غير معين', 'Not assigned' ) );
		$additional_ids = array_values( array_diff( $assigned, array( $primary_id ) ) );
		$additional = $global ? \AutoDealership\Core\ProductSetup::t( 'غير مطبق — وصول شامل', 'Not applicable — global access' ) : ( $additional_ids ? implode( ', ', array_map( $label_for, $additional_ids ) ) : \AutoDealership\Core\ProductSetup::t( 'لا توجد فروع إضافية', 'No additional branches' ) );
		if ( $global ) { $ownership = \AutoDealership\Core\ProductSetup::t( 'كل السجلات التي تسمح بها سياسة الوحدة', 'All records allowed by the module policy' ); }
		elseif ( current_user_can( 'adc_view_branch_leads' ) || current_user_can( 'adc_manage_branch_leads' ) ) { $ownership = \AutoDealership\Core\ProductSetup::t( 'سجلات الفروع النشطة المعينة حسب الوحدة', 'Records in assigned active branches, subject to the module policy' ); }
		elseif ( current_user_can( 'adc_view_own_leads' ) || current_user_can( 'adc_manage_own_leads' ) ) { $ownership = \AutoDealership\Core\ProductSetup::t( 'سجلاتك داخل الفروع النشطة المعينة', 'Your records inside assigned active branches' ); }
		elseif ( $active_assigned ) { $ownership = \AutoDealership\Core\ProductSetup::t( 'سجلات الوحدة ضمن الفروع النشطة المعينة', 'Module records within assigned active branches' ); }
		else { $ownership = \AutoDealership\Core\ProductSetup::t( 'لا توجد سجلات حتى تعيين فرع نشط', 'No records until an active branch is assigned' ); }
		$suspended = \AutoDealership\Security\AccessPolicy::suspended( $user->ID );
		$status = array( 'key' => $suspended ? 'warning' : 'active', 'label' => $suspended ? \AutoDealership\Core\ProductSetup::t( 'موقوف', 'Suspended' ) : \AutoDealership\Core\ProductSetup::t( 'نشط', 'Active' ), 'detail' => $suspended ? \AutoDealership\Core\ProductSetup::t( 'تم تعطيل الوصول حتى يراجع المسؤول الحساب.', 'Access is disabled until an administrator reviews the account.' ) : ( $active_assigned || $global ? \AutoDealership\Core\ProductSetup::t( 'الوصول متاح وفق الدور والنطاق النشط.', 'Access is available according to the role and active scope.' ) : \AutoDealership\Core\ProductSetup::t( 'الحساب نشط لكن لا يوجد نطاق فرعي نشط حاليًا.', 'The account is active but has no active branch scope currently.' ) ) );
		return array( 'role' => $role, 'scope' => $scope, 'primary_branch' => $primary, 'additional_branches' => $additional, 'ownership' => $ownership, 'status' => $status );
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
