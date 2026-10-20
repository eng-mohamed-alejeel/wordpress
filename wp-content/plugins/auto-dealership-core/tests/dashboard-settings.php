<?php
/** Isolated option store: no WordPress bootstrap and no operational data writes. */
namespace AutoDealership\Audit {
 final class AuditLog { public static bool $fail = false; public static function record(...$args): bool { return ! self::$fail; } }
}
namespace AutoDealership\Inventory {
 final class PublicCatalog {
  public const MODE_COMPATIBILITY = 'compatibility'; public const MODE_AUTHORITATIVE = 'authoritative'; public const MODE_MODELS = 'models';
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
 // Initialize through the public contract; do not depend on private defaults via Reflection.
 $seed = Config::update(array(
  'vat_rate_bps'=>1500, 'pricing_fee_amount'=>4321,
  'promotion_type'=>'fixed', 'promotion_code'=>'season', 'promotion_value'=>2345,
  'promotion_starts_at'=>'2026-10-01','promotion_ends_at'=>'2026-12-01',
  'reservation_hours'=>36, 'reservation_deposit_type'=>'percentage', 'reservation_deposit_value'=>1500,
  'sales_manager_discount_limit'=>20000,'general_manager_discount_limit'=>40000,
  'seller_name'=>'Preserved seller','seller_tax_number'=>'123','seller_phone'=>'555','seller_address'=>'Address',
  'default_branch_id'=>3,'privacy_retention_days'=>365,'delivery_required_documents'=>array('invoice','insurance'),
 ));
 check(is_array($seed) && true === $seed['updated'], 'Public settings fixture initializes successfully');
 $cases = array('pricing'=>array('vat_rate_bps'=>1000),'reservations'=>array('reservation_hours'=>48),'branches'=>array('default_branch_id'=>0),'delivery'=>array('delivery_required_documents'=>array()),'privacy'=>array('privacy_retention_days'=>90),'catalog'=>array('public_catalog_mode'=>'authoritative'));
 foreach ($cases as $section=>$input) {
  $before=$options;
  $input['not_a_setting']='ignore';
  if($section!=='pricing')$input['vat_rate_bps']=9999;
  $fields=\AutoDealership\Admin\SettingsPage::sections()[$section][1];
  $result=Config::update_section($input,$fields);
  check(!($result instanceof WP_Error), "$section section saves");
  foreach($input as $key=>$value) { if(in_array($key,$fields,true))check($options['adc_'.$key]===$value,"$section updates $key"); }
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
