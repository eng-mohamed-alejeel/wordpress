<?php
/** Real WordPress menu registration order; no database writes or login changes. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'WP_ADMIN', true ); define( 'DISABLE_WP_CRON', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { throw new RuntimeException( 'Administrator required.' ); }
wp_set_current_user( (int) $admins[0] );
wp_get_current_user()->locale = 'en' === ( $argv[1] ?? '' ) ? 'en_US' : 'ar';
$menu = array(); $submenu = array(); $admin_page_hooks = array(); $_registered_pages = array(); $_parent_pages = array();
foreach ( $wp_filter['admin_menu']->callbacks as $callbacks ) {
	foreach ( $callbacks as $entry ) {
		$callback = $entry['function'];
		$is_settings = is_array( $callback ) && \AutoDealership\Admin\SettingsPage::class === $callback[0];
		$is_translations = $callback instanceof Closure && str_ends_with( str_replace( '\\', '/', (new ReflectionFunction( $callback ))->getFileName() ), '/Admin/StoredTranslationsPage.php' );
		if ( $is_settings || $is_translations ) { $callback(); }
	}
}
$hook = get_plugin_page_hookname( 'adc-stored-translations', 'adc-settings' );
$plugin_page = 'adc-stored-translations'; $pagenow = 'admin.php';
if ( empty( $_registered_pages[$hook] ) || ! has_action( $hook, array( \AutoDealership\Admin\StoredTranslationsPage::class, 'render' ) ) || $hook !== get_plugin_page_hook( 'adc-stored-translations', 'admin.php' ) ) { throw new RuntimeException( 'Translation screen is not registered under its final parent menu hook: ' . $hook ); }
if ( ! user_can_access_admin_page() ) { throw new RuntimeException( 'Administrator cannot access translation route.' ); }
$_GET = array( 'page' => $plugin_page );
ob_start(); do_action( $hook ); $html = ob_get_clean();
if ( ! str_contains( $html, 'name="page" value="adc-stored-translations"' ) || preg_match( '/(?:Fatal error|Warning:|Notice:)/', $html ) ) { throw new RuntimeException( 'Registered route did not render the translation screen.' ); }
echo "PASS Registered admin route renders the translation screen.\n";
echo 'PASS Translation route registers the correct parent hook: ' . $hook . "\n";
echo 'PASS Translation URL: ' . admin_url( 'admin.php?page=adc-stored-translations' ) . "\n";
