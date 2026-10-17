<?php
namespace AutoDealership\Portal;
use AutoDealership\Operations\OutboxService;
defined('ABSPATH') || exit;

final class PortalMail {
	public static function enqueue(int $user,int $thread,int $message) {
		if(!PortalAccess::ready()||!PortalAccess::settings()['mail']){return true;}
		return OutboxService::enqueue('portal.message',array('subject_type'=>'portal_message','subject_id'=>$message,'actor_user_id'=>$user),'portal-message:'.$message.':'.$user);
	}
	public static function dispatch_after_commit(int $event,string $type='portal.message'):void {
		if('cli'===PHP_SAPI){return;}add_action('shutdown',static function()use($event,$type):void{if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}$previous=ignore_user_abort(true);try{OutboxService::process_due(1,array($type),array($event));}finally{ignore_user_abort($previous);}},20);
	}
	public static function review(array $payload) {
		global $wpdb;if(!PortalAccess::ready()||!PortalAccess::settings()['mail']){return true;}
		$action=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.PortalAccess::table('review_actions').' WHERE id=%d',(int)($payload['subject_id']??0)),ARRAY_A);$row=$action?Reviews::row((int)$action['review_id']):null;$user=$row?get_userdata((int)$row['user_id']):false;
		if(!$user||!$row['consent']||(int)$row['current_version']!==(int)$action['version_id']){return true;}
		if((int)$action['id']!==(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM '.PortalAccess::table('review_actions').' WHERE review_id=%d',$row['id']))){return true;}
		$lang=\AutoDealership\Leads\NewsletterService::language((string)get_user_meta($user->ID,'adc_preferred_language',true));$ar='ar'===$lang;$title=$ar?'تم تحديث حالة تقييمك':'Your review status has changed';$body='<p>'.esc_html($ar?'يمكنك الاطلاع على قرار المراجعة ومتابعة تقييمك بأمان من حسابك.':'You can read the moderation decision and manage your review securely from your account.').'</p>';
		$html=\AutoDealership\Accounts\BrandedMail::render($title,$body,$ar?'عرض تقييمي':'View my review',PortalAccess::url('reviews',array('lang'=>$lang)),'',$lang);return \AutoDealership\Accounts\BrandedMail::send($user->user_email,$title,$html)?true:new \WP_Error('adc_portal_mail');
	}
	public static function send(array $payload) {
		global $wpdb;if(!PortalAccess::ready()||!PortalAccess::settings()['mail']){return true;}$message=$wpdb->get_row($wpdb->prepare('SELECT id,thread_id,sender_id,visibility FROM '.PortalAccess::table('messages').' WHERE id=%d',(int)($payload['subject_id']??0)),ARRAY_A);$id=(int)($payload['actor_user_id']??0);$user=get_userdata($id);
		if(!$message||'public'!==$message['visibility']||!$user){return true;}$row=Conversations::row((int)$message['thread_id']);if(!$row){return true;}
		if($id!==(int)$row['customer_id']&&($id!==(int)$row['owner_id']||!user_can($id,'adc_view_conversations')||!\AutoDealership\Security\BranchScope::allows((int)$row['branch_id'],$id))){return true;}
		$lang=\AutoDealership\Leads\NewsletterService::language((string)get_user_meta($id,'adc_preferred_language',true));$ar='ar'===$lang;
		// Once per minute per recipient/thread. The unread in-account notification remains canonical.
		$lock='adc_portal_mail_'.md5(DB_NAME.$wpdb->prefix.$id.':'.$row['id']);if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))){return new \WP_Error('adc_portal_mail_busy');}try{
		$key='adc_portal_mail_'.hash('sha256',$id.':'.$row['id']);if(get_transient($key)){return true;}
		$url=$id===(int)$row['customer_id']?PortalAccess::url('messages',array('thread'=>$row['id'],'lang'=>$lang)):admin_url('admin.php?page=adc-conversations&thread='.$row['id']);
		$title=$ar?'لديك رسالة جديدة من المعرض':'You have a new dealership message';$body='<p>'.esc_html($ar?'توجد رسالة جديدة في محادثتك. افتح حسابك للاطلاع عليها والرد بأمان.':'There is a new message in your conversation. Open your account to read and reply securely.').'</p>';
		$html=\AutoDealership\Accounts\BrandedMail::render($title,$body,$ar?'عرض المحادثة':'View conversation',$url,'',$lang);
		$ok=\AutoDealership\Accounts\BrandedMail::send($user->user_email,$title,$html);if($ok){set_transient($key,1,MINUTE_IN_SECONDS);}return $ok?true:new \WP_Error('adc_portal_mail');
		}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
}
