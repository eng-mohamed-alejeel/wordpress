<?php
namespace AutoDealership\Portal;
defined('ABSPATH') || exit;

final class PortalPrivacy {
	public static function exporter(array $items):array {$items['adc-customer-portal']=array('exporter_friendly_name'=>'Customer portal','callback'=>array(self::class,'export'));return $items;}
	public static function eraser(array $items):array {$items['adc-customer-portal']=array('eraser_friendly_name'=>'Customer portal','callback'=>array(self::class,'erase'));return $items;}
	public static function export(string $email,int $page=1):array {
		global $wpdb;$user=get_user_by('email',sanitize_email($email));$items=array();if(!$user||!\AutoDealership\Database\Schema::is_ready()){return array('data'=>$items,'done'=>true);}$offset=max(0,$page-1)*50;
		$messages=$wpdb->get_results($wpdb->prepare('SELECT m.id,m.body,m.created_at FROM '.PortalAccess::table('messages').' m JOIN '.PortalAccess::table('threads')." t ON t.id=m.thread_id WHERE (t.customer_id=%d AND m.visibility='public') OR m.sender_id=%d ORDER BY m.id LIMIT 50 OFFSET %d",$user->ID,$user->ID,$offset),ARRAY_A)?:array();
		foreach($messages as $row){$items[]=array('group_id'=>'adc_portal','group_label'=>'Customer conversations','item_id'=>'portal-message-'.$row['id'],'data'=>array(array('name'=>'Message','value'=>$row['body']),array('name'=>'Created','value'=>$row['created_at'])));}
		$versions=$wpdb->get_results($wpdb->prepare('SELECT v.* FROM '.PortalAccess::table('review_versions').' v JOIN '.PortalAccess::table('reviews').' r ON r.id=v.review_id WHERE r.user_id=%d ORDER BY v.id LIMIT 50 OFFSET %d',$user->ID,$offset),ARRAY_A)?:array();
		foreach($versions as $row){$items[]=array('group_id'=>'adc_reviews','group_label'=>'Customer reviews','item_id'=>'portal-review-version-'.$row['id'],'data'=>array(array('name'=>'Review','value'=>$row['body']),array('name'=>'Display name','value'=>$row['display_name']),array('name'=>'Rating','value'=>(string)$row['rating'])));}
		$favorites=$wpdb->get_results($wpdb->prepare('SELECT car_id,created_at FROM '.PortalAccess::table('favorites').' WHERE user_id=%d ORDER BY car_id LIMIT 50 OFFSET %d',$user->ID,$offset),ARRAY_A)?:array();foreach($favorites as $row){$items[]=array('group_id'=>'adc_favorites','group_label'=>'Saved cars','item_id'=>'favorite-'.$row['car_id'],'data'=>array(array('name'=>'Car ID','value'=>(string)$row['car_id']),array('name'=>'Saved','value'=>$row['created_at'])));}
		$documents=$wpdb->get_results($wpdb->prepare('SELECT d.id,d.title,d.reference,d.created_at,d.status FROM '.PortalAccess::table('documents').' d JOIN '.\AutoDealership\Database\Schema::table('sales').' s ON s.id=d.sale_id JOIN '.\AutoDealership\Database\Schema::table('customers').' c ON c.id=s.customer_id WHERE c.account_user_id=%d AND c.merged_into_id IS NULL ORDER BY d.id LIMIT 50 OFFSET %d',$user->ID,$offset),ARRAY_A)?:array();foreach($documents as $row){$items[]=array('group_id'=>'adc_purchase_documents','group_label'=>'Purchase documents','item_id'=>'purchase-document-'.$row['id'],'data'=>array(array('name'=>'Title','value'=>$row['title']),array('name'=>'Reference','value'=>$row['reference']),array('name'=>'Status','value'=>$row['status'])));}
		return array('data'=>$items,'done'=>count($messages)<50&&count($versions)<50&&count($favorites)<50&&count($documents)<50);
	}
	public static function erase(string $email,int $page=1):array {
		global $wpdb;$user=get_user_by('email',sanitize_email($email));$result=array('items_removed'=>false,'items_retained'=>false,'messages'=>array(),'done'=>true);
		if(!$user||!\AutoDealership\Database\Schema::is_ready()){return $result;}
		$retained=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.PortalAccess::table('documents').' d JOIN '.\AutoDealership\Database\Schema::table('sales').' s ON s.id=d.sale_id JOIN '.\AutoDealership\Database\Schema::table('customers').' c ON c.id=s.customer_id WHERE c.account_user_id=%d',$user->ID));if($retained){$result['items_retained']=true;$result['messages'][]='Purchase documents remain subject to the dealership financial-record retention policy; review them separately.';}
		if(!\AutoDealership\Database\Transaction::begin()){$result['items_retained']=true;$result['done']=false;return $result;}
		$owned_reviews=$wpdb->prepare('SELECT id FROM '.PortalAccess::table('reviews').' WHERE user_id=%d',$user->ID);$owned_threads=$wpdb->prepare('SELECT id FROM '.PortalAccess::table('threads').' WHERE customer_id=%d',$user->ID);
		// Hide every public version immediately; erase large histories in bounded batches.
		$ok=false!==$wpdb->query($wpdb->prepare('UPDATE '.PortalAccess::table('reviews')." SET consent=0,public_version=0,status='withdrawn',revision=revision+1 WHERE user_id=%d AND (consent<>0 OR public_version<>0 OR status<>'withdrawn')",$user->ID));
		$queries=array(
			'DELETE FROM '.PortalAccess::table('favorites').' WHERE user_id='.(int)$user->ID.' LIMIT 500',
			'DELETE FROM '.PortalAccess::table('presence').' WHERE user_id='.(int)$user->ID.' LIMIT 500',
			'UPDATE '.PortalAccess::table('review_versions')." SET body='[erased]',display_name='Anonymous' WHERE review_id IN ($owned_reviews) AND (body<>'[erased]' OR display_name<>'Anonymous') LIMIT 500",
			'UPDATE '.PortalAccess::table('review_actions')." SET reason='' WHERE review_id IN ($owned_reviews) AND reason<>'' LIMIT 500",
			'UPDATE '.PortalAccess::table('messages')." SET body='[erased]',sender_label='Anonymous' WHERE (thread_id IN ($owned_threads) OR sender_id=".(int)$user->ID.") AND (body<>'[erased]' OR sender_label<>'Anonymous') LIMIT 500",
			'UPDATE '.PortalAccess::table('threads')." SET subject='[erased]' WHERE customer_id=".(int)$user->ID." AND subject<>'[erased]' LIMIT 500",
			'DELETE FROM '.PortalAccess::table('notifications').' WHERE user_id='.(int)$user->ID.' LIMIT 500',
			'DELETE FROM '.PortalAccess::table('reads').' WHERE user_id='.(int)$user->ID.' LIMIT 500',
		);
		$more=false;foreach($queries as $query){$changed=$wpdb->query($query);if(false===$changed){$ok=false;break;}$more=$more||$changed>=500;}
		if(!$ok||!\AutoDealership\Database\Transaction::commit(static fn()=>PortalAccess::audit('privacy_erased','user',$user->ID))){$wpdb->query('ROLLBACK');$result['items_retained']=true;$result['done']=false;return $result;}$result['items_removed']=true;$result['done']=!$more;Reviews::purge();return $result;
	}
}
