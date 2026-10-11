<?php
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Inventory\OnyxCatalogNames as Names;
use AutoDealership\Inventory\OnyxImportService as Import;
use AutoDealership\Purchasing\SupplierNameDirectory as Directory;
use AutoDealership\Purchasing\SupplierService as Suppliers;
use AutoDealership\Database\Transaction;
use AutoDealership\Database\Schema;
global $wpdb;
$name_checks=0;$test=static function(bool $ok,string $message)use(&$name_checks):void{if(!$ok){throw new RuntimeException($message);}++$name_checks;echo "PASS $message\n";};
wp_set_current_user((int)get_user_by('login','verify_admin')->ID);
$test(Names::row(array('brand'=>'JMC','model'=>'جراند افنيو','trim_name'=>'غمارتين دبل بنزين','model_year'=>2024,'exterior_color'=>'ابيض'))==='JMC جراند افنيو غمارتين دبل بنزين 2024','Full name uses brand, model, trim and model year without a physical identifier or color');
$test(Directory::normalized(" ACME\u{00A0}  Motors ")===Directory::normalized('acme motors'),'Supplier name matching normalizes Unicode spacing and case');
$missing=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('onyx_stock')." WHERE supplier_name<>'' AND supplier_id IS NULL");$test($missing===0,'Every nonempty imported supplier name links to a directory record');
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('suppliers'));
$preview=Import::preview(ABSPATH.'docs/مخزون جديد بتاريخ 6-10-2026.xlsx','reconcile.xlsx','partial');$repeat=Import::apply($preview['id']);
$test(is_array($repeat)&&$repeat['suppliers_created']===0&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('suppliers'))===$before,'Repeated workbook import creates no duplicate suppliers');
$names=$wpdb->get_results('SELECT supplier_name,supplier_id FROM '.Schema::table('onyx_stock')." WHERE supplier_name<>''",ARRAY_A);$map=array();$unique=true;foreach($names as$row){$key=Directory::normalized($row['supplier_name']);if(isset($map[$key])&&$map[$key]!==$row['supplier_id']){$unique=false;}$map[$key]=$row['supplier_id'];}
$test($unique,'Equivalent supplier names across physical stock resolve to one supplier ID');
$manual=Suppliers::create(array('supplier_code'=>'NAME-MATCH-TEST','display_name'=>'Existing Import Supplier','tax_number'=>'KEEP-TAX','contact_phone'=>'KEEP-PHONE'));
Suppliers::set_active($manual['id'],false,'Preserve disabled supplier');
Transaction::begin();$match=Directory::import(' existing   import supplier ');$ok=Transaction::commit(static fn()=>true);
$test($ok&&!$match['created']&&$match['id']===$manual['id'],'Import adopts a matching supplier already entered manually');
$preserved=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('suppliers').' WHERE id=%d',$manual['id']),ARRAY_A);
$test((int)$preserved['active']===0&&$preserved['tax_number']==='KEEP-TAX'&&$preserved['contact_phone']==='KEEP-PHONE','Import preserves disabled state, tax number and existing contact information');
$test(is_wp_error(Suppliers::create(array('supplier_code'=>'DIFFERENT-CODE','display_name'=>'EXISTING  IMPORT SUPPLIER'))),'Manual entry also rejects duplicate supplier names under a different code');
$other=Suppliers::create(array('supplier_code'=>'DISTINCT-NAME','display_name'=>'Existing Import Suppliers'));
$test(is_array($other)&&$other['id']!==$manual['id'],'Similar but different supplier names are not merged by fuzzy matching');
$count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('suppliers'));Transaction::begin();$new=Directory::import('Rolled back source supplier');Transaction::commit(static fn()=>false);
$test($new['created']&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('suppliers'))===$count,'Supplier and name claim roll back together on an import failure');
$pages=$wpdb->get_results('SELECT m.id,m.public_post_id FROM '.Schema::table('catalog_models')." m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id WHERE p.post_type='car'",ARRAY_A);$correct=true;
foreach($pages as$page){$post_id=(int)$page['public_post_id'];$full=Names::for_model((int)$page['id']);$correct=$correct&&get_post($post_id)->post_title===$full&&get_the_title($post_id)===$full;}
$test($correct,'Workbook reconciliation updates stored and displayed names for published and draft pages');
$page=$pages[0];$post_id=(int)$page['public_post_id'];$model_id=(int)$page['id'];$source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('onyx_stock').' WHERE model_id=%d LIMIT 1',$model_id),ARRAY_A);
$duplicate=$source;unset($duplicate['id']);$duplicate['source_identifier']='NAMETEST01';$duplicate['vehicle_id']=null;$duplicate['present']=1;$wpdb->insert(Schema::table('onyx_stock'),$duplicate);$duplicate_id=(int)$wpdb->insert_id;$title=Names::for_model($model_id);
$test(substr_count($title,trim($source['trim_name'].' '.$source['model_year']))===1,'Repeated VINs do not repeat the trim/year portion of a model name');
$wpdb->update(Schema::table('onyx_stock'),array('trim_name'=>'Distinct trim','model_year'=>2026),array('id'=>$duplicate_id));$configuration_split=Import::complete_model_drafts();$separated=(int)$wpdb->get_var($wpdb->prepare('SELECT model_id FROM '.Schema::table('onyx_stock').' WHERE id=%d',$duplicate_id));$combined=Names::for_model($separated);
$test(is_array($configuration_split)&&$separated!==$model_id&&str_contains($combined,'Distinct trim 2026')&&!str_contains($combined,' / '),'Different trim/year configurations have independent editable pages');
$wpdb->delete(Schema::table('onyx_stock'),array('id'=>$duplicate_id));
$_GET=array('page'=>'adc-car-publishing','post_id'=>$post_id);ob_start();\AutoDealership\Admin\CatalogPublishingPage::render();$html=ob_get_clean();$_GET=array();
$test((bool)preg_match('/name="title"[^>]*readonly/',$html),'Mapped vehicle name is displayed as an automatic read-only source field');
// Exercise the actual upgrade path from one legacy model page to independent configurations.
$legacy_brand='Legacy split fixture';$legacy_model='Migration model';
$wpdb->insert(Schema::table('catalog_models'),array('model_key'=>Import::model_key($legacy_brand,$legacy_model),'brand'=>$legacy_brand,'model'=>$legacy_model,'created_at'=>current_time('mysql',true)));$legacy_id=(int)$wpdb->insert_id;
$legacy_page=wp_insert_post(array('post_type'=>'car','post_status'=>'publish','post_title'=>'Legacy combined title','post_content'=>'Keep reviewed description','post_name'=>'keep-legacy-model-url'));update_post_meta($legacy_page,'_car_plate_type','private_transport');update_post_meta($legacy_page,'_thumbnail_id',123456);
$wpdb->update(Schema::table('catalog_models'),array('public_post_id'=>$legacy_page),array('id'=>$legacy_id));
$legacy_sources=array();foreach(array(array('LE',2026,'White'),array('LE',2026,'Black'),array('XLE',2025,'Red'))as$i=>$spec){$fixture=$source;unset($fixture['id']);$fixture['source_identifier']='SPLITFIXTURE0'.$i;$fixture['brand']=$legacy_brand;$fixture['model']=$legacy_model;$fixture['trim_name']=$spec[0];$fixture['model_year']=$spec[1];$fixture['exterior_color']=$spec[2];$fixture['model_id']=$legacy_id;$fixture['present']=1;$fixture['vehicle_id']=null;$wpdb->insert(Schema::table('onyx_stock'),$fixture);$legacy_sources[]=(int)$wpdb->insert_id;}
$legacy_before=get_post($legacy_page);$before_models=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('catalog_models'));
$fail_split=static function(string $sql):string{return str_contains($sql,'INSERT INTO')&&str_contains($sql,Schema::table('audit_events'))?'INSERT INTO adc_missing_split_audit VALUES(1)':$sql;};$wpdb->suppress_errors(true);add_filter('query',$fail_split);$rolled=Import::complete_model_drafts();remove_filter('query',$fail_split);$wpdb->suppress_errors(false);
$test(is_wp_error($rolled)&&(int)$wpdb->get_var('SELECT COUNT(*) FROM '.Schema::table('catalog_models'))===$before_models&&get_post($legacy_page)->post_title===$legacy_before->post_title&&get_post_meta($legacy_page,'_adc_onyx_model_key',true)==='','Legacy splitting and title/meta changes roll back together when audit fails');
$split_result=Import::complete_model_drafts();$split_groups=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.Schema::table('catalog_models').' WHERE brand=%s AND model=%s',$legacy_brand,$legacy_model),ARRAY_A);
$test(is_array($split_result)&&$split_result['created']===1&&count($split_groups)===2,'Legacy combined page splits into exactly two configuration pages, not three VIN or color pages');
$original=get_post($legacy_page);$test($original->post_status==='publish'&&$original->post_name===$legacy_before->post_name&&$original->post_content===$legacy_before->post_content&&get_post_meta($legacy_page,'_car_plate_type',true)==='private_transport'&&(int)get_post_meta($legacy_page,'_thumbnail_id',true)===123456,'Splitting preserves the original publication, URL, description, image and plate selection');
$test($original->post_title===$legacy_brand.' '.$legacy_model.' LE 2026','The retained page has one complete configuration name without merged titles');
$new_page=(int)array_values(array_filter($split_groups,static fn($g)=>(int)$g['public_post_id']!==$legacy_page))[0]['public_post_id'];
$test(get_post_status($new_page)==='draft'&&get_post($new_page)->post_title===$legacy_brand.' '.$legacy_model.' XLE 2025','Additional configuration is an independent editable draft with its own exact name');
$test(count(\AutoDealership\Inventory\GroupedModelCatalog::view($legacy_page)['catalog_model']['colors'])===2&&\AutoDealership\Inventory\GroupedModelCatalog::view($new_page)['catalog_model']['colors']===array('Red'),'Colors stay within their matching trim/year page and do not leak between configurations');
$split_again=Import::complete_model_drafts();$test(is_array($split_again)&&$split_again['created']===0,'Repeating legacy reconciliation creates no additional configuration pages');
wp_set_current_user(0);Transaction::begin();$denied=Directory::import('Denied supplier');$wpdb->query('ROLLBACK');$test(is_wp_error($denied),'Anonymous callers cannot provision Onyx suppliers');
wp_set_current_user((int)get_user_by('login','verify_admin')->ID);
echo "Onyx names/suppliers checks passed: $name_checks\n";
