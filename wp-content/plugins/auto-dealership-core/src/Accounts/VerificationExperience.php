<?php
namespace AutoDealership\Accounts;

use AutoDealership\Audit\AuditLog;
use AutoDealership\Database\Transaction;
use AutoDealership\Security\ClientAddress;

defined('ABSPATH') || exit;

/** Public verification UX and bounded administrative diagnostics. No raw links in diagnostics. */
final class VerificationExperience {
	private static function t(string $ar,string $en): string { return EmailVerification::t($ar,$en); }
	public static function boot(): void {
		add_filter('manage_users_columns',static function($columns){$columns['adc_verification']=self::t('تأكيد البريد','Email confirmation');return $columns;});
		add_filter('manage_users_custom_column',static function($value,$column,$id){return 'adc_verification'===$column && current_user_can('list_users') ? esc_html(self::account_status((int)$id)) : $value;},10,3);
		add_action('show_user_profile',array(self::class,'profile'));
		add_action('edit_user_profile',array(self::class,'profile'));
		add_action('admin_post_adc_verification_resend',array(self::class,'admin_resend'));
	}
	public static function account_status(int $id): string {
		if(!get_user_meta($id,'adc_email_verification_status',true)){return self::t('حساب سابق — لا يوجد إثبات تأكيد مسجّل','Existing account — no recorded confirmation');}
		return EmailVerification::blocked($id)?self::t('بانتظار تأكيد البريد','Awaiting email confirmation'):self::t('البريد مؤكّد','Email confirmed');
	}
	private static function cooldown_key(string $purpose): string {
		return 'adc_verify_wait_'.hash_hmac('sha256',$purpose.'|'.ClientAddress::resolve(),wp_salt('auth'));
	}
	public static function remaining(string $purpose): int { return max(0,min(60,(int)get_transient(self::cooldown_key($purpose))-time())); }
	/** Exact rolling minute, serialized independently of whether an address exists. */
	public static function reserve_resend(string $purpose) {
		global $wpdb;$key=self::cooldown_key($purpose);$lock='adc_wait_'.substr(hash('sha256',$key),0,40);
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))){return new \WP_Error('adc_verification_busy');}
		try{if(self::remaining($purpose)){return new \WP_Error('adc_verification_throttled');}if(!set_transient($key,time()+60,120)){return new \WP_Error('adc_verification_storage');}return true;}
		finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	/** Opaque registration-browser capability; never issued for duplicate registrations. */
	public static function enrollment(int $id): void {
		if('cli'===PHP_SAPI){return;}
		$token=bin2hex(random_bytes(32));set_transient('adc_enrollment_'.hash('sha256',$token),$id,30*MINUTE_IN_SECONDS);
		self::reserve_resend('account');
		setcookie('adc_enrollment',$token,array('expires'=>time()+30*MINUTE_IN_SECONDS,'path'=>defined('COOKIEPATH')?COOKIEPATH:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
	}
	public static function enrollment_id(): int {
		$token=$_COOKIE['adc_enrollment']??'';
		if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/',$token)){return 0;}
		$id=(int)get_transient('adc_enrollment_'.hash('sha256',$token));
		return $id && 'pending'===get_user_meta($id,'adc_email_verification_status',true) && EmailVerification::blocked($id)?$id:0;
	}
	/** Correction requires the original registration browser and an unverified customer. */
	public static function change_email(string $email) {
		$id=self::enrollment_id();$email=strtolower($email);$user=$id?get_userdata($id):false;
		if(!$user || !in_array('car_dealer_customer',$user->roles,true) || !is_email($email) || strlen($email)>100){return new \WP_Error('adc_email_correction_invalid');}
		global $wpdb;$lock='adc_register_'.md5(strtolower($email));
		if('1'!==(string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))){return new \WP_Error('adc_email_correction_busy');}
		try{
			if(!Transaction::begin()){return new \WP_Error('adc_verification_storage');}
			$locked=$wpdb->get_row($wpdb->prepare("SELECT ID,user_email FROM {$wpdb->users} WHERE ID=%d FOR UPDATE",$id));
			clean_user_cache($id);
			$other=$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE user_email=%s LIMIT 1 FOR UPDATE",$email));
			if($wpdb->last_error || !$locked || !self::enrollment_id() || $other && (int)$other!==$id){$wpdb->query('ROLLBACK');return new \WP_Error('adc_email_correction_invalid');}
			$changed=false!==$wpdb->update($wpdb->users,array('user_email'=>$email),array('ID'=>$id));clean_user_cache($id);
			$payload=$changed?EmailVerification::issue('account',$id,$email,(string)get_user_meta($id,'adc_preferred_language',true)):new \WP_Error('adc_verification_storage');
			if(is_wp_error($payload)){$wpdb->query('ROLLBACK');clean_user_cache($id);return $payload;}
			if(!Transaction::commit(static fn()=>AuditLog::record('email.registration_corrected','user',$id,'Pending registration email corrected',null,array('new_link_issued'=>true)))){clean_user_cache($id);return new \WP_Error('adc_verification_storage');}
			clean_user_cache($id);EmailVerification::deliver_committed($payload);\WP_Session_Tokens::get_instance($id)->destroy_all();
			return true;
		}finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
	}
	public static function browser_stamp(string $purpose): string {
		$time=time();return $time.'.'.hash_hmac('sha256','browser|'.$purpose.'|'.$time.'|'.wp_create_nonce('adc_email_verify'),wp_salt('nonce'));
	}
	public static function valid_browser_stamp(string $stamp,string $purpose): bool {
		if(!preg_match('/\A([0-9]{10})\.([a-f0-9]{64})\z/',$stamp,$parts)){return false;}
		$age=time()-(int)$parts[1];
		return $age>=1 && $age<=10*MINUTE_IN_SECONDS && hash_equals(hash_hmac('sha256','browser|'.$purpose.'|'.$parts[1].'|'.wp_create_nonce('adc_email_verify'),wp_salt('nonce')),$parts[2]);
	}
	public static function profile($user): void {
		if(!current_user_can('list_users')||!current_user_can('edit_user',$user->ID)){return;}
		global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT id,generation,created_at,sent_at,used_at FROM '.EmailVerification::table()." WHERE purpose='account' AND subject_id=%d",$user->ID),ARRAY_A);
		echo '<h2>'.esc_html(self::t('تأكيد البريد الإلكتروني','Email verification')).'</h2>';
		$notice=get_transient('adc_verify_admin_'.get_current_user_id());if($notice){delete_transient('adc_verify_admin_'.get_current_user_id());echo '<div class="notice notice-info inline"><p>'.esc_html($notice).'</p></div>';}
		echo '<table class="form-table"><tr><th>'.esc_html(self::t('حالة الحساب','Account status')).'</th><td>'.esc_html(self::account_status((int)$user->ID)).'</td></tr>';
		foreach(array(self::t('تاريخ التأكيد','Confirmed at')=>get_user_meta($user->ID,'adc_email_verified_at',true),self::t('آخر طلب رابط','Last link requested')=>$row['created_at']??'',self::t('آخر قبول من SMTP','Last SMTP acceptance')=>$row['sent_at']??'') as $label=>$value){echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($value?get_date_from_gmt($value,'Y-m-d H:i:s'):self::t('غير مسجّل','Not recorded')).'</td></tr>';}
		if($row){$key=hash('sha256','email.verify|email-verify:'.$row['id'].':'.$row['generation']);$event=$wpdb->get_row($wpdb->prepare('SELECT status,attempts FROM '.\AutoDealership\Operations\OutboxService::table().' WHERE idempotency_key=%s',$key),ARRAY_A);$labels=array('pending'=>self::t('في انتظار الإرسال','Queued'),'processing'=>self::t('جارٍ الإرسال','Sending'),'retry'=>self::t('بانتظار إعادة المحاولة','Waiting for retry'),'completed'=>self::t('اكتملت المعالجة','Processing completed'),'failed'=>self::t('تعذر الإرسال','Sending failed'));echo '<tr><th>'.esc_html(self::t('معالجة الرسالة','Message processing')).'</th><td>'.esc_html($labels[$event['status']??'']??self::t('غير مسجّل','Not recorded')).' · '.esc_html(self::t('المحاولات: ','Attempts: ').(int)($event['attempts']??0)).'</td></tr>';}
		echo '</table><p class="description">'.esc_html(self::t('قبول SMTP لا يثبت وصول الرسالة إلى صندوق الوارد. Gmail SMTP لا يوفّر هنا إشعارات تسليم أو ارتداد موثوقة؛ حالة التسليم غير متاحة.','SMTP acceptance does not prove inbox delivery. Gmail SMTP provides no verified delivery or bounce callbacks here; delivery status is unavailable.')).'</p>';
		if(EmailVerification::blocked((int)$user->ID)){echo '<p><a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'adc_verification_resend','user_id'=>$user->ID),admin_url('admin-post.php')),'adc_resend_'.$user->ID)).'">'.esc_html(self::t('إعادة إرسال رابط التأكيد','Resend verification link')).'</a> '.esc_html(self::t('تطبق مهلة الدقيقة وحدود الإرسال نفسها.','The same one-minute cooldown and sending limits apply.')).'</p>';}
	}
	public static function admin_resend(): void {
		$id=absint($_GET['user_id']??0);
		if(!$id || !current_user_can('list_users') || !current_user_can('edit_user',$id)){wp_die(esc_html(self::t('غير مسموح','Not allowed')),403);}
		check_admin_referer('adc_resend_'.$id);$user=get_userdata($id);$rate=self::reserve_resend('account');
		$result=is_wp_error($rate)?$rate:($user?EmailVerification::request('account',$user->user_email):new \WP_Error('adc_user_missing'));
		set_transient('adc_verify_admin_'.get_current_user_id(),is_wp_error($result)?self::t('تعذر الإرسال الآن. انتظر دقيقة ثم أعد المحاولة.','Unable to send now. Wait one minute and retry.'):self::t('تمت معالجة الطلب. يُرسل رابط جديد فقط للحساب الذي ينتظر التأكيد، وضمن حدود الإرسال.','Request processed. A new link is sent only for an account awaiting confirmation and within sending limits.'),60);
		wp_safe_redirect(get_edit_user_link($id));exit;
	}
}
