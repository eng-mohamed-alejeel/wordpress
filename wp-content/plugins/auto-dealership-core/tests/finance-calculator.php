<?php
/** Standalone regression suite: no WordPress database or customer records are changed. */
namespace AutoDealership\Core { final class Localization { public static string $lang='ar'; public static function language(): string { return self::$lang; } } }
namespace {
 define('ABSPATH',__DIR__.'/');
 class WP_Error { public function __construct(public string $code,public string $message,public array $data=[]) {} }
 require dirname(__DIR__).'/src/Tools/FinanceConfiguration.php';
 require dirname(__DIR__).'/src/Tools/LoanCalculator.php';
 use AutoDealership\Tools\LoanCalculator as C;
 set_error_handler(static function($severity,$message,$file,$line){throw new \ErrorException($message,0,$severity,$file,$line);});
 $count=0;
 function check($ok,$message) { global $count; if(!$ok) throw new \RuntimeException($message); ++$count; }
 function quote(array $overrides=[]) { $result=C::calculate(array_merge(['price'=>'100000','salary'=>'10000','provider'=>'alrajhi'],$overrides));check(is_array($result),'Valid result');return $result['results'][0]; }
 $q=quote();check($q['profit']===15450,'Flat profit: 100000 × 3.09% × 5');check($q['monthly_payment']===1340.83,'Balloon and insurance monthly');check($q['total_payable']===130450,'Total includes balloon exactly once');check($q['status']==='incomplete','Unpublished minimum salary is not invented');check(!$q['lowest_payment'],'Incomplete rows cannot win badges');
 $q=quote(['category'=>'D','transfer'=>'nst']);check($q['down_payment']===5000,'D NST down 5%');check($q['balloon_payment']===35000,'D balloon 35%');check($q['profit']===15817.5,'Profit uses financed principal including balloon');
 $q=quote(['campaign'=>'half','months'=>'24']);check($q['profit']===0,'Rajhi 50/50 zero profit');check($q['base_monthly']===0.0,'50/50 principal deferred');check($q['monthly_payment']===250.0,'50/50 still includes insurance');check($q['total_payable']===106000,'50/50 total includes both halves and insurance');
 $q=quote(['provider'=>'albilad']);check($q['annual_rate']===2.67,'Bilad approved monthly corrected');check($q['balloon_percent']===40,'Bilad monthly balloon corrected');
 $q=quote(['provider'=>'albilad','transfer'=>'nst']);check($q['annual_rate']===2.67,'Bilad approval determines rate, not transfer');
 $q=quote(['provider'=>'albilad','sector'=>'private_unapproved']);check($q['annual_rate']===3.64,'Bilad unapproved corrected');
 $q=quote(['provider'=>'albilad','campaign'=>'half','months'=>'24','sector'=>'private_unapproved']);check($q['annual_rate']===1.0,'Bilad 50/50 corrected');check($q['balloon_percent']===50,'Bilad 50/50 balloon corrected');
 $q=quote(['provider'=>'albilad','campaign'=>'half','months'=>'24','price'=>'80000']);check($q['status']==='ineligible','Bilad 50/50 above 80k strict boundary');
 $q=quote(['provider'=>'jb','salary'=>'9999','nationality'=>'expat']);check($q['annual_rate']===5.90,'JB lower salary expat');
 $q=quote(['provider'=>'jb','salary'=>'10000','nationality'=>'expat','chinese'=>'yes','category'=>'B']);check($q['annual_rate']===5.40,'JB higher salary corrected');check($q['down_payment']===5000,'JB expat down');check($q['balloon_percent']===35,'Chinese is independent of Rajhi category');check($q['monthly_payment']===1677.5,'JB financed amount and profit verified');
 $q=quote(['provider'=>'riyad','transfer'=>'nst']);check($q['annual_rate']===2.49,'Riyad high salary');check($q['balloon_percent']===40,'Riyad NST cap corrected');check($q['monthly_payment']===1457.5,'Riyad NST independent example');check($q['lowest_payment'],'Selected complete provider can receive badge');
 $q=quote(['provider'=>'riyad','salary'=>'8000']);check($q['status']==='incomplete','Riyad salary 8000 source ambiguity');
 $q=quote(['provider'=>'riyad','salary'=>'7000']);check($q['annual_rate']===2.75,'Riyad lower band');
 $q=quote(['provider'=>'raya','salary'=>'3499']);check($q['status']==='ineligible','Minimum salary checked');check(!$q['lowest_payment'],'Ineligible cannot win');
 $q=quote(['provider'=>'raya','salary'=>'10000','obligations'=>'4000']);check($q['status']==='ineligible','Existing obligations included');
 $q=quote(['provider'=>'raya','sector'=>'self_employed']);check($q['status']==='ineligible','Raya sector matrix exclusion');
 $q=quote(['provider'=>'snb']);check($q['monthly_payment']===null,'Missing balloon/down does not silently become zero');
 $q=quote(['provider'=>'snb','assumed_down'=>'0','assumed_balloon'=>'40']);check($q['monthly_payment']===1558.33,'SNB explicitly assumed balloon');check($q['status']==='incomplete','Assumptions excluded from eligibility badges');
 $q=quote(['provider'=>'alinma','assumed_down'=>'0','assumed_balloon'=>'40','assumed_admin'=>'1000']);check($q['admin_fees']===1000,'Scheduled fee accepted as explicit scenario');check($q['status']==='incomplete','Scenario is labelled incomplete');
 $q=quote(['provider'=>'enbd','nationality'=>'expat','transfer'=>'nst','assumed_down'=>'0','assumed_balloon'=>'0']);check($q['annual_rate']===3.25,'ENBD nationality and transfer');check($q['admin_fees']===1000,'ENBD fee on principal');
 $q=quote(['provider'=>'anb','sector'=>'private_unapproved']);check($q['annual_rate']===4.15,'ANB Non-ALE');
 $q=quote(['provider'=>'aloula','chinese'=>'yes']);check($q['down_percent']===5&&$q['balloon_percent']===25,'Aloula Chinese terms');check($q['annual_rate']===7.5,'Dealer support not double deducted');
 $q=quote(['provider'=>'emkan','sector'=>'retired','salary'=>'1899','brand'=>'toyota','assumed_admin'=>'0']);check($q['status']==='ineligible','Emkan retired salary minimum');
 $q=quote(['provider'=>'emkan','sector'=>'military','brand'=>'toyota']);check($q['down_percent']===5,'Military down payment');
 $q=quote(['provider'=>'emkan','brand'=>'ferrari']);check($q['status']==='ineligible','Emkan brand restriction');
 foreach(['0.01','0.03','1.01','100000.01'] as $fractional){$q=quote(['price'=>$fractional,'campaign'=>'half','months'=>'24']);check(round($q['down_payment']+$q['balloon_payment'],2)===(float)$fractional,'50/50 halves balance exactly for '.$fractional);check($q['base_monthly']>=0,'No negative installment from halala rounding');}
 foreach([12,24,36,48] as $months)check(quote(['months'=>$months])['status']==='unavailable','No invented rates for unpublished tenure');
 $all=C::calculate(['price'=>'100000.50','salary'=>'10000','provider'=>'all']);check(count($all['results'])===11,'All 11 providers compared');check($all['input']['price']===100000.5,'Halala precision and VAT not reapplied');
 $base=['price'=>'100000','salary'=>'10000'];
 foreach([['price'=>-1],['price'=>'NaN'],['price'=>'1e5'],['price'=>['100000']],['salary'=>0],['salary'=>[]],['insurance_rate'=>'2.99'],['insurance_rate'=>'4.51'],['months'=>'13'],['provider'=>'bad'],['assumed_admin'=>[]],['assumed_balloon'=>'101'],['brand'=>[]],['category'=>'E']] as $bad)check(C::calculate(array_merge($base,$bad)) instanceof WP_Error,'Malformed input rejected');
 foreach(array_keys(C::rules()) as $provider)foreach(['st','nst'] as $transfer)foreach(['saudi','expat'] as $nationality)foreach(['A','B','C','D'] as $category)foreach(['regular','half','leasing'] as $campaign)foreach(['government','military','private_unapproved','retired','self_employed'] as $sector) {
  $r=C::calculate(array_merge($base,compact('provider','transfer','nationality','category','campaign','sector'),['months'=>$campaign==='half'?24:60,'chinese'=>'yes','assumed_down'=>'0','assumed_balloon'=>'40','assumed_admin'=>'100']));
  check(is_array($r),'Matrix branch has no warnings');$q=$r['results'][0];if($q['monthly_payment']!==null)check($q['monthly_payment']>=0&&is_finite($q['monthly_payment']),'Finite nonnegative payment');
 }
 \AutoDealership\Core\Localization::$lang='en';check(quote(['provider'=>'riyad'])['name']==='Riyad Bank','English results');
 echo "FINANCE PASS: $count assertions.\n";
}
