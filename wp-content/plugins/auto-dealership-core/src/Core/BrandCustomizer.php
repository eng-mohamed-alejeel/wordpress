<?php
namespace AutoDealership\Core;
defined('ABSPATH') || exit;

/** WordPress changesets own draft persistence; the published palette remains untouched. */
final class BrandCustomizer {
    public static function boot(): void {
        add_action('customize_register',array(self::class,'register'),30);
        add_action('customize_controls_enqueue_scripts',array(self::class,'controls'));
        add_action('customize_preview_init',array(self::class,'preview'));
        add_action('customize_save_after',array(self::class,'after_publish'));
        add_action('template_redirect',array(self::class,'mail_preview'));
        add_action('update_option_'.BrandPalette::OPTION,static function($old):void{if(is_array($old)){update_option('adc_brand_palette_previous',$old,false);}},5,1);
    }
    public static function url(): string { return add_query_arg('autofocus[panel]','adc_brand_palette',admin_url('customize.php')); }
    public static function register($manager): void {
        if('car-dealer'!==get_stylesheet()){return;}
        $manager->add_panel('adc_brand_palette',array('capability'=>'manage_options','title'=>BrandPalette::t('الهوية والألوان','Brand and colors'),'priority'=>35,'description'=>BrandPalette::t('غيّر ألوان الصفحات مباشرة، ثم اضغط نشر لحفظها للموقع.','Preview colors on real pages, then publish to apply them to the site.')));
        $manager->add_setting(BrandPalette::OPTION,array('type'=>'option','capability'=>'manage_options','default'=>BrandPalette::config(),'transport'=>'postMessage','sanitize_callback'=>static function($value){$clean=BrandPalette::normalize($value);return is_wp_error($clean)?null:$clean;},'validate_callback'=>static function($validity,$value){$clean=BrandPalette::normalize($value);if(is_wp_error($clean)){$validity->add('adc_palette_invalid',$clean->get_error_message());}return $validity;}));
        $groups=array('overview'=>BrandPalette::t('المعاينة وفحص التباين','Preview and contrast'))+BrandPalette::groups();
        foreach($groups as $key=>$title){
            $manager->add_section('adc_palette_'.$key,array('panel'=>'adc_brand_palette','title'=>$title,'priority'=>'overview'===$key?1:10));
            $control=new class($manager,'adc_palette_'.$key,array('section'=>'adc_palette_'.$key,'settings'=>BrandPalette::OPTION,'label'=>$title,'input_attrs'=>array('group'=>$key))) extends \WP_Customize_Control {
                public $type='adc-brand-palette';
                public function render_content(){echo '<div class="adc-customizer-colors" data-palette-group="'.esc_attr($this->input_attrs['group']).'"></div>';}
            };
            $manager->add_control($control);
        }
    }
    private static function engine(): void { wp_enqueue_script('adc-brand-color-engine',plugins_url('assets/js/brand-color-engine.js',ADC_FILE),array(),ADC_VERSION,true); }
    public static function controls(): void {
        // Keep the compact personal appearance switch separate from palette editing.
        self::engine();wp_enqueue_style('adc-brand-customizer',plugins_url('assets/css/brand-customizer.css',ADC_FILE),array(),ADC_VERSION);
        wp_enqueue_script('adc-color-lab',plugins_url('assets/js/brand-color-lab.js',ADC_FILE),array('adc-brand-color-engine'),ADC_VERSION,true);
        wp_enqueue_script('adc-brand-studio',plugins_url('assets/js/brand-studio.js',ADC_FILE),array('customize-controls','adc-brand-color-engine','adc-color-lab'),ADC_VERSION,true);
        wp_add_inline_script('adc-brand-studio','window.adcBrandStudioLogo='.wp_json_encode(wp_get_attachment_image_url((int)get_theme_mod('custom_logo'),'full')?:'').';','before');
        wp_add_inline_script('adc-brand-studio','window.adcBrandStudioLogoId='.(int)get_theme_mod('custom_logo').';','before');
        wp_enqueue_script('adc-brand-studio-pro',plugins_url('assets/js/brand-studio-pro.js',ADC_FILE),array('adc-brand-studio'),ADC_VERSION,true);
        wp_add_inline_script('adc-brand-studio-pro','window.adcBrandPublished='.wp_json_encode(array('palette'=>BrandPalette::config(),'history'=>get_option('adc_brand_palette_history',array()))).';','before');
        wp_enqueue_script('adc-brand-customizer',plugins_url('assets/js/brand-customizer.js',ADC_FILE),array('customize-controls','adc-brand-color-engine'),ADC_VERSION,true);
        wp_add_inline_script('adc-brand-customizer','window.adcBrandCustomizer='.wp_json_encode(array('schema'=>BrandPalette::schema(),'tools'=>BrandPalette::url(),'admin'=>admin_url('admin.php?page=adc-workspace'),'mail'=>add_query_arg('adc_brand_preview','mail',home_url('/')),'strings'=>array('mode'=>BrandPalette::t('لوحة المعاينة والتحرير','Preview and editing scheme'),'light'=>BrandPalette::t('فاتح','Light'),'dark'=>BrandPalette::t('داكن','Dark'),'advanced'=>BrandPalette::t('عرض جميع الدرجات والإعدادات المتقدمة','Show all shades and advanced settings'),'automatic'=>BrandPalette::t('تلقائي','Automatic'),'custom'=>BrandPalette::t('مخصص','Custom'),'reset'=>BrandPalette::t('إعادة المجموعة إلى التلقائي','Reset group to automatic'),'contrast'=>BrandPalette::t('فحص التباين','Contrast checks'),'pass'=>BrandPalette::t('واضح','Pass'),'fail'=>BrandPalette::t('يحتاج تحسينًا','Needs improvement'),'fix'=>BrandPalette::t('اقتراح لون أوضح','Suggest readable color'),'tools'=>BrandPalette::t('أدوات الاستيراد والتصدير والاستعادة','Import, export and restore tools'),'admin'=>BrandPalette::t('فتح الإدارة المنشورة في نافذة أخرى','Open published administration in another window'),'mail'=>BrandPalette::t('معاينة رسالة البريد','Preview email'),'mailNote'=>BrandPalette::t('البريد يستخدم لوحة الفاتح. المعاينة لا ترسل أي رسالة.','Email uses the light palette. Preview does not send messages.'),'draft'=>BrandPalette::t('التغييرات خاصة بالمعاينة حتى الضغط على نشر.','Changes remain private until Publish.')))).';','before');
    }
    public static function preview(): void {
        self::engine();wp_enqueue_script('adc-brand-customizer-preview',plugins_url('assets/js/brand-customizer-preview.js',ADC_FILE),array('customize-preview','adc-brand-color-engine'),ADC_VERSION,true);
        wp_add_inline_script('adc-brand-customizer-preview','window.adcBrandPreview='.wp_json_encode(array('schema'=>BrandPalette::schema())).';','before');
    }
    public static function after_publish($manager): void {
        // Core has already checked changeset ownership, capabilities and validity, including scheduled publication.
        $setting=$manager->get_setting(BrandPalette::OPTION);if(!$setting || null===$manager->post_value($setting,null)){return;}
        $colors=BrandPalette::resolve();$theme=(array)get_option('car_dealer_theme_settings',array());$theme['primary_color']=$colors['brand-navy'];$theme['accent_color']=$colors['brand-blue'];remove_action('update_option_car_dealer_theme_settings',array(BrandPalette::class,'legacy_update'),10);update_option('car_dealer_theme_settings',$theme);BrandPalette::invalidate();
    }
    public static function render_tools(): void {
        if(!current_user_can('manage_options')){return;}
        self::engine();wp_enqueue_script('adc-brand-tools',plugins_url('assets/js/brand-color-tools.js',ADC_FILE),array('adc-brand-color-engine'),ADC_VERSION,true);
        wp_add_inline_script('adc-brand-tools','window.adcBrandTools='.wp_json_encode(array('schema'=>BrandPalette::schema(),'invalid'=>BrandPalette::t('ملف ألوان غير صالح. لم تتغير الإعدادات.','Invalid color file. Settings were not changed.'),'ready'=>BrandPalette::t('الملف صالح وجاهز للاستيراد. يمكنك مراجعة التباين أدناه قبل الحفظ.','Valid file, ready to import. Review contrast results below before saving.'),'fail'=>BrandPalette::t('أزواج ألوان تحتاج تحسين التباين: ','Color pairs needing contrast improvement: '),'pass'=>BrandPalette::t('اجتاز فحص التباين في اللوحتين.','Both schemes passed contrast checks.'))).';','before');
        echo '<div class="wrap"><h1>'.esc_html(BrandPalette::t('أدوات الهوية اللونية','Color identity tools')).'</h1><p>'.esc_html(BrandPalette::t('تعديل الألوان ومعاينة صفحات الموقع أصبح داخل التخصيص. هذه الصفحة مخصصة لنقل الإعدادات واستعادة النسخة السابقة.','Edit colors and preview real pages in the Customizer. This page provides settings transfer and previous-version restore tools.')).'</p><p><a class="button button-primary" href="'.esc_url(self::url()).'">'.esc_html(BrandPalette::t('فتح التخصيص — الهوية والألوان','Open Customizer — Brand and colors')).'</a></p>';
        $notice=get_transient('adc_palette_notice_'.get_current_user_id());if($notice){delete_transient('adc_palette_notice_'.get_current_user_id());echo '<div class="notice notice-'.(isset($notice['error'])?'error':'success').' is-dismissible"><p>'.esc_html($notice['error']??$notice['success']).'</p></div>';}
        echo '<form id="adc-brand-tools" action="'.esc_url(admin_url('admin-post.php')).'" method="post">';wp_nonce_field('adc_brand_palette');echo '<input type="hidden" name="action" value="adc_brand_palette"><input type="hidden" name="palette" data-palette-value><div class="card"><h2>'.esc_html(BrandPalette::t('تصدير واستعادة','Export and restore')).'</h2><p><button class="button" name="operation" value="export">'.esc_html(BrandPalette::t('تصدير الألوان المنشورة','Export published colors')).'</button> <button class="button" name="operation" value="restore" '.(get_option('adc_brand_palette_previous',false)?'':'disabled').'>'.esc_html(BrandPalette::t('استعادة النسخة السابقة','Restore previous version')).'</button></p></div><div class="card"><h2>'.esc_html(BrandPalette::t('استيراد الألوان','Import colors')).'</h2><label for="adc-color-import">'.esc_html(BrandPalette::t('ملف إعدادات الألوان JSON','Color settings JSON file')).'</label><p><input id="adc-color-import" type="file" accept="application/json,.json"></p><p data-import-status role="status"></p><ul data-import-contrast></ul><button class="button button-primary" name="operation" value="save" data-import-submit disabled>'.esc_html(BrandPalette::t('استيراد ونشر الألوان','Import and publish colors')).'</button></div></form></div>';
    }
    public static function mail_preview(): void {
        if('mail'!==($_GET['adc_brand_preview']??'') || !is_customize_preview() || !current_user_can('manage_options')){return;}
        nocache_headers();show_admin_bar(false);
        $t=static fn($ar,$en)=>esc_html(BrandPalette::t($ar,$en));
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?><style>body{margin:0;padding:32px 12px;background:var(--ab-mail-bg);font:15px/1.9 Tahoma,Arial;color:var(--ab-mail-text)}.adc-mail-preview{max-width:600px;margin:0 auto;background:var(--ab-mail-surface);border:1px solid var(--ab-mail-border);border-radius:16px;overflow:hidden}.adc-mail-preview header{padding:28px;background:var(--ab-mail-header);color:var(--ab-mail-header-text);font-size:26px;font-weight:bold}.adc-mail-preview section{padding:32px 28px}.adc-mail-preview h1{color:var(--ab-mail-heading);font-size:26px;line-height:1.5}.adc-mail-preview a{display:inline-block;background:var(--ab-mail-action);color:var(--ab-mail-on-action);padding:16px 24px;border-radius:8px;text-decoration:none;font-weight:bold}.adc-mail-preview footer{padding:20px 28px;border-top:1px solid var(--ab-mail-border);color:var(--ab-mail-muted);font-size:12px;text-align:center}</style></head><body><article class="adc-mail-preview"><header><?php echo esc_html(ProductSetup::brand(Localization::language())); ?></header><section><h1><?php echo $t('تأكيد حسابك','Verify your account'); ?></h1><p><?php echo $t('شكرًا لانضمامك. أكمل تأكيد بريدك للمتابعة.','Thank you for joining. Verify your email to continue.'); ?></p><a href="#" onclick="return false"><?php echo $t('تأكيد البريد الإلكتروني','Verify email'); ?></a></section><footer><?php echo $t('هذه معاينة تصميم فقط. لن يتم إرسال بريد إلكتروني.','Design preview only. No email will be sent.'); ?></footer></article><?php wp_footer(); ?></body></html><?php exit;
    }
}
