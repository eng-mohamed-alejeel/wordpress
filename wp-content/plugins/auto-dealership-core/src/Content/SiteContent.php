<?php
namespace AutoDealership\Content;

use AutoDealership\Core\Localization;
use AutoDealership\Core\ProductSetup;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;

defined('ABSPATH') || exit;

/** Tenant-owned editorial sections. Empty installations contain no endorsements or metrics. */
final class SiteContent {
	public const OPTION = 'adc_site_content';
	public const COLLECTIONS = array('team','reviews','metrics');
	public static function boot(): void {
		foreach (array('adc_team'=>'team','adc_reviews'=>'reviews','adc_metrics'=>'metrics') as $tag=>$group) { add_shortcode($tag, static fn($a=[]) => self::render($group,is_array($a)?$a:[])); }
		add_action('wp_enqueue_scripts', static function(): void { wp_enqueue_style('adc-site-content',plugins_url('assets/css/site-content.css',ADC_FILE),array(),ADC_VERSION); });
		add_action('template_redirect', static function(): void { if(isset($_GET['adc_content_preview']) && current_user_can('manage_options')) { nocache_headers(); header('X-Robots-Tag: noindex, nofollow',true); } });
		add_filter('adc_shortcode_ab_team_section_html',static fn($html) => self::render('team'),50);
		add_filter('adc_shortcode_ab_stats_section_html',static fn($html) => self::render('metrics'),50);
		add_filter('adc_shortcode_ab_testimonials_section_html',static fn($html) => self::render('reviews'),50);
		add_filter('adc_shortcode_car_dealer_testimonials_html',static fn($html,$model,$attrs) => self::render('reviews',$attrs),50,3);
	}
	public static function t(string $ar,string $en): string { return ProductSetup::t($ar,$en); }
	public static function home_definitions(): array {
		return array('hero'=>['المقدمة الرئيسية','Hero'],'featured'=>['السيارات المميزة','Featured vehicles'],'why'=>['لماذا تختارنا','Why choose us'],'latest'=>['أحدث السيارات','Latest vehicles'],'offers'=>['العروض الحالية','Current offers'],'demand'=>['سيارات مختارة','Selected vehicles'],'reviews'=>['آراء العملاء','Customer reviews'],'cta'=>['اعثر على سيارتك القادمة','Find your next car']);
	}
	public static function defaults(): array {
		$home=[]; foreach(self::home_definitions() as $key=>$label) { $home[$key]=array('status'=>'published','order'=>count($home),'height'=>'auto','spacing'=>'normal','count'=>'latest'===$key?6:('offers'===$key?8:(in_array($key,['demand','reviews'],true)?3:6)),'title_ar'=>'','title_en'=>'','description_ar'=>'','description_en'=>''); }
		return array('format'=>1,'home'=>$home,'hero_image'=>0,'team'=>[],'reviews'=>[],'metrics'=>[]);
	}
	public static function data(): array {
		$data=get_option(self::OPTION,[]); $defaults=self::defaults();
		$data=is_array($data)?array_replace($defaults,$data):$defaults;
		$data['home']=array_replace($defaults['home'],array_intersect_key(is_array($data['home'])?$data['home']:[],$defaults['home']));
		foreach($defaults['home'] as $key=>$settings) { $data['home'][$key]=array_replace($settings,is_array($data['home'][$key])?$data['home'][$key]:[]); }
		if(isset($_GET['adc_content_preview']) && is_scalar($_GET['adc_content_preview']) && current_user_can('manage_options')) {
			$token=(string)$_GET['adc_content_preview']; if(preg_match('/\A[a-f0-9]{32}\z/',$token)) { $preview=get_transient('adc_content_preview_'.$token); if(is_array($preview) && (int)$preview['user']===get_current_user_id()) { return $preview['data']; } }
		}
		return $data;
	}
	public static function revision(): string { return hash('sha256',wp_json_encode(get_option(self::OPTION,[]))); }
	private static function fail(string $ar,string $en) { return new \WP_Error('adc_content_input',self::t($ar,$en)); }
	public static function normalize(array $input) {
		if(array_diff(array_keys($input),array_keys(self::defaults()))) { return self::fail('يتضمن الملف حقولًا غير معروفة.','Unknown content fields.'); }
		if(isset($input['format']) && 1!==$input['format']) { return self::fail('صيغة المحتوى غير مدعومة.','Unsupported content format.'); }
		$out=self::defaults();
		foreach(self::COLLECTIONS as $group) {
			$rows=$input[$group]??[]; if(!is_array($rows) || count($rows)>100) { return self::fail('الحد الأقصى 100 عنصر لكل قسم.','Maximum 100 entries per section.'); }
			$seen=[];
			foreach($rows as $row) {
				if(!is_array($row)) { return self::fail('بيانات العنصر غير صالحة.','Invalid entry.'); }
				$fields=['id','status','order','name_ar','name_en','role_ar','role_en','text_ar','text_en','title_ar','title_en','unit_ar','unit_en','value','source','reviewed_at','image','rating','consent','verified_ar','verified_en'];
				if(array_diff(array_keys($row),$fields)) { return self::fail('حقول عنصر غير معروفة.','Unknown entry fields.'); }
				$clean=[]; foreach($fields as $key) { if(isset($row[$key]) && !is_scalar($row[$key])) { return self::fail('حقول نصية غير صالحة.','Invalid text fields.'); } $v=(string)($row[$key]??''); if(strlen($v)>12000) { return self::fail('النص أطول من الحد المسموح.','Text is too long.'); } $clean[$key]=sanitize_textarea_field($v); }
				$clean['id']=$clean['id']?:wp_generate_uuid4(); if(!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$clean['id']) || isset($seen[$clean['id']])) { return self::fail('معرّف العنصر مكرر أو غير صالح.','Invalid or duplicate entry ID.'); } $seen[$clean['id']]=true;
				if(!in_array($clean['status'],['draft','published','hidden'],true)) { return self::fail('حالة النشر غير صالحة.','Invalid publication status.'); }
				$clean['image']=absint($clean['image']); if($clean['image'] && !wp_attachment_is_image($clean['image'])) { return self::fail('اختر صورة صالحة من مكتبة الوسائط.','Choose a valid media library image.'); }
				$clean['order']=max(0,min(1000,(int)$clean['order'])); $clean['consent']=!empty($row['consent']);
				$clean['rating']=max(0,min(5,(int)$clean['rating']));
				if($clean['reviewed_at'] && (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$clean['reviewed_at']) || !checkdate((int)substr($clean['reviewed_at'],5,2),(int)substr($clean['reviewed_at'],8,2),(int)substr($clean['reviewed_at'],0,4)) || $clean['reviewed_at']>current_time('Y-m-d'))) { return self::fail('أدخل تاريخ مراجعة صحيحًا لا يتجاوز اليوم.','Enter a valid review date no later than today.'); }
				if('metrics'===$group && !in_array($clean['source'],['manual','published_vehicles','available_stock'],true)) { return self::fail('مصدر الإحصاء غير صالح.','Invalid metric source.'); }
				if('metrics'===$group && 'manual'===$clean['source'] && ''!==$clean['value'] && !preg_match('/\A\d{1,12}(?:\.\d{1,2})?\z/',$clean['value'])) { return self::fail('قيمة الإحصاء يجب أن تكون رقمًا موجبًا أو صفرًا.','Metric value must be a nonnegative number.'); }
				foreach(['ar','en'] as $lang) {
					$hash=self::entry_hash($clean,$group,$lang); $clean['verified_'.$lang]=('1'===($row['verified_'.$lang]??'') || hash_equals($hash,(string)($row['verified_'.$lang]??'')))?$hash:'';
				}
				if('published'===$clean['status'] && !self::ready($clean,$group,'ar') && !self::ready($clean,$group,'en')) { return self::fail('لا يمكن نشر عنصر دون محتوى مكتمل ومراجع بلغة واحدة على الأقل. تأكد من موافقة صاحب الرأي ومصدر وتاريخ الإحصاء اليدوي.','Publishing requires complete reviewed content in at least one language, review consent and a dated source for manual metrics.'); }
				$out[$group][]=$clean;
			}
		}
		$home=$input['home']??[]; if(is_array($home)) { unset($home['team'],$home['metrics']); } if(!is_array($home) || array_diff(array_keys($home),array_keys($out['home']))) { return self::fail('أقسام الرئيسية غير صالحة.','Invalid home sections.'); }
		foreach($home as $key=>$row) {
			if(!is_array($row) || array_diff(array_keys($row),array_keys($out['home'][$key]))) { return self::fail('إعدادات القسم غير صالحة.','Invalid section settings.'); }
			foreach($row as $field=>$value) { if(!is_scalar($value) || strlen((string)$value)>4000) { return self::fail('حقل رئيسية غير صالح.','Invalid home field.'); } $out['home'][$key][$field]=sanitize_textarea_field((string)$value); }
			if(!in_array($out['home'][$key]['status'],['draft','published','hidden'],true)) { return self::fail('حالة القسم غير صالحة.','Invalid section status.'); }
			if(!in_array($out['home'][$key]['height'],['auto','balanced','screen'],true) || !in_array($out['home'][$key]['spacing'],['compact','normal','spacious'],true) || ('screen'===$out['home'][$key]['height'] && !in_array($key,['hero','why','cta'],true))) { return self::fail('اختر نمط عرض صالحًا لهذا القسم.','Choose a supported layout for this section.'); }
			$out['home'][$key]['count']=max(1,min(48,(int)$out['home'][$key]['count'])); $out['home'][$key]['order']=max(0,min(1000,(int)$out['home'][$key]['order']));
		}
		if(!is_scalar($input['hero_image']??0)) { return self::fail('صورة المقدمة غير صالحة.','Invalid hero image.'); }
		$out['hero_image']=absint($input['hero_image']??0); if($out['hero_image'] && !wp_attachment_is_image($out['hero_image'])) { return self::fail('صورة المقدمة غير صالحة.','Invalid hero image.'); }
		return $out;
	}
	public static function entry_hash(array $row,string $group,string $lang): string {
		$keys='team'===$group?['name','role','text']:('reviews'===$group?['name','text']:['title','unit','text']); $values=[]; foreach($keys as $key) { $values[]=$row[$key.'_'.$lang]??''; }
		foreach('metrics'===$group?['source','value','reviewed_at']:('reviews'===$group?['source','rating','consent']:[]) as $key){$values[]=(string)($row[$key]??'');}
		return hash('sha256',wp_json_encode($values));
	}
	public static function ready(array $row,string $group,string $lang): bool {
		if(!hash_equals(self::entry_hash($row,$group,$lang),(string)($row['verified_'.$lang]??''))) { return false; }
		$required='team'===$group?['name','role']:('reviews'===$group?['name','text']:['title']);
		foreach($required as $key) { if(''===trim($row[$key.'_'.$lang]??'')) { return false; } }
		if('en'===$lang) { foreach(['name','role','text','title','unit'] as $key) { if(preg_match('/\p{Arabic}/u',$row[$key.'_en']??'')) { return false; } } }
		if('reviews'===$group && empty($row['consent'])) { return false; }
		if('metrics'===$group && 'manual'===($row['source']??'') && (''===($row['value']??'') || empty($row['text_'.$lang]) || empty($row['reviewed_at']))) { return false; }
		return true;
	}
	public static function save(array $input,string $revision) {
		if(!current_user_can('manage_options')) { return new \WP_Error('adc_content_forbidden','Forbidden'); }
		$clean=self::normalize($input); if(is_wp_error($clean)) { return $clean; }
		global $wpdb; $lock=$wpdb->prefix.'adc_site_content';
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))) { return new \WP_Error('adc_content_busy',self::t('يجري حفظ المحتوى. حاول مجددًا.','Content is being saved. Try again.')); }
		try {
			wp_cache_delete(self::OPTION,'options');
			if(!hash_equals(self::revision(),$revision)) { return new \WP_Error('adc_content_stale',self::t('تغير المحتوى في نافذة أخرى. أعد تحميل الصفحة قبل الحفظ.','Content changed in another window. Reload before saving.')); }
			if(!Transaction::begin()) { return new \WP_Error('adc_content_storage','Storage unavailable'); }
			$before=(array)get_option(self::OPTION,[]); update_option(self::OPTION,$clean,false);
			$ok=get_option(self::OPTION)===$clean && Transaction::commit(static fn()=>AuditLog::record('content.saved','site_content',0,'Editorial content updated',$before,$clean));
			if(!$ok) { $wpdb->query('ROLLBACK'); wp_cache_delete(self::OPTION,'options'); wp_cache_delete('notoptions','options'); return new \WP_Error('adc_content_storage',self::t('تعذر حفظ المحتوى؛ لم تُطبق التغييرات.','Content could not be saved; changes were not applied.')); }
			if(function_exists('w3tc_flush_all')) { call_user_func('w3tc_flush_all'); } if(function_exists('car_dealer_clear_public_page_cache')) { car_dealer_clear_public_page_cache(); }
			return true;
		} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
	}
	public static function rows(string $group,?string $lang=null): array {
		$lang=$lang??Localization::language(); $data=self::data(); $rows=array_filter($data[$group]??[],static fn($r)=>'published'===$r['status'] && self::ready($r,$group,$lang));
		if('reviews'===$group){$rows=apply_filters('adc_site_content_review_rows',array_values($rows),$lang);}
		usort($rows,static fn($a,$b)=>$a['order']<=>$b['order']); return $rows;
	}
	public static function render(string $group,array $attrs=[]): string {
		if(!in_array($group,self::COLLECTIONS,true)) { return ''; } $rows=self::rows($group); if(!$rows) { return ''; }
		$rows=array_slice($rows,0,max(1,min(48,(int)($attrs['count']??12)))); $lang=Localization::language(); $title=['team'=>['فريقنا','Our team'],'reviews'=>['آراء العملاء','Customer reviews'],'metrics'=>['بالأرقام','In numbers']][$group];
		$custom_title=$attrs['title']??''; $description=$attrs['description']??'';
		$html='<section class="adc-editorial-section adc-editorial-'.esc_attr($group).'"><div class="container"><h2>'.esc_html($custom_title?:self::t(...$title)).'</h2>'.($description?'<p>'.esc_html($description).'</p>':'').'<div class="adc-editorial-grid">';
		$metric_counts=null;
		foreach($rows as $r) {
			$html.='<article class="adc-editorial-card"'.(!empty($r['original_language'])?' lang="'.esc_attr($r['original_language']).'" dir="'.('ar'===$r['original_language']?'rtl':'ltr').'"':'').'>';
			if('team'===$group) {
				$image=$r['image']?wp_get_attachment_image($r['image'],'medium',false,['class'=>'adc-team-photo','loading'=>'lazy','alt'=>$r['name_'.$lang]]):'';
				$html.=$image?:'<div class="adc-team-avatar" aria-hidden="true"><svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="60" cy="60" r="58" fill="currentColor" opacity=".08"/><circle cx="60" cy="43" r="20" fill="currentColor" opacity=".65"/><path d="M24 103v-9a36 30 0 0 1 72 0v9" fill="currentColor" opacity=".65"/></svg></div>';
			}
			if('metrics'===$group) {
				$value=$r['value']; if('manual'!==$r['source']) { $metric_counts=$metric_counts??HomePageView::view(); $value=$metric_counts['available_stock'===$r['source']?'available_car_count':'published_car_count']; }
				$html.='<p class="adc-metric-value">'.esc_html(number_format_i18n((float)$value,str_contains((string)$value,'.')?2:0)).' <span>'.esc_html($r['unit_'.$lang]).'</span></p><h3>'.esc_html($r['title_'.$lang]).'</h3>';
				$html.='<small>'.esc_html(self::t('المصدر: ','Source: ').('manual'===$r['source']?$r['text_'.$lang]:self::t('بيانات النظام الحالية','Current system data'))).'</small>';
				if('manual'===$r['source']) { $html.='<small>'.esc_html(self::t('آخر مراجعة: ','Last reviewed: ').$r['reviewed_at']).'</small>'; }
			} else {
				if('reviews'===$group){$html.='<span class="adc-review-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><circle cx="12" cy="8" r="4"/><path d="M4 22v-3a8 7 0 0 1 16 0v3Z"/></svg></span>';}
				$html.='<h3>'.esc_html($r['name_'.$lang]).'</h3>';
				if('team'===$group) { $html.='<p class="adc-team-role">'.esc_html($r['role_'.$lang]).'</p>'; }
				if('reviews'===$group && $r['rating']) { $html.='<p class="adc-review-rating" aria-label="'.esc_attr(sprintf(self::t('التقييم %d من 5','Rating %d out of 5'),$r['rating'])).'">'.str_repeat('★',$r['rating']).'</p>'; }
				$html.=('reviews'===$group?'<blockquote>':'<p>').nl2br(esc_html($r['text_'.$lang])).('reviews'===$group?'</blockquote>':'</p>');
				if('reviews'===$group && $r['source']) { $html.='<small>'.esc_html($r['source']).'</small>'; }
			} $html.='</article>';
		}
		return $html.'</div></div></section>';
	}
	public static function home_enabled(string $key): bool { return 'published'===(self::data()['home'][$key]['status']??'hidden'); }
	public static function home_text(string $key,string $field,string $fallback): string {
		$lang = Localization::language();
		$text = self::data()['home'][$key][$field.'_'.$lang] ?: $fallback;
		// Only exact shipped defaults are upgraded; custom editorial copy stays intact.
		$defaults = array('hero' => array('سيارتك الجديدة تبدأ من أوتو براندز' => 'سيارتك الجديدة تبدأ من {site_name}', 'Your new vehicle starts with Auto Brands' => 'Your new vehicle starts with {site_name}'), 'why' => array('لماذا أوتو براندز؟' => 'لماذا {site_name}؟', 'Why AUTO BRANDS?' => 'Why {site_name}?'));
		if ('title' === $field) { $text = $defaults[$key][$text] ?? $text; }
		return ProductSetup::template($text,$lang);
	}
	public static function home_empty(string $key): string {
		$messages=[
			'featured'=>['نعمل على تجهيز مجموعة مختارة من السيارات لعرضها هنا. يمكنك استعراض السيارات المتاحة حاليًا أو التواصل مع فريقنا لمساعدتك في الاختيار.','We are preparing a selection of featured vehicles. Browse the available vehicles or contact our team for help choosing.'],
			'latest'=>['ستظهر أحدث السيارات هنا فور إضافتها ونشرها. يسعد فريقنا مساعدتك في معرفة الخيارات المتاحة حاليًا.','New arrivals will appear here as they are published. Our team will be happy to help you explore the options currently available.'],
			'offers'=>['لا توجد عروض منشورة حاليًا. استعرض السيارات المتاحة أو تواصل مع فريقنا لمعرفة الخيارات المناسبة لك.','There are no published offers at the moment. Browse available vehicles or contact our team to discuss your options.'],
			'demand'=>['نجهّز اختيارات هذا القسم بعناية. يمكنك الآن استعراض الكتالوج ومقارنة السيارات بحسب احتياجك.','We are preparing the selections for this section. Explore the catalog and compare vehicles that suit your needs.'],
			'reviews'=>['لم تُنشر آراء العملاء في هذا القسم بعد. يسعدنا الاستماع إلى تجربتك عبر صفحة التواصل.','No customer reviews have been published here yet. We welcome your feedback through our contact page.']
		];
		if(!isset($messages[$key])) { return ''; }
		$ids=['featured'=>'featured-vehicles','latest'=>'latest-vehicles','offers'=>'current-offers','demand'=>'selected-vehicles','reviews'=>'customer-reviews'];
		$title=self::home_text($key,'title',self::t(...self::home_definitions()[$key]));
		$html='<section id="'.esc_attr($ids[$key]).'" class="ab-section adc-home-empty-section adc-home-empty-'.esc_attr($key).'"><div class="container"><h2>'.esc_html($title).'</h2><div class="adc-home-empty"><span class="adc-home-empty-symbol" aria-hidden="true">'.('reviews'===$key?'❝':'◇').'</span><p>'.esc_html(self::t(...$messages[$key])).'</p>';
		$page=get_page_by_path('contact'); if($page && 'publish'===$page->post_status) { $url=add_query_arg('lang',Localization::language(),get_permalink($page)); $html.='<a class="btn btn-primary" href="'.esc_url($url).'">'.esc_html(self::t('تواصل مع فريقنا','Contact our team')).'</a>'; }
		return $html.'</div></div></section>';
	}
	public static function home_layout(string $html,string $key,array $settings): string {
		if(''===trim($html)) { return $html; }
		$tag=new \WP_HTML_Tag_Processor($html);
		if($tag->next_tag('SECTION')) {
			$tag->add_class('adc-home-section');
			$tag->set_attribute('data-home-section',$key);
			$height=in_array($settings['height']??'auto',['auto','balanced','screen'],true)?($settings['height']??'auto'):'auto';
			if('screen'===$height && !in_array($key,['hero','why','cta'],true)) { $height='auto'; }
			$tag->set_attribute('data-section-height',$height);
			$tag->set_attribute('data-section-spacing',in_array($settings['spacing']??'normal',['compact','normal','spacious'],true)?($settings['spacing']??'normal'):'normal');
		}
		return $tag->get_updated_html();
	}
	public static function home_render(array $parts): string {
		foreach(['featured','latest','offers','demand','reviews'] as $key) { if(''===trim($parts[$key]??'')) { $parts[$key]=self::home_empty($key); } }
		$home=array_intersect_key(self::data()['home'],self::home_definitions()); uasort($home,static fn($a,$b)=>$a['order']<=>$b['order']); $html=self::home_enabled('hero')?self::home_layout($parts['hero']??'','hero',$home['hero']):''; unset($home['hero']); foreach($home as $key=>$settings) { if('published'===$settings['status']) { $html.=self::home_layout($parts[$key]??'',$key,$settings); } }
		if(isset($_GET['adc_content_preview']) && current_user_can('manage_options')) { $html='<div class="adc-content-preview-banner">'.esc_html(self::t('معاينة خاصة — لم تُنشر هذه التغييرات','Private preview — these changes are not published')).'</div>'.$html; }
		return $html;
	}
}
