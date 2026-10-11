<?php
namespace AutoDealership\Inventory;

use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Source availability supplements operational state; it never records a financial sale. */
final class OnyxOperationalPolicy {
	public static function predicate(string $vehicle_alias='v'):string {
		$stock=Schema::table('onyx_stock');
		return "NOT EXISTS (SELECT 1 FROM $stock os WHERE os.vehicle_id=$vehicle_alias.id AND os.present=0)";
	}
	/** Call inside the caller's transaction after its vehicle lock. */
	public static function current(int $vehicle_id,bool $lock=false) {
		global $wpdb;
		$rows=$wpdb->get_results($wpdb->prepare('SELECT id,present,listed_price,model_id FROM '.Schema::table('onyx_stock').' WHERE vehicle_id=%d'.($lock?' FOR UPDATE':''),$vehicle_id),ARRAY_A);
		if($wpdb->last_error){return new \WP_Error('adc_onyx_read','Unable to verify Onyx inventory.',array('status'=>503));}
		if(count($rows??array())>1){return new \WP_Error('adc_onyx_conflict','Conflicting Onyx vehicle identity.',array('status'=>409));}
		if($rows&&!$rows[0]['present']){return new \WP_Error('adc_onyx_absent',\AutoDealership\Core\Localization::language()==='en'?'This vehicle is absent from the latest Onyx inventory. Review its open transactions.':'السيارة غير موجودة في آخر مخزون أونكس. راجع تعاملاتها المفتوحة.',array('status'=>409));}
		return $rows[0]??null;
	}
}
