<?php
namespace AutoDealership\Core;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;

defined('ABSPATH') || exit;

/** One shared URL source for the platform and Customizer; display preferences are separate. */
final class SocialChannels {
	public const OPTION='adc_social_preferences';
	public const URL_OPTION='car_dealer_theme_settings';
	public static function t(string $ar,string $en):string{return Localization::language()==='en'?$en:$ar;}
	public static function platforms():array {
		return array(
			'facebook'=>array('ar'=>'فيسبوك','en'=>'Facebook','hosts'=>array('facebook.com','fb.com'),'color'=>'#1463C7','dark'=>'#86B7FF'),
			'instagram'=>array('ar'=>'إنستغرام','en'=>'Instagram','hosts'=>array('instagram.com'),'color'=>'#B12861','dark'=>'#FFA4C6'),
			'whatsapp'=>array('ar'=>'واتساب','en'=>'WhatsApp','hosts'=>array('wa.me','whatsapp.com'),'color'=>'#147D46','dark'=>'#75E5A5'),
			'x'=>array('ar'=>'إكس','en'=>'X','hosts'=>array('x.com','twitter.com'),'color'=>'#161616','dark'=>'#FFFFFF'),
			'tiktok'=>array('ar'=>'تيك توك','en'=>'TikTok','hosts'=>array('tiktok.com'),'color'=>'#161616','dark'=>'#FFFFFF'),
			'snapchat'=>array('ar'=>'سناب شات','en'=>'Snapchat','hosts'=>array('snapchat.com'),'color'=>'#6A5800','dark'=>'#FFFC00'),
			'youtube'=>array('ar'=>'يوتيوب','en'=>'YouTube','hosts'=>array('youtube.com','youtu.be'),'color'=>'#C00000','dark'=>'#FF9999'),
			'linkedin'=>array('ar'=>'لينكدإن','en'=>'LinkedIn','hosts'=>array('linkedin.com'),'color'=>'#0A66C2','dark'=>'#8FC5FF'),
		);
	}
	public static function label(string $key):string{$p=self::platforms()[$key]??array('ar'=>'','en'=>'');return self::t($p['ar'],$p['en']);}
	public static function defaults():array {$rows=array();foreach(self::platforms()as$key=>$p){$rows[$key]=array('enabled'=>true,'footer'=>true,'contact'=>true);}return array('style'=>'theme','order'=>array_keys($rows),'channels'=>$rows);}
	public static function validate_preferences($input) {
		if(is_string($input)&&strlen($input)<10000){$input=json_decode($input,true);}
		$keys=array_keys(self::platforms());
		if(!is_array($input)||array_diff(array_keys($input),array('style','order','channels'))||!in_array($input['style']??null,array('theme','brand'),true)||!isset($input['order'],$input['channels'])||!is_array($input['order'])||!is_array($input['channels'])||count($input['order'])!==count($keys)||array_diff_key($input['channels'],array_flip($keys))||array_diff($keys,array_keys($input['channels']))){return self::error();}
		foreach($input['order']as$key){if(!is_string($key)||!in_array($key,$keys,true)){return self::error();}}
		if(count(array_unique($input['order']))!==count($keys)){return self::error();}
		foreach($keys as$key){$row=$input['channels'][$key];if(!is_array($row)||count($row)!==3){return self::error();}foreach(array('enabled','footer','contact')as$flag){if(!isset($row[$flag])||!is_bool($row[$flag])){return self::error();}}}
		return $input;
	}
	public static function preferences():array{$saved=get_option(self::OPTION,'');$valid=self::validate_preferences($saved);return is_wp_error($valid)?self::defaults():$valid;}
	public static function sanitize_preferences($value){$valid=self::validate_preferences($value);return is_wp_error($valid)?$valid:wp_json_encode($valid);}
	private static function error():\WP_Error{return new \WP_Error('adc_social_invalid',self::t('راجع روابط المنصات والترتيب وإعدادات الظهور. لم تُحفظ تغييرات ناقصة.','Check platform links, order and visibility settings. Incomplete changes were not saved.'));}
	public static function phone(string $value):string {
		$value=trim($value);if(!preg_match('/^[+0-9\s()\-]*$/D',$value)){return '';}$number=preg_replace('/\D/','',$value);
		if(str_starts_with($number,'00')){$number=substr($number,2);}if(preg_match('/^05\d{8}$/D',$number)){$number='966'.substr($number,1);}
		return preg_match('/^[1-9]\d{8,14}$/D',$number)?$number:'';
	}
	/** Validate known platform hosts without contacting them or resolving DNS. */
	public static function validate_link(string $key,$value) {
		if(!isset(self::platforms()[$key])||!is_string($value)||strlen($value)>2000){return self::error();}$value=trim($value);if(''===$value){return '';}
		if('whatsapp'===$key){$number=self::phone($value);return $number?:self::error();}
		$url=wp_parse_url($value);if(!is_array($url)||!in_array(strtolower($url['scheme']??''),array('https','http'),true)||isset($url['user'])||isset($url['pass'])||isset($url['port'])){return self::error();}
		$host=strtolower($url['host']??'');$valid=false;foreach(self::platforms()[$key]['hosts']as$allowed){if($host===$allowed||str_ends_with($host,'.'.$allowed)){$valid=true;break;}}
		if(!$valid){return new \WP_Error('adc_social_host',sprintf(self::t('أدخل رابط حساب %s على نطاق المنصة الصحيح.','Enter a %s account URL on the platform’s own domain.'),self::label($key)));}
		$value=preg_replace('/^http:/i','https:',$value);$safe=esc_url_raw($value,array('https'));return $safe?:self::error();
	}
	public static function links(string $context):array {
		if(!in_array($context,array('footer','contact'),true)){return array();}$prefs=self::preferences();$settings=(array)get_option(self::URL_OPTION,array());$out=array();
		foreach($prefs['order']as$key){$flags=$prefs['channels'][$key];if(!$flags['enabled']||!$flags[$context]){continue;}$raw=$settings[$key]??'';if('whatsapp'===$key&&is_string($raw)&&''===trim($raw)){$raw=get_option('car_dealer_whatsapp','');}$url=self::validate_link($key,$raw);if(is_wp_error($url)||''===$url){continue;}if('whatsapp'===$key){$url='https://wa.me/'.$url;}$out[$key]=array('url'=>$url,'label'=>self::label($key));}return $out;
	}
	public static function icon(string $key,string $class='adc-social-icon'):string {
		$paths=array(
			'facebook'=>'<path d="M13.6 22v-9.1h3.1l.5-3.6h-3.6V7c0-1 .3-1.8 1.8-1.8h1.9V2.1A25 25 0 0 0 14.5 2c-2.8 0-4.7 1.7-4.7 4.8v2.5H6.7v3.6h3.1V22h3.8Z"/>',
			'instagram'=>'<rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="4.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="17.6" cy="6.5" r="1.1"/>',
			'x'=>'<path d="M18.9 2H22l-6.8 7.8L23.2 22h-6.3L12 14.6 5.5 22H2.3l8.2-9.4L.8 2h6.5l4.5 6.8L18.9 2Zm-1.1 18h1.7L6.3 3.9H4.5L17.8 20Z"/>',
			'tiktok'=>'<path d="M16.7 2c.3 2.7 1.8 4.3 4.3 4.5v3.3a10 10 0 0 1-4.3-1.1v7.1a6.5 6.5 0 1 1-5.5-6.4v3.4a3.2 3.2 0 1 0 2.1 3V2h3.4Z"/>',
			'snapchat'=>'<path d="M12 2.5c-3.5 0-5.2 2.6-5 5.8l.1 1.7c-.7.5-1.3-.4-2-.1-1 .4-.6 1.4.5 1.8.8.3 1.3.4 1.2 1-.3 1.7-1.8 3.5-4 4-.8.2-.6 1 .1 1.3l1.7.5.5 1.4c.2.5 1 .2 1.6.2 2.1-.1 3 1.4 5.3 1.4s3.2-1.5 5.3-1.4c.6 0 1.4.3 1.6-.2l.5-1.4 1.7-.5c.7-.3.9-1.1.1-1.3-2.2-.5-3.7-2.3-4-4-.1-.6.4-.7 1.2-1 1.1-.4 1.5-1.4.5-1.8-.7-.3-1.3.6-2 .1l.1-1.7c.2-3.2-1.5-5.8-5-5.8Z"/>',
			'youtube'=>'<path fill-rule="evenodd" d="M21.6 5.3C21.2 4 20.2 3.6 19 3.5a72 72 0 0 0-14 0C3.8 3.6 2.8 4 2.4 5.3a28 28 0 0 0 0 13.4c.4 1.3 1.4 1.7 2.6 1.8a72 72 0 0 0 14 0c1.2-.1 2.2-.5 2.6-1.8a28 28 0 0 0 0-13.4ZM10 8v8l7-4-7-4Z" clip-rule="evenodd"/>',
			'linkedin'=>'<path d="M5.2 7.7a2.2 2.2 0 1 0 0-4.4 2.2 2.2 0 0 0 0 4.4ZM3.4 9.3H7V21H3.4V9.3Zm5.8 0h3.5v1.6c.5-.9 1.7-1.9 3.5-1.9 3.8 0 4.5 2.4 4.5 5.5V21H17v-5.8c0-1.4 0-3.2-2-3.2s-2.2 1.5-2.2 3.1V21H9.2V9.3Z"/>',
			'whatsapp'=>'<path fill-rule="evenodd" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 1.8a8.2 8.2 0 1 1-4.2 15.2l-.3-.2-2.9.8.8-2.8-.2-.3A8.2 8.2 0 0 1 12 3.8Zm-3.1 3c-.3 0-.6.1-.8.4-.5.5-.9 1.1-.9 2 0 .9.6 1.9.8 2.2.1.2 1.6 2.6 4 3.6 2 .8 2.4.6 2.8.5.5-.1 1.5-.6 1.7-1.2.2-.6.2-1 .2-1.1-.1-.1-.3-.2-.5-.3l-1.7-.8c-.2-.1-.4-.1-.5.1l-.8.9c-.1.2-.3.2-.5.1a7 7 0 0 1-2.1-1.3 8 8 0 0 1-1.4-1.8c-.2-.2 0-.3.1-.4l.4-.5.3-.4c.1-.2 0-.4 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.7Z" clip-rule="evenodd"/>',
			'copy'=>'<rect x="8" y="8" width="12" height="13" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3" fill="none" stroke="currentColor" stroke-width="1.8"/>',
			'share'=>'<path d="M12 16V3m-5 5 5-5 5 5M5 13v7h14v-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
		);
		if(!isset($paths[$key])){return '';}
		return '<svg class="'.esc_attr($class).'" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false">'.$paths[$key].'</svg>';
	}
	public static function render(string $context):string {
		$links=self::links($context);if(!$links){return '';}$style=self::preferences()['style'];$html='<div class="adc-social-links adc-social-links--'.esc_attr($context).'" data-social-style="'.esc_attr($style).'" role="group" aria-label="'.esc_attr(self::t('حسابات التواصل الاجتماعي','Social media accounts')).'">';
		foreach($links as$key=>$link){$label=sprintf(self::t('زيارة حساب %s — يفتح في علامة تبويب جديدة','Visit our %s account — opens in a new tab'),$link['label']);$html.='<a class="adc-social-link" data-social-platform="'.$key.'" href="'.esc_url($link['url']).'" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr($label).'" data-tooltip="'.esc_attr($link['label']).'">'.self::icon($key).('contact'===$context?'<span class="adc-social-label">'.esc_html($link['label']).'</span>':'').'</a>';}
		return $html.'</div>';
	}
	public static function share(string $url,string $title,string $kind='vehicle'):string {
		$url=esc_url_raw($url,array('http','https'));if(!$url){return '';}$title=html_entity_decode(wp_strip_all_tags($title),ENT_QUOTES|ENT_HTML5,'UTF-8');$urls=array('whatsapp'=>'https://wa.me/?'.http_build_query(array('text'=>$title.' '.$url),'','&',PHP_QUERY_RFC3986),'x'=>'https://x.com/intent/tweet?'.http_build_query(array('text'=>$title,'url'=>$url),'','&',PHP_QUERY_RFC3986),'facebook'=>'https://www.facebook.com/sharer/sharer.php?'.http_build_query(array('u'=>$url),'','&',PHP_QUERY_RFC3986));
		$html='<section class="adc-share" data-social-share data-share-url="'.esc_attr($url).'" data-share-title="'.esc_attr($title).'" data-copied="'.esc_attr(self::t('تم نسخ الرابط.','Link copied.')).'" data-copy-fallback="'.esc_attr(self::t('تعذر النسخ التلقائي؛ انسخ الرابط من الحقل التالي.','Automatic copy is unavailable. Copy the link from the field below.')).'" data-share-error="'.esc_attr(self::t('تعذر فتح المشاركة. يمكنك نسخ الرابط بدلًا من ذلك.','Unable to open sharing. You can copy the link instead.')).'"><h2>'.esc_html('offer'===$kind?self::t('مشاركة العرض','Share this offer'):self::t('مشاركة السيارة','Share this vehicle')).'</h2><div class="adc-social-links" role="group" aria-label="'.esc_attr(self::t('خيارات المشاركة','Sharing options')).'">';
		foreach($urls as$key=>$link){$label=sprintf(self::t('مشاركة عبر %s','Share via %s'),self::label($key));$html.='<a class="adc-social-link" href="'.esc_url($link).'" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr($label).'" data-tooltip="'.esc_attr($label).'">'.self::icon($key).'</a>';}
		foreach(array('copy'=>self::t('نسخ الرابط','Copy link'),'share'=>self::t('مشاركة','Share'))as$key=>$label){$html.='<button class="adc-social-link" type="button" data-share-action="'.$key.'" aria-label="'.esc_attr($label).'" data-tooltip="'.esc_attr($label).'" hidden>'.self::icon($key).'</button>';}
		return $html.'</div><p class="adc-share-status" role="status" aria-live="polite"></p><label class="adc-share-fallback" hidden>'.esc_html(self::t('رابط الصفحة','Page link')).'<input readonly dir="ltr" value="'.esc_attr($url).'"></label></section>';
	}
	public static function revision():string{return hash('sha256',wp_json_encode(array(get_option(self::URL_OPTION,array()),get_option(self::OPTION,''))));}
	public static function save(array $input) {
		if(!current_user_can('manage_options')){return new \WP_Error('adc_social_forbidden',self::t('لا تملك صلاحية تعديل حسابات التواصل.','You cannot edit social accounts.'));}
		$prefs=self::validate_preferences($input['preferences']??null);$urls=$input['social_urls']??null;$keys=array_keys(self::platforms());if(is_wp_error($prefs)||!is_array($urls)||count($urls)!==count($keys)||array_diff_key($urls,array_flip($keys))){return self::error();}$clean=array();
		foreach($keys as$key){$value=self::validate_link($key,$urls[$key]??null);if(is_wp_error($value)){return $value;}$clean[$key]=$value;}
		if(!is_string($input['revision']??null)){return self::error();}if(!Transaction::begin()){return new \WP_Error('adc_social_storage',self::t('تعذر بدء الحفظ. حاول مجددًا.','Unable to start saving. Try again.'));}
		global $wpdb;$options=array(self::URL_OPTION,self::OPTION);$wpdb->get_results($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name IN (%s,%s) FOR UPDATE",...$options));self::clear_options();
		if(!hash_equals(self::revision(),$input['revision'])){$wpdb->query('ROLLBACK');return new \WP_Error('adc_social_stale',self::t('تغيرت الإعدادات. أعد تحميل الصفحة قبل الحفظ.','Settings changed. Reload before saving.'));}
		try{$existing=(array)get_option(self::URL_OPTION,array());$new=array_replace($existing,$clean);update_option(self::URL_OPTION,$new);update_option(self::OPTION,wp_json_encode($prefs),false);
			foreach($clean as$key=>$value){if(((array)get_option(self::URL_OPTION,array()))[$key]!==$value){throw new \RuntimeException('urls');}}if(self::preferences()!==$prefs){throw new \RuntimeException('preferences');}
			if(!Transaction::commit(static fn()=>AuditLog::record('settings.social_channels_changed','configuration',0,'Social account URLs and display preferences updated',null,array('enabled'=>array_keys(array_filter($prefs['channels'],static fn($row)=>$row['enabled'])),'style'=>$prefs['style'])))){throw new \RuntimeException('audit');}
		}catch(\Throwable){$wpdb->query('ROLLBACK');self::clear_options();return new \WP_Error('adc_social_save',self::t('تعذر حفظ التغييرات؛ أعد تحميل الصفحة وحاول مجددًا.','Changes could not be saved. Reload and try again.'));}
		self::clear_options();if(function_exists('car_dealer_clear_public_page_cache')){\car_dealer_clear_public_page_cache();}return true;
	}
	private static function clear_options():void {foreach(array(self::URL_OPTION,self::OPTION)as$name){wp_cache_delete($name,'options');}wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}
	public static function boot():void {
		add_action('admin_menu',static function(){add_options_page(self::t('التواصل الاجتماعي','Social media'),self::t('التواصل الاجتماعي','Social media'),'manage_options','adc-social-channels',array(self::class,'admin'));});
		add_action('admin_post_adc_save_social_channels',static function(){check_admin_referer('adc_save_social_channels');$result=self::save(wp_unslash($_POST));if(is_wp_error($result)){set_transient('adc_social_error_'.get_current_user_id(),$result->get_error_message(),120);}wp_safe_redirect(add_query_arg(array('page'=>'adc-social-channels','saved'=>is_wp_error($result)?'0':'1'),admin_url('admin.php')));exit;});
		add_action('wp_enqueue_scripts',static function(){self::assets(false);},50);
		add_action('admin_enqueue_scripts',static function(){if('adc-social-channels'===($_GET['page']??'')){self::assets(true);}});
		add_action('customize_register',array(self::class,'customize'),30);
	}
	public static function assets(bool $admin):void {
		$file=$admin?'social-settings':'social-channels';wp_enqueue_style('adc-'.$file,plugins_url('assets/css/'.$file.'.css',ADC_FILE),array(),filemtime(ADC_PATH.'assets/css/'.$file.'.css'));wp_enqueue_script('adc-'.$file,plugins_url('assets/js/'.$file.'.js',ADC_FILE),array(),filemtime(ADC_PATH.'assets/js/'.$file.'.js'),true);
	}
	public static function preferences_control(string $link='', $value=null):void {
		$prefs=null===$value?self::preferences():self::validate_preferences($value);if(is_wp_error($prefs)){$prefs=self::preferences();}echo '<div class="adc-social-settings"><input type="hidden" data-social-preferences '.($link?:'name="preferences"').' value="'.esc_attr(wp_json_encode($prefs)).'"><label class="adc-social-style">'.esc_html(self::t('نمط الألوان','Color style')).'<select data-social-style><option value="theme" '.selected($prefs['style'],'theme',false).'>'.esc_html(self::t('ألوان الثيم','Theme colors')).'</option><option value="brand" '.selected($prefs['style'],'brand',false).'>'.esc_html(self::t('ألوان المنصات','Platform colors')).'</option></select></label><p>'.esc_html(self::t('رتّب بالسحب أو بأزرار التحريك. المنصة بلا رابط صالح لا تظهر، والتفعيل هنا لا يعطل واتساب الخاص بطلبات السيارات.','Drag to reorder or use the move buttons. Platforms without valid links stay hidden. These switches do not disable vehicle WhatsApp enquiries.')).'</p><div data-social-rows>';
		foreach($prefs['order']as$key){$flags=$prefs['channels'][$key];echo '<div class="adc-social-setting-row" data-social-key="'.$key.'" draggable="true"><div class="adc-social-setting-title">'.self::icon($key).'<strong>'.esc_html(self::label($key)).'</strong></div><div class="adc-social-setting-flags">';foreach(array('enabled'=>self::t('تفعيل','Enabled'),'footer'=>self::t('الفوتر','Footer'),'contact'=>self::t('صفحة التواصل','Contact page'))as$flag=>$label){echo '<label><input type="checkbox" data-social-flag="'.$flag.'" '.checked($flags[$flag],true,false).'> '.esc_html($label).'</label>';}echo '</div><div class="adc-social-setting-order"><button type="button" class="button" data-social-move="-1" aria-label="'.esc_attr(sprintf(self::t('تقديم %s','Move %s earlier'),self::label($key))).'">↑</button><button type="button" class="button" data-social-move="1" aria-label="'.esc_attr(sprintf(self::t('تأخير %s','Move %s later'),self::label($key))).'">↓</button></div></div>';}
		echo '</div><noscript><p>'.esc_html(self::t('فعّل JavaScript لتغيير الترتيب وخيارات الظهور. تبقى القيم الحالية محفوظة.','Enable JavaScript to change order and visibility. Existing preferences are preserved.')).'</p></noscript></div>';
	}
	public static function admin():void {
		if(!current_user_can('manage_options')){wp_die(esc_html(self::t('غير مسموح.','Access denied.')),'',array('response'=>403));}$settings=(array)get_option(self::URL_OPTION,array());echo '<div class="wrap adc-social-admin" dir="'.(Localization::language()==='en'?'ltr':'rtl').'"><h1>'.esc_html(self::t('حسابات التواصل الاجتماعي','Social media accounts')).'</h1><p>'.esc_html(self::t('الروابط نفسها مستخدمة في إعدادات المنصة والتخصيص. لا تظهر الحسابات الفارغة.','Manage account links, order and visibility here. Empty accounts stay hidden.')).' <a href="'.esc_url(add_query_arg('autofocus[section]','car_dealer_social',admin_url('customize.php'))).'">'.esc_html(self::t('فتح المعاينة في التخصيص','Preview in the Customizer')).'</a></p>';
		$error=get_transient('adc_social_error_'.get_current_user_id());if($error){delete_transient('adc_social_error_'.get_current_user_id());echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';}elseif('1'===($_GET['saved']??'')){echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(self::t('تم حفظ حسابات التواصل.','Social accounts saved.')).'</p></div>';}
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_save_social_channels"><input type="hidden" name="revision" value="'.esc_attr(self::revision()).'">';wp_nonce_field('adc_save_social_channels');echo '<div class="adc-social-admin-grid"><section class="adc-social-panel"><h2>'.esc_html(self::t('روابط الحسابات','Account links')).'</h2>';
		foreach(self::platforms()as$key=>$platform){echo '<label class="adc-social-url"><span>'.self::icon($key).esc_html(self::label($key)).'</span><input type="'.('whatsapp'===$key?'tel':'url').'" name="social_urls['.$key.']" dir="ltr" value="'.esc_attr($settings[$key]??('whatsapp'===$key?get_option('car_dealer_whatsapp',''):'')).'" placeholder="'.esc_attr('whatsapp'===$key?'9665XXXXXXXX':'https://'.$platform['hosts'][0].'/...').'"></label>';}
		echo '</section><section class="adc-social-panel"><h2>'.esc_html(self::t('الترتيب والظهور','Order and visibility')).'</h2>';self::preferences_control();echo '</section></div>';submit_button(self::t('حفظ التغييرات','Save changes'));echo '</form></div>';
	}
	public static function customize($manager):void {
		if(!$manager->get_section('car_dealer_social')){$manager->add_section('car_dealer_social',array('title'=>self::t('الشبكات الاجتماعية','Social media'),'priority'=>37));}
		foreach(self::platforms()as$key=>$platform){$id=self::URL_OPTION.'['.$key.']';if($manager->get_control($id)){$manager->remove_control($id);}if($manager->get_setting($id)){$manager->remove_setting($id);}$settings=(array)get_option(self::URL_OPTION,array());$manager->add_setting($id,array('type'=>'option','capability'=>'manage_options','default'=>$settings[$key]??'','transport'=>'refresh','sanitize_callback'=>static fn($value)=>self::validate_link($key,$value)));$manager->add_control($id,array('label'=>self::label($key),'section'=>'car_dealer_social','type'=>'whatsapp'===$key?'text':'url','description'=>'whatsapp'===$key?self::t('رقم دولي، مثال: 9665XXXXXXXX','International number, e.g. 9665XXXXXXXX'):''));}
		$manager->add_setting(self::OPTION,array('type'=>'option','default'=>wp_json_encode(self::defaults()),'capability'=>'manage_options','transport'=>'refresh','sanitize_callback'=>array(self::class,'sanitize_preferences')));
		require_once ADC_PATH.'src/Admin/SocialCustomizeControl.php';$manager->add_control(new \AutoDealership\Admin\SocialCustomizeControl($manager,self::OPTION,array('label'=>self::t('الترتيب والظهور والألوان','Order, visibility and colors'),'section'=>'car_dealer_social','settings'=>self::OPTION,'priority'=>100)));
	}
}
