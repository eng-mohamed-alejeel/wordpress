<?php
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Content\CatalogPublishingService as Publishing;
use AutoDealership\Admin\CatalogPublishingPage as PublishingPage;
use AutoDealership\Database\Schema;
global $wpdb;
$publishing_checks=0;
$verify=static function(bool $ok,string $message)use(&$publishing_checks):void{if(!$ok){throw new RuntimeException($message);}++$publishing_checks;echo "PASS $message\n";};
$publisher=(int)get_user_by('login','verify_admin')->ID;wp_set_current_user($publisher);
\AutoDealership\Content\ContentRegistry::register();
require_once ABSPATH.'wp-admin/includes/template.php';
$model=$wpdb->get_row('SELECT m.* FROM '.Schema::table('catalog_models').' m WHERE NOT EXISTS (SELECT 1 FROM '.Schema::table('onyx_stock').' s WHERE s.model_id=m.id AND s.present=1) ORDER BY m.id LIMIT 1',ARRAY_A);
// Isolated fixture: remove an automatic draft to exercise manual recovery of a missing page.
wp_delete_post((int)$model['public_post_id'],true);$wpdb->update(Schema::table('catalog_models'),array('public_post_id'=>null),array('id'=>$model['id']));
$form=array('post_id'=>'0','model_id'=>(string)$model['id'],'title'=>$model['brand'].' '.$model['model'],'description'=>'<p>Reviewed vehicle description.</p>','summary'=>'Model summary','status'=>'draft','revision'=>'','plate_type'=>'private_transport','features'=>"Safety\nComfort",'gallery_ids'=>'','thumbnail_id'=>'0');
wp_set_current_user(0);$verify(is_wp_error(Publishing::save_car($form)),'Anonymous caller cannot create a published model page');wp_set_current_user($publisher);
$created=Publishing::save_car($form);$verify(is_array($created),'Dedicated publisher creates an Onyx-linked draft atomically'.(is_wp_error($created)?': '.$created->get_error_code().' '.$created->get_error_message():''));$id=$created['id'];
$verify(get_post_status($id)==='draft'&&(int)$wpdb->get_var($wpdb->prepare('SELECT public_post_id FROM '.Schema::table('catalog_models').' WHERE id=%d',$model['id']))===$id,'Draft is linked to its original model without publication');
$verify(is_wp_error(Publishing::save_car($form)),'Repeated create cannot duplicate the same model page');
$verify(get_edit_post_link($id,'raw')===PublishingPage::url($id),'Every vehicle edit link opens the dedicated publisher');
$verify(PublishingPage::legacy_destination('edit.php',array('post_type'=>'car'))===PublishingPage::url(),'Legacy vehicle list bookmark resolves to the custom workspace');
$verify(PublishingPage::legacy_destination('edit-tags.php',array('taxonomy'=>'car_brand'))===PublishingPage::term_url('car_brand'),'Legacy brand bookmark resolves to custom management');
$verify(PublishingPage::legacy_destination('term.php',array('taxonomy'=>'car_category','tag_ID'=>4))===PublishingPage::term_url('car_category',4),'Legacy category editing retains its term identity');
$verify(PublishingPage::legacy_destination('post.php',array('post'=>$id,'action'=>'untrash'))==='','Native nonce-protected trash restoration is not intercepted');
$editing=array_replace($form,array('post_id'=>(string)$id,'revision'=>Publishing::fingerprint($id),'status'=>'publish'));
$verify(is_wp_error(Publishing::save_car($editing))&&get_post_status($id)==='draft','Publication still rejects unavailable source inventory even when photos and description are optional');
$image=wp_insert_attachment(array('post_title'=>'Vehicle test image','post_mime_type'=>'image/jpeg','post_status'=>'inherit'));
update_post_meta($image,'_wp_attached_file','2026/publishing-fixture.jpg');
wp_update_attachment_metadata($image,array('file'=>'2026/publishing-fixture.jpg','width'=>100,'height'=>80));
$editing['thumbnail_id']=(string)$image;$editing['gallery_ids']=(string)$image;
$missing_stock=Publishing::save_car($editing);$verify(is_wp_error($missing_stock)&&$missing_stock->get_error_code()==='adc_stock'&&get_post_status($id)==='draft','Grouped publication rejects a model with no available inventory');
$wpdb->update(Schema::table('onyx_stock'),array('present'=>1),array('model_id'=>$model['id']));
$optional=array_replace($editing,array('thumbnail_id'=>'0','gallery_ids'=>'','description'=>''));$optional_saved=Publishing::save_car($optional);
$verify(is_array($optional_saved)&&get_post_status($id)==='publish'&&get_post($id)->post_content===''&&!get_post_thumbnail_id($id),'Available vehicle publishes with empty description and default image');
$optional['revision']=Publishing::fingerprint($id);$verify(is_array(Publishing::save_car($optional)),'Published vehicle can be edited and saved with empty description and default image');
$editing['revision']=Publishing::fingerprint($id);
$published=Publishing::save_car($editing);$verify(is_array($published)&&get_post_status($id)==='publish','Dedicated form publishes a reviewed available model'.(is_wp_error($published)?': '.$published->get_error_code().' '.$published->get_error_message():''));
$verify(get_post_meta($id,'_car_plate_type',true)==='private_transport'&&get_post_meta($id,'_car_gallery_ids',true)===array($image),'Plate choice and gallery persist independently from net inventory price');
// Color metadata shares the existing atomic editorial save, without touching Onyx source rows.
$color_names=\AutoDealership\Inventory\VehicleColors::source_names($id);$color_key=\AutoDealership\Inventory\VehicleColors::gallery_key($color_names[0]??'ابيض');$archive_key=hash('sha256','historical-unavailable-color');
update_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,array($archive_key=>array($image)));
$editing['revision']=Publishing::fingerprint($id);$editing['color_galleries_present']='1';$editing['color_galleries']=array($color_key=>(string)$image);
$verify(is_array(Publishing::save_car($editing)),'Dedicated publishing saves a per-color image gallery atomically');
$color_meta=get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true);
$verify($color_meta[$color_key]===array($image)&&$color_meta[$archive_key]===array($image),'Current and historical color galleries coexist without data loss');
$legacy_edit=$editing;unset($legacy_edit['color_galleries_present'],$legacy_edit['color_galleries']);$legacy_edit['revision']=Publishing::fingerprint($id);
$verify(is_array(Publishing::save_car($legacy_edit))&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true)===$color_meta,'Legacy save without color fields preserves all color galleries');
$bad_color=$editing;$bad_color['revision']=Publishing::fingerprint($id);$bad_color['color_galleries']=array($color_key=>'https://example.invalid/not-an-attachment.jpg');
$verify(is_wp_error(Publishing::save_car($bad_color))&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true)===$color_meta,'Invalid color photo input cannot modify publication or galleries');
$clear_color=$editing;$clear_color['revision']=Publishing::fingerprint($id);$clear_color['color_galleries']=array($color_key=>'');
$verify(is_array(Publishing::save_car($clear_color))&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true)[$color_key]===array()&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true)[$archive_key]===array($image),'Clearing one color leaves historical galleries intact');
$editing['revision']=Publishing::fingerprint($id);
$color_image=wp_insert_attachment(array('post_title'=>'Color main image fixture','post_mime_type'=>'image/jpeg','post_status'=>'inherit'));update_post_meta($color_image,'_wp_attached_file','2026/color-main-fixture.jpg');wp_update_attachment_metadata($color_image,array('file'=>'2026/color-main-fixture.jpg','width'=>100,'height'=>80));
$editing['revision']=Publishing::fingerprint($id);$editing['color_primary_present']='1';$editing['color_main']=array($color_key=>(string)$color_image);$editing['color_galleries']=array($color_key=>(string)$image);$editing['default_color']=$color_key;
$verify(is_array(Publishing::save_car($editing)),'Explicit main color image and default display color save with the existing publishing transaction');
$verify(get_post_meta($id,\AutoDealership\Inventory\VehicleColors::META,true)[$color_key]===array($color_image,$image)&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::MAIN_META,true)[$color_key]===$color_image,'Main image remains first independently from additional image ordering');
$verify(get_post_meta($id,\AutoDealership\Inventory\VehicleColors::DEFAULT_META,true)===$color_key&&\AutoDealership\Inventory\VehicleColors::default_image($id)===$color_image,'Saved default color supplies the public vehicle card image');
$verify((int)get_post_meta($id,'_thumbnail_id',true)===$image&&get_post_meta($id,'_car_gallery_ids',true)===array($image),'Color main images never replace or delete general fallback photos');
$invalid_main=$editing;$invalid_main['revision']=Publishing::fingerprint($id);$invalid_main['color_main']=array($color_key=>'0');$verify(is_wp_error(Publishing::save_car($invalid_main)),'Additional photos cannot be saved with a missing explicit primary image');
$invalid_default=$editing;$invalid_default['revision']=Publishing::fingerprint($id);$invalid_default['default_color']=hash('sha256','unknown-default-color');$verify(is_wp_error(Publishing::save_car($invalid_default)),'A forged default color outside the model source names is rejected');
$legacy_primary=$editing;unset($legacy_primary['color_primary_present'],$legacy_primary['color_main'],$legacy_primary['default_color'],$legacy_primary['color_galleries_present'],$legacy_primary['color_galleries']);$legacy_primary['revision']=Publishing::fingerprint($id);$verify(is_array(Publishing::save_car($legacy_primary))&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::MAIN_META,true)[$color_key]===$color_image&&get_post_meta($id,\AutoDealership\Inventory\VehicleColors::DEFAULT_META,true)===$color_key,'An older editor preserves explicit primary images and default color preferences');
$color_only=$editing;unset($color_only['thumbnail_id'],$color_only['gallery_ids']);$color_only['revision']=Publishing::fingerprint($id);
$verify(is_array(Publishing::save_car($color_only))&&(int)get_post_meta($id,'_thumbnail_id',true)===$image&&get_post_meta($id,'_car_gallery_ids',true)===array($image),'Color-only publishing does not clear unrelated archived attachment metadata');
// Technical details use the same editorial transaction, never source-stock writes.
$technical_class=\AutoDealership\Inventory\VehicleTechnicalDetails::class;
$technical_form=$color_only;$technical_form['revision']=Publishing::fingerprint($id);$technical_form['technical_present']='1';
$technical_base=array('fuel'=>'diesel','transmission'=>'manual','capacity'=>'2.5','capacity_unit'=>'l','speeds'=>'5','body_type'=>'pickup','usage'=>'commercial','equipment'=>'chiller');
$technical_form['technical_base']=$technical_base;$technical_form['technical_variants']=array();
foreach($technical_class::variants($id)as$key=>$variant){$technical_form['technical_variants'][$key]=array_fill_keys(array_keys($technical_base),'');$technical_form['technical_variants'][$key]['capacity_unit']='cc';}
$onyx_before=hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.Schema::table('onyx_stock').' ORDER BY id',ARRAY_A)));
$verify(is_array(Publishing::save_car($technical_form)),'Technical specifications persist atomically through the real publishing service');
$technical_saved=get_post_meta($id,$technical_class::META,true);$verify($technical_saved['base']['capacity_cc']===2500&&$technical_saved['base']['fuel']==='diesel','Fuel, engine capacity and units survive a database round trip');
$verify($onyx_before===hash('sha256',serialize($wpdb->get_results('SELECT * FROM '.Schema::table('onyx_stock').' ORDER BY id',ARRAY_A))),'Editorial specifications leave all imported Onyx stock rows unchanged');
$historical_key=hash('sha256','historical-technical-trim');$technical_saved['variants'][$historical_key]=array_replace($technical_saved['base'],array('capacity_cc'=>3000));update_post_meta($id,$technical_class::META,$technical_saved);
$technical_form['revision']=Publishing::fingerprint($id);$verify(is_array(Publishing::save_car($technical_form))&&get_post_meta($id,$technical_class::META,true)['variants'][$historical_key]['capacity_cc']===3000,'Saving current variants preserves historical trim specifications');
$legacy_spec=$color_only;$legacy_spec['revision']=Publishing::fingerprint($id);$technical_saved=get_post_meta($id,$technical_class::META,true);$verify(is_array(Publishing::save_car($legacy_spec))&&get_post_meta($id,$technical_class::META,true)===$technical_saved,'Older publishing forms preserve all technical details');
$invalid_spec=$technical_form;$invalid_spec['revision']=Publishing::fingerprint($id);$invalid_spec['technical_base']['fuel']='forged';$verify(is_wp_error(Publishing::save_car($invalid_spec))&&get_post_meta($id,$technical_class::META,true)===$technical_saved,'Invalid specification cannot modify existing metadata');
$technical_form['revision']=Publishing::fingerprint($id);$technical_form['technical_base']['capacity']='3.0';
$technical_fault=static fn(string $sql):string=>str_contains($sql,'INSERT INTO')&&str_contains($sql,Schema::table('audit_events'))?'INSERT INTO adc_missing_technical_audit VALUES(1)':$sql;
$wpdb->suppress_errors(true);add_filter('query',$technical_fault);$technical_failed=Publishing::save_car($technical_form);remove_filter('query',$technical_fault);$verify(is_wp_error($technical_failed)&&get_post_meta($id,$technical_class::META,true)===$technical_saved,'Audit failure rolls back specification metadata with the vehicle publication');
$technical_form['revision']=Publishing::fingerprint($id);$technical_form['technical_base']['capacity']='';$technical_form['technical_base']['fuel']='';$verify(is_array(Publishing::save_car($technical_form))&&get_post_meta($id,$technical_class::META,true)['base']['capacity_cc']===''&&get_post_meta($id,$technical_class::META,true)['base']['fuel']==='','Clearing a specification restores source inheritance without deleting other fields');
$technical_form['revision']=Publishing::fingerprint($id);wp_set_current_user(0);$verify(is_wp_error(Publishing::save_car($technical_form)),'Anonymous callers cannot save technical specifications');wp_set_current_user($publisher);
$editing['revision']=Publishing::fingerprint($id);
$stable_url=get_permalink($id);$editing['revision']=Publishing::fingerprint($id);$old_revision=$editing['revision'];$editing['title'].=' updated';$editing['description'].='<p>Updated review.</p>';
$verify(is_array(Publishing::save_car($editing))&&get_permalink($id)===$stable_url,'Editing vehicle content preserves its published URL and ID');
$editing['revision']=$old_revision;$verify(is_wp_error(Publishing::save_car($editing)),'An outdated publishing form cannot overwrite a newer save');
$editing['revision']=Publishing::fingerprint($id);$before=get_post($id)->post_title;$editing['title']='Must roll back';
$fault=static function(string $sql):string{return str_contains($sql,'INSERT INTO')&&str_contains($sql,Schema::table('audit_events'))?'INSERT INTO adc_missing_publication_audit VALUES(1)':$sql;};
$wpdb->suppress_errors(true);add_filter('query',$fault);$failed=Publishing::save_car($editing);remove_filter('query',$fault);
$verify(is_wp_error($failed)&&get_post($id)->post_title===$before,'Audit failure restores the complete vehicle publication');
$before_trash=Publishing::fingerprint($id);
$verify(is_wp_error(Publishing::change_status($id,'restore',$before_trash))&&get_post_status($id)!=='trash','Restore action cannot change a vehicle that is not in trash');
$verify(is_array(Publishing::change_status($id,'trash',Publishing::fingerprint($id)))&&get_post_status($id)==='trash','Publisher moves a page to trash without deleting model identity');
$verify(is_wp_error(Publishing::change_status($id,'trash',$before_trash))&&get_post_status($id)==='trash','Repeated stale trash submission cannot delete the vehicle page');
$restore_published=static fn()=> 'publish';add_filter('wp_untrash_post_status',$restore_published);
$verify(is_array(Publishing::change_status($id,'restore',Publishing::fingerprint($id)))&&get_post_status($id)==='draft','Restoration keeps the same page and returns it safely to draft');
remove_filter('wp_untrash_post_status',$restore_published);
$_GET=array('page'=>'adc-car-publishing','post_id'=>$id);ob_start();PublishingPage::render();$editor=ob_get_clean();
$verify(str_contains($editor,'name="model_id"')===false&&!str_contains($editor,'name="thumbnail_id"')&&!str_contains($editor,'name="gallery_ids"')&&str_contains($editor,'name="color_main[')&&str_contains($editor,'onyx-variant-table'),'Dedicated editor contains photos, source specs and publication controls without an article screen');
$_GET=array('page'=>'adc-car-publishing');ob_start();PublishingPage::render();$list=ob_get_clean();$verify(str_contains($list,'adc-publishing-filters')&&str_contains($list,'adc_catalog_status'),'Custom vehicle list provides search, status filters and scoped actions');
$source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.\AutoDealership\Database\Schema::table('catalog_models').' WHERE public_post_id=%d',$id),ARRAY_A);
$_GET=array('page'=>'adc-car-publishing','brand'=>$source['brand'],'model'=>$source['model'],'trim_name'=>$source['trim_name'],'model_year'=>(string)$source['model_year'],'availability'=>'available');ob_start();PublishingPage::render();$filtered=ob_get_clean();
$verify(str_contains($filtered,esc_url(PublishingPage::url($id)))&&str_contains($filtered,'name="availability"'),'Combined exact source identity filters retain an available editable page');
$_GET['brand']="missing' OR 1=1 --";ob_start();PublishingPage::render();$filtered=ob_get_clean();$verify(!str_contains($filtered,esc_url(PublishingPage::url($id))),'Source filters safely bind values and do not widen the results');
$_GET=array('page'=>'adc-car-publishing','brand'=>$source['brand'],'model'=>$source['model'],'trim_name'=>$source['trim_name'],'model_year'=>(string)$source['model_year'],'availability'=>'unavailable');ob_start();PublishingPage::render();$filtered=ob_get_clean();$verify(!str_contains($filtered,esc_url(PublishingPage::url($id))),'Unavailable filter excludes an in-stock configuration');
$_GET=array('page'=>'adc-onyx');ob_start();\AutoDealership\Admin\OnyxInventoryPage::render();$summary=ob_get_clean();$verify(str_contains($summary,'adc-onyx-summary')&&str_contains($summary,'adc-car-publishing')&&!str_contains($summary,'value="adc_onyx_model"'),'Onyx summary links to website vehicles without duplicating its list');
$_GET=array();
$term_form=array('taxonomy'=>'car_brand','term_id'=>'0','name'=>'Publisher brand','slug'=>'publisher-brand','description'=>'Reviewed brand','name_ar'=>'ماركة النشر','name_en'=>'Publisher brand','image_id'=>(string)$image,'revision'=>'');
$brand=Publishing::save_term($term_form);$verify(is_array($brand),'Brand manager creates a classification with reviewed translations');$brand_id=$brand['id'];
$verify((int)get_term_meta($brand_id,'_adc_catalog_image_id',true)===$image&&get_term_meta($brand_id,'_adc_name_ar',true)==='ماركة النشر','Logo and bilingual names are stored as separate taxonomy metadata');
$term_form['term_id']=(string)$brand_id;$term_form['revision']=Publishing::term_fingerprint($brand_id,'car_brand');$term_form['description']='Changed';
$verify(is_array(Publishing::save_term($term_form)),'Brand details save without changing classification identity');
$verify(is_wp_error(Publishing::save_term($term_form)),'Outdated taxonomy form cannot overwrite a newer change');
$term_form['revision']=Publishing::term_fingerprint($brand_id,'car_brand');$term_form['description']='Must roll back';add_filter('query',$fault);$bad_term=Publishing::save_term($term_form);remove_filter('query',$fault);
$verify(is_wp_error($bad_term)&&get_term($brand_id,'car_brand',OBJECT,'raw')->description==='Changed','Taxonomy audit failure restores its original content and metadata');
$category=Publishing::save_term(array_replace($term_form,array('taxonomy'=>'car_category','term_id'=>'0','name'=>'Publisher category','slug'=>'publisher-category','revision'=>'','image_id'=>'0')));
$verify(is_array($category),'Vehicle category is managed independently of brands');
$cycle=Publishing::save_term(array_replace($term_form,array('taxonomy'=>'car_category','term_id'=>(string)$category['id'],'parent'=>(string)$category['id'],'revision'=>Publishing::term_fingerprint($category['id'],'car_category'))));$verify(is_wp_error($cycle),'Category parent cannot form a cycle');
$_GET=array('page'=>'adc-car-brands','term_id'=>$brand_id);ob_start();PublishingPage::terms();$brands_html=ob_get_clean();$verify(str_contains($brands_html,'name="name_ar"')&&str_contains($brands_html,'name="image_id"'),'Custom brand editor shows translated names and media controls');
$_GET=array('page'=>'adc-car-categories','term_id'=>$category['id']);ob_start();PublishingPage::terms();$categories_html=ob_get_clean();$verify(str_contains($categories_html,'name="parent"'),'Custom category editor supports hierarchical classifications');
$_GET=array('page'=>'adc-car-publishing','post_id'=>$id,'lang'=>'en');ob_start();PublishingPage::render();$english=ob_get_clean();$verify(str_contains($english,'Edit vehicle page')&&str_contains($english,'Main color image')&&!str_contains($english,'General fallback photos'),'Dedicated publisher supports the English interface');
$_GET=array();wp_set_current_user(0);$verify(is_wp_error(Publishing::save_term($term_form)),'Anonymous caller cannot change vehicle classifications');wp_set_current_user($publisher);

if ( ! function_exists( 'curl_init' ) || ! defined( 'ADC_TEST_HTTP_CONFIG' ) ) { throw new RuntimeException( 'Catalog publishing HTTP verification requires cURL and isolated database configuration.' ); }
update_option( 'active_plugins', array( 'auto-dealership-core/auto-dealership-core.php' ) );
$catalog_listener=stream_socket_server('tcp://127.0.0.1:0',$catalog_socket_error,$catalog_socket_message);
if(false===$catalog_listener){throw new RuntimeException('Cannot allocate catalog HTTP test port: '.$catalog_socket_message);}
$catalog_listener_name=stream_socket_get_name($catalog_listener,false);fclose($catalog_listener);
$catalog_port=(int)substr($catalog_listener_name,strrpos($catalog_listener_name,':')+1);
$catalog_http_log=tempnam(sys_get_temp_dir(),'adc-catalog-http-');$catalog_http_process=null;
try{
	$catalog_http_process=proc_open(array(PHP_BINARY,'-S','127.0.0.1:'.$catalog_port,__DIR__.'/http-router.php'),array(0=>array('pipe','r'),1=>array('file',$catalog_http_log,'a'),2=>array('file',$catalog_http_log,'a')),$catalog_http_pipes,ABSPATH,array_merge(getenv(),array('ADC_HTTP_TEST_CONFIG'=>ADC_TEST_HTTP_CONFIG)));
	if(!is_resource($catalog_http_process)){throw new RuntimeException('Cannot start catalog HTTP verification server.');}
	fclose($catalog_http_pipes[0]);$catalog_http_ready=false;
	for($attempt=0;$attempt<50;++$attempt){$catalog_probe=@fsockopen('127.0.0.1',$catalog_port,$catalog_probe_error,$catalog_probe_message,0.1);if(is_resource($catalog_probe)){fclose($catalog_probe);$catalog_http_ready=true;break;}usleep(100000);}
	if(!$catalog_http_ready){throw new RuntimeException('Catalog HTTP verification server did not become ready.');}
	$catalog_request=static function(string $path,string $cookie,?array $form=null)use($catalog_port):array{
		$handle=curl_init('http://127.0.0.1:'.$catalog_port.$path);$headers=array('Host: adc-verification.invalid');
		if(''!==$cookie){$headers[]='Cookie: '.$cookie;}
		$options=array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false);
		if(null!==$form){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=isset($form['content_file'])&&$form['content_file'] instanceof CURLFile?$form:http_build_query($form);}
		curl_setopt_array($handle,$options);$response=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$header_size=(int)curl_getinfo($handle,CURLINFO_HEADER_SIZE);$error=curl_error($handle);curl_close($handle);
		if(false===$response){throw new RuntimeException('Catalog HTTP request failed: '.$error);}
		return array($status,substr($response,$header_size),substr($response,0,$header_size));
	};
	$catalog_auth=static function(int $user_id)use($catalog_request):string{
		$key=hash('sha256',ADC_TEST_HTTP_CONFIG.'|session');$response=$catalog_request('/adc-test-session?user_id='.$user_id.'&key='.rawurlencode($key),'');
		preg_match_all('/^Set-Cookie:\s*([^=;\r\n]+)=([^;\r\n]*)/mi',$response[2],$matches,PREG_SET_ORDER);$cookies=array();foreach($matches as$match){$cookies[]=$match[1].'='.$match[2];}
		if(200!==$response[0]||!$cookies){throw new RuntimeException('Cannot create isolated catalog HTTP session.');}
		return implode('; ',$cookies);
	};
	$catalog_nonce=static function(string $action,string $cookie)use($catalog_request):string{$response=$catalog_request('/adc-test-nonce?action='.rawurlencode($action),$cookie);if(200!==$response[0]||10!==strlen(trim($response[1]))){throw new RuntimeException('Cannot create catalog HTTP nonce.');}return trim($response[1]);};
	$catalog_admin_cookie=$catalog_auth($publisher);$catalog_sales=wp_insert_user(array('user_login'=>'catalog_http_sales','user_pass'=>wp_generate_password(),'role'=>'dealership_sales'));
	if(is_wp_error($catalog_sales)){throw new RuntimeException('Cannot create catalog authorization fixture.');}
	$catalog_sales_cookie=$catalog_auth((int)$catalog_sales);$catalog_audit=Schema::table('audit_events');

	$catalog_term_slug='http-catalog-'.strtolower(bin2hex(random_bytes(5)));
	$catalog_find_term=static fn()=>$wpdb->get_var($wpdb->prepare("SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.slug=%s AND tt.taxonomy=%s",$catalog_term_slug,'car_brand'));
	$catalog_term_form=array('action'=>'adc_catalog_save_term','taxonomy'=>'car_brand','term_id'=>'0','name'=>'HTTP Catalog Brand','slug'=>$catalog_term_slug,'description'=>'Catalog HTTP test','name_ar'=>'علامة اختبار الكتالوج','name_en'=>'Catalog HTTP Test','image_id'=>'0','revision'=>'');
	$catalog_term_form['_wpnonce']=$catalog_nonce('adc_catalog_save_term',$catalog_sales_cookie);
	$verify(302===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_term_form)[0]&&!$catalog_find_term(),'Catalog classification admin-post delegates its permission check and leaves data unchanged for an unauthorized user');
	$catalog_term_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_term_form)[0]&&!$catalog_find_term(),'Catalog classification admin-post rejects an invalid nonce without writing a term');
	$catalog_term_form['_wpnonce']=$catalog_nonce('adc_catalog_save_term',$catalog_admin_cookie);
	$catalog_term_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.classification_saved'));
	$catalog_term_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_term_form);
	$catalog_term_id=(int)$catalog_find_term();
	$catalog_term_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.classification_saved'));
	$verify(302===$catalog_term_response[0]&&$catalog_term_id>0&&$catalog_term_audit_before+1===$catalog_term_audit_after,'Authorized catalog classification admin-post creates the term and records its audit event (HTTP '.$catalog_term_response[0].', term '.$catalog_term_id.', audit '.$catalog_term_audit_before.'->'.$catalog_term_audit_after.', location '.(preg_match('/^Location:\\s*(.+)$/mi',$catalog_term_response[2],$catalog_location)?trim($catalog_location[1]):'missing').')');

	$catalog_status_form=array('action'=>'adc_catalog_status','post_id'=>$id,'change'=>'trash','revision'=>Publishing::fingerprint($id));
	$catalog_status_form['_wpnonce']=$catalog_nonce('adc_catalog_status',$catalog_sales_cookie);
	$verify(302===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_status_form)[0]&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$id)),'Catalog status admin-post refuses unauthorized trash requests without changing the page');
	$catalog_status_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_status_form)[0]&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$id)),'Catalog status admin-post rejects an invalid nonce without changing the page');
	$catalog_status_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.vehicle_trash'));
	$catalog_status_form['_wpnonce']=$catalog_nonce('adc_catalog_status',$catalog_admin_cookie);
	$catalog_status_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_status_form);
	$catalog_trashed_status=$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$id));
	$catalog_status_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.vehicle_trash'));
	$verify(302===$catalog_status_response[0]&&'trash'===$catalog_trashed_status&&$catalog_status_audit_before+1===$catalog_status_audit_after,'Authorized catalog status admin-post moves the page to trash and audits the change (HTTP '.$catalog_status_response[0].', status '.$catalog_trashed_status.', audit '.$catalog_status_audit_before.'->'.$catalog_status_audit_after.')');
	clean_post_cache($id);
	$catalog_restore=array('action'=>'adc_catalog_status','post_id'=>$id,'change'=>'restore','revision'=>Publishing::fingerprint($id),'_wpnonce'=>$catalog_nonce('adc_catalog_status',$catalog_admin_cookie));
	$catalog_restore_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_restore);
	$verify(302===$catalog_restore_response[0]&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$id)),'Authorized catalog restore admin-post returns the same page to draft');

	$catalog_offer_car=0;
	foreach(get_posts(array('post_type'=>'car','post_status'=>'publish','numberposts'=>-1))as$catalog_car){if(\AutoDealership\Inventory\PublicCatalog::is_post_publicly_eligible((int)$catalog_car->ID)){$catalog_offer_car=(int)$catalog_car->ID;break;}}
	if(!$catalog_offer_car){throw new RuntimeException('Catalog HTTP offer tests require an available published vehicle fixture.');}
	$catalog_offer_title='HTTP catalog offer '.strtolower(bin2hex(random_bytes(4)));
	$catalog_offer_form=array('action'=>'adc_offer_save','post_id'=>'0','title'=>$catalog_offer_title,'description'=>'Reviewed offer terms','summary'=>'Isolated HTTP offer','status'=>'draft','car_id'=>(string)$catalog_offer_car,'old_price'=>'90000','new_price'=>'85000','monthly_payment'=>'0','expires'=>gmdate('Y-m-d',time()+86400*7),'thumbnail_id'=>'0','revision'=>'','request_key'=>wp_generate_uuid4());
	$catalog_offer_lookup=static fn()=>(int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_title=%s ORDER BY ID DESC LIMIT 1",'car_offer',$catalog_offer_title));
	$catalog_offer_form['_wpnonce']=$catalog_nonce('adc_offer_save',$catalog_sales_cookie);
	$verify(302===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_offer_form)[0]&&!$catalog_offer_lookup(),'Offer save admin-post rejects unauthorized creation without inserting an offer');
	$catalog_offer_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_offer_form)[0]&&!$catalog_offer_lookup(),'Offer save admin-post rejects an invalid nonce without inserting an offer');
	$catalog_offer_form['_wpnonce']=$catalog_nonce('adc_offer_save',$catalog_admin_cookie);
	$catalog_offer_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.offer_saved'));
	$catalog_offer_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_offer_form);
	$catalog_offer_id=$catalog_offer_lookup();
	$catalog_offer_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.offer_saved'));
	$verify(302===$catalog_offer_response[0]&&$catalog_offer_id>0&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$catalog_offer_id))&&$catalog_offer_audit_before+1===$catalog_offer_audit_after,'Authorized offer save admin-post creates an audited draft linked to an available vehicle');

	$catalog_offer_status=array('action'=>'adc_offer_status','post_id'=>$catalog_offer_id,'change'=>'trash','revision'=>\AutoDealership\Content\OfferPublishingService::fingerprint($catalog_offer_id));
	$catalog_offer_status['_wpnonce']=$catalog_nonce('adc_offer_status',$catalog_sales_cookie);
	$verify(302===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_offer_status)[0]&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$catalog_offer_id)),'Offer status admin-post refuses unauthorized trash requests without changing the offer');
	$catalog_offer_status['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_offer_status)[0]&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$catalog_offer_id)),'Offer status admin-post rejects an invalid nonce without changing the offer');
	$catalog_offer_status_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.offer_trash'));
	$catalog_offer_status['_wpnonce']=$catalog_nonce('adc_offer_status',$catalog_admin_cookie);
	$catalog_offer_trash_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_offer_status);
	$catalog_offer_trash_status=$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$catalog_offer_id));
	$catalog_offer_status_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.offer_trash'));
	$verify(302===$catalog_offer_trash_response[0]&&'trash'===$catalog_offer_trash_status&&$catalog_offer_status_audit_before+1===$catalog_offer_status_audit_after,'Authorized offer status admin-post moves offer to trash and records its audit event');
	clean_post_cache($catalog_offer_id);
	$catalog_offer_restore=array('action'=>'adc_offer_status','post_id'=>$catalog_offer_id,'change'=>'restore','revision'=>\AutoDealership\Content\OfferPublishingService::fingerprint($catalog_offer_id),'_wpnonce'=>$catalog_nonce('adc_offer_status',$catalog_admin_cookie));
	$catalog_offer_restore_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_offer_restore);
	$verify(302===$catalog_offer_restore_response[0]&&'draft'===$wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID=%d",$catalog_offer_id)),'Authorized offer restore admin-post returns the same offer to draft');

	$site_content_option=\AutoDealership\Content\SiteContent::OPTION;
	$site_content_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option));
	$site_content_data=\AutoDealership\Content\SiteContent::data();
	$site_content_data['home']['hero']['title_ar']='محتوى اختبار HTTP';
	$site_content_form=array('action'=>'adc_site_content','tab'=>'home','operation'=>'save','revision'=>\AutoDealership\Content\SiteContent::revision(),'adc_content_complete'=>'1','home'=>$site_content_data['home'],'hero_image'=>(string)$site_content_data['hero_image']);
	$site_content_form['_wpnonce']=$catalog_nonce('adc_site_content',$catalog_sales_cookie);
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$site_content_form)[0]&&$site_content_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option)),'Site content admin-post rejects unauthorized edits without changing stored content');
	$site_content_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$site_content_form)[0]&&$site_content_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option)),'Site content admin-post rejects an invalid nonce without changing stored content');
	$site_content_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.saved'));
	$site_content_form['_wpnonce']=$catalog_nonce('adc_site_content',$catalog_admin_cookie);
	$site_content_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$site_content_form);
	$site_content_saved=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option)));
	$site_content_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",'content.saved'));
	$verify(302===$site_content_response[0]&&'محتوى اختبار HTTP'===($site_content_saved['home']['hero']['title_ar']??'')&&$site_content_audit_before+1===$site_content_audit_after,'Authorized site content admin-post saves reviewed content and records an audit event');

	require_once ABSPATH.'wp-admin/includes/image.php';
	$catalog_import_image_upload=wp_upload_bits('content-transfer-http-fixture.png',null,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aP1sAAAAASUVORK5CYII='));
	if($catalog_import_image_upload['error']){throw new RuntimeException('Cannot create content-transfer HTTP image fixture: '.$catalog_import_image_upload['error']);}
	$catalog_import_image=wp_insert_attachment(array('post_mime_type'=>'image/png','post_title'=>'Content transfer HTTP fixture'),$catalog_import_image_upload['file']);
	if(is_wp_error($catalog_import_image)){throw new RuntimeException('Cannot register content-transfer HTTP image fixture: '.$catalog_import_image->get_error_message());}
	$site_content_data=\AutoDealership\Content\SiteContent::data();$site_content_data['hero_image']=$catalog_import_image;
	$verify(true===\AutoDealership\Content\SiteContent::save($site_content_data,\AutoDealership\Content\SiteContent::revision()),'Image fixture is attached to site content for HTTP export and import coverage');
	$catalog_export_form=array('action'=>'adc_site_content','tab'=>'transfer','operation'=>'export','_wpnonce'=>$catalog_nonce('adc_site_content',$catalog_sales_cookie));
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_export_form)[0],'Content export rejects an unauthorized caller');
	$catalog_export_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_export_form)[0],'Content export rejects an invalid nonce');
	$catalog_export_form['_wpnonce']=$catalog_nonce('adc_site_content',$catalog_admin_cookie);
	$catalog_export_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_export_form);
	$verify(200===$catalog_export_response[0]&&str_contains(strtolower($catalog_export_response[2]),'application/zip')&&str_starts_with($catalog_export_response[1],'PK'),'Authorized content export returns a ZIP archive');
	$catalog_import_archive=tempnam(sys_get_temp_dir(),'adc-content-http-');
	if(false===$catalog_import_archive||false===file_put_contents($catalog_import_archive,$catalog_export_response[1])){throw new RuntimeException('Cannot persist isolated content export for HTTP import test.');}
	$catalog_upload_form=array('action'=>'adc_site_content','tab'=>'transfer','operation'=>'import_preview','content_file'=>new CURLFile($catalog_import_archive,'application/zip','website-content.zip'),'_wpnonce'=>$catalog_nonce('adc_site_content',$catalog_sales_cookie));
	$catalog_import_key='adc_content_import_'.$publisher;
	$catalog_import_option='_transient_'.$catalog_import_key;
	$catalog_import_path='';
	$catalog_transient=static function()use($wpdb,$catalog_import_option):array{$value=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$catalog_import_option));$decoded=maybe_unserialize($value);return is_array($decoded)?$decoded:array();};
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_upload_form)[0]&&!$catalog_transient(),'Import preview rejects an unauthorized caller without staging a package');
	$catalog_upload_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_upload_form)[0]&&!$catalog_transient(),'Import preview rejects an invalid nonce without staging a package');
	$catalog_upload_form['_wpnonce']=$catalog_nonce('adc_site_content',$catalog_admin_cookie);
	$catalog_preview_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_upload_form);
	$catalog_preview=$catalog_transient();$catalog_import_path=(string)($catalog_preview['path']??'');
	$verify(302===$catalog_preview_response[0]&&is_file($catalog_import_path)&&hash_equals((string)($catalog_preview['hash']??''),hash_file('sha256',$catalog_import_path)),'Authorized import preview stages an integrity-hashed package for review');
	$catalog_site_option_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option));
	$catalog_attachment_count=static fn()=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'");
	$catalog_attachments_before=$catalog_attachment_count();
	$catalog_apply_form=array('action'=>'adc_site_content','tab'=>'transfer','operation'=>'import','preview_hash'=>$catalog_preview['hash'],'revision'=>$catalog_preview['revision'],'_wpnonce'=>$catalog_nonce('adc_site_content',$catalog_sales_cookie));
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$catalog_apply_form)[0]&&$catalog_site_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option))&&$catalog_attachment_count()===$catalog_attachments_before,'Import application rejects an unauthorized caller without changing content or attachments');
	$catalog_apply_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_apply_form)[0]&&$catalog_site_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option))&&$catalog_attachment_count()===$catalog_attachments_before,'Import application rejects an invalid nonce without changing content or attachments');
	$site_content_data=\AutoDealership\Content\SiteContent::data();$site_content_data['home']['hero']['title_ar']='محتوى أحدث من المعاينة';
	$verify(true===\AutoDealership\Content\SiteContent::save($site_content_data,\AutoDealership\Content\SiteContent::revision()),'Concurrent content edit advances the revision after import preview');
	$catalog_stale_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option));
	$catalog_apply_form['_wpnonce']=$catalog_nonce('adc_site_content',$catalog_admin_cookie);
	$catalog_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_apply_form);
	$verify(302===$catalog_stale_response[0]&&$catalog_stale_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option))&&$catalog_attachment_count()===$catalog_attachments_before,'Stale import preview is rejected without replacing content or creating attachments');

	$catalog_upload_form['_wpnonce']=$catalog_nonce('adc_site_content',$catalog_admin_cookie);
	$catalog_preview_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_upload_form);
	$catalog_preview=$catalog_transient();$catalog_import_path=(string)($catalog_preview['path']??'');
	$verify(302===$catalog_preview_response[0]&&is_file($catalog_import_path),'A fresh import preview can be staged after the stale revision is rejected');
	$catalog_apply_form=array('action'=>'adc_site_content','tab'=>'transfer','operation'=>'import','preview_hash'=>$catalog_preview['hash'],'revision'=>$catalog_preview['revision'],'_wpnonce'=>$catalog_nonce('adc_site_content',$catalog_admin_cookie));
	$catalog_failure_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option));
	$catalog_attachments_before=$catalog_attachment_count();
	$wpdb->query("CREATE TRIGGER adc_content_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='content.saved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated content audit failure'; END IF; END");
	try{
		$catalog_failed_apply=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$catalog_apply_form);
		$verify(302===$catalog_failed_apply[0]&&$catalog_failure_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$site_content_option))&&$catalog_attachment_count()===$catalog_attachments_before,'Failed import rolls back stored content and removes newly created attachments');
	}finally{$wpdb->query('DROP TRIGGER adc_content_http_audit_failure');}

	$technical_option_name=\AutoDealership\Inventory\VehicleTechnicalDetails::OPTION;
	$technical_option_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name));
	$technical_form_options=array();
	foreach(\AutoDealership\Inventory\VehicleTechnicalDetails::definitions() as$field=>$options){foreach($options as$key=>$option){$technical_form_options[$field][$key]=array('ar'=>$option['ar'],'en'=>$option['en'],'icon'=>$option['icon'],'active'=>empty($option['active'])?'0':'1');}}
	$technical_revision=\AutoDealership\Inventory\VehicleTechnicalDetails::options_revision();
	$technical_form=array('action'=>'adc_save_technical_options','revision'=>$technical_revision,'technical_options'=>$technical_form_options,'new_option'=>array('field'=>'fuel','ar'=>'وقود اختبار HTTP','en'=>'HTTP Test Fuel','icon'=>'fuel'));
	$technical_form['_wpnonce']=$catalog_nonce('adc_save_technical_options',$catalog_sales_cookie);
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$technical_form)[0]&&$technical_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name)),'Vehicle technical options admin-post rejects an unauthorized user without changing definitions');
	$technical_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$technical_form)[0]&&$technical_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name)),'Vehicle technical options admin-post rejects an invalid nonce without changing definitions');
	$technical_form['_wpnonce']=$catalog_nonce('adc_save_technical_options',$catalog_admin_cookie);
	$technical_save_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$technical_form);
	$technical_option_saved=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name)));
	$technical_custom_key='custom_'.substr(hash('sha256','وقود اختبار HTTP|HTTP Test Fuel'),0,16);
	$verify(302===$technical_save_response[0]&&isset($technical_option_saved['fuel'][$technical_custom_key])&&$technical_option_saved['fuel'][$technical_custom_key]['ar']==='وقود اختبار HTTP','Authorized technical options admin-post persists a validated bilingual option');
	$technical_saved_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name));
	$technical_form['revision']=$technical_revision;
	$technical_saved_options=maybe_unserialize($technical_saved_raw);
	foreach($technical_saved_options as$field=>$options){foreach($options as$key=>$option){$technical_form['technical_options'][$field][$key]=array('ar'=>$option['ar'],'en'=>$option['en'],'icon'=>$option['icon'],'active'=>empty($option['active'])?'0':'1');}}
	$technical_form['new_option']['ar']='وقود مراجعة قديمة';
	$technical_form['new_option']['en']='Stale Revision Fuel';
	$technical_form['_wpnonce']=$catalog_nonce('adc_save_technical_options',$catalog_admin_cookie);
	$technical_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$technical_form);
	$technical_option_after_stale=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name)));
	$technical_stale_key='custom_'.substr(hash('sha256','وقود مراجعة قديمة|Stale Revision Fuel'),0,16);
	$verify(302===$technical_stale_response[0]&&$technical_saved_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$technical_option_name))&&!isset($technical_option_after_stale['fuel'][$technical_stale_key]),'Stale vehicle technical options revision redirects with an error and cannot overwrite newer definitions');

	$color_option_name='adc_vehicle_colors';
	$color_option_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name));
	$color_saved_value=null===$color_option_before?array():maybe_unserialize($color_option_before);
	$color_revision=hash('sha256',wp_json_encode($color_saved_value));
	$onyx_color_fingerprint=static function()use($wpdb):array{$table=Schema::table('onyx_stock');return array((int)$wpdb->get_var("SELECT COUNT(*) FROM $table"),(string)$wpdb->get_var("SELECT COALESCE(MD5(GROUP_CONCAT(CONCAT(id,':',exterior_color) ORDER BY id SEPARATOR '|')),'') FROM $table"));};
	$onyx_colors_before=$onyx_color_fingerprint();
	$color_row=array('name'=>'HTTP Test Green','hex'=>'#128050','second'=>'','en'=>'HTTP Test Green','alias'=>'');
	$color_form=array('action'=>'adc_save_vehicle_colors','revision'=>$color_revision,'color_count'=>'1','colors'=>array(hash('sha256','http-test-green')=>$color_row));
	$color_form['_wpnonce']=$catalog_nonce('adc_vehicle_colors',$catalog_sales_cookie);
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$color_form)[0]&&$color_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name))&&$onyx_colors_before===$onyx_color_fingerprint(),'Vehicle color admin-post rejects unauthorized edits without changing color mappings or Onyx source data');
	$color_form['_wpnonce']='invalid';
	$verify(403===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$color_form)[0]&&$color_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name))&&$onyx_colors_before===$onyx_color_fingerprint(),'Vehicle color admin-post rejects an invalid nonce without changing mappings or Onyx source data');
	$color_form['_wpnonce']=$catalog_nonce('adc_vehicle_colors',$catalog_admin_cookie);
	$color_incomplete=$color_form;$color_incomplete['color_count']='2';
	$color_incomplete_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$color_incomplete);
	$verify(302!==$color_incomplete_response[0]&&$color_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name))&&$onyx_colors_before===$onyx_color_fingerprint(),'Incomplete vehicle color submissions are rejected without changing mappings or Onyx source data');
	$color_invalid=$color_form;$color_invalid['colors'][hash('sha256','http-test-green')]['hex']='url(https://example.invalid)';
	$color_invalid['_wpnonce']=$catalog_nonce('adc_vehicle_colors',$catalog_admin_cookie);
	$color_invalid_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$color_invalid);
	$verify(302!==$color_invalid_response[0]&&$color_option_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name))&&$onyx_colors_before===$onyx_color_fingerprint(),'Invalid vehicle color values are rejected without changing mappings or Onyx source data');
	$color_form['_wpnonce']=$catalog_nonce('adc_vehicle_colors',$catalog_admin_cookie);
	$color_save_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$color_form);
	$color_option_after_save=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name)));
	$verify(302===$color_save_response[0]&&'HTTP Test Green'===($color_option_after_save['http test green']['en']??'')&&'#128050'===($color_option_after_save['http test green']['hex']??'')&&$onyx_colors_before===$onyx_color_fingerprint(),'Authorized vehicle color save persists validated mapping without modifying Onyx source data');
	$color_saved_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name));
	$color_stale=$color_form;$color_stale['revision']=$color_revision;$color_stale['colors'][hash('sha256','http-test-green')]['name']='HTTP Stale Color';
	$color_stale['_wpnonce']=$catalog_nonce('adc_vehicle_colors',$catalog_admin_cookie);
	$color_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$color_stale);
	$verify(302!==$color_stale_response[0]&&$color_saved_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$color_option_name))&&$onyx_colors_before===$onyx_color_fingerprint(),'Stale vehicle color revision cannot overwrite saved mappings or alter Onyx source data');

	$social_url_option=\AutoDealership\Core\SocialChannels::URL_OPTION;
	$social_preferences_option=\AutoDealership\Core\SocialChannels::OPTION;
	$social_url_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option));
	$social_preferences_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option));
	update_option($social_url_option,array('phone'=>'0500000000','description_en'=>'Preserve unrelated contact details'));
	update_option($social_preferences_option,wp_json_encode(\AutoDealership\Core\SocialChannels::defaults()),false);
	$social_urls=array();
	foreach(\AutoDealership\Core\SocialChannels::platforms() as$key=>$platform){$social_urls[$key]='whatsapp'===$key?'0538722576':'https://'.$platform['hosts'][0].'/http-test';}
	$social_preferences=\AutoDealership\Core\SocialChannels::defaults();
	$social_form=array('action'=>'adc_save_social_channels','revision'=>\AutoDealership\Core\SocialChannels::revision(),'social_urls'=>$social_urls,'preferences'=>wp_json_encode($social_preferences));
	$social_audit_event='settings.social_channels_changed';
	$social_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$social_audit_event));
	$social_url_raw_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option));
	$social_preferences_raw_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option));
	$social_form['_wpnonce']=$catalog_nonce('adc_save_social_channels',$catalog_sales_cookie);
	$social_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$social_form);
	$verify(302===$social_denied_response[0]&&$social_url_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option))&&$social_preferences_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option)),'Social channel admin-post rejects an unauthorized role without changing account URLs or preferences');
	$social_form['_wpnonce']='invalid';
	$social_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$social_form);
	$verify(403===$social_nonce_response[0]&&$social_url_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option))&&$social_preferences_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option)),'Social channel admin-post rejects an invalid nonce without changing account URLs or preferences');
	$social_form['_wpnonce']=$catalog_nonce('adc_save_social_channels',$catalog_admin_cookie);
	$social_invalid=$social_form;$social_invalid['revision']=\AutoDealership\Core\SocialChannels::revision();$social_invalid['social_urls']['facebook']='https://example.invalid/not-facebook';
	$social_invalid_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$social_invalid);
	$verify(302===$social_invalid_response[0]&&$social_url_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option))&&$social_preferences_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option)),'Invalid social platform URL is rejected without partially saving settings');
	$social_form['revision']='stale-social-settings-revision';
	$social_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$social_form);
	$verify(302===$social_stale_response[0]&&$social_url_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option))&&$social_preferences_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option)),'Stale social settings revision cannot overwrite current account URLs or preferences');
	$social_form['revision']=\AutoDealership\Core\SocialChannels::revision();
	$social_form['_wpnonce']=$catalog_nonce('adc_save_social_channels',$catalog_admin_cookie);
	$social_saved_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$social_form);
	$social_saved_urls=maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option)));
	$social_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$social_audit_event));
	$verify(302===$social_saved_response[0]&&'https://facebook.com/http-test'===($social_saved_urls['facebook']??'')&&'966538722576'===($social_saved_urls['whatsapp']??'')&&$social_audit_before+1===$social_audit_after,'Authorized social channel admin-post normalizes URLs and phone and records its audit event');
	$social_saved_urls_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option));
	$social_saved_preferences_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option));
	wp_cache_delete($social_url_option,'options');wp_cache_delete($social_preferences_option,'options');wp_cache_delete('alloptions','options');
	$social_failure_form=$social_form;$social_failure_form['revision']=\AutoDealership\Core\SocialChannels::revision();$social_failure_form['social_urls']['facebook']='https://facebook.com/rollback-check';$social_failure_form['preferences']=wp_json_encode(array_replace($social_preferences,array('style'=>'brand')));
	$social_failure_form['_wpnonce']=$catalog_nonce('adc_save_social_channels',$catalog_admin_cookie);
	$social_failure_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$social_audit_event));
	$wpdb->query("CREATE TRIGGER adc_social_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='settings.social_channels_changed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated social settings audit failure'; END IF; END");
	try{
		$social_failure_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$social_failure_form);
		$social_failure_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$social_audit_event));
		$verify(302===$social_failure_response[0]&&$social_saved_urls_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_url_option))&&$social_saved_preferences_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$social_preferences_option))&&$social_failure_audit_before===$social_failure_audit_after,'Social settings audit failure rolls account URLs and preferences back together');
	}finally{$wpdb->query('DROP TRIGGER adc_social_http_audit_failure');}

	$followup_email_option='adc_followup_email_enabled';
	$followup_email_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option));
	update_option($followup_email_option,false,false);wp_cache_delete($followup_email_option,'options');wp_cache_delete('alloptions','options');
	$followup_email_raw_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option));
	$followup_email_audit='followup.email_policy_changed';
	$followup_email_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$followup_email_audit));
	$followup_email_form=array('action'=>'adc_followup_email','previous'=>'0','enabled'=>'1');
	$followup_email_form['_wpnonce']=$catalog_nonce('adc_followup_email',$catalog_sales_cookie);
	$followup_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$followup_email_form);
	$verify(302===$followup_denied_response[0]&&$followup_email_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option)),'Follow-up email policy admin-post rejects an unauthorized role without enabling delivery');
	$followup_email_form['_wpnonce']='invalid';
	$followup_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_email_form);
	$verify(403===$followup_nonce_response[0]&&$followup_email_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option)),'Follow-up email policy admin-post rejects an invalid nonce without changing policy');
	$followup_email_form['_wpnonce']=$catalog_nonce('adc_followup_email',$catalog_admin_cookie);$followup_email_form['previous']='1';
	$followup_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_email_form);
	$verify(302===$followup_stale_response[0]&&$followup_email_raw_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option)),'Stale follow-up email policy state cannot overwrite the current setting');
	$followup_email_form['previous']='0';$followup_email_form['_wpnonce']=$catalog_nonce('adc_followup_email',$catalog_admin_cookie);
	$followup_email_saved_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_email_form);
	$followup_email_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$followup_email_audit));
	$verify(302===$followup_email_saved_response[0]&&'1'===(string)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option))&&$followup_email_audit_before+1===$followup_email_audit_after,'Authorized follow-up email policy change persists and records its audit event');
	$followup_email_raw_saved=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option));
	$followup_email_audit_count_saved=$followup_email_audit_after;
	$followup_email_failure=array('action'=>'adc_followup_email','previous'=>'1','enabled'=>'0','_wpnonce'=>$catalog_nonce('adc_followup_email',$catalog_admin_cookie));
	$wpdb->query("CREATE TRIGGER adc_followup_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='followup.email_policy_changed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated follow-up audit failure'; END IF; END");
	try{
		$followup_email_failed_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_email_failure);
		$followup_email_audit_after_failure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$followup_email_audit));
		$verify(302===$followup_email_failed_response[0]&&$followup_email_raw_saved===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$followup_email_option))&&$followup_email_audit_count_saved===$followup_email_audit_after_failure,'Follow-up policy audit failure rolls back the setting change');
	}finally{$wpdb->query('DROP TRIGGER adc_followup_http_audit_failure');}

	$branch_option='adc_branch_locations';
	wp_cache_delete($branch_option,'options');wp_cache_delete('alloptions','options');
	$branch_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option));
	$branch_form=array('action'=>'adc_branch_locations','revision'=>\AutoDealership\Core\BranchLocations::revision(),'branches'=>array(array('id'=>'http-test-branch','name_ar'=>'فرع اختبار HTTP','name_en'=>'HTTP Test Branch','address_ar'=>'عنوان الاختبار','address_en'=>'Test address','phone'=>'','embed_url'=>'https://www.google.com/maps/embed?pb=test','directions_url'=>'https://www.google.com/maps/dir/?api=1','active'=>'1')));
	$branch_form['_wpnonce']=$catalog_nonce('adc_branch_locations',$catalog_sales_cookie);
	$branch_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$branch_form);
	$verify(403===$branch_denied_response[0]&&$branch_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option)),'Branch locations admin-post refuses an unauthorized role without changing the directory');
	$branch_form['_wpnonce']='invalid';
	$branch_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$branch_form);
	$verify(403===$branch_nonce_response[0]&&$branch_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option)),'Branch locations admin-post rejects an invalid nonce without changing the directory');
	$branch_form['_wpnonce']=$catalog_nonce('adc_branch_locations',$catalog_admin_cookie);$branch_form['revision']='stale-branch-revision';
	$branch_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$branch_form);
	$verify(302===$branch_stale_response[0]&&$branch_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option)),'Stale branch locations revision cannot overwrite the current directory');
	$branch_form['revision']=\AutoDealership\Core\BranchLocations::revision();$branch_form['branches'][0]['embed_url']='https://maps.example.invalid/embed?pb=test';
	$branch_invalid_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$branch_form);
	$verify(302===$branch_invalid_response[0]&&$branch_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option)),'Invalid branch map URL cannot partially change the directory');
	$branch_form['branches'][0]['embed_url']='https://www.google.com/maps/embed?pb=test';$branch_form['_wpnonce']=$catalog_nonce('adc_branch_locations',$catalog_admin_cookie);
	$branch_audit='platform.branches_updated';
	$branch_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$branch_audit));
	$branch_saved_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$branch_form);
	$branch_saved_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option));
	$branch_saved=maybe_unserialize($branch_saved_raw);
	$branch_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$branch_audit));
	$verify(302===$branch_saved_response[0]&&is_array($branch_saved)&&'http-test-branch'===($branch_saved[0]['id']??'')&&'https://www.google.com/maps/embed?pb=test'===($branch_saved[0]['embed_url']??'')&&$branch_audit_before+1===$branch_audit_after,'Authorized branch locations admin-post saves normalized data and records its audit event');
	$branch_failure_form=$branch_form;$branch_failure_form['revision']=\AutoDealership\Core\BranchLocations::revision();$branch_failure_form['branches'][0]['name_en']='Rollback Test Branch';$branch_failure_form['_wpnonce']=$catalog_nonce('adc_branch_locations',$catalog_admin_cookie);
	$wpdb->query("CREATE TRIGGER adc_branch_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='platform.branches_updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated branch settings audit failure'; END IF; END");
	try{
		$branch_failure_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$branch_failure_form);
		$branch_audit_after_failure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$branch_audit));
		$verify(302===$branch_failure_response[0]&&$branch_saved_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$branch_option))&&$branch_audit_after===$branch_audit_after_failure,'Branch locations audit failure rolls back the directory update');
	}finally{$wpdb->query('DROP TRIGGER adc_branch_http_audit_failure');}

	$jobs_option='adc_jobs_mode';
	wp_cache_delete($jobs_option,'options');wp_cache_delete('alloptions','options');
	$jobs_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option));
	$jobs_audit='platform.job_mode';
	$jobs_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$jobs_audit));
	$jobs_form=array('action'=>'adc_jobs_settings','mode'=>'browser');
	$jobs_form['_wpnonce']=$catalog_nonce('adc_jobs_settings',$catalog_sales_cookie);
	$jobs_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$jobs_form);
	$verify(403===$jobs_denied_response[0]&&$jobs_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option)),'Job runtime settings admin-post refuses an unauthorized role without changing execution mode');
	$jobs_form['_wpnonce']='invalid';
	$jobs_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$jobs_form);
	$verify(403===$jobs_nonce_response[0]&&$jobs_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option)),'Job runtime settings admin-post rejects an invalid nonce without changing execution mode');
	$jobs_form['_wpnonce']=$catalog_nonce('adc_jobs_settings',$catalog_admin_cookie);$jobs_form['mode']='unknown';
	$jobs_invalid_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$jobs_form);
	$jobs_audit_after_invalid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$jobs_audit));
	$verify(302===$jobs_invalid_response[0]&&$jobs_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option))&&$jobs_audit_before===$jobs_audit_after_invalid,'Unsupported job runtime mode is rejected without changing or falsely auditing settings');
	$jobs_form['_wpnonce']=$catalog_nonce('adc_jobs_settings',$catalog_admin_cookie);$jobs_form['mode']=array('browser');
	$jobs_malformed_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$jobs_form);
	$verify(302===$jobs_malformed_response[0]&&$jobs_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option)),'Malformed job runtime mode input is safely rejected');
	$jobs_form['_wpnonce']=$catalog_nonce('adc_jobs_settings',$catalog_admin_cookie);$jobs_form['mode']='browser';
	$jobs_saved_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$jobs_form);
	$jobs_saved_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option));
	$jobs_audit_after_save=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$jobs_audit));
	$verify(302===$jobs_saved_response[0]&&'browser'===maybe_unserialize($jobs_saved_raw)&&$jobs_audit_before+1===$jobs_audit_after_save,'Authorized job runtime mode is saved with an audit event');
	$jobs_failure_form=array('action'=>'adc_jobs_settings','mode'=>'server','_wpnonce'=>$catalog_nonce('adc_jobs_settings',$catalog_admin_cookie));
	$wpdb->query("CREATE TRIGGER adc_jobs_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='platform.job_mode' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated job settings audit failure'; END IF; END");
	try{
		$jobs_failure_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$jobs_failure_form);
		$jobs_audit_after_failure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$jobs_audit));
		$verify(302===$jobs_failure_response[0]&&$jobs_saved_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$jobs_option))&&$jobs_audit_after_save===$jobs_audit_after_failure,'Job runtime audit failure rolls back the mode change');
	}finally{$wpdb->query('DROP TRIGGER adc_jobs_http_audit_failure');}

	$margin_option=\AutoDealership\Pricing\ProfitMargin::OPTION;
	wp_cache_delete($margin_option,'options');wp_cache_delete('alloptions','options');
	$margin_before=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option));
	$margin_revision=(string)\AutoDealership\Pricing\ProfitMargin::policy()['revision'];
	$margin_audit='pricing.margin_updated';
	$margin_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$margin_audit));
	$margin_form=array('action'=>'adc_profit_margin','type'=>'fixed','stage'=>'before_tax','value'=>'1250.25','revision'=>$margin_revision);
	$margin_form['_wpnonce']=$catalog_nonce('adc_profit_margin',$catalog_sales_cookie);
	$margin_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$margin_form);
	$verify(302===$margin_denied_response[0]&&$margin_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option)),'Profit margin admin-post rejects an unauthorized role without changing the policy');
	$margin_form['_wpnonce']='invalid';
	$margin_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$margin_form);
	$verify(403===$margin_nonce_response[0]&&$margin_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option)),'Profit margin admin-post rejects an invalid nonce without changing the policy');
	$margin_form['_wpnonce']=$catalog_nonce('adc_profit_margin',$catalog_admin_cookie);$margin_form['revision']=(string)((int)$margin_revision+1);
	$margin_stale_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$margin_form);
	$margin_audit_after_stale=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$margin_audit));
	$verify(302===$margin_stale_response[0]&&$margin_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option))&&$margin_audit_before===$margin_audit_after_stale,'Stale profit margin revision cannot overwrite or audit a newer policy');
	$margin_form['revision']=$margin_revision;$margin_form['value']='-1';$margin_form['_wpnonce']=$catalog_nonce('adc_profit_margin',$catalog_admin_cookie);
	$margin_invalid_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$margin_form);
	$verify(302===$margin_invalid_response[0]&&$margin_before===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option)),'Invalid profit margin amount cannot change the policy');
	$margin_form['value']='1250.25';$margin_form['_wpnonce']=$catalog_nonce('adc_profit_margin',$catalog_admin_cookie);
	$margin_saved_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$margin_form);
	$margin_saved_raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option));
	$margin_saved=maybe_unserialize($margin_saved_raw);
	$margin_audit_after_save=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$margin_audit));
	$verify(302===$margin_saved_response[0]&&is_array($margin_saved)&&'fixed'===($margin_saved['type']??'')&&125025===($margin_saved['value']??null)&&((int)$margin_revision+1)===($margin_saved['revision']??-1)&&$margin_audit_before+1===$margin_audit_after_save,'Authorized profit margin admin-post saves the policy revision and audit event');
	$margin_failure_form=array('action'=>'adc_profit_margin','type'=>'percentage','stage'=>'after_tax','value'=>'4.5','revision'=>(string)$margin_saved['revision'],'_wpnonce'=>$catalog_nonce('adc_profit_margin',$catalog_admin_cookie));
	$wpdb->query("CREATE TRIGGER adc_margin_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='pricing.margin_updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated margin policy audit failure'; END IF; END");
	try{
		$margin_failure_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$margin_failure_form);
		$margin_audit_after_failure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$margin_audit));
		$verify(302===$margin_failure_response[0]&&$margin_saved_raw===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$margin_option))&&$margin_audit_after_save===$margin_audit_after_failure,'Profit margin audit failure rolls back the policy change');
	}finally{$wpdb->query('DROP TRIGGER adc_margin_http_audit_failure');}

	$issue_branch_result=\AutoDealership\Branches\BranchService::create(array('code'=>'ISSUE-'.strtoupper(bin2hex(random_bytes(3))),'name'=>'HTTP vehicle issue branch'));
	if(!is_array($issue_branch_result)){throw new RuntimeException('Cannot create isolated vehicle issue branch fixture.');}
	$issue_branch_id=(int)$issue_branch_result['id'];
	\AutoDealership\Security\BranchScope::clear_cache();
	$issue_vehicle_ids=array();
	foreach(array('1','2')as$issue_suffix){
		$issue_vehicle=\AutoDealership\Inventory\VehicleService::create(array('vin'=>'1M8GDM9AXKP'.strtoupper(bin2hex(random_bytes(3))),'stock_number'=>'ISSUE-HTTP-'.$issue_suffix,'brand'=>'HTTP issue fixture','model'=>'Authorization test','model_year'=>2026,'condition'=>'new','branch_id'=>$issue_branch_id,'retail_price'=>1000000));
		if(!is_array($issue_vehicle)){throw new RuntimeException('Cannot create isolated vehicle issue fixture: '.(is_wp_error($issue_vehicle)?$issue_vehicle->get_error_code():'invalid result').'.');}
		$issue_vehicle_ids[]=(int)$issue_vehicle['id'];
	}
	$issue_table=Schema::table('vehicle_issues');$vehicle_table=Schema::table('vehicles');$movement_table=Schema::table('vehicle_movements');
	$issue_open_audit='vehicle.issue_opened';$issue_resolve_audit='vehicle.issue_resolved';
	$issue_open_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$issue_open_audit));
	$issue_open_form=array('action'=>'adc_open_vehicle_issue','vehicle_id'=>(string)$issue_vehicle_ids[0],'type'=>'hold','reason'=>'HTTP authorization hold','review_at'=>gmdate('Y-m-d',time()+DAY_IN_SECONDS));
	$issue_open_form['_wpnonce']=$catalog_nonce('adc_open_vehicle_issue',$catalog_sales_cookie);
	$issue_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$issue_open_form);
	$verify(302===$issue_denied_response[0]&&'received'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[0]))&&0===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $issue_table WHERE vehicle_id=%d",$issue_vehicle_ids[0])),'Vehicle issue admin-post refuses a role without inventory permission and leaves the vehicle unchanged');
	$issue_open_form['_wpnonce']='invalid';
	$issue_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_open_form);
	$verify(403===$issue_nonce_response[0]&&'received'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[0])),'Vehicle issue opening rejects an invalid nonce without changing inventory');
	$issue_open_form['_wpnonce']=$catalog_nonce('adc_open_vehicle_issue',$catalog_admin_cookie);$issue_open_form['type']='unsupported';
	$issue_invalid_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_open_form);
	$verify(302===$issue_invalid_response[0]&&'received'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[0]))&&0===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $issue_table WHERE vehicle_id=%d",$issue_vehicle_ids[0])),'Unsupported vehicle issue type cannot create an issue or change inventory status');
	$issue_open_form['type']='hold';$issue_open_form['_wpnonce']=$catalog_nonce('adc_open_vehicle_issue',$catalog_admin_cookie);
	$issue_open_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_open_form);
	$issue_open_row=$wpdb->get_row($wpdb->prepare("SELECT id,status,issue_type,reason FROM $issue_table WHERE vehicle_id=%d",$issue_vehicle_ids[0]),ARRAY_A);
	$issue_open_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$issue_open_audit));
	$verify(302===$issue_open_response[0]&&'open'===($issue_open_row['status']??'')&&'hold'===($issue_open_row['issue_type']??'')&&'hold'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[0]))&&$issue_open_audit_before+1===$issue_open_audit_after,'Authorized vehicle issue opening creates an audited hold and updates inventory atomically');
	$issue_id=(int)$issue_open_row['id'];
	$issue_resolve_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$issue_resolve_audit));
	$issue_resolve_form=array('action'=>'adc_resolve_vehicle_issue','id'=>(string)$issue_id,'resolution'=>'HTTP repair verified');
	$issue_resolve_form['_wpnonce']=$catalog_nonce('adc_resolve_vehicle_issue_'.$issue_id,$catalog_sales_cookie);
	$issue_resolve_denied=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$issue_resolve_form);
	$verify(302===$issue_resolve_denied[0]&&'open'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $issue_table WHERE id=%d",$issue_id))&&'hold'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[0])),'Vehicle issue resolution refuses a role without inventory permission');
	$issue_resolve_form['_wpnonce']='invalid';
	$issue_resolve_nonce=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_resolve_form);
	$verify(403===$issue_resolve_nonce[0]&&'open'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $issue_table WHERE id=%d",$issue_id)),'Vehicle issue resolution rejects an invalid action-specific nonce');
	$issue_resolve_form['_wpnonce']=$catalog_nonce('adc_resolve_vehicle_issue_'.$issue_id,$catalog_admin_cookie);
	$issue_resolve_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_resolve_form);
	$issue_resolve_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$issue_resolve_audit));
	$verify(302===$issue_resolve_response[0]&&'resolved'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $issue_table WHERE id=%d",$issue_id))&&'inspection'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[0]))&&$issue_resolve_audit_before+1===$issue_resolve_audit_after,'Authorized vehicle issue resolution records the audit event and returns inventory to inspection');
	$issue_open_form=array('action'=>'adc_open_vehicle_issue','vehicle_id'=>(string)$issue_vehicle_ids[1],'type'=>'maintenance','reason'=>'Rollback opening fixture','_wpnonce'=>$catalog_nonce('adc_open_vehicle_issue',$catalog_admin_cookie));
	$wpdb->query("CREATE TRIGGER adc_issue_open_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='vehicle.issue_opened' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated vehicle issue open audit failure'; END IF; END");
	try{
		$issue_open_failure=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_open_form);
		$issue_open_audit_after_failure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$issue_open_audit));
		$verify(302===$issue_open_failure[0]&&'received'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[1]))&&0===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $issue_table WHERE vehicle_id=%d",$issue_vehicle_ids[1]))&&$issue_open_audit_after===$issue_open_audit_after_failure,'Vehicle issue opening rolls issue and inventory back when audit insertion fails');
	}finally{$wpdb->query('DROP TRIGGER adc_issue_open_http_audit_failure');}
	$issue_open_form['_wpnonce']=$catalog_nonce('adc_open_vehicle_issue',$catalog_admin_cookie);
	$issue_open_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_open_form);
	$issue_second_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $issue_table WHERE vehicle_id=%d",$issue_vehicle_ids[1]));
	$verify(302===$issue_open_response[0]&&$issue_second_id>0&&'maintenance'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[1])),'Vehicle issue can be opened after a previous transactional audit failure');
	$issue_resolve_form=array('action'=>'adc_resolve_vehicle_issue','id'=>(string)$issue_second_id,'resolution'=>'Rollback resolution fixture','_wpnonce'=>$catalog_nonce('adc_resolve_vehicle_issue_'.$issue_second_id,$catalog_admin_cookie));
	$wpdb->query("CREATE TRIGGER adc_issue_resolve_http_audit_failure BEFORE INSERT ON $catalog_audit FOR EACH ROW BEGIN IF NEW.event_key='vehicle.issue_resolved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Isolated vehicle issue resolve audit failure'; END IF; END");
	try{
		$issue_resolve_failure=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$issue_resolve_form);
		$issue_resolve_audit_after_failure=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s",$issue_resolve_audit));
		$verify(302===$issue_resolve_failure[0]&&'open'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $issue_table WHERE id=%d",$issue_second_id))&&'maintenance'===$wpdb->get_var($wpdb->prepare("SELECT status FROM $vehicle_table WHERE id=%d",$issue_vehicle_ids[1]))&&$issue_resolve_audit_after===$issue_resolve_audit_after_failure,'Vehicle issue resolution rolls issue and inventory back when audit insertion fails');
	}finally{$wpdb->query('DROP TRIGGER adc_issue_resolve_http_audit_failure');}

	$followup_table=Schema::table('followup_notifications');
	$followup_ack_audit='followup.acknowledged';
	$followup_ack_key=hash('sha256','http-followup-ack-'.wp_generate_uuid4());
	$followup_ack_insert=$wpdb->insert($followup_table,array('notification_key'=>$followup_ack_key,'source_type'=>'outbox','source_id'=>1,'branch_id'=>0,'owner_user_id'=>0,'recipient_id'=>$publisher,'level'=>0,'due_at'=>current_time('mysql',true),'created_at'=>current_time('mysql',true)));
	if(1!==$followup_ack_insert){throw new RuntimeException('Cannot create isolated follow-up acknowledgement fixture.');}
	$followup_ack_id=(int)$wpdb->insert_id;
	$followup_ack_audit_before=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s AND subject_id=%d",$followup_ack_audit,$followup_ack_id));
	$followup_ack_form=array('action'=>'adc_ack_followup','id'=>(string)$followup_ack_id,'_wpnonce'=>$catalog_nonce('adc_ack_followup_'.$followup_ack_id,$catalog_sales_cookie));
	$followup_ack_denied_response=$catalog_request('/wp-admin/admin-post.php',$catalog_sales_cookie,$followup_ack_form);
	$followup_ack_state=$wpdb->get_var($wpdb->prepare("SELECT acknowledged_at FROM $followup_table WHERE id=%d",$followup_ack_id));
	$verify(302===$followup_ack_denied_response[0]&&null===$followup_ack_state,'Follow-up acknowledgement refuses a different recipient without acknowledging the notification');
	$followup_ack_form['_wpnonce']='invalid';
	$followup_ack_nonce_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_ack_form);
	$verify(403===$followup_ack_nonce_response[0]&&null===$wpdb->get_var($wpdb->prepare("SELECT acknowledged_at FROM $followup_table WHERE id=%d",$followup_ack_id)),'Follow-up acknowledgement rejects an invalid nonce without changing the notification');
	$followup_ack_form['_wpnonce']=$catalog_nonce('adc_ack_followup_'.$followup_ack_id,$catalog_admin_cookie);
	$followup_ack_response=$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_ack_form);
	$followup_ack_state=$wpdb->get_var($wpdb->prepare("SELECT acknowledged_at FROM $followup_table WHERE id=%d",$followup_ack_id));
	$followup_ack_audit_after=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $catalog_audit WHERE event_key=%s AND subject_id=%d",$followup_ack_audit,$followup_ack_id));
	$verify(302===$followup_ack_response[0]&&is_string($followup_ack_state)&&''!==$followup_ack_state&&$followup_ack_audit_before+1===$followup_ack_audit_after,'Notification recipient can acknowledge their alert and the action is audited');
	$followup_missing_id=$followup_ack_id+100000;
	$followup_missing_form=array('action'=>'adc_ack_followup','id'=>(string)$followup_missing_id,'_wpnonce'=>$catalog_nonce('adc_ack_followup_'.$followup_missing_id,$catalog_admin_cookie));
	$verify(302===$catalog_request('/wp-admin/admin-post.php',$catalog_admin_cookie,$followup_missing_form)[0]&&0===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $followup_table WHERE id=%d",$followup_missing_id)),'Acknowledgement of an unknown notification does not create a notification record');
}finally{
	if(is_resource($catalog_http_process)){proc_terminate($catalog_http_process);proc_close($catalog_http_process);}
	if(is_file($catalog_http_log)){unlink($catalog_http_log);}
	if(isset($catalog_import_archive)&&is_file($catalog_import_archive)){unlink($catalog_import_archive);}
	if(isset($catalog_import_path)&&''!==$catalog_import_path){\AutoDealership\Content\ContentTransfer::cleanup($catalog_import_path);}
	if(isset($catalog_import_key)){delete_transient($catalog_import_key);}
	if(isset($catalog_import_image)&&is_int($catalog_import_image)){wp_delete_attachment($catalog_import_image,true);}
	elseif(isset($catalog_import_image_upload['file'])&&is_file($catalog_import_image_upload['file'])){unlink($catalog_import_image_upload['file']);}
	if(isset($color_option_before)){if(null===$color_option_before){delete_option('adc_vehicle_colors');}else{$wpdb->update($wpdb->options,array('option_value'=>$color_option_before),array('option_name'=>'adc_vehicle_colors'));wp_cache_delete('adc_vehicle_colors','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}}
	foreach(array('social_url_option'=>'social_url_before','social_preferences_option'=>'social_preferences_before')as$option_var=>$raw_var){if(isset($$option_var,$$raw_var)){if(null===$$raw_var){delete_option($$option_var);}else{$wpdb->update($wpdb->options,array('option_value'=>$$raw_var),array('option_name'=>$$option_var));}wp_cache_delete($$option_var,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}}
	if(isset($branch_option,$branch_before)){if(null===$branch_before){delete_option($branch_option);}else{$wpdb->update($wpdb->options,array('option_value'=>$branch_before),array('option_name'=>$branch_option));}wp_cache_delete($branch_option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');delete_transient('adc_branch_notice_'.$publisher);}
	$wpdb->query('DROP TRIGGER IF EXISTS adc_branch_http_audit_failure');
	if(isset($jobs_option,$jobs_before)){if(null===$jobs_before){delete_option($jobs_option);}else{$wpdb->update($wpdb->options,array('option_value'=>$jobs_before),array('option_name'=>$jobs_option));}wp_cache_delete($jobs_option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');delete_transient('adc_job_settings_notice_'.$publisher);}
	$wpdb->query('DROP TRIGGER IF EXISTS adc_jobs_http_audit_failure');
	if(isset($margin_option,$margin_before)){if(null===$margin_before){delete_option($margin_option);}else{$wpdb->update($wpdb->options,array('option_value'=>$margin_before),array('option_name'=>$margin_option));}wp_cache_delete($margin_option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');delete_transient('adc_margin_error_'.$publisher);}
	$wpdb->query('DROP TRIGGER IF EXISTS adc_margin_http_audit_failure');
	if(isset($issue_vehicle_ids,$issue_table,$vehicle_table,$movement_table)){foreach($issue_vehicle_ids as $issue_vehicle_id){$wpdb->delete($issue_table,array('vehicle_id'=>$issue_vehicle_id),array('%d'));$wpdb->delete($movement_table,array('vehicle_id'=>$issue_vehicle_id),array('%d'));$wpdb->delete($vehicle_table,array('id'=>$issue_vehicle_id),array('%d'));}}
	if(isset($issue_branch_id)){ $wpdb->delete(Schema::table('branches'),array('id'=>$issue_branch_id),array('%d')); \AutoDealership\Security\BranchScope::clear_cache(); }
	$wpdb->query('DROP TRIGGER IF EXISTS adc_issue_open_http_audit_failure');
	$wpdb->query('DROP TRIGGER IF EXISTS adc_issue_resolve_http_audit_failure');
	if(isset($followup_table,$followup_ack_id)){ $wpdb->delete($followup_table,array('id'=>$followup_ack_id),array('%d')); }
	if(isset($followup_email_option,$followup_email_before)){if(null===$followup_email_before){delete_option($followup_email_option);}else{$wpdb->update($wpdb->options,array('option_value'=>$followup_email_before),array('option_name'=>$followup_email_option));}wp_cache_delete($followup_email_option,'options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}
	$wpdb->query('DROP TRIGGER IF EXISTS adc_followup_http_audit_failure');
	if(isset($catalog_term_id)&&$catalog_term_id>0){wp_delete_term($catalog_term_id,'car_brand');}
	if(isset($catalog_sales)&&!is_wp_error($catalog_sales)){wp_delete_user((int)$catalog_sales);}
}
echo "Catalog publishing checks passed: $publishing_checks\n";
