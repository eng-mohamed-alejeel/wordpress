<?php
if(PHP_SAPI!=='cli'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
use AutoDealership\Pricing\ProfitMargin as Margin;
use AutoDealership\Pricing\PublicVehiclePrice as Price;
global $wpdb;
$margin_checks=0;$margin_assert=static function($ok,$label)use(&$margin_checks){if(!$ok){throw new RuntimeException($label);}++$margin_checks;echo "PASS $label\n";};
$admin=(int)get_user_by('login','verify_admin')->ID;wp_set_current_user($admin);
$margin_original_vat=get_option('adc_vat_rate_bps',0);update_option('adc_vat_rate_bps',1500);
$car=wp_insert_post(array('post_type'=>'car','post_status'=>'draft','post_title'=>'Margin fixture'));
update_post_meta($car,'_car_plate_type','private_transport');
$save=static function($type,$stage,$value){return Margin::save(array('type'=>$type,'stage'=>$stage,'value'=>$value,'revision'=>(string)Margin::policy()['revision']));};
foreach(array(array('fixed','before_tax','1500',9462500),array('fixed','after_tax','1500',9440000),array('percentage','before_tax','5',9750000),array('percentage','after_tax','5',9750000),array('none','before_tax','0',9290000))as$case){
 list($type,$stage,$value,$expected)=$case;
 $margin_assert(is_array($save($type,$stage,$value)),"Audited margin policy saves $type $stage");
 $amount=Price::calculate(8000000,$car);
 $margin_assert($amount['final_amount']===$expected,"Margin, VAT and private plates calculate $type $stage");
 foreach(array(0,1,101,12345,8000000)as$net){$sql=Price::sql((string)$net,(string)$car);$result=$wpdb->get_var('SELECT '.$sql);$margin_assert(!$wpdb->last_error&&(int)$result===Price::calculate($net,$car)['final_amount'],"SQL filtering agrees with displayed rounding $type $stage net $net");}
}
$margin_assert(is_wp_error(Margin::save(array('type'=>'fixed','stage'=>'before_tax','value'=>'100','revision'=>'0'))),'Stale policy form cannot overwrite the current policy');
$margin_assert(is_wp_error($save('fixed','before_tax','-5'))&&is_wp_error($save('percentage','after_tax','1000.01')),'Invalid policy amounts are rejected');
$before=Margin::policy();$fault=static fn($sql)=>str_contains($sql,'INSERT INTO `'.\AutoDealership\Database\Schema::table('audit_events').'`')?'INSERT INTO `'.\AutoDealership\Database\Schema::table('audit_events').'` (missing_column) VALUES(1)':$sql;
add_filter('query',$fault);$failed=$save('fixed','after_tax','999');remove_filter('query',$fault);
$margin_assert(is_wp_error($failed)&&Margin::policy()===$before,'Audit failure rolls margin policy back completely');
wp_set_current_user(wp_insert_user(array('user_login'=>'margin_subscriber','user_pass'=>wp_generate_password(),'role'=>'subscriber')));
$margin_assert(is_wp_error($save('fixed','before_tax','500')),'Unauthorized user cannot change vehicle margin');
wp_set_current_user($admin);$save('none','before_tax','0');
update_option('adc_vat_rate_bps',$margin_original_vat);
echo 'Profit margin checks passed: '.$margin_checks."\n";
