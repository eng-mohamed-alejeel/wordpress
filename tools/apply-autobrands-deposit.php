<?php
/** Apply the owner's local reservation policy without rewriting other settings. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
if ( 'wp-autobrands' !== DB_NAME || 'http://localhost/wordpress' !== untrailingslashit( get_option( 'siteurl' ) ) ) { exit( 2 ); }
$defaults = array( 'vat_rate_bps'=>0, 'pricing_fee_amount'=>0, 'promotion_code'=>'', 'promotion_type'=>'none', 'promotion_value'=>0, 'promotion_starts_at'=>'', 'promotion_ends_at'=>'', 'reservation_hours'=>24, 'reservation_deposit_type'=>'none', 'reservation_deposit_value'=>0, 'sales_manager_discount_limit'=>0, 'general_manager_discount_limit'=>PHP_INT_MAX, 'seller_name'=>get_bloginfo('name'), 'seller_tax_number'=>'', 'seller_address'=>'', 'seller_phone'=>'', 'delivery_required_documents'=>array(), 'default_branch_id'=>0, 'privacy_retention_days'=>0, 'public_catalog_mode'=>'compatibility' );
$input = array();
foreach ( $defaults as $key=>$default ) { $input[$key] = get_option( 'adc_' . $key, $default ); }
$before = $input;
$input['reservation_hours'] = 72;
$input['reservation_deposit_type'] = 'fixed';
$input['reservation_deposit_value'] = 2000000;
$bundle = json_decode( file_get_contents( dirname(__DIR__) . '/docs/launch-content/editorial.ar-en.json' ), true, 512, JSON_THROW_ON_ERROR );
$drafts = array();
foreach ( $bundle['pages'] as $definition ) {
	if ( ! in_array( $definition['slug'], array('about','faq'), true ) ) { continue; }
	$page = get_page_by_path( 'autobrands-review-' . $definition['slug'], OBJECT, 'page' );
	if ( ! $page || 'draft' !== $page->post_status || 'autobrands-owner-content-2026-10-03' !== get_post_meta($page->ID,'_adc_content_preparation',true) ) { exit(3); }
	$drafts[] = array('page'=>$page,'definition'=>$definition,'old_en'=>get_post_meta($page->ID,'_adc_content_en',true));
}
if ('--inspect' === $argv[1]) { echo json_encode(array('current_hours'=>$before['reservation_hours'],'current_type'=>$before['reservation_deposit_type'],'current_value_halalas'=>$before['reservation_deposit_value'],'target_hours'=>72,'target_value_halalas'=>2000000,'refund_method'=>'same_as_original_payment','refund_processing_days'=>3,'refund_start'=>'reservation_cancellation'),JSON_PRETTY_PRINT) . "\n"; exit; }
$admins = get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));
if (!$admins) {exit(4);} wp_set_current_user((int)$admins[0]);
$backup = dirname(__DIR__) . '/.tmp/autobrands-deposit-before-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
if (false === file_put_contents($backup,wp_json_encode(array('settings'=>$before,'drafts'=>array_map(static fn($item)=>array('id'=>$item['page']->ID,'content'=>$item['page']->post_content,'content_en'=>$item['old_en']),$drafts)),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX)) {exit(5);}
$result = \AutoDealership\Core\ConfigurationService::update($input);
if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code()."\n");exit(6);}
foreach($before as $key=>$value){if(!in_array($key,array('reservation_hours','reservation_deposit_type','reservation_deposit_value'),true) && get_option('adc_'.$key,$defaults[$key]) != $value){throw new RuntimeException('Unrelated setting changed: '.$key);}}
foreach($drafts as $item){$id=$item['page']->ID;$definition=$item['definition'];$ar=wp_kses_post($definition['ar']['content']);$en=wp_kses_post($definition['en']['content']);if(get_post($id)->post_content!==$ar){$saved=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$ar)),true);if(is_wp_error($saved)){exit(7);}}update_post_meta($id,'_adc_content_en',$en);if(get_post($id)->post_content!==$ar || get_post_meta($id,'_adc_content_en',true)!==$en){exit(8);}}
if(72!==(int)get_option('adc_reservation_hours') || 'fixed'!==get_option('adc_reservation_deposit_type') || 2000000!==(int)get_option('adc_reservation_deposit_value')){exit(9);}
echo "Verified fixed SAR 20,000 deposit and 72-hour reservation; review drafts updated. Existing transactions preserved. Refund method is documented, not automated.\n";
