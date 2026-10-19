<?php
/** CLI read-only social settings, migration and sharing contracts. */
if(PHP_SAPI!=='cli'){exit;}define('DISABLE_WP_CRON',true);require dirname(__DIR__,4).'/wp-load.php';
use AutoDealership\Core\SocialChannels as S;
$checks=0;$check=static function($ok,$message)use(&$checks){if(!$ok){throw new RuntimeException($message);}++$checks;echo "PASS $message\n";};
$prefs=S::defaults();$settings=array('phone'=>'0500000000','address'=>'Existing address','unknown_future_setting'=>'keep');
foreach(S::platforms()as$key=>$row){$settings[$key]=$key==='whatsapp'?'966500000000':'https://'.$row['hosts'][0].'/example';}
$settings_filter=static function()use(&$settings){return $settings;};$prefs_filter=static function()use(&$prefs){return wp_json_encode($prefs);};$old_get=$_GET;
add_filter('pre_option_'.S::URL_OPTION,$settings_filter);add_filter('pre_option_'.S::OPTION,$prefs_filter);
try{
 if(($argv[1]??'')==='--fixtures'){$out=array();foreach(array('ar','en')as$lang){$_GET['lang']=$lang;foreach(array('theme','brand')as$style){$prefs['style']=$style;$out[$lang][$style]=array('footer'=>S::render('footer'),'contact'=>S::render('contact'),'share'=>S::share('http://localhost/wordpress/cars/example/?lang='.$lang,'Vehicle & offer')) ;}}echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
 $check(count(S::platforms())===8,'Eight platform identities available');
 foreach($settings as$key=>$url){if(!isset(S::platforms()[$key]))continue;$valid=S::validate_link($key,$url);$check(is_string($valid)&&$valid!=='','Valid platform setting accepted: '.$key);$svg=S::icon($key);$check(str_contains($svg,'aria-hidden="true"')&&!str_contains($svg,'<script'),'Trusted decorative SVG: '.$key);}
 foreach(array(array('facebook','javascript:alert(1)'),array('instagram','https://instagram.com.evil.example/a'),array('x','https://x.com@evil.example/a'),array('facebook','https://user:pass@facebook.com/a'),array('linkedin','https://linkedin.com:444/a'),array('tiktok',array('bad')),array('youtube','file:///tmp/a'),array('whatsapp','123'),array('whatsapp','<script>966500000000'))as[$key,$bad]){$check(is_wp_error(S::validate_link($key,$bad)),'Reject unsafe or malformed URL: '.$key);}
 $check(S::validate_link('x','http://twitter.com/example')==='https://twitter.com/example','Legacy Twitter account remains compatible and upgrades to HTTPS');
 $check(S::phone('0538722576')==='966538722576'&&S::phone('+966 53 872 2576')==='966538722576','Saudi and international WhatsApp numbers normalized');
 $check(count(S::links('footer'))===8&&count(S::links('contact'))===8,'Both contexts use the same valid account source');
 $prefs['channels']['facebook']['enabled']=false;$prefs['channels']['instagram']['footer']=false;$check(!isset(S::links('footer')['facebook'],S::links('footer')['instagram'])&&isset(S::links('contact')['instagram']),'Global and contextual visibility flags respected');
 $prefs=S::defaults();$prefs['order']=array_reverse($prefs['order']);$check(array_keys(S::links('footer'))===$prefs['order'],'Custom order used consistently');
 $settings['youtube']='';$check(!isset(S::links('footer')['youtube']),'Empty platforms never create blank controls');$settings['youtube']='https://evil.example/account';$check(!isset(S::links('footer')['youtube']),'Invalid historical links are hidden safely');
 foreach(array('ar','en')as$lang){$_GET['lang']=$lang;$html=S::render('footer');$check(str_contains($html,'rel="noopener noreferrer"')&&str_contains($html,'aria-label=')&&str_contains($html,'<svg'),'Accessible localized public icons '.$lang);$check(S::label('facebook')===($lang==='ar'?'فيسبوك':'Facebook'),'Platform names translated '.$lang);}
 $invalid=S::defaults();$invalid['order'][0]='instagram';$check(is_wp_error(S::validate_preferences($invalid)),'Duplicate order cannot drop another platform');$invalid=S::defaults();unset($invalid['channels']['x']);$check(is_wp_error(S::validate_preferences($invalid)),'Truncated preferences cannot remove saved channels');$invalid=S::defaults();$invalid['channels']['x']['enabled']='false';$check(is_wp_error(S::validate_preferences($invalid)),'Visibility uses strict booleans');
 $clean=car_dealer_sanitize_theme_settings(array('address'=>'Updated'));$check($clean['facebook']===$settings['facebook']&&$clean['unknown_future_setting']==='keep'&&$clean['phone']===$settings['phone'],'Partial legacy theme saves preserve account URLs and unowned settings');
 $url='https://example.test/cars/سيارة/?lang=ar&trim=A%26B';$title='سيارة & عرض';$html=S::share($url,$title);preg_match('~href="([^"]*wa.me[^\"]*)"~',$html,$match);parse_str(wp_parse_url(html_entity_decode($match[1]),PHP_URL_QUERY),$query);$check($query['text']===$title.' '.esc_url_raw($url,array('http','https')),'Share title and URL encoded exactly once, preserving language and parameters');
 $check(S::share('javascript:alert(1)','Unsafe')==='','Unsafe share URL is rejected');$check(str_contains(S::share('https://example.test','Offer','offer'),'Share this offer'),'Offers get their own sharing label');
 echo "Completed $checks read-only social checks.\n";
}finally{$_GET=$old_get;remove_filter('pre_option_'.S::URL_OPTION,$settings_filter);remove_filter('pre_option_'.S::OPTION,$prefs_filter);}
