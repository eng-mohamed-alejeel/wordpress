<?php
/** Read-only WordPress identity regression checks. No mail or tenant settings writes. */
if ( PHP_SAPI !== 'cli' ) { exit(1); }
define('DISABLE_WP_CRON', true);
require dirname(__DIR__, 4) . '/wp-load.php';
use AutoDealership\Core\ProductSetup as Identity;
use AutoDealership\Content\SiteContent;
$checks = 0;
function identity_check($actual, $expected, string $label): void {
 global $checks;
 if ($actual !== $expected) { throw new RuntimeException($label . ': ' . var_export($actual,true)); }
 ++$checks; echo "PASS $label\n";
}
$profile = ['brand_ar'=>'معرض النخبة','brand_en'=>'Elite Motors','legal_ar'=>'شركة النخبة التجارية','legal_en'=>'Elite Trading LLC'];
$siteName = 'Independent Site';
$home = SiteContent::defaults();
add_filter('pre_option_adc_product_profile', static function() use (&$profile) { return $profile; });
add_filter('pre_option_blogname', static function() use (&$siteName) { return $siteName; });
add_filter('pre_option_adc_site_content', static function() use (&$home) { return $home; });
foreach (['ar'=>'معرض النخبة','en'=>'Elite Motors'] as $language=>$name) {
 $_GET['lang'] = $language;
 identity_check(Identity::brand($language),$name,'Bilingual identity '.$language);
 identity_check(car_dealer_brand_name(),$name,'Theme identity '.$language);
 identity_check(Identity::copy('AUTO BRANDS / أوتو براندز / AutoBrands',$language),"$name / $name / $name",'Legacy UI compatibility '.$language);
 identity_check(Identity::template('{site_name}', $language),$name,'Template '.$language);
 identity_check(do_shortcode('[adc_brand_name]'),esc_html($name),'Editor shortcode '.$language);
 identity_check(do_shortcode('[adc_legal_name]'),esc_html(Identity::legal_name($language)),'Legal shortcode '.$language);
 identity_check(car_dealer_text('لماذا {site_name}؟','Why {site_name}?'),$language==='ar'?"لماذا {$name}؟":"Why $name?",'UI template '.$language);
 identity_check(Identity::copy('https://example.com/other', $language),'https://example.com/other','Unrelated URL preserved '.$language);
 $home['home']['hero']['title_'.$language]=$language==='ar'?'سيارتك الجديدة تبدأ من أوتو براندز':'Your new vehicle starts with Auto Brands';
 identity_check(SiteContent::home_text('hero','title','fallback'),$language==='ar'?"سيارتك الجديدة تبدأ من $name":"Your new vehicle starts with $name",'Exact saved default '.$language);
 $home['home']['hero']['title_'.$language]='Custom أوتو براندز editorial';
 identity_check(SiteContent::home_text('hero','title','fallback'),'Custom أوتو براندز editorial','Custom editorial preserved '.$language);
 $home['home']['hero']['title_'.$language]='Welcome {site_name}';
 identity_check(SiteContent::home_text('hero','title','fallback'),'Welcome '.$name,'Saved token '.$language);
}
$profile=['brand_ar'=>'اسم عربي فقط'];
identity_check(Identity::brand('en'),'اسم عربي فقط','Missing English falls back to Arabic');
identity_check(Identity::legal_name('en'),'اسم عربي فقط','Missing legal falls back to brand');
$profile=['brand_en'=>'English only'];
identity_check(Identity::brand('ar'),'English only','Missing Arabic falls back to English');
$profile=[];
identity_check(Identity::brand('en'),'Independent Site','No profile uses WordPress title');
$profile=['brand_ar'=>'Auto Brands & Sons'];
identity_check(Identity::copy('Welcome Auto Brands','ar'),'Welcome Auto Brands & Sons','Inserted name is not recursively replaced');
$profile=['brand_ar'=>'معرض <النخبة> & شركاؤه'];
identity_check(do_shortcode('[adc_brand_name]'),esc_html($profile['brand_ar']),'Shortcode escapes special characters');
$profile=['brand_ar'=>str_repeat('معرض ',50)];
identity_check(Identity::template('{site_name}', 'ar'),$profile['brand_ar'],'Long identity preserved');
identity_check(is_wp_error(Identity::normalize(['profile'=>['legal_ar'=>'شركة مثال','legal_en'=>'Example LLC'] ])),false,'Legal fields accepted in portable settings');
identity_check(Identity::template('{unknown}', 'ar'),'{unknown}','Unknown tokens preserved');
$profile=['brand_ar'=>'Old name','brand_en'=>'Existing English'];
$captured = null;
$intercept = static function($value,$old) use (&$captured) { $captured=$value; return $old; };
add_filter('pre_update_option_adc_product_profile',$intercept,10,2);
do_action('update_option_blogname','Old name','Updated WordPress name');
identity_check($captured['brand_ar'] ?? null,'Updated WordPress name','WordPress title synchronizes identity');
identity_check($captured['brand_en'] ?? null,'Existing English','WordPress title preserves explicit English');
remove_filter('pre_update_option_adc_product_profile',$intercept,10);
echo "PASS identity checks: $checks; no persistent identity changes.\n";
