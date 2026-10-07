<?php
/** Shared calculator view for the theme adapter and all shortcode fallbacks. */
defined('ABSPATH') || exit;
use AutoDealership\Tools\LoanCalculator;
use AutoDealership\Tools\FinanceConfiguration;
$settings=FinanceConfiguration::get()['settings'];
$label=static fn($ar,$en)=>LoanCalculator::text($ar,$en);
$select=static function($name,$ar,$en,$options,$default) use($label) {
 echo '<label>'.esc_html($label($ar,$en)).'<select name="'.esc_attr($name).'" required>';
 foreach($options as $value=>$texts) echo '<option value="'.esc_attr($value).'"'.selected((string)$value,(string)$default,false).'>'.esc_html($label(...$texts)).'</option>';
 echo '</select></label>';
};
$providers=['all'=>['مقارنة كافة البنوك والشركات','Compare all banks and companies']];
foreach(LoanCalculator::rules() as $id=>$bank) $providers[$id]=$bank['name'];
?>
<section class="cd-tool cd-loan-calculator adc-finance-calculator" data-finance-widget>
 <h2><?php echo esc_html($label('مقارنة التمويل حسب البنك','Compare financing by provider')); ?></h2>
 <p class="adc-finance-notice"><?php echo esc_html($label('أدخل بياناتك لمقارنة الأقساط والتكاليف لدى جهات التمويل.','Enter your details to compare payments and costs across financing providers.')); ?></p>
 <form data-loan-calculator>
  <input type="hidden" name="lang" value="<?php echo esc_attr(\AutoDealership\Core\Localization::language()); ?>">
  <div class="cd-form-grid adc-finance-inputs">
   <label><?php echo esc_html($label('سعر السيارة شامل ضريبة 15% (ريال)','Car price including 15% VAT (SAR)')); ?><input name="price" data-loan-price type="number" min="0.01" max="100000000" step="0.01" value="<?php echo esc_attr($model['price']>0?$model['price']:''); ?>" required></label>
   <label><?php echo esc_html($label('الراتب الشهري (ريال)','Monthly salary (SAR)')); ?><input name="salary" type="number" min="0.01" max="100000000" step="0.01" required></label>
   <?php
   $select('sector','قطاع العمل','Employment sector', ['government'=>['حكومي / مدني','Government / Civilian'],'military'=>['عسكري','Military'],'private_approved'=>['خاص معتمد','Approved private'],'private_unapproved'=>['خاص غير معتمد','Non-approved private'],'retired'=>['متقاعد','Retired'],'self_employed'=>['أصحاب مؤسسات','Self-employed']],'government');
   $select('nationality','الجنسية','Nationality',['saudi'=>['سعودي','Saudi'],'expat'=>['مقيم','Expat']],'saudi');
   $select('transfer','تحويل الراتب','Salary transfer',['st'=>['محول راتب (ST)','Salary transfer (ST)'],'nst'=>['غير محول (NST)','Non-transfer (NST)']],'st');
   $select('employer','اعتماد جهة العمل لدى البنك','Employer approval at provider',['approved'=>['جهة معتمدة','Approved employer'],'unapproved'=>['جهة غير معتمدة','Non-approved employer']],'approved');
   $select('provider','جهة التمويل','Financing provider',$providers,'all');
   $select('months','المدة بالأشهر','Tenure in months',[12=>['12 شهرًا','12 months'],24=>['24 شهرًا','24 months'],36=>['36 شهرًا','36 months'],48=>['48 شهرًا','48 months'],60=>['60 شهرًا','60 months']],60);
   $select('campaign','نوع البرنامج','Campaign',['regular'=>['أقساط شهرية منتظمة','Regular monthly'],'half'=>['برنامج 50/50','50/50 program'],'leasing'=>['برنامج التأجير','Leasing program']],'regular');
   $select('category','فئة العلامة التجارية','Vehicle brand category',['A'=>['الفئة A','Category A'],'B'=>['الفئة B','Category B'],'C'=>['الفئة C','Category C'],'D'=>['الفئة D','Category D']],'A');
   $select('chinese','هل العلامة صينية؟','Is the brand Chinese?',['no'=>['لا','No'],'yes'=>['نعم','Yes']],'no');
   ?>
   <label><?php echo esc_html($label('العلامة التجارية (بالإنجليزية)','Vehicle brand (English)')); ?><input name="brand" type="text" maxlength="80" placeholder="Toyota" dir="ltr"></label>
   <label><?php echo esc_html($label('الالتزامات الشهرية الحالية (ريال)','Existing monthly obligations (SAR)')); ?><input name="obligations" type="number" min="0" max="100000000" step="0.01" value="0" required></label>
   <label><?php echo esc_html($label('نسبة التأمين السنوية التقديرية %','Estimated annual insurance %')); ?><input name="insurance_rate" type="number" min="<?php echo esc_attr($settings['insurance_min']); ?>" max="<?php echo esc_attr($settings['insurance_max']); ?>" step="0.01" value="<?php echo esc_attr($settings['insurance_default']); ?>" required></label>
  </div>
  <fieldset class="adc-finance-assumptions"><legend><?php echo esc_html($label('بيانات إضافية لحساب التمويل','Additional financing details')); ?></legend>
   <p><?php echo esc_html($label('إذا كانت لديك تفاصيل من جهة التمويل، يمكنك إدخالها هنا. نستخدم هذه القيم فقط عندما لا تتوفر في بيانات الجهة، ونوضحها في تفاصيل النتيجة. النتائج التي تعتمد على قيم أدخلتها لا تدخل في اختيار أقل قسط أو أقل نسبة ربح.','If you have details from the financing provider, enter them here. These values are used only when missing from the provider data and are identified in the result details. Results using your supplied values are excluded from the lowest-payment and lowest-rate badges.')); ?></p>
   <div class="adc-finance-inputs">
    <label><?php echo esc_html($label('الدفعة الأولى % ','Down payment % ')); ?><input name="assumed_down" type="number" min="0" max="100" step="0.01"></label>
    <label><?php echo esc_html($label('الدفعة الأخيرة % ','Balloon payment % ')); ?><input name="assumed_balloon" type="number" min="0" max="100" step="0.01"></label>
    <label><?php echo esc_html($label('الرسوم الإدارية (ريال) ','Administrative fees (SAR)')); ?><input name="assumed_admin" type="number" min="0" max="100000000" step="0.01"></label>
   </div>
  </fieldset>
  <details class="adc-finance-brands" open><summary><?php echo esc_html($label('مرجع فئات العلامات','Brand category reference')); ?></summary>
   <?php foreach($settings['categories'] as $category=>$brands): ?>
   <p dir="ltr"><strong><?php echo esc_html($category); ?>:</strong> <?php echo esc_html($brands); ?></p>
   <?php endforeach; ?>
   <?php if($label(...$settings['category_note'])!==''): ?><p><?php echo esc_html($label(...$settings['category_note'])); ?></p><?php endif; ?>
  </details>
  <p class="adc-finance-note"><?php echo esc_html($label('تعتمد المدد والنسب على البرامج المتاحة لدى جهة التمويل. التأمين تقديري ويحسب على سعر السيارة كاملًا.','Tenures and rates depend on the provider programs. Insurance is estimated on the full car price.')); ?></p>
  <p class="adc-finance-note"><?php echo esc_html($label('فحص الالتزامات يشمل القسط والتأمين والالتزامات الحالية. قد تنطبق حدود استقطاع إضافية، منها 33.33% للموظف و25% للمتقاعد في الحالات المنصوص عليها.','The obligations screen includes payment, insurance and existing obligations. Additional salary deduction limits may apply, including 33.33% for employees and 25% for retirees in the specified cases.')); ?> <a href="https://rulebook.sama.gov.sa/en/chapter-iv-quantitative-principles-responsible-lending" target="_blank" rel="noopener noreferrer"><?php echo esc_html($label('مبادئ التمويل المسؤول — ساما','Responsible lending principles — SAMA')); ?></a></p>
  <button class="btn btn-primary" type="submit"><?php echo esc_html($label('احسب وقارن','Calculate and compare')); ?></button>
  <p data-loan-status role="status" aria-live="polite"></p>
  <div data-loan-results></div>
 </form>
</section>
