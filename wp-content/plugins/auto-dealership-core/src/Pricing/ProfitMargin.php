<?php
namespace AutoDealership\Pricing;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
defined('ABSPATH') || exit;

/** Global display pricing policy. Source prices and issued financial documents stay immutable. */
final class ProfitMargin {
 public const OPTION='adc_profit_margin_policy';
 public static function policy():array { $p=get_option(self::OPTION,array());return array('type'=>$p['type']??'none','value'=>(int)($p['value']??0),'stage'=>$p['stage']??'before_tax','revision'=>(int)($p['revision']??0)); }
 public static function ratio(int $amount,int $rate):int {
  if($amount<0||$rate<0||$rate>100000){throw new \InvalidArgumentException('Invalid margin amount.');}
  $major=intdiv($amount,10000);$rest=intdiv(($amount%10000)*$rate+5000,10000);
  if($rate&&$major>intdiv(PHP_INT_MAX-$rest,$rate)){throw new \OverflowException('Margin exceeds amount limit.');}
  return $major*$rate+$rest;
 }
 public static function amount(int $base,?array $policy=null):int { $p=$policy??self::policy();return $p['type']==='fixed'?(int)$p['value']:($p['type']==='percentage'?self::ratio($base,(int)$p['value']):0); }
 public static function save(array $input) {
  if(!current_user_can('manage_options')){return new \WP_Error('forbidden','Forbidden');}
  $type=$input['type']??'';$stage=$input['stage']??'';$value=Money::from_sar($input['value']??'0');$revision=Money::parse($input['revision']??null);
  if(!in_array($type,array('none','fixed','percentage'),true)||!in_array($stage,array('before_tax','after_tax'),true)||$value===null||$revision===null||($type==='percentage'&&$value>100000)){return new \WP_Error('invalid_margin','Invalid margin policy.');}
  global $wpdb;
  if($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->options))!=='InnoDB'||!Transaction::begin()){return new \WP_Error('margin_storage','Margin storage is unavailable.');}
  try {
   $default=maybe_serialize(array('type'=>'none','value'=>0,'stage'=>'before_tax','revision'=>0));
   if(false===$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options}(option_name,option_value,autoload) VALUES(%s,%s,'off') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)",self::OPTION,$default))){throw new \RuntimeException('Unable to save margin.');}
   $raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",self::OPTION));$before=maybe_unserialize($raw);
   if(!is_array($before)||($before['revision']??0)!==$revision){throw new \RuntimeException('Margin changed. Reload before saving.');}
   $after=array('type'=>$type,'value'=>$type==='none'?0:$value,'stage'=>$stage,'revision'=>$revision+1);
   if(1!==$wpdb->update($wpdb->options,array('option_value'=>maybe_serialize($after)),array('option_name'=>self::OPTION))||!Transaction::commit(static fn()=>AuditLog::record('pricing.margin_updated','settings',0,'Public vehicle margin policy updated',$before,$after))){throw new \RuntimeException('Unable to save and audit margin.');}
   wp_cache_delete(self::OPTION,'options');wp_cache_delete('notoptions','options');wp_cache_delete('alloptions','options');
   if(function_exists('wp_cache_clear_cache')){wp_cache_clear_cache();}
   return $after;
  }catch(\Throwable $e){$wpdb->query('ROLLBACK');wp_cache_delete(self::OPTION,'options');return new \WP_Error('margin_failed',$e->getMessage());}
 }
}
