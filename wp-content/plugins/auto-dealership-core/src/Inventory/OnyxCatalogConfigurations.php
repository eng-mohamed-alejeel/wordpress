<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Refine legacy model groups inside the caller's revision-locked transaction. */
final class OnyxCatalogConfigurations {
	private static bool $changed=false;
	public static function changed(): bool { return self::$changed; }
	public static function split(array &$touched): bool {
		self::$changed=false;
		global $wpdb;$models=Schema::table('catalog_models');$stock=Schema::table('onyx_stock');
		$groups=$wpdb->get_results("SELECT * FROM $models ORDER BY id FOR UPDATE",ARRAY_A);
		if($wpdb->last_error){return false;}
		foreach($groups as $group){
			$pairs=$wpdb->get_results($wpdb->prepare("SELECT BINARY trim_name trim_name,model_year,MAX(present) present FROM $stock WHERE model_id=%d GROUP BY BINARY trim_name,model_year ORDER BY present DESC,model_year DESC,trim_name ASC",$group['id']),ARRAY_A);
			if($wpdb->last_error){return false;}if(!$pairs){continue;}
			// Once a page has a configuration identity, retain that identity on subsequent splits.
			foreach($pairs as $position=>$pair){if(OnyxImportService::model_key($group['brand'],$group['model'],$pair['trim_name'],(int)$pair['model_year'])===$group['model_key']){if($position){array_splice($pairs,$position,1);array_unshift($pairs,$pair);}break;}}
			foreach($pairs as $index=>$pair){
				$key=OnyxImportService::model_key($group['brand'],$group['model'],$pair['trim_name'],(int)$pair['model_year']);
				$existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $models WHERE model_key=%s FOR UPDATE",$key));
				if($wpdb->last_error||($existing&&$existing!==(int)$group['id'])){return false;}
				if(!$index){
					$id=(int)$group['id'];$result=$wpdb->update($models,array('model_key'=>$key,'trim_name'=>$pair['trim_name'],'model_year'=>$pair['model_year']),array('id'=>$id));if(false===$result){return false;}self::$changed=self::$changed||$result>0;
					if($group['public_post_id']&&get_post_meta((int)$group['public_post_id'],'_adc_onyx_model_key',true)!==$key){$touched[]=(int)$group['public_post_id'];update_post_meta((int)$group['public_post_id'],'_adc_onyx_model_key',$key);if($wpdb->last_error){return false;}}
				}else{
					self::$changed=true;
					if(1!==$wpdb->insert($models,array('model_key'=>$key,'brand'=>$group['brand'],'model'=>$group['model'],'trim_name'=>$pair['trim_name'],'model_year'=>$pair['model_year'],'created_at'=>current_time('mysql',true)))){return false;}$id=(int)$wpdb->insert_id;
				}
				if(false===$wpdb->query($wpdb->prepare("UPDATE $stock SET model_id=%d WHERE model_id=%d AND BINARY trim_name=BINARY %s AND model_year=%d",$id,$group['id'],$pair['trim_name'],$pair['model_year']))){return false;}
			}
		}
		OnyxCatalogNames::invalidate();return true;
	}
}
