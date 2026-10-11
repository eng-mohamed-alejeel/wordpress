<?php
/** Explicit, audited local synchronization of known reservation copy; does not edit legal pages. */
if ( PHP_SAPI !== 'cli' || ! in_array( $argv[1] ?? '', array( '--inspect', '--apply' ), true ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
require dirname( __DIR__ ) . '/wp-load.php';
if ( ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) { fwrite( STDERR, "Local site only.\n" ); exit( 2 ); }
$admins = get_users( array( 'role'=>'administrator', 'number'=>1, 'fields'=>'ID' ) );
if ( ! $admins ) { exit( 3 ); } wp_set_current_user( (int) $admins[0] );
$replace = static function( string $content ): string {
	$content = preg_replace( '~<p>يتم الحجز بدفع عربون ثابت قدره 20,000 ريال سعودي لمدة لا تتجاوز ثلاثة أيام\..*?</p>~u', '[adc_reservation_policy]', $content );
	$content = preg_replace( '~<p>A reservation requires a fixed deposit of SAR 20,000 and lasts no longer than three days\..*?</p>~u', '[adc_reservation_policy]', $content );
	$content = preg_replace( '~يُحجز بدفع عربون ثابت قدره 20,000 ريال سعودي لمدة ثلاثة أيام كحد أقصى\. يُعاد العربون خلال ثلاثة أيام من إلغاء الحجز عبر طريقة الدفع نفسها\.~u', '', $content );
	$content = str_replace( 'A fixed deposit of SAR 20,000 reserves the vehicle for a maximum of three days and is returned within three days of reservation cancellation through the same payment method.', '', $content );
	if ( $content !== '' && ! str_contains( $content, '[adc_reservation_policy]' ) ) { $content .= "\n[adc_reservation_policy]"; }
	return $content;
};
$plan=array();
foreach ( array( 'about', 'faq' ) as $slug ) {
	$page=get_page_by_path($slug,OBJECT,'page'); if(!$page){continue;}
	$english=(string)get_post_meta($page->ID,'_adc_content_en',true);
	$plan[]=array('id'=>$page->ID,'slug'=>$slug,'old'=>$page->post_content,'old_en'=>$english,'old_en_hash'=>get_post_meta($page->ID,'_adc_content_en_source_hash',true),'new'=>$replace($page->post_content),'new_en'=>$english ? $replace($english) : '');
}
echo wp_json_encode(array('reservation_hours'=>get_option('adc_reservation_hours',24),'deposit_type'=>get_option('adc_reservation_deposit_type','none'),'refund_processing_hours'=>get_option('adc_reservation_refund_hours',72),'pages'=>array_map(static fn($p)=>array('id'=>$p['id'],'slug'=>$p['slug'],'changes'=>$p['old']!==$p['new']||$p['old_en']!==$p['new_en']),$plan)),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
if('--inspect'===$argv[1]){exit;}
$changed=array_filter($plan,static fn($p)=>$p['old']!==$p['new']||$p['old_en']!==$p['new_en']);if(!$changed){echo "Already synchronized.\n";exit;}
$backup=tempnam(sys_get_temp_dir(),'adc-policy-backup-');if(false===file_put_contents($backup,wp_json_encode($plan),LOCK_EX)){exit(4);}
if(!\AutoDealership\Database\Transaction::begin()){exit(5);}
try{
	foreach($changed as$p){$saved=wp_update_post(wp_slash(array('ID'=>$p['id'],'post_content'=>$p['new'])),true);if(is_wp_error($saved)){throw new RuntimeException($saved->get_error_code());}update_post_meta($p['id'],'_adc_content_en',wp_slash($p['new_en']));if($p['new_en']){update_post_meta($p['id'],'_adc_content_en_source_hash',hash('sha256',$p['new']));}if(get_post($p['id'])->post_content!==$p['new']||(string)get_post_meta($p['id'],'_adc_content_en',true)!==$p['new_en']){throw new RuntimeException('Content verification failed');}}
	if(!\AutoDealership\Database\Transaction::commit(static fn()=>\AutoDealership\Audit\AuditLog::record('content.reservation_policy_synchronized','settings',0,'Known About/FAQ reservation text now reads operational policy',null,array('page_ids'=>array_column($changed,'id'))))){throw new RuntimeException('Audit commit failed');}
}catch(Throwable$error){global$wpdb;$wpdb->query('ROLLBACK');foreach($changed as$p){clean_post_cache($p['id']);}fwrite(STDERR,$error->getMessage()."\n");exit(6);}
if(function_exists('wp_cache_clear_cache')){wp_cache_clear_cache();}
echo "Synchronized About/FAQ with operational settings. Backup: $backup\n";
