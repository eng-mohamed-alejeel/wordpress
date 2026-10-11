<?php
namespace AutoDealership\Content;
use AutoDealership\Database\Transaction;
use AutoDealership\Audit\AuditLog;
defined('ABSPATH') || exit;

final class OfferPublishingService {
	public static function fingerprint(int $id):string{return hash('sha256',wp_json_encode(array(get_post($id)?->to_array(),get_post_meta($id))));}
	private static function error(string $message):\WP_Error{return new \WP_Error('adc_offer_invalid',$message);}
	private static function begin():bool {global $wpdb;foreach(array($wpdb->posts,$wpdb->postmeta,$wpdb->options)as$table){if($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table))!=='InnoDB'){return false;}}return Transaction::begin();}
	public static function save(array $input) {
		global $wpdb;$id=absint($input['post_id']??0);$type=get_post_type_object('car_offer');
		if(!$type||!current_user_can($type->cap->edit_posts)||($id&&(get_post_type($id)!=='car_offer'||!current_user_can('edit_post',$id)))||(!$id&&!current_user_can($type->cap->create_posts))){return self::error('You cannot edit this offer.');}
		foreach(array('title','description','summary','status','expires','revision')as$key){if(isset($input[$key])&&!is_string($input[$key])){return self::error('Invalid offer fields.');}}
		foreach(array('post_id','car_id','thumbnail_id','old_price','new_price','monthly_payment')as$key){if(isset($input[$key])&&(!is_scalar($input[$key])||!preg_match('/\A[0-9]{1,12}\z/',(string)$input[$key]))){return self::error('Enter whole SAR amounts and valid record identifiers.');}}
		$title=sanitize_text_field($input['title']??'');$status=$input['status']??'draft';$car=absint($input['car_id']??0);$image=absint($input['thumbnail_id']??0);$expires=$input['expires']??'';$price=(int)($input['new_price']??0);$old=(int)($input['old_price']??0);
		if(!$title||mb_strlen($title)>200||!in_array($status,array('draft','pending','publish','private','future'),true)||($car&&get_post_type($car)!=='car')||($car&&!current_user_can('read_post',$car))){return self::error('Offer title, status or vehicle is invalid.');}
		if(in_array($status,array('publish','private','future'),true)&&!current_user_can($type->cap->publish_posts)){return self::error('You cannot publish offers.');}
		$date=$expires!==''?\DateTimeImmutable::createFromFormat('!Y-m-d',$expires):null;if($expires!==''&&(!$date||$date->format('Y-m-d')!==$expires)){return self::error('Enter a valid expiry date.');}
		if($old>0&&$price>0&&$old<=$price){return self::error('The previous price must exceed the offer price.');}
		if($image&&(get_post_type($image)!=='attachment'||!wp_attachment_is_image($image)||!current_user_can('read_post',$image))){return self::error('Select an accessible image.');}
		$request=$input['request_key']??'';if(!is_string($request)||($request!==''&&!wp_is_uuid($request))){return self::error('Invalid offer request.');}
		if(!self::begin()){return self::error('Offer storage is unavailable.');}
		try {
			$claim='';$signature='';if(!$id&&$request!==''){
				$claim='adc_offer_request_'.get_current_user_id().'_'.hash('sha256',$request);
				$signature=hash('sha256',wp_json_encode(array($title,$status,$car,$image,$expires,$price,$old,(int)($input['monthly_payment']??0),wp_kses_post($input['description']??''),sanitize_textarea_field($input['summary']??''))));
				if(false===$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES(%s,%s,'off') ON DUPLICATE KEY UPDATE option_name=VALUES(option_name)",$claim,wp_json_encode(array('id'=>0,'signature'=>$signature))))){throw new \RuntimeException('Offer storage is unavailable.');}
				$stored=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$claim)),true);
				if(!is_array($stored)||!hash_equals($stored['signature']??'',$signature)){throw new \RuntimeException('Offer request does not match the original submission.');}
				if(!empty($stored['id'])){$existing=(int)$stored['id'];$wpdb->query('ROLLBACK');return get_post_type($existing)==='car_offer'&&current_user_can('edit_post',$existing)?array('id'=>$existing,'replayed'=>true):self::error('Offer request is no longer available.');}
			}
			if($id){$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$id));clean_post_cache($id);if(!hash_equals(self::fingerprint($id),$input['revision']??'')){throw new \RuntimeException('This offer changed. Reload before saving.');}if(get_post_status($id)==='trash'){throw new \RuntimeException('Restore this offer before editing.');}}
			if($car){$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$car));clean_post_cache($car);}
			if($status==='future'&&(!$id||get_post_status($id)!=='future')){throw new \RuntimeException('Invalid offer status.');}
			if(in_array($status,array('publish','future'),true)&&(!$price||!$car||!\AutoDealership\Inventory\PublicCatalog::is_post_publicly_eligible($car)||($expires!==''&&$expires<current_time('Y-m-d')))){throw new \RuntimeException('Publishing requires an available published vehicle, a positive price and an unexpired offer.');}
			$result=wp_insert_post(wp_slash(array('ID'=>$id,'post_type'=>'car_offer','post_title'=>$title,'post_content'=>wp_kses_post($input['description']??''),'post_excerpt'=>sanitize_textarea_field($input['summary']??''),'post_status'=>$status)),true);if(is_wp_error($result)){throw new \RuntimeException($result->get_error_message());}$id=(int)$result;
			$meta=array('_offer_car_id'=>$car,'_offer_old_price'=>$old,'_offer_new_price'=>$price,'_offer_monthly_payment'=>(int)($input['monthly_payment']??0),'_offer_expires'=>$expires,'_thumbnail_id'=>$image);
			foreach($meta as$key=>$value){update_post_meta($id,$key,$value);if((string)get_post_meta($id,$key,true)!==(string)$value){throw new \RuntimeException('Unable to save offer details.');}}
			if($claim&&1!==$wpdb->update($wpdb->options,array('option_value'=>wp_json_encode(array('id'=>$id,'signature'=>$signature))),array('option_name'=>$claim))){throw new \RuntimeException('Offer storage is unavailable.');}
			if(!Transaction::commit(static fn()=>AuditLog::record('content.offer_saved','car_offer',$id,'Offer saved',null,array('car_id'=>$car,'status'=>$status,'price'=>$price,'currency'=>'SAR','expires'=>$expires)))){throw new \RuntimeException('Unable to audit the offer.');}clean_post_cache($id);return array('id'=>$id);
		}catch(\Throwable $error){$wpdb->query('ROLLBACK');if($id){clean_post_cache($id);wp_cache_delete($id,'post_meta');wp_cache_delete('alloptions','options');wp_cache_delete('cron','options');}return self::error($error->getMessage());}
	}
	public static function change_status(int $id,string $action,string $revision) {
		global $wpdb;if(get_post_type($id)!=='car_offer'||!current_user_can('delete_post',$id)||!in_array($action,array('trash','restore'),true)){return self::error('You cannot change this offer.');}
		if($action==='trash'&&defined('EMPTY_TRASH_DAYS')&&!EMPTY_TRASH_DAYS){return self::error('Trash is disabled.');}if(!self::begin()){return self::error('Offer storage is unavailable.');}
		try{
		$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE",$id));clean_post_cache($id);
		if(!hash_equals(self::fingerprint($id),$revision)||($action==='restore'&&get_post_status($id)!=='trash')||($action==='trash'&&get_post_status($id)==='trash')){$wpdb->query('ROLLBACK');return self::error('This offer changed. Reload before saving.');}
		$ok=$action==='trash'?wp_trash_post($id):wp_untrash_post($id);if($ok&&$action==='restore'){$ok=wp_update_post(array('ID'=>$id,'post_status'=>'draft'),true);}
		if(!$ok||is_wp_error($ok)||!Transaction::commit(static fn()=>AuditLog::record('content.offer_'.$action,'car_offer',$id,'Offer publication state changed'))){$wpdb->query('ROLLBACK');clean_post_cache($id);wp_cache_delete('alloptions','options');wp_cache_delete('cron','options');return self::error('Unable to change the offer.');}clean_post_cache($id);return array('id'=>$id);
		}catch(\Throwable){$wpdb->query('ROLLBACK');clean_post_cache($id);wp_cache_delete($id,'post_meta');wp_cache_delete('alloptions','options');wp_cache_delete('cron','options');return self::error('Unable to change the offer.');}
	}
}
