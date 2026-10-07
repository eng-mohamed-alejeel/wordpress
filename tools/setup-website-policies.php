<?php
/** Populate local editable policy pages; existing content is backed up before changes. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
if ( untrailingslashit( get_option( 'siteurl' ) ) !== 'http://localhost/wordpress' ) { exit( 2 ); }
$policies = json_decode( file_get_contents( dirname( __DIR__ ) . '/docs/legal/website-policies.json' ), true, 512, JSON_THROW_ON_ERROR );
$backup = array( 'privacy_page' => get_option( 'wp_page_for_privacy_policy' ), 'pages' => array() );
foreach ( $policies as $policy ) {
	$page = get_page_by_path( $policy['slug'], OBJECT, 'page' );
	$backup['pages'][] = array( 'slug' => $policy['slug'], 'post' => $page ? $page->to_array() : null, 'meta' => $page ? get_post_meta( $page->ID ) : array() );
	echo $policy['slug'] . ': ' . ( $page ? $page->ID . ' (' . $page->post_status . ')' : 'new page' ) . "\n";
}
if ( '--inspect' === $argv[1] ) { exit; }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { exit( 3 ); }
wp_set_current_user( (int) $admins[0] );
if ( ! current_user_can( 'publish_pages' ) || ! current_user_can( 'manage_options' ) ) { exit( 3 ); }
$backup_path = dirname( __DIR__ ) . '/.tmp/legal-pages-before-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ) . '.json';
if ( false === file_put_contents( $backup_path, wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), LOCK_EX ) ) { exit( 4 ); }
foreach ( $policies as $policy ) {
	$page = get_page_by_path( $policy['slug'], OBJECT, 'page' );
	$data = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $policy['slug'], 'post_title' => $policy['arTitle'], 'post_content' => wp_kses_post( $policy['ar'] ), 'post_author' => (int) $admins[0], 'comment_status' => 'closed', 'ping_status' => 'closed' );
	if ( $page ) { $data['ID'] = $page->ID; }
	$id = wp_insert_post( wp_slash( $data ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	update_post_meta( $id, '_wp_page_template', 'page-legal.php' );
	update_post_meta( $id, '_adc_title_en', $policy['enTitle'] );
	update_post_meta( $id, '_adc_content_en', wp_kses_post( $policy['en'] ) );
	update_post_meta( $id, '_adc_title_en_source_hash', hash( 'sha256', get_post( $id )->post_title ) );
	update_post_meta( $id, '_adc_content_en_source_hash', hash( 'sha256', get_post( $id )->post_content ) );
	if ( 'privacy-policy' === $policy['slug'] ) { update_option( 'wp_page_for_privacy_policy', $id ); }
	echo "Saved editable page $id: {$policy['slug']}\n";
	// WordPress may auto-add newly published pages to the primary navigation.
	$locations = get_nav_menu_locations();
	if ( ! empty( $locations['primary'] ) ) {
		foreach ( wp_get_nav_menu_items( $locations['primary'] ) ?: array() as $item ) {
			if ( 'page' === $item->object && (int) $item->object_id === (int) $id ) { wp_delete_post( $item->ID, true ); }
		}
	}
}
echo "Backup: $backup_path\n";
