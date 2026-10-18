<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
defined('ABSPATH')||exit;

/** One exact trim/year configuration per public page, independent of physical VINs. */
final class OnyxCatalogNames {
	private static ?array $models=null;
	public static function invalidate():void{self::$models=null;}
	private static function models():array{
		global $wpdb;
		if(self::$models===null){self::$models=array();foreach($wpdb->get_results('SELECT id,brand,model,trim_name,model_year,public_post_id FROM '.Schema::table('catalog_models'),ARRAY_A)?:array()as$model){self::$models[(int)$model['id']]=$model;}}
		return self::$models;
	}
	public static function boot():void{
		add_action('clean_post_cache',array(self::class,'invalidate'));
		add_filter('the_title',static function($title,$id){if($id&&get_post_type($id)==='car'){$english=(!is_admin()||wp_doing_ajax())&&'en'===CatalogPresentation::language();$full=self::for_post((int)$id,$english);if($full){return $english?\AutoDealership\Content\PublicEditorialTranslations::title($full,(int)$id):$full;}}return $title;},30,2);
	}
	public static function row(array $row,bool $display=false):string{
		if($display){foreach(array('brand','model','trim_name')as$field){if(isset($row[$field])){$row[$field]=__((string)$row[$field],'auto-dealership-core');}}}
		return implode(' ',array_filter(array_map('trim',array((string)($row['brand']??''),(string)($row['model']??''),(string)($row['trim_name']??''),(string)($row['model_year']??''))),static fn($v)=>$v!==''));
	}
	public static function for_model(int $id,bool $display=false):string{
		global $wpdb;
		if(get_option('adc_db_version')!==Schema::VERSION){return '';}
		$model=self::models()[$id]??null;if(!$model){return '';}if($model['model_year']){return self::row($model,$display);}
		$stock=Schema::table('onyx_stock');$rows=array();
		foreach(array(GroupedModelCatalog::availability(),'s.present=1','1=1')as$where){$rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT s.trim_name,s.model_year FROM $stock s WHERE s.model_id=%d AND $where ORDER BY s.model_year DESC,s.trim_name ASC",$id),ARRAY_A);if($wpdb->last_error){return '';}if($rows){break;}}
		$variants=array();foreach($rows?:array()as$row){$variants[]=trim(($display?__((string)$row['trim_name'],'auto-dealership-core'):$row['trim_name']).' '.$row['model_year']);}
		return trim(self::row(array('brand'=>$model['brand'],'model'=>$model['model']),$display).' '.($variants[0]??''));
	}
	public static function for_post(int $id,bool $display=false):string{
		global $wpdb;if(get_option('adc_db_version')!==Schema::VERSION){return '';}
		foreach(self::models()as$model){if((int)$model['public_post_id']===$id){return self::for_model((int)$model['id'],$display);}}return '';
	}
	/** Caller owns the stock transaction and must clean touched caches after rollback. */
	public static function sync(array &$touched):bool{
		global $wpdb;
		self::invalidate();
		$ids=$wpdb->get_col('SELECT m.public_post_id FROM '.Schema::table('catalog_models')." m INNER JOIN {$wpdb->posts} p ON p.ID=m.public_post_id AND p.post_type='car' ORDER BY m.id");
		if($wpdb->last_error){return false;}
		foreach($ids?:array()as$id){
			$id=(int)$id;$title=self::for_post($id);
			if($wpdb->last_error){return false;}
			$locked=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d AND post_type='car' FOR UPDATE",$id));
			if($wpdb->last_error){return false;}if(!$locked||!$title||$locked->post_title===$title){continue;}
			// wp_update_post merges its cached record: prime it with the current locked row.
			wp_cache_set($id,$locked,'posts');$touched[]=$id;
			if(is_wp_error(wp_update_post(wp_slash(array('ID'=>$id,'post_title'=>$title,'post_name'=>$locked->post_name,'post_status'=>$locked->post_status)),true))){return false;}
			clean_post_cache($id);
		}
		return true;
	}
}
