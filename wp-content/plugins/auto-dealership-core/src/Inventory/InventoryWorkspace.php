<?php
namespace AutoDealership\Inventory;

use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;
defined('ABSPATH') || exit;

final class InventoryWorkspace {
	public static function warehouse_branches():array { $map=get_option('adc_onyx_warehouse_branches',array());return is_array($map)?$map:array(); }
	public static function source(int $id) {
		global $wpdb;
		$row=$wpdb->get_row($wpdb->prepare('SELECT s.*,v.branch_id,v.status operational_status FROM '.Schema::table('onyx_stock').' s LEFT JOIN '.Schema::table('vehicles').' v ON v.id=s.vehicle_id WHERE s.id=%d',$id),ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_inventory_read','Inventory unavailable');}
		if(!current_user_can('adc_view_inventory')||!$row){return new \WP_Error('adc_forbidden','Forbidden');}
		$branch=(int)($row['branch_id']?:self::warehouse_branches()[$row['warehouse_name']]??0);
		if(!BranchScope::is_global()&&(!$branch||!BranchScope::allows($branch))){return new \WP_Error('adc_forbidden','Forbidden');}
		$row['mapped_branch_id']=$branch;return $row;
	}
	public static function list(array $filters=array()) {
		global $wpdb;
		if(!current_user_can('adc_view_inventory')){return new \WP_Error('adc_forbidden','Forbidden');}
		$stock=Schema::table('onyx_stock');$vehicles=Schema::table('vehicles');$models=Schema::table('catalog_models');
		$select="SELECT s.*,v.branch_id,v.status operational_status,v.stock_number,v.vin,v.retail_price operational_price,m.public_post_id FROM $stock s LEFT JOIN $vehicles v ON v.id=s.vehicle_id LEFT JOIN $models m ON m.id=s.model_id";
		$where=array('1=1');$args=array();
		if(!BranchScope::is_global()){
			list($scope,$scope_args)=BranchScope::predicate('v.branch_id');$names=array();
			foreach(self::warehouse_branches()as$name=>$branch){if(BranchScope::allows((int)$branch)){$names[]=$name;}}
			$extra=$names?' OR (s.vehicle_id IS NULL AND s.warehouse_name IN ('.implode(',',array_fill(0,count($names),'%s')).'))':'';
			$where[]='('.$scope.$extra.')';$args=array_merge($args,$scope_args,$names);
		}
		foreach(array('brand','model','trim_name','model_year','warehouse_name')as$key){if(!empty($filters[$key])){$where[]="s.$key=%s";$args[]=sanitize_text_field((string)$filters[$key]);}}
		if(!empty($filters['search'])){$pattern='%'.$wpdb->esc_like(sanitize_text_field((string)$filters['search'])).'%';$where[]='(s.source_identifier LIKE %s OR v.vin LIKE %s OR s.brand LIKE %s OR s.model LIKE %s OR s.trim_name LIKE %s)';$args=array_merge($args,array_fill(0,5,$pattern));}
		$status=$filters['availability']??'available';if(in_array($status,array('available','sold'),true)){$where[]='s.present=%d';$args[]=$status==='available'?1:0;}
		if(($filters['completion']??'')==='pending'){$where[]='s.vehicle_id IS NULL';}elseif(($filters['completion']??'')==='complete'){$where[]='s.vehicle_id IS NOT NULL';}
		if(!empty($filters['operational_status'])){$where[]='v.status=%s';$args[]=sanitize_key($filters['operational_status']);}
		if(!empty($filters['branch_id'])){$branch=absint($filters['branch_id']);if(!BranchScope::allows($branch)){return new \WP_Error('adc_forbidden','Forbidden');}$names=array();foreach(self::warehouse_branches()as$name=>$mapped){if((int)$mapped===$branch){$names[]=$name;}}$extra=$names?' OR (s.vehicle_id IS NULL AND s.warehouse_name IN ('.implode(',',array_fill(0,count($names),'%s')).'))':'';$where[]='(v.branch_id=%d'.$extra.')';$args=array_merge($args,array($branch),$names);}
		if(($filters['conflicts']??'')==='1'){$where[]="s.present=0 AND (EXISTS(SELECT 1 FROM ".Schema::table('reservations')." r WHERE r.vehicle_id=v.id AND r.status='confirmed') OR EXISTS(SELECT 1 FROM ".Schema::table('sales')." sale WHERE sale.vehicle_id=v.id AND sale.status='pending_approval'))";}
		$clause=' WHERE '.implode(' AND ',$where);$count='SELECT COUNT(*) FROM '.$stock.' s LEFT JOIN '.$vehicles.' v ON v.id=s.vehicle_id'.$clause;
		$total=(int)$wpdb->get_var($args?$wpdb->prepare($count,$args):$count);if($wpdb->last_error){return new \WP_Error('adc_inventory_read','Inventory unavailable');}
		$page=max(1,absint($filters['paged']??1));$pages=max(1,(int)ceil($total/50));$page=min($page,$pages);
		$rows=$wpdb->get_results($wpdb->prepare($select.$clause.' ORDER BY s.brand,s.model,s.trim_name,s.model_year DESC,s.id LIMIT 50 OFFSET %d',array_merge($args,array(($page-1)*50))),ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_inventory_read','Inventory unavailable');}
		return array('rows'=>$rows??array(),'total'=>$total,'page'=>$page,'pages'=>$pages);
	}
}
