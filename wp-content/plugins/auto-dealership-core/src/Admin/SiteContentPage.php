<?php
namespace AutoDealership\Admin;
use AutoDealership\Content\SiteContent as Content;
use AutoDealership\Content\ContentTransfer;
use AutoDealership\Core\ProductSetup;
defined('ABSPATH') || exit;

final class SiteContentPage {
	public static function boot(): void {
		add_action('admin_post_adc_site_content',[self::class,'submit']);
		add_action('rest_api_init',static function(): void { register_rest_route('auto-dealership/v1','/content-preview',['methods'=>'POST','permission_callback'=>static fn()=>current_user_can('manage_options'),'callback'=>[self::class,'preview']]); });
		add_action('rest_api_init',static function(): void { register_rest_route('auto-dealership/v1','/site-content',['methods'=>'POST','permission_callback'=>static fn()=>current_user_can('manage_options'),'callback'=>[self::class,'save_json']]); });
	}
	public static function url(string $tab='readiness'): string { return add_query_arg(['page'=>'adc-site-content','tab'=>$tab],admin_url('admin.php')); }
	private static function tabs(): array { return ['readiness'=>['جاهزية المحتوى','Content readiness'],'home'=>['الصفحة الرئيسية','Homepage'],'team'=>['فريق العمل','Team'],'reviews'=>['آراء العملاء','Customer reviews'],'metrics'=>['الإحصاءات','Metrics'],'transfer'=>['نقل المحتوى','Transfer content']]; }
	public static function preview(\WP_REST_Request $request) {
		if(!current_user_can('manage_options')) { return new \WP_Error('adc_content_forbidden','Forbidden',['status'=>403]); }
		$data=$request->get_json_params(); $clean=self::merge_input(is_array($data)?$data:[]); if(is_wp_error($clean)) { return $clean; }
		if(in_array($data['tab']??'',Content::COLLECTIONS,true)) { $group=$data['tab']; if(isset($clean['home'][$group])) { $clean['home'][$group]['status']='published'; } foreach($clean[$group] as &$row) { if('draft'===$row['status']) { $row['status']='published'; } } unset($row); }
		$token=bin2hex(random_bytes(16)); set_transient('adc_content_preview_'.$token,['user'=>get_current_user_id(),'data'=>$clean],10*MINUTE_IN_SECONDS);
		$preview_url=home_url('/'); if('team'===($data['tab']??'')) { $about=get_page_by_path('about'); if($about && 'publish'===$about->post_status) { $preview_url=get_permalink($about); } }
		return ['url'=>add_query_arg(['adc_content_preview'=>$token,'lang'=>in_array($data['lang']??'', ['ar','en'],true)?$data['lang']:'ar'],$preview_url)];
	}
	private static function merge_input(array $input) {
		$tab=$input['tab']??''; if(!in_array($tab,array_merge(['home'],Content::COLLECTIONS),true)) { return new \WP_Error('adc_content_tab','Invalid content tab'); }
		$data=Content::data();
		if('home'===$tab) { $data['home']=$input['home']??[]; $data['hero_image']=$input['hero_image']??0; }
		else { $data[$tab]=$input['rows']??[]; }
		return Content::normalize($data);
	}
	/** JSON avoids PHP max_input_vars truncating large collections on shared hosting. */
	public static function save_json(\WP_REST_Request $request) {
		if(!current_user_can('manage_options')) { return new \WP_Error('adc_content_forbidden','Forbidden',['status'=>403]); }
		$input=$request->get_json_params();if(!is_array($input) || !is_string($input['revision']??null)){return new \WP_Error('adc_content_input','Invalid content request',['status'=>400]);}
		$clean=self::merge_input($input);$result=is_wp_error($clean)?$clean:Content::save($clean,$input['revision']);if(is_wp_error($result)){return $result;}
		set_transient('adc_content_notice_'.get_current_user_id(),['ok'=>true,'text'=>Content::t('تم حفظ المحتوى وتحديث الموقع.','Content saved and site refreshed.')],60);
		return ['url'=>self::url($input['tab'])];
	}
	public static function submit(): void {
		if(!current_user_can('manage_options')) { wp_die('Forbidden','',['response'=>403]); } check_admin_referer('adc_site_content');
		$input=wp_unslash($_POST); $tab=is_string($input['tab']??null)?$input['tab']:'readiness'; $op=$input['operation']??'save';
		if('export'===$op) { ContentTransfer::download(); }
		if('import_preview'===$op) { $result=ContentTransfer::stage($_FILES['content_file']??[]); if(!is_wp_error($result)) { wp_safe_redirect(add_query_arg('import_preview',1,self::url('transfer'))); exit; } }
		elseif('import'===$op) { $result=ContentTransfer::apply((string)($input['preview_hash']??''),(string)($input['revision']??'')); }
		else { $clean=('1'===($input['adc_content_complete']??''))?self::merge_input($input):new \WP_Error('adc_content_incomplete',Content::t('لم تصل جميع الحقول؛ لم يتغير المحتوى. فعّل JavaScript أو قلّل عدد العناصر.','Not all fields arrived; content was preserved. Enable JavaScript or reduce the number of entries.')); $result=is_wp_error($clean)?$clean:Content::save($clean,(string)($input['revision']??'')); }
		set_transient('adc_content_notice_'.get_current_user_id(),['ok'=>!is_wp_error($result),'text'=>is_wp_error($result)?$result->get_error_message():Content::t('تم حفظ المحتوى وتحديث الموقع.','Content saved and site refreshed.')],60);
		// Keep submitted values privately for correcting validation failures, including unsaved entries.
		if(is_wp_error($result) && 'save'===$op) { set_transient('adc_content_input_'.get_current_user_id(),$input,10*MINUTE_IN_SECONDS); }
		wp_safe_redirect(self::url(isset(self::tabs()[$tab])?$tab:'readiness')); exit;
	}
	private static function form(string $tab,string $op='save',bool $upload=false): void {
		echo '<form method="post" class="adc-content-form" action="'.esc_url(admin_url('admin-post.php')).'"'.($upload?' enctype="multipart/form-data"':'').'><input type="hidden" name="action" value="adc_site_content"><input type="hidden" name="tab" value="'.esc_attr($tab).'"><input type="hidden" name="operation" value="'.esc_attr($op).'"><input type="hidden" name="revision" value="'.esc_attr(Content::revision()).'">'; wp_nonce_field('adc_site_content');
	}
	private static function field(string $name,string $label,$value='',string $type='text',array $options=[]): void {
		echo '<label class="adc-content-field"><span>'.esc_html($label).'</span>';
		if($options) { echo '<select name="'.esc_attr($name).'">'; foreach($options as $key=>$text) { echo '<option value="'.esc_attr($key).'" '.selected((string)$value,(string)$key,false).'>'.esc_html($text).'</option>'; } echo '</select>'; }
		elseif('textarea'===$type) { echo '<textarea rows="3" name="'.esc_attr($name).'"'.(str_contains($name,'_en]')?' lang="en" dir="ltr"':'').'>'.esc_textarea($value).'</textarea>'; }
		else { echo '<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($value).'"'.(str_contains($name,'_en]')?' lang="en" dir="ltr"':'').'>'; }
		echo '</label>';
	}
	private static function status(): array { return ['draft'=>Content::t('مسودة','Draft'),'published'=>Content::t('منشور','Published'),'hidden'=>Content::t('مخفي','Hidden')]; }
	private static function media(string $name,int $id): void {
		echo '<div class="adc-content-media"><input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr($id).'"><div data-image-preview>'.($id?wp_get_attachment_image($id,'thumbnail'):'').'</div><button type="button" class="button" data-media-pick>'.esc_html(Content::t('اختيار صورة','Choose image')).'</button> <button type="button" class="button-link-delete" data-media-clear>'.esc_html(Content::t('إزالة الصورة','Remove image')).'</button></div>';
	}
	private static function row(string $group,array $r,string $index): void {
		$prefix='rows['.$index.']'; echo '<fieldset class="adc-content-row"><legend>'.esc_html(Content::t('عنصر المحتوى','Content entry')).'</legend><input type="hidden" name="'.esc_attr($prefix.'[id]').'" value="'.esc_attr($r['id']??'').'"><div class="adc-content-fields">';
		self::field($prefix.'[status]',Content::t('حالة النشر','Publication status'),$r['status']??'draft','text',self::status());
		self::field($prefix.'[order]',Content::t('الترتيب','Order'),$r['order']??0,'number');
		if('metrics'===$group) {
			self::field($prefix.'[source]',Content::t('مصدر الرقم','Value source'),$r['source']??'manual','text',['manual'=>Content::t('إدخال يدوي موثق','Documented manual value'),'published_vehicles'=>Content::t('السيارات المنشورة من النظام','Published vehicles from system'),'available_stock'=>Content::t('المخزون المتاح من النظام','Available stock from system')]); self::field($prefix.'[value]',Content::t('القيمة اليدوية','Manual value'),$r['value']??'','text');
		}
		foreach(['ar'=>Content::t('العربية','Arabic'),'en'=>'English'] as $lang=>$label) {
			echo '<div class="adc-content-language"><h3>'.esc_html($label).'</h3>';
			$fields='metrics'===$group?['title'=>['عنوان الإحصاء','Metric title'],'unit'=>['الوحدة (اختياري)','Unit (optional)'],'text'=>['مصدر الرقم اليدوي','Manual value evidence/source']]:['name'=>['اسم العرض','Display name']];
			if('team'===$group) { $fields['role']=['المنصب','Role']; $fields['text']=['نبذة','Biography']; } elseif('reviews'===$group) { $fields['text']=['نص الرأي','Review text']; }
			foreach($fields as $key=>$text) { self::field($prefix.'['.$key.'_'.$lang.']',Content::t(...$text),$r[$key.'_'.$lang]??'','text'===$key?'textarea':'text'); }
			$verified=(string)($r['verified_'.$lang]??''); $valid=$verified && hash_equals(Content::entry_hash($r,$group,$lang),$verified);
			echo '<label><input type="checkbox" name="'.esc_attr($prefix.'[verified_'.$lang.']').'" value="1" '.checked($valid,true,false).'> '.esc_html(Content::t('راجعت محتوى هذه اللغة وأعتمد نشره','I reviewed and approve this language')).'</label></div>';
		}
		if('team'===$group) { self::media($prefix.'[image]',(int)($r['image']??0)); }
		if('reviews'===$group) {
			self::field($prefix.'[rating]',Content::t('التقييم؛ 0 دون تقييم','Rating; 0 for no rating'),$r['rating']??0,'text',['0'=>Content::t('دون تقييم','No rating'),'1'=>'1 / 5','2'=>'2 / 5','3'=>'3 / 5','4'=>'4 / 5','5'=>'5 / 5']); self::field($prefix.'[source]',Content::t('مصدر الرأي (اختياري)','Review source (optional)'),$r['source']??'');
			echo '<label><input type="checkbox" name="'.esc_attr($prefix.'[consent]').'" value="1" '.checked(!empty($r['consent']),true,false).'> '.esc_html(Content::t('لدي موافقة صاحب الرأي على نشر اسمه والنص','I have permission to publish this display name and review')).'</label>';
		}
		if(in_array($group,['metrics','reviews'],true)) { self::field($prefix.'[reviewed_at]',Content::t('تاريخ المراجعة','Review date'),$r['reviewed_at']??'','date'); }
		echo '</div><p><button type="button" class="button" data-row-up>'.esc_html(Content::t('لأعلى','Move up')).'</button> <button type="button" class="button" data-row-down>'.esc_html(Content::t('لأسفل','Move down')).'</button> <button type="button" class="button-link-delete" data-row-remove>'.esc_html(Content::t('حذف العنصر عند الحفظ','Remove entry on save')).'</button></p></fieldset>';
	}
	public static function readiness(): array {
		$data=Content::data(); $rows=[];
		$add=static function($name,$ready,$detail,$url)use(&$rows){$rows[]=['name'=>$name,'status'=>$ready?Content::t('جاهز','Ready'):Content::t('يحتاج بيانات أو مراجعة','Needs data or review'),'detail'=>$detail,'url'=>$url];};
		$add(Content::t('شعار الموقع','Site logo'),has_custom_logo(),Content::t('يظهر اسم المنشأة عند غياب الصورة.','Business name is used when no image is set.'),admin_url('customize.php'));
		$settings=(array)get_option('car_dealer_theme_settings',[]);
		foreach(['phone'=>['الهاتف','Phone'],'email'=>['البريد','Email'],'whatsapp'=>['واتساب','WhatsApp'],'address'=>['العنوان العربي','Arabic address'],'address_en'=>['العنوان الإنجليزي','English address'],'facebook'=>['فيسبوك','Facebook'],'instagram'=>['إنستغرام','Instagram']] as $key=>$label) { $add(Content::t(...$label),!empty($settings[$key]),Content::t('حقل اختياري؛ غيابه لا يمنع تشغيل الموقع.','Optional; absence does not prevent site operation.'),admin_url('customize.php')); }
		$add(Content::t('الفروع والخرائط','Branches and maps'),(bool)array_filter(\AutoDealership\Core\BranchLocations::all(),static fn($r)=>!empty($r['active'])),Content::t('تظهر الفروع المفعلة فقط.','Only enabled branches appear.'),admin_url('options-general.php?page=adc-branch-locations'));
		$home=\AutoDealership\Content\HomePageView::view();
		foreach(['featured_ids'=>['السيارات المميزة','Featured vehicles'],'latest_ids'=>['السيارات المنشورة','Published vehicles'],'demand_ids'=>['السيارات المختارة','Selected vehicles'],'offer_ids'=>['العروض الصالحة','Valid offers']] as $key=>$label) { $add(Content::t(...$label),(bool)$home[$key],Content::t('يشترط النشر والتوفر، وتحديد النوع عند الحاجة.','Requires publication, availability and appropriate selection.'),admin_url('edit.php?post_type='.('offer_ids'===$key?'car_offer':'car'))); }
		foreach(Content::COLLECTIONS as $group) { foreach(['ar','en'] as $lang) { $add(Content::t(...self::tabs()[$group]).' · '.$lang,(bool)Content::rows($group,$lang),sprintf(Content::t('%d عناصر محفوظة؛ تُعرض العناصر المنشورة والمراجعة فقط.','%d stored entries; only reviewed published entries appear.'),count($data[$group])),self::url($group)); } }
		foreach($data['home'] as $key=>$r) { $rows[]=['name'=>Content::t('الرئيسية: ','Homepage: ').Content::t(...Content::home_definitions()[$key]),'status'=>self::status()[$r['status']],'detail'=>Content::t('التفعيل لا يغني عن استكمال بيانات القسم.','Enabling a section still requires its content.'),'url'=>self::url('home')]; }
		foreach(\AutoDealership\Core\ProductSetup::blueprints() as $blueprint) { $page=get_page_by_path($blueprint['slug']); if($page && 'publish'===$page->post_status) { $add($page->post_title.' · English',''!==\AutoDealership\Content\PublicEditorialTranslations::approved($page->ID,'_adc_content_en'),Content::t('راجع الترجمة بعد التعديل على النص الأصلي.','Review translation after editing the original.'),get_edit_post_link($page->ID,'raw')); } }
		return $rows;
	}
	public static function render(): void {
		if(!current_user_can('manage_options')) { wp_die('Forbidden'); }
		$tab=isset($_GET['tab']) && is_string($_GET['tab']) && isset(self::tabs()[$_GET['tab']])?$_GET['tab']:'readiness'; $data=Content::data();
		$pending=get_transient('adc_content_input_'.get_current_user_id()); delete_transient('adc_content_input_'.get_current_user_id()); if(is_array($pending) && ($pending['tab']??'')===$tab) { if('home'===$tab){$data['home']=$pending['home']??$data['home'];$data['hero_image']=absint($pending['hero_image']??0);}elseif(in_array($tab,Content::COLLECTIONS,true)){$data[$tab]=$pending['rows']??[];} }
		wp_enqueue_media(); wp_enqueue_style('adc-site-content-admin',plugins_url('assets/css/site-content-admin.css',ADC_FILE),[],ADC_VERSION); wp_enqueue_script('adc-site-content-admin',plugins_url('assets/js/site-content-admin.js',ADC_FILE),[],ADC_VERSION,true);
		wp_localize_script('adc-site-content-admin','adcSiteContent',['preview'=>rest_url('auto-dealership/v1/content-preview'),'save'=>rest_url('auto-dealership/v1/site-content'),'nonce'=>wp_create_nonce('wp_rest'),'ar'=>'ar'===\AutoDealership\Core\Localization::language()]);
		echo '<div class="wrap adc-content-admin"><h1>'.esc_html(Content::t('محتوى الموقع','Website content')).'</h1><p>'.esc_html(Content::t('تحكم في محتوى الأقسام وراجع جاهزيتها. إخفاء القسم يحتفظ ببياناته.','Manage section content and readiness. Hiding a section preserves its data.')).'</p>';
		$notice=get_transient('adc_content_notice_'.get_current_user_id()); delete_transient('adc_content_notice_'.get_current_user_id()); if(is_array($notice)) { echo '<div class="notice '.($notice['ok']?'notice-success':'notice-error').' is-dismissible"><p>'.esc_html($notice['text']).'</p></div>'; }
		echo '<nav class="nav-tab-wrapper">'; foreach(self::tabs() as $key=>$label){echo '<a class="nav-tab '.($key===$tab?'nav-tab-active':'').'" href="'.esc_url(self::url($key)).'">'.esc_html(Content::t(...$label)).'</a>';} echo '</nav>';
		if('readiness'===$tab) { echo '<p><a class="button" href="'.esc_url(ProductSetup::url()).'">'.esc_html(Content::t('إعداد الهوية والسياسات والبريد','Identity, policy and mail settings')).'</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=page')).'">'.esc_html(Content::t('تحرير الصفحات','Edit pages')).'</a></p><table class="widefat striped"><thead><tr>'; foreach([['العنصر','Item'],['الحالة','Status'],['التفاصيل','Details'],['الإدارة','Manage']] as $label){echo '<th>'.esc_html(Content::t(...$label)).'</th>';} echo '</tr></thead><tbody>'; foreach(self::readiness() as $r){echo '<tr><td>'.esc_html($r['name']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html($r['detail']).'</td><td><a href="'.esc_url($r['url']).'">'.esc_html(Content::t('إدارة','Manage')).'</a></td></tr>';} echo '</tbody></table>'; }
		elseif('transfer'===$tab) { ContentTransfer::screen(); }
		else {
			self::form($tab);
			if('home'===$tab) {
				echo '<p>'.esc_html(Content::t('استخدم {site_name} لإدراج اسم المنشأة حسب لغة النص، و{legal_name} للاسم القانوني. تبقى النصوص المخصصة كما كتبتها.','Use {site_name} for the business name in the text language, and {legal_name} for the legal name. Custom copy remains as entered.')).'</p>';
				echo '<h2>'.esc_html(Content::t('صورة المقدمة المستقلة','Independent hero image')).'</h2>';self::media('hero_image',(int)$data['hero_image']); echo '<p>'.esc_html(Content::t('عند عدم اختيار صورة تُستخدم صورة أول سيارة مميزة. المقدمة تبقى أول قسم للحفاظ على عنوان الصفحة.','Without an image, the first featured vehicle image is used. The hero stays first to preserve the page heading.')).'</p>';
				foreach($data['home'] as $key=>$r) { echo '<fieldset class="adc-content-row"><legend>'.esc_html(Content::t(...Content::home_definitions()[$key])).'</legend><div class="adc-content-fields">';self::field('home['.$key.'][status]',Content::t('الحالة','Status'),$r['status'],'text',self::status());self::field('home['.$key.'][height]',Content::t('ارتفاع القسم','Section height'),$r['height']??'auto','text',array_merge(['auto'=>Content::t('تلقائي حسب المحتوى','Automatic, content based'),'balanced'=>Content::t('متوازن','Balanced')],in_array($key,['hero','why','cta'],true)?['screen'=>Content::t('مساحة الشاشة','Screen height')]:[]));self::field('home['.$key.'][spacing]',Content::t('المسافات','Spacing'),$r['spacing']??'normal','text',['compact'=>Content::t('مدمجة','Compact'),'normal'=>Content::t('عادية','Normal'),'spacious'=>Content::t('واسعة','Spacious')]);if('hero'!==$key){self::field('home['.$key.'][order]',Content::t('الترتيب','Order'),$r['order'],'number');}else{echo '<input type="hidden" name="home[hero][order]" value="0">';}if(!in_array($key,['hero','why','cta'],true)){self::field('home['.$key.'][count]',Content::t('عدد العناصر (1–48)','Item count (1–48)'),$r['count'],'number');}else{echo '<input type="hidden" name="home['.esc_attr($key).'][count]" value="'.esc_attr($r['count']).'">';}foreach(['ar','en'] as $lang){self::field('home['.$key.'][title_'.$lang.']',Content::t('العنوان ','Title ').$lang,$r['title_'.$lang]);self::field('home['.$key.'][description_'.$lang.']',Content::t('الوصف ','Description ').$lang,$r['description_'.$lang],'textarea');}echo '</div></fieldset>'; }
			} else {
				echo '<p>'.esc_html(Content::t('أضف محتوى حقيقيًا فقط. تعتمد أهلية العرض لكل لغة على اكتمالها ومراجعتها. لتغيير نص معتمد أزل علامة المراجعة أو أعد مراجعته قبل الحفظ.','Add authentic content only. Each language requires complete reviewed copy. After editing approved text, clear its approval or review it again before saving.')).'</p><div data-content-rows>';foreach($data[$tab] as $index=>$r){self::row($tab,$r,(string)$index);}echo '</div><template data-content-template>';self::row($tab,[],'__INDEX__');echo '</template><button type="button" class="button" data-content-add>'.esc_html(Content::t('إضافة عنصر','Add entry')).'</button>';
			}
			echo '<div class="adc-content-toolbar">';submit_button(Content::t('حفظ التغييرات','Save changes'),'primary','submit',false);echo ' <select data-preview-lang aria-label="'.esc_attr(Content::t('لغة المعاينة','Preview language')).'"><option value="ar">العربية</option><option value="en">English</option></select> <button type="button" class="button" data-content-preview>'.esc_html(Content::t('معاينة دون نشر','Preview without publishing')).'</button><p data-preview-status role="status"></p></div><input type="hidden" name="adc_content_complete" value="1"></form>';
		}
		echo '</div>';
	}
}
