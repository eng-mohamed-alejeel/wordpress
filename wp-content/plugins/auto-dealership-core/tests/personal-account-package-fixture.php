<?php
/** Included only by the guarded loopback package router, never shipped. */
use AutoDealership\Portal\{PortalAccess as A,Conversations as C};
use AutoDealership\Database\Schema as S;
if(PHP_SAPI!=='cli-server'||!preg_match('/\Aadc_verify_[a-f0-9]{16}\z/',DB_NAME)||!hash_equals($config['token'],(string)($_GET['key']??''))){http_response_code(403);exit;}
global $wpdb;wp_set_current_user($config['admin']);$fixture=get_option('adc_test_personal_fixture');
if(!$fixture){
    $branch=\AutoDealership\Branches\BranchService::create(array('code'=>'PERSONAL_BROWSER','name'=>'Personal browser branch'))['id'];$fixture=array();$now=current_time('mysql',true);
    foreach(array('customer'=>'car_dealer_customer','staff'=>'dealership_sales','admin'=>'administrator','empty'=>'administrator') as $kind=>$role){
        $uid=wp_insert_user(array('user_login'=>'personal_browser_'.$kind,'user_email'=>'personal_browser_'.$kind.'@example.invalid','display_name'=>'Personal '.$kind,'role'=>$role,'user_pass'=>wp_generate_password(32)));
        if(is_wp_error($uid)){throw new RuntimeException('Personal account actor fixture failed.');}
        foreach(array('adc_email_verification_status'=>'verified','adc_email_verified_at'=>$now,'adc_verified_email_hash'=>hash('sha256','personal_browser_'.$kind.'@example.invalid')) as $key=>$value){update_user_meta($uid,$key,$value);}
        if('staff'===$kind){\AutoDealership\Core\ConfigurationService::assign_branches($uid,$branch,array($branch));}
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
$kind=is_string($_GET['as']??null)&&isset($fixture[$_GET['as']])?$_GET['as']:'customer';$uid=(int)$fixture[$kind]['user'];wp_set_current_user($uid);wp_set_auth_cookie($uid,false,false);header('Content-Type: application/json');echo wp_json_encode($fixture);exit;
