<?php
namespace AutoDealership\Accounts;

use AutoDealership\Core\Localization;
use AutoDealership\Security\PublicRequestGuard;

defined('ABSPATH') || exit;

/** Branded routes backed by WordPress reset keys, mail and session revocation. */
final class PasswordRecovery {
	private const SECTIONS = array('overview','requests','bookings','messages','reviews','profile','preferences','favorites','purchases','documents');
	public static function enabled(): bool { return 'car-dealer'===get_template() && CustomerAccount::owns_theme_actions(); }
	public static function boot(): void {
		add_filter('lostpassword_url',static fn($url,$redirect)=>self::enabled()?self::url('lostpassword',$redirect):$url,20,2);
		add_filter('login_url',static fn($url,$redirect,$reauth)=>self::enabled()?($reauth?add_query_arg('reauth',1,self::url('login',$redirect)):self::url('login',$redirect)):$url,20,3);
		add_filter('register_url',static fn($url)=>self::enabled()?self::url('register'):$url,20);
		add_filter('logout_url',static fn($url)=>self::enabled()?wp_nonce_url(self::url('logout'),'adc_account_logout'):$url,20);
		add_action('login_init',array(self::class,'legacy_route'));
		add_action('template_redirect',array(self::class,'prepare'),-1);
	}
	private static function field(array $input,string $name): string { return isset($input[$name])&&is_string($input[$name])?wp_unslash($input[$name]):''; }
	public static function url(string $view,string $redirect='',string $language=''): string {
		$args=array('cd_account'=>$view,'lang'=>'en'===($language?:Localization::language())?'en':'ar');
		$context=$_GET;
		$section=self::field($_GET,'portal_return');
		if($redirect){
			$target=wp_parse_url($redirect);$home=wp_parse_url(home_url('/'));
			if(($target['host']??'')===($home['host']??'')&&($target['port']??null)===($home['port']??null)&&($target['path']??'/')===($home['path']??'/')){
				parse_str($target['query']??'',$query);$context=$query;$candidate=$query['portal_return']??$query['portal']??'';
				if(is_string($candidate)){$section=$candidate;}
			}
		}
		if(in_array($section,self::SECTIONS,true)){$args['portal_return']=$section;}
		foreach(array('thread'=>'messages','review'=>'reviews') as $key=>$owner){
			$value=$context['portal_'.$key]??$context[$key]??null;
			$id=is_scalar($value)?filter_var($value,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1))):false;
			if($section===$owner&&false!==$id){$args['portal_'.$key]=$id;}
		}
		return add_query_arg($args,home_url('/'));
	}
	/** Old bookmarks and previously issued email links keep working without native forms. */
	public static function legacy_route(): void {
		if(!self::enabled()){return;}
		$action=self::field($_REQUEST,'action')?:'login';
		if(!in_array($action,array('login','register','lostpassword','retrievepassword','rp','resetpass'),true)){return;}
		// Embedded reauthentication and protected-post passwords retain their core contract.
		if(isset($_REQUEST['interim-login'])){return;}
		$view=in_array($action,array('rp','resetpass'),true)?'resetpass':('retrievepassword'===$action?'lostpassword':$action);
		$lang=str_starts_with(self::field($_GET,'wp_lang'),'en')?'en':(self::field($_GET,'wp_lang')?'ar':Localization::language());
		$url=self::url($view,self::field($_REQUEST,'redirect_to'),$lang);
		if('login'===$view&&isset($_REQUEST['reauth'])){$url=add_query_arg('reauth',1,$url);}
		if('resetpass'===$view){foreach(array('key','login') as $name){$value=self::field($_GET,$name);if($value!==''){$url=add_query_arg($name,$value,$url);}}}
		if('resetpass'===$view&&!isset($_GET['key'],$_GET['login'])){
			$legacy=self::field($_COOKIE,'wp-resetpass-'.COOKIEHASH);$parts=explode(':',$legacy,2);
			if(count($parts)===2&&!is_wp_error(check_password_reset_key($parts[1],$parts[0]))){self::cookie(base64_encode(wp_json_encode(array('login'=>$parts[0],'key'=>$parts[1]))));}
		}
		nocache_headers();header('Referrer-Policy: no-referrer');wp_safe_redirect($url,303);exit;
	}
	private static function cookie_name(): string { return 'adc_password_reset_'.substr(hash('sha256',home_url('/')),0,16); }
	private static function cookie(string $value): void {
		setcookie(self::cookie_name(),$value,array('expires'=>$value?time()+HOUR_IN_SECONDS:time()-YEAR_IN_SECONDS,'path'=>defined('COOKIEPATH')&&COOKIEPATH?COOKIEPATH:'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
		if($value){$_COOKIE[self::cookie_name()]=$value;}else{unset($_COOKIE[self::cookie_name()]);}
	}
	private static function credentials(): array {
		$value=self::field($_COOKIE,self::cookie_name());
		if(strlen($value)>2048){return array();}
		$decoded=json_decode((string)base64_decode($value,true),true);
		return is_array($decoded)&&is_string($decoded['login']??null)&&is_string($decoded['key']??null)?$decoded:array();
	}
	public static function user() {
		$proof=self::credentials();return $proof?check_password_reset_key($proof['key'],$proof['login']):new \WP_Error('invalid_key');
	}
	public static function nonce_action(): string { return 'adc_password_reset_'.hash('sha256',self::field($_COOKIE,self::cookie_name())); }
	public static function prepare(): void {
		// The editor iframe needs WordPress's interim-login completion handshake.
		if(self::enabled()&&'login'===self::field($_GET,'cd_account')&&isset($_GET['interim-login'])){
			$locale=self::field($_GET,'wp_lang');$locale=$locale?(str_starts_with($locale,'en')?'en_US':'ar'):('en'===Localization::language()?'en_US':'ar');
			$args=array('interim-login'=>1,'wp_lang'=>$locale);
			if('1'===self::field($_GET,'reauth')){$args['reauth']=1;}
			nocache_headers();wp_safe_redirect(add_query_arg($args,site_url('wp-login.php','login')),303);exit;
		}
		if(self::enabled()&&'logout'===self::field($_GET,'cd_account')){
			nocache_headers();
			if(!wp_verify_nonce(self::field($_GET,'_wpnonce'),'adc_account_logout')){wp_safe_redirect(self::url('dashboard'),303);exit;}
			wp_logout();self::cookie('');wp_safe_redirect(home_url('/'),303);exit;
		}
		if(self::enabled()&&'login'===self::field($_GET,'cd_account')&&'1'===self::field($_GET,'reauth')){wp_clear_auth_cookie();wp_set_current_user(0);}
		if(!self::enabled()||!in_array(self::field($_GET,'cd_account'),array('lostpassword','resetpass'),true)){return;}
		nocache_headers();header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow',true);
		if('resetpass'!==self::field($_GET,'cd_account')||!isset($_GET['key'],$_GET['login'])){return;}
		$key=self::field($_GET,'key');$login=self::field($_GET,'login');
		$user=strlen($key)<=256&&strlen($login)<=60?check_password_reset_key($key,$login):new \WP_Error('invalid_key');
		if(is_wp_error($user)){self::cookie('');}else{self::cookie(base64_encode(wp_json_encode(array('login'=>$login,'key'=>$key))));}
		$url=self::url('resetpass');if(is_wp_error($user)){$url=add_query_arg('invalid',1,$url);}
		wp_safe_redirect($url,303);exit;
	}
	/** Returns a localized error; successful POSTs use PRG and never create a login session. */
	public static function process(string $view): string {
		if('POST'!==($_SERVER['REQUEST_METHOD']??'')){return '';}
		$action='resetpass'===$view?self::nonce_action():'cd_account_lostpassword';
		if(!wp_verify_nonce(self::field($_POST,'_wpnonce'),$action)){return EmailVerification::t('انتهت صلاحية النموذج. حدّث الصفحة وحاول مجددًا.','The form expired. Refresh the page and try again.');}
		$identity='lostpassword'===$view?strtolower(trim(self::field($_POST,'user_login'))):hash('sha256',self::field($_COOKIE,self::cookie_name()));
		$rate_identity=$identity;
		if('lostpassword'===$view&&''!==$identity&&strlen($identity)<=100){$account=get_user_by(str_contains($identity,'@')?'email':'login',$identity);if($account){$rate_identity='account:'.$account->ID;}}
		$rate=PublicRequestGuard::consume('recovery_network');
		if(!is_wp_error($rate)){$rate=PublicRequestGuard::consume('password_recovery',hash_hmac('sha256',$rate_identity,wp_salt('auth')));}
		if(is_wp_error($rate)){return EmailVerification::t('تعذر إتمام الطلب الآن. انتظر قليلًا ثم حاول مجددًا.','Unable to complete the request now. Please wait and try again.');}
		if('lostpassword'===$view){
			if(''===$identity||strlen($identity)>100){return EmailVerification::t('أدخل البريد الإلكتروني أو اسم المستخدم.','Enter your email address or username.');}
			$result=retrieve_password($identity);
			if(is_wp_error($result)&&array_diff($result->get_error_codes(),array('invalid_email','invalidcombo','invalid_username'))){return EmailVerification::t('تعذر إرسال رسالة الاستعادة الآن. حاول لاحقًا أو تواصل معنا.','Unable to send the recovery email now. Try later or contact us.');}
			wp_safe_redirect(add_query_arg('requested',1,self::url('lostpassword')),303);exit;
		}
		$user=self::user();
		if(is_wp_error($user)){self::cookie('');return self::invalid_message();}
		$password=self::field($_POST,'pass1');
		if(!CustomerAccount::password_is_valid($password)){return EmailVerification::t('استخدم من 12 إلى 128 محرفًا، مع حرف إنجليزي كبير وصغير ورقم ورمز، دون مسافات.','Use 12–128 characters with uppercase and lowercase English letters, a number and a symbol, without spaces.');}
		if(!hash_equals($password,self::field($_POST,'pass2'))){return EmailVerification::t('تأكيد كلمة المرور غير مطابق.','The passwords do not match.');}
		$errors=new \WP_Error();do_action('validate_password_reset',$errors,$user);
		if($errors->has_errors()){return wp_strip_all_tags($errors->get_error_message());}
		reset_password($user,$password);self::cookie('');
		wp_safe_redirect(add_query_arg('password_reset',1,self::url('login')),303);exit;
	}
	public static function invalid_message(): string { return EmailVerification::t('رابط الاستعادة غير صالح أو انتهت صلاحيته أو استُخدم بالفعل. اطلب رابطًا جديدًا.', 'This recovery link is invalid, expired or already used. Request a new link.'); }
}
