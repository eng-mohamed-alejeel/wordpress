<?php
namespace AutoDealership\Inventory;
use AutoDealership\Core\Localization;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Stable editorial color mappings; Onyx source text and stock remain untouched. */
final class VehicleColors {
 public const META = '_adc_color_galleries';
 public const MAIN_META = '_adc_color_main_images';
 public const DEFAULT_META = '_adc_default_color';
 private static function t(string $ar,string $en):string{return Localization::language()==='en'?$en:$ar;}
 public static function key(string $name):string {
  $name=mb_strtolower(trim($name));
  $name=strtr($name,array('أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي'));
  $name=preg_replace('/[\s\p{Z}]+/u',' ',$name)??$name;
  $name=preg_replace('/\s*([\/\-])\s*/u',' $1 ',$name)??$name;
  return trim($name);
 }
 private static function defaults():array {
  $rows=array('ابيض'=>['#FFFFFF','White'],'اسود'=>['#171717','Black'],'فضي'=>['#C0C4CA','Silver'],'رمادي'=>['#80858D','Gray'],'احمر'=>['#C62732','Red'],'ازرق'=>['#2456A6','Blue'],'ازرق فاتح'=>['#91C8E8','Light blue'],'ازرق غامق'=>['#182F67','Dark blue'],'اخضر'=>['#237247','Green'],'بيج'=>['#D8C5A6','Beige'],'بني'=>['#76533D','Brown'],'ذهبي'=>['#BEA263','Gold'],'برتقالي'=>['#EC7C26','Orange'],'اصفر'=>['#F1D431','Yellow'],'ابيض لؤلؤي'=>['#F5F2E9','Pearl white']);
  $rows+=array('رمادي'=>['#80858D','Gray'],'رصاصي'=>['#7C8188','Gunmetal gray'],'رصاصي غامق'=>['#454B53','Dark gunmetal gray'],'رصاصي فاتح'=>['#ADB2B9','Light gunmetal gray'],'ذهبي فاتح'=>['#D8C79A','Light gold']);
  $out=array();foreach($rows as $name=>$row){$out[self::key($name)]=array('hex'=>$row[0],'second'=>'','en'=>$row[1],'alias'=>'');}foreach(array('ابيض / اسود'=>array('#FFFFFF','#171717','White / black'),'اخضر - اسود'=>array('#237247','#171717','Green / black'),'رصاصي - اسود'=>array('#7C8188','#171717','Gunmetal gray / black'),'رمادي / اسود'=>array('#80858D','#171717','Gray / black'))as$name=>$row){$out[self::key($name)]=array('hex'=>$row[0],'second'=>$row[1],'en'=>$row[2],'alias'=>'');}return $out;
 }
 public static function definitions():array {return array_replace(self::defaults(),(array)get_option('adc_vehicle_colors',array()));}
 public static function resolve(string $raw):array {
  $key=self::key($raw);$all=self::definitions();$row=$all[$key]??array();
  if(!empty($row['alias'])&&isset($all[$row['alias']])&&empty($all[$row['alias']]['alias'])){$key=$row['alias'];$row=$all[$key];}
  $hex=sanitize_hex_color($row['hex']??'')?:'';$second=sanitize_hex_color($row['second']??'')?:'';
  return array('key'=>$key,'raw'=>$raw,'hex'=>$hex,'second'=>$second,'label'=>Localization::language()==='en'&&!empty($row['en'])?$row['en']:$raw);
 }
 public static function swatch(string $raw):string {
  $c=self::resolve($raw);$style=$c['hex']?'--vehicle-color:'.$c['hex'].';--vehicle-color-second:'.($c['second']?:$c['hex']).';':'';
  return '<span class="adc-color-dot'.(!$c['hex']?' is-unknown':'').'" style="'.esc_attr($style).'" aria-hidden="true">'.(!$c['hex']?'?':'').'</span>'.(!$c['hex']?'<span>'.esc_html($c['label']).'</span>':'');
 }
 public static function boot():void {
  add_action('admin_menu',static function(){add_submenu_page('adc-area-inventory',self::t('ألوان السيارات','Vehicle colors'),self::t('ألوان السيارات','Vehicle colors'),'edit_cars','adc-vehicle-colors',array(self::class,'admin'));},110);
  add_filter('post_thumbnail_id',static function($image,$post){
   if(is_admin()||!$post||$post->post_type!=='car'){return $image;}
   static $mapped=array();$id=(int)$post->ID;if(PHP_SAPI==='cli'||!array_key_exists($id,$mapped)){$mapped[$id]=GroupedModelCatalog::is_mapped($id);}
   return $mapped[$id]?self::default_image($id):$image;
  },10,2);
  add_action('admin_post_adc_save_vehicle_colors',array(self::class,'save_definitions'));
  add_action('wp_enqueue_scripts',static function(){if(is_singular('car')&&current_theme_supports('adc-color-configurator')){self::assets();}});
  add_action('admin_enqueue_scripts',static function(){if(in_array($_GET['page']??'',array('adc-car-publishing','adc-vehicle-colors'),true)){self::assets(false);}});
 }
 private static function assets(bool $public=true):void {
  $base=plugin_dir_url(ADC_FILE);wp_enqueue_style('adc-vehicle-colors',$base.'assets/css/vehicle-colors.css',array(),filemtime(ADC_PATH.'assets/css/vehicle-colors.css'));
  if($public){wp_enqueue_script('adc-vehicle-colors',$base.'assets/js/vehicle-colors.js',array(),filemtime(ADC_PATH.'assets/js/vehicle-colors.js'),true);}
  elseif(($_GET['page']??'')==='adc-vehicle-colors'){wp_enqueue_script('adc-vehicle-colors-admin',$base.'assets/js/vehicle-colors-admin.js',array(),filemtime(ADC_PATH.'assets/js/vehicle-colors-admin.js'),true);}
 }
 public static function source_names(int $post=0):array {
  if(get_option('adc_db_version')!==Schema::VERSION){return array();}
  global $wpdb;$stock=Schema::table('onyx_stock');
  if($post){$rows=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT s.exterior_color FROM $stock s INNER JOIN ".Schema::table('catalog_models').' m ON m.id=s.model_id WHERE m.public_post_id=%d ORDER BY s.exterior_color',$post));}
  else{$rows=$wpdb->get_col("SELECT DISTINCT exterior_color FROM $stock ORDER BY exterior_color");}
  return array_values(array_filter($rows?:array(),static fn($s)=>is_string($s)&&trim($s)!==''));
 }
 public static function admin():void {
  if(!current_user_can('edit_cars')){wp_die('Access denied.');}
  $names=self::source_names();$all=self::definitions();foreach(array_keys($all)as$name){if(!in_array($name,array_map(array(self::class,'key'),$names),true)){$names[]=$name;}}
  $unique=array();foreach($names as$name){$unique[self::key($name)]=$name;}$names=array_values($unique);
  echo '<div class="wrap"><h1>'.esc_html(self::t('ألوان السيارات من أونكس','Vehicle colors from Onyx')).'</h1><p>'.esc_html(self::t('الاسم الأصلي محفوظ كما ورد من أونكس. اربط المسميات المتطابقة فقط؛ لا تدمج درجات مختلفة. قيم الألوان تمثل عينات تقريبية للشاشة، وليست ضمانًا لدرجة الطلاء.','Original Onyx names remain unchanged. Link equivalent names only; keep different shades separate. Screen swatches are approximate paint references.')).'</p>';
  if(isset($_GET['saved'])){echo '<div class="notice notice-success"><p>'.esc_html(self::t('تم حفظ الألوان.','Colors saved.')).'</p></div>';}
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('adc_vehicle_colors');echo '<input type="hidden" name="action" value="adc_save_vehicle_colors"><input type="hidden" name="revision" value="'.esc_attr(hash('sha256',wp_json_encode(get_option('adc_vehicle_colors',array())))).'"><input type="hidden" name="color_count" value="'.count($names).'"><div class="adc-color-admin-grid">';
  foreach($names as$name){$key=self::key($name);$row=$all[$key]??array();$index=hash('sha256',$key);echo '<fieldset class="adc-color-admin-card"><legend>'.esc_html($name).'</legend><input type="hidden" name="colors['.$index.'][name]" value="'.esc_attr($name).'">';
   foreach(array('hex'=>self::t('اللون HEX','Color HEX'),'second'=>self::t('لون ثانٍ اختياري HEX','Optional second color HEX'),'en'=>self::t('الاسم بالإنجليزية','English name'))as$field=>$label){
    echo '<label>'.esc_html($label).'<input type="text" data-color-field="'.esc_attr($field).'" name="colors['.$index.']['.$field.']" value="'.esc_attr($row[$field]??'').'" '.($field!=='en'?'dir="ltr" pattern="#[a-fA-F0-9]{6}" placeholder="#FFFFFF"':'maxlength="100"').'></label>';
    if($field!=='en'){echo '<input type="color" class="adc-native-color-picker" data-color-picker="'.esc_attr($field).'" value="'.esc_attr(sanitize_hex_color($row[$field]??'')?:'#FFFFFF').'" aria-label="'.esc_attr(self::t('اختيار درجة اللون: ','Choose color shade: ').$name.' — '.$label).'">';}
   }
   echo '<label>'.esc_html(self::t('مطابقة مع لون معتمد (اختياري)','Match an existing color (optional)')).'<select name="colors['.$index.'][alias]"><option value="">'.esc_html(self::t('لون مستقل','Independent color')).'</option>';foreach($all as$target=>$def){if($target===$key||!empty($def['alias'])||empty($def['hex']))continue;echo '<option value="'.esc_attr($target).'" data-hex="'.esc_attr($def['hex']).'" data-second="'.esc_attr($def['second']??'').'" '.selected($row['alias']??'',$target,false).'>'.esc_html($target).'</option>'; }echo '</select></label>'.self::swatch($name).'</fieldset>';
  }
  echo '</div><p><button class="button button-primary">'.esc_html(self::t('حفظ الألوان','Save colors')).'</button></p></form></div>';
 }
 public static function validate_definitions($rows) {
  $error=static fn()=>new \WP_Error('adc_vehicle_colors',self::t('تحقق من أسماء الألوان وقيم HEX والمطابقات؛ يجب أن تشير المطابقة إلى لون مستقل معروف.','Check color names, HEX values and matches; a match must refer to a defined independent color.'));
  if(!is_array($rows)||count($rows)>500){return $error();}$out=array();
  foreach($rows as$row){
   if(!is_array($row)){return $error();}
   foreach(array('name','hex','second','en','alias')as$f){if(!is_string($row[$f]??null)||mb_strlen($row[$f])>120){return $error();}}
   foreach(array('hex','second')as$f){if($row[$f]!==''&&!preg_match('/^#[a-fA-F0-9]{6}$/D',$row[$f])){return $error();}}
   if($row['second']&&!$row['hex']&&!$row['alias']){return $error();}
   $key=self::key($row['name']);if($key===''||isset($out[$key])){return $error();}
   $out[$key]=array('hex'=>$row['hex'],'second'=>$row['second'],'en'=>sanitize_text_field($row['en']),'alias'=>self::key($row['alias']));
  }
  foreach($out as$key=>$row){if($row['alias']&&($key===$row['alias']||!isset($out[$row['alias']])||$out[$row['alias']]['alias']||!$out[$row['alias']]['hex'])){return $error();}}
  return $out;
 }
 public static function save_definitions():void {
  if(!current_user_can('edit_cars')){wp_die('Access denied.',403);}check_admin_referer('adc_vehicle_colors');$input=wp_unslash($_POST);
  $rows=$input['colors']??null;
  if(!is_array($rows)||!is_scalar($input['color_count']??null)||(int)$input['color_count']!==count($rows)){wp_die(esc_html(self::t('لم تصل جميع حقول الألوان. راجع حد حقول PHP ثم أعد الحفظ.','Some color fields were missing. Check the PHP input limit and save again.')));}
  $out=self::validate_definitions($rows);if(is_wp_error($out)){wp_die(esc_html($out->get_error_message()));}
  global $wpdb;$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",'adc_vehicle_colors'));$old=$raw===null?array():maybe_unserialize($raw);
  if(!is_string($input['revision']??null)||!hash_equals(hash('sha256',wp_json_encode($old)),$input['revision'])){wp_die(esc_html(self::t('تغيرت الألوان. أعد تحميل الصفحة قبل الحفظ.','Colors changed. Reload before saving.')));}
  // Compare-and-swap prevents two editors from silently overwriting one another, on any storage engine.
  if($raw===null){$ok=add_option('adc_vehicle_colors',$out,'',false);}
  else{$serialized=maybe_serialize($out);$ok=$serialized===$raw||$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=%s",$serialized,'adc_vehicle_colors',$raw))===1;}
  if(!$ok){wp_die(esc_html(self::t('تعذر الحفظ أو تغيرت الألوان أثناء الحفظ. أعد تحميل الصفحة وحاول مجددًا.','Saving failed or colors changed while saving. Reload and try again.')));}
  wp_cache_delete('adc_vehicle_colors','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');
  if(function_exists('car_dealer_clear_public_page_cache')){car_dealer_clear_public_page_cache();}
  wp_safe_redirect(admin_url('admin.php?page=adc-vehicle-colors&saved=1'));exit;
 }
 public static function validate_galleries($input) {
  if(!is_array($input)||count($input)>100){return new \WP_Error('adc_colors',self::t('تحقق من معارض الألوان.','Invalid color galleries.'));}$out=array();
  foreach($input as$key=>$csv){if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_string($csv)){return new \WP_Error('adc_colors',self::t('تحقق من معرض صور اللون.','Invalid color gallery.'));}$ids=$csv===''?array():explode(',',$csv);if(count($ids)>20){return new \WP_Error('adc_colors',self::t('الحد الأقصى 20 صورة لكل لون.','Maximum 20 images per color.'));}
   foreach($ids as$id){if(!ctype_digit($id)||(int)$id<1||!wp_attachment_is_image((int)$id)||!current_user_can('read_post',(int)$id)){return new \WP_Error('adc_colors',self::t('اختر صورًا صالحة من مكتبة الوسائط.','Select valid images from the media library.'));}}$out[$key]=array_values(array_unique(array_map('intval',$ids)));
  }return $out;
 }
 public static function gallery_key(string $raw):string {return hash('sha256',self::resolve($raw)['key']);}
 public static function gallery_ids(int $post,string $raw):array {
  $all=(array)get_post_meta($post,self::META,true);$mains=(array)get_post_meta($post,self::MAIN_META,true);$keys=array_unique(array(self::gallery_key($raw),hash('sha256',self::key($raw))));
  foreach(self::source_names($post)as$source){if(self::resolve($source)['key']===self::resolve($raw)['key']){$keys[]=hash('sha256',self::key($source));}}
  foreach(array_unique($keys) as$key){$ids=array_values(array_filter((array)($all[$key]??array()),static fn($id)=>wp_attachment_is_image((int)$id)));$main=(int)($mains[$key]??0);if($main&&wp_attachment_is_image($main)){$ids=array_values(array_unique(array_merge(array($main),$ids)));}if($ids){return $ids;}}return array();
 }
 /** Resolve an available color with its own configured photos. */
 public static function default_option(int $post,?array $view=null):?array {
  $view=$view??GroupedModelCatalog::view($post);if(!$view){return null;}$options=$view['catalog_model']['options'];$preferred=(string)get_post_meta($post,self::DEFAULT_META,true);$chosen=null;$first_photo=null;
  foreach($options as$row){$has_photos=(bool)self::gallery_ids($post,$row['exterior_color']);if($has_photos&&!$first_photo){$first_photo=$row;}if(self::gallery_key($row['exterior_color'])===$preferred){$chosen=$row;if($has_photos){return $row;}}}
  return $first_photo??$chosen??($options[0]??null);
 }
 public static function default_image(int $post):int {
  if(get_option('adc_db_version')!==Schema::VERSION){return 0;}
  $galleries=get_post_meta($post,self::META,true);$mains=get_post_meta($post,self::MAIN_META,true);
  if(!array_filter(is_array($galleries)?$galleries:array())&&!array_filter(is_array($mains)?$mains:array())){return 0;}
  static $cache=array();$cacheable=PHP_SAPI!=='cli'&&!is_admin()&&!wp_doing_ajax();$key=$post.'|'.hash('sha256',serialize(array($galleries,$mains,get_post_meta($post,self::DEFAULT_META,true))));
  if($cacheable&&array_key_exists($key,$cache)){return $cache[$key];}
  $option=self::default_option($post);$ids=$option?self::gallery_ids($post,$option['exterior_color']):array();$image=(int)($ids[0]??0);if($cacheable){$cache[$key]=$image;}return $image;
 }
 public static function validate_primary_images($input,array $galleries) {
  if(!is_array($input)||count($input)>100){return new \WP_Error('adc_colors',self::t('تحقق من صور الألوان الرئيسية.','Check the main color images.'));}$out=array();
  foreach($input as$key=>$value){
   if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_string($value)||!ctype_digit($value)||!array_key_exists($key,$galleries)){return new \WP_Error('adc_colors',self::t('تحقق من صور الألوان الرئيسية.','Check the main color images.'));}
   $id=(int)$value;if(($id&&(!wp_attachment_is_image($id)||!current_user_can('read_post',$id)))||(!$id&&!empty($galleries[$key]))){return new \WP_Error('adc_colors',self::t('اختر صورة رئيسية صالحة لكل لون يحتوي على صور إضافية.','Choose a valid main image for each color with additional photos.'));}
   if(count(array_unique(array_merge($id?array($id):array(),$galleries[$key])))>20){return new \WP_Error('adc_colors',self::t('الحد الأقصى صورة رئيسية و19 صورة إضافية لكل لون.','Each color supports one main image and up to 19 additional photos.'));}$out[$key]=$id;
  }
  if(array_diff_key($galleries,$out)){return new \WP_Error('adc_colors',self::t('لم تصل جميع حقول صور الألوان؛ أعد تحميل الصفحة.','Some color image fields are missing; reload the page.'));}
  return $out;
 }
 public static function price_label(array $row):string {
  if((int)$row['listed_price']<1||$row['public_price']===null){return self::t('اسأل عن السعر','Ask for price');}
  return function_exists('car_dealer_catalog_format_price')?car_dealer_catalog_format_price(\AutoDealership\Pricing\Money::decimal((string)$row['public_price'])):\AutoDealership\Pricing\Money::display((string)$row['public_price']);
 }
 public static function option_id(array $row):string {return hash('sha256',wp_json_encode(array((string)$row['model_year'],(string)$row['trim_name'],(string)$row['exterior_color'],(string)$row['listed_price'])));}
 public static function selected(int $post,string $id):?array {
  $view=GroupedModelCatalog::view($post);if(!$view){return null;}foreach($view['catalog_model']['options']as$row){if(hash_equals(self::option_id($row),$id)){return $row;}}return null;
 }
 private static function photos(array $ids,string $title):array {
  $out=array();foreach(array_slice(array_unique($ids),0,20)as$id){$large=wp_get_attachment_image_url((int)$id,'large');if(!$large)continue;$out[]=array('src'=>$large,'full'=>wp_get_attachment_image_url((int)$id,'full'),'thumb'=>wp_get_attachment_image_url((int)$id,'thumbnail'),'alt'=>get_post_meta((int)$id,'_wp_attachment_image_alt',true)?:$title);}return $out;
 }
 public static function render(int $post):bool {
  $view=GroupedModelCatalog::view($post);if(!$view){return false;}$title=get_the_title($post);$options=array();
  foreach($view['catalog_model']['options']as$row){$color=self::resolve($row['exterior_color']);$own=self::photos(self::gallery_ids($post,$row['exterior_color']),$title.' — '.$color['label']);$options[]=array('id'=>self::option_id($row),'color'=>$color,'year'=>(string)$row['model_year'],'trim'=>$row['trim_name'],'trimLabel'=>function_exists('car_dealer_catalog_value_label')?car_dealer_catalog_value_label($row['trim_name']):__($row['trim_name'],'auto-dealership-core'),'price'=>$row['public_price'],'priceText'=>self::price_label($row),'photos'=>$own,'technical'=>VehicleTechnicalDetails::payload($post,$row));}
  $default=self::default_option($post,$view);$default_id=$default?self::option_id($default):$options[0]['id'];$default_index=array_search($default_id,array_column($options,'id'),true);$initial=$options[$default_index===false?0:$default_index]['photos']??array();
  $data=array('options'=>$options,'defaultOption'=>$default_id,'title'=>$title,'labels'=>array('empty'=>self::t('صور هذا اللون غير متوفرة حاليًا.','Photos for this color are not available yet.'),'close'=>self::t('إغلاق الصورة','Close image'),'image'=>self::t('صورة','Image'),'selected'=>self::t('الخيار المحدد','Selected option')));
  echo '<section class="adc-vehicle-configurator" data-vehicle-configurator data-post="'.$post.'"><script type="application/json" data-vehicle-options>'.wp_json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script><div class="adc-vehicle-main" data-vehicle-main>';
  if($initial){echo '<img src="'.esc_url($initial[0]['src']).'" alt="'.esc_attr($initial[0]['alt']).'" fetchpriority="high">';}else{echo '<p>'.esc_html(self::t('صور السيارة غير متوفرة حاليًا.','Vehicle photos are not available yet.')).'</p>';}
  echo '</div><div class="adc-vehicle-thumbnails" data-vehicle-thumbnails aria-label="'.esc_attr(self::t('صور السيارة','Vehicle photos')).'"></div>';
  self::render_picker();echo '</section>';
  return true;
 }
 public static function render_picker():void {
  echo '<div class="adc-vehicle-inline-colors" data-vehicle-choices hidden><div class="adc-vehicle-swatches" data-vehicle-swatches role="group" aria-label="'.esc_attr(self::t('ألوان السيارة','Vehicle colors')).'"></div><span class="adc-vehicle-choice-status" data-vehicle-selection role="status" aria-live="polite"></span></div>';
 }
 public static function render_variant_picker():void {
  echo '<label class="adc-vehicle-variant-picker" data-vehicle-trim-label hidden>'.esc_html(self::t('الفئة والموديل','Trim and model year')).'<select data-vehicle-trim></select></label>';
 }
}
