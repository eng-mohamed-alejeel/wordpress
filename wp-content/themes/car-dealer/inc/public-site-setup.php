<?php
/** Portable public content defaults. Existing editorial changes are preserved. */
defined( 'ABSPATH' ) || exit;

function car_dealer_setup_public_site() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	$file = __DIR__ . '/public-site-defaults.json';
	$defaults = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $defaults ) || empty( $defaults['pages'] ) ) { return new WP_Error( 'cd_defaults_missing', 'Public site defaults are unavailable.' ); }
	if ( (int) get_option( 'car_dealer_public_setup_version' ) >= $defaults['version'] ) { return; }
	global $wpdb;
	$lock = 'cd_public_setup_' . md5( DB_NAME . $wpdb->prefix );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return; }
	try {
		// Keep new legal pages out of the primary navigation, including on fresh installs.
		$locations = get_nav_menu_locations();
		$menu_options = (array) get_option( 'nav_menu_options', array() );
		$menu_options['auto_add'] = array_values( array_diff( (array) ( $menu_options['auto_add'] ?? array() ), array( (int) ( $locations['primary'] ?? 0 ) ) ) );
		update_option( 'nav_menu_options', $menu_options );
			$ids = array();
		foreach ( $defaults['pages'] as $definition ) {
			$page = get_page_by_path( $definition['slug'], OBJECT, 'page' );
			$stock_privacy = false;
			if ( $page && 'privacy-policy' === $definition['slug'] && 'draft' === $page->post_status && (int) get_option( 'wp_page_for_privacy_policy' ) === (int) $page->ID ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
				$stock_privacy = trim( $page->post_content ) === trim( WP_Privacy_Policy_Content::get_default_content() );
			}
			if ( ! $page || $stock_privacy ) {
				$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $definition['slug'], 'post_title' => $definition['title'], 'post_content' => wp_kses_post( $definition['content'] ), 'post_author' => get_current_user_id(), 'comment_status' => 'closed', 'ping_status' => 'closed' );
				if ( $stock_privacy ) { $data['ID'] = $page->ID; }
				$id = wp_insert_post( wp_slash( $data ), true );
				if ( is_wp_error( $id ) ) { return $id; }
				update_post_meta( $id, '_wp_page_template', $definition['template'] );
				foreach ( array( 'title', 'content' ) as $field ) {
					$value = 'content' === $field ? wp_kses_post( $definition[$field . '_en'] ) : $definition[$field . '_en'];
					update_post_meta( $id, '_adc_' . $field . '_en', wp_slash( $value ) );
					update_post_meta( $id, '_adc_' . $field . '_en_source_hash', hash( 'sha256', get_post( $id )->{'post_' . $field} ) );
				}
				$page = get_post( $id );
			}
			if ( 'publish' === $page->post_status ) { $ids[$definition['slug']] = (int) $page->ID; }
		}
		if ( isset( $ids['privacy-policy'] ) && ! get_option( 'wp_page_for_privacy_policy' ) ) { update_option( 'wp_page_for_privacy_policy', $ids['privacy-policy'] ); }
		$settings = get_option( 'car_dealer_theme_settings', array() );
		update_option( 'car_dealer_theme_settings', array_merge( $defaults['settings'], is_array( $settings ) ? $settings : array() ) );
		if ( in_array( get_option( 'blogname' ), array( '', 'WordPress', 'ووردبريس' ), true ) ) { update_option( 'blogname', $defaults['site_name'] ); }
		if ( in_array( get_option( 'blogdescription' ), array( '', 'Just another WordPress site', 'موقع آخر في ووردبريس' ), true ) ) { update_option( 'blogdescription', $defaults['tagline'] ); }
		$locations = get_nav_menu_locations();
		foreach ( array( 'primary' => array( 'about', 'finance', 'faq', 'buying-guide', 'contact' ), 'footer' => array( 'privacy-policy', 'terms', 'cookie-policy', 'reservation-policy' ) ) as $location => $slugs ) {
			$menu_id = (int) ( $locations[$location] ?? 0 );
			// A stale location ID may remain after a reinstall; only keep actual menus.
			if ( $menu_id && wp_get_nav_menu_object( $menu_id ) && wp_get_nav_menu_items( $menu_id ) && ! get_term_meta( $menu_id, '_cd_default_menu', true ) ) { continue; }
			if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
				$menu = wp_get_nav_menu_object( 'autobrands-' . $location );
				$menu_id = $menu ? (int) $menu->term_id : wp_create_nav_menu( 'autobrands-' . $location );
				if ( is_wp_error( $menu_id ) ) { return $menu_id; }
				update_term_meta( $menu_id, '_cd_default_menu', 1 );
			}
			$items = wp_get_nav_menu_items( $menu_id ) ?: array();
			$existing_urls = array_column( $items, 'url' );
			$existing_pages = array();
			foreach ( $items as $item ) { if ( 'page' === $item->object ) { $existing_pages[] = (int) $item->object_id; } }
			$position = count( $items ) + 1;
			if ( 'primary' === $location ) {
				foreach ( array( array( 'الرئيسية', home_url( '/' ) ), array( 'كل السيارات', get_post_type_archive_link( 'car' ) ?: home_url( '/cars/' ) ) ) as $link ) {
					if ( in_array( $link[1], $existing_urls, true ) ) { continue; }
					$added = wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => $link[0], 'menu-item-url' => $link[1], 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-position' => $position++ ) );
					if ( is_wp_error( $added ) ) { return $added; }
				}
			}
			foreach ( $slugs as $slug ) {
				if ( ! isset( $ids[$slug] ) || in_array( $ids[$slug], $existing_pages, true ) ) { continue; }
				$added = wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-object-id' => $ids[$slug], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish', 'menu-item-position' => $position++ ) );
				if ( is_wp_error( $added ) ) { return $added; }
			}
			$locations[$location] = (int) $menu_id;
		}
		set_theme_mod( 'nav_menu_locations', $locations );
		update_option( 'car_dealer_public_setup_version', $defaults['version'], false );
		flush_rewrite_rules( false );
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}
add_action( 'admin_init', 'car_dealer_setup_public_site', 30 );
add_action( 'after_switch_theme', 'car_dealer_setup_public_site' );
add_action( 'activated_plugin', 'car_dealer_setup_public_site' );

/** Upgrade only the shipped contact copy, preserving independently edited content. */
function car_dealer_upgrade_contact_copy() {
	if ( ! current_user_can( 'manage_options' ) || get_option( 'car_dealer_contact_copy_version' ) ) { return; }
	$defaults = json_decode( (string) file_get_contents( __DIR__ . '/public-site-defaults.json' ), true );
	foreach ( $defaults['pages'] ?? array() as $definition ) {
		if ( 'contact' !== $definition['slug'] ) { continue; }
		$page = get_page_by_path( 'contact', OBJECT, 'page' );
		if ( ! $page ) { return; }
		$english = get_post_meta( $page->ID, '_adc_content_en', true );
		$ar_matches = hash( 'sha256', $page->post_content ) === ( $definition['previous_content_hash'] ?? '' );
		$en_matches = hash( 'sha256', $english ) === ( $definition['previous_content_en_hash'] ?? '' );
		if ( $ar_matches || $en_matches ) {
			// Keep a recoverable snapshot before migrating the packaged text.
			add_option( 'car_dealer_contact_copy_before_upgrade', array( 'page_id' => $page->ID, 'content' => $page->post_content, 'content_en' => $english ), '', false );
			if ( $ar_matches ) {
				$result = wp_update_post( wp_slash( array( 'ID' => $page->ID, 'post_content' => wp_kses_post( $definition['content'] ) ) ), true );
				if ( is_wp_error( $result ) ) { return; }
			}
			if ( $en_matches ) {
				update_post_meta( $page->ID, '_adc_content_en', wp_slash( wp_kses_post( $definition['content_en'] ) ) );
				update_post_meta( $page->ID, '_adc_content_en_source_hash', hash( 'sha256', get_post( $page->ID )->post_content ) );
			}
		}
		update_option( 'car_dealer_contact_copy_version', 1, false );
	}
}
add_action( 'admin_init', 'car_dealer_upgrade_contact_copy', 31 );
add_action( 'after_switch_theme', 'car_dealer_upgrade_contact_copy', 11 );
add_action( 'activated_plugin', 'car_dealer_upgrade_contact_copy', 11 );
