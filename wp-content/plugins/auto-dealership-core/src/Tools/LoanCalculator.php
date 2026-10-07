<?php
namespace AutoDealership\Tools;
defined('ABSPATH') || exit;
/** Historical flat-rate campaign engine. Money is calculated in halalas. */
final class LoanCalculator {
 public const MAX_AMOUNT=100000000;
 public const DEFAULT_MONTHS=60;
 public static function text(string $ar,string $en): string { return \AutoDealership\Core\Localization::language()==='en'?$en:$ar; }
 /** Null denotes an unpublished value. Page numbers refer to PDF pages. */
 public static function default_rules(): array {
  $monthly=['months'=>[60],'rate'=>null,'down'=>0,'balloon'=>null,'admin'=>null];
  $half=['months'=>[24],'rate'=>0,'down'=>50,'balloon'=>50,'admin'=>0];
  return [
   'snb'=>['name'=>['الأهلي SNB','SNB'],'page'=>5,'rebate'=>6,'regular'=>array_replace($monthly,['down'=>null,'rate'=>['st'=>3.70,'nst'=>4.70],'admin'=>0]),'minimum'=>['government:st'=>2500,'military:st'=>2500,'military:nst'=>8000,'private_approved'=>4000,'private_unapproved'=>4000]],
   'alrajhi'=>['name'=>['مصرف الراجحي','Al Rajhi Bank'],'page'=>7,'rebate'=>8,'regular'=>array_replace($monthly,['rate'=>['A'=>['st'=>3.09,'nst'=>3.84],'B'=>['st'=>3.09,'nst'=>3.84],'C'=>['st'=>2.81,'nst'=>3.51],'D'=>['st'=>2.67,'nst'=>3.33]],'down'=>['D:nst'=>5,'default'=>0],'balloon'=>['A'=>50,'B'=>50,'C'=>40,'D'=>35],'admin'=>0]),'half'=>$half],
   'albilad'=>['name'=>['بنك البلاد','Bank Albilad'],'page'=>6,'rebate'=>6,'regular'=>array_replace($monthly,['rate'=>['approved'=>2.67,'unapproved'=>3.64],'balloon'=>40,'admin'=>0]),'half'=>array_replace($half,['rate'=>['approved'=>0,'unapproved'=>1]]),'minimum_price_half'=>80000],
   'alinma'=>['name'=>['مصرف الإنماء','Alinma Bank'],'page'=>8,'rebate'=>6,'regular'=>array_replace($monthly,['down'=>null,'rate'=>['st'=>2.41,'nst'=>2.81]]),'half'=>$half,'approved_only'=>true],
   'anb'=>['name'=>['البنك العربي الوطني','ANB'],'page'=>9,'rebate'=>6,'regular'=>array_replace($monthly,['down'=>null,'rate'=>['st'=>3.19,'nst'=>3.65,'unapproved'=>4.15],'admin'=>0]),'half'=>array_replace($half,['balloon'=>null])],
   'enbd'=>['name'=>['الإمارات دبي الوطني','Emirates NBD'],'page'=>10,'rebate'=>6,'regular'=>array_replace($monthly,['down'=>null,'rate'=>['saudi:st'=>2.40,'expat:st'=>3.05,'saudi:nst'=>3.00,'expat:nst'=>3.25,'self_employed'=>4.16],'admin'=>1]),'half'=>array_replace($half,['balloon'=>null,'admin'=>1])],
   'riyad'=>['name'=>['بنك الرياض','Riyad Bank'],'page'=>11,'rebate'=>6,'regular'=>array_replace($monthly,['rate'=>['high'=>['st'=>2.25,'nst'=>2.49],'low'=>['st'=>2.75,'nst'=>2.99]],'balloon'=>['st'=>45,'nst'=>40],'admin'=>0]),'half'=>$half,'minimum'=>['default'=>5000]],
   'raya'=>['name'=>['راية للتمويل','Raya Financing'],'page'=>12,'rebate'=>6,'regular'=>array_replace($monthly,['rate'=>6.50,'balloon'=>45,'admin'=>0]),'minimum'=>['default'=>3500],'self_employed_excluded'=>true,'leasing'=>true],
   'jb'=>['name'=>['جيبي J-B','J-B'],'page'=>13,'rebate'=>6,'regular'=>array_replace($monthly,['rate'=>['high'=>['saudi'=>4.55,'expat'=>5.40],'low'=>['saudi'=>4.90,'expat'=>5.90]],'down'=>['saudi'=>0,'expat'=>5],'balloon'=>['chinese'=>35,'other'=>45],'admin'=>0]),'half'=>array_replace($half,['rate'=>['saudi'=>2.83,'expat'=>3.08]]),'minimum'=>['default'=>5000]],
   'aloula'=>['name'=>['تمويل الأولى','Tamweel Aloula'],'page'=>14,'rebate'=>6,'regular'=>array_replace($monthly,['rate'=>7.50,'down'=>['chinese'=>5,'other'=>0],'balloon'=>['chinese'=>25,'other'=>35],'admin'=>0])],
   'emkan'=>['name'=>['إمكان','Emkan'],'page'=>15,'rebate'=>6,'regular'=>array_replace($monthly,['rate'=>5.50,'down'=>['military'=>5,'default'=>0],'balloon'=>45]),'minimum'=>['government:st'=>2000,'private_approved:st'=>3500,'private_unapproved:st'=>3500,'retired:st'=>1900,'government:nst'=>3500,'private_approved:nst'=>4000,'private_unapproved:nst'=>4000],'brands'=>['toyota','lexus','nissan','honda','mazda','ford','gmc','chevrolet','isuzu','hyundai','genesis','kia','mitsubishi','mg','geely','changan','haval','jetour']],
  ];
 }
 public static function rules(): array { return FinanceConfiguration::providers(); }
 public static function calculate(array $input) {
  $settings=FinanceConfiguration::get()['settings'];
  $input=array_merge(['months'=>60,'sector'=>'government','nationality'=>'saudi','transfer'=>'st','provider'=>'all','campaign'=>'regular','category'=>'A','chinese'=>'no','employer'=>'approved','insurance_rate'=>$settings['insurance_default'],'obligations'=>'0','brand'=>'','assumed_down'=>'','assumed_balloon'=>'','assumed_admin'=>''],$input);
  foreach(['assumed_down','assumed_balloon','assumed_admin'] as $field) {
   if($input[$field]==='') continue;
   $n=self::decimal($input[$field]);
   if($n===null || $n>($field==='assumed_admin'?self::MAX_AMOUNT:100)) return self::invalid();
  }
  $price=self::money($input['price']??null); $salary=self::money($input['salary']??null); $obligations=self::money($input['obligations']); $insurance=self::decimal($input['insurance_rate']);
  $choices=['months'=>FinanceConfiguration::CONDITIONS['months'],'sector'=>['government','military','private_approved','private_unapproved','retired','self_employed'],'nationality'=>['saudi','expat'],'transfer'=>['st','nst'],'provider'=>array_merge(['all'],array_keys(self::rules())),'campaign'=>['regular','half','leasing'],'category'=>['A','B','C','D'],'chinese'=>['yes','no'],'employer'=>['approved','unapproved']];
  foreach($choices as $key=>$allowed) if(!is_scalar($input[$key])||!in_array((string)$input[$key],$allowed,true)) return self::invalid();
  if($price===null||$price<=0||$price>self::MAX_AMOUNT*100||$salary===null||$salary<=0||$salary>self::MAX_AMOUNT*100||$obligations===null||$obligations>self::MAX_AMOUNT*100||$insurance===null||$insurance<$settings['insurance_min']||$insurance>$settings['insurance_max']||!is_string($input['brand'])||strlen($input['brand'])>80) return self::invalid();
  $input['price']=$price/100; $input['salary']=$salary/100; $input['months']=(int)$input['months']; $input['insurance_rate']=$insurance; $input['obligations']=$obligations/100; $input['brand']=strtolower(trim($input['brand']));
  $rows=[];
  foreach(self::rules() as $id=>$bank) { if($input['provider']!=='all'&&$input['provider']!==$id) continue; $rows[]=self::quote($id,$bank,$input,$price,$salary,$obligations); }
  $ranked=array_filter($rows,static fn($r)=>$r['status']==='eligible');
  // Missing eligibility information never prevents calculation, but excludes best-offer badges.
  $minPayment=$ranked?min(array_column($ranked,'monthly_payment')):null; $minRate=$ranked?min(array_column($ranked,'annual_rate')):null;
  foreach($rows as &$row) { $row['lowest_payment']=$row['status']==='eligible'&&$row['monthly_payment']===$minPayment; $row['lowest_rate']=$row['status']==='eligible'&&$row['annual_rate']===$minRate; } unset($row);
  return ['input'=>$input,'results'=>$rows,'currency'=>'SAR','estimate_only'=>true,'source'=>'Managed financing configuration','source_period'=>$settings['source_period'],'notice'=>self::text('النتائج تقديرية، وتحدد جهة التمويل الشروط والتكاليف النهائية.','Results are estimates. The financing provider determines final terms and costs.')];
 }
 private static function quote(string $id,array $bank,array $i,int $price,int $salary,int $obligations): array {
  $settings=FinanceConfiguration::get()['settings'];
  $row=['id'=>$id,'name'=>self::text(...$bank['name']),'source_page'=>$bank['page'],'source'=>$bank['source'],'rebate_percent'=>0,'status'=>'unavailable','reasons'=>[],'notes'=>[],'annual_rate'=>null,'monthly_payment'=>null];
  $key=$i['campaign'];$p=$bank['programs'][$key];
  if(!$p['active']) { $row['reasons'][]=self::text('هذا البرنامج غير متاح لدى الجهة.','This program is unavailable at this provider.');return $row; }
  if(!in_array($i['months'],$p['months'],true)) { $row['reasons'][]=self::text('المدد المتاحة لهذا البرنامج: ','Available tenures for this program: ').implode(', ',$p['months']).self::text(' شهرًا.',' months.');return $row; }
  $resolved=FinanceConfiguration::resolve($p,$i);$v=$resolved['values'];$rate=$v['rate'];$down=$v['down'];$balloon=$v['balloon'];$admin=$v['admin'];$minimum=$v['min_salary'];
  $unknown=[];$assumptions=[];$fixedFee=null;
  if($p['tenure_assumed'])$assumptions[]=self::text('مدة التمويل تحتاج تأكيدًا من الجهة.','Financing tenure requires provider confirmation.');
  if($p['payments_assumed'])$assumptions[]=self::text('نسب الدفعات تحتاج تأكيدًا من الجهة.','Payment percentages require provider confirmation.');
  $note=self::text(...$p['notes']);$notes=$resolved['notes'];if($note!=='')$notes[]=$note;
  if($resolved['verify'])$unknown[]=self::text('يلزم التحقق من شروط إضافية موضحة في التفاصيل.','Verify the additional criteria in the details.');
  if($down===null&&$i['assumed_down']!=='') { $down=(float)$i['assumed_down'];$assumptions[]=self::text('دفعة أولى أدخلها المستخدم: ','User-supplied down payment: ').$down.'%'; }
  if($balloon===null&&$i['assumed_balloon']!=='') { $balloon=(float)$i['assumed_balloon'];$assumptions[]=self::text('دفعة أخيرة أدخلها المستخدم: ','User-supplied balloon: ').$balloon.'%'; }
  if($admin===null&&$i['assumed_admin']!=='') { $fixedFee=self::money($i['assumed_admin']);$admin=0;$assumptions[]=self::text('رسوم أدخلها المستخدم: ','User-supplied administrative fee: ').$i['assumed_admin'].' SAR'; }
  foreach(['rate'=>$rate,'down'=>$down,'balloon'=>$balloon,'admin'=>$admin,'min_salary'=>$minimum] as $field=>$value)if($value===null)$unknown[]=self::text(['rate'=>'نسبة الربح غير متوفرة.','down'=>'نسبة الدفعة الأولى غير متوفرة.','balloon'=>'نسبة الدفعة الأخيرة غير متوفرة.','admin'=>'الرسوم الإدارية غير متوفرة.','min_salary'=>'الحد الأدنى للراتب غير متوفر لهذه الشريحة.'][$field],['rate'=>'Profit rate is missing.','down'=>'Down payment percentage is missing.','balloon'=>'Balloon percentage is missing.','admin'=>'Administrative fees are missing.','min_salary'=>'Minimum salary for this segment is missing.'][$field]);
  if($minimum!==null&&$salary<(int)round($minimum*100))$row['reasons'][]=self::text('الراتب أقل من الحد الأدنى: ','Salary is below the minimum: ').$minimum.' SAR';
  if(!in_array($i['sector'],$p['sectors'],true))$row['reasons'][]=self::text('قطاع العمل غير مشمول في هذا البرنامج.','Employment sector is excluded from this program.');
  if(!in_array($i['nationality'],$p['nationalities'],true))$row['reasons'][]=self::text('الجنسية غير مشمولة في هذا البرنامج.','Nationality is excluded from this program.');
  $approved=$i['sector']==='private_unapproved'||$i['employer']==='unapproved'?'unapproved':'approved';
  if($p['approved_only']&&$approved==='unapproved')$row['reasons'][]=self::text('البرنامج مخصص للجهات المعتمدة.','Program is limited to approved employers.');
  $minimum_price=(int)round($p['price_min']*100);
  if($price<$minimum_price||($p['price_strict']&&$price<=$minimum_price))$row['reasons'][]=self::text('سعر السيارة لا يستوفي الحد المطلوب: ','Car price does not meet the required minimum: ').$p['price_min'].' SAR';
  if($p['brands']) { if($i['brand']==='')$unknown[]=self::text('أدخل العلامة للتحقق من شمولها في البرنامج.','Enter the brand to check program coverage.');elseif(!in_array($i['brand'],$p['brands'],true))$row['reasons'][]=self::text('العلامة غير مشمولة في هذا البرنامج.','Brand is excluded from this program.'); }
  $row=array_merge($row,['annual_rate'=>$rate===null?null:(float)$rate,'minimum_salary'=>$minimum,'down_percent'=>$down,'balloon_percent'=>$balloon,'rebate_percent'=>$v['rebate'],'notes'=>array_merge($unknown,$assumptions,$notes),'assumptions'=>$assumptions]);
  if($rate===null||$down===null||$balloon===null||$admin===null){$row['status']=$row['reasons']?'ineligible':'incomplete';return $row;}
  if($down+$balloon>100){$row['status']='unavailable';$row['reasons'][]=self::text('مجموع الدفعتين يتجاوز سعر السيارة.','Down payment plus balloon exceeds car price.');return $row;}
  $dp=(int)round($price*$down/100); $bp=(int)round($price*$balloon/100); $principal=$price-$dp;
  // Two independently rounded payments must not exceed the price by a halala.
  $bp=min($bp,$principal);
  $profit=(int)round($principal*$rate/100*$i['months']/12); $fee=$fixedFee??($p['admin_mode']==='fixed'?(int)round($admin*100):(int)round($principal*$admin/100)); if($p['admin_cap']!==null)$fee=min($fee,(int)round($p['admin_cap']*100)); $installments=$principal-$bp+$profit+$fee;
  $insuranceTotal=(int)round($price*$i['insurance_rate']/100*$i['months']/12); $base=$installments/$i['months']; $insuranceMonth=$insuranceTotal/$i['months']; $payment=$base+$insuranceMonth;
  $ratio=($payment+$obligations)/$salary*100;
  if($ratio>$settings['debt_limit']) $row['reasons'][]=self::text('القسط والتأمين والالتزامات القائمة تتجاوز الحد المسموح: ','Payment, insurance and obligations exceed the configured limit: ').$settings['debt_limit'].'%';
  $row['status']=$row['reasons']?'ineligible':(($unknown||$assumptions)?'incomplete':'eligible');
  $row['notes'][]=self::text('التأمين تقدير ثابت على سعر السيارة، والرسوم النسبية على المبلغ الممول. الاستحقاق فحص أولي وليس موافقة.','Insurance is estimated on full car price; percentage fees use financed principal. Eligibility is preliminary, not approval.');
  $row['notes'][]=self::text('فحص الالتزامات لا يغني عن دراسة الاستقطاع من الراتب والتقاعد والسجل الائتماني وتمويل الدفعة الأخيرة.','The obligations check does not replace salary deduction, retirement, credit history and balloon affordability assessment.');
  return array_merge($row,['down_payment'=>$dp/100,'principal'=>$principal/100,'profit'=>$profit/100,'balloon_payment'=>$bp/100,'admin_fees'=>$fee/100,'admin_percent'=>$p['admin_mode']==='percent'?$admin:null,'installment_total'=>$installments/100,'base_monthly'=>round($base/100,2),'insurance_monthly'=>round($insuranceMonth/100,2),'insurance_total'=>$insuranceTotal/100,'monthly_payment'=>round($payment/100,2),'total_payable'=>($dp+$installments+$insuranceTotal+$bp)/100,'debt_ratio'=>round($ratio,2)]);
 }
 public static function render(array $model): string { ob_start(); require __DIR__.'/views/loan-calculator.php'; return (string)ob_get_clean(); }
 public static function view_model(float $price=0): array { return ['price'=>round(min(self::MAX_AMOUNT,max(0,$price)),2),'months'=>60,'providers'=>self::rules(),'settings'=>FinanceConfiguration::get()['settings'],'available_months'=>FinanceConfiguration::months()]; }
 private static function decimal($value): ?float { return is_scalar($value)&&preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/',(string)$value)?(float)$value:null; }
 private static function money($value): ?int { return self::decimal($value)===null?null:\AutoDealership\Pricing\Money::from_sar((string)$value); }
 private static function invalid(): \WP_Error { return new \WP_Error('adc_loan_invalid',self::text('راجع السعر والراتب وخيارات التمويل.','Check price, salary and financing selections.'),['status'=>400]); }
}
