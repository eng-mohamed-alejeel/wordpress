<?php
/** Isolated option store: no WordPress bootstrap and no operational data writes. */
namespace AutoDealership\Audit {
 final class AuditLog { public static bool $fail = false; public static function record(...$args): bool { return ! self::$fail; } }
}
namespace AutoDealership\Inventory {
 final class PublicCatalog {
  public const MODE_COMPATIBILITY = 'compatibility'; public const MODE_AUTHORITATIVE = 'authoritative';
  public static function mode(): string { return \get_option('adc_public_catalog_mode', 'compatibility'); }
  public static function readiness(): array { return array('ready'=>true); }
 }
 final class VehicleService { public static function branch_exists(int $id): bool { return 3 === $id; } }
}
namespace {
 define('ABSPATH', __DIR__); $options = array(); $allowed = true;
 class WP_Error { public function __construct(public string $code, ...$args) {} }
 function current_user_can($cap): bool { return $GLOBALS['allowed']; }
 function __($value, ...$args) { return $value; }
 function get_option($key, $default=false) { return $GLOBALS['options'][$key] ?? $default; }
 function update_option($key,$value,...$args) { $GLOBALS['options'][$key]=$value; }
 function delete_option($key) { unset($GLOBALS['options'][$key]); }
 function absint($value) { return abs((int)$value); }
 function sanitize_key($value) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/','',$value)); }
 function sanitize_text_field($value) { return trim(strip_tags($value)); }
 function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
 function get_bloginfo($key) { return 'Test seller'; }
 function check($value,$message) { if(!$value)throw new \RuntimeException($message); echo "PASS $message\n"; }
 require dirname(__DIR__).'/src/Core/ConfigurationService.php';
 require dirname(__DIR__).'/src/Admin/SettingsPage.php';
 use AutoDealership\Core\ConfigurationService as Config;
 $reflection = new \ReflectionMethod(Config::class, 'values');
 $options = array_replace($reflection->invoke(null), array(
  'adc_vat_rate_bps'=>1500, 'adc_pricing_fee_amount'=>4321,
  'adc_promotion_type'=>'fixed', 'adc_promotion_code'=>'season', 'adc_promotion_value'=>2345,
  'adc_promotion_starts_at'=>'2026-10-01','adc_promotion_ends_at'=>'2026-12-01',
  'adc_reservation_hours'=>36, 'adc_reservation_deposit_type'=>'percentage', 'adc_reservation_deposit_value'=>1500,
  'adc_sales_manager_discount_limit'=>20000,'adc_general_manager_discount_limit'=>40000,
  'adc_seller_name'=>'Preserved seller','adc_seller_tax_number'=>'123','adc_seller_phone'=>'555','adc_seller_address'=>'Address',
  'adc_default_branch_id'=>3,'adc_privacy_retention_days'=>365,'adc_delivery_required_documents'=>array('invoice','insurance'),
 ));
 $cases = array('pricing'=>array('vat_rate_bps'=>1000),'reservations'=>array('reservation_hours'=>48),'branches'=>array('default_branch_id'=>0),'delivery'=>array('delivery_required_documents'=>array()),'privacy'=>array('privacy_retention_days'=>90),'catalog'=>array('public_catalog_mode'=>'authoritative'));
 foreach ($cases as $section=>$input) {
  $before=$options;
  $input['not_a_setting']='ignore';
  if($section!=='pricing')$input['vat_rate_bps']=9999;
  $fields=\AutoDealership\Admin\SettingsPage::sections()[$section][1];
  $result=Config::update_section($input,$fields);
  check(!($result instanceof WP_Error), "$section section saves");
  foreach($before as $key=>$value) { if(!in_array(substr($key,4),$fields,true))check($options[$key]===$value,"$section preserves $key"); }
 }
 $before=$options; \AutoDealership\Audit\AuditLog::$fail=true;
 $result=Config::update_section(array('reservation_hours'=>72),array('reservation_hours'));
 check($result instanceof WP_Error && $options===$before,'Audit failure restores all options');
 \AutoDealership\Audit\AuditLog::$fail=false; $allowed=false;
 $result=Config::update_section(array('reservation_hours'=>72),array('reservation_hours'));
 check($result instanceof WP_Error && $options===$before,'Unauthorized section save leaves options untouched');
 echo "Dashboard settings isolation passed.\n";
}
