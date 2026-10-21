<?php
namespace AutoDealership\Inventory;

use AutoDealership\Core\Localization;

defined( 'ABSPATH' ) || exit;

/** Editorial specifications never write to operational inventory or imported Onyx data. */
final class VehicleTechnicalDetails {
	public const META = '_adc_vehicle_technical_details';
	public const OPTION = 'adc_vehicle_technical_options';
	private const FIELDS = array( 'fuel', 'transmission', 'capacity', 'capacity_unit', 'speeds', 'body_type', 'usage', 'equipment' );
	public static function t( string $ar, string $en ): string { return 'en' === Localization::language() ? $en : $ar; }
	public static function labels(): array {
		return array( 'fuel'=>self::t('نوع الوقود والطاقة','Fuel and energy'), 'transmission'=>self::t('ناقل الحركة','Transmission'), 'capacity'=>self::t('سعة المحرك','Engine capacity'), 'body_type'=>self::t('نوع الهيكل','Body type'), 'usage'=>self::t('تصنيف الاستخدام','Vehicle use'), 'equipment'=>self::t('التجهيز التجاري','Commercial equipment'), 'speeds'=>self::t('عدد السرعات','Number of gears') );
	}
	public static function defaults(): array {
		$groups = array(
			'fuel'=>array('petrol'=>['بنزين','Petrol'],'diesel'=>['ديزل','Diesel'],'hev'=>['هجين','Hybrid — HEV'],'phev'=>['هجين قابل للشحن','Plug-in Hybrid — PHEV'],'mhev'=>['هجين خفيف','Mild Hybrid — MHEV'],'bev'=>['كهربائي','Electric — BEV'],'reev'=>['كهربائي بمدى ممتد','Range-Extended Electric — REEV'],'cng'=>['غاز طبيعي مضغوط','Compressed Natural Gas — CNG'],'lpg'=>['غاز بترولي مسال','Liquefied Petroleum Gas — LPG'],'bifuel'=>['بنزين وغاز','Petrol / Gas'],'fcev'=>['هيدروجين بخلايا وقود','Hydrogen Fuel Cell — FCEV'],'flex'=>['وقود مرن','Flex Fuel']),
			'transmission'=>array('manual'=>['يدوي','Manual'],'automatic'=>['أوتوماتيكي','Automatic'],'cvt'=>['أوتوماتيكي متغير باستمرار','CVT'],'ecvt'=>['ناقل هجين إلكتروني','e-CVT'],'dct'=>['أوتوماتيكي ثنائي القابض','DCT'],'amt'=>['يدوي مؤتمت','AMT'],'sequential'=>['تسلسلي','Sequential'],'electric_single'=>['كهربائي أحادي السرعة','Single-Speed Electric'],'electric_multi'=>['كهربائي متعدد السرعات','Multi-Speed Electric']),
			'body_type'=>array('sedan'=>['سيدان','Sedan'],'hatchback'=>['هاتشباك','Hatchback'],'liftback'=>['ليفت باك','Liftback'],'crossover'=>['كروس أوفر','Crossover'],'suv'=>['رياضية متعددة الاستخدامات','SUV'],'coupe'=>['كوبيه','Coupe'],'convertible'=>['مكشوفة','Convertible'],'wagon'=>['واجن','Wagon'],'mpv'=>['متعددة الأغراض','MPV'],'minivan'=>['ميني فان','Minivan'],'passenger_van'=>['فان ركاب','Passenger Van'],'cargo_van'=>['فان بضائع','Cargo Van'],'pickup'=>['بيك أب','Pickup'],'chassis_cab'=>['شاصيه بكابينة','Chassis Cab'],'truck'=>['شاحنة','Truck'],'minibus'=>['حافلة صغيرة','Minibus'],'bus'=>['حافلة','Bus']),
			'usage'=>array('passenger'=>['ركوب','Passenger'],'commercial'=>['تجاري','Commercial'],'special'=>['استخدام خاص','Special Purpose']),
			'equipment'=>array('single_cab'=>['غمارة','Single Cab'],'double_cab'=>['غمارتان','Double Cab'],'chiller'=>['صندوق تبريد','Chiller Body'],'freezer'=>['صندوق تجميد','Freezer Body'],'box'=>['صندوق مغلق','Box Body'],'flatbed'=>['سطح مسطح','Flatbed'],'tipper'=>['قلاب','Tipper'],'tanker'=>['صهريج','Tanker'],'recovery'=>['سطحة نقل سيارات','Recovery Body'],'service'=>['تجهيز خدمات','Service Body']),
		);
		$out = array();
		foreach ( $groups as $field=>$rows ) { foreach ( $rows as $key=>$pair ) { $out[$field][$key] = array('ar'=>$pair[0],'en'=>$pair[1],'active'=>true,'icon'=>self::default_icon($field,$key)); } }
		return $out;
	}
	private static function default_icon(string $field,string $key=''):string {
		if('fuel'===$field){return in_array($key,array('bev','fcev'),true)?'electric':(in_array($key,array('hev','phev','mhev','reev'),true)?'hybrid':'fuel');}
		return array('transmission'=>'gear','capacity'=>'engine','speeds'=>'gear','body_type'=>'car','usage'=>'briefcase','equipment'=>'truck')[$field]??'car';
	}
	public static function definitions(): array {
		$rows=self::defaults();$saved=get_option(self::OPTION,array());
		foreach(is_array($saved)?$saved:array()as$field=>$options){if(!isset($rows[$field])||!is_array($options)){continue;}foreach($options as$key=>$option){if(is_array($option)){$rows[$field][$key]=$option;}}}
		return $rows;
	}
	public static function stored(int $post):array { $raw=get_post_meta($post,self::META,true);return is_array($raw)?$raw:array(); }
	public static function variant_key(array $row):string { return hash('sha256',wp_json_encode(array((string)($row['model_year']??''),(string)($row['trim_name']??'')))); }
	public static function variants(int $post):array {
		if(!$post||!GroupedModelCatalog::is_mapped($post)){return array();}$view=GroupedModelCatalog::view($post);$out=array();
		foreach($view['catalog_model']['options']??array()as$row){$out[self::variant_key($row)]=array('trim'=>$row['trim_name'],'year'=>$row['model_year']);}return $out;
	}
	private static function blank():array {return array_fill_keys(self::FIELDS,'')+array('capacity_cc'=>'');}
	/** Strict unit conversion: retain exact cubic centimetres, never infer units. */
	public static function capacity_cc(string $value,string $unit) {
		$value=strtr(trim($value),array('٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','٫'=>'.'));
		if(''===$value){return '';}
		if('cc'===$unit&&preg_match('/^[0-9]{1,5}$/D',$value)){$cc=(int)$value;}
		elseif('l'===$unit&&preg_match('/^([0-9]{1,2})(?:\.([0-9]{1,3}))?$/D',$value,$parts)){$cc=(int)$parts[1]*1000+(int)str_pad($parts[2]??'',3,'0');}
		else{return false;}
		return $cc>=50&&$cc<=30000?$cc:false;
	}
	private static function invalid():\WP_Error {return new \WP_Error('adc_technical_invalid',self::t('راجع المواصفات: اختر القيم المعتمدة وحدد سعة صحيحة ووحدتها (50–30,000 سي سي)، وعدد سرعات من 1 إلى 24.','Check specifications: use approved options, a capacity with its unit (50–30,000 cc), and 1–24 gears.'));}
	private static function validate_row($input,array $previous,array $definitions) {
		if(!is_array($input)||array_diff(self::FIELDS,array_keys($input))||array_diff(array_keys($input),self::FIELDS)){return self::invalid();}$out=array();
		foreach(self::FIELDS as$field){if(!is_string($input[$field])){return self::invalid();}$value=$input[$field];
			if(isset($definitions[$field])&&''!==$value){$option=$definitions[$field][$value]??null;if(!$option|| (empty($option['active'])&&($previous[$field]??'')!==$value)){return self::invalid();}}
			$out[$field]=$value;
		}
		if(!in_array($out['capacity_unit'],array('cc','l'),true)){return self::invalid();}
		$cc=self::capacity_cc($out['capacity'],$out['capacity_unit']);if(false===$cc){return self::invalid();}
		if(''!==$out['speeds']&&(!ctype_digit($out['speeds'])||(int)$out['speeds']<1||(int)$out['speeds']>24)){return self::invalid();}
		$out['capacity_cc']=$cc;$out['capacity']=''===$cc?'':('l'===$out['capacity_unit']?rtrim(rtrim(number_format($cc/1000,3,'.',''),'0'),'.'):(string)$cc);
		return $out;
	}
	/** Absent section means unchanged, including submissions by older editors. */
	public static function validate_input(int $post,array $input) {
		if(!array_key_exists('technical_present',$input)){return null;}
		$old=self::stored($post);$definitions=self::definitions();$base=self::validate_row($input['technical_base']??null,(array)($old['base']??array()),$definitions);if(is_wp_error($base)){return $base;}
		$variants=$input['technical_variants']??array();$known=self::variants($post);
		if(!is_array($variants)||count($variants)>100||array_diff_key($variants,$known)||array_diff_key($known,$variants)){return new \WP_Error('adc_technical_stale',self::t('تغيرت فئات السيارة أو لم تصل جميع حقول المواصفات. أعد تحميل الصفحة قبل الحفظ.','Vehicle variants changed or specification fields are missing. Reload before saving.'));}
		$out=array('base'=>$base,'variants'=>(array)($old['variants']??array()));
		foreach($variants as$key=>$row){$validated=self::validate_row($row,(array)($old['variants'][$key]??array()),$definitions);if(is_wp_error($validated)){return $validated;}$out['variants'][$key]=$validated;}
		return $out;
	}
	private static function source(int $post,array $view):array {
		$vehicle=(array)($view['vehicle']??array());$out=array();
		foreach(array('fuel'=>'fuel_type','transmission'=>'transmission','body_type'=>'body_type')as$key=>$source){$raw=$vehicle[$source]??get_post_meta($post,'_car_'.$source,true);$out[$key]=is_scalar($raw)?(string)$raw:'';}
		$engine=$vehicle['engine_size']??get_post_meta($post,'_car_engine_size',true);$out['engine_raw']=is_scalar($engine)?(string)$engine:'';
		return $out;
	}
	private static function normalize(string $text):string {return mb_strtolower(trim(strtr($text,array('أ'=>'ا','إ'=>'ا','آ'=>'ا','ى'=>'ي'))));}
	public static function resolved(int $post,array $view=array(),?array $variant=null):array {
		$saved=self::stored($post);$row=(array)($saved['base']??array());
		if($variant){foreach((array)($saved['variants'][self::variant_key($variant)]??array())as$key=>$value){if(''!==$value&&'capacity_unit'!==$key){$row[$key]=$value;}}}
		$definitions=self::definitions();$defaults=self::defaults();$source=self::source($post,$view);$out=array();
		foreach($definitions as$field=>$options){$value=$row[$field]??'';$raw=$source[$field]??'';$option=$options[$value]??null;
			if(!$option&&''!==$raw){foreach($options as$key=>$candidate){$original=$defaults[$field][$key]??array('ar'=>'','en'=>'');if(in_array(self::normalize($raw),array(self::normalize($key),self::normalize($candidate['ar']),self::normalize($candidate['en']),self::normalize($original['ar']),self::normalize($original['en'])),true)){$option=$candidate;$value=$key;break;}}}
			$label=$option?('en'===Localization::language()?$option['en']:$option['ar']):$raw;
			// Historical broad fuel keys are display labels, not a claim of a specific HEV/BEV technology.
			if ( ! $option && 'fuel' === $field ) {
				$legacy = array( 'gasoline'=>array( 'بنزين', 'Gasoline' ), 'hybrid'=>array( 'هجين', 'Hybrid' ), 'electric'=>array( 'كهربائي', 'Electric' ) );
				if ( isset( $legacy[$raw] ) ) { $label = $legacy[$raw]['en' === Localization::language() ? 1 : 0]; }
			}
			$out[$field]=array('value'=>$value,'label'=>$label,'icon'=>$option['icon']??self::default_icon($field));
		}
		$cc=$row['capacity_cc']??'';
		if(''===$cc&&preg_match('/^\s*([0-9]+(?:\.[0-9]{1,3})?)\s*(cc|سي\s*سي|سم³|l|litres?|liters?|لتر)\s*$/iu',$source['engine_raw']??'',$match)){$parsed=self::capacity_cc($match[1],preg_match('/^(cc|سي\s*سي|سم³)$/iu',$match[2])?'cc':'l');if(false!==$parsed){$cc=$parsed;}}
		$fuel=$out['fuel']['value'];if(in_array($fuel,array('bev','fcev'),true)){$cc='';}
		$out['capacity']=array('value'=>$cc,'label'=>''===$cc?'':rtrim(rtrim(number_format((int)$cc/1000,3,'.',''),'0'),'.').' '.self::t('لتر','L').' · '.number_format_i18n((int)$cc).' '.self::t('سي سي','cc'),'icon'=>'engine');
		$speeds=$row['speeds']??'';if(''!==$speeds&&''!==$out['transmission']['label']){$out['transmission']['label'].=' · '.sprintf(self::t('%s سرعات','%s gears'),number_format_i18n((int)$speeds));}
		return $out;
	}
	/** Trusted SVG paths only; no editable SVG markup. */
	public static function icon(string $name):string {
		$paths=array('fuel'=>'<path d="M4 21V5a2 2 0 0 1 2-2h5a2 2 0 0 1 2 2v16M3 21h11M4 10h9M13 8h2l3 3v7a2 2 0 0 0 4 0V9l-4-5M19 5v4h3"/>','electric'=>'<path d="m13 2-9 12h7l-1 8 10-12h-7l1-8Z"/>','hybrid'=>'<path d="M4 20V6h9v14M4 10h9M3 20h11m5-15-4 7h4l-2 7 6-9h-4l2-5"/>','gear'=>'<path d="M6 3v18M18 3v9H6m6-9v18M3 3h6m6 0h6M3 21h6m0 0h6"/>','engine'=>'<path d="M5 8h4l2-3h5v3h3l3 4v6h-4l-2 2H7l-2-3H2v-7h3V8ZM9 2h7M12 2v3M2 9v9"/>','car'=>'<path d="m3 12 3-7h12l3 7v8h-3v-3H6v3H3v-8Zm0 0h18M6 14h2m8 0h2"/>','briefcase'=>'<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V3h8v4M3 12a22 22 0 0 0 18 0M12 11v4"/>','truck'=>'<path d="M2 5h13v13H2V5Zm13 5h4l3 4v4h-7M2 18h2m5 0h7"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>');
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name]??$paths['car']).'</svg>';
	}
	public static function public_summary(int $post,array $view):void {
		$variant=isset($view['catalog_model'])?VehicleColors::default_option($post,$view):null;$resolved=self::resolved($post,$view,$variant);
		$visible=array_filter(array_intersect_key($resolved,array_flip(array('fuel','transmission','capacity','body_type'))),static fn($item)=>''!==$item['label']);
		echo '<dl class="adc-technical-summary" data-technical-summary'.(!$visible?' hidden':'').'>';
		foreach(array('fuel','transmission','capacity','body_type')as$field){$item=$resolved[$field];echo '<div data-technical-field="'.esc_attr($field).'"'.(''===$item['label']?' hidden':'').'><dt><span data-technical-icon>'.self::icon($item['icon']).'</span>'.esc_html(self::labels()[$field]).'</dt><dd data-technical-value dir="auto">'.esc_html($item['label']).'</dd></div>';}
		echo '</dl>';
	}
	public static function payload(int $post,array $variant):array {
		$items=self::resolved($post,array(),$variant);foreach($items as&$item){$item['svg']=self::icon($item['icon']);}unset($item);return $items;
	}
	public static function public_rows(int $post,array $view):void {
		$variant=isset($view['catalog_model'])?VehicleColors::default_option($post,$view):null;$resolved=self::resolved($post,$view,$variant);
		foreach(array('fuel','transmission','capacity','body_type','usage','equipment')as$field){$item=$resolved[$field];echo '<div data-technical-row="'.esc_attr($field).'"'.(''===$item['label']?' hidden':'').'><dt>'.esc_html(self::labels()[$field]).'</dt><dd data-technical-detail="'.esc_attr($field).'" dir="auto">'.esc_html($item['label']).'</dd></div>';}
	}
	public static function enrich(array $view,int $post):array {
		$variant=isset($view['catalog_model'])?VehicleColors::default_option($post,$view):null;$resolved=self::resolved($post,$view,$variant);
		foreach(array('fuel','transmission','body_type','capacity','usage','equipment')as$field){$view[$field]=$resolved[$field]['label'];}return $view;
	}
	public static function boot():void {
		add_action('admin_menu',static function(){add_submenu_page('adc-area-inventory',self::t('خيارات مواصفات السيارات','Vehicle specification options'),self::t('خيارات المواصفات','Specification options'),'manage_options','adc-vehicle-specifications',array(self::class,'admin'),111);},111);
		add_action('admin_post_adc_save_technical_options',array(self::class,'save_options'));
		add_action('admin_enqueue_scripts',static function(){if(in_array($_GET['page']??'',array('adc-car-publishing','adc-vehicle-specifications'),true)){self::assets(true);}});
		add_action('wp_enqueue_scripts',static function(){if(is_singular('car')){self::assets(false);}});
	}
	private static function assets(bool $admin):void {
		wp_enqueue_style('adc-technical-details',plugins_url('assets/css/vehicle-technical-details.css',ADC_FILE),array(),filemtime(ADC_PATH.'assets/css/vehicle-technical-details.css'));
		wp_enqueue_script('adc-technical-details',plugins_url('assets/js/vehicle-technical-details.js',ADC_FILE),array(),filemtime(ADC_PATH.'assets/js/vehicle-technical-details.js'),true);
	}
	private static function editor_row(string $prefix,array $saved,string $empty):void {
		$values=array_replace(self::blank(),$saved);$definitions=self::definitions();echo '<div class="adc-technical-fields">';
		foreach(self::labels()as$field=>$label){echo '<label class="adc-field"><span class="adc-technical-label">'.self::icon(self::default_icon($field)).esc_html($label).'</span>';
			if(isset($definitions[$field])){echo '<select name="'.esc_attr($prefix.'['.$field.']').'"><option value="">'.esc_html($empty).'</option>';foreach($definitions[$field]as$key=>$option){if(empty($option['active'])&&($values[$field]??'')!==$key){continue;}$text='en'===Localization::language()?$option['en']:$option['ar'];echo '<option value="'.esc_attr($key).'" '.selected($values[$field],$key,false).'>'.esc_html($text.(empty($option['active'])?self::t(' — معطل',' — disabled'):'')).'</option>';}echo '</select>';}
			elseif('capacity'===$field){echo '<span class="adc-capacity-input"><input type="number" inputmode="decimal" min="0.05" max="30000" step="0.001" name="'.esc_attr($prefix.'[capacity]').'" value="'.esc_attr($values['capacity']).'" placeholder="'.esc_attr($empty).'"><select name="'.esc_attr($prefix.'[capacity_unit]').'" aria-label="'.esc_attr(self::t('وحدة سعة المحرك','Engine capacity unit')).'"><option value="cc" '.selected($values['capacity_unit']?:'cc','cc',false).'>'.esc_html(self::t('سي سي','cc')).'</option><option value="l" '.selected($values['capacity_unit'],'l',false).'>'.esc_html(self::t('لتر','L')).'</option></select></span>';}
			else{echo '<input type="number" min="1" max="24" step="1" name="'.esc_attr($prefix.'[speeds]').'" value="'.esc_attr($values['speeds']).'" placeholder="'.esc_attr($empty).'">';}echo '</label>';
		}echo '</div>';
	}
	public static function editor(int $post):void {
		$saved=self::stored($post);echo '<section class="adc-panel adc-technical-editor"><h2>'.esc_html(self::t('المواصفات الأساسية','Technical specifications')).'</h2><p class="description">'.esc_html(self::t('اترك الحقل فارغًا لاستخدام بيانات المصدر إن توفرت. القيم هنا مخصصة للموقع ولا تغير أونكس أو رسوم اللوحات. لا تُستنتج المواصفات من اسم السيارة.','Leave a field empty to use source data when available. Website overrides do not change Onyx or plate fees. Specifications are never inferred from the vehicle name.')).'</p><input type="hidden" name="technical_present" value="1">';
		self::editor_row('technical_base',(array)($saved['base']??array()),self::t('غير محدد — استخدام المصدر','Not specified — use source'));
		$source=self::source($post,array('vehicle'=>PublicCatalog::vehicle_for_post($post)));$legacy_engine=$source['engine_raw'];unset($source['engine_raw']);$source=array_filter($source,static fn($value)=>''!==$value);
		if($source||$legacy_engine){echo '<details class="adc-technical-variant"><summary>'.esc_html(self::t('بيانات المصدر للمراجعة','Source data for review')).'</summary><ul>';foreach($source as$field=>$value){echo '<li>'.esc_html(self::labels()[$field].': '.$value).'</li>';}if(is_scalar($legacy_engine)&&''!==(string)$legacy_engine){echo '<li>'.esc_html(self::t('سعة المحرك القديمة (حدد الوحدة قبل اعتمادها): ','Historical engine size (confirm its unit first): ').$legacy_engine).'</li>';}echo '</ul></details>';}
		$variants=self::variants($post);foreach($variants as$key=>$variant){echo '<details class="adc-technical-variant"><summary>'.esc_html(sprintf(self::t('تخصيص الفئة: %1$s · %2$s','Trim overrides: %1$s · %2$s'),$variant['trim'],$variant['year'])).'</summary><p class="description">'.esc_html(self::t('الحقول الفارغة ترث المواصفات الأساسية أعلاه. التخصيص يطبق على جميع ألوان هذه الفئة والموديل.','Empty fields inherit the base specifications above. Overrides apply to all colors of this trim and model year.')).'</p>';self::editor_row('technical_variants['.$key.']',(array)($saved['variants'][$key]??array()),self::t('استخدام المواصفات الأساسية','Use base specifications'));echo '</details>';}
		if(current_user_can('manage_options')){echo '<p><a href="'.esc_url(admin_url('admin.php?page=adc-vehicle-specifications')).'">'.esc_html(self::t('إدارة الخيارات والترجمات والأيقونات','Manage options, translations and icons')).'</a></p>';}echo '</section>';
	}
	public static function options_revision():string {return hash('sha256',maybe_serialize(get_option(self::OPTION,array())));}
	public static function validate_options($input,$new) {
		if(!is_array($input)||!is_array($new)){return self::invalid();}$existing=self::definitions();$out=$existing;$icons=array('fuel','electric','hybrid','gear','engine','car','briefcase','truck');
		if(array_diff_key($existing,$input)||array_diff_key($input,$existing)){return self::invalid();}
		foreach($existing as$field=>$options){if(!isset($input[$field])||!is_array($input[$field])||array_diff_key($options,$input[$field])||array_diff_key($input[$field],$options)){return self::invalid();}
			foreach($options as$key=>$old){$row=$input[$field][$key];if(!is_array($row)){return self::invalid();}foreach(array('ar','en','icon','active')as$part){if(!isset($row[$part])||!is_string($row[$part])){return self::invalid();}}
				if(!in_array($row['active'],array('0','1'),true)||!in_array($row['icon'],$icons,true)){return self::invalid();}
				foreach(array('ar','en')as$lang){$row[$lang]=sanitize_text_field($row[$lang]);if(''===$row[$lang]||mb_strlen($row[$lang])>100){return self::invalid();}}
				$out[$field][$key]=array('ar'=>$row['ar'],'en'=>$row['en'],'icon'=>$row['icon'],'active'=>'1'===$row['active']);
			}
		}
		foreach($out as$options){$seen_ar=array();$seen_en=array();foreach($options as$row){$ar_key=self::normalize($row['ar']);$en_key=self::normalize($row['en']);if(isset($seen_ar[$ar_key])||isset($seen_en[$en_key])){return new \WP_Error('adc_technical_duplicate',self::t('توجد أسماء خيارات مكررة داخل المجموعة.','Duplicate option names exist in a group.'));}$seen_ar[$ar_key]=true;$seen_en[$en_key]=true;}}
		foreach(array('field','ar','en','icon')as$part){if(!isset($new[$part])||!is_string($new[$part])){return self::invalid();}}
		if(''!==trim($new['ar'])||''!==trim($new['en'])){if(!isset($out[$new['field']])||!in_array($new['icon'],$icons,true)){return self::invalid();}$ar=sanitize_text_field($new['ar']);$en=sanitize_text_field($new['en']);if(''===$ar||''===$en||mb_strlen($ar)>100||mb_strlen($en)>100||count($out[$new['field']])>=60){return self::invalid();}
			if(array_sum(array_map('count',$out))>=180){return self::invalid();}
			foreach($out[$new['field']]as$row){if(self::normalize($ar)===self::normalize($row['ar'])||self::normalize($en)===self::normalize($row['en'])){return new \WP_Error('adc_technical_duplicate',self::t('هذا الخيار موجود بالفعل في المجموعة.','This option already exists in the group.'));}}
			$key='custom_'.substr(hash('sha256',$ar.'|'.$en),0,16);$out[$new['field']][$key]=array('ar'=>$ar,'en'=>$en,'icon'=>$new['icon'],'active'=>true);
		}return $out;
	}
	public static function save_options():void {
		if(!current_user_can('manage_options')){wp_die(esc_html(self::t('لا تملك صلاحية إدارة الخيارات.','You cannot manage specification options.')),403);}check_admin_referer('adc_save_technical_options');$input=wp_unslash($_POST);$revision=$input['revision']??'';$result=self::validate_options($input['technical_options']??null,$input['new_option']??null);
		if(!is_string($revision)||!hash_equals(self::options_revision(),$revision)){$result=new \WP_Error('adc_technical_stale',self::t('تغيرت الخيارات؛ أعد تحميل الصفحة.','Options changed; reload this page.'));}
		if(!is_wp_error($result)){
			global $wpdb;$before=get_option(self::OPTION,null);
			if(null===$before){$ok=add_option(self::OPTION,$result,'',false);}elseif(maybe_serialize($before)===maybe_serialize($result)){$ok=true;}else{$ok=1===$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s",maybe_serialize($result),self::OPTION,maybe_serialize($before)));wp_cache_delete(self::OPTION,'options');wp_cache_delete('alloptions','options');}
			if(!$ok){$result=new \WP_Error('adc_technical_stale',self::t('تعذر الحفظ؛ أعد تحميل الصفحة وحاول مجددًا.','Unable to save; reload and try again.'));}
		}
		if(is_wp_error($result)){set_transient('adc_technical_options_error_'.get_current_user_id(),$result->get_error_message(),120);}elseif(function_exists('car_dealer_clear_public_page_cache')){\car_dealer_clear_public_page_cache();}
		wp_safe_redirect(add_query_arg(array('page'=>'adc-vehicle-specifications','saved'=>is_wp_error($result)?'0':'1'),admin_url('admin.php')));exit;
	}
	public static function admin():void {
		if(!current_user_can('manage_options')){wp_die(esc_html(self::t('غير مسموح.','Access denied.')),403);}$labels=self::labels();$icons=array('fuel'=>self::t('وقود','Fuel'),'electric'=>self::t('كهرباء','Electric'),'hybrid'=>self::t('هجين','Hybrid'),'gear'=>self::t('ناقل الحركة','Transmission'),'engine'=>self::t('محرك','Engine'),'car'=>self::t('سيارة','Car'),'briefcase'=>self::t('استخدام','Use'),'truck'=>self::t('شاحنة','Truck'));
		echo '<div class="wrap adc-technical-admin" dir="'.('en'===Localization::language()?'ltr':'rtl').'"><h1>'.esc_html(self::t('خيارات مواصفات السيارات','Vehicle specification options')).'</h1><p>'.esc_html(self::t('إدارة موحدة للخيارات باللغتين. التعطيل يمنع الاختيار الجديد ويحافظ على مواصفات السيارات المحفوظة.','Manage bilingual options centrally. Disabling prevents new selections and preserves existing vehicle specifications.')).'</p>';
		$error=get_transient('adc_technical_options_error_'.get_current_user_id());if($error){delete_transient('adc_technical_options_error_'.get_current_user_id());echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';}elseif('1'===($_GET['saved']??'')){echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(self::t('تم حفظ الخيارات.','Options saved.')).'</p></div>';}
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="adc_save_technical_options"><input type="hidden" name="revision" value="'.esc_attr(self::options_revision()).'">';wp_nonce_field('adc_save_technical_options');
		foreach(self::definitions()as$field=>$options){echo '<details class="adc-panel adc-technical-options"><summary>'.esc_html($labels[$field]).' <span>('.count($options).')</span></summary><div class="adc-technical-option-list">';foreach($options as$key=>$option){$prefix='technical_options['.$field.']['.$key.']';echo '<div class="adc-technical-option">'.self::icon($option['icon']).'<label>'.esc_html(self::t('العربية','Arabic')).'<input lang="ar" dir="rtl" name="'.esc_attr($prefix.'[ar]').'" value="'.esc_attr($option['ar']).'" maxlength="100" required></label><label>'.esc_html(self::t('الإنجليزية','English')).'<input lang="en" dir="ltr" name="'.esc_attr($prefix.'[en]').'" value="'.esc_attr($option['en']).'" maxlength="100" required></label><label>'.esc_html(self::t('الأيقونة','Icon')).'<select name="'.esc_attr($prefix.'[icon]').'">';foreach($icons as$icon=>$label){echo '<option value="'.$icon.'" '.selected($option['icon'],$icon,false).'>'.esc_html($label).'</option>';}echo '</select></label><label>'.esc_html(self::t('الحالة','Status')).'<select name="'.esc_attr($prefix.'[active]').'"><option value="1" '.selected(!empty($option['active']),true,false).'>'.esc_html(self::t('نشط','Active')).'</option><option value="0" '.selected(empty($option['active']),true,false).'>'.esc_html(self::t('معطل','Disabled')).'</option></select></label></div>';}echo '</div></details>';}
		echo '<section class="adc-panel"><h2>'.esc_html(self::t('إضافة خيار مخصص','Add a custom option')).'</h2><div class="adc-technical-fields"><label>'.esc_html(self::t('المجموعة','Group')).'<select name="new_option[field]">';foreach(self::definitions()as$field=>$rows){echo '<option value="'.$field.'">'.esc_html($labels[$field]).'</option>';}echo '</select></label><label>'.esc_html(self::t('الاسم بالعربية','Arabic name')).'<input name="new_option[ar]" maxlength="100" dir="rtl"></label><label>'.esc_html(self::t('الاسم بالإنجليزية','English name')).'<input name="new_option[en]" maxlength="100" dir="ltr"></label><label>'.esc_html(self::t('الأيقونة','Icon')).'<select name="new_option[icon]">';foreach($icons as$key=>$label){echo '<option value="'.$key.'">'.esc_html($label).'</option>';}echo '</select></label></div></section>';
		submit_button(self::t('حفظ الخيارات','Save options'));echo '</form></div>';
	}
}
