<?php
/** Synthetic fixtures only, loaded by the guarded loopback/isolated-database router. */
if(PHP_SAPI!=='cli-server'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)){exit(1);}
if(get_option('adc_test_recovery_enabled',false)){
	remove_all_filters('pre_wp_mail');
	add_filter('pre_wp_mail',static function($result,$mail){
		$messages=(array)get_option('adc_test_recovery_mail',array());$messages[]=$mail;update_option('adc_test_recovery_mail',$messages,false);
		return 'fail'!==get_option('adc_test_recovery_mail_mode','accept');
	},PHP_INT_MAX,2);
}
if('/adc-product-recovery-fixture'!==$path){return;}
if(!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}
use AutoDealership\Accounts\PasswordRecovery as Recovery;
use AutoDealership\Accounts\EmailVerification;
$op=$_GET['op']??'create';$id=(int)get_option('adc_test_recovery_user',0);
if(!$id){
	$id=wp_insert_user(array('user_login'=>'recovery_customer','user_email'=>'recovery@example.invalid','user_pass'=>'OriginalPassword123!','display_name'=>'Recovery customer','role'=>'car_dealer_customer'));
	if(is_wp_error($id)){http_response_code(500);exit;}
	update_option('adc_test_recovery_user',$id);update_user_meta($id,'adc_preferred_language','en');
	update_user_meta($id,'adc_email_verification_status','verified');update_user_meta($id,'adc_verified_email_hash',hash('sha256','recovery@example.invalid'));
}
update_option('adc_test_recovery_enabled',true,false);
if('fail'===$op||'accept'===$op){update_option('adc_test_recovery_mail_mode',$op,false);}
if('clear_limits'===$op){global $wpdb;$wpdb->query('DELETE FROM '.\AutoDealership\Database\Schema::table('request_limits')." WHERE policy_key IN ('password_recovery','recovery_network')");}
if('pending'===$op){update_user_meta($id,'adc_email_verification_status','pending');}
if('verified'===$op){update_user_meta($id,'adc_email_verification_status','verified');}
$user=get_userdata($id);$data=array('id'=>$id,'email'=>$user->user_email,'role'=>$user->roles,'blocked'=>EmailVerification::blocked($id),'original_password'=>wp_check_password('OriginalPassword123!',$user->user_pass),'new_password'=>wp_check_password('NewRecoveryPassword123!',$user->user_pass),'messages'=>get_option('adc_test_recovery_mail',array()),'lost_url'=>wp_lostpassword_url(add_query_arg(array('cd_account'=>'login','portal_return'=>'profile'),home_url('/'))),'login_url'=>wp_login_url(),'register_url'=>wp_registration_url());
$data['logout_url']=wp_logout_url(home_url('/'));
$data['login']=$user->user_login;
if('audit'===$op){
	ob_start();wp_auth_check_html();$data['auth_check_html']=ob_get_clean();
	$data['message_url']=Recovery::url('lostpassword',add_query_arg(array('cd_account'=>'login','portal_return'=>'messages','portal_thread'=>123),home_url('/')),'en');
	$data['review_url']=Recovery::url('lostpassword',add_query_arg(array('cd_account'=>'login','portal_return'=>'reviews','portal_review'=>456),home_url('/')),'en');
	$data['external_url']=Recovery::url('lostpassword','https://untrusted.example.invalid/?portal_return=messages&portal_thread=123','en');
	$saved=$_GET;$_GET=array('portal_return'=>'messages','portal_thread'=>'123');
	foreach(array('login','lostpassword','resetpass','dashboard') as $view){$data['theme_urls'][$view]=car_dealer_account_url($view);}
	$_GET=array('portal_return'=>'messages','portal_thread'=>array('123'));$data['invalid_context_url']=Recovery::url('resetpass','','en');$_GET=$saved;
}
if(in_array($op,array('create','fresh','expired'),true)){
	$proof=get_password_reset_key($user);
	if('expired'===$op){global $wpdb;$wpdb->update($wpdb->users,array('user_activation_key'=>(time()-2*DAY_IN_SECONDS).':'.wp_fast_hash($proof)),array('ID'=>$id));clean_user_cache($id);}
	$data['url']=add_query_arg(array('key'=>$proof,'login'=>$user->user_login),Recovery::url('resetpass','','en'));
	$data['legacy_url']=add_query_arg(array('action'=>'rp','key'=>$proof,'login'=>$user->user_login,'wp_lang'=>'en'),site_url('/wp-login.php'));
}
header('Content-Type: application/json');echo wp_json_encode($data);exit;
