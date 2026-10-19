<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
use AutoDealership\Pricing\Money;
use AutoDealership\Pricing\PublicVehiclePrice;
defined('ABSPATH')||exit;

/** One editorial page per brand/model/trim/year; physical identifiers never enter its public read model. */
final class GroupedModelCatalog {
	public static function boot(): void {
		$enqueue = static function(): void {
			$screen = function_exists('get_current_screen') ? get_current_screen() : null;
			$editor = $screen && $screen->post_type === 'car' && self::is_mapped(absint($_GET['post'] ?? 0));
			if (!$editor && !self::enabled() && !(is_singular('car') && self::is_mapped((int)get_queried_object_id())) && !(is_preview() && is_singular('car'))) { return; }
			wp_enqueue_style('adc-model-variants', plugin_dir_url(ADC_FILE).'assets/css/model-variants.css', array(), ADC_VERSION);
			wp_enqueue_script('adc-model-variants', plugin_dir_url(ADC_FILE).'assets/js/model-variants.js', array(), ADC_VERSION, true);
		};
		add_action('wp_enqueue_scripts', $enqueue);
		add_action('admin_enqueue_scripts', $enqueue);
	}
	public static function enabled():bool{return get_option('adc_public_catalog_mode')==='models'&&get_option('adc_db_version')===Schema::VERSION;}
	private static function editorial_features(int $post_id): array {
		$raw = get_post_meta($post_id, '_car_features', true);
		$values = is_array($raw) ? $raw : explode("\n", (string)$raw);
		return array_values(array_unique(array_filter(array_map('trim', array_map('strval', array_filter($values, 'is_scalar'))))));
	}
	public static function is_mapped(int $post_id): bool {
		global $wpdb;
		return get_option('adc_db_version') === Schema::VERSION && (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Schema::table('catalog_models').' WHERE public_post_id=%d', $post_id));
	}
	public static function query(array $filters):\WP_Query{return new \WP_Query(array('post_type'=>'car','post_status'=>'publish','posts_per_page'=>$filters['per_page']??12,'paged'=>$filters['page']??1,'adc_public_catalog'=>true,'adc_catalog_filters'=>$filters));}
	public static function catalog(array $filters):array{$query=self::query($filters);$items=array();foreach($query->posts as$post){$view=self::view((int)$post->ID);if($view){$items[]=array('id'=>(int)$post->ID,'public_post_id'=>(int)$post->ID,'brand'=>$view['brand_name'],'model'=>$view['model'],'model_year'=>$view['year'],'retail_price'=>$view['price']!==''?Money::from_sar($view['price']):0,'currency'=>'SAR','status'=>'available','colors'=>$view['catalog_model']['colors'],'variants'=>$view['catalog_model']['variants'],'url'=>get_permalink($post));}}return $items;}
	public static function options():array {
 global $wpdb;
 $rows=$wpdb->get_results('SELECT DISTINCT s.brand,s.model,s.trim_name,s.model_year,s.exterior_color FROM '.Schema::table('onyx_stock').' s INNER JOIN '.Schema::table('catalog_models')." m ON m.id=s.model_id INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE ".self::availability().' ORDER BY s.brand,s.model,s.trim_name,s.model_year DESC,s.exterior_color',ARRAY_A);
 $rows=$wpdb->last_error?array():($rows?:array());
 return array('body_type'=>array(),'fuel_type'=>array(),'transmission'=>array(),'condition_key'=>array(),'branches'=>array(),'brand'=>array_values(array_unique(wp_list_pluck($rows,'brand'))),'catalog_choices'=>$rows);
 }
	public static function readiness():array{
		global $wpdb;$models=Schema::table('catalog_models');$stock=Schema::table('onyx_stock');$published=(int)$wpdb->get_var("SELECT COUNT(*) FROM $models m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' AND p.post_status='publish'");$eligible=(int)$wpdb->get_var("SELECT COUNT(*) FROM $models m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' AND p.post_status='publish' WHERE EXISTS (SELECT 1 FROM $stock s WHERE s.model_id=m.id AND ".self::availability().')');$invalid=(int)$wpdb->get_var("SELECT COUNT(*) FROM $models m LEFT JOIN {$wpdb->posts} p ON p.ID=m.public_post_id WHERE m.public_post_id IS NOT NULL AND (p.ID IS NULL OR p.post_type<>'car')");
		return array('schema_ready'=>true,'operational'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $stock WHERE present=1"),'eligible'=>$eligible,'published_posts'=>$published,'unmapped_published_posts'=>0,'duplicate_mappings'=>0,'invalid_mappings'=>$invalid,'ready'=>$eligible>0&&$invalid===0&&!$wpdb->last_error);
	}
	public static function availability():string{ $v=Schema::table('vehicles');$b=Schema::table('branches');return "s.present=1 AND (s.vehicle_id IS NULL OR EXISTS (SELECT 1 FROM $v gv INNER JOIN $b gb ON gb.id=gv.branch_id AND gb.active=1 WHERE gv.id=s.vehicle_id AND gv.status='available'))"; }
	public static function filter_linked_availability(array $clauses): array {
		global $wpdb;
		$models = Schema::table('catalog_models'); $stock = Schema::table('onyx_stock');
		$clauses['where'] .= " AND (NOT EXISTS (SELECT 1 FROM $models gm WHERE gm.public_post_id={$wpdb->posts}.ID) OR EXISTS (SELECT 1 FROM $models gm INNER JOIN $stock s ON s.model_id=gm.id WHERE gm.public_post_id={$wpdb->posts}.ID AND ".self::availability().'))';
		return $clauses;
	}
	public static function clauses(array $clauses,\WP_Query $query):array{
		global $wpdb;$models=Schema::table('catalog_models');$stock=Schema::table('onyx_stock');$where=self::availability();$filters=(array)$query->get('adc_catalog_filters');
		foreach(array('brand'=>'brand','model'=>'model','trim'=>'trim_name','exterior_color'=>'exterior_color','interior_color'=>'interior_color')as$key=>$field){if(!empty($filters[$key])){$where.=$wpdb->prepare(" AND s.$field=%s",$filters[$key]);}}
		foreach(array('min_price'=>array('listed_price','>='),'max_price'=>array('listed_price','<='),'year'=>array('model_year','='),'min_year'=>array('model_year','>='),'max_year'=>array('model_year','<='))as$key=>$comparison){if(isset($filters[$key])&&$filters[$key]!==''){$expression=$comparison[0]==='listed_price'?PublicVehiclePrice::sql('s.listed_price',"{$wpdb->posts}.ID"):'s.'.$comparison[0];$where.=$wpdb->prepare(' AND '.$expression.$comparison[1].'%d',(int)$filters[$key]);}}
		if(!empty($filters['search'])){$pattern='%'.$wpdb->esc_like($filters['search']).'%';$where.=$wpdb->prepare(' AND (s.brand LIKE %s OR s.model LIKE %s OR s.trim_name LIKE %s)',$pattern,$pattern,$pattern);}
		$clauses['where'].=" AND EXISTS (SELECT 1 FROM $models gm INNER JOIN $stock s ON s.model_id=gm.id WHERE gm.public_post_id={$wpdb->posts}.ID AND $where)";
		$sort=$filters['sort']??'';if(in_array($sort,array('price_asc','price_desc','year_desc'),true)){$expression=$sort==='year_desc'?'MAX(s.model_year)':'MIN(NULLIF('.PublicVehiclePrice::sql('s.listed_price',"{$wpdb->posts}.ID").',0))';$direction=$sort==='price_asc'?'ASC':'DESC';$clauses['orderby']="(SELECT $expression FROM $stock s INNER JOIN $models gm ON gm.id=s.model_id WHERE gm.public_post_id={$wpdb->posts}.ID AND ".self::availability().") $direction, {$wpdb->posts}.ID DESC";}
		return $clauses;
	}
	public static function view(int $post_id):?array{
		global $wpdb;$model=$wpdb->get_row($wpdb->prepare('SELECT id,brand,model FROM '.Schema::table('catalog_models').' WHERE public_post_id=%d',$post_id),ARRAY_A);if(!$model){return null;}
		$variants=$wpdb->get_results($wpdb->prepare('SELECT s.model_year,s.trim_name,s.exterior_color,s.interior_color,s.listed_price,COUNT(*) available_count,MIN(NULLIF(s.listed_price,0)) min_price,MAX(s.listed_price) max_price FROM '.Schema::table('onyx_stock').' s WHERE s.model_id=%d AND '.self::availability().' GROUP BY s.model_year,s.trim_name,s.exterior_color,s.interior_color,s.listed_price ORDER BY s.model_year DESC,s.trim_name,s.exterior_color,s.listed_price',$model['id']),ARRAY_A);if(!$variants||$wpdb->last_error){return null;}
		$options = array();
		foreach ($variants as &$variant) {
			$amounts = PublicVehiclePrice::calculate((int)$variant['listed_price'], $post_id);
			$variant['public_price'] = $amounts['final_amount'];
			$variant['price_breakdown'] = PublicVehiclePrice::public_breakdown($amounts);
		}
		unset($variant);
		foreach ($variants as $variant) {
			$key = wp_json_encode(array($variant['model_year'], $variant['trim_name'], $variant['exterior_color'], $variant['listed_price']));
			if (!isset($options[$key])) {
				$options[$key] = array('brand'=>$model['brand'], 'model'=>$model['model'], 'trim_name'=>$variant['trim_name'], 'exterior_color'=>$variant['exterior_color'], 'model_year'=>$variant['model_year'], 'listed_price'=>$variant['listed_price'], 'public_price'=>$variant['public_price'], 'price_breakdown'=>$variant['price_breakdown']);
				$options[$key]['full_name']=OnyxCatalogNames::row($options[$key]);
			}
		}
		$colors=array_values(array_unique(array_filter(wp_list_pluck($variants,'exterior_color'))));$years=array_values(array_unique(wp_list_pluck($variants,'model_year')));$prices=array_filter(wp_list_pluck($variants,'public_price'),static fn($price)=>$price!==null&&(int)$price>0);
		return array('post_id'=>$post_id,'price_label'=>PublicVehiclePrice::label($post_id),'vehicle'=>null,'price'=>$prices?Money::decimal((string)min($prices)):'','price_from'=>count(array_unique($prices))>1||count($prices)<count($variants),'monthly'=>'','year'=>implode(' / ',$years),'model'=>$model['model'],'color'=>implode(' / ',array_map(static fn($color)=>__( $color, 'auto-dealership-core' ),$colors)),'kilometers'=>'','transmission'=>'','fuel'=>'','condition'=>'','status'=>'available','featured'=>(bool)get_post_meta($post_id,'_car_featured',true),'features'=>self::editorial_features($post_id),'brand_name'=>$model['brand'],'category'=>'','catalog_model'=>array('options'=>array_values($options),'variants'=>$variants,'colors'=>$colors,'available_count'=>array_sum(wp_list_pluck($variants,'available_count'))));
	}
	public static function render_variants(int $post_id): void {
		if(!is_admin()&&current_theme_supports('adc-color-configurator')){return;}
		$view = self::view($post_id);
		if (!$view) { return; }
		$ar = \AutoDealership\Core\Localization::language() !== 'en';
		$options = $view['catalog_model']['options'];
		echo '<section class="onyx-variants" data-empty="'.esc_attr($ar?'لا توجد خيارات متاحة بهذه المواصفات.':'No available variants match these selections.').'" data-matches="'.esc_attr($ar?'خيارات متاحة':'available variants').'"><h2>'.esc_html($ar?'معلومات السيارة والخيارات المتاحة':'Vehicle information and available options').'</h2>';
		echo '<p>'.esc_html($ar?'تُعرض أحدث بيانات السيارة المتاحة. سيتحقق فريقنا من التوفر والسعر النهائي قبل تأكيد الحجز.':'This page shows the latest available vehicle information. Our team will confirm availability and the final price before confirming your reservation.').'</p><div class="onyx-variant-filters">';
		foreach (array('model_year'=>$ar?'الموديل':'Model year', 'trim_name'=>$ar?'الفئة':'Trim', 'exterior_color'=>$ar?'اللون':'Color') as $field=>$label) {
			echo '<label>'.esc_html($label).'<select data-variant-field="'.esc_attr($field).'"><option value="">'.esc_html($ar?'الكل':'All').'</option>';
			foreach (array_unique(wp_list_pluck($options, $field)) as $value) {
				if ('' !== (string)$value) { echo '<option value="'.esc_attr($value).'">'.esc_html(__((string)$value, 'auto-dealership-core')).'</option>'; }
			}
			echo '</select></label>';
		}
		echo '<button type="button" data-variant-reset>'.esc_html($ar?'إعادة ضبط':'Reset').'</button></div><p data-variant-result role="status" aria-live="polite"></p><div class="onyx-variant-scroll" tabindex="0" role="region" aria-label="'.esc_attr($ar?'فئات وألوان السيارة':'Vehicle variants and colors').'"><table class="onyx-variant-table"><thead><tr>';
		$columns = array('brand'=>$ar?'الماركة':'Brand', 'model'=>$ar?'الطراز':'Model', 'trim_name'=>$ar?'الفئة':'Trim', 'exterior_color'=>$ar?'اللون':'Color', 'model_year'=>$ar?'الموديل':'Model year', 'listed_price'=>$ar?'سعر البيع':'Sale price');
		foreach ($columns as $label) { echo '<th scope="col">'.esc_html($label).'</th>'; }
		echo '</tr></thead><tbody>';
		foreach ($options as $row) {
			echo '<tr';
			foreach (array('model_year', 'trim_name', 'exterior_color') as $field) { echo ' data-'.$field.'="'.esc_attr($row[$field]).'"'; }
			echo '>';
			foreach ($columns as $field=>$label) {
				$value = 'listed_price' === $field ? ((int)$row[$field] > 0 ? Money::display((string)$row['public_price']) : ($ar?'اسأل عن السعر':'Ask for price')) : __((string)($row[$field] ?: '—'), 'auto-dealership-core');
				echo '<td>'.esc_html($value).'</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div><p class="public-price-inclusions">'.esc_html($view['price_label']).'</p></section>';
	}
}
