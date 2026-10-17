<?php
namespace AutoDealership\Portal;
use AutoDealership\Database\Transaction;
defined('ABSPATH') || exit;

final class Reviews {
	public static function context(int $id):?array {
		global $wpdb;$row=Conversations::row($id);if(!$row||!PortalAccess::owns_customer((int)$row['customer_id'])||!in_array($row['kind'],array('message','booking'),true)||!$row['source_id']){return null;}
		$table=$wpdb->prefix.'car_dealer_'.('booking'===$row['kind']?'bookings':'messages');$valid=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d AND user_id=%d AND status='completed'",$row['source_id'],get_current_user_id()));return $valid?$row:null;
	}
	public static function row(int $id,bool $lock=false):?array {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.PortalAccess::table('reviews').' WHERE id=%d'.($lock?' FOR UPDATE':''),$id),ARRAY_A)?:null;}
	public static function version(int $id):?array {global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.PortalAccess::table('review_versions').' WHERE id=%d',$id),ARRAY_A)?:null;}
	public static function submit(array $input,int $id=0) {
		global $wpdb;if(!PortalAccess::ready()||!PortalAccess::verified()){return PortalAccess::error('adc_portal_verified',403);}
		$name=trim(sanitize_text_field($input['name']??''));$body=trim(sanitize_textarea_field($input['body']??''));$rating=filter_var($input['rating']??0,FILTER_VALIDATE_INT);$branch=absint($input['branch']??0);$thread=absint($input['thread']??0);$locale='en'===($input['locale']??'')?'en':'ar';
		if(!$name||mb_strlen($name)>100||str_contains($name,'@')||mb_strlen($body)<10||mb_strlen($body)>3000||$rating<1||$rating>5||'1'!==($input['consent']??'')||!in_array($branch,array_column(PortalAccess::branches(),'id'))){return PortalAccess::error('adc_portal_input');}
		if($thread){$context=self::context($thread);if(!$context||(int)$context['branch_id']!==$branch){return PortalAccess::error('adc_portal_forbidden',404);}}
		$key=hash('sha256','review:'.get_current_user_id().':'.($thread?'thread:'.$thread:'branch:'.$branch));$hash=hash('sha256',wp_json_encode(array($rating,$name,$body,$locale)));
		if(!Transaction::begin()){return PortalAccess::error();}
		$row=$id?self::row($id,true):$wpdb->get_row($wpdb->prepare('SELECT * FROM '.PortalAccess::table('reviews').' WHERE context_key=%s FOR UPDATE',$key),ARRAY_A);
		if($id&&(!$row||!PortalAccess::owns_customer((int)$row['user_id']))){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',404);}
		if($row){
			if(!$id){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_duplicate',409);}
			if((int)($input['revision']??0)!==(int)$row['revision']){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_stale',409);}
			if($row['context_key']!==$key){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_input');}
			$old=self::version((int)$row['current_version']);if($old&&hash_equals($old['content_hash'],$hash)&&$row['consent']){$wpdb->query('ROLLBACK');return (int)$row['id'];}
		}else{
			$now=current_time('mysql',true);if(!$wpdb->insert(PortalAccess::table('reviews'),array('user_id'=>get_current_user_id(),'branch_id'=>$branch,'context_key'=>$key,'thread_id'=>$thread,'created_at'=>$now,'updated_at'=>$now))){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_duplicate',409);}$id=(int)$wpdb->insert_id;$row=self::row($id,true);
		}
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('portal_review','account:'.get_current_user_id());if(is_wp_error($rate)){$wpdb->query('ROLLBACK');return $rate;}
		$ok=$wpdb->insert(PortalAccess::table('review_versions'),array('review_id'=>$row['id'],'rating'=>$rating,'display_name'=>$name,'body'=>$body,'locale'=>$locale,'content_hash'=>$hash,'created_at'=>current_time('mysql',true)));$version=(int)$wpdb->insert_id;
		if($ok){$ok=false!==$wpdb->update(PortalAccess::table('reviews'),array('current_version'=>$version,'consent'=>1,'status'=>'pending','revision'=>(int)$row['revision']+1,'updated_at'=>current_time('mysql',true)),array('id'=>$row['id']));}
		if(!$ok||!Transaction::commit(static fn()=>PortalAccess::audit('review_submitted','portal_review',(int)$row['id'],array('version_id'=>$version)))){$wpdb->query('ROLLBACK');return PortalAccess::error();}return (int)$row['id'];
	}
	public static function withdraw(int $id,int $revision) {
		global $wpdb;if(!PortalAccess::ready()||!Transaction::begin()){return PortalAccess::error();}$row=self::row($id,true);
		if(!$row||!PortalAccess::owns_customer((int)$row['user_id'])){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',404);}
		if($revision!==(int)$row['revision']){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_stale',409);}
		$ok=false!==$wpdb->update(PortalAccess::table('reviews'),array('consent'=>0,'public_version'=>0,'status'=>'withdrawn','revision'=>$revision+1,'updated_at'=>current_time('mysql',true)),array('id'=>$id));
		if(!$ok||!Transaction::commit(static fn()=>PortalAccess::audit('review_withdrawn','portal_review',$id))){$wpdb->query('ROLLBACK');return PortalAccess::error();}self::purge();return true;
	}
	public static function moderate(int $id,int $version,int $revision,string $action,string $reason='') {
		global $wpdb;if(!PortalAccess::ready()||!PortalAccess::cap('adc_moderate_reviews')||!in_array($action,array('publish','reject','hide'),true)||!Transaction::begin()){return PortalAccess::error('adc_portal_forbidden',403);}
		$row=self::row($id,true);$reason=trim(sanitize_textarea_field($reason));
		if(!$row||!PortalAccess::branch((int)$row['branch_id'])||('publish'===$action&&!PortalAccess::cap('adc_publish_reviews'))){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',404);}
		if((int)$row['current_version']!==$version||(int)$row['revision']!==$revision||!$row['consent']||'withdrawn'===$row['status']){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_stale',409);}
		if(mb_strlen($reason)>500||('reject'===$action&&!$reason)){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_input');}
		$ok=$wpdb->insert(PortalAccess::table('review_actions'),array('review_id'=>$id,'version_id'=>$version,'actor_id'=>get_current_user_id(),'action'=>$action,'reason'=>$reason,'created_at'=>current_time('mysql',true)));
		$action_id=(int)$wpdb->insert_id;
		$changes=array('status'=>array('publish'=>'published','reject'=>'rejected','hide'=>'hidden')[$action],'revision'=>$revision+1,'updated_at'=>current_time('mysql',true));
		// Rejecting an edit preserves the earlier consented published version; hiding is explicit.
		if('publish'===$action){$changes['public_version']=$version;}elseif('hide'===$action){$changes['public_version']=0;}
		if($ok){$ok=false!==$wpdb->update(PortalAccess::table('reviews'),$changes,array('id'=>$id));}
		if($ok){$ok=PortalAccess::notify((int)$row['user_id'],'review',$id,'review:'.$id.':'.($revision+1));}
		$event=$ok&&PortalAccess::settings()['mail']?\AutoDealership\Operations\OutboxService::enqueue('portal.review',array('subject_type'=>'portal_review_action','subject_id'=>$action_id),'portal-review:'.$action_id):true;
		if(!$ok||is_wp_error($event)||!Transaction::commit(static fn()=>PortalAccess::audit('review_'.$action,'portal_review',$id,array('version_id'=>$version)))){$wpdb->query('ROLLBACK');return PortalAccess::error();}self::purge();if(is_array($event)){PortalMail::dispatch_after_commit((int)$event['id'],'portal.review');}return true;
	}
	public static function mine(int $page=1):array {global $wpdb;if(!PortalAccess::ready()){return array();}return $wpdb->get_results($wpdb->prepare('SELECT r.*,v.rating,v.display_name,v.body,v.locale,(SELECT a.reason FROM '.PortalAccess::table('review_actions').' a WHERE a.review_id=r.id AND a.version_id=r.current_version ORDER BY a.id DESC LIMIT 1) reason FROM '.PortalAccess::table('reviews').' r JOIN '.PortalAccess::table('review_versions').' v ON v.id=r.current_version WHERE r.user_id=%d ORDER BY r.id DESC LIMIT 21 OFFSET %d',get_current_user_id(),max(0,min(10000,$page)-1)*20),ARRAY_A)?:array();}
	public static function owned(int $id,int $thread=0):?array {
		global $wpdb;return $wpdb->get_row($wpdb->prepare('SELECT r.*,v.rating,v.display_name,v.body,v.locale FROM '.PortalAccess::table('reviews').' r JOIN '.PortalAccess::table('review_versions').' v ON v.id=r.current_version WHERE r.user_id=%d AND (r.id=%d OR (%d>0 AND r.thread_id=%d)) LIMIT 1',get_current_user_id(),$id,$thread,$thread),ARRAY_A)?:null;
	}
	public static function moderation_list(int $page=1,string $status='pending'):array {
		global $wpdb;if(!PortalAccess::ready()||!PortalAccess::cap('adc_moderate_reviews')){return array();}list($scope,$args)=\AutoDealership\Security\BranchScope::predicate('r.branch_id');
		if(in_array($status,array('pending','published','rejected','hidden','withdrawn'),true)){$scope.=' AND r.status=%s';$args[]=$status;}$args[]=max(0,min(10000,$page)-1)*20;
		return $wpdb->get_results($wpdb->prepare('SELECT r.*,v.rating,v.display_name,v.body,v.locale FROM '.PortalAccess::table('reviews').' r JOIN '.PortalAccess::table('review_versions')." v ON v.id=r.current_version WHERE $scope ORDER BY r.updated_at DESC,r.id DESC LIMIT 21 OFFSET %d",$args),ARRAY_A)?:array();
	}
	public static function public_rows(int $limit=12):array {
		global $wpdb;if(!PortalAccess::ready()){return array();}return $wpdb->get_results($wpdb->prepare('SELECT r.id,v.rating,v.display_name,v.body,v.locale,v.created_at FROM '.PortalAccess::table('reviews')." r JOIN ".PortalAccess::table('review_versions')." v ON v.id=r.public_version AND v.review_id=r.id WHERE r.consent=1 AND r.public_version>0 AND r.status<>'withdrawn' ORDER BY r.updated_at DESC,r.id DESC LIMIT %d",max(1,min(48,$limit))),ARRAY_A)?:array();
	}
	public static function content_rows(array $existing,string $lang):array {
		$seen=array();foreach($existing as $row){$seen[hash('sha256',trim($row['name_'.$lang]).'|'.trim($row['text_'.$lang]).'|'.(int)$row['rating'])]=true;}
		foreach(self::public_rows(48) as $row){$key=hash('sha256',trim($row['display_name']).'|'.trim($row['body']).'|'.(int)$row['rating']);if(isset($seen[$key])){continue;}$seen[$key]=true;$existing[]=array('id'=>'customer-review-'.$row['id'],'status'=>'published','order'=>-(int)$row['id'],'name_'.$lang=>$row['display_name'],'text_'.$lang=>$row['body'],'rating'=>$row['rating'],'image'=>0,'source'=>PortalAccess::t('رأي عميل من الموقع','Website customer review'),'original_language'=>$row['locale']);}return $existing;
	}
	public static function purge():void {
		foreach(array('w3tc_flush_all','car_dealer_clear_public_page_cache') as $function){if(is_callable($function)){$function();}}
		do_action('adc_portal_reviews_published');
	}
}
