<?php
/** Guarded customization of local legal drafts; never publishes them. */
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',array('--inspect','--apply'),true)){exit(1);}
define('DISABLE_WP_CRON',true);require dirname(__DIR__).'/wp-load.php';
if(DB_NAME!=='wp-autobrands'||untrailingslashit(get_option('siteurl'))!=='http://localhost/wordpress'){exit(2);}
$root=dirname(__DIR__);$changes=array();
foreach(array('privacy-policy'=>'PRIVACY','terms'=>'TERMS') as $slug=>$key){
 $page=get_page_by_path($slug,OBJECT,'page');$original=wp_kses_post(file_get_contents($root.'/docs/legal/'.$key.'-DRAFT-AR-EN.html'));$source=file_get_contents($root.'/docs/legal/AUTOBRANDS-'.$key.'-DRAFT-AR-EN.html');
 if(!$page||$page->post_status!=='draft'||!preg_match('/(<section lang="ar".*?<\/section>)\s*(<section lang="en".*?<\/section>)/s',$source,$sections)){throw new RuntimeException('Draft identity/languages changed');}
 $ar=wp_kses_post($sections[1]);$en=wp_kses_post($sections[2]);
 if($page->post_content!==$original&&$page->post_content!==$ar){throw new RuntimeException('Manual content preserved: '.$slug);}
 $changes[]=array('id'=>$page->ID,'old'=>$page->post_content,'old_en'=>get_post_meta($page->ID,'_adc_content_en',true),'ar'=>$ar,'en'=>$en,'title_en'=>$key==='PRIVACY'?'Privacy policy':'Terms of use');
 echo $slug.': draft '.$page->ID.', customization '.($page->post_content===$ar?'current':'needed')."\n";
}
if('--inspect'===$argv[1]){exit;}
$admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));if(!$admins){exit(3);}wp_set_current_user((int)$admins[0]);
foreach($changes as $change){if(!current_user_can('edit_post',$change['id'])){exit(3);}}
$backup=$root.'/.tmp/autobrands-legal-before-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
if(false===file_put_contents($backup,wp_json_encode($changes,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX)){exit(4);}
foreach($changes as $change){$id=$change['id'];$result=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$change['ar'])),true);if(is_wp_error($result)){exit(5);}update_post_meta($id,'_adc_content_en',$change['en']);update_post_meta($id,'_adc_title_en',$change['title_en']);if(get_post($id)->post_status!=='draft'||get_post($id)->post_content!==$change['ar']||get_post_meta($id,'_adc_content_en',true)!==$change['en']){exit(6);}}
echo "Customized legal drafts saved and verified; not published.\n";
