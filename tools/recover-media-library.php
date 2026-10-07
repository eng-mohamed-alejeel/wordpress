<?php
/** Register surviving original images after database recovery, without duplicating assets. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { exit( 2 ); }
wp_set_current_user( (int) $admins[0] );
$uploads = wp_upload_dir();
if ( $uploads['error'] ) { throw new RuntimeException( $uploads['error'] ); }
$seen = array();
foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	$file = get_attached_file( $id );
	if ( is_file( $file ) ) { $seen[hash_file( 'sha256', $file )] = $id; }
}
$report = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads['basedir'], FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $entry ) {
	if ( ! $entry->isFile() || $entry->isLink() || ! preg_match( '/\.(png|jpe?g|webp|gif)$/i', $entry->getFilename() ) || preg_match( '/-(?:\d+x\d+|scaled|rotated)\.[^.]+$/i', $entry->getFilename() ) ) { continue; }
	$file = wp_normalize_path( $entry->getPathname() );
	$image = @getimagesize( $file );
	if ( ! $image ) { continue; }
	$hash = hash_file( 'sha256', $file );
	if ( isset( $seen[$hash] ) ) { $report[] = array( 'file' => $entry->getFilename(), 'status' => 'duplicate', 'id' => $seen[$hash] ); continue; }
	$relative = ltrim( str_replace( '\\', '/', substr( $file, strlen( $uploads['basedir'] ) ) ), '/' );
	$id = 0;
	if ( '--apply' === $argv[1] ) {
		$id = wp_insert_attachment( wp_slash( array( 'post_title' => pathinfo( $entry->getFilename(), PATHINFO_FILENAME ), 'post_mime_type' => $image['mime'], 'post_status' => 'inherit', 'post_author' => get_current_user_id(), 'guid' => trailingslashit( $uploads['baseurl'] ) . $relative ) ), $file, 0, true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		update_post_meta( $id, '_wp_attached_file', $relative );
		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( empty( $metadata['width'] ) ) { throw new RuntimeException( 'Image metadata could not be generated; original file preserved.' ); }
		$metadata['file'] = $relative;
		wp_update_attachment_metadata( $id, $metadata );
	}
	$seen[$hash] = $id;
	$report[] = array( 'file' => $relative, 'status' => $id ? 'registered' : 'missing', 'id' => $id, 'width' => $image[0], 'height' => $image[1] );
}
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
