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
		return self::is_plugin_screen() ? $classes . ' adc-admin' : ( self::is_content_screen() ? $classes . ' adc-admin-native' : $classes );
	}

	private static function is_content_screen(): bool {
		$screen = get_current_screen();
		return $screen && in_array( $screen->post_type, array( 'car', 'car_offer' ), true ) && in_array( $screen->base, array( 'edit', 'edit-tags', 'term', 'post' ), true );
	}

	public static function enqueue(): void {
		if ( self::is_content_screen() ) {
			wp_enqueue_style( 'adc-admin-native', plugins_url( 'assets/css/admin-native.css', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin-native.css' ) );
		}
		if ( ! self::is_plugin_screen() ) {
			return;
		}
		wp_enqueue_style( 'adc-admin', plugins_url( 'assets/css/admin.css', ADC_FILE ), array(), (string) filemtime( dirname( ADC_FILE ) . '/assets/css/admin.css' ) );
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
		?>
		<div class="wrap adc-workspace" dir="<?php echo 'en' === \AutoDealership\Core\Localization::language() ? 'ltr' : 'rtl'; ?>">
			<header class="adc-workspace-hero">
				<div>
					<span><?php esc_html_e( 'إدارة المعرض', 'auto-dealership-core' ); ?></span>
					<h1><?php echo esc_html( $area_allowed ? $groups[0]['title'] : sprintf( __( 'مرحبًا، %s', 'auto-dealership-core' ), $user->display_name ) ); ?></h1>
					<p><?php esc_html_e( 'تظهر الوحدات المتاحة وفق صلاحيات دورك ونطاق عملك الحالي.', 'auto-dealership-core' ); ?></p>
				</div>
				<span class="dashicons dashicons-car" aria-hidden="true"></span>
			</header>

			<?php foreach ( $groups as $group ) : ?>
				<?php $items = array_values( array_filter( $group['items'], static fn( array $item ): bool => self::allowed( $item ) ) ); ?>
				<?php if ( ! $items ) { continue; } ?>
				<section class="adc-workspace-section">
					<?php if ( ! $area_allowed ) : ?><h2><?php echo esc_html( $group['title'] ); ?></h2><?php endif; ?>
					<?php
					$sections = array( '' => $items );
					if ( 'inventory' === $group['id'] ) {
						$sections = array(
							'المركبات التشغيلية' => array_filter( $items, static fn( array $item ): bool => ! str_contains( $item['path'], 'post_type=car' ) ),
							'كتالوج الموقع' => array_filter( $items, static fn( array $item ): bool => str_contains( $item['path'], 'post_type=car' ) ),
						);
					}
					foreach ( $sections as $section_title => $section_items ) :
						if ( ! $section_items ) { continue; }
					?>
					<?php if ( $section_title ) : ?><h3><?php echo esc_html__( $section_title, 'auto-dealership-core' ); ?></h3><?php endif; ?>
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
		</div>
		<?php
	}

	private static function allowed( array $item ): bool {
		return Navigation::allowed( $item );
	}

	private static function url( string $path ): string {
		return admin_url( $path );
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
