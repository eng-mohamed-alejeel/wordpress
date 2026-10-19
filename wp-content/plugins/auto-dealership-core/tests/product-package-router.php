<?php
/** Loopback test server for extracted distributable packages; never loads source wp-config. */
if ( PHP_SAPI !== 'cli-server' ) { http_response_code( 404 ); exit; }
$file = (string) getenv( 'ADC_PRODUCT_TEST_CONFIG' );
$config = json_decode( (string) @file_get_contents( $file ), true );
if ( ! is_array( $config ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', $config['database'] ?? '' ) || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) ) { http_response_code( 503 ); exit; }
$path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$root = realpath( dirname( __DIR__, 4 ) );
if ( preg_match( '#\A/(?:wp-content|wp-includes|wp-admin)/.+\.(css|js|svg|png|jpe?g|webp|woff2?)\z#i', $path, $match ) ) {
	$base = str_starts_with( $path, '/wp-content/' ) ? $config['content'] : $root;
	$relative = str_starts_with( $path, '/wp-content/' ) ? substr( $path, strlen( '/wp-content' ) ) : $path;
	$asset = realpath( $base . $relative );
	if ( ! $asset || ! str_starts_with( $asset, realpath( $base ) . DIRECTORY_SEPARATOR ) || ! is_file( $asset ) ) { http_response_code( 404 ); exit; }
	$types = array( 'css'=>'text/css', 'js'=>'application/javascript', 'svg'=>'image/svg+xml', 'woff2'=>'font/woff2', 'woff'=>'font/woff', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp' );
	header( 'Content-Type: ' . $types[strtolower( $match[1] )] ); readfile( $asset ); exit;
}
define( 'ABSPATH', $root . '/' );
if ( '1' === getenv( 'ADC_PRODUCT_ADMIN_ONLY' ) ) {
	define( 'CONCATENATE_SCRIPTS', false );
	// Use installed core translations, while all plugin/theme code comes from the ZIPs.
	define( 'WP_LANG_DIR', $root . '/wp-content/languages' );
}
define( 'WP_USE_THEMES', true );
define( 'DB_NAME', $config['database'] ); define( 'DB_HOST', '127.0.0.1:' . $config['port'] ); define( 'DB_USER', 'root' ); define( 'DB_PASSWORD', '' ); define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'WP_CONTENT_DIR', $config['content'] ); define( 'WP_CONTENT_URL', 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/wp-content' );
define( 'WP_SITEURL', 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'] ); define( 'WP_HOME', WP_SITEURL );
define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'WP_HTTP_BLOCK_EXTERNAL', true ); define( 'DISABLE_WP_CRON', true ); define( 'WP_DEBUG', false );
if ( str_starts_with( $path, '/wp-admin/' ) ) { define( 'WP_ADMIN', true ); }
if('/wp-admin/admin-ajax.php'===$path){define('DOING_AJAX',true);}
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $key ) { define( $key, hash( 'sha512', $config['token'] . $key ) ); }
$table_prefix = 'test_';
require ABSPATH . 'wp-includes/plugin.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
require ABSPATH . 'wp-settings.php';
if ( '/adc-product-session' === $path ) {
	if ( ! hash_equals( $config['token'], (string) ( $_GET['key'] ?? '' ) ) ) { http_response_code( 403 ); exit; }
	$session_user = 'staff' === ( $_GET['actor'] ?? '' ) ? (int) get_option( 'adc_test_admin_staff', 0 ) : $config['admin'];
	if ( '1' === getenv( 'ADC_PRODUCT_ADMIN_ONLY' ) && str_starts_with( (string) ( $_GET['actor'] ?? '' ), 'dealership_' ) ) { $session_user = (int) get_option( 'adc_test_admin_role_' . sanitize_key( $_GET['actor'] ), 0 ); }
	if ( ! $session_user ) { http_response_code( 404 ); exit; }
	if ( '1' === getenv( 'ADC_PRODUCT_ADMIN_ONLY' ) && in_array( $_GET['locale'] ?? '', array( 'ar', 'en_US' ), true ) ) { update_user_meta( $session_user, 'locale', $_GET['locale'] ); }
	wp_set_current_user( $session_user ); wp_set_auth_cookie( $session_user, false, false ); header( 'Content-Type: application/json' ); echo '{"authenticated":true}'; exit;
}
if ( '1' === getenv( 'ADC_PRODUCT_ADMIN_ONLY' ) && '/adc-product-admin-fixture' === $path ) {
	if ( ! hash_equals( $config['token'], (string) ( $_GET['key'] ?? '' ) ) ) { http_response_code( 403 ); exit; }
	wp_set_current_user( $config['admin'] );
	if ( ! get_option( 'adc_test_admin_staff' ) ) {
		$branch = \AutoDealership\Branches\BranchService::create( array( 'code'=>'ADMIN-QA', 'name'=>'Synthetic admin acceptance' ) );
		$id = wp_insert_user( array( 'user_login'=>'admin_acceptance_sales', 'user_pass'=>wp_generate_password( 32 ), 'role'=>'dealership_sales' ) );
		if ( is_wp_error( $id ) || is_wp_error( $branch ) ) { http_response_code( 500 ); exit; }
		$staff = get_userdata( $id ); $staff->add_cap( 'adc_view_workspace' ); $staff->add_cap( 'adc_view_own_leads' );
		\AutoDealership\Core\ConfigurationService::assign_branches( $id, $branch['id'], array( $branch['id'] ) );
		update_option( 'adc_test_admin_staff', $id, false );
	}
	foreach ( array( 'dealership_finance', 'dealership_inventory', 'dealership_general_manager', 'dealership_delivery' ) as $role ) {
		if ( get_option( 'adc_test_admin_role_' . $role ) ) { continue; }
		$id = wp_insert_user( array( 'user_login'=>'review_' . $role, 'user_pass'=>wp_generate_password( 32 ), 'role'=>$role ) );
		if ( is_wp_error( $id ) ) { http_response_code( 500 ); exit; }
		$branch_id = (int) get_user_meta( (int) get_option( 'adc_test_admin_staff' ), 'adc_branch_id', true );
		\AutoDealership\Core\ConfigurationService::assign_branches( $id, $branch_id, array( $branch_id ) );
		update_option( 'adc_test_admin_role_' . $role, $id, false );
	}
	header( 'Content-Type: application/json' ); echo '{"created":true}'; exit;
}
if('/adc-product-portal-fixture'===$path){
	if(!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}
	wp_set_current_user($config['admin']);$fixture=get_option('adc_test_portal_fixture');
	if(!$fixture){
		$branch=\AutoDealership\Branches\BranchService::create(array('code'=>'PORTAL_QA','name'=>'Portal QA branch'))['id'];$ids=array();
		foreach(array('customer','other') as $name){$id=wp_insert_user(array('user_login'=>'portal_qa_'.$name,'user_email'=>'portal_qa_'.$name.'@example.invalid','display_name'=>'Portal QA '.$name,'user_pass'=>'PortalBrowserPass123!','role'=>'car_dealer_customer'));update_user_meta($id,'adc_email_verification_status','verified');update_user_meta($id,'adc_email_verified_at',current_time('mysql',true));update_user_meta($id,'adc_verified_email_hash',hash('sha256','portal_qa_'.$name.'@example.invalid'));$ids[$name]=$id;}
		wp_set_current_user($ids['customer']);$thread=\AutoDealership\Portal\Conversations::ensure('general',0,'Browser enquiry',$branch);\AutoDealership\Portal\Conversations::send($thread,'Customer initial message',wp_generate_uuid4());
		wp_set_current_user($config['admin']);\AutoDealership\Portal\Conversations::send($thread,'PRIVATE_STAFF_NOTE',wp_generate_uuid4(),true);\AutoDealership\Portal\Conversations::send($thread,'Public adviser reply',wp_generate_uuid4());
		$fixture=array_merge($ids,array('thread'=>$thread,'branch'=>$branch));update_option('adc_test_portal_fixture',$fixture,false);
	}
	$actor='other'===($_GET['as']??'')?$fixture['other']:('admin'===($_GET['as']??'')?$config['admin']:$fixture['customer']);wp_set_current_user($actor);wp_set_auth_cookie($actor,false,false);header('Content-Type: application/json');echo wp_json_encode($fixture);exit;
}
if('/adc-product-extended-fixture'===$path){
	if(!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}global $wpdb;wp_set_current_user($config['admin']);$x=get_option('adc_test_extended_fixture');
	if(!$x){$base=get_option('adc_test_portal_fixture');$b=\AutoDealership\Branches\BranchService::create(array('code'=>'EXT_BROWSER','name'=>'Extended browser branch'))['id'];$adviser=wp_insert_user(array('user_login'=>'extended_qa_adviser','user_email'=>'extended_qa_adviser@example.invalid','user_pass'=>wp_generate_password(32),'display_name'=>'Branch adviser','role'=>'dealership_chat_adviser'));update_user_meta($adviser,'adc_branch_id',$b);$car=wp_insert_post(array('post_type'=>'car','post_status'=>'publish','post_title'=>'Extended QA car'));update_post_meta($car,'_car_inventory_status','available');
		wp_set_current_user($base['customer']);$lead=\AutoDealership\Leads\PublicIntake::submit(array('name'=>get_userdata($base['customer'])->display_name,'email'=>get_userdata($base['customer'])->user_email,'mobile'=>'+966505555557','branch_id'=>$b,'car_id'=>$car,'date'=>wp_date('Y-m-d',time()+8*DAY_IN_SECONDS),'time'=>'10:30','privacy_consent'=>'1','idempotency_key'=>wp_generate_uuid4()),'booking');if(is_wp_error($lead)){throw new RuntimeException(wp_json_encode($lead));}$cid=(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.\AutoDealership\Database\Schema::table('customers').' WHERE account_user_id=%d',$base['customer']));$now=current_time('mysql',true);
		$wpdb->insert(\AutoDealership\Database\Schema::table('quotations'),array('quote_number'=>'EXT-BROWSER-QUOTE','customer_id'=>$cid,'vehicle_id'=>0,'branch_id'=>$b,'owner_user_id'=>$config['admin'],'base_amount'=>100000,'final_amount'=>115000,'valid_until'=>wp_date('Y-m-d',time()+DAY_IN_SECONDS),'status'=>'approved','seller_address'=>'','created_at'=>$now));$qid=(int)$wpdb->insert_id;$wpdb->insert(\AutoDealership\Database\Schema::table('sales'),array('quotation_id'=>$qid,'reservation_id'=>0,'customer_id'=>$cid,'vehicle_id'=>0,'owner_user_id'=>$config['admin'],'created_at'=>$now,'updated_at'=>$now));$sale=(int)$wpdb->insert_id;$x=array('staff'=>$adviser,'customer'=>$base['customer'],'other'=>$base['other'],'branch'=>$b,'car'=>$car,'sale'=>$sale,'booking'=>$lead['legacy_request_id'],'date'=>wp_date('Y-m-d',time()+10*DAY_IN_SECONDS));update_option('adc_test_extended_fixture',$x,false);
	}
	$as=$_GET['as']??'customer';$id='admin'===$as?$config['admin']:($x[$as]??$x['customer']);wp_set_current_user($id);wp_set_auth_cookie($id,false,false);header('Content-Type: application/json');echo wp_json_encode($x);exit;
}
if('/adc-product-mail-state'===$path) {
	if(!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}
	$kind='subscription'===($_GET['kind']??'')?'newsletter.subscription':'email.verify';
	global $wpdb;header('Content-Type: application/json');echo wp_json_encode($wpdb->get_results($wpdb->prepare('SELECT status,attempts FROM '.\AutoDealership\Operations\OutboxService::table().' WHERE event_key=%s ORDER BY id',$kind),ARRAY_A));exit;
}
if('/wp-admin/admin-ajax.php'===$path){$action=sanitize_key($_REQUEST['action']??'');do_action((is_user_logged_in()?'wp_ajax_':'wp_ajax_nopriv_').$action);http_response_code(400);exit;}
if('/adc-product-confirmation-fixture'===$path) {
	if(!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}
	if(isset($_GET['clear_wait'])){$key=new ReflectionMethod(\AutoDealership\Accounts\VerificationExperience::class,'cooldown_key');$key->setAccessible(true);delete_transient($key->invoke(null,'account'));}
	$user=get_user_by('email','package-verification@example.invalid');global $wpdb;
	$row=$user?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.\AutoDealership\Accounts\EmailVerification::table()." WHERE purpose='account' AND subject_id=%d",$user->ID),ARRAY_A):null;
	if(!$row){http_response_code(404);exit;}$method=new ReflectionMethod(\AutoDealership\Accounts\EmailVerification::class,'proof');$method->setAccessible(true);
	header('Content-Type: application/json');echo wp_json_encode(['blocked'=>\AutoDealership\Accounts\EmailVerification::blocked((int)$user->ID),'url'=>\AutoDealership\Accounts\EmailVerification::page_url('account','en').'#token='.$row['selector'].'.'.$method->invoke(null,$row)]);exit;
}
if ( '/wp-admin/admin-post.php' === $path ) { do_action( 'admin_post_' . sanitize_key( $_POST['action'] ?? '' ) ); exit; }
if ( '1' === getenv( 'ADC_PRODUCT_ADMIN_ONLY' ) && in_array( $path, array( '/wp-admin/admin.php', '/wp-admin/options-general.php', '/wp-admin/index.php' ), true ) ) {
	if ( ! is_user_logged_in() ) { http_response_code( 401 ); exit; }
	// Use actual core menus, header, footer, scripts and plugin page hooks without wp-load.php.
	// wp-load.php would load the source wp-config.php, which this isolated router must never do.
	if ( ! defined( 'WP_NETWORK_ADMIN' ) ) { define( 'WP_NETWORK_ADMIN', false ); }
	if ( ! defined( 'WP_USER_ADMIN' ) ) { define( 'WP_USER_ADMIN', false ); }
	if ( ! defined( 'WP_BLOG_ADMIN' ) ) { define( 'WP_BLOG_ADMIN', true ); }
	global $pagenow, $plugin_page, $hook_suffix, $typenow, $taxnow, $parent_file;
	$pagenow = basename( $path ); $plugin_page = sanitize_key( $_GET['page'] ?? '' ); $typenow = ''; $taxnow = '';
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require ABSPATH . 'wp-admin/menu.php';
	do_action( 'admin_init' );
	if ( 'index.php' === $pagenow ) { do_action( 'load-index.php' ); }
	$hook_suffix = get_plugin_page_hook( $plugin_page, $pagenow );
	if ( ! $hook_suffix ) { http_response_code( 404 ); exit; }
	set_current_screen( $hook_suffix ); do_action( 'load-' . $hook_suffix );
	require ABSPATH . 'wp-admin/admin-header.php'; do_action( $hook_suffix );
	require ABSPATH . 'wp-admin/admin-footer.php'; exit;
}
if('/wp-admin/admin.php'===$path&&in_array($_GET['page']??'',array('adc-conversations','adc-customer-reviews','adc-portal-operations'),true)){
	if(!current_user_can('manage_options')&&!current_user_can('adc_view_conversations')){http_response_code(403);exit;}require_once ABSPATH.'wp-admin/includes/admin.php';set_current_screen('toplevel_page_'.sanitize_key($_GET['page']));do_action('admin_enqueue_scripts');wp_enqueue_style('common');wp_enqueue_style('forms');wp_dequeue_script('wp-auth-check');echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">';wp_print_styles();echo '</head><body class="wp-admin">';if('adc-conversations'===$_GET['page']){\AutoDealership\Portal\PortalAdmin::conversations();}elseif('adc-portal-operations'===$_GET['page']){\AutoDealership\Portal\PortalOperations::render();}else{\AutoDealership\Portal\PortalAdmin::reviews();}wp_print_scripts(array('adc-customer-portal','adc-live-chat','adc-chat-typing'));echo '</body></html>';exit;
}
if ( '/wp-admin/options-general.php' === $path || ( '/wp-admin/admin.php' === $path && in_array( $_GET['page'] ?? '', array( 'adc-site-content', 'adc-platform-setup', 'adc-contact', 'adc-social-channels', 'adc-branch-locations', 'adc-editorial-setup', 'adc-settings' ), true ) ) ) {
	if ( ! current_user_can( 'manage_options' ) ) { http_response_code( 403 ); exit; }
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	$GLOBALS['pagenow'] = basename( $path );
	\AutoDealership\Admin\AdminStructure::redirect();
	$branch_page = 'adc-branch-locations' === ($_GET['page'] ?? '');
	$content_page = 'adc-site-content' === ($_GET['page'] ?? '');
	set_current_screen( $branch_page ? 'settings_page_adc-branch-locations' : 'settings_page_adc-platform-setup' );
	do_action( 'admin_enqueue_scripts', $branch_page ? 'settings_page_adc-branch-locations' : 'settings_page_adc-platform-setup' );
	wp_enqueue_style( 'common' ); wp_enqueue_style( 'forms' );
	wp_dequeue_script('wp-auth-check');
	echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1">'; wp_print_styles(); echo '</head><body class="wp-admin"><main style="padding:16px">';
	if ($content_page) { \AutoDealership\Admin\SiteContentPage::render(); wp_print_styles(array('adc-site-content-admin')); } elseif ($branch_page) { \AutoDealership\Core\BranchLocations::admin(); } elseif ('adc-editorial-setup' === ($_GET['page']??'')) { \AutoDealership\Admin\EditorialSetupPage::render(); } elseif ('adc-settings' === ($_GET['page']??'')) { \AutoDealership\Admin\SettingsPage::render(); } elseif ('adc-social-channels' === ($_GET['page']??'')) { \AutoDealership\Core\SocialChannels::admin(); } else { \AutoDealership\Core\ProductSetup::render(); }
	echo '</main>'; if ($content_page) { wp_print_scripts(array('adc-site-content-admin')); } elseif ($branch_page) { wp_print_scripts(array('adc-branch-editor')); } echo '</body></html>'; exit;
}
if (str_starts_with($path, '/wp-admin/')) { http_response_code(404); exit; }
wp();
require ABSPATH . WPINC . '/template-loader.php';
