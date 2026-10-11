<?php
/** Local-only adoption of source supplier names and model titles, with no new publications. */
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',array('--apply','--status','--drafts'),true)){exit(1);}
require dirname(__DIR__).'/wp-load.php';
if(!in_array(parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true)){exit(2);}
$admins=get_users(array('role'=>'administrator','number'=>1,'orderby'=>'ID'));if(!$admins){exit(3);}wp_set_current_user($admins[0]->ID);
if($argv[1]==='--drafts'){
	\AutoDealership\Database\Schema::install();if(!\AutoDealership\Database\Schema::is_ready()){exit(4);}
	$result=\AutoDealership\Inventory\OnyxImportService::complete_model_drafts();
	if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code());exit(6);}
	echo wp_json_encode(array('drafts'=>$result),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}
if($argv[1]==='--apply'){
	\AutoDealership\Database\Schema::install();
	if(!\AutoDealership\Database\Schema::is_ready()){fwrite(STDERR,"Schema verification failed.\n");exit(4);}
	$preview=\AutoDealership\Inventory\OnyxImportService::preview(dirname(__DIR__).'/docs/مخزون جديد بتاريخ 6-10-2026.xlsx','onyx-source-reconciliation.xlsx','snapshot');
	if(is_wp_error($preview)){fwrite(STDERR,$preview->get_error_message());exit(5);}
	$result=\AutoDealership\Inventory\OnyxImportService::apply($preview['id']);
	if(is_wp_error($result)){fwrite(STDERR,$result->get_error_code().': '.$result->get_error_message());exit(6);}
	echo wp_json_encode(array('applied'=>$result),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
}
global $wpdb;
$stock=\AutoDealership\Database\Schema::table('onyx_stock');$models=\AutoDealership\Database\Schema::table('catalog_models');$suppliers=\AutoDealership\Database\Schema::table('suppliers');
$pages=$wpdb->get_results("SELECT p.ID,p.post_title,p.post_name,p.post_status FROM $models m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' ORDER BY p.ID",ARRAY_A);
echo wp_json_encode(array('schema'=>get_option('adc_db_version'),'stock_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock"),'current_stock_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock WHERE present=1"),'sold_source_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock WHERE present=0"),'catalog_mode'=>get_option('adc_public_catalog_mode'),'unlinked_published_cars'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='car' AND p.post_status='publish' AND NOT EXISTS (SELECT 1 FROM $models m WHERE m.public_post_id=p.ID)"),'linked_source_suppliers'=>(int)$wpdb->get_var("SELECT COUNT(DISTINCT supplier_id) FROM $stock WHERE supplier_id IS NOT NULL"),'supplier_directory_rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $suppliers"),'unlinked_nonempty_supplier_names'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock WHERE supplier_name<>'' AND supplier_id IS NULL"),'model_pages'=>$pages),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
