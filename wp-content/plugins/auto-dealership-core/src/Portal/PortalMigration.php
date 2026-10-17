<?php
namespace AutoDealership\Portal;
defined('ABSPATH') || exit;

/** Additive, restartable, no email matching and no historical delivery. */
final class PortalMigration {
	public static function batch():void {
		if(!current_user_can('manage_options')||!PortalAccess::ready()||wp_doing_ajax()){return;}
		global $wpdb;$lock='adc_portal_import_'.md5(DB_NAME.$wpdb->prefix);
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))){return;}
		try{$cursor=(array)get_option('adc_portal_migration',array());$remaining=25;
			foreach(array('message'=>'messages','booking'=>'bookings') as $kind=>$suffix){if(!$remaining){break;}$rows=$wpdb->get_col($wpdb->prepare("SELECT r.id FROM {$wpdb->prefix}car_dealer_$suffix r INNER JOIN {$wpdb->users} u ON u.ID=r.user_id WHERE r.id>%d ORDER BY r.id LIMIT %d",(int)($cursor[$kind]??0),$remaining));
				foreach($rows as $id){$result=Conversations::ensure($kind,(int)$id);if(is_wp_error($result)){update_option('adc_portal_migration_error',$result->get_error_code(),false);return;}$cursor[$kind]=(int)$id;$remaining--;update_option('adc_portal_migration',$cursor,false);}
			}delete_option('adc_portal_migration_error');
		}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
}
