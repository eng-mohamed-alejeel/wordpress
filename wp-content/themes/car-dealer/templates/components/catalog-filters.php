<?php
defined('ABSPATH') || exit;
/** @var callable(string): string $get Query reader provided by filter-bar.php. */
/** @var string $archive_url Archive URL provided by filter-bar.php. */
/** @var array $filter_model Catalog options provided by filter-bar.php. */
$choices=$filter_model['options']['catalog_choices']??array();
foreach($choices as &$choice){foreach(array('brand','model','trim_name','exterior_color')as$field){$choice[$field.'_label']=car_dealer_catalog_value_label((string)$choice[$field]);}}unset($choice);
$text=static fn($ar,$en)=>car_dealer_text($ar,$en);
$select=static function($name,$field,$label)use($choices,$get,$text){
 $rows=array_filter($choices,static fn($r)=>($name==='brand'||!$get('brand')||$r['brand']===$get('brand'))&&(!in_array($name,array('trim','exterior_color'),true)||!$get('model')||$r['model']===$get('model')));
 $values=array_values(array_unique(array_filter(array_map('strval',wp_list_pluck($rows,$field)))));
 echo '<label><span>'.esc_html($label).'</span><select name="'.esc_attr($name).'" data-catalog-choice="'.esc_attr($field).'"><option value="">'.esc_html($text('الكل','All')).'</option>';
 foreach($values as $v){echo '<option value="'.esc_attr($v).'" '.selected($get($name),$v,false).'>'.esc_html(car_dealer_catalog_value_label($v)).'</option>';}echo '</select></label>';
};
$number=static function($name,$label,$year=false)use($get){echo '<label><span>'.esc_html($label).'</span><input type="number" name="'.esc_attr($name).'" min="'.($year?'1900':'0').'" '.($year?'max="2200"':'step="0.01"').' value="'.esc_attr($get($name)).'"></label>';};
?>
<form class="filter-bar cd-customer-filters" data-customer-filters method="get" action="<?php echo esc_url($archive_url); ?>" aria-label="<?php echo esc_attr($text('تصفية السيارات','Filter vehicles')); ?>">
<?php if(!get_option('permalink_structure')): ?><input type="hidden" name="post_type" value="car"><?php endif; ?>
<input type="hidden" name="lang" value="<?php echo esc_attr(car_dealer_catalog_language()); ?>">
<div class="cd-filter-primary">
<label><span><?php echo esc_html($text('بحث عن سيارة','Search vehicles')); ?></span><input type="search" name="search" maxlength="120" value="<?php echo esc_attr($get('search')); ?>" placeholder="<?php echo esc_attr($text('الماركة أو الطراز أو الفئة','Brand, model or trim')); ?>"></label>
<?php $select('brand','brand',$text('الماركة','Brand'));$select('model','model',$text('الطراز','Model'));$number('max_price',$text('ميزانيتك حتى (ريال)','Budget up to (SAR)')); ?>
<label><span><?php echo esc_html($text('ترتيب النتائج','Sort results')); ?></span><select name="sort">
<?php foreach(array('newest'=>array('الأحدث','Newest'),'price_asc'=>array('السعر: الأقل أولًا','Price: low to high'),'price_desc'=>array('السعر: الأعلى أولًا','Price: high to low'),'year_desc'=>array('سنة الموديل: الأحدث','Model year: newest'))as$v=>$labels){echo '<option value="'.esc_attr($v).'" '.selected($get('sort')?:'newest',$v,false).'>'.esc_html($text($labels[0],$labels[1])).'</option>';} ?>
</select></label></div>
<details class="cd-filter-more" <?php if($get('trim')||$get('exterior_color')||$get('min_year')||$get('max_year')||$get('min_price')){echo 'open';} ?>>
<summary><?php echo esc_html($text('خيارات تصفية إضافية','More filters')); ?></summary>
<div class="cd-filter-secondary">
<?php $select('trim','trim_name',$text('الفئة','Trim'));$select('exterior_color','exterior_color',$text('اللون','Color'));$number('min_year',$text('سنة الموديل من','Model year from'),true);$number('max_year',$text('سنة الموديل إلى','Model year to'),true);$number('min_price',$text('السعر من (ريال)','Price from (SAR)')); ?>
</div></details>
<div class="filter-actions"><button class="btn btn-primary" type="submit"><?php echo esc_html($text('عرض النتائج','Show results')); ?></button><a class="btn btn-outline" href="<?php echo esc_url(car_dealer_catalog_localized_url($archive_url)); ?>"><?php echo esc_html($text('مسح الفلاتر','Clear filters')); ?></a></div>
<script type="application/json" data-catalog-choices><?php echo wp_json_encode($choices,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
</form>
