<?php
/** Local CLI acceptance. Restores the option even if an assertion fails. */
if(PHP_SAPI!=='cli')exit;
define('DISABLE_WP_CRON',true);
require dirname(__DIR__,4).'/wp-load.php';
use AutoDealership\Tools\FinanceConfiguration as Config;
use AutoDealership\Tools\LoanCalculator as Calculator;
if(untrailingslashit(get_option('siteurl'))!=='http://localhost/wordpress')throw new RuntimeException('Local site only.');
$count=0;function verify($ok,$message){global $count;if(!$ok)throw new RuntimeException($message);++$count;}
$old=get_option(Config::OPTION,false);$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);wp_set_current_user((int)$admins[0]);
try {
 $defaults=Config::defaults();foreach($defaults['providers'] as $id=>$provider){$clean=Config::validate_provider($provider);verify($clean['name']===$provider['name'],'Default provider validates: '.$id);}

 $probe=['sector'=>'government','employer'=>'approved','nationality'=>'saudi','transfer'=>'st','category'=>'A','chinese'=>'no','months'=>60,'salary'=>8000];
 $program=Config::blank_program();$program['values']['rate']=2;$program['values']['down']=0;
 $program['rules']=[['when'=>['salary_min'=>8000,'salary_max'=>10000],'values'=>['rate'=>3],'verify'=>false,'notes'=>['','']],['when'=>['transfer'=>'st'],'values'=>['rate'=>4,'down'=>5],'verify'=>false,'notes'=>['','']]];
 $resolved=Config::resolve($program,$probe);verify($resolved['values']['rate']===4&&$resolved['values']['down']===5,'Later matched values win independently');
 $program['rules']=array_reverse($program['rules']);$resolved=Config::resolve($program,$probe);verify($resolved['values']['rate']===3&&$resolved['values']['down']===5,'Reordering rules changes precedence without clearing other values');
 foreach([[7999.99,false],[8000,true],[10000,true],[10000.01,false]] as [$salary,$expected]){$probe['salary']=$salary;verify(Config::matches(['salary_min'=>8000,'salary_max'=>10000],$probe)===$expected,'Salary interval boundary '.$salary);}
 foreach(['A','B','C','D'] as $category){$probe['category']=$category;verify(Config::matches(['category'=>$category],$probe),'Category condition '.$category);}
 $probe['sector']='private_unapproved';$probe['employer']='approved';verify(Config::matches(['employer'=>'unapproved'],$probe),'Non-approved private sector overrides employer approval selection');
 $config=Config::get();$revision=Config::revision();
 $provider=['name'=>['جهة اختبار الحاسبة','Calculator test provider'],'active'=>true,'position'=>1,'page'=>0,'source'=>'Acceptance test','programs'=>array_fill_keys(['regular','half','leasing'],Config::blank_program())];
 $p=&$provider['programs']['regular'];$p['active']=true;$p['months']=[12,24,36,48,60];$p['values']=['rate'=>2,'down'=>10,'balloon'=>20,'admin'=>1,'min_salary'=>3000,'rebate'=>6];$p['rules']=[['when'=>['nationality'=>'expat','transfer'=>'nst','salary_min'=>8000,'category'=>'C','chinese'=>'yes','months'=>'36'],'values'=>['rate'=>4,'down'=>15,'balloon'=>25],'verify'=>false,'notes'=>['ملاحظة اختبار','Test note']]];
 $config['providers']['acceptance']=Config::validate_provider($provider);
 verify(Config::save($config,$revision)===true,'Add provider persisted');
 $result=Calculator::calculate(['price'=>'100000','salary'=>'10000','provider'=>'acceptance','nationality'=>'expat','transfer'=>'nst','category'=>'C','chinese'=>'yes','months'=>36]);$q=$result['results'][0];
 verify($q['annual_rate']===4.0&&$q['down_payment']===15000&&$q['balloon_payment']===25000,'New provider conditions drive calculation');verify(abs($q['admin_fees']-850)<.001,'Percentage fee uses financed amount');verify($q['status']==='eligible','Complete configured provider is eligible');

 $bound=Config::get();$boundRevision=Config::revision();$bound['providers']['acceptance']['programs']['regular']['price_min']=80000.0;$bound['providers']['acceptance']['programs']['regular']['price_strict']=true;
 verify(Config::save($bound,$boundRevision)===true,'Saved decimal price threshold');
 foreach(['79999.99'=>'ineligible','80000'=>'ineligible','80000.01'=>'eligible'] as $price=>$expected){$r=Calculator::calculate(['price'=>(string)$price,'salary'=>'10000','provider'=>'acceptance']);verify($r['results'][0]['status']===$expected,'Strict decimal price threshold at '.$price);}
 $boundRevision=Config::revision();$bound['providers']['acceptance']['programs']['regular']['price_strict']=false;verify(Config::save($bound,$boundRevision)===true,'Inclusive threshold saved');
 verify(Calculator::calculate(['price'=>'80000','salary'=>'10000','provider'=>'acceptance'])['results'][0]['status']==='eligible','Inclusive decimal price threshold accepts equality');

 $boundRevision=Config::revision();$bound['providers']['acceptance']['programs']['regular']['price_min']=0.29;$bound['providers']['acceptance']['programs']['regular']['price_strict']=true;verify(Config::save($bound,$boundRevision)===true,'Fractional halala boundary saved');
 verify(Calculator::calculate(['price'=>'0.29','salary'=>'10000','provider'=>'acceptance'])['results'][0]['status']==='ineligible','Strict 0.29 threshold does not suffer float equality error');
 $boundRevision=Config::revision();$bound['providers']['acceptance']['programs']['regular']['price_strict']=false;$bound['providers']['acceptance']['programs']['regular']['values']['min_salary']=3500.01;verify(Config::save($bound,$boundRevision)===true,'Decimal minimum salary saved');
 verify(Calculator::calculate(['price'=>'0.29','salary'=>'3500.01','provider'=>'acceptance'])['results'][0]['status']==='eligible','Inclusive fractional price and salary equality accepted');
 verify(is_wp_error(Config::save($config,$revision)),'Stale revision rejected');
 $current=Config::get();$revision=Config::revision();$current['providers']['acceptance']['programs']['regular']['admin_mode']='fixed';$current['providers']['acceptance']['programs']['regular']['values']['admin']=1200;$current['providers']['acceptance']['programs']['regular']['admin_cap']=900;
 verify(Config::save($current,$revision)===true,'Edit provider saved');$q=Calculator::calculate(['price'=>'100000','salary'=>'10000','provider'=>'acceptance'])['results'][0];verify($q['admin_fees']===900,'Fixed fee and cap apply');
 $current=Config::get();$revision=Config::revision();$current['settings']['insurance_min']=1;$current['settings']['insurance_max']=6;$current['settings']['insurance_default']=2;$current['settings']['debt_limit']=10;verify(Config::save($current,$revision)===true,'Global settings persisted');
 $q=Calculator::calculate(['price'=>'100000','salary'=>'10000','provider'=>'acceptance','insurance_rate'=>5])['results'][0];verify($q['status']==='ineligible','Configured debt limit applied');verify(abs($q['insurance_monthly']-416.67)<.01,'Configured insurance bounds applied');verify(Calculator::view_model()['settings']['insurance_default']===2,'Frontend model uses configured insurance');
 $current=Config::get();$revision=Config::revision();$current['providers']['acceptance']['active']=false;verify(Config::save($current,$revision)===true,'Disable provider');verify(!isset(Config::providers()['acceptance']),'Disabled provider removed from public list');verify(is_wp_error(Calculator::calculate(['price'=>100000,'salary'=>10000,'provider'=>'acceptance'])),'Disabled provider cannot be submitted');
 $current=Config::get();$revision=Config::revision();unset($current['providers']['acceptance']);verify(Config::save($current,$revision)===true,'Delete provider persisted');verify(!isset(Config::get()['providers']['acceptance']),'Deleted provider does not reappear');
 $revision=Config::revision();$empty=Config::get();$empty['providers']=[];verify(Config::save($empty,$revision)===true,'Empty provider configuration saved');verify(Config::providers()===[],'Deleting all does not restore defaults');
 wp_set_current_user(0);verify(is_wp_error(Config::save($defaults,Config::revision())),'Anonymous changes rejected');wp_set_current_user((int)$admins[0]);
 foreach([['rate'=>101],['down'=>-1],['balloon'=>['bad']],['down'=>80,'balloon'=>30]] as $bad){$v=$provider;foreach($bad as $field=>$value)$v['programs']['regular']['values'][$field]=$value;$rejected=false;try{Config::validate_provider($v);}catch(Throwable $e){$rejected=true;}verify($rejected,'Invalid provider numeric values rejected');}
 $badSettings=$defaults['settings'];$badSettings['insurance_default']=8;$rejected=false;try{Config::validate_settings($badSettings);}catch(Throwable $e){$rejected=true;}verify($rejected,'Invalid insurance settings rejected');
 echo "MANAGED FINANCE PASS: $count checks; original option restored.\n";
}finally{if($old===false)delete_option(Config::OPTION);else update_option(Config::OPTION,$old,false);}
