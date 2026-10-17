<?php
namespace AutoDealership\Portal;
use AutoDealership\Database\Schema;
use AutoDealership\Security\BranchScope;
defined('ABSPATH') || exit;

final class PortalAccess {
	public const CAPS=array('adc_view_conversations','adc_reply_conversations','adc_close_conversations','adc_assign_conversations','adc_moderate_reviews','adc_publish_reviews');
	public static function language():string {
		$action=$_REQUEST['action']??'';$value=$_REQUEST['lang']??'';
		if(is_string($action)&&in_array($action,array('adc_portal','adc_portal_poll'),true)&&is_string($value)&&in_array($value,array('ar','en'),true)){return $value;}
		return \AutoDealership\Core\Localization::language();
	}
	public static function t(string $ar,string $en):string { return 'en'===self::language()?$en:$ar; }
	public static function error(string $code='adc_portal_unavailable',int $status=400):\WP_Error {
		$messages=array('adc_portal_forbidden'=>['هذا العنصر غير متاح لحسابك.','This item is not available to your account.'],'adc_portal_verified'=>['أكد ملكية بريدك من قسم بيانات الحساب لإكمال هذا الإجراء.','Confirm email ownership in Account details to continue.'],'adc_portal_stale'=>['تغيّرت البيانات. حدّث الصفحة قبل المحاولة مجددًا.','The information changed. Refresh before trying again.'],'adc_portal_closed'=>['المحادثة مغلقة. اطلب إعادة فتحها للمتابعة.','This conversation is closed. Reopen it to continue.'],'adc_portal_input'=>['تحقق من الحقول والموافقة المطلوبة.','Check the fields and required consent.'],'adc_portal_duplicate'=>['يوجد تقييم لهذه التجربة. افتح تقييمك الحالي لتعديله.','You already reviewed this experience. Open your existing review to edit it.']);
		return new \WP_Error($code,self::t(...($messages[$code]??['تعذر تنفيذ الطلب الآن. أعد المحاولة لاحقًا.','Unable to process the request now. Please retry later.'])),array('status'=>$status));
	}
	public static function table(string $name):string { return Schema::table('portal_'.$name); }
	public static function settings():array { return array_replace(array('enabled'=>true,'mail'=>false),array_intersect_key((array)get_option('adc_portal_settings',array()),array('enabled'=>1,'mail'=>1))); }
	public static function save_settings(bool $enabled,bool $mail) {
		if(!current_user_can('manage_options')||!\AutoDealership\Database\Transaction::begin()){return self::error('adc_portal_forbidden',403);}
		$before=get_option('adc_portal_settings',array());$value=array('enabled'=>$enabled,'mail'=>$mail);update_option('adc_portal_settings',$value,false);
		if(!\AutoDealership\Database\Transaction::commit(static fn()=>self::audit('settings_saved','portal_settings',0,$value))){wp_cache_delete('adc_portal_settings','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');update_option('adc_portal_settings',$before,false);return self::error();}Reviews::purge();return true;
	}
	public static function ready():bool { return Schema::is_ready() && self::settings()['enabled']; }
	public static function verified():bool { $user=wp_get_current_user();return $user->ID && \AutoDealership\Accounts\EmailVerification::current_account_verified_for($user->user_email); }
	public static function staff():bool { return current_user_can('manage_options')||current_user_can('adc_view_conversations'); }
	public static function cap(string $cap):bool { return current_user_can('manage_options')||current_user_can($cap); }
	public static function branch(int $branch):bool { return BranchScope::allows($branch); }
	public static function can_thread(array $row,bool $write=false):bool {
		$id=get_current_user_id();if(!$id){return false;}
		if($id===(int)$row['customer_id']){return !$write||self::verified();}
		if(!self::staff()||!self::branch((int)$row['branch_id'])||($write&&!self::cap('adc_reply_conversations'))){return false;}
		return current_user_can('manage_options')||((int)$row['owner_id']===$id)||current_user_can('adc_view_branch_leads');
	}
	public static function role_caps():array {return array('dealership_sales'=>array('adc_view_conversations','adc_reply_conversations','adc_close_conversations'),'dealership_sales_manager'=>array('adc_view_conversations','adc_reply_conversations','adc_close_conversations','adc_assign_conversations'),'dealership_customer_service'=>array('adc_view_conversations','adc_reply_conversations','adc_close_conversations'),'dealership_general_manager'=>self::CAPS,'dealership_marketing'=>array('adc_moderate_reviews','adc_publish_reviews'));}
	public static function migrate_permissions():void {
		if(get_option('adc_portal_roles')==='2'){return;}$definitions=(array)get_option('adc_role_definitions',array());
		foreach(self::role_caps() as $slug=>$caps){$role=get_role($slug);if(!$role||isset($definitions[$slug])){continue;}foreach($caps as $cap){if(!array_key_exists($cap,$role->capabilities)){$role->add_cap($cap);}}}
		$admin=get_role('administrator');if($admin){foreach(self::CAPS as $cap){$admin->add_cap($cap);}}update_option('adc_portal_roles','2',false);
	}
	public static function branches():array {global $wpdb;return $wpdb->get_results('SELECT id,name FROM '.Schema::table('branches').' WHERE active=1 ORDER BY name',ARRAY_A)?:array(array('id'=>0,'name'=>self::t('خدمة العملاء','Customer care')));}
	public static function label(string $key):string {$labels=array('staff'=>['بانتظار الموظف','Awaiting staff'],'customer'=>['بانتظار ردك','Awaiting customer'],'closed'=>['مغلقة','Closed'],'pending'=>['بانتظار المراجعة','Under review'],'published'=>['منشور','Published'],'rejected'=>['لم يُعتمد','Not approved'],'hidden'=>['مخفي','Hidden'],'withdrawn'=>['سُحبت موافقة النشر','Publication consent withdrawn']);return isset($labels[$key])?self::t(...$labels[$key]):$key;}
	public static function notify(int $user,string $kind,int $object,string $event):bool {
		if(!$user){return true;}global $wpdb;$key=hash('sha256',$event);
		return false!==$wpdb->query($wpdb->prepare('INSERT INTO '.self::table('notifications').'(user_id,kind,object_id,event_key,created_at) VALUES(%d,%s,%d,%s,%s) ON DUPLICATE KEY UPDATE id=id',$user,$kind,$object,$key,current_time('mysql',true)));
	}
	public static function audit(string $event,string $kind,int $id,array $data=array()):bool { return \AutoDealership\Audit\AuditLog::record('portal.'.$event,$kind,$id,'Customer portal',null,$data); }
	public static function url(string $section='overview',array $args=array()):string {return add_query_arg(array_merge(array('cd_account'=>'dashboard','portal'=>$section,'lang'=>self::language()),$args),home_url('/'));}
	public static function owns_customer(int $id):bool { return $id>0 && $id===get_current_user_id(); }
}
