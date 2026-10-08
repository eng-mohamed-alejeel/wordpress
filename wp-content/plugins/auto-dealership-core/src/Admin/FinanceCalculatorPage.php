<?php
namespace AutoDealership\Admin;
use AutoDealership\Tools\FinanceConfiguration as Config;
defined('ABSPATH') || exit;
final class FinanceCalculatorPage {
 public static function boot(): void {
  add_action('admin_menu',static function(){add_submenu_page('adc-settings','إدارة حاسبة التمويل','حاسبة التمويل','manage_options','adc-finance-calculator',[self::class,'render']);});
  add_action('admin_post_adc_finance_config',[self::class,'save']);add_action('admin_enqueue_scripts',[self::class,'assets']);
 }
 public static function assets(string $hook): void {
  if(($_GET['page']??'')!=='adc-finance-calculator')return;
  wp_enqueue_style('adc-finance-admin',plugins_url('assets/css/finance-admin.css',ADC_FILE),['adc-admin'],(string)filemtime(ADC_PATH.'assets/css/finance-admin.css'));
  wp_enqueue_script('adc-finance-admin',plugins_url('assets/js/finance-admin.js',ADC_FILE),[],(string)filemtime(ADC_PATH.'assets/js/finance-admin.js'),true);
 }
 private static function guard(): void { if(!current_user_can('manage_options'))wp_die('غير مسموح.','',['response'=>403]); }
 private static function url(array $args=[]): string { return add_query_arg(array_merge(['page'=>'adc-finance-calculator'],$args),admin_url('admin.php')); }
 private static function input(string $name,string $label,$value='',string $type='text',bool $required=false,$max=null): void {
  echo '<label class="adc-fa-field">'.esc_html($label).'<input name="'.esc_attr($name).'" type="'.esc_attr($type).'" value="'.esc_attr($value??'').'"'.($required?' required':'').($type==='number'?' min="0" step="0.01"'.($max!==null?' max="'.esc_attr($max).'"':''):' maxlength="1000"').'></label>';
 }
 private static function textarea(string $name,string $label,string $value): void { echo '<label class="adc-fa-field">'.esc_html($label).'<textarea name="'.esc_attr($name).'" rows="3" maxlength="16000">'.esc_textarea($value).'</textarea></label>'; }
 private static function checkbox(string $name,string $label,bool $checked): void { echo '<label class="adc-fa-check"><input name="'.esc_attr($name).'" type="checkbox" value="1"'.checked($checked,true,false).'> '.esc_html($label).'</label>'; }
 /**
  * @param string|int|float|null $value
  */
 private static function select(string $name,string $label,array $choices,$value): void { echo '<label class="adc-fa-field">'.esc_html($label).'<select name="'.esc_attr($name).'">';foreach($choices as $k=>$text)echo '<option value="'.esc_attr($k).'"'.selected((string)$value,(string)$k,false).'>'.esc_html($text).'</option>';echo '</select></label>'; }
 private static function sectors(): array { return ['government'=>'حكومي / مدني','military'=>'عسكري','private_approved'=>'خاص معتمد','private_unapproved'=>'خاص غير معتمد','retired'=>'متقاعد','self_employed'=>'أصحاب مؤسسات']; }
 private static function fields(): array { return ['rate'=>'نسبة الربح السنوية الثابتة %','down'=>'الدفعة الأولى %','balloon'=>'الدفعة الأخيرة %','admin'=>'الرسوم الإدارية حسب النوع المختار','min_salary'=>'الحد الأدنى للراتب (ريال)','rebate'=>'دعم الوكيل %']; }
 private static function start_form(string $operation,string $id='',?string $revision=null): void {
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-finance-admin-form><input type="hidden" name="action" value="adc_finance_config"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="id" value="'.esc_attr($id).'"><input type="hidden" name="revision" value="'.esc_attr($revision??Config::revision()).'">';wp_nonce_field('adc_finance_config');
 }
 private static function end_form(string $label): void { echo '<input type="hidden" name="complete" value="1"><p><button class="button button-primary">'.esc_html($label).'</button></p><p data-finance-editor-status role="status"></p></form>'; }
 public static function render(): void {
  self::guard();$config=Config::get();$id=isset($_GET['edit'])&&is_string($_GET['edit'])?sanitize_key($_GET['edit']):'';$new=isset($_GET['new']);
  $draft=get_transient('adc_finance_editor_'.get_current_user_id());if($draft)delete_transient('adc_finance_editor_'.get_current_user_id());
  echo '<div class="wrap adc-finance-admin"><h1>إدارة حاسبة التمويل</h1><p>تظهر التعديلات المحفوظة في جميع حاسبات الموقع. هذه الإعدادات لا تعدّل طلبات التمويل أو الأقساط المسجلة سابقًا.</p>';
  if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>تم حفظ التعديلات.</p></div>';
  if($draft&&isset($draft['error']))echo '<div class="notice notice-error"><p>'.esc_html($draft['error']).'</p></div>';
  echo '<p><a class="button" href="'.esc_url(self::url()).'">جهات التمويل</a> <a class="button button-primary" href="'.esc_url(self::url(['new'=>1])).'">إضافة جهة تمويل</a> <a class="button" href="'.esc_url(self::url(['settings'=>1])).'">الإعدادات العامة وفئات العلامات</a></p>';
  if(isset($_GET['settings']))self::settings($draft['raw']['settings']??$config['settings'],$draft['raw']['revision']??null);
  elseif($new||$id!=='') {
   if(!$new&&!isset($config['providers'][$id]))echo '<div class="notice notice-error"><p>جهة التمويل غير موجودة.</p></div>';
   else { $provider=$draft['raw']['provider']??($new?['name'=>['',''],'active'=>false,'position'=>count($config['providers'])+1,'page'=>0,'source'=>'','programs'=>array_fill_keys(['regular','half','leasing'],Config::blank_program())]:$config['providers'][$id]);self::editor($id,$provider,$new,$draft['raw']['revision']??null); }
  }else {
   echo '<table class="widefat striped"><thead><tr><th>الترتيب</th><th>جهة التمويل</th><th>الحالة</th><th>البرامج</th><th>الإجراءات</th></tr></thead><tbody>';
   $providers=$config['providers'];uasort($providers,static fn($a,$b)=>$a['position']<=>$b['position']);
   foreach($providers as $key=>$p){$programs=[];foreach(['regular'=>'أقساط شهرية','half'=>'50/50','leasing'=>'تأجير'] as $k=>$title)if($p['programs'][$k]['active'])$programs[]=$title;
    echo '<tr><td>'.esc_html($p['position']).'</td><td><strong>'.esc_html($p['name'][0]).'</strong><br>'.esc_html($p['name'][1]).'</td><td>'.($p['active']?'مفعّلة':'معطّلة').'</td><td>'.esc_html(implode('، ',$programs)).'</td><td><a class="button" href="'.esc_url(self::url(['edit'=>$key])).'">تعديل</a> ';
    self::start_form('toggle',$key);echo '<button class="button">'.($p['active']?'تعطيل':'تفعيل').'</button></form>';
    self::start_form('delete',$key);echo '<label><input type="checkbox" name="confirm_delete" value="1" required> تأكيد حذف الجهة</label> <button class="button adc-fa-delete">حذف</button></form></td></tr>';
   }
   if(!$providers)echo '<tr><td colspan="5">لا توجد جهات تمويل. أضف جهة لتظهر في الحاسبة.</td></tr>';echo '</tbody></table>';
  }echo '</div>';
 }
 private static function editor(string $id,array $p,bool $new,?string $revision): void {
  self::start_form('provider',$id,$revision);echo '<div class="adc-fa-card"><h2>'.($new?'إضافة جهة تمويل':'بيانات جهة التمويل').'</h2><div class="adc-fa-grid">';
  self::input('provider[name][0]','الاسم بالعربية',$p['name'][0]??'','text',true);self::input('provider[name][1]','الاسم بالإنجليزية',$p['name'][1]??'','text',true);self::input('provider[position]','ترتيب العرض',$p['position']??0,'number',true,9999);self::input('provider[source]','مرجع البيانات (اختياري)',$p['source']??'');self::input('provider[page]','صفحة المرجع (0 إذا لا يوجد)',$p['page']??0,'number',true,9999);echo '</div>';self::checkbox('provider[active]','إظهار الجهة في حاسبة الموقع',!empty($p['active']));echo '</div>';
  foreach(['regular'=>'برنامج الأقساط الشهرية','half'=>'برنامج 50/50','leasing'=>'برنامج التأجير'] as $key=>$title)self::program($key,$title,$p['programs'][$key]??Config::blank_program());self::end_form('حفظ جهة التمويل');
 }
 private static function program(string $key,string $title,array $p): void {
  $prefix='provider[programs]['.$key.']';echo '<section class="adc-fa-card" data-finance-program><h2>'.esc_html($title).'</h2>';self::checkbox($prefix.'[active]','تفعيل هذا البرنامج',!empty($p['active']));
  echo '<h3>مدد التمويل المتاحة</h3><div class="adc-fa-checks">';foreach([12,24,36,48,60] as $m)echo '<label><input type="checkbox" name="'.esc_attr($prefix.'[months][]').'" value="'.$m.'"'.checked(in_array($m,array_map('intval',$p['months']??[]),true),true,false).'> '.$m.' شهرًا</label>';echo '</div><h3>القيم الأساسية</h3><p>اترك القيمة فارغة إذا كانت تحتاج عرضًا من الجهة. الرقم صفر يعني قيمة مؤكدة تساوي صفرًا. الشروط الخاصة تعدّل القيم حسب شريحة العميل.</p><div class="adc-fa-grid">';
  self::select($prefix.'[admin_mode]','نوع الرسوم',['percent'=>'نسبة من مبلغ التمويل %','fixed'=>'مبلغ ثابت (ريال)'],$p['admin_mode']??'percent');
  foreach(self::fields() as $field=>$label)self::input($prefix.'[values]['.$field.']',$label,$p['values'][$field]??'','number',false,($field==='min_salary'||($field==='admin'&&($p['admin_mode']??'')==='fixed'))?100000000:100);
  self::input($prefix.'[admin_cap]','الحد الأعلى للرسوم (ريال، اختياري)',$p['admin_cap']??'','number',false,100000000);self::input($prefix.'[price_min]','الحد الأدنى لسعر السيارة (ريال)',$p['price_min']??0,'number',true,100000000);echo '</div>';
  self::checkbox($prefix.'[price_strict]','السعر أعلى من الحد الأدنى، وليس مساويًا له',!empty($p['price_strict']));self::checkbox($prefix.'[approved_only]','البرنامج للجهات المعتمدة فقط',!empty($p['approved_only']));
  echo '<h3>القطاعات المشمولة</h3><div class="adc-fa-checks">';foreach(self::sectors() as $s=>$label)echo '<label><input type="checkbox" name="'.esc_attr($prefix.'[sectors][]').'" value="'.esc_attr($s).'"'.checked(in_array($s,$p['sectors']??[],true),true,false).'> '.esc_html($label).'</label>';echo '</div><h3>الجنسيات المشمولة</h3><div class="adc-fa-checks">';foreach(['saudi'=>'سعودي','expat'=>'مقيم'] as $s=>$label)echo '<label><input type="checkbox" name="'.esc_attr($prefix.'[nationalities][]').'" value="'.esc_attr($s).'"'.checked(in_array($s,$p['nationalities']??[],true),true,false).'> '.esc_html($label).'</label>';echo '</div>';
  self::textarea($prefix.'[brands]','العلامات المشمولة بالإنجليزية، بفاصلة (فارغ = لا توجد قائمة مقيدة)',is_array($p['brands']??null)?implode(', ',$p['brands']):($p['brands']??''));echo '<div class="adc-fa-grid">';self::textarea($prefix.'[notes][0]','ملاحظات للعميل بالعربية',$p['notes'][0]??'');self::textarea($prefix.'[notes][1]','ملاحظات للعميل بالإنجليزية',$p['notes'][1]??'');echo '</div>';
  self::checkbox($prefix.'[tenure_assumed]','مدة التمويل تحتاج تأكيدًا (تستبعد من شارات الأفضل)',!empty($p['tenure_assumed']));self::checkbox($prefix.'[payments_assumed]','نسب الدفعات تحتاج تأكيدًا (تستبعد من شارات الأفضل)',!empty($p['payments_assumed']));
  echo '<h3>شروط وقيم خاصة حسب العميل</h3><p>تطبق من أعلى إلى أسفل؛ عند تطابق عدة شروط، تعتمد آخر قيمة لكل حقل. الحقل الفارغ لا يغيّر القيمة الأساسية.</p><div data-finance-rules>';foreach($p['rules']??[] as $index=>$rule)self::rule($prefix,(string)$index,$rule);echo '</div><template data-finance-rule-template>';self::rule($prefix,'__INDEX__',['when'=>[],'values'=>[],'verify'=>false,'notes'=>['','']]);echo '</template><button type="button" class="button" data-finance-add-rule>إضافة شرط خاص</button></section>';
 }
 private static function rule(string $prefix,string $index,array $rule): void {
  $prefix.='[rules]['.$index.']';echo '<details class="adc-fa-rule" data-finance-rule'.($index==='__INDEX__'?' open':'').'><summary>شرط خاص <span data-rule-number>'.esc_html(is_numeric($index)?(int)$index+1:'').'</span> — '.esc_html(self::rule_summary($rule)).'</summary><div class="adc-fa-grid">';
  $choices=['sector'=>self::sectors(),'nationality'=>['saudi'=>'سعودي','expat'=>'مقيم'],'transfer'=>['st'=>'محول راتب','nst'=>'غير محول'],'employer'=>['approved'=>'معتمد','unapproved'=>'غير معتمد'],'category'=>['A'=>'A','B'=>'B','C'=>'C','D'=>'D'],'chinese'=>['yes'=>'صيني','no'=>'غير صيني'],'months'=>[12=>'12',24=>'24',36=>'36',48=>'48',60=>'60']];$labels=['sector'=>'القطاع','nationality'=>'الجنسية','transfer'=>'تحويل الراتب','employer'=>'اعتماد جهة العمل','category'=>'فئة العلامة','chinese'=>'منشأ العلامة','months'=>'مدة التمويل'];
  foreach($choices as $field=>$list)self::select($prefix.'[when]['.$field.']',$labels[$field],[''=>'أي قيمة']+$list,$rule['when'][$field]??'');self::input($prefix.'[when][salary_min]','الراتب من (شامل)',$rule['when']['salary_min']??'','number',false,100000000);self::input($prefix.'[when][salary_max]','الراتب حتى (شامل)',$rule['when']['salary_max']??'','number',false,100000000);echo '</div><h4>القيم عند تحقق الشرط</h4><div class="adc-fa-grid">';
  foreach(self::fields() as $field=>$label)self::input($prefix.'[values]['.$field.']',$label,$rule['values'][$field]??'','number',false,$field==='min_salary'||$field==='admin'?100000000:100);echo '</div><div class="adc-fa-grid">';self::textarea($prefix.'[notes][0]','ملاحظة بالعربية',$rule['notes'][0]??'');self::textarea($prefix.'[notes][1]','ملاحظة بالإنجليزية',$rule['notes'][1]??'');echo '</div>';self::checkbox($prefix.'[verify]','يلزم تحقق إضافي — تستبعد النتيجة من شارات الأفضل',!empty($rule['verify']));echo '<p><button type="button" class="button adc-fa-delete" data-finance-remove-rule>حذف الشرط</button> <button type="button" class="button" data-finance-move-rule="up">تحريك للأعلى</button> <button type="button" class="button" data-finance-move-rule="down">تحريك للأسفل</button></p></details>';
 }
 private static function rule_summary(array $rule): string {
  $names=['sector'=>'قطاع','nationality'=>'جنسية','transfer'=>'تحويل','employer'=>'اعتماد','category'=>'فئة','chinese'=>'صيني','months'=>'أشهر','salary_min'=>'راتب من','salary_max'=>'راتب حتى'];
  $display=self::sectors()+['saudi'=>'سعودي','expat'=>'مقيم','st'=>'محول','nst'=>'غير محول','approved'=>'معتمد','unapproved'=>'غير معتمد','yes'=>'نعم','no'=>'لا'];
  $parts=[];foreach($rule['when']??[] as $field=>$value)if($value!=='')$parts[]=($names[$field]??$field).': '.($display[$value]??$value);
  if(!$parts)$parts[]='جميع العملاء';
  foreach($rule['values']??[] as $field=>$value)if($value!==null&&$value!=='')$parts[]=(self::fields()[$field]??$field).': '.$value;
  if(!empty($rule['verify']))$parts[]='يلزم تحقق إضافي';return implode(' | ',$parts);
 }
 private static function settings(array $s,?string $revision): void {
  self::start_form('settings','',$revision);echo '<section class="adc-fa-card"><h2>إعدادات الحساب</h2><div class="adc-fa-grid">';foreach(['debt_limit'=>'الحد الأقصى للقسط والالتزامات من الراتب %','insurance_min'=>'أقل نسبة تأمين سنوية %','insurance_max'=>'أعلى نسبة تأمين سنوية %','insurance_default'=>'نسبة التأمين الافتراضية %'] as $key=>$label)self::input('settings['.$key.']',$label,$s[$key]??'','number',true,100);self::input('settings[source_period]','فترة مرجع البيانات (اختياري)',$s['source_period']??'');echo '</div><h3>قوائم العلامات حسب الفئة</h3>';foreach(['A','B','C','D'] as $cat)self::textarea('settings[categories]['.$cat.']','علامات الفئة '.$cat,$s['categories'][$cat]??'');echo '<div class="adc-fa-grid">';self::textarea('settings[category_note][0]','توضيح الفئات بالعربية',$s['category_note'][0]??'');self::textarea('settings[category_note][1]','توضيح الفئات بالإنجليزية',$s['category_note'][1]??'');echo '</div></section>';self::end_form('حفظ إعدادات الحاسبة');
 }
 public static function save(): void {
  self::guard();check_admin_referer('adc_finance_config');$raw=wp_unslash($_POST);$operation=is_string($raw['operation']??null)?$raw['operation']:'';$id=is_string($raw['id']??null)?sanitize_key($raw['id']):'';$redirect=[];
  try {
   if(isset($raw['payload'])){if(!is_string($raw['payload'])||strlen($raw['payload'])>1000000)throw new \InvalidArgumentException('حجم البيانات غير صحيح.');$payload=json_decode($raw['payload'],true,64,JSON_THROW_ON_ERROR);if(!is_array($payload))throw new \InvalidArgumentException('بيانات غير صحيحة.');$raw=array_merge($raw,array_intersect_key($payload,array_flip(['provider','settings','complete'])));}
   $config=Config::get();$revision=$raw['revision']??'';if(!is_string($revision))throw new \InvalidArgumentException('مراجعة الحفظ غير صحيحة.');
   if(in_array($operation,['provider','settings'],true)&&($raw['complete']??'')!=='1')throw new \InvalidArgumentException('لم تكتمل بيانات النموذج. أعد تحميل الصفحة وحاول مجددًا.');
   if($operation==='provider'){$redirect=$id!==''?['edit'=>$id]:['new'=>1];if(!is_array($raw['provider']??null))throw new \InvalidArgumentException('بيانات الجهة غير صحيحة.');if($id!==''&&!isset($config['providers'][$id]))throw new \InvalidArgumentException('الجهة غير موجودة.');$provider=Config::validate_provider($raw['provider']);if($id==='')$id='provider_'.str_replace('-','',wp_generate_uuid4());$config['providers'][$id]=$provider;$redirect=['edit'=>$id];}
   elseif($operation==='settings'){$redirect=['settings'=>1];if(!is_array($raw['settings']??null))throw new \InvalidArgumentException('الإعدادات غير صحيحة.');$config['settings']=Config::validate_settings($raw['settings']);}
   elseif(in_array($operation,['toggle','delete'],true)){if(!isset($config['providers'][$id]))throw new \InvalidArgumentException('الجهة غير موجودة.');if($operation==='delete'){if(($raw['confirm_delete']??'')!=='1')throw new \InvalidArgumentException('أكد حذف الجهة.');unset($config['providers'][$id]);}else $config['providers'][$id]['active']=!$config['providers'][$id]['active'];}
   else throw new \InvalidArgumentException('عملية غير صحيحة.');
   $result=Config::save($config,$revision);if(is_wp_error($result))throw new \RuntimeException($result->get_error_message());
   \AutoDealership\Audit\AuditLog::record('finance_calculator.'.$operation,'finance_configuration',0,'Finance calculator configuration updated',null,['provider_id'=>$id,'revision'=>Config::revision()]);wp_safe_redirect(self::url(array_merge($redirect,['saved'=>1])));exit;
  }catch(\Throwable $e){if($operation==='provider')$redirect=$id!==''&&isset(Config::get()['providers'][$id])?['edit'=>$id]:['new'=>1];if($operation==='settings')$redirect=['settings'=>1];set_transient('adc_finance_editor_'.get_current_user_id(),['error'=>$e->getMessage(),'raw'=>$raw],10*MINUTE_IN_SECONDS);wp_safe_redirect(self::url(array_merge($redirect,['error'=>1])));exit;}
 }
}
