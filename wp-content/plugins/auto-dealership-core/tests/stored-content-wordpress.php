<?php
/** Read-only integration checks against the local development WordPress installation. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( 'http://localhost/wordpress' !== untrailingslashit( get_option( 'siteurl' ) ) ) { exit( 2 ); }
use AutoDealership\Content\StoredTranslations as T;
use AutoDealership\Content\PublicEditorialTranslations as E;
function adc_check_translation( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo 'PASS ' . $label . "\n"; }
$_GET['lang'] = 'en';
$post = get_page_by_path( 'adc-demo-04', OBJECT, 'car' );
adc_check_translation( $post instanceof WP_Post, 'Demo vehicle is available' );
adc_check_translation( false !== strpos( get_the_title( $post ), '[Demo]' ) && ! preg_match( '/\p{Arabic}/u', get_the_title( $post ) ), 'Stored Arabic vehicle title renders in English' );
adc_check_translation( false !== strpos( E::approved( $post->ID, '_adc_content_en' ), 'system development' ), 'Reviewed English vehicle description exists' );
adc_check_translation( $post->post_title !== get_the_title( $post ), 'Public translation leaves the stored original intact' );
$vehicle = \AutoDealership\Inventory\PublicCatalog::vehicle_for_post( $post->ID );
adc_check_translation( false !== strpos( $vehicle['branch_name'], 'Demo' ) && ! preg_match( '/\p{Arabic}/u', $vehicle['warranty'] ), 'Operational branch and warranty use reviewed English text' );
$options = \AutoDealership\Inventory\PublicCatalog::filter_options();
foreach ( $options['branches'] as $branch ) { adc_check_translation( ! preg_match( '/\p{Arabic}/u', $branch['name'] ), 'Public branch filter uses English labels' ); }
$term = get_term_by( 'slug', 'demo-sedan', 'car_category' );
adc_check_translation( '[Demo] Sedan' === $term->name, 'Taxonomy name renders in English' );
adc_check_translation( 'demo-sedan' === $term->slug, 'Taxonomy filter slug is stable' );
$_GET['lang'] = 'ar';
adc_check_translation( $post->post_title === get_the_title( $post ), 'Arabic vehicle title remains the original' );
$arabic = \AutoDealership\Inventory\PublicCatalog::vehicle_for_post( $post->ID );
adc_check_translation( 'فرع الرياض التجريبي' === $arabic['branch_name'], 'Public cache separates English and Arabic display' );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admins[0]->ID );
set_current_screen( 'dealership_page_adc-stored-translations' );
foreach ( array( 'ar', 'en_US' ) as $locale ) {
	wp_get_current_user()->locale = $locale;
	$_GET = array( 'page' => 'adc-stored-translations', 'entity' => 'branches' );
	ob_start(); \AutoDealership\Admin\StoredTranslationsPage::render(); $html = ob_get_clean();
	adc_check_translation( ! preg_match( '/(?:Fatal error|Warning:|Notice:)/', $html ), 'Translation screen renders cleanly: ' . $locale );
	adc_check_translation( false !== strpos( $html, 'en_US' === $locale ? 'Stored data translations' : 'ترجمات البيانات المخزّنة' ), 'Translation screen heading follows staff locale: ' . $locale );
	adc_check_translation( false !== strpos( $html, 'Demo Riyadh branch' ), 'Translation screen displays saved copy: ' . $locale );
	adc_check_translation( false !== strpos( $html, 'فرع الرياض التجريبي' ), 'Review screen retains original for comparison: ' . $locale );
}
foreach ( array_keys( T::FIELDS ) as $type ) {
	$_GET = array( 'page' => 'adc-stored-translations', 'entity' => $type );
	ob_start(); \AutoDealership\Admin\StoredTranslationsPage::render(); $html = ob_get_clean();
	adc_check_translation( '' === $wpdb->last_error && ! preg_match( '/(?:Fatal error|Warning:|Notice:)/', $html ), 'Record translation review supports ' . $type );
}
foreach ( array(
	array( array( \AutoDealership\Admin\VehicleIssuePage::class, 'render' ), 'Demo tire inspection; repairs are required before listing.' ),
	array( array( \AutoDealership\Admin\SupplierPage::class, 'render' ), 'Demo local vehicle supplier' ),
	array( array( \AutoDealership\Admin\ReferencePage::class, 'render' ), 'Demo showroom RYD' ),
	array( array( \AutoDealership\Admin\WorkflowPages::class, 'finance' ), 'Demo finance provider' ),
) as list( $callback, $expected ) ) {
	ob_start(); $callback(); $html = ob_get_clean();
	adc_check_translation( '' === $wpdb->last_error && ! preg_match( '/(?:Fatal error|Warning:|Notice:)/', $html ), 'Operational display renders cleanly: ' . $callback[0] );
	adc_check_translation( false !== strpos( $html, $expected ), 'Operational display uses stored English copy: ' . $expected );
}
echo "WordPress stored content checks passed.\n";
