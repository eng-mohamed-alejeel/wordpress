<?php
/** Read-only color / Onyx compatibility checks. No leads, mail or inventory writes. */
if(PHP_SAPI!=='cli'){exit;}define('DISABLE_WP_CRON',true);require dirname(__DIR__,4).'/wp-load.php';
use AutoDealership\Inventory\VehicleColors as C;
use AutoDealership\Inventory\GroupedModelCatalog as G;
$checks=0;$check=static function($condition,$message)use(&$checks){if(!$condition){throw new RuntimeException($message);}++$checks;echo "PASS $message\n";};
$admin=get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'));$previous=get_current_user_id();wp_set_current_user((int)$admin[0]);
try{
 $check(C::key(' أبيض  ')==='ابيض','Onyx spelling normalization preserves original source separately');
 $check(C::key('ابيض/اسود')===C::key('ابيض / اسود'),'Onyx separator spacing does not duplicate two-tone colors');
 $check(C::key('رمادى')===C::key('رمادي'),'final Arabic yeh variants map consistently');
 $check(C::resolve('ابيض')['hex']==='#FFFFFF','white has an exact explicit mapping');
 $check(C::resolve('ازرق فاتح')['key']!==C::resolve('ازرق غامق')['key'],'light and dark shades stay distinct');
 $check(C::resolve('ابيض / اسود')['second']==='#171717','Onyx two-tone names have two explicit swatches');
 $check(C::resolve('لون جديد غير معروف')['hex']==='','unknown source colors never get guessed');
 $check(str_contains(C::swatch('لون جديد غير معروف'),'لون جديد غير معروف'),'unknown colors retain their source name');
 $sources=C::source_names();foreach($sources as$name){$color=C::resolve($name);$check($color['raw']===$name&&($color['hex']!==''||str_contains(C::swatch($name),$name)),'Onyx source is explicitly mapped or safely retains its unknown name: '.$name);}
 $row=static fn($name,$hex,$alias='')=>array('name'=>$name,'hex'=>$hex,'second'=>'','en'=>'Sample','alias'=>$alias);
 $check(is_wp_error(C::validate_definitions(array($row('أبيض','#FFFFFF'),$row('ابيض','#000000')))),'duplicate normalized color definitions rejected');
 $check(is_wp_error(C::validate_definitions(array($row('test','red')))),'non-HEX and injected color values rejected');
 $check(is_wp_error(C::validate_definitions(array($row('one','#FFFFFF','two'),$row('two','#000000','one')))),'circular aliases rejected');
 $check(is_wp_error(C::validate_definitions(array($row('one','','missing')))),'missing alias targets rejected');
 $check(!is_wp_error(C::validate_definitions(array($row('ابيض','#FFFFFF'),$row('ثلجي','','ابيض')))),'approved one-level alias accepted');
 $filter=static fn()=>array('ثلجي'=>array('hex'=>'','second'=>'','en'=>'','alias'=>'ابيض'));
 add_filter('pre_option_adc_vehicle_colors',$filter);
 $check(C::resolve('ثلجي')['key']==='ابيض','administrator-approved aliases resolve to canonical colors');
 $check(C::resolve('ثلجي')['raw']==='ثلجي','alias preserves original Onyx name for requests');
 remove_filter('pre_option_adc_vehicle_colors',$filter);
 $check(is_wp_error(C::validate_galleries('not an array')),'malformed gallery rejected');
 $check(is_wp_error(C::validate_galleries(array('x'=>'1'))),'malformed gallery key rejected');
 $key=hash('sha256','white');
 $check(is_wp_error(C::validate_galleries(array($key=>'https://example.test/image.jpg'))),'external URLs are not accepted as attachments');
 $check(is_wp_error(C::validate_galleries(array($key=>implode(',',array_fill(0,21,'1'))))),'gallery size capped at twenty photos');
 $check(C::validate_galleries(array($key=>''))===array($key=>array()),'empty gallery explicitly clears only that color');
 $ids=get_posts(array('post_type'=>'attachment','post_mime_type'=>'image','posts_per_page'=>2,'fields'=>'ids'));
 if(count($ids)>1){$valid=C::validate_galleries(array($key=>$ids[1].','.$ids[0].','.$ids[1]));$check($valid[$key]===array((int)$ids[1],(int)$ids[0]),'gallery order preserved and duplicate images removed');}
 global $wpdb;$posts=$wpdb->get_col('SELECT public_post_id FROM '.\AutoDealership\Database\Schema::table('catalog_models').' WHERE public_post_id IS NOT NULL');$verified=0;
 foreach($posts as$post){$view=G::view((int)$post);if(!$view)continue;foreach($view['catalog_model']['options']as$row){$id=C::option_id($row);$resolved=C::selected((int)$post,$id);$check($resolved&&$resolved['exterior_color']===$row['exterior_color']&&$resolved['public_price']===$row['public_price'],'selected option resolves to current Onyx source and server price');if(++$verified>=3)break;}if($verified>=3)break;}
 $eligible_post=0;$test_post=0;$test_colors=array();
 foreach($posts as$candidate){$candidate_view=G::view((int)$candidate);if(!$candidate_view)continue;if(!$eligible_post){$eligible_post=(int)$candidate;}$distinct=array();foreach($candidate_view['catalog_model']['options']as$option){$distinct[C::resolve($option['exterior_color'])['key']]=$option['exterior_color'];}if(count($distinct)>=2){$test_post=(int)$candidate;$test_colors=array_slice(array_values($distinct),0,2);break;}}
 if(count($ids)>1&&$test_post){
  $map=array(C::gallery_key($test_colors[0])=>array((int)$ids[0],(int)$ids[1]),C::gallery_key($test_colors[1])=>array((int)$ids[1]));
  $main_map=array();$preferred='';$meta_filter=static function($value,$post,$key,$single)use($test_post,&$map,&$main_map,&$preferred){if($post!==$test_post){return $value;}if($key===C::META){return array($map);}if($key===C::MAIN_META){return array($main_map);}if($key===C::DEFAULT_META){return array($preferred);}return $value;};
  add_filter('get_post_metadata',$meta_filter,10,4);
  try{
   $check(C::gallery_ids($test_post,$test_colors[0])===$map[C::gallery_key($test_colors[0])],'first color retains its own ordered attachments');
   $check(C::gallery_ids($test_post,$test_colors[1])===$map[C::gallery_key($test_colors[1])],'second color gallery stays separate from the first');
   ob_start();C::render($test_post);$html=ob_get_clean();preg_match('~<script type="application/json" data-vehicle-options>(.*?)</script>~s',$html,$match);$payload=json_decode($match[1],true);
   foreach($test_colors as$i=>$raw){$matching=array_values(array_filter($payload['options'],static fn($o)=>$o['color']['key']===C::resolve($raw)['key']));$check(count($matching[0]['photos'])===($i===0?2:1),'each configured color renders only its own image collection');}
   $main_map=array(C::gallery_key($test_colors[0])=>(int)$ids[1],C::gallery_key($test_colors[1])=>(int)$ids[0]);$preferred=C::gallery_key($test_colors[1]);
   $check(C::gallery_ids($test_post,$test_colors[0])[0]===(int)$ids[1],'explicit primary image takes precedence over legacy gallery order');
   $default=C::default_option($test_post);$check($default['exterior_color']===$test_colors[1],'configured available display color is selected first');
   $fallback_original=get_post_meta($test_post,'_thumbnail_id',true);$check(C::default_image($test_post)===(int)$ids[0]&&get_post_thumbnail_id($test_post)===(int)$ids[0],'public card thumbnail follows the configured color main image');
   $check(get_post_meta($test_post,'_thumbnail_id',true)===$fallback_original,'card image selection never overwrites the general fallback thumbnail');
   $white_options=array_values(array_filter(G::view($test_post)['catalog_model']['options'],static fn($o)=>$o['exterior_color']===$test_colors[0]));
   $next=C::default_option($test_post,array('catalog_model'=>array('options'=>$white_options)));$check($next['exterior_color']===$test_colors[0],'unavailable preferred color falls back to an available photographed color');
   ob_start();C::render($test_post);$default_html=ob_get_clean();preg_match('~<script type="application/json" data-vehicle-options>(.*?)</script>~s',$default_html,$default_match);$default_payload=json_decode($default_match[1],true);$check($default_payload['defaultOption']===C::option_id($default),'page gallery and card share the same default option');
   $valid=C::validate_primary_images(array($key=>(string)$ids[0]),array($key=>array((int)$ids[1])));$check(is_array($valid)&&$valid[$key]===(int)$ids[0],'valid independent main and additional images accepted');
   $check(is_wp_error(C::validate_primary_images(array($key=>'0'),array($key=>array((int)$ids[1])))),'additional images require an explicit primary before saving');
   $check(is_wp_error(C::validate_primary_images(array(),array($key=>array()))),'missing primary fields cannot silently clear galleries');
   $check(is_wp_error(C::validate_primary_images(array($key=>'https://example.invalid/x.jpg'),array($key=>array()))),'external URLs cannot become primary attachment IDs');
   $map=array();$main_map=array();
   ob_start();C::render($test_post);$empty_html=ob_get_clean();preg_match('~<script type="application/json" data-vehicle-options>(.*?)</script>~s',$empty_html,$empty_match);$empty_payload=json_decode($empty_match[1],true);
   $check(!array_filter(array_column($empty_payload['options'],'photos')),'unconfigured colors never borrow archived model images');
   $check(get_post_thumbnail_id($test_post)===0,'a mapped car without color photos does not reuse its archived thumbnail');
  }finally{remove_filter('get_post_metadata',$meta_filter,10);}
 }
 if($eligible_post){
  $check(C::selected($eligible_post,str_repeat('0',64))===null,'tampered or removed option is rejected');
  $input=array('name'=>'Read-only option check','email'=>'nobody@example.test','mobile'=>'0500000000','request_kind'=>'price_request','car_id'=>$eligible_post,'vehicle_option'=>str_repeat('0',64));
  $rejected=\AutoDealership\Leads\PublicIntake::submit($input);
  $check(is_wp_error($rejected)&&$rejected->get_error_code()==='adc_intake_vehicle_unavailable','public intake rejects a removed or forged stock option before creating a lead');
  $input['vehicle_option']=array('malformed');$rejected=\AutoDealership\Leads\PublicIntake::submit($input);
  $check(is_wp_error($rejected)&&$rejected->get_error_code()==='adc_invalid_lead','public intake rejects malformed option input before creating a lead');
  $supported=current_theme_supports('adc-color-configurator');remove_theme_support('adc-color-configurator');
  try{ob_start();G::render_variants($eligible_post);$legacy=ob_get_clean();$check(str_contains($legacy,'data-variant-field')&&str_contains($legacy,'onyx-variant-table'),'an older theme retains the original functional variant table');}finally{if($supported){add_theme_support('adc-color-configurator');}}
 }
 wp_set_current_user(0);if($ids){$check(is_wp_error(C::validate_galleries(array($key=>(string)$ids[0]))),'unauthorized gallery attachments rejected');}
 echo "Completed $checks color checks without persistent data changes.\n";
}finally{wp_set_current_user($previous);}
