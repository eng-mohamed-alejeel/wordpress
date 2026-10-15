<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
use AutoDealership\Pricing\Money;
defined( 'ABSPATH' ) || exit;

/** Onyx identifiers remain strings and never stand in for the legally verified full VIN. */
final class OnyxImportService {
	private const HEADERS=array('الهيكل'=>'source_identifier','الماركة'=>'brand','الطراز'=>'model','الفئة'=>'trim_name','اللون'=>'exterior_color','الموديل'=>'model_year','سعر البيع'=>'listed_price','المورد'=>'supplier_name','المخزن'=>'warehouse_name','ملاحظات'=>'notes','اللون الداخلي'=>'interior_color','عمر المخزون'=>'stock_age');
	public static function model_key(string $brand,string $model,?string $trim=null,?int $year=null):string { if(null!==$trim){return hash('sha256',self::model_key($brand,$model).'|'.wp_json_encode(array($trim,$year)));} return hash('sha256',mb_strtolower(preg_replace('/\s+/u',' ',trim($brand))).'|'.mb_strtolower(preg_replace('/\s+/u',' ',trim($model)))); }
	public static function parse(string $path):array {
		$sheet=OnyxWorkbook::read($path);$columns=array();$data=array();$errors=array();$header=0;
		foreach($sheet as $line=>$cells){
			if (!$header) {
				foreach ($cells as $column => $text) {
					if (is_string($text) && isset(self::HEADERS[$text])) { $columns[$column] = self::HEADERS[$text]; }
				}
				if (!array_diff(array_values(self::HEADERS), $columns)) {
					if (count($columns) !== count(self::HEADERS)) { throw new \RuntimeException('Duplicate Onyx column headings.'); }
					$header = $line;
				} else { $columns = array(); }
				continue;
			}
			$row=array();foreach($columns as $column=>$field){$row[$field]=(string)($cells[$column]??'');}
			if(!array_filter($row,static fn($value)=>''!==$value)){continue;}
			$id=strtoupper(trim($row['source_identifier']));$row['source_identifier']=$id;$row['line']=$line;
			$invalid=array();if(!preg_match('/\A[A-Z0-9]{4,32}\z/',$id)){$invalid[]='identifier';}if(isset($data[$id])){$invalid[]='duplicate_identifier';}
			foreach(array('brand'=>100,'model'=>120,'trim_name'=>120,'exterior_color'=>80,'interior_color'=>80,'supplier_name'=>190,'warehouse_name'=>190,'notes'=>4000)as$field=>$limit){$row[$field]=sanitize_textarea_field($row[$field]??'');if(mb_strlen($row[$field])>$limit){$invalid[]=$field;}}
			if(!$row['brand']||!$row['model']){$invalid[]='brand_model';}
			$year=filter_var($row['model_year'],FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1900,'max_range'=>(int)gmdate('Y')+2)));if(false===$year){$invalid[]='year';}$row['model_year']=$year;
			$price=Money::from_sar($row['listed_price']);if(null===$price){$invalid[]='price';}$row['listed_price']=$price;
			$age=$row['stock_age']??'';$row['stock_age']=''===$age?null:filter_var($age,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>0,'max_range'=>100000)));if(false===$row['stock_age']){$invalid[]='stock_age';}
			if(array_intersect($cells['_formulas']??array(),array_keys($columns))){$invalid[]='formula';}
			if($invalid){$errors[]=array('line'=>$line,'identifier'=>$id,'fields'=>$invalid);}else{$data[$id]=$row;}
		}
		if(!$header){throw new \RuntimeException('Required Onyx column headings were not found.');}
		return array('rows'=>array_values($data),'errors'=>$errors,'header_line'=>$header);
	}
	public static function preview(string $path,string $name,string $mode='snapshot'){
		global $wpdb;if(!current_user_can('manage_options')){return self::error('forbidden',403);}if(!in_array($mode,array('partial','snapshot'),true)){return self::error('mode',400);}$mode='snapshot';
		try{$parsed=self::parse($path);}catch(\Throwable $error){return new \WP_Error('adc_onyx_file',$error->getMessage(),array('status'=>400));}
		$summary=array('new'=>0,'updated'=>0,'unchanged'=>0,'missing'=>0,'errors'=>$parsed['errors'],'rows'=>count($parsed['rows']));$ids=array();
		foreach($parsed['rows']as$row){$ids[]=$row['source_identifier'];$existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('onyx_stock').' WHERE source_identifier=%s',$row['source_identifier']),ARRAY_A);if(!$existing){++$summary['new'];continue;}$changed=!$existing['present'];foreach($row as$key=>$value){if('line'!==$key&&(string)$value!==(string)($existing[$key]??'')){$changed=true;}}++$summary[$changed?'updated':'unchanged'];}
		if('snapshot'===$mode&&$ids){$summary['missing']=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.Schema::table('onyx_stock').' WHERE present=1 AND source_identifier NOT IN ('.implode(',',array_fill(0,count($ids),'%s')).')',$ids));}
		if($wpdb->last_error){return self::error('database',500);}
		$entry=array('request_key'=>wp_generate_uuid4(),'file_hash'=>hash_file('sha256',$path),'file_name'=>mb_substr(sanitize_file_name($name),0,190),'import_mode'=>$mode,'baseline_revision'=>(int)get_option('adc_onyx_revision',0),'status'=>'preview','rows_json'=>wp_json_encode($parsed['rows'],JSON_UNESCAPED_UNICODE),'summary_json'=>wp_json_encode($summary,JSON_UNESCAPED_UNICODE),'actor_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql',true));
		if(!Transaction::begin()){return self::error('database',500);}if(1!==$wpdb->insert(Schema::table('onyx_imports'),$entry)){return self::rollback('database',500);}$id=(int)$wpdb->insert_id;
		if(!Transaction::commit(static fn()=>AuditLog::record('inventory.onyx_previewed','onyx_import',$id,'Onyx workbook reviewed',null,array('file_hash'=>$entry['file_hash'],'mode'=>$mode,'rows'=>$summary['rows'],'errors'=>count($summary['errors']))))){return self::error('database',500);}
		return array('id'=>$id,'summary'=>$summary);
	}
	/** A successful upload is applied immediately as the complete source inventory. */
	public static function upload(string $path,string $name){
		$preview=self::preview($path,$name,'snapshot');if(is_wp_error($preview)){return $preview;}if(!empty($preview['summary']['errors'])){return array('id'=>$preview['id'],'invalid'=>true);}
		return self::apply($preview['id']);
	}
	public static function get(int $id){global $wpdb;if(!current_user_can('manage_options')){return self::error('forbidden',403);}return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('onyx_imports').' WHERE id=%d',$id),ARRAY_A)?:self::error('missing',404);}
	public static function link(int $source_id,string $vin,int $expected_vehicle_id=0){
		global $wpdb;if(!current_user_can('manage_options')||!current_user_can('adc_manage_inventory')){return self::error('forbidden',403);}$vin=strtoupper(trim($vin));if(!preg_match('/\A[A-HJ-NPR-Z0-9]{17}\z/',$vin)){return self::error('vin',400);}
		if(!Transaction::begin()){return self::error('database',500);}$source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('onyx_stock').' WHERE id=%d FOR UPDATE',$source_id),ARRAY_A);$matches=InventoryIdentityLookup::sources($vin);$vehicle=$wpdb->get_row($wpdb->prepare('SELECT id,vin,branch_id,model_year FROM '.Schema::table('vehicles').' WHERE vin=%s FOR UPDATE',$vin),ARRAY_A);
		if(!$source||!$source['present']||count($matches)!==1||(int)$matches[0]['id']!==$source_id||!$vehicle||($expected_vehicle_id&&(int)$vehicle['id']!==$expected_vehicle_id)||!\AutoDealership\Security\BranchScope::allows((int)$vehicle['branch_id'])||(int)$source['model_year']!==(int)$vehicle['model_year']){return self::rollback('link_mismatch',409);}
		if($source['vehicle_id']){$wpdb->query('ROLLBACK');return (int)$source['vehicle_id']===(int)$vehicle['id']?array('id'=>$source_id,'vehicle_id'=>(int)$vehicle['id'],'replayed'=>true):self::error('link_conflict',409);}
		if(1!==$wpdb->query($wpdb->prepare('UPDATE '.Schema::table('onyx_stock').' SET vehicle_id=%d WHERE id=%d AND vehicle_id IS NULL',$vehicle['id'],$source_id))||!Transaction::commit(static fn()=>AuditLog::record('inventory.onyx_linked','onyx_stock',$source_id,'Verified full VIN linked to Onyx stock',null,array('vehicle_id'=>(int)$vehicle['id'])))){return self::rollback('link_conflict',409);}return array('id'=>$source_id,'vehicle_id'=>(int)$vehicle['id'],'replayed'=>false);
	}
	/** Backfill existing inventory without reapplying a historical workbook. */
	public static function complete_model_drafts(){
		global $wpdb;if(!current_user_can('manage_options')){return self::error('forbidden',403);}if(!OnyxModelDrafts::ensure_transactional_tables()){return self::error('transactional_tables',500);}
		add_option('adc_onyx_revision',0,'',false);if(!Transaction::begin()){return self::error('database',500);}
		$revision=(int)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'adc_onyx_revision'));$touched=array();
		if(!OnyxCatalogConfigurations::split($touched)){return self::rollback('configuration_split',500,$touched);}$drafts=OnyxModelDrafts::create($touched);if(is_wp_error($drafts)){$wpdb->query('ROLLBACK');OnyxModelDrafts::clear_caches($touched);return $drafts;}
		$title_touched=array();$titles_synced=OnyxCatalogNames::sync($title_touched);$touched=array_merge($touched,$title_touched);if(!$titles_synced){return self::rollback('database',500,$touched);}
		if(!$drafts['linked']&&!OnyxCatalogConfigurations::changed()&&!$touched){$wpdb->query('ROLLBACK');return $drafts;}
		if(false===$wpdb->update($wpdb->options,array('option_value'=>(string)($revision+1)),array('option_name'=>'adc_onyx_revision'))||!Transaction::commit(static fn()=>AuditLog::record('inventory.model_drafts_completed','configuration',0,'Independent Onyx configuration drafts reconciled',null,$drafts))){return self::rollback('database',500,$touched);}
		wp_cache_delete('adc_onyx_revision','options');return $drafts;
	}
	public static function apply(int $id){
		global $wpdb;if(!current_user_can('manage_options')){return self::error('forbidden',403);}if(!OnyxModelDrafts::ensure_transactional_tables()){return self::error('transactional_tables',500);}add_option('adc_onyx_revision',0,'',false);if(!Transaction::begin()){return self::error('database',500);}
		$revision=(int)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",'adc_onyx_revision'));
		$table=Schema::table('onyx_imports');$batch=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE",$id),ARRAY_A);
		if(!$batch||(int)$batch['actor_user_id']!==get_current_user_id()){return self::rollback('missing',404);}if('applied'===$batch['status']){$wpdb->query('ROLLBACK');return array('id'=>$id,'replayed'=>true);}
		$summary=json_decode($batch['summary_json'],true);$rows=json_decode($batch['rows_json'],true);if('preview'!==$batch['status']||$revision!==(int)$batch['baseline_revision']||$batch['import_mode']!=='snapshot'||!is_array($rows)||!is_array($summary)||!empty($summary['errors'])){return self::rollback('preview_stale_or_invalid',409);}
		$now=current_time('mysql',true);$stock=Schema::table('onyx_stock');$models=Schema::table('catalog_models');
		$suppliers=array();$supplier_new=0;$touched=array();if(!OnyxCatalogConfigurations::split($touched)){return self::rollback('configuration_split',500,$touched);}
		foreach($rows as$row){
			$supplier_key=\AutoDealership\Purchasing\SupplierNameDirectory::normalized($row['supplier_name']);
			if(!array_key_exists($supplier_key,$suppliers)){$supplier=\AutoDealership\Purchasing\SupplierNameDirectory::import($row['supplier_name']);if(is_wp_error($supplier)){$wpdb->query('ROLLBACK');OnyxModelDrafts::clear_caches($touched);return $supplier;}$suppliers[$supplier_key]=$supplier['id'];$supplier_new+=(int)$supplier['created'];}
			$row['supplier_id']=$suppliers[$supplier_key];
			unset($row['line']);$key=self::model_key($row['brand'],$row['model'],$row['trim_name'],(int)$row['model_year']);$model_id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $models WHERE model_key=%s FOR UPDATE",$key));
			if(!$model_id){if(1!==$wpdb->insert($models,array('model_key'=>$key,'brand'=>$row['brand'],'model'=>$row['model'],'trim_name'=>$row['trim_name'],'model_year'=>$row['model_year'],'created_at'=>$now))){return self::rollback('database',500,$touched);}$model_id=(int)$wpdb->insert_id;}
			$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM $stock WHERE source_identifier=%s FOR UPDATE",$row['source_identifier']),ARRAY_A);$row+=array('model_id'=>$model_id,'present'=>1,'last_import_id'=>$id,'updated_at'=>$now);
			if($existing){$result=$wpdb->update($stock,$row,array('id'=>$existing['id']));}else{$row['created_at']=$now;$result=$wpdb->insert($stock,$row);}if(false===$result){return self::rollback('database',500,$touched);}
		}
		if(false===$wpdb->query($wpdb->prepare("UPDATE $stock SET present=0,updated_at=%s WHERE present=1 AND last_import_id<>%d",$now,$id))){return self::rollback('database',500,$touched);}
		// Adopt only a unique existing full VIN and year. Never synthesize a VIN or branch.
		$unlinked=$wpdb->get_results("SELECT id,source_identifier,model_year FROM $stock WHERE present=1 AND vehicle_id IS NULL",ARRAY_A);if($wpdb->last_error){return self::rollback('database',500,$touched);}
		$auto_links=array();foreach($unlinked??array()as$source_row){$candidates=$wpdb->get_results($wpdb->prepare('SELECT v.id,v.vin,v.branch_id FROM '.Schema::table('vehicles').' v WHERE v.vin LIKE %s AND v.model_year=%d AND NOT EXISTS(SELECT 1 FROM '.$stock.' used WHERE used.vehicle_id=v.id) LIMIT 2 FOR UPDATE','%'.$wpdb->esc_like($source_row['source_identifier']),$source_row['model_year']),ARRAY_A);if($wpdb->last_error){return self::rollback('database',500,$touched);}if(count($candidates??array())!==1||!VehicleService::user_can_access_branch((int)$candidates[0]['branch_id'])){continue;}$matches=InventoryIdentityLookup::sources($candidates[0]['vin']);if(count($matches)!==1||(int)$matches[0]['id']!==(int)$source_row['id']){continue;}if(1!==$wpdb->update($stock,array('vehicle_id'=>(int)$candidates[0]['id']),array('id'=>$source_row['id'],'vehicle_id'=>null))){return self::rollback('database',500,$touched);}$auto_links[]=array('source_id'=>(int)$source_row['id'],'vehicle_id'=>(int)$candidates[0]['id']);}
		// Source identity and net price stay current; financial snapshots and operational state stay intact.
		if(false===$wpdb->query("UPDATE ".Schema::table('vehicles')." v INNER JOIN $stock s ON s.vehicle_id=v.id SET v.brand=s.brand,v.model=s.model,v.trim_name=s.trim_name,v.model_year=s.model_year,v.retail_price=s.listed_price WHERE s.present=1")){return self::rollback('database',500,$touched);}
		$summary['automatic_links']=$auto_links;
		$drafts=OnyxModelDrafts::create($touched);if(is_wp_error($drafts)){$wpdb->query('ROLLBACK');OnyxModelDrafts::clear_caches($touched);return $drafts;}
		$summary['model_drafts_created']=$drafts['created'];$summary['model_pages_retained']=$drafts['retained'];
		$title_touched=array();$titles_synced=OnyxCatalogNames::sync($title_touched);$touched=array_merge($touched,$title_touched);if(!$titles_synced){return self::rollback('database',500,$touched);}
		$publication=OnyxPublicationSync::sync($touched);if(is_wp_error($publication)){$wpdb->query('ROLLBACK');OnyxModelDrafts::clear_caches($touched);wp_cache_delete('adc_public_catalog_mode','options');wp_cache_delete('alloptions','options');wp_cache_delete('cron','options');return $publication;}
		$summary=array_merge($summary,$publication);$summary['model_pages_created']=$drafts['created'];
		$summary['suppliers_created']=$supplier_new;$summary['supplier_names']=count(array_filter($suppliers));
		if(1!==$wpdb->update($table,array('status'=>'applied','applied_at'=>$now,'summary_json'=>wp_json_encode($summary)),array('id'=>$id,'status'=>'preview'))||false===$wpdb->update($wpdb->options,array('option_value'=>(string)($revision+1)),array('option_name'=>'adc_onyx_revision'))||!Transaction::commit(static fn()=>AuditLog::record('inventory.onyx_imported','onyx_import',$id,'Final Onyx inventory synchronized and published',null,array('rows'=>count($rows),'mode'=>$batch['import_mode'],'new'=>$summary['new'],'updated'=>$summary['updated'],'missing'=>$summary['missing'],'revision'=>$revision+1,'suppliers_created'=>$supplier_new,'model_titles_updated'=>count($title_touched),'model_drafts_created'=>$drafts['created'],'model_pages_retained'=>$drafts['retained'],'publication'=>$publication,'automatic_links'=>$auto_links,'catalog_mode'=>'models')))){return self::rollback('database',500,$touched);}
		wp_cache_delete('adc_onyx_revision','options');return array('id'=>$id,'rows'=>count($rows),'suppliers_created'=>$supplier_new,'model_titles_updated'=>count($title_touched),'model_drafts_created'=>$drafts['created'],'model_pages_retained'=>$drafts['retained'],'publication'=>$publication,'automatic_links'=>$auto_links,'replayed'=>false);
	}
	private static function rollback(string $code,int $status,array $touched=array()){global $wpdb;$wpdb->query('ROLLBACK');OnyxModelDrafts::clear_caches($touched);wp_cache_delete('adc_public_catalog_mode','options');wp_cache_delete('alloptions','options');wp_cache_delete('cron','options');return self::error($code,$status);}
	private static function error(string $code,int $status){return new \WP_Error('adc_onyx_'.$code,$code,array('status'=>$status));}
}
