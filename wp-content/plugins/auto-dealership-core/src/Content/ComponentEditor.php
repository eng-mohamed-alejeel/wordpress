<?php
namespace AutoDealership\Content;
defined('ABSPATH') || exit;

/** Curated public components: blocks and classic-editor insertion share one contract. */
final class ComponentEditor {
	public static function boot(): void {
		add_shortcode('adc_newsletter', static function(): string { if(!function_exists('car_dealer_newsletter_form')) { return ''; } ob_start(); car_dealer_newsletter_form(); return (string)ob_get_clean(); });
		add_action('init', array(self::class,'register'), 20);
		add_filter('block_categories_all', static function(array $categories): array { array_unshift($categories,array('slug'=>'adc-platform','title'=>self::t('مكونات المعرض','Dealership components'),'icon'=>'store')); return $categories; });
		add_action('enqueue_block_editor_assets', array(self::class,'assets'));
		add_action('add_meta_boxes_page', static function(): void { if(get_current_screen() && get_current_screen()->is_block_editor()) { return; } add_meta_box('adc-component-library',self::t('مكتبة مكونات الموقع','Website component library'),array(self::class,'classic_box'),'page','side','high'); });
		add_action('admin_enqueue_scripts', static function(string $hook): void { if(in_array($hook,array('post.php','post-new.php'),true) && get_current_screen() && 'page' === get_current_screen()->post_type) { self::assets(); } });
	}
	private static function t(string $ar,string $en): string { return str_starts_with(get_user_locale(),'ar') ? $ar : $en; }
	public static function catalog(): array {
		$definitions = array(
			'adc_brand_name'=>array('اسم المنشأة','Business name','identity','اسم المنشأة من إعدادات المنصة، حسب لغة الزائر.','Business name from platform settings, in the visitor’s language.'),
			'adc_about_logo'=>array('شعار الموقع','Site logo','identity','الشعار المحفوظ في تخصيص الموقع.','Logo selected in site customization.'),
			'adc_legal_contact'=>array('بيانات التواصل القانونية','Legal contact details','identity','اسم المنشأة والعنوان والبريد والهاتف بالنسخة المناسبة للغة.','Business name, address, email and phone in the selected language.'),
			'car_dealer_cars'=>array('شبكة السيارات','Vehicle grid','vehicles','سيارات متاحة من الكتالوج مع إمكانية تحديد العدد.','Vehicles from the public catalog, with an adjustable count.'),
			'car_dealer_loan_calculator'=>array('حاسبة التمويل','Finance calculator','vehicles','حاسبة تقديرية مع إمكانية تحديد سعر ابتدائي.','Illustrative calculator with an optional initial price.'),
			'car_dealer_comparison'=>array('مقارنة السيارات','Vehicle comparison','vehicles','تعرض السيارات التي اختار الزائر مقارنتها.','Shows vehicles selected for comparison by the visitor.'),
			'car_dealer_contact_form'=>array('نموذج التواصل','Contact form','contact','نموذج التواصل الآمن المرتبط برسائل العملاء.','Contact form connected to customer enquiries.'),
			'ab_contact_grid'=>array('بيانات التواصل والفروع','Contact and branch cards','contact','بطاقات الفروع المفعّلة، أو بيانات التواصل العامة عند عدم وجود فروع.','Enabled branch cards, or general contact details when no branches exist.'),
			'adc_branch_locations'=>array('خرائط الفروع','Branch maps','contact','الخرائط والاتجاهات للفروع المفعّلة في الداشبورد.','Maps and directions for enabled dashboard branches.'),
			'adc_contact_intro'=>array('إرشادات نموذج التواصل','Enquiry guidance','contact','مقدمة وتنبيهات ما قبل إرسال الاستفسار.','Introduction and guidance before sending an enquiry.'),
			'adc_newsletter'=>array('الاشتراك في النشرة البريدية','Newsletter subscription','contact','نموذج الاشتراك مع موافقة التسويق والتحقق من البريد.','Subscription form with marketing consent and email verification.'),
			'ab_contact_hero'=>array('مقدمة صفحة التواصل','Contact page hero','contact','مقدمة مرئية لصفحة التواصل.','Visual introduction to the contact page.'),
			'ab_contact_map'=>array('رابط موقع المنشأة','Business map link','contact','العنوان المترجم ورابط فتحه على الخريطة.','Translated address with a link to open the map.'),
			'ab_contact_form_section'=>array('قسم التواصل الكامل','Full enquiry section','contact','عنوان ومقدمة ونموذج تواصل في قسم واحد. لا تجمعه مع نموذج آخر لنفس الغرض.','Heading, introduction and contact form. Avoid adding a second equivalent form.'),
			'ab_faq_section'=>array('الأسئلة الشائعة للتواصل','Contact FAQs','contact','مجموعة الأسئلة المتاحة في القالب.','FAQ section provided by the theme.'),
			'ab_social_section'=>array('الشبكات الاجتماعية','Social links','contact','روابط التواصل الاجتماعي المضبوطة في إعدادات الموقع.','Social links configured in site settings.'),
			'adc_about_actions'=>array('أزرار السيارات والتواصل','Vehicle and contact actions','about','أزرار مرتبطة بروابط الموقع واللغة الحالية.','Buttons using the site’s current URLs and language.'),
			'adc_about_branches'=>array('فروعنا في صفحة التعريف','About page branch list','about','تعريف مختصر بالفروع دون تحميل خرائط.','Compact branch list without embedded maps.'),
			'ab_hero_section'=>array('مقدمة تعريف المعرض','About hero','about','مقدمة تعريفية وأزرار الاستكشاف والتواصل.','Introduction with vehicle and contact actions.'),
			'ab_mission_vision'=>array('الرؤية والرسالة','Mission and vision','about','بطاقات الرؤية والرسالة.','Mission and vision cards.'),
			'ab_values_section'=>array('قيم المعرض','Dealership values','about','بطاقات القيم والمزايا.','Values and features cards.'),
			'ab_cta_section'=>array('دعوة للتواصل','Contact call to action','about','قسم دعوة للتواصل وروابط القنوات المتاحة.','Call to action using available contact channels.'),
			'adc_reservation_policy'=>array('سياسة الحجز الحالية','Current reservation policy','legal','شروط الحجز التشغيلية من إعدادات المنصة؛ راجعها قبل النشر.','Operational reservation terms from platform settings; review before publishing.'),
			'ab_team_section'=>array('فريق العمل القديم','Legacy team section','legacy','يظهر فقط عندما تتوفر بيانات الفريق المعتمدة في النسخة القديمة.','Only appears when approved legacy team data is available.'),
			'ab_stats_section'=>array('الإحصائيات المعتمدة','Approved statistics','legacy','محجوب حاليًا حتى ربط أرقام فعلية معتمدة؛ قد تكون المعاينة فارغة.','Currently withheld until approved real figures are available; preview may be empty.'),
			'ab_testimonials_section'=>array('آراء العملاء المعتمدة','Approved customer reviews','legacy','لا يعرض آراء تجريبية؛ قد تكون المعاينة فارغة.','Does not display invented reviews; preview may be empty.'),
			'car_dealer_testimonials'=>array('قائمة آراء العملاء','Customer review list','legacy','لا تعرض بيانات تجريبية عند عدم توفر مصدر معتمد.','Empty when no approved customer review source is available.'),
		);
		$rows = array();
		foreach($definitions as $tag=>$info) { if(shortcode_exists($tag)) { $rows[] = array('tag'=>$tag,'title'=>self::t($info[0],$info[1]),'group'=>$info[2],'description'=>self::t($info[3],$info[4]),'parameters'=>in_array($tag,array('car_dealer_cars','car_dealer_testimonials'),true)?array('count'):('car_dealer_loan_calculator'===$tag?array('price'):array())); } }
		return $rows;
	}
	public static function register(): void {
		wp_register_style('adc-component-editor',plugins_url('assets/css/component-editor.css',ADC_FILE),array(),ADC_VERSION);
		register_block_type('auto-dealership/component',array('api_version'=>3,'editor_style'=>'adc-component-editor','attributes'=>array('component'=>array('type'=>'string','default'=>'adc_brand_name'),'count'=>array('type'=>'integer','default'=>6),'price'=>array('type'=>'number','default'=>0),'previewLanguage'=>array('type'=>'string','default'=>'')),'supports'=>array('html'=>false,'align'=>array('wide','full')),'render_callback'=>array(self::class,'render')));
		register_block_pattern_category('adc-platform',array('label'=>self::t('أقسام المعرض الجاهزة','Dealership sections')));
		$contact = ''; foreach(array('ab_contact_grid','adc_branch_locations','adc_contact_intro','car_dealer_contact_form') as $tag) { $contact .= '<!-- wp:auto-dealership/component {"component":"' . $tag . '"} /-->' . "\n"; }
		register_block_pattern('auto-dealership/contact',array('title'=>self::t('صفحة تواصل مع الفروع والخرائط','Contact with branches and maps'),'categories'=>array('adc-platform'),'content'=>$contact));
	}
	public static function shortcode(array $attributes): string {
		$tags = array_column(self::catalog(),null,'tag'); $tag = $attributes['component'] ?? '';
		if(!is_string($tag) || !isset($tags[$tag])) { return ''; }
		$code = '[' . $tag;
		if(in_array('count',$tags[$tag]['parameters'],true)) { $code .= ' count="' . min(48,max(1,(int)($attributes['count']??6))) . '"'; }
		if(in_array('price',$tags[$tag]['parameters'],true)) { $code .= ' price="' . min(100000000,max(0,(float)($attributes['price']??0))) . '"'; }
		return $code . ']';
	}
	public static function render(array $attributes): string {
		$code = self::shortcode($attributes); if(!$code) { return ''; }
		$had = array_key_exists('lang',$_GET); $old = $_GET['lang']??null;
		if(defined('REST_REQUEST') && REST_REQUEST && in_array($attributes['previewLanguage']??'',array('ar','en'),true)) { $_GET['lang']=$attributes['previewLanguage']; }
		try { return '<div class="adc-component-output">' . do_shortcode($code) . '</div>'; }
		finally { if($had) { $_GET['lang']=$old; } else { unset($_GET['lang']); } }
	}
	public static function assets(): void {
		if(!current_user_can('edit_pages')) { return; }
		wp_enqueue_style('adc-component-editor',plugins_url('assets/css/component-editor.css',ADC_FILE),array(),ADC_VERSION);
		wp_enqueue_script('adc-component-editor',plugins_url('assets/js/component-editor.js',ADC_FILE),array('wp-blocks','wp-element','wp-block-editor','wp-components','wp-data','wp-plugins','wp-editor','wp-rich-text','wp-server-side-render','wp-dom-ready'),ADC_VERSION,true);
		wp_localize_script('adc-component-editor','adcComponentEditor',array('items'=>self::catalog(),'ar'=>str_starts_with(get_user_locale(),'ar')));
	}
	public static function classic_box(): void {
		echo '<div id="adc-classic-components"><p>' . esc_html(self::t('ابحث عن المكوّن وأدرجه في موضع المؤشر، أو انسخ الكود إلى أي نص أو ترجمة.','Find a component and insert it at the cursor, or copy its shortcode into text or translations.')) . '</p><input type="search" class="widefat" data-adc-search aria-label="' . esc_attr(self::t('بحث المكونات','Search components')) . '"><div data-adc-list></div><p data-adc-feedback role="status"></p></div>';
	}
}
