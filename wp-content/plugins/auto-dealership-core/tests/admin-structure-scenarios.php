<?php
/** Real WordPress/database regression for consolidated administration. Uses parent synthetic fixtures. */
use AutoDealership\Admin\{AdminStructure, Navigation, WorkspacePage, SiteContentPage, TransferPages, EditorialSetupPage};
use AutoDealership\Core\{ProductSetup, SocialChannels};

if ( PHP_SAPI !== 'cli' || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
wp_set_current_user( $admin );
$check( AdminStructure::destination( 'adc-platform-setup', 'policy' ) === array( 'page'=>'adc-settings', 'section'=>'reservations' ), 'Retired policy editor routes to its single owner' );
$check( AdminStructure::destination( 'adc-platform-setup', 'appearance' ) === array( 'page'=>'adc-contact' ) && str_contains( ProductSetup::url( 'appearance' ), 'page=adc-contact' ), 'Contact links use their canonical editor' );
$check( str_contains( ProductSetup::url( 'pages' ), 'page=adc-editorial-setup' ) && ! str_contains( ProductSetup::url(), 'options-general.php' ), 'Generated setup links never use retired pages or parent' );
$check( AdminStructure::destination( 'adc-site-content', '', 'readiness' ) === array( 'page'=>'adc-platform-setup', 'section'=>'readiness' ), 'Content readiness has one destination' );
$check( AdminStructure::destination( 'adc-transfer-queue' ) === array( 'page'=>'adc-transfers' ) && ! AdminStructure::destination( 'adc-transfers' ), 'Transfer routing terminates at its single canonical page' );
$check( AdminStructure::destination( 'adc-follow-ups', '', '', 'Overview' ) === array( 'page'=>'adc-workspace', 'workspace_task'=>'overdue', 'workspace_search'=>'Overview' ) && ! AdminStructure::destination( 'adc-follow-ups' ), 'Saved filtered follow-up bookmarks preserve search without redirecting the notification inbox' );
$groups = Navigation::groups(); $slugs_all = array();
foreach ( $groups as $group ) { foreach ( $group['items'] as $item ) { $slugs_all[] = $item['slug']; } }
$check( count( $slugs_all ) === count( array_unique( $slugs_all ) ) && ! in_array( 'adc-transfer-queue', $slugs_all, true ) && ! in_array( 'customize.php', $slugs_all, true ), 'No duplicate or retired destinations in central navigation' );
foreach ( array( 'adc-contact', 'adc-social-channels', 'adc-branch-locations', 'adc-platform-setup' ) as $slug ) {
	$check( count( array_keys( $slugs( 'adc-area-settings' ), $slug, true ) ) === 1, 'One sidebar owner: ' . $slug );
}
$_GET = array( 'page'=>'adc-workspace', 'workspace_task'=>'overdue' );
ob_start(); WorkspacePage::render(); $home = ob_get_clean();
$check( str_contains( $home, 'adc-area-card' ) && ! str_contains( $home, 'class="adc-workspace-card"' ) && ! str_contains( $home, 'page=adc-profit-margin' ), 'Home keeps daily work and area entries, without repeating module cards' );
$_GET = array( 'page'=>'adc-area-settings' ); ob_start(); WorkspacePage::render(); $area = ob_get_clean();
$check( substr_count( $area, 'page=adc-contact' ) === 1 && substr_count( $area, 'page=adc-profit-margin' ) === 1 && ! str_contains( $area, 'adc-overview-heading' ), 'Area directory owns its module links and does not duplicate daily work' );
$_GET = array( 'page'=>'adc-contact' ); ob_start(); ProductSetup::render_contact(); $contact = ob_get_clean();
$check( str_contains( $contact, 'name="settings[phone]"' ) && ! str_contains( $contact, 'settings[facebook]' ) && ! str_contains( $contact, 'settings[whatsapp]' ) && ! str_contains( $contact, 'nav-tab-wrapper' ), 'Contact screen edits only public contact, without social forms or setup shortcuts' );
$_GET = array( 'page'=>'adc-platform-setup' ); ob_start(); ProductSetup::render(); $identity = ob_get_clean();
$check( str_contains( $identity, 'settings[brand_ar]' ) && ! str_contains( $identity, 'section=policy' ) && ! str_contains( $identity, 'section=appearance' ) && ! str_contains( $identity, 'section=pages' ), 'Identity tabs contain no parallel policy, contact or page editors' );
$_GET = array( 'page'=>'adc-platform-setup', 'section'=>'readiness' ); ob_start(); ProductSetup::render(); $ready = ob_get_clean();
$check( str_contains( $ready, 'adc-contact' ) && str_contains( $ready, 'settings_file' ) && ! str_contains( $ready, 'name="settings[phone]"' ), 'Unified readiness points to responsible screens without copying editors' );
$_GET = array( 'page'=>'adc-editorial-setup' ); ob_start(); EditorialSetupPage::render(); $pages = ob_get_clean();
$check( str_contains( $pages, 'adc-setup-tools' ) && substr_count( $pages, 'value="adc_platform_setup"' ) === 1, 'One pages screen hosts distinct provisioning tools' );
$_GET = array( 'page'=>'adc-transfers' ); ob_start(); TransferPages::render(); $transfers = ob_get_clean();
$check( str_contains( $transfers, 'section=new' ) && ! str_contains( $transfers, 'adc-transfer-queue' ), 'Transfer center links to creation without a second destination' );
$_GET = array( 'page'=>'adc-transfers', 'section'=>'new' ); ob_start(); TransferPages::render(); $creation = ob_get_clean();
$check( str_contains( $creation, 'section=requests' ) && str_contains( $creation, 'section=new' ), 'Transfer creation keeps center tabs and current context' );

// Shared storage remains compatible; save one section without replacing others.
$old_theme = get_option( 'car_dealer_theme_settings', array() );
update_option( 'car_dealer_theme_settings', array_replace( $old_theme, array( 'facebook'=>'https://facebook.com/synthetic-owner' ) ) );
$check( ! is_wp_error( ProductSetup::apply( array( 'appearance'=>array( 'phone'=>'+966500000001', 'email'=>'public@example.invalid' ) ) ) ) && get_option( 'car_dealer_theme_settings' )['facebook'] === 'https://facebook.com/synthetic-owner', 'Canonical contact save preserves social account settings' );
$profile = ProductSetup::profile();
$check( ! is_wp_error( ProductSetup::apply( array( 'profile'=>array( 'brand_ar'=>'Synthetic business', 'description_en'=>'Synthetic English description' ) ) ) ) && get_option( 'blogname' ) === 'Synthetic business' && get_option( 'car_dealer_theme_settings' )['description_en'] === 'Synthetic English description', 'Canonical identity still synchronizes WordPress and English public description' );
update_option( 'adc_product_profile', $profile ); update_option( 'car_dealer_theme_settings', $old_theme );
wp_set_current_user( $sales );
$groups = Navigation::groups(); $restricted = array();
foreach ( $groups as $group ) { foreach ( $group['items'] as $item ) { if ( in_array( $item['slug'], array( 'adc-contact', 'adc-social-channels', 'adc-branch-locations', 'adc-platform-setup' ), true ) ) { $restricted[] = Navigation::allowed( $item ); } } }
$check( count( $restricted ) === 4 && ! in_array( true, $restricted, true ), 'Sales role acquires no settings permissions through consolidated navigation' );
wp_set_current_user( $admin ); $_GET = array();
$original_pagenow = $GLOBALS['pagenow'] ?? null; $original_post = $_POST;
$GLOBALS['pagenow'] = 'options.php'; $_POST = array( 'option_page'=>'general' );
$check( AdminStructure::preserve_identity( 'stale value', 'current canonical identity' ) === 'current canonical identity', 'Old core General form cannot overwrite newer canonical identity' );
$GLOBALS['pagenow'] = 'admin-post.php';
$check( AdminStructure::preserve_identity( 'new canonical identity', 'old' ) === 'new canonical identity', 'Canonical identity writes retain their existing save behavior' );
$GLOBALS['pagenow'] = $original_pagenow; $_POST = $original_post;
require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
$customizer = new WP_Customize_Manager();
$customizer->register_controls();
foreach ( array( 'phone', 'email', 'address', 'address_en', 'description_en' ) as $field ) {
	$id = 'car_dealer_theme_settings[' . $field . ']';
	$customizer->add_setting( $id, array( 'type'=>'option' ) );
	$customizer->add_control( $id, array( 'section'=>'title_tagline' ) );
}
SocialChannels::customize( $customizer );
AdminStructure::customize( $customizer );
$check( ! $customizer->get_control( 'car_dealer_theme_settings[phone]' ) && ! $customizer->get_control( 'car_dealer_theme_settings[facebook]' ) && ! $customizer->get_control( SocialChannels::OPTION ), 'Actual Customizer removes duplicate contact and social controls' );
$check( ! $customizer->get_control( 'blogname' ) && ! $customizer->get_control( 'blogdescription' ) && $customizer->get_control( 'adc-owner-title_tagline' ) && $customizer->get_control( 'adc-owner-car_dealer_contact' ), 'Customizer links identity/contact to canonical owners' );
unset( $customizer );
$third = \AutoDealership\Branches\BranchService::create( array( 'code'=>'OV-C', 'name'=>'Foreign branch C' ) );
$ids = array();
for ( $i=0; $i<51; $i++ ) {
	$ids[] = $insert( 'vehicle_transfers', array( 'vehicle_id'=>$va, 'from_branch_id'=>$a['id'], 'to_branch_id'=>$b['id'], 'requested_by'=>$other, 'status'=>'requested', 'reason'=>'Synthetic transfer ' . $i, 'created_at'=>$now, 'updated_at'=>$now ) );
}
$ids[] = $insert( 'vehicle_transfers', array( 'vehicle_id'=>$vb, 'from_branch_id'=>$b['id'], 'to_branch_id'=>$third['id'], 'requested_by'=>$other, 'status'=>'requested', 'reason'=>'FOREIGN_TRANSFER_SECRET', 'created_at'=>$now, 'updated_at'=>$now ) );
get_userdata( $manager )->add_cap( 'adc_transfer_inventory' );
wp_set_current_user( $manager ); $_GET = array( 'page'=>'adc-transfers', 'section'=>'requests' );
ob_start(); TransferPages::render(); $first = ob_get_clean();
$dom = new DOMDocument(); @$dom->loadHTML( $first );
$check( ( new DOMXPath( $dom ) )->query( '//table/tbody/tr' )->length === 50 && ! str_contains( $first, 'FOREIGN_TRANSFER_SECRET' ) && str_contains( $first, 'queue_page=2' ), 'Transfer center pages fifty scoped requests without revealing foreign branch transfers' );
$_GET['queue_page'] = 2; ob_start(); TransferPages::render(); $second = ob_get_clean();
$dom = new DOMDocument(); @$dom->loadHTML( $second );
$check( ( new DOMXPath( $dom ) )->query( '//table/tbody/tr' )->length === 1 && str_contains( $second, 'Synthetic transfer 0' ), 'Transfer second page reaches remaining request and preserves its center' );
$_GET['search'] = "' OR 1=1 --"; ob_start(); TransferPages::render(); $searched = ob_get_clean();
$check( ! str_contains( $searched, 'Synthetic transfer ' ) && ! str_contains( $searched, 'FOREIGN_TRANSFER_SECRET' ), 'Transfer search cannot widen branch scope' );
wp_set_current_user( $admin ); $_GET = array();
foreach ( $ids as $id ) { $wpdb->delete( \AutoDealership\Database\Schema::table( 'vehicle_transfers' ), array( 'id'=>$id ) ); }
