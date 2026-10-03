<?php
/** Prepare owner-supplied editorial copy as separate local drafts. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
if ( 'wp-autobrands' !== DB_NAME || 'http://localhost/wordpress' !== untrailingslashit( get_option( 'siteurl' ) ) ) { fwrite( STDERR, "Local development site required.\n" ); exit( 2 ); }
$bundle = json_decode( file_get_contents( dirname( __DIR__ ) . '/docs/launch-content/editorial.ar-en.json' ), true, 512, JSON_THROW_ON_ERROR );
if ( 'owner_facts_confirmed_editorial_review' !== $bundle['status'] ) { exit( 3 ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { exit( 4 ); }
wp_set_current_user( (int) $admins[0] );
if ( ! current_user_can( 'manage_options' ) ) { exit( 4 ); }
foreach ( $bundle['pages'] as $definition ) {
	$slug = 'autobrands-review-' . sanitize_title( $definition['slug'] );
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		if ( 'draft' !== $existing->post_status || 'autobrands-owner-content-2026-10-03' !== get_post_meta( $existing->ID, '_adc_content_preparation', true ) ) { fwrite( STDERR, "Existing page preserved; conflict: $slug\n" ); exit( 5 ); }
		echo "Preserved existing review draft: $slug ({$existing->ID}).\n";
		continue;
	}
	if ( '--inspect' === $argv[1] ) { echo "Missing draft: $slug\n"; continue; }
	$result = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => $slug, 'post_title' => $definition['ar']['title'] . ' — مراجعة أوتو براندز', 'post_content' => wp_kses_post( $definition['ar']['content'] ), 'post_author' => get_current_user_id(), 'meta_input' => array( '_adc_title_en' => $definition['en']['title'], '_adc_content_en' => wp_kses_post( $definition['en']['content'] ), '_adc_content_preparation' => 'autobrands-owner-content-2026-10-03', '_wp_page_template' => 'default' ) ) ), true );
	if ( is_wp_error( $result ) ) { fwrite( STDERR, $result->get_error_code() . "\n" ); exit( 6 ); }
	$saved = get_post( $result );
	if ( ! $saved || 'draft' !== $saved->post_status || $saved->post_content !== wp_kses_post( $definition['ar']['content'] ) || get_post_meta( $result, '_adc_content_en', true ) !== wp_kses_post( $definition['en']['content'] ) ) { fwrite( STDERR, "Draft verification failed.\n" ); exit( 7 ); }
	echo "Created bilingual draft: $slug ($result).\n";
}
echo "Published pages, business transactions, branches and policy settings unchanged.\n";
