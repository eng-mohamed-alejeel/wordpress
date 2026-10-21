<?php
namespace AutoDealership\Portal;
defined('ABSPATH') || exit;

final class Portal {
	public static function boot():void {
		add_action('template_redirect',array(AccountDashboard::class,'validate_request'),4);
		LiveChat::boot();CustomerJourney::boot();PortalOperations::boot();
		add_action('wp_enqueue_scripts',array(LiveChat::class,'assets'));
		add_action('admin_enqueue_scripts',static function(){if(PortalAccess::staff()){LiveChat::assets();}});
		add_action('init',array(PortalAccess::class,'migrate_permissions'),35);
		add_action('admin_init',array(PortalMigration::class,'batch'),40);
		add_action('admin_post_adc_portal',array(self::class,'submit'));
		add_action('admin_post_nopriv_adc_portal',static function(){wp_die('Forbidden','',array('response'=>403));});
		add_action('wp_ajax_adc_portal_poll',array(self::class,'poll'));
		add_action('wp_enqueue_scripts',array(self::class,'assets'));
		add_action('admin_enqueue_scripts',static function(){if(in_array($_GET['page']??'',array('adc-conversations','adc-customer-reviews','adc-portal-operations'),true)){self::assets();}});
		\AutoDealership\Operations\OutboxService::register_handler('portal.message',array(PortalMail::class,'send'));
		\AutoDealership\Operations\OutboxService::register_handler('portal.review',array(PortalMail::class,'review'));
		add_filter('adc_site_content_review_rows',array(Reviews::class,'content_rows'),10,2);
		add_filter('wp_privacy_personal_data_exporters',array(PortalPrivacy::class,'exporter'));
		add_filter('wp_privacy_personal_data_erasers',array(PortalPrivacy::class,'eraser'));
	}
	public static function assets():void {
		if(!isset($_GET['cd_account'])&&!in_array($_GET['page']??'',array('adc-conversations','adc-customer-reviews','adc-portal-operations'),true)){return;}
		wp_enqueue_style('adc-customer-portal',plugins_url('assets/css/customer-portal.css',ADC_FILE),array(),ADC_VERSION);
		if('dashboard'===($_GET['cd_account']??'')){
			wp_enqueue_style('adc-account-work',plugins_url('assets/css/account-work.css',ADC_FILE),array('adc-customer-portal'),(string)filemtime(ADC_PATH.'assets/css/account-work.css'));
		}
		wp_enqueue_script('adc-customer-portal',plugins_url('assets/js/customer-portal.js',ADC_FILE),array(),ADC_VERSION,true);
		wp_localize_script('adc-customer-portal','adcPortal',array('ajax'=>admin_url('admin-ajax.php'),'action'=>admin_url('admin-post.php'),'nonce'=>wp_create_nonce('adc_portal'),'pollNonce'=>wp_create_nonce('adc_portal_poll'),'lang'=>PortalAccess::language(),'saved'=>PortalAccess::t('تم الحفظ بنجاح.','Saved successfully.'),'failed'=>PortalAccess::t('تعذر الاتصال. احتفظنا بالنص لتتمكن من المحاولة مجددًا.','Connection failed. Your text is preserved so you can retry.'),'more'=>PortalAccess::t('تحميل المزيد من الرسائل','Load more messages'),'new'=>PortalAccess::t('رسائل جديدة','New messages')));
	}
	public static function field(string $key):string {return isset($_POST[$key])&&is_string($_POST[$key])?wp_unslash($_POST[$key]):'';}
	public static function submit():void {
		if(!is_user_logged_in()){wp_die('Forbidden','',array('response'=>403));}check_admin_referer('adc_portal');PortalAccess::request_scope('1'===self::field('admin')&&(PortalAccess::staff()||PortalAccess::cap('adc_moderate_reviews')));$operation=self::field('operation');$id=absint(self::field('id'));$section=self::field('section');$result=null;
		if(PortalAccess::personal_request()&&!in_array($operation,array('context','review_context','send','read','review','withdraw','ownership','close','reopen'),true)){$result=PortalAccess::error('adc_portal_forbidden',403);}
		elseif(!PortalAccess::ready()&&'settings'!==$operation){$result=PortalAccess::error();}
		elseif('context'===$operation){$result=Conversations::ensure(self::field('kind'),absint(self::field('source')),self::field('subject'),absint(self::field('branch')));$section='messages';if(!is_wp_error($result)){$id=(int)$result;}}
		elseif('review_context'===$operation){$result=Conversations::ensure(self::field('kind'),absint(self::field('source')));$section='reviews';if(!is_wp_error($result)){$id=(int)$result;if(!Reviews::context($id)){$result=PortalAccess::error('adc_portal_input');}}}
		elseif('send'===$operation){$result=Conversations::send($id,self::field('body'),self::field('request_key'),'1'===self::field('internal'));$section='messages';}
		elseif(in_array($operation,array('assign','close','reopen','route'),true)){$result=Conversations::change($id,$operation,absint(self::field('revision')),absint(self::field('owner')),absint(self::field('branch')));$section='messages';}
		elseif('read'===$operation){$result=Conversations::read($id,absint(self::field('last')));}
		elseif('review'===$operation){$result=Reviews::submit(array('name'=>self::field('name'),'body'=>self::field('body'),'rating'=>self::field('rating'),'branch'=>self::field('branch'),'thread'=>self::field('thread'),'locale'=>self::field('locale'),'consent'=>self::field('consent'),'revision'=>self::field('revision')),$id);$section='reviews';}
		elseif('withdraw'===$operation){$result=Reviews::withdraw($id,absint(self::field('revision')));$section='reviews';}
		elseif('moderate'===$operation){$result=Reviews::moderate($id,absint(self::field('version')),absint(self::field('revision')),self::field('decision'),self::field('reason'));$section='reviews';}
		elseif('ownership'===$operation){$result=self::ownership();$section='profile';}
		elseif('settings'===$operation&&current_user_can('manage_options')){
			$result=PortalAccess::save_settings('1'===self::field('enabled'),'1'===self::field('mail'));$section='messages';
		}else{$result=PortalAccess::error('adc_portal_input');}
		$section=in_array($section,array('overview','requests','bookings','messages','reviews','profile','preferences'),true)?$section:'overview';
		$admin='1'===self::field('admin')&&(PortalAccess::staff()||PortalAccess::cap('adc_moderate_reviews'));
		$url=$admin?admin_url('admin.php?page='.('reviews'===$section?'adc-customer-reviews':'adc-conversations')):PortalAccess::url($section);
		if($id&&'messages'===$section){$url=add_query_arg('thread',$id,$url);}
		if($id&&'review_context'===$operation&&!is_wp_error($result)){$url=add_query_arg('context',$id,$url);}
		if(is_wp_error($result)&&'review'===$operation&&$id&&!$admin){$url=add_query_arg('review',$id,$url);}
		$url=\AutoDealership\Inventory\CatalogPresentation::localized_url($url,PortalAccess::language());
		if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')){if(is_wp_error($result)){wp_send_json_error(array('message'=>$result->get_error_message()),(int)($result->get_error_data()['status']??400));}if(!in_array($operation,array('send','read'),true)){set_transient('adc_portal_flash_'.get_current_user_id(),array('error'=>false,'message'=>PortalAccess::t('تم تنفيذ الطلب بنجاح.','Your request was completed successfully.')),60);}wp_send_json_success(array('id'=>$id,'result'=>$result,'redirect'=>$url));}
		if(is_wp_error($result)&&in_array($operation,array('send','review'),true)){$draft=array('operation'=>$operation,'id'=>$id);foreach(array('body','name','rating','branch','locale','thread','consent','revision','request_key') as $field){$draft[$field]=mb_substr(sanitize_textarea_field(self::field($field)),0,4000);}set_transient('adc_portal_draft_'.get_current_user_id(),$draft,10*MINUTE_IN_SECONDS);}
		set_transient('adc_portal_flash_'.get_current_user_id(),array('error'=>is_wp_error($result),'message'=>is_wp_error($result)?$result->get_error_message():PortalAccess::t('تم تنفيذ الطلب بنجاح.','Your request was completed successfully.')),60);wp_safe_redirect($url,303);exit;
	}
	public static function draft(string $operation,int $id=0):array {$draft=get_transient('adc_portal_draft_'.get_current_user_id());if(!is_array($draft)||($draft['operation']??'')!==$operation||(int)($draft['id']??0)!==$id){return array();}delete_transient('adc_portal_draft_'.get_current_user_id());return $draft;}
	public static function ownership() {
		if(!get_current_user_id()||PortalAccess::verified()){return true;}
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('email_verification');if(is_wp_error($rate)){return $rate;}
		global $wpdb;if(!\AutoDealership\Database\Transaction::begin()){return PortalAccess::error();}$user=wp_get_current_user();
		$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$user->ID));
		update_user_meta($user->ID,'adc_portal_ownership_requested','1');$payload=\AutoDealership\Accounts\EmailVerification::issue('account',$user->ID,$user->user_email,PortalAccess::language());
		if(is_wp_error($payload)){$wpdb->query('ROLLBACK');clean_user_cache($user->ID);return $payload;}
		if(!\AutoDealership\Database\Transaction::commit(static fn()=>PortalAccess::audit('ownership_requested','user',$user->ID))){clean_user_cache($user->ID);return PortalAccess::error();}
		\AutoDealership\Accounts\EmailVerification::deliver_committed($payload);return true;
	}
	public static function poll():void {
		check_ajax_referer('adc_portal_poll','nonce');PortalAccess::request_scope('staff'===($_REQUEST['portal_scope']??'')&&PortalAccess::staff());nocache_headers();$id=isset($_GET['id'])&&is_scalar($_GET['id'])?absint($_GET['id']):0;$after=isset($_GET['after'])&&is_scalar($_GET['after'])?absint($_GET['after']):0;
		$rate=\AutoDealership\Security\PublicRequestGuard::consume('public_read');$result=is_wp_error($rate)?$rate:Conversations::detail($id,$after);
		if(is_wp_error($result)){wp_send_json_error(array('message'=>$result->get_error_message()),(int)($result->get_error_data()['status']??400));}wp_send_json_success($result);
	}
	public static function flash():void {
		$notice=get_transient('adc_portal_flash_'.get_current_user_id());if(!$notice){return;}delete_transient('adc_portal_flash_'.get_current_user_id());echo '<div class="adc-p-notice '.(!empty($notice['error'])?'is-error':'is-success').'" role="'.(!empty($notice['error'])?'alert':'status').'" '.(empty($notice['error'])?'data-success-notice':'').'>'.esc_html($notice['message']).'</div>';
	}
	public static function form(string $operation,string $section,array $fields=array(),bool $admin=false):void {
		echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="adc-p-form">';wp_nonce_field('adc_portal');
		foreach(array_merge(array('action'=>'adc_portal','operation'=>$operation,'section'=>$section,'admin'=>$admin?'1':'0','portal_scope'=>$admin?'staff':'personal','lang'=>PortalAccess::language()),$fields) as $name=>$value){echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'">';}
	}
	public static function notifications():array {global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT kind,object_id,created_at FROM '.PortalAccess::table('notifications').' WHERE user_id=%d AND read_at IS NULL ORDER BY id DESC LIMIT 10',get_current_user_id()),ARRAY_A)?:array();}
}
