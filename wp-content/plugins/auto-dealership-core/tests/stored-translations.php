<?php
/** Offline regressions for source-bound copies, authorization and audited rollback. */
namespace AutoDealership\Core {
	final class Localization { public static function language(): string { return $GLOBALS['language']; } public static function label( string $text ): string { return $text; } }
}
namespace AutoDealership\Database {
	final class Schema { public static function table( string $type ): string { return $type; } }
	final class Transaction {
		public static function begin(): bool { $GLOBALS['snapshot'] = $GLOBALS['options']; return true; }
		public static function commit( callable $audit ): bool { if ( $audit() ) { return true; } $GLOBALS['wpdb']->query( 'ROLLBACK' ); return false; }
	}
}
namespace AutoDealership\Audit {
	final class AuditLog { public static function record( ...$args ): bool { $GLOBALS['audit_count']++; return $GLOBALS['audit_ok']; } }
}
namespace AutoDealership\Inventory {
	final class CatalogPresentation { public static function language(): string { return $GLOBALS['language']; } }
}
namespace {
	define( 'ABSPATH', __DIR__ ); define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['language'] = 'en'; $GLOBALS['allowed'] = true; $GLOBALS['audit_ok'] = true; $GLOBALS['audit_count'] = 0; $GLOBALS['options'] = array();
	function current_user_can( ...$args ) { return $GLOBALS['allowed']; }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
	function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][$key] = $value; return true; }
	function wp_cache_delete( ...$args ) {}
	function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
	function __( $value, $domain ) { return $value; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	class WP_Error { public function __construct( public string $code, public string $message ) {} }
	class WP_Post { public int $ID = 10; public string $post_type = 'car'; public string $post_title = 'سيارة تجريبية'; public string $post_content = '<p>وصف السيارة</p>'; }
	$GLOBALS['post'] = new WP_Post(); $GLOBALS['meta'] = array();
	function is_admin() { return false; }
	function wp_doing_ajax() { return false; }
	function get_post( $id ) { return 10 === $id ? $GLOBALS['post'] : null; }
	function get_post_type( $id ) { return get_post( $id )?->post_type; }
	function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
	function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; return true; }
	function is_singular( $types ) { return in_array( $GLOBALS['post']->post_type, $types, true ); }
	function get_the_ID() { return 10; }
	function get_queried_object_id() { return 10; }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function strip_shortcodes( $value ) { return $value; }
	function wp_trim_words( $value, $length ) { return $value; }
	$GLOBALS['wpdb'] = new class {
		public array $row = array( 'id' => 1, 'name' => 'فرع الرياض', 'city' => 'الرياض', 'address' => '' );
		public function prepare( $sql, ...$args ) { return $sql; }
		public function get_row( ...$args ) { return $this->row; }
		public function query( $sql ) { if ( 'ROLLBACK' === $sql ) { $GLOBALS['options'] = $GLOBALS['snapshot']; } return 1; }
	};
	require dirname( __DIR__ ) . '/src/Content/StoredTranslations.php';
	use AutoDealership\Content\StoredTranslations as T;
	function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new \RuntimeException( $label ); } echo 'PASS ' . $label . "\n"; }
	$hash = T::fingerprint( 'فرع الرياض' );
	check( 'Branch #1' === T::text( 'branches', 1, 'name', 'فرع الرياض', true ), 'Untranslated public branch has an English fallback label' );
	check( 'فرع الرياض' === T::text( 'branches', 1, 'name', 'فرع الرياض' ), 'Untranslated originals remain available to staff' );
	check( true === T::save( 'branches', 1, 'en', array( 'name' => 'Riyadh branch' ), array( 'name' => $hash ) ), 'Reviewed copy saves successfully' );
	check( 'Riyadh branch' === T::text( 'branches', 1, 'name', 'فرع الرياض', true ), 'Approved copy displays in English' );
	check( '' === T::approved( 'branches', 2, 'name', 'فرع الرياض', 'en' ), 'Identical source text in another record does not share its copy' );
	check( '' === T::approved( 'branches', 1, 'name', 'فرع جديد', 'en' ), 'Changed source invalidates old translation' );
	check( 'فرع الرياض' === $GLOBALS['wpdb']->row['name'], 'Saving a translation preserves the business original' );
	$GLOBALS['language'] = 'ar';
	check( 'فرع الرياض' === T::text( 'branches', 1, 'name', 'فرع الرياض' ), 'Arabic display remains original' );
	$GLOBALS['language'] = 'en'; $GLOBALS['allowed'] = false;
	check( is_wp_error( T::save( 'branches', 1, 'en', array(), array() ) ), 'Non-administrator writes are rejected' );
	$GLOBALS['allowed'] = true;
	check( is_wp_error( T::save( 'customers', 1, 'en', array(), array() ) ), 'Customer identities cannot be translated through this service' );
	check( is_wp_error( T::save( 'branches', 1, 'en', array( 'name' => 'Wrong' ), array( 'name' => 'stale' ) ) ), 'Stale editor submissions are rejected' );
	check( is_wp_error( T::save( 'branches', 1, 'en', array( 'tax_number' => 'Wrong' ), array( 'tax_number' => $hash ) ) ), 'Fields outside the whitelist cannot be saved' );
	check( is_wp_error( T::save( 'branches', 1, 'en', array( 'name' => 'نص عربي' ), array( 'name' => $hash ) ) ), 'Arabic text cannot be approved as an English translation' );
	$before = $GLOBALS['audit_count'];
	check( true === T::save( 'branches', 1, 'en', array( 'name' => 'Riyadh branch' ), array( 'name' => $hash ) ) && $before === $GLOBALS['audit_count'], 'Repeated identical saves do not generate duplicate audit entries' );
	$GLOBALS['audit_ok'] = false;
	check( is_wp_error( T::save( 'branches', 1, 'en', array( 'name' => 'Replacement' ), array( 'name' => $hash ) ) ), 'Audit failure rejects translation write' );
	check( 'Riyadh branch' === T::text( 'branches', 1, 'name', 'فرع الرياض' ), 'Audit failure restores the previous approved copy' );
	$GLOBALS['audit_ok'] = true;
	check( true === T::save( 'branches', 1, 'en', array( 'name' => '' ), array( 'name' => $hash ) ) && '' === T::approved( 'branches', 1, 'name', 'فرع الرياض', 'en' ), 'Clearing a copy removes its approval' );
	require dirname( __DIR__ ) . '/src/Content/PublicEditorialTranslations.php';
	$editorial = '\AutoDealership\Content\PublicEditorialTranslations';
	check( 'Content #10' === $editorial::title( 'سيارة تجريبية', 10 ), 'Untranslated public title has an English fallback' );
	check( false === strpos( $editorial::content( '<p>وصف السيارة</p>' ), 'وصف السيارة' ), 'Missing English description never exposes Arabic fallback content' );
	update_post_meta( 10, '_adc_title_en', 'Demo vehicle' ); update_post_meta( 10, '_adc_content_en', '<p>Demo description</p>' );
	check( 'Demo vehicle' === $editorial::title( 'سيارة تجريبية', 10 ), 'Existing reviewed English title remains compatible' );
	check( '<p>Demo description</p>' === $editorial::content( '<p>وصف السيارة</p>' ), 'English description replaces the original only during display' );
	check( 'Demo description' === $editorial::excerpt( 'ملخص عربي', $GLOBALS['post'] ), 'English excerpts derive from English content' );
	$before = clone $GLOBALS['post']; $GLOBALS['post']->post_title = 'عنوان معدّل'; $GLOBALS['post']->post_content = '<p>وصف معدّل</p>';
	$editorial::capture_legacy_source( 10, $GLOBALS['post'], $before );
	check( '' === $editorial::approved( 10, '_adc_title_en' ) && '' === $editorial::approved( 10, '_adc_content_en' ), 'Editing legacy originals invalidates their old English copies' );
	check( 'Content #10' === $editorial::title( 'عنوان معدّل', 10 ), 'Outdated title copy is not shown publicly' );
	$GLOBALS['language'] = 'ar';
	check( 'عنوان معدّل' === $editorial::title( 'عنوان معدّل', 10 ) && '<p>وصف معدّل</p>' === $editorial::content( '<p>وصف معدّل</p>' ), 'Arabic title and content remain intact after English copy expires' );
	 echo "Stored translation regression passed.\n";
}
