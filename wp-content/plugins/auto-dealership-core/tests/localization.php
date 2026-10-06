<?php
/** Offline regression for admin/public/AJAX language boundaries and workflow labels. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['test_admin'] = false;
$GLOBALS['test_ajax'] = false;
$GLOBALS['test_locale'] = 'ar';
$GLOBALS['test_filters'] = array();
function is_admin() { return $GLOBALS['test_admin']; }
function wp_doing_ajax() { return $GLOBALS['test_ajax']; }
function get_user_locale() { return $GLOBALS['test_locale']; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_unslash( $value ) { return $value; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['test_filters'][$hook][$priority][] = $callback; }
function add_action( $hook, $callback ) { $GLOBALS['test_actions'][$hook][] = $callback; }
function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['test_menus'][$slug] = array( $page_title, $menu_title );
}
function apply_filters( $hook, $value, ...$args ) {
	$filters = $GLOBALS['test_filters'][$hook] ?? array(); ksort( $filters );
	foreach ( $filters as $callbacks ) { foreach ( $callbacks as $callback ) { $value = $callback( $value, ...$args ); } }
	return $value;
}
function __( $text, $domain = '' ) {
	$translation = apply_filters( 'gettext', $text, $text, $domain );
	return apply_filters( 'gettext_' . $domain, $translation, $text, $domain );
}
require dirname( __DIR__ ) . '/src/Inventory/CatalogPresentation.php';
require dirname( __DIR__ ) . '/src/Core/Localization.php';
require dirname( __DIR__, 3 ) . '/themes/car-dealer/inc/public-catalog.php';
require dirname( __DIR__, 3 ) . '/themes/car-dealer/inc/localization.php';
\AutoDealership\Core\Localization::boot();
require dirname( __DIR__ ) . '/src/Admin/CustomerIdentityPage.php';
\AutoDealership\Admin\CustomerIdentityPage::boot();
function check( $condition, $description ) {
	if ( ! $condition ) { throw new RuntimeException( $description ); }
	echo 'PASS ' . $description . PHP_EOL;
}
$_GET = array(); $_REQUEST = array();
$GLOBALS['test_admin'] = true;
foreach ( $GLOBALS['test_actions']['admin_menu'] as $callback ) { $callback(); }
check( array( 'مراجعة ملفات العملاء', 'مراجعة ملفات العملاء' ) === $GLOBALS['test_menus']['adc-customer-identities'], 'Customer review page and menu titles use Arabic admin locale' );
check( 'التمويل' === __( 'Finance', 'auto-dealership-core' ), 'English source labels translate in Arabic admin' );
check( 'قيد الانتظار' === \AutoDealership\Core\Localization::label( 'pending' ), 'Stored workflow keys have Arabic display labels' );
check( 'قيد الفحص' === __( 'inspection', 'auto-dealership-core' ), 'Literal option keys have Arabic display labels' );
check( 'في الطريق' === \AutoDealership\Core\Localization::label( 'in_transit' ), 'Transfer status has Arabic display copy' );
$GLOBALS['test_locale'] = 'en_US'; $_GET['lang'] = 'ar';
foreach ( $GLOBALS['test_actions']['admin_menu'] as $callback ) { $callback(); }
check( array( 'Review customer records', 'Review customer records' ) === $GLOBALS['test_menus']['adc-customer-identities'], 'Customer review page and menu titles use English admin locale' );
check( 'Quotations' === __( 'عروض الأسعار', 'auto-dealership-core' ), 'Admin follows user locale independently of public query language' );
check( 'Pending' === \AutoDealership\Core\Localization::label( 'pending' ), 'Workflow labels have readable English copy' );
check( 'Inspection' === __( 'inspection', 'auto-dealership-core' ), 'English options do not expose lowercase internal keys' );
check( 'Partially refunded' === \AutoDealership\Core\Localization::label( 'partially_refunded' ), 'Financial status has readable English display copy' );
check( 'Save settings' === __( 'حفظ الإعدادات', 'car-dealer' ), 'Theme settings follow English admin locale' );
check( 'Finance' === __( 'Finance', 'unrelated-domain' ), 'Other translation domains are untouched' );
$GLOBALS['test_admin'] = false; $_GET = array();
check( 'ar' === \AutoDealership\Core\Localization::language(), 'Public Arabic default is independent of administrator locale' );
check( 'تويوتا' === car_dealer_catalog_value_label( 'Toyota' ), 'Known vehicle brands localize for Arabic display' );
check( 'بنزين' === car_dealer_catalog_value_label( 'gasoline' ), 'Internal specification keys localize in Arabic' );
$_GET = array( 'lang'=>'en' );
check( 'Gasoline' === car_dealer_catalog_value_label( 'بنزين' ), 'Arabic specification values localize in English' );
check( 'Toyota' === car_dealer_catalog_value_label( 'تويوتا' ), 'Known Arabic brands localize in English' );
check( 'Front-wheel drive' === __( 'دفع أمامي', 'auto-dealership-core' ), 'Dynamic Arabic specification labels translate' );
check( 'Calculating…' === __( 'جارٍ الحساب…', 'auto-dealership-core' ), 'Public calculator messages localize' );
check( 'أحمد العميل' === car_dealer_catalog_value_label( 'أحمد العميل' ), 'Unknown customer or editorial content is preserved' );
$GLOBALS['test_admin'] = true; $GLOBALS['test_ajax'] = true; $GLOBALS['test_locale'] = 'ar'; $_REQUEST = array( 'lang'=>'en' );
check( 'The comparison could not be updated.' === __( 'تعذر تحديث المقارنة.', 'auto-dealership-core' ), 'AJAX response follows request language rather than admin locale' );
$_REQUEST = array( 'lang'=>array('en') );
check( 'ar' === \AutoDealership\Core\Localization::language(), 'Non-scalar language input falls back safely' );
echo "Localization regression passed.\n";
