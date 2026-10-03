<?php
/** Idempotent synthetic inventory through audited domain services; local site only. */
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',array('--inspect','--apply'),true)){exit(1);}
define('DISABLE_WP_CRON',true);require dirname(__DIR__).'/wp-load.php';
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\VehicleService;
use AutoDealership\Inventory\VehicleIntakeService;
use AutoDealership\Inventory\CatalogMappingService;
use AutoDealership\Content\PostMetaStore;
if(DB_NAME!=='wp-autobrands'||untrailingslashit(get_option('siteurl'))!=='http://localhost/wordpress'||!get_option('adc_demo_seed_version')||Schema::verify()){exit(2);}
$admins=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));if(!$admins){exit(3);}wp_set_current_user((int)$admins[0]);
$requireResult=static function($result){if(is_wp_error($result)){throw new RuntimeException($result->get_error_code());}return $result;};
global $wpdb;
$lookup=static fn($table,$key,$value)=>(int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Schema::table($table)." WHERE $key=%s",$value));
$csv=fopen(dirname(__DIR__).'/docs/launch-content/demo-inventory.csv','r');$headers=fgetcsv($csv);$rows=array();while(($row=fgetcsv($csv))!==false){$rows[]=array_combine($headers,$row);}fclose($csv);
foreach($rows as $row){if($row['record_type']!=='synthetic'||$row['price_approved_for_sale']!=='false'||$row['branch_code']!=='JED-MAIN'){exit(4);}}
$branch=$lookup('branches','code','AB-DEMO-JED');
if('--inspect'===$argv[1]){foreach($rows as $row){echo $row['stock_number'].': '.($lookup('vehicles','stock_number',$row['stock_number'])?'exists':'missing')."\n";}exit;}
if(!$branch){$branch=$requireResult(\AutoDealership\Branches\BranchService::create(array('code'=>'AB-DEMO-JED','name'=>'[تجريبي] أوتو براندز — جدة','city'=>'جدة','address'=>'حي الجوهرة — ربط تطوير اصطناعي')) )['id'];}
foreach($rows as $index=>$row){
 $stock=$row['stock_number'];$id=$lookup('vehicles','stock_number',$stock);$vin='TESTAB'.str_pad((string)($index+1),11,'0',STR_PAD_LEFT);
 if(!$id){$id=$requireResult(VehicleService::create(array('vin'=>$vin,'stock_number'=>$stock,'brand'=>$row['brand'],'model'=>$row['model'],'model_year'=>(int)$row['model_year'],'condition'=>'new','branch_id'=>$branch,'mileage'=>0,'retail_price'=>(int)$row['illustrative_price_sar']*100,'warranty'=>'مثال تطوير فقط؛ ضمان السيارة الفعلية على الوكيل بحسب المورد.')))['id'];}
 $vehicle=VehicleService::get($id,true);if(!$vehicle||$vehicle['branch_id']!=$branch||$vehicle['vin']!==$vin){throw new RuntimeException('Synthetic identity collision');}
 if($vehicle['status']==='received'){
  if(!VehicleIntakeService::has_receipt($id)){$requireResult(VehicleIntakeService::receive($id,array('condition'=>'good','document_reference'=>'DEMO-NO-PHYSICAL-RECEIPT-'.$stock,'odometer'=>0)));}
  $requireResult(VehicleService::transition($id,'inspection','Synthetic development receipt; no physical vehicle'));
 }
 $vehicle=VehicleService::get($id,true);
 if($vehicle['status']==='inspection'){
  if(!VehicleIntakeService::passed($id)){$requireResult(VehicleIntakeService::inspect($id,array('checklist'=>array_fill_keys(array('exterior','interior','engine','tires','vin'),'pass'),'notes'=>'محاكاة اختبار فقط؛ لم يتم فحص مركبة فعلية.')));}
  $requireResult(VehicleService::transition($id,'available','Synthetic inspection complete for local demonstration only'));
 }
 $slug=strtolower($stock);$post=get_page_by_path($slug,OBJECT,'car');
 if($post&&get_post_meta($post->ID,'_adc_autobrands_demo',true)!=='2026-10-03'){throw new RuntimeException('Post collision');}
 if(!$post){$postId=$requireResult(wp_insert_post(wp_slash(array('post_type'=>'car','post_status'=>'publish','post_name'=>$slug,'post_title'=>$row['display_title_ar'],'post_content'=>'<p>مثال تطوير اصطناعي لسيارة جديدة. السعر والعداد والهوية بيانات تجريبية، وليست عرض بيع أو مخزونًا فعليًا لشركة أوتو براندز. الصورة البديلة لا تمثل سيارة بعينها.</p>','meta_input'=>array('_adc_autobrands_demo'=>'2026-10-03'))),true));}else{$postId=$post->ID;}
 $meta=array('_adc_title_en'=>$row['display_title_en'],'_adc_content_en'=>'<p>Synthetic new-vehicle development example. Price, mileage and identity are fictional and do not represent an Auto Brands sales offer or actual stock. Placeholder artwork does not depict a specific vehicle.</p>','_car_make'=>$row['brand'],'_car_model'=>$row['model'],'_car_year'=>$row['model_year'],'_car_price'=>$row['illustrative_price_sar'],'_car_mileage'=>'0','_car_condition'=>'new','_car_inventory_status'=>'available');
 $changes=array();foreach($meta as $key=>$value){$changes[]=array('post_id'=>$postId,'key'=>$key,'value'=>$value);}$requireResult(PostMetaStore::apply($changes,'content.autobrands_demo_prepared','car',$postId));
 $term=get_term_by('slug','ab-demo-'.strtolower($row['brand']),'car_brand');
 if(!$term){$termId=$requireResult(wp_insert_term('[تجريبي] '.$row['brand'],'car_brand',array('slug'=>'ab-demo-'.strtolower($row['brand']))))['term_id'];}else{$termId=$term->term_id;}
 $requireResult(wp_set_object_terms($postId,array((int)$termId),'car_brand',false));
 $mapped=(int)$wpdb->get_var($wpdb->prepare('SELECT public_post_id FROM '.Schema::table('vehicles').' WHERE id=%d',$id));
 if(!$mapped){$requireResult(CatalogMappingService::assign($id,$postId,'Owner authorized local synthetic inventory'));}elseif($mapped!==(int)$postId){throw new RuntimeException('Mapping collision');}
 echo "$stock: vehicle $id / public post $postId prepared.\n";
}
echo "Six development vehicles prepared; old transactions preserved; no Makkah stock or external provider activated.\n";
