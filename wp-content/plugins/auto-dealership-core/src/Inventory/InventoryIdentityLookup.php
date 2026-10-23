<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;
defined('ABSPATH')||exit;

final class InventoryIdentityLookup {
	public static function boot():void{add_action('wp_ajax_adc_lookup_vin',array(self::class,'ajax'));add_action('admin_enqueue_scripts',array(self::class,'enqueue'));}
	private static function source_matches(string $identifier,bool $include_branch=false):array{
		global $wpdb;$identifier=strtoupper(trim($identifier));if(!preg_match('/\A[A-Z0-9]{4,32}\z/',$identifier)){return array();}
		$stock=Schema::table('onyx_stock');$vehicles=Schema::table('vehicles');$join=$include_branch?' LEFT JOIN '.$vehicles.' v ON v.id=s.vehicle_id':'';$where='(s.source_identifier=%s OR RIGHT(%s,CHAR_LENGTH(s.source_identifier))=s.source_identifier)';$args=array($identifier,$identifier);
		if($include_branch&&!current_user_can('manage_options')){
			list($scope,$scope_args)=BranchScope::predicate('v.branch_id');$names=array();
			foreach(InventoryWorkspace::warehouse_branches()as$name=>$branch){if(BranchScope::allows((int)$branch)){$names[]=(string)$name;}}
			$accessible='(s.vehicle_id IS NOT NULL AND '.$scope.')';
			if($names){$accessible.=' OR (s.vehicle_id IS NULL AND s.warehouse_name IN ('.implode(',',array_fill(0,count($names),'%s')).'))';}
			$where.=' AND ('.$accessible.')';$args=array_merge($args,$scope_args,$names);
		}
		return $wpdb->get_results($wpdb->prepare('SELECT s.* FROM '.$stock.' s'.$join.' WHERE '.$where.' ORDER BY LENGTH(s.source_identifier) DESC LIMIT 25',$args),ARRAY_A)?:array();
	}
	private static function longest_sources(array $rows):array{
		if($rows){$longest=strlen($rows[0]['source_identifier']);$rows=array_values(array_filter($rows,static fn($row)=>strlen($row['source_identifier'])===$longest));}return $rows;
	}
	public static function sources(string $identifier):array{
		return self::longest_sources(self::source_matches($identifier));
	}
	public static function lookup(string $identifier){
		global $wpdb;if(!current_user_can('adc_manage_inventory')){return new \WP_Error('adc_forbidden','Forbidden',array('status'=>403));}$identifier=strtoupper(trim($identifier));if(!preg_match('/\A[A-Z0-9]{4,32}\z/',$identifier)){return new \WP_Error('adc_invalid_identifier','Invalid identifier',array('status'=>400));}
		$vehicles=$wpdb->get_results($wpdb->prepare('SELECT id,vin,stock_number,branch_id,status FROM '.Schema::table('vehicles').' WHERE vin=%s OR vin LIKE %s ORDER BY id LIMIT 25',$identifier,'%'.$wpdb->esc_like($identifier)),ARRAY_A)?:array();
		$vehicles=array_values(array_filter($vehicles,static fn($vehicle)=>BranchScope::allows((int)$vehicle['branch_id'])));
		$sources=self::source_matches($identifier,true);
		$sources=self::longest_sources($sources);
		$result=array('exists'=>(bool)($vehicles||$sources),'operational_exists'=>(bool)$vehicles,'ambiguous'=>count($vehicles)>1||count($sources)>1,'vehicle'=>null,'source'=>null);
		if(count($vehicles)===1){$result['vehicle']=array_intersect_key($vehicles[0],array_flip(array('id','stock_number','status')));}
		if(count($sources)===1){$result['source']=array_intersect_key($sources[0],array_flip(array('id','source_identifier','vehicle_id','brand','model','trim_name','model_year','listed_price','exterior_color','interior_color','present')));}
		if($wpdb->last_error){return new \WP_Error('adc_lookup_failed','Lookup unavailable',array('status'=>500));}return $result;
	}
	public static function ajax():void{check_ajax_referer('adc_lookup_vin','nonce');$result=self::lookup(sanitize_text_field(wp_unslash($_POST['identifier']??'')));if(is_wp_error($result)){wp_send_json_error(array('code'=>$result->get_error_code()),(int)($result->get_error_data()['status']??400));}wp_send_json_success($result);}
	public static function enqueue():void{
		if('adc-inventory'!==($_GET['page']??'')||!current_user_can('adc_manage_inventory')){return;}wp_enqueue_script('adc-vin-lookup',plugin_dir_url(ADC_FILE).'assets/js/vin-lookup.js',array(),ADC_VERSION,true);
		$ar='en'!==\AutoDealership\Core\Localization::language();wp_localize_script('adc-vin-lookup','adcVinLookup',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('adc_lookup_vin'),'checking'=>$ar?'جارٍ البحث عن رقم الهيكل…':'Checking chassis identifier…','exists'=>$ar?'السيارة موجودة بالفعل. افتح سجلها بدل إنشاء سيارة أخرى.':'This vehicle already exists. Open its record instead of creating another.','source'=>$ar?'السيارة موجودة في مخزون أونكس. أكمل رقم الهيكل الكامل واربط السجل الموجود.':'This vehicle exists in Onyx stock. Complete its full VIN and link the existing stock record.','ambiguous'=>$ar?'هناك أكثر من مطابقة. استخدم الرقم الكامل أو المعرّف المختصر المميز بالحرف.':'More than one match. Use the full VIN or the letter-qualified Onyx identifier.','new'=>$ar?'لم توجد سيارة بهذا المعرّف.':'No vehicle found for this identifier.','failed'=>$ar?'تعذر التحقق. أعد المحاولة؛ سيتحقق النظام عند الحفظ أيضًا.':'Lookup failed. Retry; the server also checks when saving.','missing'=>$ar?'هذا السجل غير موجود في آخر لقطة مخزون كاملة.':'This record is absent from the latest complete stock snapshot.'));
	}
}
