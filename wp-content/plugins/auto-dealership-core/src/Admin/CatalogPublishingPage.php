<?php
namespace AutoDealership\Admin;

use AutoDealership\Content\CatalogPublishingService as Service;
use AutoDealership\Core\Localization;
use AutoDealership\Database\Schema;
use AutoDealership\Inventory\GroupedModelCatalog;

defined('ABSPATH') || exit;

/** Vehicle publishing workspace; WordPress continues to own stable public URLs. */
final class CatalogPublishingPage {
	private static function t(string $ar,string $en):string{return Localization::language()==='en'?$en:$ar;}
	public static function url(int $id=0):string{return add_query_arg(array('page'=>'adc-car-publishing')+($id?array('post_id'=>$id):array()),admin_url('admin.php'));}
	public static function boot():void{
		add_action('admin_menu',array(self::class,'menu'),100);
		add_action('admin_init',array(self::class,'redirect_legacy'),20);
		add_action('admin_enqueue_scripts',array(self::class,'assets'));
		add_action('wp_enqueue_scripts',static function():void{if(is_singular('car')&&!GroupedModelCatalog::is_mapped((int)get_queried_object_id())&&get_post_meta(get_queried_object_id(),'_car_gallery_ids',true)){wp_enqueue_style('adc-catalog-gallery',plugins_url('assets/css/catalog-gallery.css',ADC_FILE),array(),ADC_VERSION);}});
		add_action('admin_post_adc_catalog_save_car',array(self::class,'save_car'));
		add_action('admin_post_adc_catalog_status',array(self::class,'status'));
		add_action('admin_post_adc_catalog_save_term',array(self::class,'save_term'));
		add_filter('get_edit_post_link',static function($url,$id){return get_post_type($id)==='car'?self::url((int)$id):$url;},20,2);
		add_filter('get_edit_term_link',static function($url,$id,$taxonomy){return in_array($taxonomy,array('car_brand','car_category'),true)?self::term_url($taxonomy,(int)$id):$url;},20,3);
	}
	public static function menu():void{
		add_submenu_page('adc-area-inventory',self::t('سيارات الموقع','Website vehicles'),self::t('سيارات الموقع','Website vehicles'),'edit_cars','adc-car-publishing',array(self::class,'render'));
		add_submenu_page('adc-area-inventory',self::t('الماركات','Brands'),self::t('الماركات','Brands'),'manage_car_brands','adc-car-brands',array(self::class,'terms'));
		add_submenu_page('adc-area-inventory',self::t('تصنيفات السيارات','Vehicle categories'),self::t('تصنيفات السيارات','Vehicle categories'),'manage_car_categories','adc-car-categories',array(self::class,'terms'));
		remove_submenu_page('adc-area-inventory','edit.php?post_type=car');
		remove_submenu_page('adc-area-inventory','edit-tags.php?taxonomy=car_brand&post_type=car');
		remove_submenu_page('adc-area-inventory','edit-tags.php?taxonomy=car_category&post_type=car');
	}
	public static function term_url(string $taxonomy,int $id=0):string{return add_query_arg(array('page'=>$taxonomy==='car_brand'?'adc-car-brands':'adc-car-categories')+($id?array('term_id'=>$id):array()),admin_url('admin.php'));}
	/** Resolve old bookmarks without intercepting nonce-protected native mutations. */
	public static function legacy_destination(string $screen,array $query):string{
		if($screen==='edit.php'&&($query['post_type']??'')==='car'){return self::url();}
		if($screen==='post-new.php'&&($query['post_type']??'')==='car'){return add_query_arg('new','1',self::url());}
		if($screen==='post.php'&&($query['action']??'edit')==='edit'&&get_post_type(absint($query['post']??0))==='car'){return self::url(absint($query['post']));}
		if(in_array($screen,array('edit-tags.php','term.php'),true)&&in_array($query['taxonomy']??'',array('car_brand','car_category'),true)&&in_array($query['action']??'',array('','edit'),true)){return self::term_url($query['taxonomy'],absint($query['tag_ID']??0));}
		return '';
	}
	public static function redirect_legacy():void{
		if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||wp_doing_ajax()){return;}
		$target=self::legacy_destination($GLOBALS['pagenow']??'',$_GET);
		if($target){wp_safe_redirect($target);exit;}
	}
	public static function assets():void{
		if(!in_array($_GET['page']??'',array('adc-car-publishing','adc-car-brands','adc-car-categories'),true)){return;}
		wp_enqueue_media();wp_enqueue_style('adc-publishing',plugins_url('assets/css/catalog-publishing.css',ADC_FILE),array(),ADC_VERSION);
		wp_enqueue_script('adc-publishing',plugins_url('assets/js/catalog-publishing.js',ADC_FILE),array('media-views'),ADC_VERSION,true);
		wp_enqueue_script('adc-plate-fields',plugins_url('assets/js/plate-fields.js',ADC_FILE),array(),ADC_VERSION,true);
		wp_enqueue_style('adc-model-variants',plugins_url('assets/css/model-variants.css',ADC_FILE),array(),ADC_VERSION);
		wp_enqueue_script('adc-model-variants',plugins_url('assets/js/model-variants.js',ADC_FILE),array(),ADC_VERSION,true);
	}
	private static function start(string $title):void{
		echo '<div class="wrap adc-publishing" dir="'.(Localization::language()==='en'?'ltr':'rtl').'"><header class="adc-publishing-header"><div><p class="adc-eyebrow">'.esc_html(self::t('كتالوج الموقع','Website catalog')).'</p><h1>'.esc_html($title).'</h1></div><nav aria-label="'.esc_attr(self::t('إدارة الكتالوج','Catalog management')).'">';
		foreach(array('adc-car-publishing'=>array('edit_cars',self::t('السيارات','Vehicles')),'adc-car-brands'=>array('manage_car_brands',self::t('الماركات','Brands')),'adc-car-categories'=>array('manage_car_categories',self::t('التصنيفات','Categories')))as$page=>$item){if(current_user_can($item[0])){echo '<a class="button" href="'.esc_url(admin_url('admin.php?page='.$page)).'">'.esc_html($item[1]).'</a> ';}}
		echo '</nav></header>';
		$error=get_transient('adc_publishing_error_'.get_current_user_id());if($error){delete_transient('adc_publishing_error_'.get_current_user_id());echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';}
		if(isset($_GET['saved'])){echo '<div class="notice notice-success"><p>'.esc_html(self::t('تم حفظ التغييرات.','Changes saved.')).'</p></div>';}
	}
	private static function guard(string $action):void{check_admin_referer($action);}
	private static function finish($result,string $url):void{
		if(is_wp_error($result)){set_transient('adc_publishing_error_'.get_current_user_id(),$result->get_error_message(),120);}else{$url=add_query_arg('saved','1',$url);}
		wp_safe_redirect($url);exit;
	}
	public static function save_car():void{
		self::guard('adc_catalog_save_car');$input=wp_unslash($_POST);
		$plates=$input['_car_plate_types']??array();
		if(!is_array($plates)||count($plates)>1||($plates&&!is_string(reset($plates)))){self::finish(new \WP_Error('adc_plate',self::t('اختر نوع لوحات واحدًا فقط.','Select one plate type only.')),self::url(absint($input['post_id']??0)));}
		$input['plate_type']=$plates?reset($plates):'';
		foreach(array('car_brand','car_category')as$taxonomy){if(isset($input[$taxonomy.'_present'])){$input[$taxonomy]=$input[$taxonomy]??array();}}
		$result=Service::save_car($input);self::finish($result,is_wp_error($result)?add_query_arg('new',empty($input['post_id'])?'1':'0',self::url(absint($input['post_id']??0))):self::url($result['id']));
	}
	public static function status():void{self::guard('adc_catalog_status');$result=Service::change_status(absint($_POST['post_id']??0),sanitize_key(wp_unslash($_POST['change']??'')),sanitize_text_field(wp_unslash($_POST['revision']??'')));self::finish($result,self::url());}
	public static function save_term():void{self::guard('adc_catalog_save_term');$input=wp_unslash($_POST);$result=Service::save_term($input);self::finish($result,self::term_url(in_array($input['taxonomy']??'',array('car_brand','car_category'),true)?$input['taxonomy']:'car_brand',is_wp_error($result)?absint($input['term_id']??0):$result['id']));}
	private static function statuses():array{return array('publish'=>self::t('منشورة','Published'),'draft'=>self::t('مسودة','Draft'),'pending'=>self::t('بانتظار المراجعة','Pending review'),'private'=>self::t('خاصة','Private'),'future'=>self::t('مجدولة','Scheduled'),'trash'=>self::t('في السلة','In trash'));}
	public static function render():void{
		if(!current_user_can('edit_cars')){wp_die(esc_html(self::t('لا تملك صلاحية الوصول.','Access denied.')));}
		$id=absint($_GET['post_id']??0);
		if($id||isset($_GET['new'])&&$_GET['new']==='1'){self::editor($id);return;}
		self::start(self::t('سيارات الموقع','Website vehicles'));
		echo '<p>'.esc_html(self::t('بيانات السيارة وتوفرها وأسعارها تُحدّث تلقائيًا من أونكس. يمكنك تعديل الصور والوصف ورسوم اللوحات هنا.','Vehicle details, availability and prices update automatically from Onyx. Edit photos, description and plate fees here.')).'</p><p><a class="button button-primary" href="'.esc_url(add_query_arg('new','1',self::url())).'">'.esc_html(self::t('إعداد صفحة سيارة','Prepare a vehicle page')).'</a></p>';
		$search=sanitize_text_field(wp_unslash($_GET['search']??''));$status=sanitize_key($_GET['status']??'');$page=max(1,absint($_GET['paged']??1));$statuses=self::statuses();
		global $wpdb;
		$filters=array();$labels=array('brand'=>self::t('الماركة','Brand'),'model'=>self::t('الطراز','Model'),'trim_name'=>self::t('الفئة','Trim'),'model_year'=>self::t('الموديل','Model year'));
		foreach($labels as$key=>$label){$filters[$key]=sanitize_text_field(wp_unslash($_GET[$key]??''));}
		$availability=sanitize_key($_GET['availability']??'');if(!in_array($availability,array('available','unavailable'),true)){$availability='';}
		echo '<form method="get" class="adc-publishing-filters"><input type="hidden" name="page" value="adc-car-publishing"><label>'.esc_html(self::t('البحث','Search')).'<input name="search" value="'.esc_attr($search).'"></label><label>'.esc_html(self::t('حالة النشر','Publication status')).'<select name="status"><option value="">'.esc_html(self::t('كل الحالات','All statuses')).'</option>';foreach($statuses as$key=>$label){echo '<option value="'.esc_attr($key).'" '.selected($status,$key,false).'>'.esc_html($label).'</option>';}echo '</select></label>';foreach($labels as$key=>$label){echo '<label>'.esc_html($label).'<input name="'.esc_attr($key).'" value="'.esc_attr($filters[$key]).'"></label>';}echo '<label>'.esc_html(self::t('التوفر','Availability')).'<select name="availability"><option value="">'.esc_html(self::t('الكل','All')).'</option><option value="available" '.selected($availability,'available',false).'>'.esc_html(self::t('متوفرة','Available')).'</option><option value="unavailable" '.selected($availability,'unavailable',false).'>'.esc_html(self::t('غير متوفرة','Unavailable')).'</option></select></label><button class="button">'.esc_html(self::t('عرض النتائج','Show results')).'</button></form>';
		$args=array('post_type'=>'car','post_status'=>isset($statuses[$status])?$status:array_keys($statuses),'s'=>$search,'posts_per_page'=>20,'paged'=>$page,'perm'=>'editable','adc_website_inventory'=>true);
		if(!current_user_can('edit_others_cars')){$args['author']=get_current_user_id();}
		$where_filter=static function($where,$query)use($wpdb,$filters,$availability){
			if(!$query->get('adc_website_inventory')){return $where;}
			$conditions=array();foreach($filters as$key=>$value){if($value!==''){$conditions[]=$wpdb->prepare("cm.$key=%s",$value);}}
			if($conditions){$where.=' AND EXISTS (SELECT 1 FROM '.Schema::table('catalog_models').' cm WHERE cm.public_post_id='.$wpdb->posts.'.ID AND '.implode(' AND ',$conditions).')';}
			if($availability!==''){$exists='EXISTS (SELECT 1 FROM '.Schema::table('catalog_models').' cm INNER JOIN '.Schema::table('onyx_stock').' s ON s.model_id=cm.id WHERE cm.public_post_id='.$wpdb->posts.'.ID AND '.GroupedModelCatalog::availability().')';$where.=' AND '.($availability==='unavailable'?'NOT ':'').$exists;}
			return $where;
		};
		add_filter('posts_where',$where_filter,10,2);try{$query=new \WP_Query($args);$failed=(bool)$wpdb->last_error;}finally{remove_filter('posts_where',$where_filter,10);}

		echo '<div class="adc-table-scroll"><table class="widefat striped"><thead><tr>';foreach(array(self::t('السيارة','Vehicle'),self::t('حالة النشر','Status'),self::t('البيانات والأسعار','Data and prices'),self::t('الكمية المتاحة','Available quantity'),self::t('الألوان','Colors'),self::t('آخر تعديل','Last updated'),self::t('الإجراءات','Actions'))as$label){echo '<th scope="col">'.esc_html($label).'</th>';}echo '</tr></thead><tbody>';
		foreach($query->posts as$post){if(!current_user_can('edit_post',$post->ID)){continue;}$mapped=GroupedModelCatalog::is_mapped($post->ID);$view=$mapped?GroupedModelCatalog::view($post->ID):null;echo '<tr><td><div class="adc-car-row">'.(get_the_post_thumbnail($post->ID,array(90,65))?:'<img width="90" height="65" alt="'.esc_attr(self::t('صورة افتراضية للسيارة','Default vehicle image')).'" src="'.esc_url(function_exists('car_dealer_vehicle_placeholder')?car_dealer_vehicle_placeholder():includes_url('images/media/default.png')).'">').'<a href="'.esc_url(self::url($post->ID)).'"><strong>'.esc_html($post->post_title).'</strong></a></div></td><td><span class="adc-status">'.esc_html($statuses[$post->post_status]??$post->post_status).'</span></td><td>'.esc_html($mapped?self::t('مربوطة بأونكس','Linked to Onyx'):self::t('صفحة سابقة','Existing page'));if($view){echo '<br>'.esc_html(\AutoDealership\Pricing\Money::display(\AutoDealership\Pricing\Money::from_sar($view['price'])??0)).'<br><small>'.esc_html($view['price_label']).'</small>';}echo '</td><td>'.esc_html((string)($view['catalog_model']['available_count']??0)).'<br><small>'.esc_html($view?self::t('متوفرة','Available'):self::t('غير متوفرة','Unavailable')).'</small></td><td>'.esc_html($view['color']??'—').'</td><td>'.esc_html(get_the_modified_date('Y-m-d H:i',$post)).'</td><td>';if($post->post_status!=='trash'){echo '<a class="button" href="'.esc_url(self::url($post->ID)).'">'.esc_html(self::t('تعديل','Edit')).'</a> ';if($post->post_status==='publish'){echo '<a class="button" href="'.esc_url(get_permalink($post)).'">'.esc_html(self::t('عرض الموقع','View on site')).'</a>';}}if(current_user_can('delete_post',$post->ID)){self::status_form($post->ID,$post->post_status==='trash'?'restore':'trash');}echo '</td></tr>';}
		if($failed||!$query->posts){echo '<tr><td colspan="7">'.esc_html($failed?self::t('تعذر تحميل سيارات الموقع.','Unable to load website vehicles.'):self::t('لا توجد صفحات مطابقة.','No matching pages.')).'</td></tr>';}echo '</tbody></table></div>';self::pagination((int)$query->max_num_pages,$page);echo '</div>';
	}
	private static function status_form(int $id,string $action):void{echo '<form class="adc-inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_catalog_status"><input type="hidden" name="post_id" value="'.$id.'"><input type="hidden" name="change" value="'.esc_attr($action).'"><input type="hidden" name="revision" value="'.esc_attr(Service::fingerprint($id)).'">';wp_nonce_field('adc_catalog_status');echo '<button type="submit" class="button">'.esc_html($action==='trash'?self::t('نقل للسلة','Move to trash'):self::t('استعادة كمسودة','Restore as draft')).'</button></form>';}
	private static function pagination(int $pages,int $current):void{if($pages>1){echo '<nav class="adc-pagination" aria-label="'.esc_attr(self::t('الصفحات','Pages')).'">'.wp_kses_post(paginate_links(array('base'=>str_replace('99999999','%#%',add_query_arg('paged',99999999)),'format'=>'','current'=>$current,'total'=>$pages))).'</nav>';}}
	private static function field(string $name,string $label,string $value='',string $type='text',bool $readonly=false):void{echo '<label class="adc-field">'.esc_html($label).'<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"'.($readonly?' readonly aria-readonly="true"':(in_array($name,array('title','name'),true)?' required maxlength="200"':'')).'></label>';}
	private static function media(string $field,array $ids,string $label,bool $multiple=false,int $limit=20):void{
		$limit=max(1,min(20,$limit));echo '<section class="adc-media" data-media-field="'.esc_attr($field).'" data-media-multiple="'.($multiple?'1':'0').'" data-media-limit="'.$limit.'"><h3>'.esc_html($label).'</h3><input type="hidden" name="'.esc_attr($field).'" value="'.esc_attr(implode(',',$ids)).'"><div class="adc-media-preview">';
		foreach($ids as$image){if($image){echo '<figure data-image-id="'.(int)$image.'" data-main-src="'.esc_url(wp_get_attachment_image_url($image,'medium')).'">'.wp_get_attachment_image($image,$multiple?'thumbnail':'medium').'<button type="button" data-remove-image aria-label="'.esc_attr(self::t('إزالة الصورة','Remove image')).'">×</button></figure>';}}
		echo '</div><button type="button" class="button" data-select-images>'.esc_html($multiple?self::t('اختيار الصور','Select images'):self::t('اختيار الصورة','Select image')).'</button><p class="description">'.esc_html($multiple?sprintf(self::t('حتى %d صورة. يمكنك ترتيبها بالسحب أو بأزرار الترتيب.','Up to %d images. Reorder by dragging or using the order buttons.'),$limit):self::t('اختر صورة من مكتبة الوسائط. إزالتها هنا لا تحذف الملف من المكتبة.','Choose an image from the media library. Removing it here does not delete its file.')).'</p></section>';
	}
	private static function editor(int $id):void{
		$post=$id?get_post($id):null;
		if($id&&(!$post||$post->post_type!=='car'||!current_user_can('edit_post',$id))){wp_die(esc_html(self::t('لا تملك صلاحية تعديل هذه السيارة.','You cannot edit this vehicle.')));}
		if(!$id&&!current_user_can(get_post_type_object('car')->cap->create_posts)){wp_die(esc_html(self::t('لا تملك صلاحية الإنشاء.','You cannot create pages.')));}
		self::start($id?self::t('تعديل صفحة السيارة','Edit vehicle page'):self::t('إعداد صفحة سيارة جديد','Prepare a new model page'));
		if($post&&$post->post_status==='trash'){self::status_form($id,'restore');echo '</div>';return;}
		echo '<p><a href="'.esc_url(self::url()).'">← '.esc_html(self::t('العودة إلى السيارات','Back to vehicles')).'</a></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="adc-publishing-form"><input type="hidden" name="action" value="adc_catalog_save_car"><input type="hidden" name="post_id" value="'.$id.'"><input type="hidden" name="revision" value="'.esc_attr($id?Service::fingerprint($id):'').'">';wp_nonce_field('adc_catalog_save_car');
		echo '<div class="adc-editor-grid"><main><section class="adc-panel"><h2>'.esc_html(self::t('بيانات صفحة السيارة','Vehicle page information')).'</h2>';
		if(!$id){global $wpdb;$models=$wpdb->get_results('SELECT id,brand,model FROM '.Schema::table('catalog_models')." WHERE public_post_id IS NULL OR NOT EXISTS(SELECT 1 FROM {$wpdb->posts} p WHERE p.ID=public_post_id AND p.post_type='car') ORDER BY brand,model",ARRAY_A);echo '<label class="adc-field">'.esc_html(self::t('الطراز من مخزون أونكس','Model from Onyx inventory')).'<select name="model_id" required data-model-select><option value="">'.esc_html(self::t('اختر الطراز','Select a model')).'</option>';foreach($models?:array()as$model){echo '<option value="'.(int)$model['id'].'">'.esc_html(\AutoDealership\Inventory\OnyxCatalogNames::for_model((int)$model['id'])).'</option>';}echo '</select></label><p class="description">'.esc_html(self::t('الطراز الذي لديه صفحة بالفعل يظهر في قائمة السيارات. استعد الصفحة من السلة عند الحاجة.','Models with existing pages are in the vehicles list. Restore their page from trash when needed.')).'</p>';}
		$source_name=$id?\AutoDealership\Inventory\OnyxCatalogNames::for_post($id):'';
		self::field('title',self::t('اسم السيارة المنشور','Published vehicle name'),$source_name?:($post?$post->post_title:''),'text',(bool)$source_name||!$id);
		if($source_name||!$id){echo '<p class="description">'.esc_html(self::t('الاسم يتكون تلقائيًا من الماركة والطراز والفئة وسنة الموديل في أونكس.','The name follows Onyx brand, model, trim and model year automatically.')).'</p>';}
		echo '<label class="adc-field">'.esc_html(self::t('وصف مختصر','Short summary')).'<textarea name="summary" rows="3">'.esc_textarea($post?$post->post_excerpt:'').'</textarea></label><label for="adc_vehicle_description">'.esc_html(self::t('وصف السيارة (اختياري)','Vehicle description (optional)')).'</label>';
		wp_editor($post?$post->post_content:'','adc_vehicle_description',array('textarea_name'=>'description','textarea_rows'=>8,'media_buttons'=>false,'teeny'=>true,'quicktags'=>false));
		echo '<label class="adc-field">'.esc_html(self::t('المزايا — سطر لكل ميزة','Features — one per line')).'<textarea name="features" rows="5">'.esc_textarea($id?(is_array(get_post_meta($id,'_car_features',true))?implode("\n",get_post_meta($id,'_car_features',true)):get_post_meta($id,'_car_features',true)):'').'</textarea></label></section>';
		$color_editor=$id&&GroupedModelCatalog::is_mapped($id);
		echo '<section class="adc-panel"><h2>'.esc_html($color_editor?self::t('صور السيارة حسب اللون','Vehicle photos by color'):self::t('صور السيارة','Vehicle photos')).'</h2>';
		if($color_editor){
			$colors=array();foreach(\AutoDealership\Inventory\VehicleColors::source_names($id)as$raw){$key=\AutoDealership\Inventory\VehicleColors::gallery_key($raw);if(!isset($colors[$key])){$colors[$key]=$raw;}}
			$default=(string)get_post_meta($id,\AutoDealership\Inventory\VehicleColors::DEFAULT_META,true);
			echo '<input type="hidden" name="color_galleries_present" value="1"><input type="hidden" name="color_primary_present" value="1"><label class="adc-field">'.esc_html(self::t('لون العرض الافتراضي','Default display color')).'<select name="default_color"><option value="">'.esc_html(self::t('تلقائي — أول لون متاح لديه صور','Automatic — first available color with photos')).'</option>';
			foreach($colors as$key=>$raw){echo '<option value="'.esc_attr($key).'" '.selected($default,$key,false).'>'.esc_html($raw).'</option>';}
			echo '</select></label><p class="description">'.esc_html(self::t('تُستخدم الصورة الرئيسية لهذا اللون في كروت السيارات وعند فتح الصفحة. إذا لم يعد متاحًا أو لم توجد له صور، يظهر أول لون متاح لديه صور.','This color’s main image appears on vehicle cards and when opening its page. If unavailable or missing photos, the first available color with photos is used.')).'</p><p><a href="'.esc_url(admin_url('admin.php?page=adc-vehicle-colors')).'">'.esc_html(self::t('إدارة الألوان والمطابقة مع أونكس','Manage colors and Onyx mappings')).'</a></p><div class="adc-color-media-grid">';
			foreach($colors as$key=>$raw){
				$images=\AutoDealership\Inventory\VehicleColors::gallery_ids($id,$raw);$main=(int)($images[0]??0);$extras=array_slice($images,1);
				echo '<article class="adc-color-media-card" data-color-media-card><header><h3>'.\AutoDealership\Inventory\VehicleColors::swatch($raw).' '.esc_html($raw).'</h3><span class="adc-color-readiness" data-color-readiness role="status">'.esc_html($main?self::t('الصورة الرئيسية محددة','Main image ready'):self::t('أضف صورة رئيسية','Add a main image')).'</span></header><div data-color-main>';
				self::media('color_main['.$key.']',array($main),self::t('الصورة الرئيسية للون','Main color image'));
				echo '</div><div data-color-extras>';self::media('color_galleries['.$key.']',$extras,self::t('صور إضافية','Additional photos'),true,19);
				echo '</div><p class="description" data-color-count>'.esc_html(sprintf(self::t('صور إضافية: %d','Additional photos: %d'),count($extras))).'</p></article>';
			}
			echo '</div>';
		}else{
			self::media('thumbnail_id',array($id?get_post_thumbnail_id($id):0),self::t('الصورة الرئيسية','Main image'));
			$gallery=$id?get_post_meta($id,'_car_gallery_ids',true):array();self::media('gallery_ids',is_array($gallery)?$gallery:array(),self::t('معرض الصور','Photo gallery'),true);
		}
		echo '</section>';
		if($id&&GroupedModelCatalog::is_mapped($id)){echo '<section class="adc-panel"><h2>'.esc_html(self::t('المواصفات والأسعار من أونكس','Specifications and prices from Onyx')).'</h2>';GroupedModelCatalog::render_variants($id);echo '</section>';}elseif($id){echo '<section class="adc-panel"><p>'.esc_html(self::t('هذه صفحة سابقة؛ تحفظ بياناتها الحالية وروابطها عند تعديل المحتوى والصور.','This is an existing page; content and photo changes preserve its current data and URLs.')).'</p></section>';}
		echo '</main><aside><section class="adc-panel"><h2>'.esc_html(self::t('النشر','Publication')).'</h2><label class="adc-field">'.esc_html(self::t('حالة النشر','Publication status')).'<select name="status">';foreach(self::statuses()as$key=>$label){if($key==='trash'||($key==='future'&&(!$post||$post->post_status!=='future'))||(!current_user_can('publish_cars')&&in_array($key,array('publish','private','future'),true))){continue;}echo '<option value="'.esc_attr($key).'" '.selected($post?$post->post_status:'draft',$key,false).'>'.esc_html($label).'</option>';}echo '</select></label><p class="description">'.esc_html(($color_editor?self::t('الوصف اختياري. أضف الصور من بطاقات الألوان، واختر «منشورة» لإظهار السيارة في الموقع.','Description is optional. Add photos through the color cards, and choose Published to show the vehicle on the website.') : self::t('الوصف والصورة اختياريان. تظهر الصورة الافتراضية عند عدم اختيار صورة. اختر «منشورة» لإظهار السيارة في الموقع.','Description and photo are optional. The default image appears when no photo is selected. Choose Published to show the vehicle on the website.'))).'</p><button class="button button-primary button-large">'.esc_html(self::t('حفظ صفحة السيارة','Save vehicle page')).'</button>';if($post&&$post->post_status==='publish'){echo '<p><a href="'.esc_url(get_permalink($id)).'">'.esc_html(self::t('عرض الصفحة المنشورة','View published page')).'</a></p>';}echo '</section><section class="adc-panel"><h2>'.esc_html(self::t('الضريبة واللوحات','VAT and plates')).'</h2><p>'.esc_html(sprintf(self::t('ضريبة السيارة الحالية: %s%%','Current vehicle VAT: %s%%'),\AutoDealership\Pricing\Money::decimal(\AutoDealership\Pricing\PublicVehiclePrice::rate()))).'</p>';
		$plate=$id?get_post_meta($id,'_car_plate_type',true):'';foreach(array('private_transport'=>self::t('نقل خصوصي — 900 ريال','Private transport — SAR 900'),'commercial_transport'=>self::t('نقل تجاري — 1,200 ريال','Commercial transport — SAR 1,200'))as$key=>$label){echo '<p><label><input type="checkbox" name="_car_plate_types[]" value="'.esc_attr($key).'" '.checked($plate,$key,false).'> '.esc_html($label).'</label></p>';}echo '<p class="description">'.esc_html(self::t('اختر نوعًا واحدًا. رسوم اللوحات تضاف بعد ضريبة السيارة.','Choose one type. Plate fees are added after vehicle VAT.')).'</p></section><section class="adc-panel"><h2>'.esc_html(self::t('التصنيف والظهور','Classification and visibility')).'</h2>';
		foreach(array('car_brand'=>self::t('الماركة','Brand'),'car_category'=>self::t('تصنيف السيارة','Vehicle category'))as$taxonomy=>$label){if(!current_user_can(get_taxonomy($taxonomy)->cap->assign_terms)){continue;}$terms=get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>false));$selected=$id?wp_get_object_terms($id,$taxonomy,array('fields'=>'ids')):array();echo '<input type="hidden" name="'.$taxonomy.'_present" value="1"><fieldset class="adc-term-options"><legend>'.esc_html($label).'</legend>';foreach(is_wp_error($terms)?array():$terms as$term){echo '<label><input type="checkbox" name="'.$taxonomy.'[]" value="'.$term->term_id.'" '.checked(in_array($term->term_id,$selected,true),true,false).'> '.esc_html($term->name).'</label>';}echo '</fieldset>';}
		echo '<p><label><input type="checkbox" name="featured" value="1" '.checked($id?get_post_meta($id,'_car_featured',true):'','1',false).'> '.esc_html(self::t('سيارة مميزة','Featured vehicle')).'</label></p><p><label><input type="checkbox" name="demand" value="1" '.checked($id?get_post_meta($id,'_car_demand',true):'','yes',false).'> '.esc_html(self::t('سيارة مطلوبة','Popular vehicle')).'</label></p></section></aside></div></form></div>';
	}
	public static function terms():void{
		$taxonomy=($_GET['page']??'')==='adc-car-categories'?'car_category':'car_brand';
		if(!current_user_can(get_taxonomy($taxonomy)->cap->manage_terms)){wp_die(esc_html(self::t('لا تملك صلاحية الوصول.','Access denied.')));}
		$id=absint($_GET['term_id']??0);$term=$id?get_term($id,$taxonomy,OBJECT,'raw'):null;if($id&&(!$term||is_wp_error($term))){wp_die(esc_html(self::t('التصنيف غير موجود.','Classification not found.')));}
		self::start($taxonomy==='car_brand'?self::t('إدارة الماركات','Manage brands'):self::t('إدارة تصنيفات السيارات','Manage vehicle categories'));
		echo '<div class="adc-terms-grid"><section class="adc-panel"><h2>'.esc_html($id?self::t('تعديل البيانات','Edit details'):self::t('إضافة جديد','Add new')).'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_catalog_save_term"><input type="hidden" name="taxonomy" value="'.$taxonomy.'"><input type="hidden" name="term_id" value="'.$id.'"><input type="hidden" name="revision" value="'.esc_attr($id?Service::term_fingerprint($id,$taxonomy):'').'">';wp_nonce_field('adc_catalog_save_term');
		self::field('name',self::t('الاسم','Name'),$term?$term->name:'');self::field('slug',self::t('اسم الرابط','URL slug'),$term?$term->slug:'');self::field('name_ar',self::t('الاسم بالعربية','Arabic name'),$id?get_term_meta($id,'_adc_name_ar',true):'');self::field('name_en',self::t('الاسم بالإنجليزية','English name'),$id?get_term_meta($id,'_adc_name_en',true):'');
		echo '<label class="adc-field">'.esc_html(self::t('الوصف','Description')).'<textarea name="description" rows="4">'.esc_textarea($term?$term->description:'').'</textarea></label>';
		if($taxonomy==='car_category'){echo '<label class="adc-field">'.esc_html(self::t('التصنيف الرئيسي','Parent category')).'<select name="parent"><option value="0">'.esc_html(self::t('بدون تصنيف رئيسي','No parent category')).'</option>';foreach(get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>false))as$parent){if($parent->term_id===$id||in_array($id,get_ancestors($parent->term_id,$taxonomy),true)){continue;}echo '<option value="'.$parent->term_id.'" '.selected($term?$term->parent:0,$parent->term_id,false).'>'.esc_html($parent->name).'</option>';}echo '</select></label>';}
		self::media('image_id',array($id?(int)get_term_meta($id,'_adc_catalog_image_id',true):0),$taxonomy==='car_brand'?self::t('شعار الماركة','Brand logo'):self::t('صورة التصنيف','Category image'));
		echo '<button class="button button-primary">'.esc_html(self::t('حفظ البيانات','Save details')).'</button> <a class="button" href="'.esc_url(self::term_url($taxonomy)).'">'.esc_html(self::t('إضافة جديد','Add new')).'</a></form></section><section class="adc-panel"><h2>'.esc_html(self::t('القائمة','List')).'</h2>';
		$search=sanitize_text_field(wp_unslash($_GET['search']??''));$page=max(1,absint($_GET['paged']??1));$args=array('taxonomy'=>$taxonomy,'hide_empty'=>false,'search'=>$search);$total=wp_count_terms($args);$terms=get_terms($args+array('number'=>25,'offset'=>($page-1)*25));echo '<form method="get" class="adc-publishing-filters"><input type="hidden" name="page" value="'.esc_attr($_GET['page']??'adc-car-brands').'"><label>'.esc_html(self::t('البحث','Search')).'<input name="search" value="'.esc_attr($search).'"></label><button class="button">'.esc_html(self::t('بحث','Search')).'</button></form><div class="adc-table-scroll"><table class="widefat striped"><thead><tr><th>'.esc_html(self::t('الاسم','Name')).'</th><th>'.esc_html(self::t('اسم الرابط','URL slug')).'</th><th>'.esc_html(self::t('صفحات السيارات','Vehicle pages')).'</th><th>'.esc_html(self::t('الإجراءات','Actions')).'</th></tr></thead><tbody>';
		if(is_wp_error($terms)){echo '<tr><td colspan="4">'.esc_html(self::t('تعذر تحميل القائمة.','The list could not be loaded.')).'</td></tr>';}else{foreach($terms as$item){echo '<tr><td>'.wp_get_attachment_image((int)get_term_meta($item->term_id,'_adc_catalog_image_id',true),array(48,48)).' '.esc_html($item->name).'</td><td>'.esc_html($item->slug).'</td><td>'.(int)$item->count.'</td><td><a class="button" href="'.esc_url(self::term_url($taxonomy,$item->term_id)).'">'.esc_html(self::t('تعديل','Edit')).'</a></td></tr>';}if(!$terms){echo '<tr><td colspan="4">'.esc_html(self::t('لا توجد نتائج.','No results.')).'</td></tr>';}}
		echo '</tbody></table></div>';if(!is_wp_error($total)){self::pagination((int)ceil($total/25),$page);}echo '</section></div></div>';
	}
}
