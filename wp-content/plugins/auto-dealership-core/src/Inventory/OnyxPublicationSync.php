<?php
namespace AutoDealership\Inventory;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Caller owns the revision-locked inventory transaction and required audit commit. */
final class OnyxPublicationSync {
	public static function sync(array &$touched) {
		global $wpdb;
		$type=get_post_type_object('car');
		if(!$type||!current_user_can($type->cap->edit_posts)||!current_user_can($type->cap->publish_posts)){return new \WP_Error('adc_onyx_publication_forbidden');}
		$models=Schema::table('catalog_models');$stock=Schema::table('onyx_stock');
		// Once Onyx is authoritative, legacy unlinked public car posts must also leave the site.
		if(false===$wpdb->query("UPDATE $models m LEFT JOIN {$wpdb->posts} p ON p.ID=m.public_post_id SET m.public_post_id=NULL WHERE m.public_post_id IS NOT NULL AND (p.ID IS NULL OR p.post_type<>'car')")){return new \WP_Error('adc_onyx_database');}
		$rows=$wpdb->get_results("SELECT p.ID public_post_id,IF(m.id IS NULL,0,EXISTS (SELECT 1 FROM $stock s WHERE s.model_id=m.id AND ".GroupedModelCatalog::availability().")) available FROM {$wpdb->posts} p LEFT JOIN $models m ON m.public_post_id=p.ID WHERE p.post_type='car' ORDER BY p.ID FOR UPDATE",ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_onyx_database');}
		$result=array('published'=>0,'unpublished'=>0,'available_pages'=>0,'changes'=>array());
		foreach($rows as $row){
			$id=(int)$row['public_post_id'];
			$post=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d AND post_type='car' FOR UPDATE",$id));
			if($wpdb->last_error){return new \WP_Error('adc_onyx_database');}
			if(!$post){
				if($row['available']){return new \WP_Error('adc_onyx_publication_missing');}
				if(false===$wpdb->update($models,array('public_post_id'=>null),array('public_post_id'=>$id))){return new \WP_Error('adc_onyx_database');}
				continue;
			}
			$status=$row['available']?'publish':'draft';$result['available_pages']+=(int)$row['available'];
			// Historical pages already hidden in trash/private/draft need no status change.
			if($post->post_status===$status||(!$row['available']&&!in_array($post->post_status,array('publish','future'),true))){continue;}
			if(!current_user_can('edit_post',$id)){return new \WP_Error('adc_onyx_publication_forbidden');}
			$before_status=$post->post_status;$touched[]=$id;wp_cache_set($id,$post,'posts');
			if($row['available']&&$post->post_status==='trash'){
				if(!wp_untrash_post($id)){return new \WP_Error('adc_onyx_publication_failed');}
				$post=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$id));if(!$post||$wpdb->last_error){return new \WP_Error('adc_onyx_database');}wp_cache_set($id,$post,'posts');
			}
			$update=array('ID'=>$id,'post_status'=>$status,'post_name'=>$post->post_name);
			// The current final file supersedes a scheduled publication date as well.
			if($status==='publish'&&$post->post_date_gmt>current_time('mysql',true)){$update['post_date']=current_time('mysql');$update['post_date_gmt']=current_time('mysql',true);}
			$updated=wp_update_post(wp_slash($update),true);
			if(is_wp_error($updated)||!$updated){return new \WP_Error('adc_onyx_publication_failed');}
			if(get_post_status($id)!==$status){return new \WP_Error('adc_onyx_publication_failed');}
			$result['changes'][]=array('post_id'=>$id,'before'=>$before_status,'after'=>$status);
			++$result[$row['available']?'published':'unpublished'];clean_post_cache($id);
		}
		update_option('adc_public_catalog_mode','models',false);
		if(get_option('adc_public_catalog_mode')!=='models'||$wpdb->last_error){return new \WP_Error('adc_onyx_database');}
		return $result;
	}
}
