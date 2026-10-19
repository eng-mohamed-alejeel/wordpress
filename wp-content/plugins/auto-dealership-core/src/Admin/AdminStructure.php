<?php
namespace AutoDealership\Admin;

use AutoDealership\Core\ProductSetup;

defined( 'ABSPATH' ) || exit;

/** Canonical administration destinations; retired GET links never render a second editor. */
final class AdminStructure {
	public static function boot(): void {
		// WordPress checks menu access before admin_init; retired slugs must redirect first.
		add_action( 'admin_menu', array( self::class, 'redirect' ), 1 );
		add_action( 'admin_init', array( self::class, 'redirect' ), 1 );
		add_action( 'customize_register', array( self::class, 'customize' ), 100 );
		add_action( 'admin_head-options-general.php', array( self::class, 'general_identity' ) );
		foreach ( array( 'blogname', 'blogdescription', 'timezone_string' ) as $option ) {
			add_filter( 'pre_update_option_' . $option, array( self::class, 'preserve_identity' ), 10, 2 );
		}
		add_action( 'admin_enqueue_scripts', static function (): void {
			$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
			if ( str_starts_with( $page, 'adc-' ) ) {
				wp_enqueue_style( 'adc-admin-structure', plugins_url( 'assets/css/admin-structure.css', ADC_FILE ), array( 'adc-admin' ), ADC_VERSION );
			}
		} );
	}

	/** Pure routing also used by acceptance tests. POST operations keep their nonce and handler. */
	public static function destination( string $page, string $section = '', string $tab = '', ?string $legacy_search = null ): array {
		if ( 'adc-follow-ups' === $page && null !== $legacy_search ) { return array( 'page'=>'adc-workspace', 'workspace_task'=>'overdue', 'workspace_search'=>$legacy_search ); }
		if ( 'adc-transfer-queue' === $page ) { return array( 'page'=>'adc-transfers' ); }
		if ( 'adc-platform-setup' === $page ) {
			if ( 'appearance' === $section ) { return array( 'page'=>'adc-contact' ); }
			if ( 'policy' === $section ) { return array( 'page'=>'adc-settings', 'section'=>'reservations' ); }
			if ( 'pages' === $section ) { return array( 'page'=>'adc-editorial-setup' ); }
		}
		if ( 'adc-site-content' === $page && 'readiness' === $tab ) { return array( 'page'=>'adc-platform-setup', 'section'=>'readiness' ); }
		return array();
	}

	public static function redirect(): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || wp_doing_ajax() ) { return; }
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		$section = isset( $_GET['section'] ) && is_string( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : '';
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		$legacy_search = isset( $_GET['search'] ) && is_scalar( $_GET['search'] ) ? wp_unslash( (string) $_GET['search'] ) : null;
		$target = self::destination( $page, $section, $tab, $legacy_search );
		// Legacy native parent URLs move to the single plugin-owned sidebar entry.
		global $pagenow;
		if ( 'index.php' === $pagenow && ! is_network_admin() && ! is_user_admin() && current_user_can( 'adc_view_workspace' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=adc-workspace' ) );
			exit;
		}
		if ( ! $target && 'options-general.php' === $pagenow && in_array( $page, array( 'adc-platform-setup', 'adc-social-channels', 'adc-branch-locations', 'adc-brand-colors' ), true ) ) {
			$target = array( 'page'=>$page );
			if ( 'adc-platform-setup' === $page && $section ) { $target['section'] = $section; }
		}
		if ( ! $target ) { return; }
		$cap = 'adc-follow-ups' === $page ? 'adc_view_workspace' : ( 'adc-transfer-queue' === $page ? 'adc_transfer_inventory' : 'manage_options' );
		if ( ! current_user_can( $cap ) ) { wp_die( esc_html( ProductSetup::t( 'غير مسموح.', 'Access denied.' ) ), '', array( 'response'=>403 ) ); }
		$context = array_intersect_key( $_GET, array_flip( array( 'saved', 'error', 'paged', 'q', 'status', 'created', 'preserved', 'preview' ) ) );
		$context = array_filter( $context, 'is_scalar' );
		wp_safe_redirect( add_query_arg( array_merge( $context, $target ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Core keeps its technical settings; identity fields link to their bilingual owner. */
	public static function general_identity(): void {
		if ( ! current_user_can( 'manage_options' ) || ! empty( $_GET['page'] ) ) { return; }
		$url = admin_url( 'admin.php?page=adc-platform-setup' );
		$label = ProductSetup::t( 'تعديل هوية المنشأة واللغة', 'Edit business identity and language' );
		echo '<style>.form-table tr:has(#blogname),.form-table tr:has(#blogdescription),.form-table tr:has(#timezone_string){display:none}</style>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){["blogname","blogdescription","timezone_string"].forEach(function(id){const field=document.getElementById(id);if(!field)return;const row=field.closest("tr");if(row)row.hidden=true;field.setAttribute("aria-hidden","true");field.tabIndex=-1;});});</script>';
		add_action( 'admin_notices', static function () use ( $url, $label ): void {
			echo '<div class="notice notice-info"><p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p></div>';
		} );
	}

	/** A stale core General form must not overwrite identity edited in its canonical screen. */
	public static function preserve_identity( $value, $old ) {
		global $pagenow;
		return 'options.php' === $pagenow && 'general' === ( $_POST['option_page'] ?? '' ) ? $old : $value;
	}

	public static function customize( $manager ): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		require_once ADC_PATH . 'src/Admin/SettingsOwnerControl.php';
		$groups = array(
			'car_dealer_contact'=>array( 'adc-contact', ProductSetup::t( 'بيانات التواصل', 'Contact details' ), array( 'phone', 'email', 'address', 'address_en' ) ),
			'car_dealer_social'=>array( 'adc-social-channels', ProductSetup::t( 'حسابات التواصل الاجتماعي', 'Social accounts' ), array_keys( \AutoDealership\Core\SocialChannels::platforms() ) ),
			'title_tagline'=>array( 'adc-platform-setup', ProductSetup::t( 'هوية المنشأة واللغة', 'Business identity and language' ), array( 'description_en' ) ),
		);
		foreach ( $groups as $section=>$group ) {
			foreach ( $group[2] as $key ) { $manager->remove_control( 'car_dealer_theme_settings[' . $key . ']' ); }
			if ( 'title_tagline' === $section ) { $manager->remove_control( 'blogname' ); $manager->remove_control( 'blogdescription' ); }
			if ( 'car_dealer_social' === $section ) { $manager->remove_control( \AutoDealership\Core\SocialChannels::OPTION ); }
			if ( ! $manager->get_section( $section ) ) { $manager->add_section( $section, array( 'title'=>$group[1], 'priority'=>37 ) ); }
			$manager->add_control( new SettingsOwnerControl( $manager, 'adc-owner-' . $section, array( 'settings'=>array(), 'section'=>$section, 'label'=>$group[1], 'description'=>admin_url( 'admin.php?page=' . $group[0] ) ) ) );
		}
	}

	/** Read-only links to specialist verification owners; never activates a provider or a worker. */
	public static function verification_sources(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$rows = array();
		if ( current_user_can( 'adc_view_integrations' ) ) {
			$routes = \AutoDealership\Integrations\IntegrationRegistry::catalogue();
			$ready = count( array_filter( $routes, static fn( array $route ): bool => ! empty( $route['ready'] ) ) );
			$rows[] = array( 'adc-integrations', ProductSetup::t( 'جاهزية مسارات التكامل', 'Integration route readiness' ), $ready . ' / ' . count( $routes ), ProductSetup::t( 'جاهزية تقنية؛ التفعيل وقبول المزود يراجعان داخل وحدة التكاملات.', 'Technical readiness; review activation and provider acceptance in Integrations.' ) );
		}
		if ( current_user_can( 'adc_view_outbox' ) ) {
			$rows[] = array( 'adc-outbox', ProductSetup::t( 'العامل والمهام والبريد', 'Worker, jobs and mail' ), ProductSetup::t( 'سجل تشغيل حي', 'Live execution log' ), ProductSetup::t( 'تفاصيل آخر تشغيل ومصدره والمهام العالقة تُقرأ من مراقبة المهام.', 'Read the latest worker run, its source and stalled jobs in Job monitoring.' ) );
		}
		$approved = \AutoDealership\Operations\ReleaseEvidence::approved( home_url(), ADC_VERSION );
		global $wpdb;
		$state = $wpdb->last_error ? ProductSetup::t( 'تعذر التحميل', 'Could not load' ) : count( $approved ) . ' / ' . count( \AutoDealership\Operations\ReleaseEvidence::GATES );
		$rows[] = array( 'adc-release-evidence', ProductSetup::t( 'أدلة اعتماد النشر', 'Deployment approval evidence' ), $state, ProductSetup::t( 'أدلة معتمدة وسارية لهذه البيئة وهذا الإصدار؛ لا تستبدلها الفحوص الآلية.', 'Valid approved evidence for this environment and release; automated checks do not replace it.' ) );
		echo '<h2>' . esc_html( ProductSetup::t( 'التحقق التشغيلي واعتماد النشر', 'Operational verification and deployment approval' ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html( ProductSetup::t( 'المصدر المختص', 'Responsible source' ) ) . '</th><th>' . esc_html( ProductSetup::t( 'الحالة', 'Status' ) ) . '</th><th>' . esc_html( ProductSetup::t( 'النطاق', 'Scope' ) ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th><a href="' . esc_url( admin_url( 'admin.php?page=' . $row[0] ) ) . '">' . esc_html( $row[1] ) . '</a></th><td>' . esc_html( $row[2] ) . '</td><td>' . esc_html( $row[3] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
