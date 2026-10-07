<?php
namespace AutoDealership\Tools;
defined('ABSPATH') || exit;

/** Validated, versioned calculator configuration. The original campaign is only a bootstrap. */
final class FinanceConfiguration {
 public const OPTION='adc_finance_calculator_v1';
 public const SECTORS=['government','military','private_approved','private_unapproved','retired','self_employed'];
 public const FIELDS=['rate','down','balloon','admin','min_salary','rebate'];
 public const CONDITIONS=['sector'=>self::SECTORS,'nationality'=>['saudi','expat'],'transfer'=>['st','nst'],'employer'=>['approved','unapproved'],'category'=>['A','B','C','D'],'chinese'=>['yes','no'],'months'=>['12','24','36','48','60']];
 public static function get(): array {
  $stored=function_exists('get_option')?get_option(self::OPTION,false):false;
  return is_array($stored)&&($stored['version']??null)===1 ? $stored : self::defaults();
 }
 public static function revision(): string { return hash('sha256',serialize(self::get())); }
 public static function providers(): array {
  $rows=array_filter(self::get()['providers'],static fn($p)=>$p['active']);
  uasort($rows,static fn($a,$b)=>$a['position']<=>$b['position']);return $rows;
 }
 public static function blank_program(): array {
  return ['active'=>false,'months'=>[60],'values'=>['rate'=>null,'down'=>null,'balloon'=>null,'admin'=>null,'min_salary'=>null,'rebate'=>0],'admin_mode'=>'percent','admin_cap'=>null,'price_min'=>0,'price_strict'=>false,'approved_only'=>false,'sectors'=>self::SECTORS,'nationalities'=>['saudi','expat'],'brands'=>[],'tenure_assumed'=>false,'payments_assumed'=>false,'notes'=>['',''],'rules'=>[]];
 }
 public static function defaults(): array {
  $providers=[];$position=0;
  foreach(LoanCalculator::default_rules() as $id=>$old) {
   $provider=['name'=>$old['name'],'active'=>true,'position'=>++$position,'page'=>$old['page'],'source'=>'PETROMIN NATC Q1 2026 Campaign','programs'=>[]];
   foreach(['regular','half','leasing'] as $key) {
    $legacy=$key==='leasing'&&!empty($old['leasing'])?$old['regular']:($old[$key]??null);
    if(!$legacy) { $provider['programs'][$key]=self::blank_program();continue; }
    $p=self::blank_program();$p['active']=true;$p['months']=$legacy['months'];$p['values']['rebate']=$id==='enbd'&&$key==='half'?5.99:$old['rebate'];
    foreach(['rate','down','balloon','admin'] as $field) self::migrate_value($p,$id,$field,$legacy[$field]);
    self::migrate_value($p,$id,'min_salary',$old['minimum']??null);
    $p['approved_only']=$key!=='half'&&!empty($old['approved_only']);
    if(!empty($old['self_employed_excluded'])) $p['sectors']=array_values(array_diff(self::SECTORS,['self_employed']));
    $p['brands']=$old['brands']??[];
    $p['price_min']=$key==='half'?($old['minimum_price_half']??0):0;$p['price_strict']=$p['price_min']>0;
    $p['tenure_assumed']=($key==='regular'&&in_array($id,['anb','enbd','aloula','emkan'],true))||($key==='half'&&in_array($id,['anb','enbd'],true));
    $p['payments_assumed']=$key==='half'&&$id==='alinma';
    if($id==='riyad') {
     if($key==='regular') $p['rules'][]=self::verification(['salary_min'=>8000,'salary_max'=>8000],['راتب 8,000 بالضبط يحتاج تأكيد الشريحة.','Verify the salary band at exactly SAR 8,000.']);
     $p['rules'][]=self::verification(['salary_min'=>5000,'salary_max'=>5000],['الملف يقول أعلى من 5,000؛ يلزم تأكيد قبول 5,000 بالضبط.','Source says above SAR 5,000; verify exactly SAR 5,000.']);
     if($key==='regular') $p['notes']=['تستخدم المقارنة الحد الأعلى للدفعة الأخيرة: 45% للمحول و40% لغير المحول.','Comparison uses maximum balloon: 45% ST / 40% NST.'];
    }
    if($id==='raya') foreach(['private_approved','private_unapproved'] as $sector) $p['rules'][]=self::verification(['sector'=>$sector],['يلزم تأكيد مدة الخدمة: 3 أشهر للسعودي و6 للمقيم في القطاع الخاص.','Verify private-sector service: 3 months Saudi / 6 months expat.']);
    if($id==='anb'&&$key==='regular') $p['rules'][]=self::verification(['employer'=>'unapproved','transfer'=>'st'],['يلزم تأكيد شروط الجهة غير المعتمدة مع تحويل الراتب.','Verify non-approved employer terms with salary transfer.']);
    $provider['programs'][$key]=$p;
   }
   $providers[$id]=$provider;
  }
  return ['version'=>1,'settings'=>['debt_limit'=>45,'insurance_min'=>3,'insurance_max'=>4.5,'insurance_default'=>3,'source_period'=>'2026-01-01 / 2026-03-31','category_note'=>['الفئة C تضم العلامات والموديلات المذكورة، والعلامات الصينية غير المسماة في الجدول. جيلي وهافال وMG ضمن B. شفروليه عمومًا ضمن A، وكورفيت وكامارو ضمن C تحديدًا.','C includes the listed brands and models and Chinese brands not named in the table. Geely, Haval and MG are in B. Chevrolet generally is in A; Corvette and Camaro are in C.'],'categories'=>[
   'A'=>'Toyota, Lexus, Mazda, Honda, Nissan, Ford, GMC, Chevrolet, Isuzu, Hyundai, Kia, Mitsubishi, Genesis',
   'B'=>'Mercedes, BMW, Range Rover, Infiniti, Volkswagen, Audi, Land Rover, Porsche, Chrysler, Dodge, Jeep, Suzuki, Changan, Geely, Haval, Great Wall, Renault, MG, GAC, Chery, Jetour, JAC, Peugeot, Lincoln, Cadillac, Skoda, BYD, Omoda, Jaecoo, Daihatsu, RAM, Hongqi, BAIC, Maxus, Exeed, JMC, SAIC, FAW',
   'C'=>'Citroen, Seat, Fiat, Subaru, Opel, Mini Cooper, SsangYong, Tata, Volvo, Chevrolet Corvette, Chevrolet Camaro, Jaguar, Lucid, Lynk & Co, Dongfeng, Bestune, Forthing, Huanghai, Soueast, Zhengzhou, ZX',
   'D'=>'Bentley, Rolls-Royce, Ferrari, Bugatti, Alfa Romeo, Aston Martin, Fisker, Lamborghini, Maserati']], 'providers'=>$providers];
 }
 private static function verification(array $when,array $notes): array { return ['when'=>$when,'values'=>[],'verify'=>true,'notes'=>$notes]; }
 private static function migrate_value(array &$p,string $id,string $field,$value,array $when=[]): void {
  if(!is_array($value)) {
   if(!$when) $p['values'][$field]=$value;
   else $p['rules'][]=['when'=>$when,'values'=>[$field=>$value],'verify'=>false,'notes'=>['','']];return;
  }
  foreach($value as $key=>$child) {
   $next=$when;
   foreach(explode(':',(string)$key) as $part) {
    if($part==='default') continue;
    if(in_array($part,self::SECTORS,true)) $next['sector']=$part;
    elseif(in_array($part,['A','B','C','D'],true)) $next['category']=$part;
    elseif(in_array($part,['st','nst'],true)) $next['transfer']=$part;
    elseif(in_array($part,['saudi','expat'],true)) $next['nationality']=$part;
    elseif(in_array($part,['approved','unapproved'],true)) $next['employer']=$part;
    elseif(in_array($part,['chinese','other'],true)) $next['chinese']=$part==='chinese'?'yes':'no';
    elseif($part==='high') $next['salary_min']=$id==='riyad'?8000.01:10000;
    elseif($part==='low') $next['salary_max']=$id==='riyad'?8000:9999.99;
   }
   self::migrate_value($p,$id,$field,$child,$next);
  }
 }
 public static function matches(array $when,array $input): bool {
  $employer=$input['sector']==='private_unapproved'||$input['employer']==='unapproved'?'unapproved':'approved';
  foreach($when as $key=>$value) {
   if($key==='salary_min') { if($input['salary']<$value) return false; }
   elseif($key==='salary_max') { if($input['salary']>$value) return false; }
   elseif((string)($key==='employer'?$employer:$input[$key])!==(string)$value) return false;
  }return true;
 }
 /** Values are resolved independently; later matching rules override earlier values. */
 public static function resolve(array $program,array $input): array {
  $values=$program['values'];$notes=[];$verify=false;
  foreach($program['rules'] as $rule) if(self::matches($rule['when'],$input)) {
   foreach($rule['values'] as $field=>$value) $values[$field]=$value;
   if($rule['verify']) $verify=true;
   $note=LoanCalculator::text(...$rule['notes']);if($note!=='') $notes[]=$note;
  }return ['values'=>$values,'notes'=>$notes,'verify'=>$verify];
 }
 public static function months(): array {
  $months=[];foreach(self::providers() as $p)foreach($p['programs'] as $program)if($program['active'])$months=array_merge($months,$program['months']);
  $months=array_values(array_unique($months));sort($months);return $months ?: [12,24,36,48,60];
 }
 private static function number($value,float $max,bool $nullable=true): ?float {
  if($nullable&&($value===''||$value===null))return null;
  if(!is_scalar($value)||!preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/',(string)$value)||(float)$value>$max)throw new \InvalidArgumentException('قيمة رقمية غير صحيحة أو خارج النطاق.');return (float)$value;
 }
 private static function plain($value,int $max=2000): string {
  if(!is_string($value)||strlen($value)>$max*4)throw new \InvalidArgumentException('نص غير صحيح أو أطول من الحد المسموح.');return sanitize_textarea_field($value);
 }
 private static function integer($value,int $max): int { $n=self::number($value,$max,false);if(floor($n)!=$n)throw new \InvalidArgumentException('الترتيب ورقم الصفحة يجب أن يكونا عددين صحيحين.');return (int)$n; }
 public static function validate_provider(array $raw): array {
  $out=['name'=>[self::plain($raw['name'][0]??'',120),self::plain($raw['name'][1]??'',120)],'active'=>!empty($raw['active']),'position'=>self::integer($raw['position']??0,9999),'page'=>self::integer($raw['page']??0,9999),'source'=>self::plain($raw['source']??'',250),'programs'=>[]];
  if($out['name'][0]===''||$out['name'][1]==='')throw new \InvalidArgumentException('أدخل اسم الجهة بالعربية والإنجليزية.');
  foreach(['regular','half','leasing'] as $key) {
   $r=$raw['programs'][$key]??[];if(!is_array($r))throw new \InvalidArgumentException('برنامج تمويل غير صحيح.');
   $p=self::blank_program();$p['active']=!empty($r['active']);
   $months=$r['months']??[];if(!is_array($months))throw new \InvalidArgumentException('مدد التمويل غير صحيحة.');
   $p['months']=[];foreach($months as $m){if(!in_array((string)$m,self::CONDITIONS['months'],true))throw new \InvalidArgumentException('مدة التمويل غير مدعومة.');$p['months'][]=(int)$m;}
   $p['months']=array_values(array_unique($p['months']));sort($p['months']);if($p['active']&&!$p['months'])throw new \InvalidArgumentException('اختر مدة واحدة على الأقل لكل برنامج مفعّل.');
   $p['admin_mode']=$r['admin_mode']??'percent';if(!in_array($p['admin_mode'],['percent','fixed'],true))throw new \InvalidArgumentException('نوع الرسوم غير صحيح.');
   foreach(self::FIELDS as $field)$p['values'][$field]=self::number($r['values'][$field]??null,($field==='min_salary'||($field==='admin'&&$p['admin_mode']==='fixed'))?LoanCalculator::MAX_AMOUNT:100);
   $p['admin_cap']=self::number($r['admin_cap']??null,LoanCalculator::MAX_AMOUNT);$p['price_min']=self::number($r['price_min']??0,LoanCalculator::MAX_AMOUNT,false);
   foreach(['price_strict','approved_only','tenure_assumed','payments_assumed'] as $flag)$p[$flag]=!empty($r[$flag]);
   foreach(['sectors'=>self::SECTORS,'nationalities'=>['saudi','expat']] as $field=>$allowed){$values=$r[$field]??[];if(!is_array($values)||count($values)>20)throw new \InvalidArgumentException('شرائح العملاء غير صحيحة.');foreach($values as $value)if(!is_string($value)||!in_array($value,$allowed,true))throw new \InvalidArgumentException('شرائح العملاء غير صحيحة.');$p[$field]=array_values(array_unique($values));if($p['active']&&!$p[$field])throw new \InvalidArgumentException('اختر شرائح العملاء للبرنامج.');}
   $brands=$r['brands']??'';if(is_array($brands))$brands=implode(',',$brands);$p['brands']=array_values(array_unique(array_filter(array_map(static fn($s)=>strtolower(trim($s)),preg_split('/[,،\r\n]+/',self::plain($brands,4000))))));
   $p['notes']=[self::plain($r['notes'][0]??''),self::plain($r['notes'][1]??'')];
   $rules=$r['rules']??[];if(!is_array($rules)||count($rules)>100)throw new \InvalidArgumentException('الحد الأقصى 100 شرط لكل برنامج.');
   $p['rules']=[];foreach($rules as $rule){
    if(!is_array($rule))throw new \InvalidArgumentException('شرط غير صحيح.');$when=[];
    foreach(self::CONDITIONS as $field=>$allowed){$value=$rule['when'][$field]??'';if($value==='')continue;if(!is_string($value)&&!is_int($value))throw new \InvalidArgumentException('قيمة شرط غير صحيحة.');if(!in_array((string)$value,$allowed,true))throw new \InvalidArgumentException('خيار شرط غير صحيح.');$when[$field]=(string)$value;}
    foreach(['salary_min','salary_max'] as $field){$value=self::number($rule['when'][$field]??null,LoanCalculator::MAX_AMOUNT);if($value!==null)$when[$field]=$value;}
    if(isset($when['salary_min'],$when['salary_max'])&&$when['salary_min']>$when['salary_max'])throw new \InvalidArgumentException('بداية شريحة الراتب أكبر من نهايتها.');
    $values=[];foreach(self::FIELDS as $field)if(array_key_exists($field,$rule['values']??[])&&$rule['values'][$field]!=='')$values[$field]=self::number($rule['values'][$field],($field==='min_salary'||($field==='admin'&&$p['admin_mode']==='fixed'))?LoanCalculator::MAX_AMOUNT:100);
    $notes=[self::plain($rule['notes'][0]??''),self::plain($rule['notes'][1]??'')];$verify=!empty($rule['verify']);
    if(!$values&&!$verify&&$notes===['',''])continue;
    if($verify&&($notes[0]===''||$notes[1]===''))throw new \InvalidArgumentException('اكتب سبب التحقق الإضافي بالعربية والإنجليزية.');
    $p['rules'][]=['when'=>$when,'values'=>$values,'verify'=>$verify,'notes'=>$notes];
   }
   $sets=array_merge([$p['values']],array_map(static fn($rule)=>array_replace($p['values'],$rule['values']),$p['rules']));foreach($sets as $v)if($v['down']!==null&&$v['balloon']!==null&&$v['down']+$v['balloon']>100)throw new \InvalidArgumentException('مجموع نسبتي الدفعة الأولى والأخيرة يتجاوز 100%.');
   $out['programs'][$key]=$p;
  }return $out;
 }
 public static function validate_settings(array $r): array {
  $out=['debt_limit'=>self::number($r['debt_limit']??null,100,false),'insurance_min'=>self::number($r['insurance_min']??null,100,false),'insurance_max'=>self::number($r['insurance_max']??null,100,false),'insurance_default'=>self::number($r['insurance_default']??null,100,false),'source_period'=>self::plain($r['source_period']??'',100),'category_note'=>[self::plain($r['category_note'][0]??''),self::plain($r['category_note'][1]??'')],'categories'=>[]];
  if($out['debt_limit']<=0||$out['insurance_min']>$out['insurance_max']||$out['insurance_default']<$out['insurance_min']||$out['insurance_default']>$out['insurance_max'])throw new \InvalidArgumentException('راجع حد الالتزامات ونطاق التأمين وقيمته الافتراضية.');
  foreach(['A','B','C','D'] as $key)$out['categories'][$key]=self::plain($r['categories'][$key]??'',4000);return $out;
 }
 /** Serialize with a compare-and-swap to reject concurrent edits, including the initial save. */
 public static function save(array $config,string $revision) {
  if(!current_user_can('manage_options'))return new \WP_Error('adc_finance_forbidden','غير مسموح.');
  $old=get_option(self::OPTION,false);
  if(!hash_equals(hash('sha256',serialize($old===false?self::defaults():$old)),$revision))return new \WP_Error('adc_finance_conflict','تم تعديل البيانات في نافذة أخرى؛ أعد تحميل الصفحة قبل الحفظ.');
  if($old===false){if(!add_option(self::OPTION,$config,'',false))return new \WP_Error('adc_finance_conflict','تم حفظ البيانات بالتزامن؛ أعد تحميل الصفحة.');return true;}
  if($old===$config)return true;
  global $wpdb;
  $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",maybe_serialize($config),self::OPTION,maybe_serialize($old)));
  if($changed!==1)return new \WP_Error('adc_finance_conflict','تعذر الحفظ أو تغيرت البيانات بالتزامن.');
  wp_cache_delete(self::OPTION,'options');wp_cache_delete('alloptions','options');return true;
 }
}
