<?php
/** Guarded local update for the public Finance Calculator page. */
if ( PHP_SAPI !== 'cli' || 2 !== $argc || ! in_array( $argv[1], array( '--inspect', '--apply' ), true ) ) {
	fwrite( STDERR, "Usage: php tools/update-finance-page.php --inspect|--apply\n" );
	exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';

if ( 'wp-autobrands' !== DB_NAME || 'http://localhost/wordpress' !== untrailingslashit( (string) get_option( 'siteurl' ) ) ) {
	fwrite( STDERR, "This update is limited to the local development site.\n" );
	exit( 2 );
}

$page = get_page_by_path( 'finance', OBJECT, 'page' );
$source = file_get_contents( dirname( __DIR__ ) . '/docs/legal/FINANCE-COPY-AR-EN.html' );
if ( ! $page instanceof WP_Post || 5 !== (int) $page->ID || 'publish' !== $page->post_status || false === $source ) {
	fwrite( STDERR, "The expected published finance page or its source copy is missing.\n" );
	exit( 3 );
}

$content = wp_kses_post( $source );
$title = 'حاسبة التمويل';
$english = 'Finance Calculator';
$previous_hash = 'c27ce269296e9f00bd09a7a36aca033f38f8e834932836a9c34475fd705c3957';
$stored_english = (string) get_post_meta( $page->ID, '_adc_title_en', true );
if ( ! in_array( $page->post_title, array( 'التمويل', $title ), true )
	|| ! in_array( hash( 'sha256', $page->post_content ), array( $previous_hash, hash( 'sha256', $content ) ), true )
	|| ! in_array( $stored_english, array( '', $english ), true )
	|| ! str_contains( $content, '[car_dealer_loan_calculator]' )
	|| ! str_contains( $content, '[car_dealer_contact_form]' ) ) {
	fwrite( STDERR, "The finance page has manual changes; review it before applying this update.\n" );
	exit( 4 );
}

$menu_items = array();
foreach ( wp_get_nav_menus() as $menu ) {
	foreach ( wp_get_nav_menu_items( $menu ) ?: array() as $item ) {
		if ( 'post_type' !== $item->type || 'page' !== $item->object || (int) $item->object_id !== (int) $page->ID ) {
			continue;
		}
		$raw_item = get_post( $item->ID );
		if ( $raw_item instanceof WP_Post && in_array( $raw_item->post_title, array( '', 'التمويل', $title ), true ) ) {
			$menu_items[ $item->ID ] = $raw_item->post_title;
		}
	}
}

$needed = $page->post_title !== $title || $page->post_content !== $content || $stored_english !== $english || in_array( 'التمويل', $menu_items, true );
if ( '--inspect' === $argv[1] ) {
	echo $needed ? "Finance page update needed.\n" : "Finance page already updated.\n";
	exit( 0 );
}
if ( ! $needed ) {
	echo "Finance page already updated.\n";
	exit( 0 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { fwrite( STDERR, "No administrator is available.\n" ); exit( 5 ); }
wp_set_current_user( (int) $admins[0] );
if ( ! current_user_can( 'edit_post', $page->ID ) ) { fwrite( STDERR, "Administrator cannot edit the finance page.\n" ); exit( 5 ); }

$backup = dirname( __DIR__ ) . '/.tmp/finance-page-before-2026-10-03.json';
if ( ! is_file( $backup ) && false === file_put_contents( $backup, wp_json_encode( array(
	'id' => $page->ID,
	'title' => $page->post_title,
	'content' => $page->post_content,
	'title_en' => $stored_english,
	'menu_titles' => $menu_items,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), LOCK_EX ) ) {
	fwrite( STDERR, "Cannot write the local page backup.\n" );
	exit( 6 );
}

$result = wp_update_post( array( 'ID' => $page->ID, 'post_title' => $title, 'post_content' => wp_slash( $content ) ), true );
if ( is_wp_error( $result ) || (int) $result !== (int) $page->ID ) {
	fwrite( STDERR, "Could not update the finance page.\n" );
	exit( 7 );
}
if ( ! update_post_meta( $page->ID, '_adc_title_en', $english ) && $english !== get_post_meta( $page->ID, '_adc_title_en', true ) ) {
	fwrite( STDERR, "Could not save the English finance page title.\n" );
	exit( 7 );
}
foreach ( $menu_items as $item_id => $menu_title ) {
	if ( 'التمويل' === $menu_title ) {
		$updated = wp_update_post( array( 'ID' => $item_id, 'post_title' => $title ), true );
		if ( is_wp_error( $updated ) ) { fwrite( STDERR, "Could not update a finance menu label.\n" ); exit( 7 ); }
	}
}

$saved = get_post( $page->ID );
if ( ! $saved instanceof WP_Post || $saved->post_title !== $title || $saved->post_content !== $content || $english !== get_post_meta( $page->ID, '_adc_title_en', true ) ) {
	fwrite( STDERR, "The saved finance page differs from its source.\n" );
	exit( 8 );
}
echo "Finance page {$page->ID} updated; slug /finance/ preserved.\n";
