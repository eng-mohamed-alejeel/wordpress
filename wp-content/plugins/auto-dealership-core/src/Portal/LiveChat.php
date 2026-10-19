<?php
namespace AutoDealership\Portal;
use AutoDealership\Security\BranchScope;
use AutoDealership\Database\Schema;
defined('ABSPATH') || exit;

/** Short-lived authenticated presence; signing in enables adviser availability. */
final class LiveChat {
	public static function boot():void {
		StaffPresence::boot();
		add_action('wp_ajax_adc_live_chat',array(self::class,'endpoint'));
		add_action('wp_ajax_adc_live_status',array(self::class,'status_endpoint'));
		add_action('wp_ajax_nopriv_adc_chat_availability',array(self::class,'availability'));
		add_action('wp_ajax_adc_chat_availability',array(self::class,'availability'));
		add_action('wp_footer',array(self::class,'widget'),15);
	}
	public static function eligible(int $user,int $branch):bool {
		return $user>0 && user_can($user,'adc_reply_conversations') && user_can($user,'adc_view_conversations') && BranchScope::allows($branch,$user) && get_userdata($user);
	}
	public static function online(int $user):bool {
		return StaffPresence::available($user);
	}
	public static function available(int $branch):array {
		global $wpdb;if(!PortalAccess::ready()||!BranchScope::is_active($branch)){return array();}
		$ids=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT user_id FROM '.PortalAccess::table('presence').' WHERE available=1 AND seen_at>%d ORDER BY user_id LIMIT 200',time()-40));$result=array();
		foreach($ids as $id){$id=(int)$id;if(self::eligible($id,$branch)&&StaffPresence::available($id)&&(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.PortalAccess::table('threads')." WHERE owner_id=%d AND status<>'closed'",$id))<8){$result[]=$id;}}return $result;
	}
	public static function availability():void {
		nocache_headers();$rate=\AutoDealership\Security\PublicRequestGuard::consume('public_read');if(is_wp_error($rate)){wp_send_json_error(array(),429);}$branches=array();if(PortalAccess::ready()){foreach(PortalAccess::branches() as $branch){$branches[]=array('id'=>(int)$branch['id'],'name'=>$branch['name'],'available'=>count(self::available((int)$branch['id'])));}}
		wp_send_json_success(array('branches'=>$branches));
	}
	public static function presence(int $thread):array {
		global $wpdb;$row=Conversations::row($thread);if(!$row||!PortalAccess::can_thread($row)){return array('online'=>false,'typing'=>false);}
		$customer=(int)$row['customer_id']===get_current_user_id();$peer=$customer?(int)$row['owner_id']:(int)$row['customer_id'];
		if(!$peer||($customer&&(!self::eligible($peer,(int)$row['branch_id'])||!StaffPresence::available($peer,false)))){return array('online'=>false,'typing'=>false);}
		$live=$wpdb->get_row($wpdb->prepare('SELECT MAX(seen_at) seen_at,MAX(CASE WHEN thread_id=%d THEN CASE WHEN typing_until<1000000000000 THEN typing_until*1000 ELSE typing_until END ELSE 0 END) typing_until FROM '.PortalAccess::table('presence').' WHERE user_id=%d',$thread,$peer),ARRAY_A);
		$online=(int)($live['seen_at']??0)>time()-40;$remaining=$online&&'closed'!==$row['status']?max(0,(int)($live['typing_until']??0)-(int)floor(microtime(true)*1000)):0;return array('online'=>$online,'typing'=>$remaining>0,'typing_ms'=>min(2000,$remaining));
	}
	/** Ordered, expiring state only. No message draft is accepted or stored here. */
	public static function signal(int $thread,bool $typing,bool $visible,string $tab,int $sequence,int $ttl=2000) {
		global $wpdb;$user=get_current_user_id();$row=$thread?Conversations::row($thread):null;
		if(!PortalAccess::ready()||!$user||!$row||!PortalAccess::can_thread($row)||!preg_match('/\A[a-f0-9-]{36}\z/i',$tab)||$sequence<1||$sequence>1000000000){return PortalAccess::error('adc_portal_forbidden',403);}
		$active=$typing&&$visible&&'closed'!==$row['status']&&PortalAccess::can_thread($row,true);$until=$active?(int)floor(microtime(true)*1000)+max(1,min(2000,$ttl)):0;
		$key=hash('sha256',wp_get_session_token().':typing:'.$tab);$available=false;
		$ok=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('presence').'(user_id,session_key,thread_id,available,typing_until,typing_seq,seen_at) VALUES(%d,%s,%d,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE typing_until=IF(VALUES(typing_seq)>typing_seq,VALUES(typing_until),typing_until),thread_id=IF(VALUES(typing_seq)>typing_seq,VALUES(thread_id),thread_id),available=IF(VALUES(typing_seq)>typing_seq,VALUES(available),available),seen_at=IF(VALUES(typing_seq)>typing_seq,VALUES(seen_at),seen_at),typing_seq=GREATEST(typing_seq,VALUES(typing_seq))',$user,$key,$thread,(int)$available,$until,$sequence,$visible?time():0));
		return false===$ok?PortalAccess::error():true;
	}
	/** Dedicated lightweight status endpoint: no inbox, messages, assignment, or activity history. */
	public static function status_endpoint():void {
		check_ajax_referer('adc_live_chat','nonce');PortalAccess::request_scope('personal'!==($_REQUEST['portal_scope']??'')&&PortalAccess::staff());nocache_headers();if(!is_user_logged_in()||!PortalAccess::ready()){wp_send_json_error(array(),403);}
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('portal_presence','account:'.get_current_user_id());if(is_wp_error($rate)){wp_send_json_error(array('message'=>$rate->get_error_message()),429);}
		$id=isset($_REQUEST['thread'])&&is_scalar($_REQUEST['thread'])?absint($_REQUEST['thread']):0;$row=$id?Conversations::row($id):null;
		if(!$row||!PortalAccess::can_thread($row)){wp_send_json_error(array(),404);}
		if('POST'===($_SERVER['REQUEST_METHOD']??'')){
			if('typing'!==Portal::field('operation')){wp_send_json_error(array(),400);}
			$result=self::signal($id,'1'===Portal::field('typing'),'1'===Portal::field('visible'),Portal::field('tab'),absint(Portal::field('seq')),absint(Portal::field('ttl')));
			if(is_wp_error($result)){wp_send_json_error(array(),(int)($result->get_error_data()['status']??400));}wp_send_json_success(array('accepted'=>true));
		}
		if('GET'!==($_SERVER['REQUEST_METHOD']??'')){wp_send_json_error(array(),405);}wp_send_json_success(self::presence($id));
	}
	public static function beat(int $thread,bool $typing,bool $visible,string $tab) {
		global $wpdb;$user=get_current_user_id();if(!PortalAccess::ready()||!$user||!preg_match('/\A[a-f0-9-]{36}\z/i',$tab)){return PortalAccess::error('adc_portal_forbidden',403);}
		$row=$thread?Conversations::row($thread):null;if($thread&&(!$row||!PortalAccess::can_thread($row))){return PortalAccess::error('adc_portal_forbidden',404);}
		$staff=PortalAccess::staff();$available=false;
		$lease=StaffPresence::heartbeat();if(is_wp_error($lease)){return $lease;}
		$key=hash('sha256',wp_get_session_token().':'.$tab);$typing=$typing&&$visible&&$row&&'closed'!==$row['status']&&PortalAccess::can_thread($row,true);
		$ok=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('presence').'(user_id,session_key,thread_id,available,typing_until,seen_at) VALUES(%d,%s,%d,%d,%d,%d) ON DUPLICATE KEY UPDATE thread_id=VALUES(thread_id),available=VALUES(available),typing_until=VALUES(typing_until),seen_at=VALUES(seen_at)',$user,$key,$thread,(int)$available,$typing?time()+6:0,($visible||$staff)?time():0));
		if(false===$ok){return PortalAccess::error();}$wpdb->query($wpdb->prepare('DELETE FROM '.PortalAccess::table('presence').' WHERE seen_at<%d LIMIT 50',time()-3600));return true;
	}
	public static function queue():void {
		global $wpdb;list($scope,$args)=BranchScope::predicate('branch_id');$sql='SELECT id FROM '.PortalAccess::table('threads')." WHERE owner_id=0 AND lead_id=0 AND status='staff' AND $scope ORDER BY id LIMIT 5";$ids=$wpdb->get_col($args?$wpdb->prepare($sql,$args):$sql);foreach($ids as $id){if(!\AutoDealership\Database\Transaction::begin()){break;}if(!self::assign((int)$id)){$wpdb->query('ROLLBACK');continue;}\AutoDealership\Database\Transaction::commit(static fn()=>PortalAccess::audit('queue_routed','portal_thread',(int)$id));}
	}
	/** Called inside the thread transaction. A private, unavailable InnoDB row serializes each adviser independently of WordPress table engines. */
	public static function assign(int $thread):bool {
		global $wpdb;$row=Conversations::row($thread,true);if($wpdb->last_error||!$row){return false;}if($row['owner_id']||$row['lead_id']||'closed'===$row['status']){return true;}
		$candidates=self::available((int)$row['branch_id']);$loads=array();foreach($candidates as $candidate){if($candidate===(int)$row['customer_id']){continue;}$loads[$candidate]=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.PortalAccess::table('threads')." WHERE owner_id=%d AND status<>'closed'",$candidate));}asort($loads,SORT_NUMERIC);
		foreach($loads as $candidate=>$load){if($load>=8){continue;}$locked=$wpdb->query($wpdb->prepare('INSERT INTO '.PortalAccess::table('presence').'(user_id,session_key,thread_id,available,typing_until,seen_at) VALUES(%d,%s,0,0,0,%d) ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at)',$candidate,hash('sha256','portal-routing-mutex'),time()));if(false===$locked){return false;}if(!self::online($candidate)||!self::eligible($candidate,(int)$row['branch_id'])){continue;}$owned=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.PortalAccess::table('threads')." WHERE owner_id=%d AND status<>'closed' LIMIT 8 FOR UPDATE",$candidate));if($wpdb->last_error){return false;}if(count($owned)>=8){continue;}
			$changed=$wpdb->query($wpdb->prepare('UPDATE '.PortalAccess::table('threads').' SET owner_id=%d,revision=revision+1 WHERE id=%d AND owner_id=0',$candidate,$thread));if(false===$changed){return false;}if(1===$changed&&!PortalAccess::notify($candidate,'message',$thread,'chat-assigned:'.$thread.':'.$candidate)){return false;}break;
		}
		return true;
	}
	public static function endpoint():void {
		if('POST'!==($_SERVER['REQUEST_METHOD']??'')){wp_send_json_error(array(),405);}check_ajax_referer('adc_live_chat','nonce');PortalAccess::request_scope('personal'!==($_REQUEST['portal_scope']??'')&&PortalAccess::staff());nocache_headers();
		if(!PortalAccess::ready()){wp_send_json_error(array('message'=>PortalAccess::t('المحادثات غير متاحة حاليًا.','Chat is currently unavailable.')),503);}
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('public_read');if(is_wp_error($rate)){wp_send_json_error(array('message'=>$rate->get_error_message()),429);}
		$operation=Portal::field('operation');$thread=absint(Portal::field('thread'));$result=true;
		if('available'===$operation){$result=StaffPresence::set('1'===Portal::field('available'));}
		elseif('start'===$operation){$result=Conversations::ensure('general',0,'ar'===PortalAccess::language()?'محادثة مباشرة مع الفرع':'Branch live chat',absint(Portal::field('branch')));if(!is_wp_error($result)){$thread=(int)$result;$row=Conversations::row($thread);if($row&&'closed'===$row['status']){$result=Conversations::change($thread,'reopen',(int)$row['revision']);}}}
		elseif('beat'!==$operation){$result=PortalAccess::error('adc_portal_input');}
		if(!is_wp_error($result)){$result=self::beat($thread,'1'===Portal::field('typing'),'1'===Portal::field('visible'),Portal::field('tab'));}
		if(is_wp_error($result)){wp_send_json_error(array('message'=>$result->get_error_message()),(int)($result->get_error_data()['status']??400));}
		$inbox=array();if(PortalAccess::staff()&&!PortalAccess::personal_request()){if(StaffPresence::accepting()){self::queue();}foreach(Conversations::list(1,'',get_current_user_id()) as $item){if(!empty($item['unread'])){$inbox[]=array('id'=>(int)$item['id'],'last'=>(int)$item['last_public_id'],'url'=>admin_url('admin.php?page=adc-conversations&thread='.(int)$item['id']));}if(count($inbox)>=5){break;}}}
		wp_send_json_success(array('available'=>StaffPresence::accepting(),'canReceive'=>StaffPresence::eligible(get_current_user_id()),'thread'=>$thread,'presence'=>$thread?self::presence($thread):null,'inbox'=>$inbox,'redirect'=>$thread?PortalAccess::url('messages',array('thread'=>$thread)):null));
	}
	public static function assets():void {
		if(!PortalAccess::ready()){return;}
		wp_enqueue_style('adc-live-chat',plugins_url('assets/css/customer-presence.css',ADC_FILE),array(),ADC_VERSION);
		if(StaffPresence::role(get_current_user_id())){wp_enqueue_style('adc-staff-presence',plugins_url('assets/css/staff-presence.css',ADC_FILE),array('adc-live-chat'),ADC_VERSION);}
		wp_enqueue_script('adc-live-chat',plugins_url('assets/js/customer-presence.js',ADC_FILE),array(),ADC_VERSION,true);
		wp_enqueue_script('adc-chat-typing',plugins_url('assets/js/customer-typing.js',ADC_FILE),array('adc-live-chat'),ADC_VERSION,true);
		wp_localize_script('adc-live-chat','adcChat',array('ajax'=>admin_url('admin-ajax.php'),'post'=>admin_url('admin-post.php'),'nonce'=>is_user_logged_in()?wp_create_nonce('adc_live_chat'):'','pollNonce'=>is_user_logged_in()?wp_create_nonce('adc_portal_poll'):'','sendNonce'=>is_user_logged_in()?wp_create_nonce('adc_portal'):'','lang'=>PortalAccess::language(),'logged'=>is_user_logged_in(),'scope'=>PortalAccess::personal_request()?'personal':'staff','staff'=>PortalAccess::staff()&&!PortalAccess::personal_request(),'available'=>StaffPresence::accepting(),'canReceive'=>StaffPresence::eligible(get_current_user_id()),'account'=>PortalAccess::url('messages')));
	}
	public static function widget():void {
		if(!PortalAccess::ready()||is_admin()||PortalAccess::staff()||('dashboard'===($_GET['cd_account']??''))){return;}
		echo '<aside class="adc-chat" dir="'.('ar'===PortalAccess::language()?'rtl':'ltr').'" aria-label="'.esc_attr(PortalAccess::t('تواصل مع المعرض','Chat with the dealership')).'"><button type="button" class="adc-chat-launch" aria-expanded="false" aria-controls="adc-chat-panel"><span class="adc-chat-dot" aria-hidden="true"></span>'.esc_html(PortalAccess::t('تحدث مع فريقنا','Talk to our team')).'</button><section id="adc-chat-panel" class="adc-chat-panel" hidden><header><h2>'.esc_html(PortalAccess::t('كيف نساعدك؟','How can we help?')).'</h2><button type="button" data-chat-close aria-label="'.esc_attr(PortalAccess::t('إغلاق','Close')).'">×</button></header><p>'.esc_html(PortalAccess::t('اختر الفرع وسنوصلك بأحد موظفيه المتاحين.','Choose a branch and we will connect you with an available adviser.')).'</p><label>'.esc_html(PortalAccess::t('الفرع','Branch')).'<select data-chat-branch>';foreach(PortalAccess::branches() as $branch){echo '<option value="'.(int)$branch['id'].'">'.esc_html($branch['name']).'</option>';}echo '</select></label><p data-chat-status role="status"></p><button type="button" data-chat-start>'.esc_html(PortalAccess::t('بدء المحادثة','Start conversation')).'</button><a href="'.esc_url(PortalAccess::url('messages')).'">'.esc_html(PortalAccess::t('محادثاتي السابقة','My conversations')).'</a></section></aside>';
	}
}
