<?php
/** Included by populated workspace acceptance, in its random isolated database only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'DB_NAME' ) || ! preg_match( '/\Aadc_verify_[a-f0-9]{16}\z/', DB_NAME ) ) { exit( 1 ); }
use AutoDealership\Portal\{AccountDashboard, WorkDashboard, WorkDashboardView, PortalAccess};

$work_get = $_GET; $work_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = array();
$personal = wp_insert_user( array( 'user_login'=>'workspace_customer', 'user_pass'=>wp_generate_password(32), 'role'=>'car_dealer_customer' ) );
$check( ! is_wp_error($personal), 'Create personal-only account' );
wp_set_current_user($personal);
$check( ! AccountDashboard::available() && ! AccountDashboard::work(), 'Customer has no operational landing or work modules' );
$check( is_wp_error(WorkDashboard::snapshot()), 'Customer cannot read the work projection by calling its service' );
foreach(array($sales,$manager,$finance,$delivery,$admin) as $actor){
 wp_set_current_user($actor);$_GET=array('cd_account'=>'dashboard');
 $check(AccountDashboard::available()&&AccountDashboard::work(),'Capability-bearing account defaults to work: '.$actor);
 $check(PortalAccess::personal_request(),'Operational frontend does not remove the personal portal action guard');
 foreach(array('overview','requests','bookings','messages','reviews','profile','preferences','favorites','purchases','documents')as$section){
  $_GET=array('cd_account'=>'dashboard','portal'=>$section,'account_context'=>'work');
  $check(!AccountDashboard::work(),'Existing personal deep link takes precedence: '.$actor.' '.$section);
 }
 foreach(array('overview','requests','bookings','messages','reviews','favorites','purchases','documents','profile','preferences')as$hidden){
  $_GET=array('cd_account'=>'dashboard','portal'=>$hidden);
  ob_start();\AutoDealership\Portal\PortalView::render();$settings=ob_get_clean();
  $check(!str_contains($settings,'portal=requests')&&!str_contains($settings,'portal=messages')&&!str_contains($settings,'portal=favorites')&&!str_contains($settings,'name="consent_marketing"'),'Staff personal routes suppress customer sections and marketing: '.$actor.' '.$hidden);
  $check(str_contains($settings,'portal=profile')&&str_contains($settings,'portal=preferences'),'Staff retains profile and appearance: '.$actor.' '.$hidden);
 }
 $_GET=array('account_context'=>'personal');$check(!AccountDashboard::work(),'Employee can explicitly choose personal view');
 $_GET=array('account_context'=>array('work'));$check(!AccountDashboard::work(),'Malformed context cannot select a work view');
 $_GET=array();$_SERVER['REQUEST_METHOD']='POST';$check(!AccountDashboard::work(),'Personal POST validation does not switch to work');$_SERVER['REQUEST_METHOD']='GET';
 $_GET=array('workspace_task'=>'overdue');if($actor===$sales){$work=WorkDashboard::snapshot();$check(25===$work['overview']['queues']['overdue']['count'],'Frontend projection reuses exact branch and owner predicates');
 ob_start();WorkDashboardView::render();$html=ob_get_clean();$check(!str_contains($html,'FOREIGN_CUSTOMER_SECRET')&&!str_contains($html,'<script>alert(1)</script>')&&str_contains($html,'&lt;script&gt;'),'Frontend queue escapes labels and excludes foreign customers');
 $check(str_contains($html,'account_context')&&str_contains($html,'portal=profile'),'Work view retains work filters and explicit personal switch');}
}
wp_set_current_user($sales);$_GET=array('workspace_branch'=>$b['id']);$check(is_wp_error(WorkDashboard::snapshot()),'Foreign branch selector is rejected before any work data is returned');
wp_set_current_user($unassigned);$_GET=array();$work=WorkDashboard::snapshot();$check(!$work['branches']&&0===$work['overview']['queues']['overdue']['count'],'Unassigned employee receives no branch records');
wp_set_current_user($admin);
$inventory=wp_insert_user(array('user_login'=>'workspace_inventory','user_pass'=>wp_generate_password(32),'role'=>'dealership_inventory'));
$check(!is_wp_error(\AutoDealership\Core\ConfigurationService::assign_branches($inventory,$a['id'],array($a['id']))),'Administrator assigns inventory fixture branch');
$received_a=$vehicle(1001,$a['id']);$received_b=$vehicle(1002,$b['id']);
$wpdb->update(\AutoDealership\Database\Schema::table('vehicles'),array('status'=>'received'),array('id'=>$received_a));
$wpdb->update(\AutoDealership\Database\Schema::table('vehicles'),array('status'=>'received'),array('id'=>$received_b));
wp_set_current_user($inventory);$work=WorkDashboard::snapshot();
$check(1===$work['cards']['received']['count']&&!isset($work['cards']['purchase_orders'])&&!isset($work['cards']['outbox']),'Inventory cards respect branch and actual module permissions: '.wp_json_encode($work['cards']));
$wpdb->update(\AutoDealership\Database\Schema::table('branches'),array('active'=>0),array('id'=>$a['id']));\AutoDealership\Security\BranchScope::clear_cache();
$inactive_work=WorkDashboard::snapshot();$check(!$inactive_work['branches']&&0===$inactive_work['cards']['received']['count'],'Inactive branch disappears from work cards as well as filters');
$wpdb->update(\AutoDealership\Database\Schema::table('branches'),array('active'=>1),array('id'=>$a['id']));\AutoDealership\Security\BranchScope::clear_cache();
wp_set_current_user($admin);
$chat=wp_insert_user(array('user_login'=>'workspace_module_only','user_pass'=>wp_generate_password(32),'role'=>'subscriber'));
get_userdata($chat)->add_cap('adc_view_conversations');$check(!is_wp_error(\AutoDealership\Core\ConfigurationService::assign_branches($chat,$a['id'],array($a['id']))),'Administrator assigns module-only fixture branch');
foreach(array(array($a['id'],$chat,0),array($b['id'],$chat,0),array($a['id'],$sales,0),array($a['id'],$chat,$old))as$i=>$values){
 $wpdb->insert(PortalAccess::table('threads'),array('customer_id'=>$personal,'branch_id'=>$values[0],'owner_id'=>$values[1],'lead_id'=>$values[2],'context_key'=>hash('sha256','workspace-thread-'.$i),'kind'=>'general','source_id'=>0,'car_id'=>0,'subject'=>'Synthetic work queue','created_at'=>$now,'updated_at'=>$now));
 $check(!$wpdb->last_error,'Create operational conversation fixture');
}
wp_set_current_user($chat);$_GET=array();$work=WorkDashboard::snapshot();
$check(AccountDashboard::work()&&null===$work['overview']&&!current_user_can('adc_view_workspace'),'Custom module-only account does not gain global workspace access');
$check(1===$work['cards']['conversations']['count'],'Conversation indicator uses current lead ownership and branches, not stale raw ownership');
$check(!\AutoDealership\Accounts\CustomerAccount::should_redirect_admin(wp_get_current_user()),'Module-only subscriber can reach authorized work screens without a redirect loop');
get_userdata($chat)->remove_cap('adc_view_conversations');wp_set_current_user(0);wp_set_current_user($chat);
$check(!AccountDashboard::available()&&is_wp_error(WorkDashboard::snapshot()),'Permission revocation takes effect without a shared user-data cache');
foreach(array('dealership_sales_manager','dealership_general_manager','dealership_inventory','dealership_finance','dealership_purchasing','dealership_delivery','dealership_customer_service','dealership_marketing','dealership_auditor')as$role){
 wp_set_current_user($admin);$actor=wp_insert_user(array('user_login'=>'workspace_role_'.$role,'user_pass'=>wp_generate_password(32),'role'=>$role));
 $check(!is_wp_error($actor)&&!is_wp_error(\AutoDealership\Core\ConfigurationService::assign_branches($actor,$a['id'],array($a['id']))),'Create isolated role and branch '.$role);
 wp_set_current_user($actor);$_GET=array();$work=WorkDashboard::snapshot();
 $check(AccountDashboard::work()&&!is_wp_error($work),'Role has authorized work landing '.$role);
 get_userdata($actor)->add_role('car_dealer_customer');wp_set_current_user(0);wp_set_current_user($actor);
 $check(!\AutoDealership\Accounts\CustomerAccount::should_redirect_admin(wp_get_current_user()),'Combined customer/employee roles retain work screen access '.$role);
 ob_start();WorkDashboardView::render();$html=ob_get_clean();$check(str_contains($html,'adc-work-dashboard')&&!str_contains($html,'FOREIGN_CUSTOMER_SECRET'),'Role renders without exposing foreign labels '.$role);
}
wp_set_current_user(0);$check(!AccountDashboard::available()&&is_wp_error(WorkDashboard::snapshot()),'Anonymous work projection denied');
wp_set_current_user($admin);$_GET=array();$portal_settings=get_option('adc_portal_settings',null);update_option('adc_portal_settings',array('enabled'=>false));
$check(AccountDashboard::work()&&!is_wp_error(WorkDashboard::snapshot()),'Disabling the customer portal does not disable authorized work navigation');
null===$portal_settings?delete_option('adc_portal_settings'):update_option('adc_portal_settings',$portal_settings);
// The runner installs WordPress with WP_INSTALLING=true. Real account requests
// use normal option caching; measure that runtime without changing this test DB.
$work_installing=wp_installing();wp_installing(false);
try {
 $work_query_start=$wpdb->num_queries;$work=WorkDashboard::snapshot();$work_query_total=$wpdb->num_queries-$work_query_start;
 $check(!is_wp_error($work)&&$work_query_total<=45,'Operational frontend projection stays within 45 queries; observed '.$work_query_total);
 echo 'PASS Work frontend query budget: '.$work_query_total." / 45 in normal WordPress runtime.\n";
} finally { wp_installing($work_installing); }
wp_set_current_user($admin);$_GET=$work_get;$_SERVER['REQUEST_METHOD']=$work_method;
