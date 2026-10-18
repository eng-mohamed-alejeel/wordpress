<?php
namespace AutoDealership\Portal;
use AutoDealership\Database\Schema;
use AutoDealership\Database\Transaction;
defined('ABSPATH') || exit;

final class Conversations {
	public static function row(int $id,bool $lock=false):?array {
		global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.PortalAccess::table('threads').' WHERE id=%d'.($lock?' FOR UPDATE':''),$id),ARRAY_A);
		if(!$row){return null;}
		if($row['lead_id']){$lead=$wpdb->get_row($wpdb->prepare('SELECT branch_id,owner_user_id FROM '.Schema::table('leads').' WHERE id=%d',$row['lead_id']),ARRAY_A);$row['branch_id']=$lead['branch_id']??0;$row['owner_id']=$lead['owner_user_id']??0;}
		return $row;
	}
	/** One transaction owns this context; imported messages never notify or send email. */
	public static function ensure(string $kind,int $source=0,string $subject='',int $branch=0) {
		global $wpdb;$user=get_current_user_id();if(!PortalAccess::ready()||!$user||!in_array($kind,array('message','booking','general'),true)){return PortalAccess::error();}
		$original=null;$lead=null;$car=0;
		if('general'!==$kind){
			$table=$wpdb->prefix.'car_dealer_'.('message'===$kind?'messages':'bookings');$original=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$source),ARRAY_A);
			if(!$original){return PortalAccess::error('adc_portal_forbidden',404);}
			$lead=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('leads').' WHERE legacy_request_type=%s AND legacy_request_id=%d',$kind,$source),ARRAY_A);
			if((int)$original['user_id']!==$user){
				if(!current_user_can('manage_options')&&(!PortalAccess::staff()||!$lead||!\AutoDealership\Security\BranchScope::can_view_lead($lead))){return PortalAccess::error('adc_portal_forbidden',404);}
			}
			$user=(int)$original['user_id'];if(!$user){return PortalAccess::error('adc_portal_forbidden',404);}
			$branch=(int)($lead['branch_id']??0);$car=(int)$original['car_id'];$subject=PortalAccess::t('booking'===$kind?'تجربة قيادة':'طلب واستفسار','booking'===$kind?'Test drive':'Enquiry').' #'.$source;
		}elseif(!PortalAccess::verified()||!in_array($branch,array_column(PortalAccess::branches(),'id'))||''===trim($subject)||mb_strlen($subject)>190){return PortalAccess::error('adc_portal_input');}
		if('general'===$kind){$rate=\AutoDealership\Security\PublicRequestGuard::consume('portal_context');if(is_wp_error($rate)){return $rate;}}
		$key=hash('sha256',($source?$kind.':'.$source:'general:'.$user.':'.$branch.':'.sanitize_text_field($subject)));
		if(!Transaction::begin()){return PortalAccess::error();}
		$now=current_time('mysql',true);$insert=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('threads').'(customer_id,branch_id,owner_id,lead_id,context_key,kind,source_id,car_id,subject,created_at,updated_at) VALUES(%d,%d,%d,%d,%s,%s,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',$user,$branch,(int)($lead['owner_user_id']??0),(int)($lead['id']??0),$key,$kind,$source,$car,sanitize_text_field($subject),$original['created_at']??$now,$now));
		$id=(int)$wpdb->insert_id;if(false===$insert||!$id){$wpdb->query('ROLLBACK');return PortalAccess::error();}
		$thread=self::row($id,true);if(!$thread||!PortalAccess::can_thread($thread)){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',404);}
		if($original){
			$initial='message'===$kind?(string)$original['message']:PortalAccess::t('طلب تجربة قيادة: ','Test-drive request: ').$original['requested_date'].' '.$original['requested_time'];
			foreach(array('initial'=>array($initial,'customer',$user,$original['created_at']),'reply'=>array((string)($original['customer_reply']??''),'staff',0,$original['updated_at']?:$original['created_at'])) as $slot=>$value){
				if(''===trim($value[0])){continue;}$request=hash('sha256','legacy:'.$kind.':'.$source.':'.$slot);
				$ok=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('messages').'(thread_id,sender_id,sender_kind,sender_label,body,request_key,body_hash,created_at) VALUES(%d,%d,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=id',$id,$value[2],$value[1],'staff'===$value[1]?PortalAccess::t('فريق المعرض','Dealership team'):PortalAccess::t('العميل','Customer'),$value[0],$request,hash('sha256',$value[0]),$value[3]));
				if(false===$ok){$wpdb->query('ROLLBACK');return PortalAccess::error();}
			}
			$last=(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.PortalAccess::table('messages')." WHERE thread_id=%d AND visibility='public'",$id));
			$wpdb->query($wpdb->prepare('UPDATE '.PortalAccess::table('threads').' SET last_public_id=GREATEST(last_public_id,%d) WHERE id=%d',$last,$id));
		}
		if('general'===$kind&&!LiveChat::assign($id)){$wpdb->query('ROLLBACK');return PortalAccess::error();}
		if(!Transaction::commit(static fn()=>PortalAccess::audit('context_opened','portal_thread',$id))){return PortalAccess::error();}return $id;
	}
	public static function list(int $page=1,string $status='',int $owner=-1,int $branch=0):array {
		global $wpdb;if(!PortalAccess::ready()||!get_current_user_id()){return array();}$where='t.customer_id=%d';$args=array(get_current_user_id());
		if(PortalAccess::staff()){
			list($where,$args)=\AutoDealership\Security\BranchScope::predicate('t.scope_branch');
			if(!current_user_can('manage_options')&&!current_user_can('adc_view_branch_leads')){$where.=' AND t.scope_owner=%d';$args[]=get_current_user_id();}
		}
		if(in_array($status,array('staff','customer','closed'),true)){$where.=' AND t.status=%s';$args[]=$status;}
		if(PortalAccess::staff()&&$owner>=0){$where.=' AND t.scope_owner=%d';$args[]=$owner;}
		if(PortalAccess::staff()&&$branch){$where.=' AND t.scope_branch=%d';$args[]=$branch;}
		$args=array_merge(array(get_current_user_id(),get_current_user_id()),$args,array(max(0,min(10000,$page)-1)*20));
		$source='(SELECT raw.*,CASE WHEN raw.lead_id=0 THEN raw.branch_id ELSE COALESCE(lead.branch_id,0) END scope_branch,CASE WHEN raw.lead_id=0 THEN raw.owner_id ELSE COALESCE(lead.owner_user_id,0) END scope_owner FROM '.PortalAccess::table('threads').' raw LEFT JOIN '.Schema::table('leads').' lead ON lead.id=raw.lead_id)';
		return $wpdb->get_results($wpdb->prepare('SELECT t.*,EXISTS(SELECT 1 FROM '.PortalAccess::table('messages')." m WHERE m.thread_id=t.id AND m.visibility='public' AND m.sender_id<>%d AND m.id>COALESCE((SELECT r.last_id FROM ".PortalAccess::table('reads')." r WHERE r.thread_id=t.id AND r.user_id=%d),0)) unread FROM $source t WHERE $where ORDER BY t.updated_at DESC,t.id DESC LIMIT 21 OFFSET %d",$args),ARRAY_A)?:array();
	}
	public static function detail(int $id,int $after=0) {
		global $wpdb;$row=self::row($id);if(!PortalAccess::ready()||!$row||!PortalAccess::can_thread($row)){return PortalAccess::error('adc_portal_forbidden',404);}
		$is_customer=(int)$row['customer_id']===get_current_user_id();$visibility=$is_customer?" AND visibility='public'":'';
		$messages=$wpdb->get_results($wpdb->prepare('SELECT id,sender_kind,sender_label,sender_id,visibility,body,created_at FROM '.PortalAccess::table('messages')." WHERE thread_id=%d AND id>%d $visibility ORDER BY id ASC LIMIT 51",$id,max(0,$after)),ARRAY_A)?:array();$more=count($messages)>50;$messages=array_slice($messages,0,50);
		foreach($messages as &$message){$message['mine']=(int)$message['sender_id']===get_current_user_id();unset($message['sender_id']);}unset($message);
		$owner=get_userdata((int)$row['owner_id']);$label=$owner?self::public_label($owner,PortalAccess::t('الموظف المختص','Assigned adviser')):PortalAccess::t('فريق خدمة العملاء','Customer care team');
		return array('id'=>$id,'subject'=>self::subject($row),'kind'=>$row['kind'],'status'=>$row['status'],'revision'=>(int)$row['revision'],'owner'=>$label,'branch_id'=>(int)$row['branch_id'],'messages'=>$messages,'has_more'=>$more,'can_reply'=>$is_customer?PortalAccess::verified():PortalAccess::can_thread($row,true),'can_close'=>$is_customer?PortalAccess::verified():PortalAccess::cap('adc_close_conversations'),'can_assign'=>!$is_customer&&PortalAccess::cap('adc_assign_conversations'),'last_public_id'=>(int)$row['last_public_id']);
	}
	private static function public_label(\WP_User $user,string $fallback):string {$label=sanitize_text_field($user->display_name);return $label&&!str_contains($label,'@')?mb_substr($label,0,120):$fallback;}
	public static function subject(array $row):string {if('general'===$row['kind']){return in_array($row['subject'],array('محادثة مباشرة مع الفرع','Branch live chat'),true)?PortalAccess::t('محادثة مباشرة مع الفرع','Branch live chat'):$row['subject'];}return PortalAccess::t('booking'===$row['kind']?'تجربة قيادة':'طلب واستفسار','booking'===$row['kind']?'Test drive':'Enquiry').' #'.$row['source_id'];}
	/** Caller owns the existing request transaction; this method never starts or commits one. */
	public static function legacy_reply(array $lead,array $old,string $body,int $activity) {
		global $wpdb;if(!Schema::is_ready()||!(int)$old['user_id']||!get_userdata((int)$old['user_id'])){return 0;}
		if(!\AutoDealership\Security\BranchScope::can_manage_lead($lead)){return PortalAccess::error('adc_portal_forbidden',403);}
		$kind=$lead['legacy_request_type'];$source=(int)$old['id'];$now=current_time('mysql',true);$key=hash('sha256',$kind.':'.$source);
		$ok=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('threads').'(customer_id,branch_id,owner_id,lead_id,context_key,kind,source_id,car_id,subject,created_at,updated_at) VALUES(%d,%d,%d,%d,%s,%s,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',(int)$old['user_id'],(int)$lead['branch_id'],(int)$lead['owner_user_id'],(int)$lead['id'],$key,$kind,$source,(int)$old['car_id'],PortalAccess::t('booking'===$kind?'تجربة قيادة':'طلب واستفسار','booking'===$kind?'Test drive':'Enquiry').' #'.$source,$old['created_at'],$now));$thread=(int)$wpdb->insert_id;
		if(false===$ok||!$thread){return PortalAccess::error();}
		$initial='message'===$kind?(string)$old['message']:PortalAccess::t('طلب تجربة قيادة: ','Test-drive request: ').$old['requested_date'].' '.$old['requested_time'];
		foreach(array('initial'=>array($initial,(int)$old['user_id'],'customer',$old['created_at']),'reply'=>array((string)$old['customer_reply'],0,'staff',$old['updated_at']?:$old['created_at']),'activity:'.$activity=>array(trim($body),get_current_user_id(),'staff',$now)) as $slot=>$value){
			if(''===$value[0]){continue;}$request=hash('sha256','legacy:'.$kind.':'.$source.':'.$slot);$label='staff'===$value[2]?PortalAccess::t('فريق المعرض','Dealership team'):PortalAccess::t('العميل','Customer');
			$insert=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('messages').'(thread_id,sender_id,sender_kind,sender_label,body,request_key,body_hash,created_at) VALUES(%d,%d,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',$thread,$value[1],$value[2],$label,$value[0],$request,hash('sha256',$value[0]),$value[3]));if(false===$insert){return PortalAccess::error();}
		}
		$last=(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.PortalAccess::table('messages')." WHERE thread_id=%d AND visibility='public'",$thread));
		if(false===$wpdb->query($wpdb->prepare('UPDATE '.PortalAccess::table('threads')." SET last_public_id=%d,status='customer',updated_at=%s,revision=revision+1 WHERE id=%d",$last,$now,$thread))){return PortalAccess::error();}
		if(''===trim($body)){return 0;}if(!PortalAccess::notify((int)$old['user_id'],'message',$thread,'message:'.$last)){return PortalAccess::error();}$event=PortalMail::enqueue((int)$old['user_id'],$thread,$last);return is_wp_error($event)?$event:(is_array($event)?(int)$event['id']:0);
	}
	public static function send(int $id,string $body,string $uuid,bool $internal=false) {
		global $wpdb;$body=trim(sanitize_textarea_field($body));if(!PortalAccess::ready()||!$body||mb_strlen($body)>4000||!wp_is_uuid($uuid)||!Transaction::begin()){return PortalAccess::error('adc_portal_input');}
		$row=self::row($id,true);$actor=get_current_user_id();$customer=$row&&$actor===(int)$row['customer_id'];
		if(!$row||!PortalAccess::can_thread($row,true)||($internal&&$customer)){$wpdb->query('ROLLBACK');return PortalAccess::error($customer?'adc_portal_verified':'adc_portal_forbidden',403);}
		$key=hash('sha256','message:'.$actor.':'.$id.':'.$uuid);$hash=hash('sha256',($internal?'internal':'public').'|'.$body);$old=$wpdb->get_row($wpdb->prepare('SELECT id,body_hash FROM '.PortalAccess::table('messages').' WHERE request_key=%s',$key),ARRAY_A);
		if($old){$wpdb->query('ROLLBACK');return hash_equals($old['body_hash'],$hash)?(int)$old['id']:PortalAccess::error('adc_portal_stale',409);}
		if('closed'===$row['status']){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_closed',409);}
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('portal_message','account:'.$actor);if(is_wp_error($rate)){$wpdb->query('ROLLBACK');return $rate;}
		$now=current_time('mysql',true);$ok=$wpdb->insert(PortalAccess::table('messages'),array('thread_id'=>$id,'sender_id'=>$actor,'sender_kind'=>$customer?'customer':'staff','sender_label'=>self::public_label(wp_get_current_user(),$customer?PortalAccess::t('العميل','Customer'):PortalAccess::t('فريق المعرض','Dealership team')),'visibility'=>$internal?'internal':'public','body'=>$body,'request_key'=>$key,'body_hash'=>$hash,'created_at'=>$now));$message=(int)$wpdb->insert_id;
		if($ok&&!$internal){$ok=false!==$wpdb->query($wpdb->prepare('UPDATE '.PortalAccess::table('threads').' SET last_public_id=%d,status=%s,updated_at=%s,revision=revision+1 WHERE id=%d',$message,$customer?'staff':'customer',$now,$id));}
		$recipient=$customer?(int)$row['owner_id']:(int)$row['customer_id'];
		if($ok&&!$internal){$ok=PortalAccess::notify($recipient,'message',$id,'message:'.$message);}
		if($ok&&!$internal&&!$customer&&$row['source_id']&&in_array($row['kind'],array('message','booking'),true)){$legacy=$wpdb->prefix.'car_dealer_'.('message'===$row['kind']?'messages':'bookings');$ok=false!==$wpdb->update($legacy,array('customer_reply'=>$body,'updated_at'=>$now),array('id'=>$row['source_id']));}
		$event=$ok&&!$internal&&$recipient?PortalMail::enqueue($recipient,$id,$message):true;
		if(!$ok||is_wp_error($event)||!Transaction::commit(static fn()=>PortalAccess::audit('message_sent','portal_thread',$id,array('message_id'=>$message,'internal'=>$internal)))){$wpdb->query('ROLLBACK');return PortalAccess::error();}
		if(!$internal&&is_array($event)){PortalMail::dispatch_after_commit((int)$event['id']);}return $message;
	}
	public static function change(int $id,string $operation,int $revision,int $owner=0,int $branch=0) {
		global $wpdb;if(!PortalAccess::ready()||!Transaction::begin()){return PortalAccess::error();}$row=self::row($id,true);$customer=$row&&(int)$row['customer_id']===get_current_user_id();
		if(!$row||!PortalAccess::can_thread($row)||($customer&&!PortalAccess::verified())){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',404);}
		if((int)$row['revision']!==$revision){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_stale',409);}
		$changes=array('revision'=>$revision+1,'updated_at'=>current_time('mysql',true));
		if('assign'===$operation){
			$user=get_userdata($owner);if($customer||!PortalAccess::cap('adc_assign_conversations')||($owner&&(!$user||!user_can($user,'adc_reply_conversations')||!\AutoDealership\Security\BranchScope::allows((int)$row['branch_id'],$owner)))){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',403);}$changes['owner_id']=$owner;
		}elseif('route'===$operation){
			if(!current_user_can('manage_options')||$row['lead_id']||'general'!==$row['kind']||!$branch||!\AutoDealership\Security\BranchScope::is_active($branch)){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',403);}$changes['branch_id']=$branch;$changes['owner_id']=0;
		}elseif(in_array($operation,array('close','reopen'),true)){
			if(!$customer&&!PortalAccess::cap('adc_close_conversations')){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',403);}
			if('reopen'===$operation){$rate=\AutoDealership\Security\PublicRequestGuard::consume('portal_message','account:'.get_current_user_id());if(is_wp_error($rate)){$wpdb->query('ROLLBACK');return $rate;}}$changes['status']='close'===$operation?'closed':($customer?'staff':'customer');
		}else{$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_input');}
		$ok=true;
		if('assign'===$operation&&$row['lead_id']){$lead=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.Schema::table('leads').' WHERE id=%d FOR UPDATE',$row['lead_id']),ARRAY_A);if(!$lead||!\AutoDealership\Security\BranchScope::can_manage_lead($lead)){$wpdb->query('ROLLBACK');return PortalAccess::error('adc_portal_forbidden',403);}$ok=false!==$wpdb->update(Schema::table('leads'),array('owner_user_id'=>$owner),array('id'=>$row['lead_id']));}
		if($ok){$ok=false!==$wpdb->update(PortalAccess::table('threads'),$changes,array('id'=>$id));}
		if(!$ok||!Transaction::commit(static fn()=>PortalAccess::audit('conversation_'.$operation,'portal_thread',$id,array('owner_id'=>$changes['owner_id']??$row['owner_id'],'branch_id'=>$changes['branch_id']??$row['branch_id'])))){$wpdb->query('ROLLBACK');return PortalAccess::error();}return true;
	}
	public static function read(int $id,int $last) {
		global $wpdb;$row=self::row($id);if(!$row||!PortalAccess::can_thread($row)){return PortalAccess::error('adc_portal_forbidden',404);}
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('public_read');if(is_wp_error($rate)){return $rate;}
		$visible=(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.PortalAccess::table('messages')." WHERE thread_id=%d AND visibility='public' AND id<=%d",$id,$last));
		$ok=false!==$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('reads').'(thread_id,user_id,last_id,updated_at) VALUES(%d,%d,%d,%s) ON DUPLICATE KEY UPDATE last_id=GREATEST(last_id,VALUES(last_id)),updated_at=VALUES(updated_at)',$id,get_current_user_id(),$visible,current_time('mysql',true)));
		if($ok){$ok=false!==$wpdb->query($wpdb->prepare('UPDATE '.PortalAccess::table('notifications').' n JOIN '.PortalAccess::table('messages')." m ON m.thread_id=n.object_id AND n.event_key=SHA2(CONCAT('message:',m.id),256) SET n.read_at=%s WHERE n.user_id=%d AND n.kind='message' AND n.object_id=%d AND n.read_at IS NULL AND m.visibility='public' AND m.id<=%d",current_time('mysql',true),get_current_user_id(),$id,$visible));}return $ok;
	}
}
