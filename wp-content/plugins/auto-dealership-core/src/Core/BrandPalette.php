<?php
namespace AutoDealership\Core;

defined( 'ABSPATH' ) || exit;

/** Validated, portable semantic colors. No operational data is stored here. */
final class BrandPalette {
    public const OPTION = 'adc_brand_palette';
    public static function boot(): void {
        add_action( 'admin_init', array( self::class, 'migrate' ) );
        add_action( 'admin_menu', static function (): void { add_submenu_page( 'options-general.php', self::t('أدوات الهوية اللونية','Color identity tools'), self::t('أدوات الهوية اللونية','Color identity tools'), 'manage_options', 'adc-brand-colors', array(self::class,'render') ); } );
        add_action( 'admin_post_adc_brand_palette', array(self::class,'submit') );
        add_action( 'update_option_car_dealer_theme_settings', array(self::class,'legacy_update'), 10, 2 );
        add_action( 'update_option_' . self::OPTION, array(self::class,'invalidate'), 10, 0 );
    }
    public static function t(string $ar,string $en): string { return ProductSetup::t($ar,$en); }
    public static function groups(): array { return array('brand'=>self::t('الهوية','Brand'),'surfaces'=>self::t('الخلفيات والنصوص','Surfaces and text'),'buttons'=>self::t('الأزرار والروابط','Buttons and links'),'navigation'=>self::t('القائمة والتذييل والهيرو','Navigation, footer and hero'),'fields'=>self::t('النماذج','Forms'),'status'=>self::t('الحالات والمحادثات','Status and conversations'),'mail'=>self::t('البريد الإلكتروني','Email')); }
    /** A recipe is a literal, reference, mix, or readable foreground. Shared with JS. */
    public static function schema(): array {
        $rows = array(
            array('brand-navy','brand','الكحلي','Navy','#101C32',null),
            array('brand-blue','brand','أزرق الهوية','Brand blue','#2D7BEA',null),
            array('brand-red','brand','أحمر الهوية','Brand red','#E60000',null),
            array('bg','surfaces','خلفية الموقع','Page background','#FFFFFF','#0B1220'),
            array('surface','surfaces','البطاقات والأسطح','Cards and surfaces','#FFFFFF','#142033'),
            array('soft','surfaces','الأقسام البديلة','Alternate sections','#F4F7FB','#1C2B42'),
            array('text','surfaces','النص الأساسي','Body text','#1E293B','#F1F5F9'),
            array('heading','surfaces','العناوين','Headings','@brand-navy','#F1F5F9'),
            array('muted','surfaces','النص الثانوي','Secondary text','#5F6F85','#B8C7DA'),
            array('border','surfaces','حدود البطاقات','Card borders','#E2E8F0','#405570'),
            array('action','buttons','الزر الأساسي','Primary button',array('tone','@brand-blue','#2D7BEA','#1764CE'),array('mix','@brand-blue','#FFFFFF',0.30)),
            array('action-hover','buttons','تحويم الزر الأساسي','Primary button hover',array('tone','@action','#1764CE','#104FA6'),array('mix','@action','#FFFFFF',0.12)),
            array('on-action','buttons','نص الزر الأساسي','Primary button text',array('ink','@action','@action-hover'),null),
            array('primary','buttons','الزر المميز','Featured button','@brand-navy','@action'),
            array('primary-hover','buttons','تحويم الزر المميز','Featured button hover',array('mix','@primary','#FFFFFF',0.12),'@action-hover'),
            array('on-primary','buttons','نص الزر المميز','Featured button text',array('ink','@primary','@primary-hover'),null),
            array('offer','buttons','العروض','Offers',array('mix','@brand-red','#000000',0.126),'#FF8A8A'),
            array('offer-hover','buttons','تحويم العروض','Offer hover',array('mix','@offer','#000000',0.17),'#FFA3A3'),
            array('on-offer','buttons','نص زر العرض','Offer button text',array('ink','@offer','@offer-hover'),null),
            array('link','buttons','الروابط','Links','@action','#93C5FD'),
            array('focus','buttons','مؤشر التركيز','Focus indicator','@action','#93C5FD'),
            array('header','navigation','خلفية القائمة','Header background','@surface',null),
            array('header-text','navigation','نص القائمة','Header text','@heading',null),
            array('header-active','navigation','رابط القائمة النشط','Active navigation','@link',null),
            array('footer','navigation','خلفية التذييل','Footer background','@brand-navy',null),
            array('footer-text','navigation','نص التذييل','Footer text',array('ink','@footer'),null),
            array('footer-muted','navigation','النص الثانوي في التذييل','Footer secondary text',array('mix','@footer-text','@footer',0.18),null),
            array('footer-link','navigation','روابط التذييل','Footer links','@footer-text',null),
            array('hero','navigation','بداية تدرج الهيرو','Hero gradient start','@brand-navy',null),
            array('hero-end','navigation','نهاية تدرج الهيرو','Hero gradient end',array('mix','@brand-navy','@brand-blue',0.30),null),
            array('hero-text','navigation','نص الهيرو','Hero text',array('ink','@hero','@hero-end'),null),
            array('hero-muted','navigation','النص الثانوي في الهيرو','Hero secondary text',array('mix','@hero-text','@hero',0.18),null),
            array('inverse','navigation','نص الأقسام المميزة','Featured section text','@on-primary',null),
            array('on-dark-muted','navigation','نص الأقسام الداكنة الثانوي','Dark section secondary text','#CBD5E1',null),
            array('on-dark-link','navigation','روابط الأقسام الداكنة','Dark section links','#93C5FD',null),
            array('field-bg','fields','خلفية الحقل','Field background','@surface',null),
            array('field-text','fields','نص الحقل','Field text','@text',null),
            array('field-placeholder','fields','النص الإرشادي','Placeholder text','@muted',null),
            array('control-border','fields','حدود عناصر التحكم','Control borders','#64748B','#8294AD'),
            array('field-border','fields','حدود الحقول','Field borders','@control-border',null),
            array('field-hover-border','fields','حدود الحقل عند التحويم','Field hover border','@action',null),
            array('field-focus','fields','تركيز الحقل','Field focus','@focus',null),
            array('field-readonly-bg','fields','الحقل للقراءة فقط','Read-only field','@soft',null),
            array('field-disabled-bg','fields','خلفية الحقل المعطل','Disabled field background','@soft',null),
            array('field-disabled-text','fields','نص الحقل المعطل','Disabled field text','@muted',null),
            array('success','status','النجاح والاتصال','Success and online','#15803D','#86EFAC'),
            array('success-bg','status','خلفية النجاح','Success background','#F0FDF4','#143829'),
            array('warning','status','التحذير','Warning','#B45309','#FDE68A'),
            array('warning-bg','status','خلفية التحذير','Warning background','#FFFBEB','#3B2B14'),
            array('error','status','الخطأ','Error','#B91C1C','#FCA5A5'),
            array('error-bg','status','خلفية الخطأ','Error background','#FEF2F2','#421F2B'),
            array('info','status','المعلومات','Information','@action','#93C5FD'),
            array('info-bg','status','خلفية المعلومات','Information background','#EFF6FF','#1B304C'),
            array('favorite','status','قلب المفضلة','Favorite heart','@offer',null),
            array('price','surfaces','أسعار السيارات','Vehicle prices','@heading',null),
            array('chat-bubble','status','رسالة العميل','Customer message bubble','@action',null),
            array('chat-text','status','نص رسالة العميل','Customer message text',array('ink','@chat-bubble'),null),
            array('chat-peer','status','رسالة الموظف','Staff message bubble','@soft',null),
            array('chat-peer-text','status','نص رسالة الموظف','Staff message text','@text',null),
            array('mail-bg','mail','خلفية البريد','Email background','@soft',null),
            array('mail-surface','mail','بطاقة البريد','Email card','@surface',null),
            array('mail-header','mail','رأس البريد','Email header','@brand-navy',null),
            array('mail-header-text','mail','نص رأس البريد','Email header text',array('ink','@mail-header'),null),
            array('mail-heading','mail','عنوان البريد','Email heading','@heading',null),
            array('mail-text','mail','نص البريد','Email body text','@text',null),
            array('mail-muted','mail','نص البريد الثانوي','Email secondary text','@muted',null),
            array('mail-action','mail','زر البريد','Email button','@action',null),
            array('mail-on-action','mail','نص زر البريد','Email button text',array('ink','@mail-action'),null),
            array('mail-border','mail','حدود البريد','Email border','@border',null),
        );
        foreach(array('blue'=>array('@brand-blue','الأزرق','Blue'),'red'=>array('@brand-red','الأحمر','Red'),'slate'=>array('@brand-navy','الرمادي','Slate')) as $family=>$spec){foreach(array(50=>.94,100=>.88,200=>.75,300=>.58,400=>.30,500=>0,600=>-.12,700=>-.28,800=>-.45,900=>-.62,950=>-.77) as $step=>$amount){$recipe=$amount>=0?array('mix',$spec[0],'#FFFFFF',$amount):array('mix',$spec[0],'#000000',abs($amount));$rows[]=array($family.'-'.$step,'brand',$spec[1].' '.$step,$spec[2].' '.$step,$recipe,$recipe);}}
        $schema=array(); foreach($rows as $row){$schema[$row[0]]=array('group'=>$row[1],'label'=>self::t($row[2],$row[3]),'light'=>$row[4],'dark'=>$row[5]??$row[4]);} return $schema;
    }
    public static function empty_config(): array { return array('format'=>1,'light'=>array(),'dark'=>array()); }
    private static function legacy_hex($value): ?string { $hex=sanitize_hex_color($value);if(!$hex){return null;}if(4===strlen($hex)){$hex='#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];}return strtoupper($hex); }
    public static function config(): array { $data=get_option(self::OPTION,false); if(is_array($data)){return $data;} return self::legacy_config(); }
    private static function legacy_config(): array {
        $data=self::empty_config(); $old=(array)get_option('car_dealer_theme_settings',array());
        foreach(array('primary_color'=>'brand-navy','accent_color'=>'brand-blue') as $oldkey=>$key){$color=self::legacy_hex($old[$oldkey]??'');if($color){$data['light'][$key]=$color;}}
        // Preserve the exact existing button color until the new preset is published.
        if(isset($data['light']['brand-blue'])){$data['light']['action']=$data['light']['brand-blue'];}
        return $data;
    }
    public static function migrate(): void { if(current_user_can('manage_options') && false===get_option(self::OPTION,false)){add_option(self::OPTION,self::legacy_config(),'','no');} }
    public static function normalize($data) {
        if(!is_array($data)||array_diff(array_keys($data),array('format','light','dark'))||($data['format']??null)!==1){return new \WP_Error('adc_palette_format',self::t('ملف ألوان غير صالح أو إصدار غير مدعوم.','Invalid color file or unsupported version.'));}
        $clean=self::empty_config();$schema=self::schema();foreach(array('light','dark') as $mode){if(!isset($data[$mode])||!is_array($data[$mode])||array_diff(array_keys($data[$mode]),array_keys($schema))){return new \WP_Error('adc_palette_keys',self::t('حقول الألوان غير صالحة.','Invalid color fields.'));}foreach($data[$mode] as $key=>$value){if(!is_string($value)||!preg_match('/^#[0-9a-f]{6}$/i',$value)){return new \WP_Error('adc_palette_color',self::t('استخدم لون HEX من ست خانات.','Use a six-digit HEX color.'));}$clean[$mode][$key]=strtoupper($value);}}return $clean;
    }
    public static function mix(string $a,string $b,float $amount): string { $out='#';for($i=1;$i<7;$i+=2){$out.=sprintf('%02X',(int)round(hexdec(substr($a,$i,2))*(1-$amount)+hexdec(substr($b,$i,2))*$amount));}return $out; }
    public static function tone(string $source,string $reference,string $target): string { $out='#';for($i=1;$i<7;$i+=2){$out.=sprintf('%02X',max(0,min(255,hexdec(substr($source,$i,2))+hexdec(substr($target,$i,2))-hexdec(substr($reference,$i,2)))));}return $out; }
    public static function luminance(string $color): float { $v=array();for($i=1;$i<7;$i+=2){$n=hexdec(substr($color,$i,2))/255;$v[]=$n<=0.04045?$n/12.92:(($n+0.055)/1.055)**2.4;}return $v[0]*.2126+$v[1]*.7152+$v[2]*.0722; }
    public static function contrast(string $a,string $b): float { $a=self::luminance($a);$b=self::luminance($b);return(max($a,$b)+.05)/(min($a,$b)+.05); }
    public static function resolve(string $mode='light',?array $config=null): array {
        $config=$config??self::config();$schema=self::schema();$out=array();$busy=array();
        $get=function($key)use(&$get,&$out,&$busy,$schema,$mode,$config){if(isset($out[$key])){return $out[$key];}if(isset($busy[$key])){return '#000000';}$busy[$key]=true;
            $recipe=$config[$mode][$key]??(('dark'===$mode && 'brand'===$schema[$key]['group'])?($config['light'][$key]??$schema[$key][$mode]):$schema[$key][$mode]);
            $read=static fn($v)=>str_starts_with($v,'@')?$get(substr($v,1)):$v;
            if(is_array($recipe)){if('mix'===$recipe[0]){$value=self::mix($read($recipe[1]),$read($recipe[2]),$recipe[3]);}elseif('tone'===$recipe[0]){$value=self::tone($read($recipe[1]),$recipe[2],$recipe[3]);}else{$backgrounds=array_map($read,array_slice($recipe,1));$white=min(array_map(static fn($v)=>self::contrast('#FFFFFF',$v),$backgrounds));$black=min(array_map(static fn($v)=>self::contrast('#000000',$v),$backgrounds));$value=$white>=$black?'#FFFFFF':'#000000';}}else{$value=$read($recipe);}unset($busy[$key]);return $out[$key]=$value;};
        foreach(array_keys($schema) as $key){$get($key);}return $out;
    }
    public static function css(?array $config=null): string {
        $css='';foreach(array('light','dark') as $mode){$css.=('dark'===$mode?':root[data-ab-theme="dark"]':':root').'{';foreach(self::resolve($mode,$config) as $key=>$value){$css.='--ab-'.$key.':'.$value.';';}$css.='}';}
        foreach(array_keys(self::schema()) as $key){$css.='.has-autobrands-'.$key.'-color{color:var(--ab-'.$key.')!important}.has-autobrands-'.$key.'-background-color{background-color:var(--ab-'.$key.')!important}';}
        return $css;
    }
    /** Email uses the published light scheme, independent of the sender's screen preference. */
    public static function email_colors(): array { $colors=self::resolve('light');return array_filter($colors,static fn($key)=>str_starts_with($key,'mail-'),ARRAY_FILTER_USE_KEY); }
    public static function email_html(string $html,array $colors): string {
        // Match only CSS properties, never replace content, URLs or arbitrary hex substrings.
        $map=array('#F3F6FA'=>$colors['mail-bg'],'#24364B'=>$colors['mail-text'],'#E2E8F0'=>$colors['mail-border'],'#64748B'=>$colors['mail-muted'],'#D5E3F4'=>$colors['mail-header-text']);
        $html=preg_replace_callback('/((?:background(?:-color)?|color|border(?:-top)?)\s*:[^;"<>]*?)(#[0-9a-f]{6})(?=[;"\s])/i',static fn($m)=>$m[1].($map[strtoupper($m[2])]??$m[2]),$html);
        $html=preg_replace_callback('/(background(?:-color)?\s*:\s*)(?:#fff(?:fff)?)(?=[;"\s])/i',static fn($m)=>$m[1].$colors['mail-surface'],$html);
        $html=preg_replace_callback('/(bgcolor=")#F3F6FA"/i',static fn($m)=>$m[1].$colors['mail-bg'].'"',$html);
        // Header and CTA need different foregrounds when their backgrounds are customized.
        $html=preg_replace_callback('/(<a\b[^>]*style=")([^"]*border:1px solid[^\"]*)(")/i',static fn($m)=>$m[1].preg_replace('/color:#(?:fff|ffffff)(?=[;"\s])/i','color:'.$colors['mail-on-action'],$m[2]).$m[3],$html);
        $html=preg_replace_callback('/(<p\b[^>]*style=")([^"]*font-size:26px[^"]*)(")/i',static fn($m)=>$m[1].preg_replace('/color:#(?:fff|ffffff)(?=[;"\s])/i','color:'.$colors['mail-header-text'],$m[2]).$m[3],$html);
        $html=preg_replace_callback('/(<h1\b[^>]*style=")([^"]*)(")/i',static fn($m)=>$m[1].preg_replace('/color:#[0-9a-f]{6}/i','color:'.$colors['mail-heading'],$m[2]).$m[3],$html);
        return $html;
    }
    public static function legacy_update($old,$new): void { if(!is_array($new)){return;}$data=self::config();foreach(array('primary_color'=>'brand-navy','accent_color'=>'brand-blue') as $key=>$token){$color=self::legacy_hex($new[$key]??'');if(($old[$key]??'')!==($new[$key]??'') && $color){$data['light'][$token]=$color;if('accent_color'===$key){unset($data['light']['action']);}}}update_option(self::OPTION,$data,false); }
    public static function invalidate(): void { if(function_exists('wp_cache_flush')){wp_cache_flush();}if(function_exists('w3tc_flush_all')){call_user_func('w3tc_flush_all');}if(function_exists('car_dealer_clear_public_page_cache')){car_dealer_clear_public_page_cache();}do_action('adc_brand_palette_updated'); }
    public static function url(): string { return admin_url('options-general.php?page=adc-brand-colors'); }
    public static function submit(): void {
        if(!current_user_can('manage_options')){wp_die('Forbidden','',array('response'=>403));}check_admin_referer('adc_brand_palette');$operation=sanitize_key($_POST['operation']??'save');
        if(!in_array($operation,array('save','restore','export'),true)){wp_die('Invalid operation','',array('response'=>400));}
        if('export'===$operation){nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="dealership-colors.json"');echo wp_json_encode(self::config(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);exit;}
        $raw=wp_unslash($_POST['palette']??'');$data='restore'===$operation?get_option('adc_brand_palette_previous',false):(is_string($raw)&&strlen($raw)<=32000?json_decode($raw,true):null);$data=self::normalize($data);
        if(is_wp_error($data)){set_transient('adc_palette_notice_'.get_current_user_id(),array('error'=>$data->get_error_message()),60);}else{update_option('adc_brand_palette_previous',self::config(),false);update_option(self::OPTION,$data,false);$colors=self::resolve('light',$data);$theme=(array)get_option('car_dealer_theme_settings',array());$theme['primary_color']=$colors['brand-navy'];$theme['accent_color']=$colors['brand-blue'];remove_action('update_option_car_dealer_theme_settings',array(self::class,'legacy_update'),10);update_option('car_dealer_theme_settings',$theme);set_transient('adc_palette_notice_'.get_current_user_id(),array('success'=>self::t('تم نشر الهوية اللونية بنجاح.','Color identity published successfully.')),60);}
        wp_safe_redirect(self::url());exit;
    }
    public static function render(): void { BrandCustomizer::render_tools(); }
}
