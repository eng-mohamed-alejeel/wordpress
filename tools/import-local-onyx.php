<?php
/** Apply the supplied real export locally; no fabricated full VINs or auto-publication. */
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',array('--status','--preview','--apply'),true)){fwrite(STDERR,"Usage: php tools/import-local-onyx.php --status | --preview | --apply\n");exit(1);}
require dirname(__DIR__).'/wp-load.php';
if(!in_array(parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true)){exit(2);}
$admins=get_users(array('role'=>'administrator','number'=>1,'orderby'=>'ID','order'=>'ASC'));if(!$admins){exit(3);}wp_set_current_user($admins[0]->ID);
if($argv[1]==='--status'){global $wpdb;$stock=\AutoDealership\Database\Schema::table('onyx_stock');$models=\AutoDealership\Database\Schema::table('catalog_models');echo wp_json_encode(array('schema'=>get_option('adc_db_version'),'mode'=>get_option('adc_public_catalog_mode'),'stock_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock"),'present_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock WHERE present=1"),'linked_full_vins'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock WHERE vehicle_id IS NOT NULL"),'model_groups'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $models"),'published_model_pages'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $models m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' AND p.post_status='publish'")),JSON_PRETTY_PRINT)."\n";exit;}
$file=dirname(__DIR__).'/docs/مخزون جديد بتاريخ 6-10-2026.xlsx';
$result=\AutoDealership\Inventory\OnyxImportService::preview($file,basename($file),'partial');
if(!is_wp_error($result)&&$argv[1]==='--apply'&&!$result['summary']['errors']){$applied=\AutoDealership\Inventory\OnyxImportService::apply($result['id']);if(is_wp_error($applied)){$result=$applied;}else{$result['applied']=$applied;}}
if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().': '.$result->get_error_message()."\n");exit(4);}
echo wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
