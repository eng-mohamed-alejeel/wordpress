<?php
/** Display translations must never alter canonical imported inventory names. */
namespace AutoDealership\Database { final class Schema { public const VERSION='display-test';public static function table($name){return $name;} } }
namespace AutoDealership\Inventory { final class CatalogPresentation {public static string $language='en';public static function language(){return self::$language;}} }
namespace {
define('ABSPATH',__DIR__.'/');define('ARRAY_A','ARRAY_A');$GLOBALS['admin']=false;$GLOBALS['ajax']=false;$GLOBALS['meta']=array();$GLOBALS['post']=(object)array('post_title'=>'جيتور داشينج لكجري 1.6 سي سي 2025','post_content'=>'');
function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return $GLOBALS['ajax'];}function add_action(...$args){}function add_filter($hook,$callback,...$args){$GLOBALS['filters'][$hook]=$callback;}
function get_option($key){return 'adc_db_version'===$key?'display-test':false;}function get_post_type($id){return 'car';}function get_post($id){return $GLOBALS['post'];}function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$key]??'';}function strip_shortcodes($text){return $text;}function wp_strip_all_tags($text){return strip_tags($text);}
function __($text,$domain=''){static $catalog=null;if(null===$catalog){$catalog=require dirname(__DIR__).'/languages/ui.php';}return $catalog[\AutoDealership\Inventory\CatalogPresentation::language()][$text]??$text;}
$GLOBALS['wpdb']=new class {public function get_results(...$args){return array(array('id'=>1,'brand'=>'جيتور','model'=>'داشينج','trim_name'=>'لكجري 1.6 سي سي','model_year'=>2025,'public_post_id'=>77));}};
require dirname(__DIR__).'/src/Content/PublicEditorialTranslations.php';require dirname(__DIR__).'/src/Inventory/OnyxCatalogNames.php';
function check($ok,$label){if(!$ok){throw new \RuntimeException($label);}echo 'PASS '.$label."\n";}
\AutoDealership\Inventory\OnyxCatalogNames::boot();$title=$GLOBALS['filters']['the_title'];
$raw=\AutoDealership\Inventory\OnyxCatalogNames::for_post(77);
check('جيتور داشينج لكجري 1.6 سي سي 2025'===$raw,'Canonical inventory name retains original Arabic source');
check('Jetour Dashing Luxury 1.6 2025'===$title('Original',77),'English display composes reviewed vehicle vocabulary');
check($raw===\AutoDealership\Inventory\OnyxCatalogNames::for_post(77),'English display does not contaminate names used for stock synchronization');
$GLOBALS['meta']=array('_adc_title_en'=>'Approved editorial vehicle title','_adc_title_en_source_hash'=>hash('sha256',$GLOBALS['post']->post_title));
check('Approved editorial vehicle title'===$title('Original',77),'Approved English editorial title takes precedence over generated stock name');
$GLOBALS['meta']['_adc_title_en_source_hash']=hash('sha256','outdated source');
check('Jetour Dashing Luxury 1.6 2025'===$title('Original',77),'Outdated editorial copy falls back to reviewed live configuration');
$GLOBALS['admin']=true;check($raw===$title('Original',77),'Administration retains the canonical inventory name');
$GLOBALS['admin']=false;\AutoDealership\Inventory\CatalogPresentation::$language='ar';check($raw===$title('Original',77),'Arabic public name remains unchanged');
check('أحمد العميل'===__('أحمد العميل'),'Unknown customer-authored names remain unchanged');
echo "Catalog display localization passed (8 checks).\n";
}
