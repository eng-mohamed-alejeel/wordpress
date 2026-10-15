<?php
/** Remove only callbacks owned by the old product MU implementation, without editing files. */
defined( 'ABSPATH' ) || exit;
if ( ! function_exists( 'adc_product_remove_legacy_mail_hooks' ) ) {
	function adc_product_remove_legacy_mail_hooks( string $filename, array $hooks ): void {
		$legacy = realpath( WPMU_PLUGIN_DIR . '/' . $filename );
		if ( ! $legacy ) { return; }
		global $wp_filter;
		foreach ( $hooks as $hook ) {
			foreach ( ( $wp_filter[$hook]->callbacks ?? array() ) as $priority=>$callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];
					if ( $fn instanceof Closure ) { $reflection = new ReflectionFunction( $fn ); if ( $reflection->getFileName() && realpath( $reflection->getFileName() ) === $legacy ) { remove_filter( $hook, $fn, $priority ); } }
				}
			}
		}
	}
}
