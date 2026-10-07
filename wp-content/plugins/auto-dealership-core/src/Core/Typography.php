<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Shared typography for the public site, WordPress administration and editors. */
final class Typography {
	private static bool $booted = false;
	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		foreach ( array( 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'login_enqueue_scripts', 'enqueue_block_assets' ) as $hook ) {
			add_action( $hook, array( self::class, 'enqueue' ), 100 );
		}
		add_filter( 'mce_css', array( self::class, 'classic_editor_css' ) );
	}
	public static function stylesheet_url(): string {
		return plugins_url( 'auto-dealership-core/assets/css/typography.css' );
	}
	public static function enqueue(): void {
		// Core Customizer controls/media dialogs keep WordPress's native typography.
		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && 'customize' === $screen->base ) { return; }
		}
		$file = dirname( __DIR__, 2 ) . '/assets/css/typography.css';
		wp_enqueue_style( 'autobrands-tajawal', self::stylesheet_url(), array(), (string) filemtime( $file ) );
	}
	public static function classic_editor_css( string $css ): string {
		return $css . ( '' !== $css ? ',' : '' ) . self::stylesheet_url();
	}
}
