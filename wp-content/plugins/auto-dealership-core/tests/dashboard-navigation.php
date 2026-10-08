<?php
/** Read-only real WordPress menu registration with an unsaved role fixture. */
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'DISABLE_WP_CRON', true ); define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';
use AutoDealership\Admin\Navigation;
use AutoDealership\Core\Capabilities;
$role = $argv[1] ?? 'dealership_sales';
$matrix = Capabilities::role_matrix();
if ( ! isset( $matrix[$role] ) ) { throw new RuntimeException( 'Unknown role.' ); }
$user = new WP_User(); $user->ID = PHP_INT_MAX;
$user->allcaps = array_fill_keys( $matrix[$role], true );
$GLOBALS['current_user'] = $user;
if ( '--english' === ( $argv[2] ?? '' ) ) { $user->locale = 'en_US'; }
require ABSPATH . 'wp-admin/includes/admin.php';
$GLOBALS['pagenow'] = 'admin.php';
require ABSPATH . 'wp-admin/menu.php';
$count = 0;
foreach ( array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php' ) as $native ) {
 if ( in_array( $native, array_column( $menu, 2 ), true ) ) { throw new RuntimeException( 'WordPress tool visible to dealership role: ' . $native ); }
}
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
$bar = new WP_Admin_Bar();
foreach ( array( 'wp-logo', 'updates', 'comments', 'new-post', 'my-account' ) as $id ) { $bar->add_node( array( 'id'=>$id, 'title'=>$id ) ); }
Navigation::admin_bar( $bar );
if ( $bar->get_node( 'wp-logo' ) || $bar->get_node( 'updates' ) || $bar->get_node( 'comments' ) || $bar->get_node( 'new-post' ) || ! $bar->get_node( 'my-account' ) ) { throw new RuntimeException( 'Incorrect employee admin toolbar visibility.' ); }
foreach ( Navigation::groups() as $group ) {
 if ( '--english' === ( $argv[2] ?? '' ) ) {
  foreach ( array_merge( array( $group['title'] ), array_column( $group['items'], 'title' ) ) as $title ) {
   if ( preg_match( '/[\x{0600}-\x{06ff}]/u', $title ) ) { throw new RuntimeException( 'Missing English navigation label: ' . $title ); }
  }
 }
 $visible = array_filter( $group['items'], array( Navigation::class, 'allowed' ) );
 $parent = 'adc-area-' . $group['id'];
 if ( (bool) $visible !== in_array( $parent, array_column( $menu, 2 ), true ) ) { throw new RuntimeException( 'Wrong parent visibility: ' . $parent ); }
 foreach ( $group['items'] as $item ) {
  if ( ! $item['callback'] ) { continue; }
  $registered = in_array( $item['slug'], array_column( $submenu[$parent] ?? array(), 2 ), true );
  if ( $registered !== Navigation::allowed( $item ) ) { throw new RuntimeException( 'Card/menu permission mismatch: ' . $item['slug'] ); }
  if ( ! is_callable( $item['callback'] ) ) { throw new RuntimeException( 'Invalid callback: ' . $item['slug'] ); }
  ++$count;
 }
}
if ( current_user_can( 'adc_change_vehicle_vin' ) && ! current_user_can( 'adc_manage_inventory' ) ) {
 $_GET['page'] = 'adc-inventory-identity';
 $GLOBALS['plugin_page'] = 'adc-inventory-identity';
 $GLOBALS['pagenow'] = 'admin.php';
 ob_start();
 \AutoDealership\Admin\InventoryIdentityPage::render();
 $legacy = ob_get_clean();
 if ( ! str_contains( $legacy, 'VIN' ) || str_contains( $legacy, 'adc_record_vehicle_receipt' ) ) {
  throw new RuntimeException( 'Legacy inventory URL must retain VIN-only access.' );
 }
 if ( ! get_plugin_page_hook( 'adc-inventory-identity', '' ) ) {
  throw new RuntimeException( 'Legacy inventory page callback was lost.' );
 }
 if ( ! user_can_access_admin_page() ) { throw new RuntimeException( 'Legacy inventory URL is inaccessible.' ); }
}
echo "PASS $role: $count page permissions and all group menus.\n";
