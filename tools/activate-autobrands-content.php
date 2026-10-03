<?php
/** Local, backed-up activation of owner-confirmed editorial content. */
if ( PHP_SAPI !== 'cli' || ! in_array($argv[1] ?? '',array('--inspect','--apply'),true)) {exit(1);}
define('DISABLE_WP_CRON',true);
require dirname(__DIR__).'/wp-load.php';
if('wp-autobrands'!==DB_NAME || 'http://localhost/wordpress'!==untrailingslashit(get_option('siteurl'))){exit(2);}
$bundle=json_decode(file_get_contents(dirname(__DIR__).'/docs/launch-content/editorial.ar-en.json'),true,512,JSON_THROW_ON_ERROR);
if('owner_facts_confirmed_editorial_review'!==$bundle['status']){exit(3);}
$admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));if(!$admins){exit(4);}wp_set_current_user((int)$admins[0]);
if(!current_user_can('manage_options')){exit(4);}
$plan=array();$backup=array('pages'=>array(),'theme_settings'=>get_option('car_dealer_theme_settings',array()),'blogname'=>get_option('blogname'));
foreach($bundle['pages'] as $copy){
 $page=get_page_by_path($copy['slug'],OBJECT,'page');
 if(!$page){$page=get_page_by_path('autobrands-review-'.$copy['slug'],OBJECT,'page');}
 if(!$page || !in_array($page->post_status,array('publish','draft'),true)){throw new RuntimeException('Page missing or unexpected status: '.$copy['slug']);}
 if(!current_user_can('edit_post',$page->ID)){exit(4);}
 $plan[]=array('page'=>$page,'copy'=>$copy);
 $backup['pages'][]=array('id'=>$page->ID,'title'=>$page->post_title,'slug'=>$page->post_name,'status'=>$page->post_status,'content'=>$page->post_content,'meta'=>array('_adc_title_en'=>get_post_meta($page->ID,'_adc_title_en',true),'_adc_content_en'=>get_post_meta($page->ID,'_adc_content_en',true),'_wp_page_template'=>get_post_meta($page->ID,'_wp_page_template',true)));
 echo $copy['slug'].' -> '.$page->ID.' -> publish, default template'."\n";
}
if('--inspect'===$argv[1]){exit;}
$backupPath=dirname(__DIR__).'/.tmp/autobrands-content-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
if(false===file_put_contents($backupPath,wp_json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX)){exit(5);}
foreach($plan as $item){$page=$item['page'];$copy=$item['copy'];$ar=wp_kses_post($copy['ar']['content']);$en=wp_kses_post($copy['en']['content']);
 $saved=wp_update_post(wp_slash(array('ID'=>$page->ID,'post_name'=>$copy['slug'],'post_title'=>$copy['ar']['title'],'post_content'=>$ar,'post_status'=>'publish')),true);
 if(is_wp_error($saved)){throw new RuntimeException($saved->get_error_code());}
 foreach(array('_adc_title_en'=>$copy['en']['title'],'_adc_content_en'=>$en,'_wp_page_template'=>'default','_adc_owner_content_version'=>'2026-10-03') as $key=>$value){update_post_meta($page->ID,$key,$value);if(get_post_meta($page->ID,$key,true)!==$value){throw new RuntimeException('Meta verification failed');}}
 $actual=get_post($page->ID);if('publish'!==$actual->post_status || $actual->post_content!==$ar || $actual->post_name!==$copy['slug']){throw new RuntimeException('Page verification failed');}
}
$settings=(array)get_option('car_dealer_theme_settings',array());
$settings['phone']='0550928190';$settings['email']='autobrands2020@gmail.com';$settings['address']='جدة، حي الجوهرة';
update_option('car_dealer_theme_settings',$settings);update_option('blogname','شركة أوتو براندز');
if(get_option('car_dealer_theme_settings')!==$settings){throw new RuntimeException('Settings verification failed');}
echo "Five local pages and official contact fields applied. Backup saved. Legal drafts and operational records unchanged.\n";
