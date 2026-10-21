<?php
/** Included only by the guarded loopback package router, never shipped. */
use AutoDealership\Portal\{PortalAccess as A,Conversations as C};
use AutoDealership\Database\Schema as S;
if((PHP_SAPI!=='cli-server'&&!(PHP_SAPI==='apache2handler'&&getenv('ADC_PRODUCT_TEST_APACHE')==='1'))||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)||!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}
global $wpdb;wp_set_current_user($config['admin']);$fixture=get_option('adc_test_personal_fixture');
if(!$fixture){
    $branch=\AutoDealership\Branches\BranchService::create(array('code'=>'PERSONAL_BROWSER','name'=>'Personal browser branch'))['id'];$fixture=array();$now=current_time('mysql',true);
    foreach(array('customer'=>'car_dealer_customer','staff'=>'dealership_sales','admin'=>'administrator','empty'=>'administrator') as $kind=>$role){
        wp_set_current_user($config['admin']);
        $uid=wp_insert_user(array('user_login'=>'personal_browser_'.$kind,'user_email'=>'personal_browser_'.$kind.'@example.invalid','display_name'=>'Personal '.$kind,'role'=>$role,'user_pass'=>wp_generate_password(32)));
        if(is_wp_error($uid)){throw new RuntimeException('Personal account actor fixture failed.');}
        foreach(array('adc_email_verification_status'=>'verified','adc_email_verified_at'=>$now,'adc_verified_email_hash'=>hash('sha256','personal_browser_'.$kind.'@example.invalid')) as $key=>$value){update_user_meta($uid,$key,$value);}
        if('staff'===$kind&&is_wp_error(\AutoDealership\Core\ConfigurationService::assign_branches($uid,$branch,array($branch)))){throw new RuntimeException('Personal staff branch fixture failed.');}
        $fixture[$kind]=array('user'=>$uid);if('empty'===$kind){continue;}
        wp_set_current_user($uid);A::request_scope(false);$marker='PERSONAL_BROWSER_'.$kind;
        $thread=C::ensure('general',0,$marker,$branch);if(is_wp_error($thread)){throw new RuntimeException('Personal conversation fixture failed.');}
        foreach(array('public','internal') as $visibility){$body=$marker.'_'.$visibility;$wpdb->insert(A::table('messages'),array('thread_id'=>$thread,'sender_id'=>0,'sender_kind'=>'staff','sender_label'=>'Account adviser','visibility'=>$visibility,'body'=>$body,'request_key'=>hash('sha256',$body),'body_hash'=>hash('sha256',$body),'created_at'=>$now));if('public'===$visibility){$wpdb->update(A::table('threads'),array('last_public_id'=>$wpdb->insert_id),array('id'=>$thread));}}
        $wpdb->insert($wpdb->prefix.'car_dealer_messages',array('user_id'=>$uid,'name'=>$marker,'email'=>get_userdata($uid)->user_email,'subject'=>$marker,'message'=>$marker,'customer_reply'=>'','created_at'=>$now,'updated_at'=>$now));$request=(int)$wpdb->insert_id;
        $wpdb->insert($wpdb->prefix.'car_dealer_bookings',array('user_id'=>$uid,'car_id'=>0,'name'=>$marker,'email'=>get_userdata($uid)->user_email,'requested_date'=>wp_date('Y-m-d',time()+5*DAY_IN_SECONDS),'requested_time'=>'13:00','customer_reply'=>'','created_at'=>$now,'updated_at'=>$now));$booking=(int)$wpdb->insert_id;
        $wpdb->insert(S::table('customers'),array('account_user_id'=>$uid,'full_name'=>$marker,'mobile'=>'051'.str_pad((string)$uid,7,'0',STR_PAD_LEFT),'created_at'=>$now,'updated_at'=>$now));$customer=(int)$wpdb->insert_id;
        $wpdb->insert(S::table('quotations'),array('quote_number'=>$marker,'customer_id'=>$customer,'vehicle_id'=>0,'branch_id'=>$branch,'owner_user_id'=>$config['admin'],'base_amount'=>100000,'final_amount'=>115000,'valid_until'=>wp_date('Y-m-d',time()+DAY_IN_SECONDS),'status'=>'issued','seller_address'=>'','created_at'=>$now));$quote=(int)$wpdb->insert_id;
        $wpdb->insert(S::table('sales'),array('quotation_id'=>$quote,'customer_id'=>$customer,'vehicle_id'=>0,'owner_user_id'=>$config['admin'],'status'=>'pending_approval','created_at'=>$now,'updated_at'=>$now));$sale=(int)$wpdb->insert_id;
        $bytes="%PDF-1.4\n%%EOF";$wpdb->insert(A::table('documents'),array('sale_id'=>$sale,'kind'=>'invoice','title'=>$marker,'reference'=>$marker,'content_hash'=>hash('sha256',$bytes),'file_bytes'=>$bytes,'file_size'=>strlen($bytes),'created_by'=>$config['admin'],'created_at'=>$now));$doc=(int)$wpdb->insert_id;
        if($wpdb->last_error||!$doc){throw new RuntimeException('Personal records fixture failed.');}
        $fixture[$kind]+=compact('thread','request','booking','sale','doc','marker');
    }
    update_option('adc_test_personal_fixture',$fixture,false);
}
if(!get_option('adc_test_work_roles')){
    wp_set_current_user($config['admin']);$branch=(int)get_user_meta($fixture['staff']['user'],'adc_branch_id',true);
    foreach(array('inventory'=>'dealership_inventory','finance'=>'dealership_finance','purchasing'=>'dealership_purchasing','delivery'=>'dealership_delivery','marketing'=>'dealership_marketing','auditor'=>'dealership_auditor','manager'=>'dealership_sales_manager','multi'=>'dealership_sales','module'=>'subscriber','unassigned'=>'dealership_sales')as$kind=>$role){
        $uid=wp_insert_user(array('user_login'=>'work_browser_'.$kind,'user_email'=>'work_browser_'.$kind.'@example.invalid','display_name'=>'Work '.$kind,'role'=>$role,'user_pass'=>wp_generate_password(32)));
        if(is_wp_error($uid)){throw new RuntimeException('Work account actor fixture failed.');}
        if($kind==='multi'){get_userdata($uid)->add_role('dealership_inventory');get_userdata($uid)->add_role('car_dealer_customer');}
        if($kind==='module'){get_userdata($uid)->add_cap('adc_view_conversations');}
        if($kind!=='unassigned'&&is_wp_error(\AutoDealership\Core\ConfigurationService::assign_branches($uid,$branch,array($branch)))){throw new RuntimeException('Work account branch fixture failed.');}
        $fixture[$kind]=array('user'=>$uid);
    }
    update_option('adc_test_personal_fixture',$fixture,false);update_option('adc_test_work_roles',true,false);
}
if(isset($_GET['portal_enabled'])&&is_string($_GET['portal_enabled'])&&in_array($_GET['portal_enabled'],array('0','1'),true)){
    $settings=A::settings();$settings['enabled']='1'===$_GET['portal_enabled'];update_option('adc_portal_settings',$settings,false);
}
$kind=is_string($_GET['as']??null)&&isset($fixture[$_GET['as']])?$_GET['as']:'customer';$uid=(int)$fixture[$kind]['user'];wp_set_current_user($uid);wp_set_auth_cookie($uid,false,false);header('Content-Type: application/json');echo wp_json_encode($fixture);exit;
