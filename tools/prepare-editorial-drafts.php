<?php
/** Guarded local editorial preparation; never publishes legal text or invents business approval. */
if ( PHP_SAPI !== 'cli' || 2 !== $argc || ! in_array( $argv[1], array( '--inspect', '--apply' ), true ) ) {
	fwrite( STDERR, "Usage: php tools/prepare-editorial-drafts.php --inspect|--apply\n" );
	exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';

if ( 'wp-autobrands' !== DB_NAME || 'http://localhost/wordpress' !== untrailingslashit( (string) get_option( 'siteurl' ) ) ) {
	fwrite( STDERR, "This operation is limited to the local development site.\n" );
	exit( 2 );
}

$root = dirname( __DIR__ );
$definitions = array(
	'privacy' => array(
		'id' => (int) get_option( 'wp_page_for_privacy_policy' ),
		'slug' => 'privacy-policy',
		'status' => 'draft',
		'expected_hash' => '80ab109942908337309b7bcf07fdcf8d2a545d82761d0225263f0f5c20ce3ac2',
		'file' => $root . '/docs/legal/PRIVACY-DRAFT-AR-EN.html',
	),
	'finance' => array(
		'id' => 5,
		'slug' => 'finance',
		'status' => 'publish',
		'expected_hash' => 'c27ce269296e9f00bd09a7a36aca033f38f8e834932836a9c34475fd705c3957',
		'file' => $root . '/docs/legal/FINANCE-COPY-AR-EN.html',
	),
	'terms' => array(
		'id' => 0,
		'slug' => 'terms',
		'status' => 'draft',
		'expected_hash' => '',
		'file' => $root . '/docs/legal/TERMS-DRAFT-AR-EN.html',
	),
);

$changes = array();
$before = array();
foreach ( $definitions as $key => $definition ) {
	$source = file_get_contents( $definition['file'] );
	if ( false === $source || '' === trim( $source ) || ( 'finance' !== $key && ! str_contains( $source, '[[' ) ) ) {
		fwrite( STDERR, "Missing draft or review placeholders: $key.\n" );
		exit( 3 );
	}
	$content = wp_kses_post( $source );
	$page = $definition['id'] > 0 ? get_post( $definition['id'] ) : get_page_by_path( $definition['slug'], OBJECT, 'page' );
	if ( 'terms' === $key && $page instanceof WP_Post && ( 'draft' !== $page->post_status || 'الشروط والأحكام' !== $page->post_title || $page->post_content !== $content ) ) {
		fwrite( STDERR, "A different terms page already exists; preserving it for manual review.\n" );
		exit( 4 );
	}
	if ( 'terms' !== $key && ( ! $page instanceof WP_Post || 'page' !== $page->post_type || $definition['slug'] !== $page->post_name || $definition['status'] !== $page->post_status ) ) {
		fwrite( STDERR, "Page identity or status changed: $key.\n" );
		exit( 4 );
	}
	if ( $page instanceof WP_Post ) {
		$old_hash = hash( 'sha256', $page->post_content );
		if ( $old_hash !== $definition['expected_hash'] && $page->post_content !== $content ) {
			fwrite( STDERR, "Page was manually edited; preserving it: $key.\n" );
			exit( 4 );
		}
		$before[ $key ] = array( 'id' => $page->ID, 'status' => $page->post_status, 'content' => $page->post_content );
	}
	$changes[ $key ] = array( 'page' => $page, 'content' => $content, 'needed' => ! $page instanceof WP_Post || $page->post_content !== $content );
}

if ( '--inspect' === $argv[1] ) {
	foreach ( $changes as $key => $change ) {
		echo $key . ': ' . ( $change['needed'] ? 'content preparation needed' : 'already prepared' ) . "\n";
	}
	exit( 0 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { fwrite( STDERR, "No administrator is available for draft attribution.\n" ); exit( 5 ); }
wp_set_current_user( (int) $admins[0] );
if ( ! current_user_can( 'manage_options' ) ) { fwrite( STDERR, "Administrator permission check failed.\n" ); exit( 5 ); }

$backup_dir = $root . '/.tmp';
if ( ! is_dir( $backup_dir ) || ! is_writable( $backup_dir ) ) { fwrite( STDERR, "Local backup directory is unavailable.\n" ); exit( 5 ); }
$backup_path = $backup_dir . '/editorial-before-2026-10-03.json';
if ( ! is_file( $backup_path ) && false === file_put_contents( $backup_path, wp_json_encode( $before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) ) {
	fwrite( STDERR, "Cannot save the pre-change editorial backup.\n" );
	exit( 5 );
}

foreach ( $changes as $key => $change ) {
	if ( ! $change['needed'] ) { echo "$key: preserved.\n"; continue; }
	if ( 'terms' === $key ) {
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'terms', 'post_title' => 'الشروط والأحكام', 'post_content' => wp_slash( $change['content'] ), 'post_author' => get_current_user_id() ), true );
	} else {
		$id = wp_update_post( array( 'ID' => $change['page']->ID, 'post_content' => wp_slash( $change['content'] ) ), true );
	}
	$page = is_wp_error( $id ) ? null : get_post( $id );
	if ( ! $page instanceof WP_Post || $page->post_status !== $definitions[ $key ]['status'] || $page->post_name !== $definitions[ $key ]['slug'] || $page->post_content !== $change['content'] ) {
		fwrite( STDERR, "Could not verify the saved draft/content for $key; inspect the site before retrying.\n" );
		exit( 6 );
	}
	echo "$key: prepared as {$page->post_status} (page {$page->ID}).\n";
}
